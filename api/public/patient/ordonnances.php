<?php
/**
 * KondjiPro — Espace patient : Mes ordonnances
 * Liste des ordonnances du patient avec leurs médicaments.
 */
$page_title = 'Mes ordonnances';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$ordonnances = [];
if ($pdo && $patientId > 0) {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, date_ordonnance, notes FROM ordonnances
             WHERE patient_id = ? ORDER BY date_ordonnance DESC"
        );
        $stmt->execute([$patientId]);
        $ordonnances = $stmt->fetchAll();

        // Médicaments par ordonnance.
        foreach ($ordonnances as &$o) {
            $o['medicaments'] = [];
            $ms = $pdo->prepare(
                "SELECT nom, quantite, unite, frequence FROM ordonnance_medicaments
                 WHERE ordonnance_id = ? ORDER BY id"
            );
            $ms->execute([(int)$o['id']]);
            $o['medicaments'] = $ms->fetchAll();
        }
        unset($o);
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Mes ordonnances</h1>
    <div class="sub">Retrouvez vos prescriptions et leurs posologies.</div>
  </div>
</div>

<?php if (!$ordonnances): ?>
  <div class="card">
    <div class="empty" style="padding:40px;">
      <i class="bi bi-capsule" style="font-size:34px; opacity:.4; display:block; margin-bottom:10px;"></i>
      Aucune ordonnance enregistrée pour le moment.
    </div>
  </div>
<?php else: ?>
  <?php foreach ($ordonnances as $o): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-title">
        <span class="dot"></span> Ordonnance #<?= (int)$o['id'] ?>
        <span class="meta" style="margin-left:auto;"><?= htmlspecialchars(date('d/m/Y', strtotime($o['date_ordonnance']))) ?></span>
      </div>

      <?php if ($o['medicaments']): ?>
        <table class="actes-table">
          <thead>
            <tr>
              <th>Médicament</th>
              <th>Quantité</th>
              <th>Posologie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($o['medicaments'] as $m): ?>
              <tr>
                <td><strong><?= htmlspecialchars($m['nom']) ?></strong></td>
                <td><?= (int)$m['quantite'] ?> <?= htmlspecialchars($m['unite'] ?? '') ?></td>
                <td><?= htmlspecialchars($m['frequence'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">Aucun médicament détaillé.</div>
      <?php endif; ?>

      <?php if (!empty($o['notes'])): ?>
        <div class="t-desc" style="margin-top:10px;">
          <span class="tag">Notes</span> <?= htmlspecialchars($o['notes']) ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';