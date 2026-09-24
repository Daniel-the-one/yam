<?php
/**
 * /api/check_phone.php
 * Vérification de la disponibilité et validité d'un numéro de téléphone.
 *
 * GET ?phone=+22890...&role=patient|medecin&exclude_id=123
 *
 * Réponse : { ok: bool, available: bool, valid: bool, message?: string, normalized?: string }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

session_start_secure();

$phone = trim($_GET['phone'] ?? $_POST['phone'] ?? '');
if ($phone === '') {
    echo json_encode([
        'ok'        => true,
        'available' => false,
        'valid'     => false,
        'message'   => 'Numéro de téléphone requis.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = trim($_GET['role'] ?? $_POST['role'] ?? ($_SESSION['role'] ?? ''));
$excludeRecordId = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : (isset($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : null);
$excludeUserId   = $_SESSION['user_id'] ?? null;

$check = phone_is_taken($phone, $role ?: null, $excludeRecordId, $excludeUserId);

echo json_encode([
    'ok'         => true,
    'available'  => !$check['taken'],
    'valid'      => $check['valid'],
    'normalized' => $check['normalized'] ?? null,
    'message'    => $check['message'] ?? ($check['taken'] ? 'Ce numéro de téléphone est déjà utilisé.' : 'Numéro disponible.'),
], JSON_UNESCAPED_UNICODE);
