<?php
/**
 * /api/ordonnances.php — Lectures (espace patient).
 *
 *   GET → ordonnances du patient connecté, chacune avec ses médicaments
 *         (jointure pivot `ordonnance_medicaments`).
 *
 * Réponse : { ok, ordonnances: [ { id, date_ordonnance, notes, medicaments: [
 *              { id, nom, quantite, unite, frequence } ] } ] }
 *
 * Même modèle que rdv.php (auth → patient_for_user → SQL → JSON).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/patient.php';

$user = auth_require();
$patient = patient_for_user((int)$user['id'], $user['phone_number'] ?? null);
if (!$patient) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Aucune fiche patient liée à ce compte.']);
    exit;
}
$patientId = (int)$patient['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$pdo = db_connect();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Base de données indisponible.']);
    exit;
}

try {
    // Ordonnances du patient (les plus récentes d'abord).
    $stmt = $pdo->prepare(
        "SELECT id, date_ordonnance, notes
           FROM ordonnances
          WHERE patient_id = ? AND patient_id IS NOT NULL
          ORDER BY date_ordonnance DESC"
    );
    $stmt->execute([$patientId]);
    $ordonnances = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Médicaments de chaque ordonnance (pivot).
    $stmtMeds = $pdo->prepare(
        "SELECT id, nom, quantite, unite, frequence
           FROM ordonnance_medicaments
          WHERE ordonnance_id = ?
          ORDER BY id"
    );
    foreach ($ordonnances as &$o) {
        $stmtMeds->execute([(int)$o['id']]);
        $o['medicaments'] = $stmtMeds->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($o);

    echo json_encode(['ok' => true, 'ordonnances' => $ordonnances], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    error_log('[kondjipro] ordonnances.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Erreur de lecture.']);
    exit;
}
