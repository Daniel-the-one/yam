<?php
/**
 * KondjiPro — Espace patient : Trouver un médecin
 * Liste des médecins du cabinet (photo, nom, spécialité, téléphone)
 * avec bouton « Prendre rendez-vous ».
 */
$page_title = 'Trouver un médecin';
require_once __DIR__ . '/_init.php';

$pdo = db_connect();
$medecins = [];
if ($pdo) {
    try {
        $medecins = $pdo->query("SELECT * FROM medecins ORDER BY prenom, nom")->fetchAll();
    } catch (Exception $e) {}
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Trouver un médecin</h1>
    <div class="sub">Choisissez un praticien puis prenez rendez-vous en ligne.</div>
  </div>
</div>

<?php if (!$medecins): ?>
  <div class="card">
    <div class="empty" style="padding:40px;">
      <i class="bi bi-person-badge" style="font-size:34px; opacity:.4; display:block; margin-bottom:10px;"></i>
      Aucun médecin n'est encore enregistré dans le cabinet.
    </div>
  </div>
<?php else: ?>
  <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
    <?php foreach ($medecins as $m):
        $nom_complet = trim(($m['prenom'] ?? '') . ' ' . ($m['nom'] ?? ''));
        if ($nom_complet === '') $nom_complet = 'Médecin';
        $init = '';
        foreach (preg_split('/\s+/', $nom_complet) as $w) {
            $init .= mb_strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($init) >= 2) break;
        }
        if ($init === '') $init = 'MD';
        // Photo : seule une image data URI (base64) est acceptée — jamais un
        // javascript: ou une URL externe (anti-XSS).
        $photo = $m['photo'] ?? null;
        if ($photo !== null && !str_starts_with($photo, 'data:image/')) $photo = null;
    ?>
      <div class="card" style="display:flex; flex-direction:column; gap:12px;">
        <div style="display:flex; align-items:center; gap:14px;">
          <?php if (!empty($photo)): ?>
            <img src="<?= htmlspecialchars($photo) ?>" alt="Photo" class="avatar-img" style="width:52px; height:52px; border-radius:50%; object-fit:cover;">
          <?php else: ?>
            <span class="avatar-letters" style="width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; background:rgba(16,185,129,.14); color:var(--accent-2); font-weight:700; font-size:18px;">
              <?= htmlspecialchars($init) ?>
            </span>
          <?php endif; ?>
          <div>
            <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($nom_complet) ?></div>
            <div class="meta" style="color:var(--muted);">
              <i class="bi bi-briefcase"></i> <?= htmlspecialchars($m['specialite'] ?? 'Médecin généraliste') ?>
            </div>
          </div>
        </div>

        <div class="kv" style="display:flex; justify-content:space-between; padding:8px 0; border-top:1px solid var(--line);">
          <span style="color:var(--muted)">Téléphone</span>
          <strong><?= htmlspecialchars($m['telephone'] ?? '—') ?></strong>
        </div>

        <a class="btn btn--primary btn--block" href="rendez-vous?medecin=<?= (int)$m['id'] ?>">
          <i class="bi bi-calendar-plus"></i>
          Prendre rendez-vous
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php';