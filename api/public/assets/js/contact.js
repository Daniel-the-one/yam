/* =====================================================================
   KondjiPro — Contacts
   Recherche de contacts (API YAM), boutons d'appel audio/vidéo et
   historique des appels manqués et passés.

   Logique métier reprise telle quelle de l'ancienne app YAM :
     - web/js/contacts.js      → recherche GET /api/v1/users/search
     - web/js/spa-contacts.js  → historique localStorage (yam_calls_<uid>)
     - boutons audio/vidéo     → redirection vers ../pages/call.html
       (écran d'appel WebRTC YAM, inchangé)
   Seule l'interface est adaptée au thème KondjiPro.

   Améliorations UI/robustesse (sans changer le contrat API) :
     - AbortController pour annuler une recherche obsolète (pas de course).
     - 401 → purge du token + redirection vers la page de connexion YAM.
     - Migration one-shot de l'historique non scopé (yam_calls → scopé),
       pour ne pas faire fuiter l'historique entre comptes.
     - Guards (date invalide, id manquant) + textContent pour les données
       API (pas de XSS latent).
   ===================================================================== */

(function () {
  'use strict';

  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  // Page d'appel de l'ancienne app YAM (servie depuis le docroot web/).
  // Depuis kondjipro/contact.php, le chemin relatif est ../pages/call.html.
  var CALL_PAGE_URL = '../pages/call';

  // ---------- Hôte de l'API (même logique que web/js/auth.js) ----------
  function getApiBaseUrl() {
    var host = window.location.hostname || '192.168.1.80';
    var isLocal = host === 'localhost' || host === '127.0.0.1' || host.indexOf('192.168.') === 0;
    var isTunnel = host.indexOf('trycloudflare.com') !== -1;
    if (isLocal) return 'http://' + host + ':8000/api/v1';
    if (isTunnel) return window.location.protocol + '//' + host + '/api/v1';
    return window.location.protocol + '//' + window.location.host + '/api/v1';
  }
  var API_BASE_URL = getApiBaseUrl();

  // ---------- Session / identité ----------
  function currentUserId() {
    return localStorage.getItem('user_id') || 'default';
  }
  function getToken() {
    return localStorage.getItem('auth_token');
  }

  // En cas de 401 : la session est morte. On purge ET on redirige vers la
  // page de connexion YAM (même comportement que contacts.js/spa-auth.js).
  function handleUnauthorized() {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user_id');
    localStorage.removeItem('user_name');
    localStorage.removeItem('auth_user_id');
    window.location.href = '../pages/login';
  }

  // ---------- Historique (localStorage, scopé par compte) ----------
  function scopedHistoryKey() {
    return 'yam_calls_' + currentUserId();
  }

  // Migration one-shot : l'ancienne app multi-pages stockait l'historique
  // sous la clé NON scopée `yam_calls`. On la copie vers la clé scopée puis
  // on supprime l'original pour éviter toute fuite entre comptes.
  function migrateLegacyHistory() {
    var scoped = localStorage.getItem(scopedHistoryKey());
    if (scoped !== null) return; // déjà scopé
    var legacy = localStorage.getItem('yam_calls');
    if (!legacy) return;
    try {
      var parsed = JSON.parse(legacy);
      if (Array.isArray(parsed)) {
        localStorage.setItem(scopedHistoryKey(), legacy);
      }
    } catch (e) { /* clé corrompue : on ne la migre pas. */ }
    localStorage.removeItem('yam_calls');
  }

  function getHistory() {
    migrateLegacyHistory();
    try {
      var raw = JSON.parse(localStorage.getItem(scopedHistoryKey()) || '[]');
      return Array.isArray(raw) ? raw : [];
    } catch (e) { return []; }
  }

  function saveHistory(history) {
    localStorage.setItem(scopedHistoryKey(), JSON.stringify(history));
    renderHistory();
  }

  // ---------- Lancement d'un appel (redirection vers l'écran YAM) ----------
  function startCall(contact, type) {
    // Les données de session suivent l'appel jusqu'à l'écran YAM call.html.
    sessionStorage.setItem('call_target_user_id', String(contact.id || ''));
    sessionStorage.setItem('call_target_username', contact.name || contact.username || 'Inconnu');
    sessionStorage.setItem('call_type', type);
    window.location.href = CALL_PAGE_URL;
  }

  // ---------- Recherche de contacts ----------
  // Un AbortController par recherche : si une nouvelle requête part avant
  // la fin de la précédente, l'ancienne est annulée (pas de course → pas
  // de résultats obsolètes qui écrasent la liste à jour).
  function bindContactSearch() {
    var searchEl = $('#contact-search');
    var listEl = $('#contact-list');
    var emptyEl = $('#contact-empty');
    var errorEl = $('#contact-error');
    if (!searchEl || !listEl) return;

    var debounceTimer = null;
    var pendingController = null;

    function showEmpty(message) {
      if (errorEl) errorEl.hidden = true;
      if (!emptyEl) { listEl.innerHTML = ''; return; }
      emptyEl.textContent = message;
      emptyEl.hidden = false;
    }
    function showError(message) {
      if (emptyEl) emptyEl.hidden = true;
      if (errorEl) {
        errorEl.textContent = message;
        errorEl.hidden = false;
      }
      listEl.innerHTML = '';
    }

    function renderContacts(contacts) {
      listEl.innerHTML = '';
      if (errorEl) errorEl.hidden = true;
      if (emptyEl) emptyEl.hidden = true;

      if (!contacts.length) {
        showEmpty('Aucun contact trouvé pour cette recherche.');
        return;
      }

      contacts.forEach(function (contact) {
        var name = contact.name || contact.username || 'Inconnu';
        var phone = contact.phone_number || contact.phoneNumber || '';
        var targetId = contact.id != null ? String(contact.id) : '';

        var item = document.createElement('div');
        item.className = 'contact-item';

        // Avatar (initiale — via textContent, jamais innerHTML).
        var avatar = document.createElement('div');
        avatar.className = 'contact-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        avatar.textContent = name.charAt(0).toUpperCase();

        var info = document.createElement('div');
        info.className = 'contact-info';
        var nameSpan = document.createElement('span');
        nameSpan.className = 'contact-name';
        nameSpan.textContent = name;
        var phoneSpan = document.createElement('span');
        phoneSpan.className = 'contact-phone';
        phoneSpan.textContent = phone;
        info.appendChild(nameSpan);
        info.appendChild(phoneSpan);

        // Boutons d'appel audio + vidéo.
        var actions = document.createElement('div');
        actions.className = 'contact-actions';

        var audioBtn = document.createElement('button');
        audioBtn.className = 'call-btn call-btn--audio';
        audioBtn.type = 'button';
        audioBtn.setAttribute('aria-label', 'Appeler ' + name + ' en audio');
        audioBtn.title = 'Appel audio';
        audioBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
        audioBtn.addEventListener('click', function () {
          startCall({ id: targetId, name: name }, 'audio');
        });

        var videoBtn = document.createElement('button');
        videoBtn.className = 'call-btn call-btn--video';
        videoBtn.type = 'button';
        videoBtn.setAttribute('aria-label', 'Appeler ' + name + ' en vidéo');
        videoBtn.title = 'Appel vidéo';
        videoBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
        videoBtn.addEventListener('click', function () {
          startCall({ id: targetId, name: name }, 'video');
        });

        actions.appendChild(audioBtn);
        actions.appendChild(videoBtn);

        item.appendChild(avatar);
        item.appendChild(info);
        item.appendChild(actions);
        listEl.appendChild(item);
      });
    }

    async function searchContacts(query) {
      // AbortController par recherche : on annule l'éventuelle requête en
      // cours (règle le problème de course entre recherches successives).
      if (pendingController) pendingController.abort();
      var controller = new AbortController();
      pendingController = controller;

      try {
        var token = getToken();
        if (!token) {
          showError('Aucune session YAM. <a href="../pages/login">Connectez-vous</a> pour rechercher des contacts.');
          return;
        }
        var response = await fetch(
          API_BASE_URL + '/users/search?q=' + encodeURIComponent(query) + '&limit=20',
          { headers: { 'Authorization': 'Bearer ' + token }, signal: controller.signal }
        );

        // 401 : session expirée → purge + redirection (même comportement YAM).
        if (response.status === 401) { handleUnauthorized(); return; }

        // On protège json() : si le serveur renvoie du non-JSON (502 HTML,
        // erreur PHP), on affiche un message clair au lieu d'une exception.
        var data;
        var contentType = response.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') !== -1) {
          data = await response.json().catch(function () { return null; });
        } else {
          data = null;
        }

        if (!response.ok || !data) {
          showError('Impossible de charger les contacts. Vérifiez que le serveur YAM est bien démarré.');
          return;
        }

        var contacts = data.data;
        if (!Array.isArray(contacts)) contacts = [];
        renderContacts(contacts);
      } catch (err) {
        // Erreur d'annulation (recherche plus récente) : on ignore proprement.
        if (err && err.name === 'AbortError') return;
        showError('Impossible de joindre le serveur. Vérifiez qu\'il est bien démarré.');
      }
    }

    searchEl.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      var query = searchEl.value.trim();
      // Le backend exige un minimum de 2 caractères.
      if (query.length < 2) {
        if (pendingController) { pendingController.abort(); pendingController = null; }
        listEl.innerHTML = '';
        if (errorEl) errorEl.hidden = true;
        showEmpty('Commencez à taper (au moins 2 caractères) pour rechercher un contact.');
        return;
      }
      // Debounce 300 ms pour ne pas surcharger le serveur à chaque frappe.
      debounceTimer = setTimeout(function () { searchContacts(query); }, 300);
    });

    // État initial.
    showEmpty('Commencez à taper (au moins 2 caractères) pour rechercher un contact.');
  }

  // ---------- Historique des appels (manqués et passés) ----------
  function renderHistory() {
    var historyEl = $('#call-history');
    if (!historyEl) return;

    var history = getHistory();
    historyEl.innerHTML = '';

    if (!history.length) {
      var empty = document.createElement('div');
      empty.className = 'empty';
      empty.textContent = 'Aucun appel dans l\'historique.';
      historyEl.appendChild(empty);
      return;
    }

    history.forEach(function (h, idx) {
      // Guards : entrée corrompue ou malformée → on saute sans planter.
      if (!h || typeof h !== 'object' || typeof h.at === 'undefined') return;

      var d = new Date(h.at);
      var dateStr = isNaN(d.getTime())
        ? (h.at || '')
        : String(d.getDate()).padStart(2, '0') + '/' +
          String(d.getMonth() + 1).padStart(2, '0') + ' ' +
          String(d.getHours()).padStart(2, '0') + ':' +
          String(d.getMinutes()).padStart(2, '0');

      var item = document.createElement('div');
      item.className = 'call-item';

      // Badge Manqué / Passé.
      var badge = document.createElement('span');
      badge.className = 'call-badge ' + (h.missed ? 'call-badge--missed' : 'call-badge--passed');
      badge.textContent = h.missed ? 'Manqué' : 'Passé';

      var info = document.createElement('div');
      info.className = 'contact-info';
      var nameSpan = document.createElement('span');
      nameSpan.className = 'contact-name';
      nameSpan.textContent = h.peerName || h.peerId || 'Inconnu';
      var metaSpan = document.createElement('span');
      metaSpan.className = 'contact-phone';
      metaSpan.textContent = (h.peerId || h.peerUserId || '') + ' · ' + dateStr;
      info.appendChild(nameSpan);
      info.appendChild(metaSpan);

      // Actions : rappeler (audio + vidéo) + effacer.
      var actions = document.createElement('div');
      actions.className = 'contact-actions';

      var callTarget = h.peerUserId || h.peerId;

      var audioBtn = document.createElement('button');
      audioBtn.className = 'call-btn call-btn--audio';
      audioBtn.type = 'button';
      audioBtn.setAttribute('aria-label', 'Rappeler ' + (h.peerName || '') + ' en audio');
      audioBtn.title = 'Rappeler en audio';
      audioBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>';
      audioBtn.addEventListener('click', function () {
        startCall({ id: callTarget, name: h.peerName || h.peerId }, 'audio');
      });

      var videoBtn = document.createElement('button');
      videoBtn.className = 'call-btn call-btn--video';
      videoBtn.type = 'button';
      videoBtn.setAttribute('aria-label', 'Rappeler ' + (h.peerName || '') + ' en vidéo');
      videoBtn.title = 'Rappeler en vidéo';
      videoBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
      videoBtn.addEventListener('click', function () {
        startCall({ id: callTarget, name: h.peerName || h.peerId }, 'video');
      });

      var delBtn = document.createElement('button');
      delBtn.className = 'call-btn call-btn--delete';
      delBtn.type = 'button';
      delBtn.setAttribute('aria-label', 'Effacer l\'appel de ' + (h.peerName || ''));
      delBtn.title = 'Effacer';
      delBtn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
      delBtn.addEventListener('click', function () {
        var list = getHistory();
        list.splice(idx, 1);
        saveHistory(list);
      });

      actions.appendChild(audioBtn);
      actions.appendChild(videoBtn);
      actions.appendChild(delBtn);

      item.appendChild(badge);
      item.appendChild(info);
      item.appendChild(actions);
      historyEl.appendChild(item);
    });
  }

  // ---------- Boot ----------
  document.addEventListener('DOMContentLoaded', function () {
    bindContactSearch();
    renderHistory();
  });
})();
