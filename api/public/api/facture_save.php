<?php
/**
 * /api/facture_save.php
 * POST : patient, actes[]=code, prix, libelle
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();

$patient  = (int)($_POST['patient'] ?? 0);
$actes_in = $_POST['actes'] ?? [];

$pdo = db_connect();
if (!$pdo) {
    // Mode démo : on accepte et on confirme
    echo json_encode(['ok'=>true,'message'=>'Facture enregistrée (mode démo).','reset'=>true]);
    exit;
}
if (!$patient || !is_array($actes_in) || !count($actes_in)) {
    echo json_encode(['ok'=>false,'message'=>'Patient ou actes manquants.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Catalogue des actes défini en PHP : portable MySQL / SQLite
    // (l'ancienne requête VALUES ROW(...) était spécifique à MySQL 8).
    $catalogue = [
        'C001' => ['Consultation',      5000],
        'C002' => ['Pansement',         3000],
        'C003' => ['Injection',         2500],
        'C004' => ['Soins infirmiers',  2000],
        'C005' => ['Visite à domicile', 10000],
        'C006' => ['Certificat médical', 2500],
    ];
    $rows = [];
    foreach ($actes_in as $code) {
        $code = (string)$code;
        if (isset($catalogue[$code])) {
            $rows[] = ['code' => $code, 'libelle' => $catalogue[$code][0], 'prix' => $catalogue[$code][1]];
        }
    }
    if (!$rows) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false, 'message'=>'Aucun acte valide.']);
        exit;
    }

    $total = 0;
    foreach ($rows as $r) $total += (int)$r['prix'];

    $ins = $pdo->prepare("INSERT INTO factures (patient_id, total, statut) VALUES (?,?, 'en_attente')");
    $ins->execute([$patient, $total]);
    $facture_id = (int)$pdo->lastInsertId();

    $ia = $pdo->prepare("INSERT INTO facture_actes (facture_id, code, libelle, prix) VALUES (?,?,?,?)");
    foreach ($rows as $r) $ia->execute([$facture_id, $r['code'], $r['libelle'], (int)$r['prix']]);

    $pdo->commit();
    echo json_encode(['ok'=>true, 'message'=>'Facture #' . $facture_id . ' enregistrée · ' . number_format($total,0,',',' ') . ' FCFA', 'reset'=>true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
}
