/* =====================================================================
   KondjiPro — Authentification (login + register)
   Pages : pages/login.html, pages/register.html

   Appelle l'API PHP KondjiPro (session + cookie) :
     POST /api/auth/login.php    { identifier, password }
     POST /api/auth/register.php { name, phone_number, password, role, device_id }

   La session est gérée côté serveur (cookie HttpOnly). Le JS ne stocke
   que des informations d'affichage (user_id, user_name, user_role) et
   redirige selon le rôle :
     - medecin → /dashboard.php
     - patient → /contact.php

   NB : le device_id est aligné sur la clé `yam_device_id` utilisée par
   incoming-call-listener.js (migration depuis l'ancienne clé `device_id`).
   ===================================================================== */

(function () {
  'use strict';

  function $(sel) { return document.querySelector(sel); }

  // ---------- Base de l'API (même origine que la page) ----------
  function getApiBaseUrl() {
    var host = window.location.hostname || '127.0.0.1';
    var isLocal = host === 'localhost' || host === '127.0.0.1' || host.indexOf('192.168.') === 0;
    if (isLocal) return window.location.protocol + '//' + host + ':' + (window.location.port || '8099') + '/api';
    return window.location.protocol + '//' + window.location.host + '/api';
  }
  var API_BASE_URL = getApiBaseUrl();

  // ---------- Device id (aligné sur incoming-call-listener.js) ----------
  function getOrCreateDeviceId() {
    var id = localStorage.getItem('yam_device_id');
    if (id) return id;
    // Migration depuis l'ancienne clé `device_id`.
    id = localStorage.getItem('device_id');
    if (id) {
      localStorage.setItem('yam_device_id', id);
      localStorage.removeItem('device_id');
      return id;
    }
    id = (window.crypto && crypto.randomUUID)
      ? crypto.randomUUID()
      : 'device-' + Math.random().toString(36).substring(2, 10);
    localStorage.setItem('yam_device_id', id);
    return id;
  }

  // ---------- Erreurs ----------
  function extractError(data) {
    if (!data) return 'Une erreur inattendue s\'est produite.';
    if (data.message) return data.message;
    if (data.error === 'invalid_credentials') return 'Identifiants incorrects.';
    if (data.error === 'phone_taken') return 'Ce numéro de téléphone est déjà utilisé.';
    if (data.error === 'validation') return 'Merci de vérifier les champs du formulaire.';
    return 'Une erreur inattendue s\'est produite.';
  }

  function showError(msg) {
    var el = $('#form-error');
    if (el) {
      el.textContent = msg;
      el.classList.add('visible');
    }
  }

  function clearError() {
    var el = $('#form-error');
    if (el) {
      el.textContent = '';
      el.classList.remove('visible');
    }
  }

  function storeSession(user) {
    var u = user || {};
    localStorage.setItem('user_id', u.id != null ? String(u.id) : '');
    localStorage.setItem('user_name', u.name || '');
    localStorage.setItem('user_username', u.username || '');
    localStorage.setItem('user_phone', u.phone_number || '');
    localStorage.setItem('user_role', u.role || 'patient');
  }

  function redirectAfterAuth(role) {
    // Retour à la page d'origine si fournie (ex. garde d'une page KondjiPro).
    var back = sessionStorage.getItem('auth_back_url');
    sessionStorage.removeItem('auth_back_url');
    if (back) { window.location.href = back; return; }
    // Sinon, redirection selon le rôle.
    window.location.href = (role === 'medecin') ? '/dashboard' : '/patient/accueil';
  }

  // ---------- Onglets Patient / Médecin ----------
  function bindRoleTabs() {
    var tabs = document.querySelectorAll('[data-role-tab]');
    if (!tabs.length) return;
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var role = tab.getAttribute('data-role-tab');
        tabs.forEach(function (t) {
          t.classList.toggle('active', t === tab);
          t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
        });
        document.body.setAttribute('data-auth-role', role);
        // Met à jour le titre et le lien d'inscription selon le rôle.
        var title = $('#auth-title');
        if (title) {
          title.textContent = (role === 'medecin')
            ? 'Espace Médecin'
            : 'Espace Patient';
        }
        var subtitle = $('#auth-subtitle');
        if (subtitle) {
          subtitle.textContent = (role === 'medecin')
            ? 'Gérez votre cabinet, vos patients et vos encaissements.'
            : 'Accédez à vos rendez-vous et votre dossier médical.';
        }
        var registerLink = $('#register-link');
        if (registerLink) {
          registerLink.setAttribute('data-role', role);
        }
        // Champ caché du rôle (formulaire register).
        var roleInput = $('#role');
        if (roleInput) {
          roleInput.value = role;
        }
      });
    });
  }

  // ---------- Toggle afficher / masquer le mot de passe ----------
  function bindPasswordToggles() {
    document.querySelectorAll('.password-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-target'));
        if (!input) return;
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.textContent = show ? 'Masquer' : 'Afficher';
      });
    });
  }

  // ---------- Login ----------
  function bindLogin() {
    var form = $('#login-form');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearError();
      var identifier = $('#identifier').value.trim();
      var password = $('#password').value;
      if (!identifier || !password) { showError('Merci de remplir tous les champs.'); return; }

      var submitBtn = form.querySelector('button[type=submit]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        var res = await fetch(API_BASE_URL + '/auth/login', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            identifier: identifier,
            password: password,
            device_id: getOrCreateDeviceId(),
          }),
        });
        var data = await res.json().catch(function () { return null; });
        if (!res.ok) { showError(extractError(data)); return; }
        storeSession(data.user);
        redirectAfterAuth(data.user && data.user.role);
      } catch (err) {
        showError('Impossible de joindre le serveur. Vérifiez votre connexion.');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  // ---------- Register ----------
  function bindRegister() {
    var form = $('#register-form');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      clearError();
      var name = $('#name').value.trim();
      var phone = $('#phone_number').value.trim();
      var password = $('#password').value;
      var confirm = $('#password-confirm').value;
      if (!name || !phone || !password || !confirm) { showError('Merci de remplir tous les champs.'); return; }
      if (password !== confirm) { showError('Les mots de passe ne correspondent pas.'); return; }

      // Rôle sélectionné (onglet actif ou champ caché).
      var roleTab = document.querySelector('[data-role-tab].active');
      var role = (roleTab && roleTab.getAttribute('data-role-tab')) || 'patient';
      var roleInput = $('#role');
      if (roleInput && roleInput.value) role = roleInput.value;

      var submitBtn = form.querySelector('button[type=submit]');
      if (submitBtn) submitBtn.disabled = true;
      try {
        var res = await fetch(API_BASE_URL + '/auth/register', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            name: name,
            phone_number: phone,
            password: password,
            role: role,
            device_id: getOrCreateDeviceId(),
          }),
        });
        var data = await res.json().catch(function () { return null; });
        if (!res.ok) { showError(extractError(data)); return; }
        storeSession(data.user);
        redirectAfterAuth(data.user && data.user.role);
      } catch (err) {
        showError('Impossible de joindre le serveur. Vérifiez votre connexion.');
      } finally {
        if (submitBtn) submitBtn.disabled = false;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindRoleTabs();
    bindPasswordToggles();
    bindLogin();
    bindRegister();
  });
})();