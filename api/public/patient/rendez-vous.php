<?php
/**
 * KondjiPro — Espace patient : Mes rendez-vous
 * Liste des rendez-vous (AJAX /api/rdv) + formulaire de prise de
 * rendez-vous (médecin, date/heure, motif) + annulation.
 */
$page_title = 'Mes rendez-vous';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$medecins = [];
if ($pdo) {
    try {
        $medecins = $pdo->query("SELECT id, prenom, nom, specialite FROM medecins ORDER BY prenom, nom")->fetchAll();
    } catch (Exception $e) {}
}
$preselect = (int)($_GET['medecin'] ?? 0);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Mes rendez-vous</h1>
    <div class="sub">Prenez rendez-vous avec un médecin et suivez son statut.</div>
  </div>
</div>

<div class="grid grid-cols-2">
  <!-- ===== Formulaire de prise de rendez-vous ===== -->
  <div class="card">
    <div class="card-title"><span class="dot"></span> Nouveau rendez-vous</div>

    <form id="form-rdv" autocomplete="off">
      <div class="form-row">
        <label for="medecin_id">Médecin <span style="color:var(--accent-2)">*</span></label>
        <select id="medecin_id" name="medecin_id" class="select" required>
          <option value="">— Choisir un médecin —</option>
          <?php foreach ($medecins as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= $preselect === (int)$m['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? '')) ?: 'Médecin') ?>
              · <?= htmlspecialchars($m['specialite'] ?? '') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <label for="date_rdv">Date et heure <span style="color:var(--accent-2)">*</span></label>
        <input id="date_rdv" name="date_rdv" class="input" type="datetime-local"
               min="<?= date('Y-m-d\TH:i', time() + 3600) ?>" required>
      </div>
      <div class="form-row">
        <label for="motif">Motif (optionnel)</label>
        <input id="motif" name="motif" class="input" placeholder="Ex : Fièvre persistante">
      </div>
      <button type="submit" class="btn btn--primary btn--block">
        <i class="bi bi-calendar-plus"></i>
        Enregistrer le rendez-vous
      </button>
    </form>
  </div>

  <!-- ===== Liste des rendez-vous ===== -->
  <div class="card">
    <div class="card-title"><span class="dot"></span> Mes rendez-vous</div>
    <div id="rdv-list">
      <div class="empty">Chargement…</div>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var listEl = document.getElementById('rdv-list');
  var formEl = document.getElementById('form-rdv');
  if (!listEl) return;

  var STATUTS = {
    'en_attente': { label: 'En attente', cls: 'pill--facture' },
    'confirme':   { label: 'Confirmé',   cls: 'pill--ord' },
    'annule':     { label: 'Annulé',     cls: 'pill--analyse' },
    'termine':    { label: 'Terminé',    cls: 'pill--consult' }
  };

  // Échappement HTML avant insertion dans innerHTML (anti-XSS).
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function statutPill(s) {
    var d = STATUTS[s] || { label: s, cls: 'pill--facture' };
    return '<span class="pill ' + esc(d.cls) + '">' + esc(d.label) + '</span>';
  }

  function load() {
    fetch('/api/rdv', { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) { listEl.innerHTML = '<div class="empty">' + (data.message || 'Erreur de chargement.') + '</div>'; return; }
        var rows = data.results || [];
        if (!rows.length) {
          listEl.innerHTML = '<div class="empty">Aucun rendez-vous pour le moment.</div>';
          return;
        }
        listEl.innerHTML = rows.map(function (r) {
          var annulable = (r.statut === 'en_attente' || r.statut === 'confirme');
          var btn = annulable
            ? '<button type="button" class="btn btn--ghost btn--sm" data-annuler="' + esc(r.id) + '">Annuler</button>'
            : '';
          return '<div class="list-item">' +
            statutPill(r.statut) +
            '<div class="grow">' +
              '<div class="who">' + esc(r.medecin || 'Médecin') + '</div>' +
              '<div class="meta">' + esc(r.date_rdv) + (r.motif ? ' · ' + esc(r.motif) : '') + '</div>' +
            '</div>' +
            btn +
          '</div>';
        }).join('');
      })
      .catch(function () {
        listEl.innerHTML = '<div class="empty">Impossible de charger les rendez-vous.</div>';
      });
  }

  // Annulation (délégation d'événement).
  listEl.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-annuler]');
    if (!btn) return;
    if (!confirm('Annuler ce rendez-vous ?')) return;
    var body = new URLSearchParams();
    body.append('action', 'annuler');
    body.append('id', btn.getAttribute('data-annuler'));
    fetch('/api/rdv', { method: 'POST', body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        alert(data.message || 'Opération terminée.');
        load();
      })
      .catch(function () { alert('Impossible de joindre le serveur.'); });
  });

  // Création (soumission du formulaire).
  if (formEl) {
    formEl.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = formEl.querySelector('button[type=submit]');
      if (btn) btn.disabled = true;
      fetch('/api/rdv', { method: 'POST', body: new FormData(formEl) })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          alert(data.message || 'Opération terminée.');
          if (data.ok) formEl.reset();
          load();
        })
        .catch(function () { alert('Impossible de joindre le serveur.'); })
        .finally(function () { if (btn) btn.disabled = false; });
    });
  }

  load();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php';