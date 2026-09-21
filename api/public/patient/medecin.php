<?php
/**
 * KondjiPro — Espace patient : Profil médecin
 * Fiche complète d'un médecin (photo, spécialité, bio, horaires)
 * + boutons : Demand Consultation (appel vidéo), Prendre RDV, Appeler.
 *
 * Usage : /patient/medecin?id=3
 */
$page_title = 'Profil médecin';
require_once __DIR__ . '/_init.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /patient/medecins');
    exit;
}

$pdo = db_connect();
$medecin = null;
if ($pdo) {
    require_once __DIR__ . '/../api/patient.php';
    ensure_patient_schema();
    try {
        $stmt = $pdo->prepare(
            'SELECT id, prenom, nom, telephone, specialite, photo, bio, horaires, accepte_rdv
             FROM medecins WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $medecin = $stmt->fetch();
    } catch (Exception $e) {}
}

if (!$medecin) {
    // Médecin introuvable → retour à la liste
    header('Location: /patient/medecins');
    exit;
}

$nom_complet = trim(($medecin['prenom'] ?? '') . ' ' . ($medecin['nom'] ?? ''));
if ($nom_complet === '') $nom_complet = 'Médecin';
$init = '';
foreach (preg_split('/\s+/', $nom_complet) as $w) {
    $init .= mb_strtoupper(mb_substr($w, 0, 1));
    if (mb_strlen($init) >= 2) break;
}
if ($init === '') $init = 'MD';

$photo = $medecin['photo'] ?? null;
// Normaliser : les anciens chemins relatifs → absolu
if ($photo !== null && $photo !== '' && !str_starts_with($photo, '/')) {
    $photo = '/' . $photo;
}
$has_photo = ($photo !== null && $photo !== '');
$bio = trim($medecin['bio'] ?? '');
$horaires = trim($medecin['horaires'] ?? '');
$telephone = trim($medecin['telephone'] ?? '');
$specialite = trim($medecin['specialite'] ?? 'Médecin généraliste');
$accepte_rdv = (int)($medecin['accepte_rdv'] ?? 1);
$tel_clean = preg_replace('/[^\d+]/', '', $telephone);

// Compter les RDV du patient avec ce médecin (pour afficher un historique éventuel)
$nb_rdv = 0;
if ($patientId > 0 && $pdo) {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM rendez_vous WHERE patient_id = :pid AND medecin_id = :mid');
        $stmt->execute([':pid' => $patientId, ':mid' => $id]);
        $nb_rdv = (int)$stmt->fetchColumn();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<!-- ── Retour ── -->
<div style="margin-bottom:16px;">
  <a href="/patient/medecins" class="btn btn--ghost btn--sm">
    <i class="bi bi-arrow-left"></i> Retour à la liste
  </a>
</div>

<!-- ── Carte profil principal ── -->
<div class="card" style="padding:0; overflow:hidden;">
  <!-- Bandeau vert en haut -->
  <div style="height:100px; background:linear-gradient(135deg, var(--accent-2) 0%, #047857 100%); position:relative;"></div>

  <div style="padding:0 24px 24px; margin-top:-48px;">
    <!-- Photo de profil + infos -->
    <div style="display:flex; align-items:flex-end; gap:18px; margin-bottom:20px;">
      <?php if ($has_photo): ?>
        <img src="<?= htmlspecialchars($photo) ?>" alt="Photo du Dr <?= htmlspecialchars($nom_complet) ?>"
             style="width:96px; height:96px; border-radius:50%; object-fit:cover; border:4px solid #fff;
                    box-shadow:0 2px 12px rgba(0,0,0,.15); flex-shrink:0;">
      <?php else: ?>
        <span style="width:96px; height:96px; border-radius:50%; display:flex; align-items:center; justify-content:center;
                     background:rgba(16,185,129,.14); color:var(--accent-2); font-weight:700; font-size:32px;
                     border:4px solid #fff; box-shadow:0 2px 12px rgba(0,0,0,.15); flex-shrink:0;">
          <?= htmlspecialchars($init) ?>
        </span>
      <?php endif; ?>

      <div style="padding-bottom:4px; min-width:0;">
        <h1 style="margin:0; font-size:24px;">Dr <?= htmlspecialchars($nom_complet) ?></h1>
        <div style="color:var(--muted); margin-top:2px;">
          <i class="bi bi-briefcase"></i> <?= htmlspecialchars($specialite) ?>
        </div>
        <?php if ($accepte_rdv): ?>
          <span style="display:inline-block; margin-top:6px; padding:2px 10px; border-radius:12px; font-size:12px;
                       background:rgba(16,185,129,.12); color:#047857; font-weight:600;">
            <i class="bi bi-check-circle-fill"></i> Accepte les rendez-vous
          </span>
        <?php else: ?>
          <span style="display:inline-block; margin-top:6px; padding:2px 10px; border-radius:12px; font-size:12px;
                       background:rgba(239,68,68,.12); color:#dc2626; font-weight:600;">
            <i class="bi bi-x-circle-fill"></i> Ne prend pas de nouveaux RDV
          </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Infos -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:20px;">
      <div>
        <div class="meta" style="color:var(--muted); margin-bottom:4px;">Téléphone</div>
        <strong><i class="bi bi-telephone"></i> <?= htmlspecialchars($telephone ?: '—') ?></strong>
      </div>
      <div>
        <div class="meta" style="color:var(--muted); margin-bottom:4px;">Spécialité</div>
        <strong><i class="bi bi-award"></i> <?= htmlspecialchars($specialite) ?></strong>
      </div>
      <?php if ($nb_rdv > 0): ?>
      <div>
        <div class="meta" style="color:var(--muted); margin-bottom:4px;">Vos rendez-vous</div>
        <strong><i class="bi bi-calendar-check"></i> <?= $nb_rdv ?> rendez-vous</strong>
      </div>
      <?php endif; ?>
    </div>

    <!-- Bio -->
    <?php if ($bio !== ''): ?>
    <div style="margin-bottom:20px;">
      <div class="meta" style="color:var(--muted); margin-bottom:6px;"><i class="bi bi-info-circle"></i> À propos</div>
      <p style="line-height:1.6; color:var(--text);"><?= nl2br(htmlspecialchars($bio)) ?></p>
    </div>
    <?php endif; ?>

    <!-- Horaires -->
    <?php if ($horaires !== ''): ?>
    <div style="margin-bottom:20px;">
      <div class="meta" style="color:var(--muted); margin-bottom:6px;"><i class="bi bi-clock"></i> Horaires</div>
      <p style="line-height:1.6; color:var(--text);"><?= nl2br(htmlspecialchars($horaires)) ?></p>
    </div>
    <?php endif; ?>

    <!-- ── Boutons d'action ── -->
    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:24px; padding-top:20px; border-top:1px solid var(--line);">

      <!-- Demander une consultation (appel vidéo) -->
      <a class="btn btn--primary" href="/pages/call?to_user_id=<?= (int)$id ?>&from=patient"
         style="flex:1; min-width:180px; text-align:center; padding:12px 16px;">
        <i class="bi bi-camera-video-fill"></i> Demander une consultation
      </a>

      <!-- Prendre rendez-vous -->
      <a class="btn btn--ghost" href="/patient/rendez-vous?medecin=<?= (int)$id ?>"
         style="flex:1; min-width:180px; text-align:center; padding:12px 16px;">
        <i class="bi bi-calendar-plus"></i> Prendre rendez-vous
      </a>

      <!-- Appeler directement -->
      <?php if ($tel_clean !== ''): ?>
      <a class="btn btn--ghost" href="tel:<?= htmlspecialchars($tel_clean) ?>"
         style="flex:1; min-width:180px; text-align:center; padding:12px 16px;">
        <i class="bi bi-telephone-fill"></i> Appeler
      </a>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
