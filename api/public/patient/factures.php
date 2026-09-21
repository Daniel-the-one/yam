<?php
/**
 * KondjiPro — Espace patient : Mes factures
 * Liste des factures du patient (total, statut, date) + total cumulé.
 */
$page_title = 'Mes factures';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$factures = [];
$total_payee = 0;
$total_attente = 0;
if ($pdo && $patientId > 0) {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, date_facture, total, statut FROM factures
             WHERE patient_id = ? ORDER BY date_facture DESC"
        );
        $stmt->execute([$patientId]);
        $factures = $stmt->fetchAll();

        foreach ($factures as $f) {
            if ($f['statut'] === 'payee')       $total_payee   += (int)$f['total'];
            if ($f['statut'] === 'en_attente')  $total_attente += (int)$f['total'];
        }
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Mes factures</h1>
    <div class="sub">Historique de vos factures et règlements.</div>
  </div>
</div>

<!-- ===== Résumé ===== -->
<div class="grid grid-stats" style="margin-bottom:18px;">
  <div class="stat">
    <div>
      <div class="stat-label">Total payé</div>
      <div class="stat-value" style="color:var(--accent-2);"><?= number_format($total_payee, 0, ',', ' ') ?> FCFA</div>
    </div>
    <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
  </div>
  <div class="stat stat--warn">
    <div>
      <div class="stat-label">En attente</div>
      <div class="stat-value"><?= number_format($total_attente, 0, ',', ' ') ?> FCFA</div>
    </div>
    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
  </div>
</div>

<?php if (!$factures): ?>
  <div class="card">
    <div class="empty" style="padding:40px;">
      <i class="bi bi-receipt" style="font-size:34px; opacity:.4; display:block; margin-bottom:10px;"></i>
      Aucune facture enregistrée pour le moment.
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <table class="actes-table">
      <thead>
        <tr>
          <th>N°</th>
          <th>Date</th>
          <th>Montant</th>
          <th style="text-align:right;">Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($factures as $f):
            $statut_label = ['en_attente'=>'En attente','payee'=>'Payée','annulee'=>'Annulée'][$f['statut']] ?? $f['statut'];
            $statut_class = ['en_attente'=>'pill--facture','payee'=>'pill--ord','annulee'=>'pill--analyse'][$f['statut']] ?? 'pill--facture';
        ?>
          <tr>
            <td><span class="tag">#<?= (int)$f['id'] ?></span></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($f['date_facture']))) ?></td>
            <td><strong><?= number_format((int)$f['total'], 0, ',', ' ') ?> FCFA</strong></td>
            <td style="text-align:right;"><span class="pill <?= $statut_class ?>"><?= htmlspecialchars($statut_label) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';