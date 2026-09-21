<?php
/**
 * /api/auth/login.php — Connexion KondjiPro.
 *
 * POST (JSON ou formulaire) :
 *   identifier : username, email ou phone_number
 *   password   : mot de passe en clair
 *
 * Réponse :
 *   200 { user: {...}, message: "Connexion réussie" }
 *   401 { error: "invalid_credentials", message: "Identifiants incorrects." }
 *   400 { error: "validation", message: "..." }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../auth.php';

session_start_secure();

// Rate limiting : max 10 tentatives par IP toutes les 15 minutes.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl = rate_limit_check('login:' . $ip, 10, 900);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode([
        'error'      => 'rate_limited',
        'message'    => 'Trop de tentatives. Réessayez dans ' . $rl['retry_after'] . ' secondes.',
        'retry_after' => $rl['retry_after'],
    ]);
    exit;
}

// Lecture du corps (JSON ou POST classique).
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '' && $raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) $body = $json;
}
$identifier = trim($body['identifier'] ?? ($_POST['identifier'] ?? ''));
$password   = (string)($body['password'] ?? ($_POST['password'] ?? ''));

if ($identifier === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'error'   => 'validation',
        'message' => 'Identifiant et mot de passe requis.',
    ]);
    exit;
}

$user = auth_attempt($identifier, $password);

if (!$user) {
    http_response_code(401);
    echo json_encode([
        'error'   => 'invalid_credentials',
        'message' => 'Identifiants incorrects.',
    ]);
    exit;
}

echo json_encode([
    'user'    => $user,
    'message' => 'Connexion réussie.',
]);