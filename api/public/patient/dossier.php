<?php
/**
 * KondjiPro — Espace patient : Mon dossier médical
 * Historique des consultations du patient (motif, diagnostic, notes, date).
 */
$page_title = 'Mon dossier médical';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$consultations = [];
if ($pdo && $patientId > 0) {
    try {
        $stmt = $pdo->prepare(
            "SELECT date_consultation, motif, diagnostic, notes
             FROM consultations WHERE patient_id = ?
             ORDER BY date_consultation DESC"
        );
        $stmt->execute([$patientId]);
        $consultations = $stmt->fetchAll();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Mon dossier médical</h1>
    <div class="sub">Historique de vos consultations au cabinet.</div>
  </div>
</div>

<?php if (!$consultations): ?>
  <div class="card">
    <div class="empty" style="padding:40px;">
      <i class="bi bi-clipboard2-pulse" style="font-size:34px; opacity:.4; display:block; margin-bottom:10px;"></i>
      Aucune consultation enregistrée pour le moment.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="timeline">
      <?php foreach ($consultations as $c): ?>
        <div class="t-item t-consult">
          <div class="t-title"><?= htmlspecialchars($c['motif'] ?? 'Consultation') ?></div>
          <div class="t-meta"><?= htmlspecialchars(date('d/m/Y · H:i', strtotime($c['date_consultation']))) ?></div>
          <?php if (!empty($c['diagnostic'])): ?>
            <div class="t-desc"><span class="tag">Diagnostic</span> <?= htmlspecialchars($c['diagnostic']) ?></div>
          <?php endif; ?>
          <?php if (!empty($c['notes'])): ?>
            <div class="t-desc"><span class="tag">Notes</span> <?= htmlspecialchars($c['notes']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';