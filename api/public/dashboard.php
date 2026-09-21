<?php
/**
 * KondjiPro — Tableau de bord (Accueil)
 * Bonjour Dr. Mensah, stats, actions rapides, activité récente, bloc cabinet.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');

$page_title = 'Accueil';
$pdo = db_connect();

/* --------- Données (avec fallback si la base est absente) --------- */
$solde       = 125000;
$patients_j  = 6;
$factures_at = 3;
$ord_mois    = 14;
$patients_suivis = 142;
$consult_mois    = 87;
$recettes_mois   = 425000;

$activite = [
  ['type' => 'consult', 'who' => 'Jean KOFFI',   'desc' => 'Consultation · Paludisme simple',     'date' => 'Aujourd’hui · 09:24'],
  ['type' => 'ord',     'who' => 'Ama ASSOGBA',  'desc' => 'Ordonnance · Amlodipine 5mg',        'date' => 'Aujourd’hui · 08:50'],
  ['type' => 'facture', 'who' => 'Kossi AGBEKO', 'desc' => 'Facture C003 — 2 500 FCFA',          'date' => 'Hier · 17:12'],
  ['type' => 'analyse', 'who' => 'Jean KOFFI',   'desc' => 'Analyse · NFS complète',             'date' => 'Hier · 16:05'],
  ['type' => 'consult', 'who' => 'Ama ASSOGBA',  'desc' => 'Consultation · contrôle tension',   'date' => '14/09/2026'],
];

if ($pdo) {
    try {
        // Requêtes volontairement portables (MySQL ET SQLite).
        $today      = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $monthEnd   = date('Y-m-01', strtotime('first day of next month'));

        $solde = (int)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM transactions_encaissement WHERE statut='reussi'")->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(date_consultation) = ?");
        $stmt->execute([$today]);
        $patients_j = (int)$stmt->fetchColumn();

        $factures_at     = (int)$pdo->query("SELECT COUNT(*) FROM factures WHERE statut='en_attente'")->fetchColumn();
        $patients_suivis = (int)$pdo->query("SELECT COUNT(*) FROM patients")->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordonnances WHERE date_ordonnance >= ? AND date_ordonnance < ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $ord_mois = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE date_consultation >= ? AND date_consultation < ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $consult_mois = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM factures WHERE statut='payee' AND date_facture >= ? AND date_facture < ?");
        $stmt->execute([$monthStart, $monthEnd]);
        $recettes_mois = (int)$stmt->fetchColumn();

        // Activité récente construite en PHP : évite CONCAT/UNION
        // non portables entre MySQL et SQLite.
        $activite_rows = [];
        foreach ($pdo->query("SELECT p.nom AS who, c.diagnostic AS detail, c.date_consultation AS dt
                              FROM consultations c JOIN patients p ON p.id = c.patient_id") as $r) {
            $activite_rows[] = ['type' => 'consult', 'who' => $r['who'],
                'desc' => 'Consultation · ' . ($r['detail'] ?? ''), 'dt' => $r['dt']];
        }
        foreach ($pdo->query("SELECT p.nom AS who, o.notes AS detail, o.date_ordonnance AS dt
                              FROM ordonnances o JOIN patients p ON p.id = o.patient_id") as $r) {
            $activite_rows[] = ['type' => 'ord', 'who' => $r['who'],
                'desc' => 'Ordonnance · ' . ($r['detail'] ?? ''), 'dt' => $r['dt']];
        }
        foreach ($pdo->query("SELECT p.nom AS who, fa.code AS code, fa.prix AS prix, f.date_facture AS dt
                              FROM factures f
                              JOIN patients p ON p.id = f.patient_id
                              JOIN facture_actes fa ON fa.facture_id = f.id") as $r) {
            $activite_rows[] = ['type' => 'facture', 'who' => $r['who'],
                'desc' => 'Facture ' . $r['code'] . ' — ' . number_format((int)$r['prix'], 0, ',', ' ') . ' FCFA', 'dt' => $r['dt']];
        }
        if ($activite_rows) {
            usort($activite_rows, fn($a, $b) => strcmp((string)$b['dt'], (string)$a['dt']));
            $activite = array_slice(array_map(fn($r) => [
                'type' => $r['type'], 'who' => $r['who'], 'desc' => $r['desc'],
                'date' => date('d/m/Y · H:i', strtotime($r['dt'])),
            ], $activite_rows), 0, 8);
        }
    } catch (Exception $e) { /* garde les valeurs par défaut */ }
}

$aujourdhui = date('l d F Y');
$aujourdhui_fr = [
    'Monday'=>'lundi','Tuesday'=>'mardi','Wednesday'=>'mercredi','Thursday'=>'jeudi',
    'Friday'=>'vendredi','Saturday'=>'samedi','Sunday'=>'dimanche'
];
$mois_fr = [
    'January'=>'janvier','February'=>'février','March'=>'mars','April'=>'avril',
    'May'=>'mai','June'=>'juin','July'=>'juillet','August'=>'août',
    'September'=>'septembre','October'=>'octobre','November'=>'novembre','December'=>'décembre'
];
foreach ($mois_fr as $en => $fr) $aujourdhui = str_replace($en, $fr, $aujourdhui);
foreach ($aujourdhui_fr as $en => $fr) $aujourdhui = str_replace($en, $fr, $aujourdhui);
$aujourdhui = ucfirst($aujourdhui);

include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Bonjour, <?= htmlspecialchars($medecin_nom) ?></h1>
  </div>
  <a class="btn btn--primary" href="/dossier">
    <i class="bi bi-person-plus"></i>
    Nouveau dossier
  </a>
</div>

<!-- ===== Solde KondjiPay — grande carte ===== -->
<div class="balance-card">
  <div class="balance-card-top">
    <span class="balance-card-label"><i class="bi bi-wallet2"></i> Solde KondjiPay</span>
    <span class="balance-card-tag">Disponible</span>
  </div>
  <div class="balance-card-value"><?= number_format($solde, 0, ',', ' ') ?> <small>FCFA</small></div>
  <div class="balance-card-foot">
    <span><i class="bi bi-calendar3"></i> <?= htmlspecialchars($aujourdhui) ?></span>
  </div>
</div>

<!-- ===== Actions rapides ===== -->
<div class="grid grid-actions" style="margin-top:18px;">
  <a class="action-btn" href="/encaisser">
    <span class="ico"><i class="bi bi-wallet2"></i></span>
    <strong>Encaisser</strong>
    <small>Générer un QR KondjiPay</small>
  </a>
  <a class="action-btn" href="/dossier">
    <span class="ico"><i class="bi bi-clipboard2-pulse"></i></span>
    <strong>Dossier médical</strong>
    <small>Rechercher un patient</small>
  </a>
  <a class="action-btn action-btn--warn" href="/facturer">
    <span class="ico"><i class="bi bi-receipt"></i></span>
    <strong>Facturer</strong>
    <small>Actes médicaux</small>
  </a>
  <a class="action-btn action-btn--info" href="/ordonnance">
    <span class="ico"><i class="bi bi-capsule"></i></span>
    <strong>Ordonnance</strong>
    <small>Prescrire des médicaments</small>
  </a>
</div>

<!-- ===== Cartes stats (retirées : solde en grande carte ci-dessus,
       patients du jour / factures en attente / ordonnances du mois masquées) =====
<div class="grid grid-stats">
  <div class="stat">
    <div>
      <div class="stat-label">Solde KondjiPay</div>
      <div class="stat-value"><?= number_format($solde, 0, ',', ' ') ?> FCFA</div>
    </div>
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M3 6h18v3H3zm0 5h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zm3 3v2h6v-2z"/></svg>
    </div>
  </div>

  <div class="stat stat--info">
    <div>
      <div class="stat-label">Patients aujourd'hui</div>
      <div class="stat-value"><?= $patients_j ?></div>
    </div>
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm0 2c-4 0-8 2-8 6v2h16v-2c0-4-4-6-8-6z"/></svg>
    </div>
  </div>

  <div class="stat stat--warn">
    <div>
      <div class="stat-label">Factures en attente</div>
      <div class="stat-value"><?= $factures_at ?></div>
    </div>
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M6 2h9l5 5v15H6zm8 1v5h5"/></svg>
    </div>
  </div>

  <div class="stat">
    <div>
      <div class="stat-label">Ordonnances ce mois</div>
      <div class="stat-value"><?= $ord_mois ?></div>
    </div>
    <div class="stat-icon">
      <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M5 3h14v18H5zm2 4h10v2H7zm0 4h10v2H7zm0 4h6v2H7z"/></svg>
    </div>
  </div>
</div>
-->

<!-- ===== Activité + Cabinet ===== -->
<div class="grid grid-cols-2" style="margin-top:18px;">
  <div class="card">
    <div class="card-title"><span class="dot"></span> Activité récente</div>
    <div class="list">
      <?php foreach ($activite as $a):
          $pill_class = 'pill--' . $a['type'];
          $label = ['consult'=>'Consultation','ord'=>'Ordonnance','facture'=>'Facture','analyse'=>'Analyse'][$a['type']] ?? $a['type'];
      ?>
        <div class="list-item">
          <span class="pill <?= $pill_class ?>"><?= htmlspecialchars($label) ?></span>
          <div class="grow">
            <div class="who"><?= htmlspecialchars($a['who']) ?></div>
            <div class="meta"><?= htmlspecialchars($a['desc']) ?></div>
          </div>
          <div class="meta"><?= htmlspecialchars($a['date']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-title">
      <i class="bi bi-hospital"></i>
      Cabinet
    </div>
    <div class="kv" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--line);">
      <span style="color:var(--muted)">Patients suivis</span>
      <strong><?= $patients_suivis ?></strong>
    </div>
    <div class="kv" style="display:flex; justify-content:space-between; padding:10px 0; border-bottom:1px solid var(--line);">
      <span style="color:var(--muted)">Consultations ce mois</span>
      <strong><?= $consult_mois ?></strong>
    </div>
    <div class="kv" style="display:flex; justify-content:space-between; padding:10px 0;">
      <span style="color:var(--muted)">Recettes du mois</span>
      <strong style="color:var(--accent-2)"><?= number_format($recettes_mois, 0, ',', ' ') ?> FCFA</strong>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php';


