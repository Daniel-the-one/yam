<?php
/**
 * KondjiPro — Encaisser (génération d'un QR KondjiPay)
 * Le QR code encode l'UUID du patient (identifiant unique stable).
 * Le numéro de téléphone est affiché en clair sous le QR.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');
$page_title = 'Encaisser';

$pdo = db_connect();
$patients = [];
if ($pdo) {
    try {
        $patients = $pdo->query("SELECT id, uuid, nom, telephone FROM patients ORDER BY nom")->fetchAll();
    } catch (Exception $e) {}
}
// Fallback démo si la base est absente (UUID stables générés côté serveur)
if (!$patients) {
    $demo = [
        ['id'=>1,'uuid'=>'a1b2c3d4-1111-4aaa-8bbb-000000000001','nom'=>'Jean KOFFI',   'telephone'=>'+229 90 12 34 56'],
        ['id'=>2,'uuid'=>'a1b2c3d4-2222-4bbb-8ccc-000000000002','nom'=>'Ama ASSOGBA',  'telephone'=>'+229 96 78 11 22'],
        ['id'=>3,'uuid'=>'a1b2c3d4-3333-4ccc-8ddd-000000000003','nom'=>'Kossi AGBEKO', 'telephone'=>'+229 95 44 33 22'],
    ];
    $patients = $demo;
}
$preselect = (int)($_GET['patient'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Encaisser un paiement</h1>
    <div class="sub">Générez un QR code KondjiPay à scanner par le patient.</div>
  </div>
</div>

<div class="grid grid-cols-2">
  <div class="card">
    <div class="card-title"><span class="dot"></span> Informations de paiement</div>

    <form id="form-enc" autocomplete="off">
      <div class="form-row">
        <label for="patient">Numéro patient</label>
        <select id="patient" name="patient" class="select" required>
          <option value="">— Choisir un patient —</option>
          <?php foreach ($patients as $p): ?>
            <option value="<?= (int)$p['id'] ?>"
                    data-uuid="<?= htmlspecialchars($p['uuid'] ?? '') ?>"
                    data-phone="<?= htmlspecialchars($p['telephone'] ?? '') ?>"
                    <?= $preselect === (int)$p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['nom']) ?> · <?= htmlspecialchars($p['telephone']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-row">
        <label for="montant">Montant (FCFA)</label>
        <input id="montant" name="montant" type="number" min="100" step="100" class="input"
               placeholder="Ex : 5 000" required>
      </div>

      <div class="form-row">
        <label for="motif">Motif (optionnel)</label>
        <input id="motif" name="motif" type="text" class="input"
               placeholder="Ex : Consultation, pansement…">
      </div>

      <button type="submit" class="btn btn--primary btn--block">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/>
        </svg>
        Générer le QR de paiement
      </button>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><span class="dot"></span> QR de paiement</div>
    <div class="qr-wrap" id="qr-wrap">
      <div class="empty" style="padding:40px 20px;">
        <svg viewBox="0 0 24 24" width="46" height="46" style="opacity:.35; margin-bottom:10px;">
          <path fill="currentColor" d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/>
        </svg>
        <div>Sélectionnez un patient puis validez<br>pour générer son QR code.</div>
      </div>
    </div>
    <div class="qr-meta" id="qr-meta" hidden></div>
    <p class="meta" style="text-align:center; color:var(--muted); margin-top:10px;">
      Le QR encode l'identifiant unique (UUID) du patient.
    </p>
  </div>
</div>

<script src="assets/js/qrcode.min.js"></script>
<script>
(function(){
  var form   = document.getElementById('form-enc');
  var wrap   = document.getElementById('qr-wrap');
  var meta   = document.getElementById('qr-meta');
  var sel    = document.getElementById('patient');

  function renderQR(uuid, nom, phone) {
    var qr = qrcode(0, 'M');
    qr.addData('KONDJIPRO:' + uuid);
    qr.make();
    wrap.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });
    meta.hidden = false;
    meta.innerHTML =
      '<strong>' + nom + '</strong><br>' +
      'Tél : ' + phone + '<br>' +
      '<small style="opacity:.7">UUID : ' + uuid + '</small>';
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
      wrap.innerHTML = '<div class="empty">Choisissez un patient.</div>';
      meta.hidden = true;
      return;
    }
    var uuid  = opt.getAttribute('data-uuid') || '';
    var phone = opt.getAttribute('data-phone') || '';
    var nom   = opt.textContent.split('·')[0].trim();
    if (!uuid) {
      wrap.innerHTML = '<div class="empty">UUID indisponible pour ce patient.</div>';
      meta.hidden = true;
      return;
    }
    renderQR(uuid, nom, phone);
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php';