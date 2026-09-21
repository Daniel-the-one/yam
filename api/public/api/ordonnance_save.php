<?php
/**
 * /api/ordonnance_save.php
 * POST : patient, notes, med[0..n][nom|quantite|unite|frequence]
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();
csrf_require();

$patient = (int)($_POST['patient'] ?? 0);
$notes   = trim($_POST['notes'] ?? '');
$meds    = [];
if (isset($_POST['med']) && is_array($_POST['med'])) {
    foreach ($_POST['med'] as $m) {
        $nom = trim($m['nom'] ?? '');
        if ($nom === '') continue;
        $meds[] = [
            'nom'       => $nom,
            'quantite'  => max(1, (int)($m['quantite'] ?? 1)),
            'unite'     => trim($m['unite'] ?? ''),
            'frequence' => trim($m['frequence'] ?? ''),
        ];
    }
}

if (!$patient || !count($meds)) {
    echo json_encode(['ok'=>false, 'message'=>'Patient ou médicaments manquants.']);
    exit;
}

$pdo = db_connect();
if (!$pdo) {
    echo json_encode(['ok'=>true,'message'=>'Ordonnance enregistrée (mode démo).','reset'=>true]);
    exit;
}
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare("INSERT INTO ordonnances (patient_id, notes) VALUES (?,?)");
    $ins->execute([$patient, $notes]);
    $ord_id = (int)$pdo->lastInsertId();
    $im = $pdo->prepare("INSERT INTO ordonnance_medicaments (ordonnance_id, nom, quantite, unite, frequence) VALUES (?,?,?,?,?)");
    foreach ($meds as $m) $im->execute([$ord_id, $m['nom'], $m['quantite'], $m['unite'], $m['frequence']]);
    $pdo->commit();
    echo json_encode(['ok'=>true, 'message'=>'Ordonnance #' . $ord_id . ' enregistrée · ' . count($meds) . ' médicament(s)', 'reset'=>true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
}
