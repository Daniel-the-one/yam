<?php
/**
 * POST /api/encaissement_create.php
 * Crée une demande d'encaissement en attente, sans la marquer comme payée.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db_connect();
if (!$pdo) api_db_unavailable();

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) $payload = $_POST;

$patientId = filter_var($payload['patient_id'] ?? null, FILTER_VALIDATE_INT);
$montant = filter_var($payload['montant'] ?? null, FILTER_VALIDATE_INT);
$motif = trim((string)($payload['motif'] ?? ''));

if (!$patientId || !$montant || $montant < 100 || $montant > 1000000) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Choisissez un patient et un montant entre 100 et 1 000 000 FCFA.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT uuid, nom, telephone FROM patients WHERE id = ?');
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();
    if (!$patient) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Patient introuvable.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (empty($patient['uuid'])) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Cet enregistrement patient ne possède pas d’identifiant QR.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $reference = 'KPRO-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $insert = $pdo->prepare(
        "INSERT INTO transactions_encaissement (patient_id, montant, motif, reference, statut)
         VALUES (?, ?, ?, ?, 'en_attente')"
    );
    $insert->execute([$patientId, $montant, $motif !== '' ? $motif : null, $reference]);

    http_response_code(201);
    echo json_encode([
        'ok' => true,
        'message' => 'Demande enregistrée en attente de confirmation du paiement.',
        'transaction' => [
            'reference' => $reference,
            'patient' => $patient['nom'],
            'telephone' => $patient['telephone'],
            'montant' => $montant,
            'motif' => $motif,
            'statut' => 'en_attente',
        ],
        'qr_data' => 'KONDJIPRO:' . $patient['uuid'] . ':' . $reference . ':' . $montant,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    api_db_failure($e);
}
