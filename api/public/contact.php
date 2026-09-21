<?php
/**
 * KondjiPro — Contacts
 * Recherche de contacts (API YAM), boutons d'appel audio/vidéo,
 * et liste des appels manqués et passés (historique local).
 * La logique métier (recherche, sessionStorage, localStorage) est
 * reprise telle quelle de l'ancienne app YAM — voir assets/js/contact.js.
 */
require_once __DIR__ . '/config/db.php';

// Garde d'authentification : redirection vers la connexion si non connecté.
require_once __DIR__ . '/api/auth.php';
auth_redirect_guest('/pages/login');
$page_title = 'Contacts';
include __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <div>
    <h1>Contacts</h1>
    <div class="sub">Recherchez un contact, lancez un appel, et retrouvez vos appels manqués et passés.</div>
  </div>
</div>

<!-- ===== Recherche de contact ===== -->
<div class="card">
  <div class="card-title"><span class="dot"></span> Recherche de contact</div>

  <div class="contact-search">
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
      <path fill="currentColor" d="M10 2a8 8 0 1 1-5.3 14L2 18.7 3.3 20l2.7-2.7A8 8 0 0 1 10 2zm0 2a6 6 0 1 0 0 12 6 6 0 0 0 0-12z"/>
    </svg>
    <input type="search" id="contact-search" placeholder="Rechercher un contact par nom ou téléphone..." autocomplete="off" aria-label="Rechercher un contact">
  </div>

  <div id="contact-error" class="form-error" role="alert" hidden></div>
  <div id="contact-empty" class="empty" hidden></div>
  <div id="contact-list" class="contact-list"></div>
</div>

<!-- ===== Appels manqués et passés ===== -->
<div class="card" style="margin-top:18px;">
  <div class="card-title"><span class="dot"></span> Appels manqués et passés</div>
  <div id="call-history" class="call-history"></div>
</div>

<script src="assets/js/contact.js"></script>
<?php include __DIR__ . '/includes/footer.php';