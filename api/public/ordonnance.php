<?php
/**
 * KondjiPro — Ordonnance
 * Médicaments dynamiques (ajout/suppression), aperçu en temps réel,
 * validation POST via /api/ordonnance_save.php.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');
$page_title = 'Ordonnance';

$pdo = db_connect();
$patients = [];
if ($pdo) {
    try { $patients = $pdo->query("SELECT id, nom, telephone FROM patients ORDER BY nom")->fetchAll(); } catch (Exception $e) {}
}
$preselect = (int)($_GET['patient'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Nouvelle ordonnance</h1>
    <div class="sub">Ajoutez les médicaments un par un, l’aperçu se met à jour en temps réel.</div>
  </div>
</div>

<div class="grid grid-cols-2">
  <div class="card">
    <div class="card-title"><span class="dot"></span> Patient & prescription</div>

    <form id="form-ord" data-ajax="/api/ordonnance_save.php" autocomplete="off">
      <div class="form-row">
        <label for="patient">Numéro patient</label>
        <select id="patient" name="patient" class="select" required>
          <option value="">— Choisir un patient —</option>
          <?php foreach ($patients as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $preselect === (int)$p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['nom']) ?> · <?= htmlspecialchars($p['telephone']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <label>Médicaments</label>
        <div id="med-list"></div>
        <button type="button" id="add-med" class="btn btn--ghost" style="margin-top:6px;">
          + Ajouter un médicament
        </button>
      </div>

      <div class="form-row">
        <label for="notes">Notes (optionnel)</label>
        <textarea id="notes" name="notes" class="input" placeholder="Recommandations, durée du traitement…"></textarea>
      </div>

      <button type="submit" class="btn btn--primary btn--block">
        Valider l’ordonnance
      </button>
    </form>
  </div>

  <div class="preview" id="ord-preview">
    <h3>Aperçu de l’ordonnance</h3>
    <p class="meta" style="color:var(--muted);">Vérifiez avant validation.</p>
    <div class="divider"></div>

    <div class="kv"><span>Patient</span><span id="ap-patient">—</span></div>
    <div class="kv"><span>Date</span><span><?= date('d/m/Y') ?></span></div>
    <div class="kv"><span>Médicaments</span><span data-bind="count">0 médicament(s)</span></div>

    <div class="divider"></div>
    <div data-bind="meds">
      <div class="empty" style="padding:12px;">Aucun médicament ajouté.</div>
    </div>

    <div class="divider"></div>
    <button type="button" class="btn btn--ghost btn--block" id="btn-share-ord">
      <svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px; margin-right:6px;" aria-hidden="true">
        <path fill="currentColor" d="M18 16a3 3 0 0 0-2.2 1L8.9 13.1a3 3 0 0 0 0-2.2L15.8 7a3 3 0 1 0-.8-2c0 .2 0 .4.1.6L7.4 9.9a3 3 0 1 0 0 4.2l7.7 4.3a3 3 0 1 0 2.9-2.4z"/>
      </svg>
      Partager l'ordonnance
    </button>

    <div class="divider"></div>
    <p class="meta" style="color:var(--muted); font-size:12.5px;">
      L’ordonnance sera enregistrée en base et visible dans l’
      <a href="/dossier" style="color:var(--accent-2)">historique du patient</a>.
    </p>
  </div>
</div>

<script>
// Petite amorce : un premier médicament vide pour faciliter la saisie
(function(){
  const list = document.getElementById('med-list');
  if (list && list.children.length === 0) {
    document.getElementById('add-med').click();
  }
  // Sync patient
  const sel = document.getElementById('patient');
  const ap  = document.getElementById('ap-patient');
  if (sel) sel.addEventListener('change', () => {
    const opt = sel.options[sel.selectedIndex];
    ap.textContent = (opt && opt.value) ? opt.textContent.split('·')[0].trim() : '—';
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
