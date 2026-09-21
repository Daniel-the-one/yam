/* ============================================================
 * kondjipro.js — Fichier JS fusionné KondjiPro + YAM
 * Regroupe : assets/js/app.js + js/push.js + js/incoming-call-listener.js
 * Objectif : réduire le nombre de requêtes HTTP (rate-limit o2switch).
 * Pour modifier : éditer les fichiers sources puis re-fusionner.
 * ============================================================ */

/* ---------- 1/3 app.js ---------- */
/* =====================================================================
   KondjiPro — JS vanilla (recherche patient, onglets, total facture,
   ajout/suppression de médicament, génération QR, toast)
   ===================================================================== */

(function () {
  'use strict';

  /* ---------- Helpers ---------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) { return Array.from((root || document).querySelectorAll(sel)); }

  function fmtFCFA(n) {
    n = Number(n) || 0;
    return n.toLocaleString('fr-FR') + ' FCFA';
  }

  function toast(msg) {
    let t = document.querySelector('.toast');
    if (!t) {
      t = document.createElement('div');
      t.className = 'toast';
      document.body.appendChild(t);
    }
    t.textContent = msg;
    requestAnimationFrame(() => t.classList.add('show'));
    clearTimeout(toast._tm);
    toast._tm = setTimeout(() => t.classList.remove('show'), 2400);
  }

  /* ---------- Onglets (dossier médical) ---------- */
  function bindTabs() {
    $$('.tabs').forEach(group => {
      const buttons = $$('button', group);
      const panes = $$('.tab-pane');
      buttons.forEach((btn, i) => {
        btn.addEventListener('click', () => {
          buttons.forEach(b => b.classList.remove('active'));
          btn.classList.add('active');
          panes.forEach(p => p.hidden = true);
          const target = document.getElementById(btn.dataset.target);
          if (target) target.hidden = false;
        });
      });
    });
  }

  /* ---------- Recherche patient (dossier.php) ---------- */
  function bindPatientSearch() {
    const input = $('#patient-search');
    const list  = $('#patient-list');
    if (!input || !list) return;

    let timer = null;
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      timer = setTimeout(() => searchPatients(q, list), 220);
    });

    // clic sur ligne
    list.addEventListener('click', e => {
      const row = e.target.closest('.patient-row');
      if (!row) return;
      $$('.patient-row', list).forEach(r => r.classList.remove('active'));
      row.classList.add('active');
      loadPatient(row.dataset.id);
    });
  }

  async function searchPatients(q, list) {
    try {
      const url = '/api/patients' + (q ? '?q=' + encodeURIComponent(q) : '');
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      const data = await res.json();
      renderPatientList(list, data.results || []);
    } catch (err) {
      console.error(err);
    }
  }

  function renderPatientList(list, items) {
    list.innerHTML = '';
    if (!items.length) {
      list.innerHTML = '<div class="empty">Aucun patient trouvé</div>';
      return;
    }
    items.forEach(p => {
      const row = document.createElement('div');
      row.className = 'patient-row';
      row.dataset.id = p.id;
      const initials = (p.nom || '?').split(/\s+/).slice(0, 2)
        .map(w => w.charAt(0).toUpperCase()).join('');
      const avatar = p.photo
        ? `<img class="mini-avatar img" src="${escapeHtml(p.photo)}" alt="">`
        : `<div class="mini-avatar" style="background:${p.couleur || '#10b981'}">${escapeHtml(initials)}</div>`;
      row.innerHTML = `
        ${avatar}
        <div>
          <div class="name">${escapeHtml(p.nom)}</div>
          <div class="phone">${escapeHtml(p.telephone)}</div>
        </div>
      `;
      list.appendChild(row);
    });
  }

  async function loadPatient(id) {
    const panel = $('#patient-detail');
    if (!panel) return;
    try {
      const res = await fetch('/api/patients.php?id=' + encodeURIComponent(id));
      const data = await res.json();
      if (!data.patient) return;
      const p = data.patient;
      const histo = data.historique || [];
      const initials = (p.nom || '?').split(/\s+/).slice(0, 2)
        .map(w => w.charAt(0).toUpperCase()).join('');
      const avatar = p.photo
        ? `<img class="mini-avatar img" style="width:64px;height:64px;font-size:22px;" src="${escapeHtml(p.photo)}" alt="">`
        : `<div class="mini-avatar" style="background:${p.couleur}; width:64px; height:64px; font-size:22px;">${escapeHtml(initials)}</div>`;
      panel.innerHTML = `
        <div style="display:flex; align-items:center; gap:14px;">
          ${avatar}
          <div>
            <h2 style="margin:0;">${escapeHtml(p.nom)}</h2>
            <div class="phone">${escapeHtml(p.telephone)}</div>
          </div>
          <div style="margin-left:auto; display:flex; gap:8px; flex-wrap:wrap;">
            <button class="btn btn--info" data-consult="${p.id}">Nouvelle consultation</button>
            <a class="btn btn--warn"  href="/facturer.php?patient=${p.id}">Facturer</a>
            <a class="btn btn--primary" href="/encaisser.php?patient=${p.id}">Encaisser</a>
          </div>
        </div>
        <div class="divider"></div>
        <div style="display:flex; gap:18px; color:var(--muted); font-size:13px;">
          <span>Naissance : <strong style="color:var(--text)">${escapeHtml(p.date_naissance || '—')}</strong></span>
          <span>Âge : <strong style="color:var(--text)">${p.age ?? '—'} ans</strong></span>
          <span>Sexe : <strong style="color:var(--text)">${escapeHtml(p.sexe || '—')}</strong></span>
        </div>

        <div class="tabs" style="margin-top:18px;">
          <button data-target="tab-histo" class="active">Historique</button>
          <button data-target="tab-profil">Profil</button>
          <button data-target="tab-docs">Documents</button>
        </div>
        <div id="tab-histo" class="tab-pane">
          <div class="timeline">${renderTimeline(histo)}</div>
        </div>
        <div id="tab-profil" class="tab-pane" hidden>
          <p>Adresse : ${escapeHtml(p.adresse || '—')}</p>
          <p>Téléphone : ${escapeHtml(p.telephone)}</p>
          <p>Date de naissance : ${escapeHtml(p.date_naissance || '—')} (${p.age ?? '—'} ans)</p>
        </div>
        <div id="tab-docs"  class="tab-pane" hidden>
          <div class="empty">Aucun document attaché pour ce patient.</div>
        </div>
      `;
      bindTabs();
    } catch (e) { console.error(e); }
  }

  function renderTimeline(items) {
    if (!items.length) return '<div class="empty">Aucun événement.</div>';
    return items.map(it => {
      const cls = 't-' + (it.type || 'consult');
      const label = ({consult:'Consultation',ord:'Ordonnance',facture:'Facture',analyse:'Analyse'})[it.type] || it.type;
      return `
        <div class="t-item ${cls}">
          <div class="t-title">${escapeHtml(label)} — ${escapeHtml(it.titre || '')}</div>
          <div class="t-meta">${escapeHtml(it.date || '')}</div>
          ${it.desc ? `<div class="t-desc">${escapeHtml(it.desc)}</div>` : ''}
        </div>
      `;
    }).join('');
  }

  /* ---------- Facturation : total dynamique ---------- */
  function bindFacturation() {
    const checkboxes = $$('.acte-cb');
    const totalEl = $('#facture-total');
    if (!checkboxes.length || !totalEl) return;

    function recalc() {
      let total = 0;
      checkboxes.forEach(cb => {
        if (cb.checked) total += Number(cb.dataset.prix) || 0;
      });
      totalEl.textContent = fmtFCFA(total);
      const preview = $('#facture-preview');
      if (preview) preview.querySelector('[data-bind=total]').textContent = fmtFCFA(total);
    }
    checkboxes.forEach(cb => cb.addEventListener('change', recalc));
    recalc();
  }

  /* ---------- Ordonnance : ajouter/supprimer médicament ---------- */
  function bindOrdonnance() {
    const list = $('#med-list');
    const addBtn = $('#add-med');
    if (!list || !addBtn) return;

    addBtn.addEventListener('click', () => {
      const idx = list.querySelectorAll('.med-row').length;
      const row = document.createElement('div');
      row.className = 'med-row';
      row.innerHTML = `
        <input class="input med-name" name="med[${idx}][nom]"      placeholder="Nom du médicament">
        <input class="input med-qty"  name="med[${idx}][quantite]" type="number" min="1" value="1" placeholder="Qté">
        <input class="input med-unit" name="med[${idx}][unite]"    placeholder="Unité (cp, ml…)">
        <input class="input med-freq" name="med[${idx}][frequence]"placeholder="Fréquence">
        <button type="button" class="rm" aria-label="Supprimer">✕</button>
      `;
      list.appendChild(row);
      row.querySelector('.med-name').focus();
      updateOrdPreview();
    });

    list.addEventListener('input', updateOrdPreview);
    list.addEventListener('click', e => {
      if (e.target.matches('.rm')) {
        e.target.closest('.med-row').remove();
        updateOrdPreview();
      }
    });

    updateOrdPreview();
  }

  function updateOrdPreview() {
    const preview = $('#ord-preview');
    if (!preview) return;
    const rows = $$('.med-row');
    const out = preview.querySelector('[data-bind=meds]');
    const count = preview.querySelector('[data-bind=count]');
    if (!out) return;

    if (!rows.length) {
      out.innerHTML = '<div class="empty" style="padding:12px">Aucun médicament ajouté.</div>';
      if (count) count.textContent = '0 médicament(s)';
      return;
    }
    let html = '<div class="list">';
    rows.forEach((r, i) => {
      const n = r.querySelector('.med-name').value || '—';
      const q = r.querySelector('.med-qty').value  || '1';
      const u = r.querySelector('.med-unit').value || '';
      const f = r.querySelector('.med-freq').value || '';
      html += `
        <div class="list-item">
          <span class="pill pill--ord">${i + 1}</span>
          <div class="grow">
            <div class="who">${escapeHtml(n)}</div>
            <div class="meta">${escapeHtml(q)} ${escapeHtml(u)} · ${escapeHtml(f)}</div>
          </div>
        </div>
      `;
    });
    html += '</div>';
    out.innerHTML = html;
    if (count) count.textContent = rows.length + ' médicament(s)';
  }

  /* ---------- Encaisser : QR dynamique ---------- */
  function bindEncaissement() {
    const form   = $('#form-enc');
    const qrImg  = $('#qr-img');
    if (!form || !qrImg) return;

    form.addEventListener('submit', e => {
      e.preventDefault();
      const fd = new FormData(form);
      const payload = {
        patient:   fd.get('patient'),
        montant:   Number(fd.get('montant') || 0),
        motif:     fd.get('motif') || ''
      };
      if (!payload.patient || !payload.montant) {
        toast('Veuillez saisir un patient et un montant.');
        return;
      }
      // Encode les données dans le QR (texte JSON, lisible par un lecteur KondjiPay).
      const text = 'KONDJIPAY:' + JSON.stringify(payload);
      const url  = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data='
                 + encodeURIComponent(text);
      qrImg.src = url;
      qrImg.alt = 'QR de paiement KondjiPay';
      toast('QR code généré pour ' + fmtFCFA(payload.montant));
    });
  }

  /* ---------- Soumission facture / ordonnance ---------- */
  function bindSubmitForms() {
    $$('form[data-ajax]').forEach(f => {
      f.addEventListener('submit', async e => {
        e.preventDefault();
        const url = f.dataset.ajax;
        try {
          const res = await fetch(url, { method: 'POST', body: new FormData(f) });
          const data = await res.json();
          if (data.ok) {
            toast(data.message || 'Enregistré avec succès.');
            if (data.reset) f.reset();
          } else {
            toast('Erreur : ' + (data.message || 'inconnue'));
          }
        } catch (err) {
          toast('Erreur réseau.');
        }
      });
    });
  }

  /* ---------- Profil médecin (modal topbar) ---------- */
  function bindMedecinModal() {
    const btn   = $('#btn-medecin');
    const modal = $('#modal-medecin');
    if (!btn || !modal) return;

    function open()  { modal.hidden = false; }
    function close() { modal.hidden = true; }

    btn.addEventListener('click', open);
    $$('[data-close="modal-medecin"]', modal).forEach(b => b.addEventListener('click', close));
    modal.addEventListener('click', e => { if (e.target === modal) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) close(); });

    // Aperçu photo avant envoi
    const file    = $('#medecin-photo');
    const preview = $('#medecin-photo-preview');
    if (file && preview) {
      file.addEventListener('change', () => {
        const f = file.files && file.files[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = ev => {
          preview.innerHTML = '<img src="' + ev.target.result + '" alt="Photo du médecin">';
        };
        reader.readAsDataURL(f);
      });
    }

    // Sauvegarde (champs texte + photo éventuelle)
    const form = $('#form-medecin');
    if (form) {
      form.addEventListener('submit', async e => {
        e.preventDefault();
        const btnSubmit = form.querySelector('button[type=submit]');
        btnSubmit.disabled = true;
        try {
          const params = await formToParams(form);
          const res  = await fetch('/api/medecin', { method: 'POST', body: params });
          const data = await res.json();
          if (data.ok) {
            toast(data.message || 'Profil mis à jour.');
            close();
            if (data.photo) {
              btn.innerHTML = '<img src="' + data.photo + '" alt="Photo du médecin" class="avatar-img">';
            }
          } else {
            toast('Erreur : ' + (data.message || 'inconnue'));
          }
        } catch (err) {
          toast('Erreur réseau.');
        } finally {
          btnSubmit.disabled = false;
        }
      });
    }
  }

  /* ---------- Modals : ouverture / fermeture générique ---------- */
  function bindModal(openSel, modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return null;
    if (openSel) $$(openSel).forEach(b => b.addEventListener('click', () => { modal.hidden = false; }));
    $$('[data-close="' + modalId + '"]', modal).forEach(b => b.addEventListener('click', () => { modal.hidden = true; }));
    modal.addEventListener('click', e => { if (e.target === modal) modal.hidden = true; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) modal.hidden = true; });
    return modal;
  }

  /**
   * Convertit un formulaire en URLSearchParams.
   * Les <input type="file"> sont envoyés en base64 (photo_data / photo_ext)
   * pour éviter le multipart qui est intercepté par le WAF d'o2switch.
   */
  async function formToParams(form) {
    const params = new URLSearchParams();
    for (const el of form.querySelectorAll('input:not([type=file]), select, textarea')) {
      if (el.name) params.set(el.name, el.value);
    }
    for (const fi of form.querySelectorAll('input[type=file]')) {
      if (fi.files && fi.files[0]) {
        const file = fi.files[0];
        const ext  = file.name.split('.').pop().toLowerCase();
        const b64  = await new Promise(resolve => {
          const reader = new FileReader();
          reader.onload = ev => resolve(ev.target.result);
          reader.readAsDataURL(file);
        });
        params.set(fi.name + '_data', b64.split(',')[1]);
        params.set(fi.name + '_ext', ext);
      }
    }
    return params;
  }

  /* ---------- Nouveau patient (modal dossier.php) ---------- */
  function bindNewPatientModal() {
    const modal = bindModal('#btn-new-patient', 'modal-patient');
    const form  = $('#form-patient');
    if (!modal || !form) return;
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btnSubmit = form.querySelector('button[type=submit]');
      btnSubmit.disabled = true;
      try {
        const params = await formToParams(form);
        const res  = await fetch('/api/patient_save', { method: 'POST', body: params });
        const data = await res.json();
        if (data.ok) {
          toast(data.message || 'Patient ajouté.');
          modal.hidden = true;
          form.reset();
          searchPatients('', $('#patient-list'));
        } else {
          toast('Erreur : ' + (data.message || 'inconnue'));
        }
      } catch (err) {
        toast('Erreur réseau.');
      } finally {
        btnSubmit.disabled = false;
      }
    });
  }

  /* ---------- Nouvelle consultation (modal dossier.php) ---------- */
  function bindConsultationModal() {
    const modal = bindModal(null, 'modal-consult');
    const form  = $('#form-consult');
    if (!modal || !form) return;

    // Ouverture via le bouton du détail patient (délégation : le panneau est re-rendu)
    document.addEventListener('click', e => {
      const btn = e.target.closest('[data-consult]');
      if (!btn) return;
      $('#c-patient').value = btn.dataset.consult;
      modal.hidden = false;
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      const btnSubmit = form.querySelector('button[type=submit]');
      btnSubmit.disabled = true;
      const pid = $('#c-patient').value;
      try {
        const res  = await fetch('/api/consultation_save', { method: 'POST', body: new FormData(form) });
        const data = await res.json();
        if (data.ok) {
          toast(data.message || 'Consultation enregistrée.');
          modal.hidden = true;
          form.reset();
          if (pid) loadPatient(pid); // rafraîchit l'historique
        } else {
          toast('Erreur : ' + (data.message || 'inconnue'));
        }
      } catch (err) {
        toast('Erreur réseau.');
      } finally {
        btnSubmit.disabled = false;
      }
    });
  }

  /* ---------- Partage (ordonnance / facture) ---------- */
  function shareText(text) {
    if (navigator.share) {
      navigator.share({ title: 'KondjiPro', text: text }).catch(() => {});
    } else {
      const wa = 'https://wa.me/?text=' + encodeURIComponent(text);
      window.open(wa, '_blank');
    }
  }

  function bindShareOrdonnance() {
    const btn = $('#btn-share-ord');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const sel = $('#patient');
      const patient = (sel && sel.value) ? sel.options[sel.selectedIndex].textContent.split('·')[0].trim() : '—';
      const rows = $$('.med-row');
      if (!rows.length) { toast('Ajoutez au moins un médicament.'); return; }
      let text = 'ORDONNANCE\nPatient : ' + patient + '\nDate : ' + new Date().toLocaleDateString('fr-FR') + '\n\n';
      rows.forEach((r, i) => {
        const n = r.querySelector('.med-name').value || '—';
        const q = r.querySelector('.med-qty').value  || '1';
        const u = r.querySelector('.med-unit').value || '';
        const f = r.querySelector('.med-freq').value || '';
        text += (i + 1) + '. ' + n + ' — ' + q + ' ' + u + (f ? ' (' + f + ')' : '') + '\n';
      });
      shareText(text);
    });
  }

  function bindShareFacture() {
    const btn = $('#btn-share-fact');
    if (!btn) return;
    btn.addEventListener('click', () => {
      const sel = $('#patient');
      const patient = (sel && sel.value) ? sel.options[sel.selectedIndex].textContent.split('·')[0].trim() : '—';
      const checked = $$('.acte-cb:checked');
      if (!checked.length) { toast('Cochez au moins un acte.'); return; }
      let total = 0;
      let text = 'FACTURE\nPatient : ' + patient + '\nDate : ' + new Date().toLocaleDateString('fr-FR') + '\n\n';
      checked.forEach(cb => {
        const row = cb.closest('tr');
        const lib = row ? row.querySelector('td:nth-child(3)').textContent.trim() : '';
        const prix = Number(cb.dataset.prix) || 0;
        total += prix;
        text += '· ' + lib + ' — ' + prix.toLocaleString('fr-FR') + ' FCFA\n';
      });
      text += '\nTotal : ' + total.toLocaleString('fr-FR') + ' FCFA';
      shareText(text);
    });
  }

  /* ---------- Utilitaires ---------- */
  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /* ---------- Panneau de notifications (topbar) ---------- */
  function bindNotifPanel() {
    const btn   = $('#btn-notif');
    const panel = $('#notif-panel');
    if (!btn || !panel) return;

    // Marque la consultation des notifications comme "lu" : on pose le cookie
    // notifs_lues_le (lu par header.php côté serveur) et on retire le badge
    // immédiatement — comme ça plus rien ne revient au rechargement.
    function markNotifsRead() {
      const d = new Date();
      const cookie = 'notifs_lues_le=' + encodeURIComponent(d.toISOString()) +
        '; path=/; max-age=31536000; samesite=lax';
      document.cookie = cookie;
      // Retire le badge du DOM
      btn.querySelector('.badge')?.remove();
    }

    function isOpen() { return !panel.hidden; }
    function open()   { panel.hidden = false; }
    function close()  { panel.hidden = true; }

    btn.addEventListener('click', e => {
      e.stopPropagation();
      isOpen() ? close() : open();
      markNotifsRead();
    });
    $$('[data-close="notif-panel"]', panel).forEach(b => b.addEventListener('click', close));
    document.addEventListener('click', e => {
      if (isOpen() && !panel.contains(e.target) && e.target !== btn) close();
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && isOpen()) close(); });
  }

  /* ---------- Boot ---------- */
  document.addEventListener('DOMContentLoaded', () => {
    bindTabs();
    bindPatientSearch();
    bindFacturation();
    bindOrdonnance();
    bindEncaissement();
    bindSubmitForms();
    bindMedecinModal();
    bindNotifPanel();
    bindNewPatientModal();
    bindConsultationModal();
    bindShareOrdonnance();
    bindShareFacture();

    // Charge la liste initiale des patients sur dossier.php
    const list = $('#patient-list');
    if (list) searchPatients('', list);
  });
})();

/* ---------- 2/3 push.js ---------- */
// =========================================================
// push.js — Enregistre le client web pour les notifications
// push via Firebase Cloud Messaging (FCM) afin de recevoir
// les appels même quand l'onglet est fermé.
//
// NB : on utilise les builds "compat" 9.x de Firebase car les
// builds 10.x+ (ES modules) ne sont pas compatibles avec
// importScripts() dans un Service Worker.
// =========================================================

// Configuration Firebase web (projet projetyam-eddc1).
const FIREBASE_CONFIG = {
  apiKey: "AIzaSyDdvlw8j9HTSbxRdir0L67v5XKdsellimY",
  authDomain: "projetyam-eddc1.firebaseapp.com",
  projectId: "projetyam-eddc1",
  storageBucket: "projetyam-eddc1.firebasestorage.app",
  messagingSenderId: "110051295714",
  appId: "1:110051295714:web:1cf81058bf2aa3016e79bb",
  measurementId: "G-SYF2XDG54K",
};

// Clé VAPID publique (console Firebase → Project settings → Cloud Messaging
// → Web Push certificates → Key pair).
const VAPID_PUBLIC_KEY = "BJtX1Wj7pUoLl6ALPz_Izz1_0mrRGsg5StuNAY_sFpb8aoGZgesJv0o6AZ8pRCoQPjSr2a_yCiG1JyFN0XRh-fA";

/**
 * Enregistre le Service Worker et le token FCM.
 * À appeler après l'enregistrement du device (registerCurrentDevice).
 */
async function registerWebPush(apiBase, deviceId) {
  try {
    if (!("serviceWorker" in navigator)) {
      console.warn("[push] Service Worker non supporté");
      return;
    }
    if (!("PushManager" in window)) {
      console.warn("[push] Web Push non supporté");
      return;
    }

    // Enregistre le Service Worker (nécessaire pour FCM web).
    const reg = await navigator.serviceWorker.register("/sw.js");
    console.log("[push] Service Worker enregistré");

    // Demande la permission de notification.
    const permission = await Notification.requestPermission();
    if (permission !== "granted") {
      console.warn("[push] Permission notification refusée");
      return;
    }

    // Charge le SDK Firebase Messaging (compat 9.x) depuis le CDN.
    await loadFirebaseCompat();

    // Initialise Firebase et obtient le token FCM.
    const app = firebase.initializeApp(FIREBASE_CONFIG);
    const messaging = firebase.messaging(app);
    const token = await messaging.getToken({
      vapidKey: VAPID_PUBLIC_KEY,
      serviceWorkerRegistration: reg,
    });
    console.log("[push] Token FCM obtenu");

    // Enregistre le token FCM dans le backend.
    const response = await fetch(`${apiBase}/devices/register`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Authorization": `Bearer ${localStorage.getItem("auth_token") || ""}`,
      },
      body: JSON.stringify({
        label: localStorage.getItem("device_label") || `Web ${deviceId.slice(-4)}`,
        device_id: deviceId,
        platform: "web",
        fcm_token: token,
      }),
    });
    if (!response.ok) {
      throw new Error("Enregistrement FCM refusé (HTTP " + response.status + ")");
    }
    console.log("[push] Token FCM enregistré côté serveur");
  } catch (err) {
    console.warn("[push] Erreur enregistrement Web Push", err);
  }
}

/**
 * Charge le SDK Firebase Messaging (compat 9.x) de façon dynamique.
 * Retourne une promesse résolue quand les scripts sont chargés.
 */
function loadFirebaseCompat() {
  return new Promise((resolve, reject) => {
    if (window.firebase && window.firebase.messaging) {
      resolve();
      return;
    }
    const scripts = [
      "https://www.gstatic.com/firebasejs/9.23.0/firebase-app-compat.js",
      "https://www.gstatic.com/firebasejs/9.23.0/firebase-messaging-compat.js",
    ];
    let loaded = 0;
    scripts.forEach((src) => {
      const s = document.createElement("script");
      s.src = src;
      s.onload = () => {
        loaded++;
        if (loaded === scripts.length) resolve();
      };
      s.onerror = () => reject(new Error("Échec chargement SDK Firebase: " + src));
      document.head.appendChild(s);
    });
  });
}

/* ---------- 3/3 incoming-call-listener.js ---------- */
// =========================================================
// incoming-call-listener.js — Écoute les appels entrants en temps réel
// via Pusher.com (SaaS) — le backend Laravel diffuse via le driver pusher.
// =========================================================

let PUSHER_APP_KEY = "local";
let PUSHER_CLUSTER = "eu";

function getHostConfig() {
  const host = window.location.hostname || "192.168.1.80";
  const isLocal = host === "localhost" || host === "127.0.0.1" || host.startsWith("192.168.");
  const isTunnel = host.endsWith("trycloudflare.com");

  if (isLocal) {
    return {
      host: host,
      apiBase: `http://${host}:8000/api/v1`,
    };
  }

  // Tunnel HTTPS (cloudflared) : l'API et le client sont servis depuis le
  // même hôte.
  if (isTunnel) {
    return {
      host: host,
      apiBase: `${window.location.protocol}//${host}/api/v1`,
    };
  }

  // Déploiement générique : l'API et le client sont servis depuis le même
  // hôte (Railway, tunnel, VPS...). On dérive l'URL de l'hôte courant au
  // lieu d'un domaine codé en dur.
  return {
    host: window.location.hostname,
    apiBase: `${window.location.protocol}//${window.location.host}/api/v1`,
  };
}

let hostConfig = getHostConfig();
let SERVER_HOST = hostConfig.host;
let API_BASE_URL = hostConfig.apiBase;
let API_CONFIG_URL = `${API_BASE_URL}/config`;
let API_RING_URL = `${API_BASE_URL}/call/ring`;
let API_SIGNAL_URL = `${API_BASE_URL}/call/signal`;

async function hydrateRuntimeConfig() {
  try {
    const res = await fetch(API_CONFIG_URL, { cache: "no-store" });
    if (!res.ok) return;
    const cfg = await res.json();

    if (cfg.pusher?.app_key) PUSHER_APP_KEY = cfg.pusher.app_key;
    if (cfg.pusher?.cluster) PUSHER_CLUSTER = cfg.pusher.cluster;

    if (cfg.turn?.url) {
      window.__TURN_CONFIG__ = {
        url: cfg.turn.url,
        username: cfg.turn.username || "",
        credential: cfg.turn.credential || "",
      };
    }
  } catch (err) {
    console.warn("[runtime-config] impossible de charger la config distante", err);
  }
}

// Lance le chargement de la config runtime immédiatement et expose la
// promesse : les pages qui créent un client Pusher (incoming-call.html,
// call.html) doivent l'attendre pour ne pas se connecter au mauvais hôte.
window.__runtimeConfigReady = hydrateRuntimeConfig();

// Garantir que device_id existe immédiatement.
// Utilise la clé `yam_device_id` (cohérente avec le SPA spa-*.js), avec
// migration depuis l'ancienne clé `device_id`.
function getMyDeviceId() {
  let id = localStorage.getItem("yam_device_id");
  if (id) return id;
  id = localStorage.getItem("device_id");
  if (id) {
    localStorage.setItem("yam_device_id", id);
    localStorage.removeItem("device_id");
    return id;
  }
  id = "device-" + Math.random().toString(36).substring(2, 10);
  localStorage.setItem("yam_device_id", id);
  return id;
}
let currentDeviceId = getMyDeviceId();

async function registerCurrentDevice() {
  const label = localStorage.getItem("device_label") || `Web ${currentDeviceId.slice(-4)}`;
  try {
    await fetch(`${hostConfig.apiBase}/devices/register`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        label,
        device_id: currentDeviceId,
        platform: "web",
      }),
    });
    console.log("[device-registry] ✅ Enregistré côté serveur", currentDeviceId, label);
  } catch (err) {
    console.warn("[device-registry] Enregistrement du device impossible", err);
  }
}

registerCurrentDevice();

// Enregistre le Web Push (Service Worker + subscription) pour recevoir
// les appels même quand l'onglet est fermé.
if (typeof registerWebPush === "function") {
  registerWebPush(hostConfig.apiBase, currentDeviceId);
}

function sdpNormalise(sdp) {
  if (typeof sdp !== "string") return sdp;
  return sdp.endsWith("\r\n") ? sdp : sdp + "\r\n";
}

document.addEventListener("DOMContentLoaded", async () => {
  await window.__runtimeConfigReady;

  // N'écouter les appels entrants globaux que si on n'est PAS déjà sur call.html ou incoming-call.html
  const isCallPage = window.location.pathname.endsWith("/call") || 
                     window.location.pathname.endsWith("/incoming-call");
  if (isCallPage) return;

  // Guard d'authentification : sans compte connecté, on ne peut pas recevoir
  // d'appels (le backend route les appels vers les appareils d'un utilisateur).
  if (!localStorage.getItem("auth_token")) {
    console.warn("[incoming-call] Pas de session (auth_token absent) — écoute des appels entrants désactivée.");
    return;
  }

  if (typeof Pusher === "undefined") {
    console.warn("[incoming-call] Pusher JS n'est pas chargé");
    return;
  }

  const pusher = new Pusher(PUSHER_APP_KEY, {
    cluster: PUSHER_CLUSTER,
    forceTLS: true,
  });

  pusher.connection.bind("state_change", (states) => {
    console.log("[incoming-call] Pusher state:", states.current);
  });

  const channel = pusher.subscribe("device." + currentDeviceId);
  console.log("[incoming-call] Écoute sur le canal device." + currentDeviceId);

  channel.bind("incoming-call", (data) => {
    console.log("[incoming-call] Appel entrant reçu:", data);
    sessionStorage.setItem("incoming_call_id", data.call_id);
    sessionStorage.setItem("incoming_call_from_device_id", data.from_device_id);
    sessionStorage.setItem("incoming_call_from", data.from_username);
    sessionStorage.setItem("incoming_call_type", data.type || "audio");
    // Chemin relatif à la racine du site : fonctionne depuis les pages
    // KondjiPro (/contact.php, /dashboard.php…) comme depuis /pages/.
    window.location.href = "pages/incoming-call";
  });
});
