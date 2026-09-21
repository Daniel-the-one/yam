<?php
/**
 * logout.php — Déconnexion (page) puis redirection vers la connexion.
 * L'endpoint JSON reste disponible sur /api/auth/logout.php.
 */
require_once __DIR__ . '/api/auth.php';
auth_logout();
header('Location: /pages/login');
exit;