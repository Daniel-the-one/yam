<?php
/**
 * Sidebar fixe, thème vert KondjiPro, navigation principale.
 * Le menu dépend du rôle connecté (session) :
 *   - médecin : Accueil, Dossier médical, Encaisser, Facturer, Ordonnance, Contacts
 *   - patient : Accueil, Trouver un médecin, Mes rendez-vous, Mon dossier,
 *               Mes ordonnances, Mes factures
 * Icônes Bootstrap Icons (CDN) — changer une icône = changer la classe
 * `bi bi-xxx` ci-dessous (https://icons.getbootstrap.com).
 */
$raw = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard');
$current = preg_replace('/\.(php|html)$/', '', $raw);

// Rôle depuis la session (défaut : médecin pour compatibilité).
$role = $_SESSION['role'] ?? 'medecin';

if ($role === 'patient') {
  $nav = [
    ['/patient/accueil',    'Accueil',            'bi-house'],
    ['/patient/medecins',   'Trouver un médecin', 'bi-person-badge'],
    ['/patient/rendez-vous','Mes rendez-vous',    'bi-calendar3'],
    ['/patient/dossier',    'Mon dossier',        'bi-clipboard2-pulse'],
    ['/patient/ordonnances','Mes ordonnances',    'bi-capsule'],
    ['/patient/factures',   'Mes factures',       'bi-receipt'],
    ['/patient/profil',     'Mon profil',         'bi-person-circle'],
  ];
} else {
  $nav = [
    ['/dashboard',  'Accueil',         'bi-house'],
    ['/dossier',    'Dossier médical', 'bi-clipboard2-pulse'],
    ['/encaisser',  'Encaisser',       'bi-wallet2'],
    ['/facturer',   'Facturer',        'bi-receipt'],
    ['/ordonnance', 'Ordonnance',      'bi-capsule'],
    ['/contact',    'Contacts',        'bi-telephone'],
  ];
}
?>
<aside class="sidebar">
  <a class="brand" href="<?= $role === 'patient' ? '/patient/accueil' : '/dashboard' ?>">
    <img class="brand-logo" src="/assets/img/kondjipro.png" alt="KondjiPro">
    <span class="brand-text">Kondji<em>Pro</em></span>
  </a>

  <nav class="nav">
    <?php foreach ($nav as $item):
        [$href, $label, $icon] = $item;
        $active = ($current === basename($href)) ? 'active' : '';
    ?>
      <a href="<?= htmlspecialchars($href) ?>" class="nav-link <?= $active ?>">
        <i class="<?= htmlspecialchars($icon) ?>"></i>
        <span><?= htmlspecialchars($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-foot">
    <a href="/logout" class="nav-link nav-link--logout">
      <i class="bi bi-box-arrow-right"></i>
      <span>Se déconnecter</span>
    </a>
    <small>v1.0 · © KondjiPro</small>
  </div>
</aside>