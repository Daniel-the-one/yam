<?php
/**
 * KondjiPro — Dossier médical
 * Recherche patient (AJAX via /api/patients.php),
 * détail + onglets Historique / Profil / Documents.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');
$page_title = 'Dossier médical';

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Dossier médical</h1>
    <div class="sub">Recherchez un patient puis consultez son historique complet.</div>
  </div>
  <button class="btn btn--primary" id="btn-new-patient">+ Nouveau patient</button>
</div>

<!-- ===== MODAL NOUVEAU PATIENT ===== -->
<div class="modal-backdrop" id="modal-patient" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Nouveau patient</h3>
      <button type="button" class="modal-close" data-close="modal-patient" aria-label="Fermer">&times;</button>
    </div>
    <form id="form-patient" autocomplete="off">
      <div class="form-row">
        <label for="p-nom">Nom complet <span style="color:var(--accent-2)">*</span></label>
        <input id="p-nom" name="nom" class="input" placeholder="Ex : Jean KOFFI" required>
      </div>
      <div class="form-row">
        <label for="p-tel">Téléphone <span style="color:var(--accent-2)">*</span></label>
        <input id="p-tel" name="telephone" class="input" type="tel" placeholder="+229 90 12 34 56" required>
      </div>
      <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div>
          <label for="p-dob">Date de naissance</label>
          <input id="p-dob" name="date_naissance" class="input" type="date">
        </div>
        <div>
          <label for="p-sexe">Sexe</label>
          <select id="p-sexe" name="sexe" class="input">
            <option value="">—</option>
            <option value="M">Masculin</option>
            <option value="F">Féminin</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <label for="p-adr">Adresse</label>
        <input id="p-adr" name="adresse" class="input" placeholder="Ville, quartier">
      </div>
      <div class="form-row">
        <label for="p-photo">Photo (optionnel)</label>
        <input type="file" id="p-photo" name="photo" accept="image/*" class="input">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn--ghost" data-close="modal-patient">Annuler</button>
        <button type="submit" class="btn btn--primary">Ajouter le patient</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== MODAL NOUVELLE CONSULTATION ===== -->
<div class="modal-backdrop" id="modal-consult" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Nouvelle consultation</h3>
      <button type="button" class="modal-close" data-close="modal-consult" aria-label="Fermer">&times;</button>
    </div>
    <form id="form-consult" autocomplete="off">
      <input type="hidden" name="patient" id="c-patient">
      <div class="form-row">
        <label for="c-motif">Motif <span style="color:var(--accent-2)">*</span></label>
        <input id="c-motif" name="motif" class="input" placeholder="Ex : Fièvre persistante" required>
      </div>
      <div class="form-row">
        <label for="c-diag">Diagnostic</label>
        <input id="c-diag" name="diagnostic" class="input" placeholder="Ex : Paludisme simple">
      </div>
      <div class="form-row">
        <label for="c-notes">Notes</label>
        <textarea id="c-notes" name="notes" class="input" rows="3" placeholder="Recommandations, traitement…"></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn--ghost" data-close="modal-consult">Annuler</button>
        <button type="submit" class="btn btn--primary">Enregistrer la consultation</button>
      </div>
    </form>
  </div>
</div>

<div class="grid grid-cols-2" style="grid-template-columns: minmax(280px, 360px) minmax(0, 1fr);">

  <!-- ===== Colonne gauche : recherche + liste ===== -->
  <div class="card">
    <div class="form-row" style="margin-bottom:14px;">
      <label for="patient-search">Rechercher un patient</label>
      <div class="search" style="max-width:none; background:var(--bg-1);">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M10 2a8 8 0 1 1-5.3 14L2 18.7 3.3 20l2.7-2.7A8 8 0 0 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/>
        </svg>
        <input id="patient-search" type="text" placeholder="Nom ou téléphone…" autocomplete="off">
      </div>
    </div>

    <div class="card-title"><span class="dot"></span> Patients récents</div>
    <div id="patient-list" class="patient-list">
      <div class="empty">Saisissez un nom ou un numéro…</div>
    </div>
  </div>

  <!-- ===== Colonne droite : détail patient ===== -->
  <div id="patient-detail" class="card">
    <div class="empty" style="padding:60px 20px;">
      <svg viewBox="0 0 24 24" width="46" height="46" style="opacity:.4; margin-bottom:10px;">
        <path fill="currentColor" d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4 0-8 2-8 6v2h16v-2c0-4-4-6-8-6z"/>
      </svg>
      <div>Sélectionnez un patient pour afficher son dossier.</div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';
