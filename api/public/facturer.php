<?php
/**
 * KondjiPro — Facturer
 * Sélection d'actes (checkboxes), total calculé en JS en temps réel,
 * validation POST via /api/facture_save.php.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');
$page_title = 'Facturer';

$actes = [
    ['code' => 'C001', 'libelle' => 'Consultation',         'prix' => 5000],
    ['code' => 'C002', 'libelle' => 'Pansement',            'prix' => 3000],
    ['code' => 'C003', 'libelle' => 'Injection',            'prix' => 2500],
    ['code' => 'C004', 'libelle' => 'Soins infirmiers',     'prix' => 2000],
    ['code' => 'C005', 'libelle' => 'Visite à domicile',    'prix' => 10000],
    ['code' => 'C006', 'libelle' => 'Certificat médical',   'prix' => 2500],
];

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
    <h1>Facturer un patient</h1>
    <div class="sub">Cochez les actes médicaux, le total est calculé automatiquement.</div>
  </div>
</div>

<div class="grid grid-cols-2">
  <div class="card">
    <div class="card-title"><span class="dot"></span> Patient & actes</div>

    <form id="form-fact" data-ajax="/api/facture_save.php" autocomplete="off">
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

      <table class="actes-table" style="margin-top:8px;">
        <thead>
          <tr>
            <th style="width:36px;"></th>
            <th>Code</th>
            <th>Acte médical</th>
            <th style="text-align:right;">Prix</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($actes as $a): ?>
            <tr>
              <td><input type="checkbox" class="acte-cb" name="actes[]"
                         value="<?= htmlspecialchars($a['code']) ?>"
                         data-prix="<?= (int)$a['prix'] ?>"></td>
              <td><span class="tag"><?= htmlspecialchars($a['code']) ?></span></td>
              <td><?= htmlspecialchars($a['libelle']) ?></td>
              <td style="text-align:right;"><?= number_format($a['prix'], 0, ',', ' ') ?> FCFA</td>
            </tr>
          <?php endforeach; ?>
          <tr class="total-row">
            <td colspan="3" style="text-align:right;">Total</td>
            <td id="facture-total" style="text-align:right; color:var(--accent-2);">0 FCFA</td>
          </tr>
        </tbody>
      </table>

      <button type="submit" class="btn btn--warn btn--block" style="margin-top:14px;">
        Valider la facture
      </button>
    </form>
  </div>

  <div class="preview" id="facture-preview">
    <h3>Aperçu de la facture</h3>
    <p class="meta" style="color:var(--muted);">Vérifiez les informations avant validation.</p>
    <div class="divider"></div>
    <div class="kv"><span>Patient</span><span id="ap-patient">—</span></div>
    <div class="kv"><span>Contact</span><span id="ap-contact">—</span></div>
    <div class="kv"><span>Date</span><span><?= date('d/m/Y') ?></span></div>
    <div class="kv"><span>Total</span><span class="total" data-bind="total">0 FCFA</span></div>
    <div class="divider"></div>
    <button type="button" class="btn btn--ghost btn--block" id="btn-share-fact">
      <svg viewBox="0 0 24 24" width="16" height="16" style="vertical-align:-3px; margin-right:6px;" aria-hidden="true">
        <path fill="currentColor" d="M18 16a3 3 0 0 0-2.2 1L8.9 13.1a3 3 0 0 0 0-2.2L15.8 7a3 3 0 1 0-.8-2c0 .2 0 .4.1.6L7.4 9.9a3 3 0 1 0 0 4.2l7.7 4.3a3 3 0 1 0 2.9-2.4z"/>
      </svg>
      Partager la facture
    </button>
    <div class="divider"></div>
    <p class="meta" style="color:var(--muted); font-size:12.5px;">
      La facture sera enregistrée en base (statut <em>en attente</em>) et
      apparaîtra dans <a href="/encaisser" style="color:var(--accent-2)">Encaisser</a>
      pour règlement KondjiPay.
    </p>
  </div>
</div>

<script>
// Sync aperçu dynamique (patient / contact) sans dépendre du module
(function(){
  const sel = document.getElementById('patient');
  const apPatient  = document.getElementById('ap-patient');
  const apContact  = document.getElementById('ap-contact');
  if (!sel) return;
  function sync() {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) { apPatient.textContent = '—'; apContact.textContent = '—'; return; }
    const txt = opt.textContent;
    const [nom, tel] = txt.split('·').map(s => s.trim());
    apPatient.textContent = nom || '—';
    apContact.textContent = tel || '—';
  }
  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php include __DIR__ . '/includes/footer.php';
