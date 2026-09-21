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

    function isOpen() { return !panel.hidden; }
    function open()   { panel.hidden = false; }
    function close()  { panel.hidden = true; }

    btn.addEventListener('click', e => {
      e.stopPropagation();
      isOpen() ? close() : open();
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
