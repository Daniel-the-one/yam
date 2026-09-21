<?php
/**
 * Barre supérieure (topbar) commune à toutes les pages.
 * Variables attendues (optionnelles) :
 *   $page_title  string  — titre de l'onglet / breadcrumb
 *
 * Le profil du médecin est chargé depuis la base (valeur réelle).
 * Le solde KondjiPay est affiché en grande carte sur le dashboard
 * (plus dans le header).
 */
$page_title = $page_title ?? 'KondjiPro';

// Rôle connecté (défaut : médecin pour compatibilité avec les pages existantes).
$role = $_SESSION['role'] ?? 'medecin';

$pdo = db_connect();
$medecin = null;
if ($pdo) {
    try {
        $medecin = $pdo->query("SELECT * FROM medecins ORDER BY id LIMIT 1")->fetch();
    } catch (Exception $e) {}
}
if (!$medecin) {
    $medecin = ['prenom'=>'Mensah','nom'=>'','telephone'=>'+229 97 00 00 00',
                'specialite'=>'Médecin généraliste','photo'=>null];
}
$medecin_nom = trim('Dr. ' . $medecin['prenom'] . ' ' . $medecin['nom']);
$medecin_init = '';
foreach (preg_split('/\s+/', trim($medecin['prenom'] . ' ' . $medecin['nom'])) as $w) {
    $medecin_init .= mb_strtoupper(mb_substr($w, 0, 1));
    if (mb_strlen($medecin_init) >= 2) break;
}
if ($medecin_init === '') $medecin_init = 'DM';

// Profil affiché dans la topbar selon le rôle.
$profile_name = $medecin_nom;
$profile_init  = $medecin_init;
$profile_photo = $medecin['photo'] ?? null;
// Normaliser chemins relatifs → absolus
if ($profile_photo !== null && $profile_photo !== '' && !str_starts_with($profile_photo, '/')) {
    $profile_photo = '/' . $profile_photo;
}
if ($role === 'patient') {
    $profile_name = $_SESSION['name'] ?? 'Patient';
    $profile_init  = '';
    foreach (preg_split('/\s+/', trim($profile_name)) as $w) {
        $profile_init .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($profile_init) >= 2) break;
    }
    if ($profile_init === '') $profile_init = 'PA';
    // Charger la photo_profil du patient depuis la base
    $profile_photo = null;
    if ($pdo && isset($_SESSION['patient_id']) && (int)$_SESSION['patient_id'] > 0) {
        try {
            $ppStmt = $pdo->prepare('SELECT photo_profil FROM patients WHERE id = :pid LIMIT 1');
            $ppStmt->execute([':pid' => (int)$_SESSION['patient_id']]);
            $ppRow = $ppStmt->fetch();
            if ($ppRow && !empty($ppRow['photo_profil'])) {
                $profile_photo = $ppRow['photo_profil'];
                if (!str_starts_with($profile_photo, '/')) $profile_photo = '/' . $profile_photo;
            }
        } catch (Exception $e) {}
    }
}

/* --------- Notifications (depuis la base, avec fallback démo) --------- */
/* Réservées au rôle médecin : le patient n'a pas de file d'encaissement,
   d'ordonnances à rédiger ni de consultations à valider. */
$notifs = [];
$notif_count = 0;
$notifs_lues_le = null;
if ($role !== 'patient' && $pdo) {
if (isset($_COOKIE['notifs_lues_le'])) {
    $t = strtotime((string)$_COOKIE['notifs_lues_le']);
    if ($t !== false) $notifs_lues_le = date('Y-m-d H:i:s', $t);
}
if ($pdo) {
    try {
        // Plage temporelle : toute l'histoire si jamais lu, sinon après la dernière lecture
        $borne = $notifs_lues_le ? " date_facture > " . $pdo->quote($notifs_lues_le) : "1=1";
        // Jalon temporel réutilisable pour les autres entités (« strictement après »)
        $apres = $notifs_lues_le ? $pdo->quote($notifs_lues_le) : "'1970-01-01 00:00:00'";

        // Factures en attente nouvellement apparues depuis la dernière lecture
        $r = $pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(total),0) AS t FROM factures WHERE statut='en_attente' AND $borne")->fetch();
        if ((int)$r['n'] > 0) {
            $notifs[] = ['type'=>'facture', 'href'=>'/encaisser.php',
                'title'=>(int)$r['n'].' facture(s) en attente',
                'meta'=>'Total : '.number_format((int)$r['t'], 0, ',', ' ').' FCFA à encaisser'];
            $notif_count += (int)$r['n'];
        }
        // Ordonnances du jour (uniquement nouvelles depuis la dernière lecture)
        $and_recent = $notifs_lues_le ? " AND date_ordonnance > " . $pdo->quote($notifs_lues_le) : "";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ordonnances WHERE DATE(date_ordonnance) = ?$and_recent");
        $stmt->execute([date('Y-m-d')]);
        $n = (int)$stmt->fetchColumn();
        if ($n > 0) {
            $notifs[] = ['type'=>'ord', 'href'=>'/ordonnance.php',
                'title'=>$n.' ordonnance(s) aujourd\'hui', 'meta'=>'Prescriptions du jour'];
            $notif_count += $n;
        }
        // Consultations du jour
        $and_recent = $notifs_lues_le ? " AND date_consultation > " . $pdo->quote($notifs_lues_le) : "";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM consultations WHERE DATE(date_consultation) = ?$and_recent");
        $stmt->execute([date('Y-m-d')]);
        $n = (int)$stmt->fetchColumn();
        if ($n > 0) {
            $notifs[] = ['type'=>'consult', 'href'=>'/dossier.php',
                'title'=>$n.' consultation(s) aujourd\'hui', 'meta'=>'Activité du cabinet'];
            $notif_count += $n;
        }
        // Nouveaux patients du jour
        $and_recent = $notifs_lues_le ? " AND created_at > " . $pdo->quote($notifs_lues_le) : "";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE DATE(created_at) = ?$and_recent");
        $stmt->execute([date('Y-m-d')]);
        $n = (int)$stmt->fetchColumn();
        if ($n > 0) {
            $notifs[] = ['type'=>'patient', 'href'=>'/dossier.php',
                'title'=>$n.' nouveau(x) patient(s)', 'meta'=>'Ajoutés aujourd\'hui'];
            $notif_count += $n;
        }
    } catch (Exception $e) {}
}
}
if (!$notifs) {
    $notifs[] = ['type'=>'info', 'href'=>'/dashboard.php',
        'title'=>'Aucune notification', 'meta'=>'Tout est à jour.'];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php
// CSRF: génère le token et l'expose via meta tag pour le JS
require_once __DIR__ . '/../api/auth.php';
$csrf_token = csrf_generate();
?>
<meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">
<title><?= htmlspecialchars($page_title) ?> · KondjiPro</title>
<link rel="stylesheet" href="/assets/css/style.css?v=5">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="icon" href="/assets/img/kondjipro.png" type="image/png">
</head>
<body>

<!-- ===== MODAL PROFIL MÉDECIN (rôle médecin uniquement) ===== -->
<?php if ($role !== 'patient'): ?>
<div class="modal-backdrop" id="modal-medecin" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <h3>Profil du médecin</h3>
      <button type="button" class="modal-close" data-close="modal-medecin" aria-label="Fermer">&times;</button>
    </div>
    <form id="form-medecin" autocomplete="off">
      <div class="photo-upload">
        <div class="photo-preview" id="medecin-photo-preview">
          <?php if (!empty($medecin['photo'])): ?>
            <img src="<?= htmlspecialchars($medecin['photo']) ?>" alt="Photo du médecin">
          <?php else: ?>
            <span><?= htmlspecialchars($medecin_init) ?></span>
          <?php endif; ?>
        </div>
        <div>
          <label class="btn btn--ghost btn--sm" for="medecin-photo">Choisir une photo</label>
          <input type="file" id="medecin-photo" name="photo" accept="image/*" hidden>
          <p class="meta" style="margin-top:6px;">JPG, PNG ou WebP</p>
        </div>
      </div>

      <div class="form-row">
        <label for="medecin-prenom">Prénom</label>
        <input id="medecin-prenom" name="prenom" class="input" value="<?= htmlspecialchars($medecin['prenom']) ?>" required>
      </div>
      <div class="form-row">
        <label for="medecin-nom">Nom</label>
        <input id="medecin-nom" name="nom" class="input" value="<?= htmlspecialchars($medecin['nom']) ?>">
      </div>
      <div class="form-row">
        <label for="medecin-tel">Téléphone</label>
        <input id="medecin-tel" name="telephone" class="input" value="<?= htmlspecialchars($medecin['telephone']) ?>">
      </div>
      <div class="form-row">
        <label for="medecin-spec">Spécialité</label>
        <input id="medecin-spec" name="specialite" class="input" value="<?= htmlspecialchars($medecin['specialite']) ?>">
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn--ghost" data-close="modal-medecin">Annuler</button>
        <button type="submit" class="btn btn--primary">Enregistrer</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="app">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <main class="main">
    <!-- ===== TOPBAR ===== -->
    <header class="topbar">
      <?php if ($role !== 'patient'): ?>
      <form class="search" onsubmit="event.preventDefault()" autocomplete="off">
        <i class="bi bi-search"></i>
        <input type="text" placeholder="Rechercher un patient par nom ou téléphone..." aria-label="Rechercher un patient">
      </form>
      <?php endif; ?>

      <div class="topbar-right">
        <?php if ($role !== 'patient'): ?>
        <div class="notif-wrap">
          <button class="icon-btn" id="btn-notif" title="Notifications" aria-label="Notifications">
            <i class="bi bi-bell"></i>
            <?php if ($notif_count > 0): ?><span class="badge"><?= $notif_count ?></span><?php endif; ?>
          </button>

          <div class="notif-panel" id="notif-panel" hidden>
            <div class="notif-head">
              <h3>Notifications</h3>
              <button type="button" class="modal-close" data-close="notif-panel" aria-label="Fermer">&times;</button>
            </div>
            <div class="notif-list">
              <?php foreach ($notifs as $n): ?>
                <a class="notif-item" href="<?= htmlspecialchars($n['href']) ?>">
                  <span class="notif-ico notif-ico--<?= $n['type'] ?>"><i class="bi bi-<?= [
                    'facture'=>'receipt', 'ord'=>'capsule', 'consult'=>'clipboard2-pulse',
                    'patient'=>'person-plus', 'info'=>'bell',
                  ][$n['type']] ?>"></i></span>
                  <span class="notif-body">
                    <span class="notif-title"><?= htmlspecialchars($n['title']) ?></span>
                    <span class="notif-meta"><?= htmlspecialchars($n['meta']) ?></span>
                  </span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($role === 'patient'): ?>
        <a href="/patient/profil" class="icon-btn icon-btn--doctor"
           title="Mon profil" aria-label="Mon profil">
          <?php if (!empty($profile_photo)): ?>
            <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Photo de profil" class="avatar-img">
          <?php else: ?>
            <span class="avatar-letters"><?= htmlspecialchars($profile_init) ?></span>
          <?php endif; ?>
        </a>
        <?php else: ?>
        <button class="icon-btn icon-btn--doctor" id="btn-medecin"
                title="Profil du médecin"
                aria-label="Profil du médecin">
          <?php if (!empty($profile_photo)): ?>
            <img src="<?= htmlspecialchars($profile_photo) ?>" alt="Photo de profil" class="avatar-img">
          <?php else: ?>
            <span class="avatar-letters"><?= htmlspecialchars($profile_init) ?></span>
          <?php endif; ?>
        </button>
        <?php endif; ?>
      </div>
    </header>

    <section class="content">