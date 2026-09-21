<?php
/**
 * KondjiPro — Espace patient : Accueil
 * Bonjour patient, prochain rendez-vous, dernières consultations,
 * ordonnances et factures.
 */
$page_title = 'Mon espace';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();

/* --------- Données (avec fallback si base absente) --------- */
$prochain_rdv = null;
$consultations = [];
$ordonnances   = [];
$factures      = [];

if ($pdo && $patientId > 0) {
    try {
        // Prochain rendez-vous (à venir, non annulé).
        // Date comparée côté PHP (portable SQLite/MySQL — pas de datetime()).
        $stmt = $pdo->prepare(
            "SELECT r.id, r.date_rdv, r.motif, r.statut,
                    m.prenom AS medecin_prenom, m.nom AS medecin_nom, m.specialite
             FROM rendez_vous r
             LEFT JOIN medecins m ON m.id = r.medecin_id
             WHERE r.patient_id = :pid AND r.statut != 'annule'
               AND r.date_rdv >= :now
             ORDER BY r.date_rdv ASC LIMIT 1"
        );
        $stmt->execute([':pid' => $patientId, ':now' => date('Y-m-d H:i:s')]);
        $prochain_rdv = $stmt->fetch() ?: null;

        // Dernières consultations.
        $stmt = $pdo->prepare(
            "SELECT date_consultation, motif, diagnostic FROM consultations
             WHERE patient_id = ? ORDER BY date_consultation DESC LIMIT 5"
        );
        $stmt->execute([$patientId]);
        $consultations = $stmt->fetchAll();

        // Dernières ordonnances.
        $stmt = $pdo->prepare(
            "SELECT id, date_ordonnance, notes FROM ordonnances
             WHERE patient_id = ? ORDER BY date_ordonnance DESC LIMIT 5"
        );
        $stmt->execute([$patientId]);
        $ordonnances = $stmt->fetchAll();

        // Dernières factures.
        $stmt = $pdo->prepare(
            "SELECT id, date_facture, total, statut FROM factures
             WHERE patient_id = ? ORDER BY date_facture DESC LIMIT 5"
        );
        $stmt->execute([$patientId]);
        $factures = $stmt->fetchAll();
    } catch (Exception $e) { /* garde les valeurs par défaut */ }
}

$prenom = trim(explode(' ', $user['name'] ?? 'Patient')[0]);
if ($prenom === '') $prenom = 'Patient';

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Bonjour, <?= htmlspecialchars($prenom) ?> 👋</h1>
    <div class="sub">Bienvenue dans votre espace patient KondjiPro.</div>
  </div>
  <a class="btn btn--primary" href="rendez-vous">
    <i class="bi bi-calendar3"></i>
    Prendre rendez-vous
  </a>
</div>

<!-- ===== Prochain rendez-vous ===== -->
<?php if ($prochain_rdv): ?>
<div class="balance-card" style="margin-bottom:18px;">
  <div class="balance-card-top">
    <span class="balance-card-label"><i class="bi bi-calendar-check"></i> Prochain rendez-vous</span>
    <span class="balance-card-tag"><?= htmlspecialchars($prochain_rdv['statut']) ?></span>
  </div>
  <div class="balance-card-value" style="font-size:22px;">
    <?= htmlspecialchars(date('d/m/Y · H:i', strtotime($prochain_rdv['date_rdv']))) ?>
  </div>
  <div class="balance-card-foot">
    <span><i class="bi bi-person-badge"></i>
      <?= htmlspecialchars(trim(($prochain_rdv['medecin_prenom'] ?? '') . ' ' . ($prochain_rdv['medecin_nom'] ?? '')) ?: 'Médecin') ?>
      <?php if (!empty($prochain_rdv['specialite'])): ?>· <?= htmlspecialchars($prochain_rdv['specialite']) ?><?php endif; ?>
    </span>
    <?php if (!empty($prochain_rdv['motif'])): ?>
      <span><i class="bi bi-chat-left-text"></i> <?= htmlspecialchars($prochain_rdv['motif']) ?></span>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:18px;">
  <div class="empty" style="padding:22px;">
    <i class="bi bi-calendar3" style="font-size:30px; opacity:.4; display:block; margin-bottom:8px;"></i>
    Aucun rendez-vous à venir.
    <a href="rendez-vous" style="color:var(--accent-2); font-weight:600;">Prendre rendez-vous</a>
  </div>
</div>
<?php endif; ?>

<!-- ===== Actions rapides ===== -->
<div class="grid grid-actions" style="margin-bottom:18px;">
  <a class="action-btn" href="medecins">
    <span class="ico"><i class="bi bi-person-badge"></i></span>
    <strong>Trouver un médecin</strong>
    <small>Consulter la liste</small>
  </a>
  <a class="action-btn" href="rendez-vous">
    <span class="ico"><i class="bi bi-calendar3"></i></span>
    <strong>Mes rendez-vous</strong>
    <small>Gérer et annuler</small>
  </a>
  <a class="action-btn action-btn--info" href="dossier">
    <span class="ico"><i class="bi bi-clipboard2-pulse"></i></span>
    <strong>Mon dossier</strong>
    <small>Consultations</small>
  </a>
  <a class="action-btn action-btn--warn" href="factures">
    <span class="ico"><i class="bi bi-receipt"></i></span>
    <strong>Mes factures</strong>
    <small>Historique</small>
  </a>
</div>

<div class="grid grid-cols-2">
  <!-- ===== Consultations ===== -->
  <div class="card">
    <div class="card-title"><span class="dot"></span> Dernières consultations</div>
    <?php if ($consultations): ?>
      <div class="list">
        <?php foreach ($consultations as $c): ?>
          <div class="list-item">
            <span class="pill pill--consult">Consultation</span>
            <div class="grow">
              <div class="who"><?= htmlspecialchars($c['motif'] ?? 'Consultation') ?></div>
              <div class="meta"><?= htmlspecialchars($c['diagnostic'] ?? '') ?></div>
            </div>
            <div class="meta"><?= htmlspecialchars(date('d/m/Y', strtotime($c['date_consultation']))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">Aucune consultation enregistrée.</div>
    <?php endif; ?>
  </div>

  <!-- ===== Ordonnances ===== -->
  <div class="card">
    <div class="card-title"><span class="dot"></span> Dernières ordonnances</div>
    <?php if ($ordonnances): ?>
      <div class="list">
        <?php foreach ($ordonnances as $o): ?>
          <div class="list-item">
            <span class="pill pill--ord">Ordonnance</span>
            <div class="grow">
              <div class="who">Ordonnance #<?= (int)$o['id'] ?></div>
              <div class="meta"><?= htmlspecialchars($o['notes'] ?? '') ?></div>
            </div>
            <div class="meta"><?= htmlspecialchars(date('d/m/Y', strtotime($o['date_ordonnance']))) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="empty">Aucune ordonnance enregistrée.</div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Factures ===== -->
<div class="card" style="margin-top:18px;">
  <div class="card-title"><span class="dot"></span> Dernières factures</div>
  <?php if ($factures): ?>
    <div class="list">
      <?php foreach ($factures as $f):
          $statut_label = ['en_attente'=>'En attente','payee'=>'Payée','annulee'=>'Annulée'][$f['statut']] ?? $f['statut'];
          $statut_class = ['en_attente'=>'pill--facture','payee'=>'pill--ord','annulee'=>'pill--analyse'][$f['statut']] ?? 'pill--facture';
      ?>
        <div class="list-item">
          <span class="pill <?= $statut_class ?>"><?= htmlspecialchars($statut_label) ?></span>
          <div class="grow">
            <div class="who">Facture #<?= (int)$f['id'] ?></div>
            <div class="meta"><?= htmlspecialchars(date('d/m/Y', strtotime($f['date_facture']))) ?></div>
          </div>
          <div class="meta" style="font-weight:700; color:var(--accent-2);">
            <?= number_format((int)$f['total'], 0, ',', ' ') ?> FCFA
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="empty">Aucune facture enregistrée.</div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php';