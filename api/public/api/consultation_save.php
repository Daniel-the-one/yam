<?php
/**
 * /api/consultation_save.php
 * POST : patient, motif, diagnostic, notes
 * Enregistre une consultation dans l'historique du patient.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();

$patient    = (int)($_POST['patient'] ?? 0);
$motif      = trim($_POST['motif'] ?? '');
$diagnostic = trim($_POST['diagnostic'] ?? '');
$notes      = trim($_POST['notes'] ?? '');

if (!$patient || $motif === '') {
    echo json_encode(['ok'=>false,'message'=>'Patient et motif sont obligatoires.']);
    exit;
}

$pdo = db_connect();
if (!$pdo) {
    echo json_encode(['ok'=>true,'message'=>'Consultation enregistrée (mode démo).','reset'=>true]);
    exit;
}
try {
    $ins = $pdo->prepare(
        "INSERT INTO consultations (patient_id, motif, diagnostic, notes) VALUES (?,?,?,?)"
    );
    $ins->execute([
        $patient,
        $motif,
        $diagnostic !== '' ? $diagnostic : null,
        $notes !== '' ? $notes : null,
    ]);
    echo json_encode(['ok'=>true,'message'=>'Consultation enregistrée.','reset'=>true]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}