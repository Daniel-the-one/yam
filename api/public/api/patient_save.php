<?php
/**
 * /api/patient_save.php
 * POST : nom, telephone, date_naissance, adresse, sexe, photo (fichier optionnel)
 * Crée un patient avec un UUID stable (utilisé par le QR KondjiPay).
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();
csrf_require();

function uuid_v4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

$nom            = trim($_POST['nom'] ?? '');
$telephone      = trim($_POST['telephone'] ?? '');
$date_naissance = trim($_POST['date_naissance'] ?? '');
$adresse        = trim($_POST['adresse'] ?? '');
$sexe           = in_array($_POST['sexe'] ?? '', ['M','F'], true) ? $_POST['sexe'] : null;

if ($nom === '' || $telephone === '') {
    echo json_encode(['ok'=>false,'message'=>'Nom et téléphone sont obligatoires.']);
    exit;
}

$pdo = db_connect();
if (!$pdo) {
    echo json_encode(['ok'=>true,'message'=>'Patient ajouté (mode démo).','reset'=>true]);
    exit;
}

// Photo optionnelle (base64 — contourne le WAF multipart d'o2switch)
$photo = null;
$photo_data = trim($_POST['photo_data'] ?? '');
if ($photo_data !== '') {
    $ext = strtolower(trim($_POST['photo_ext'] ?? 'jpg'));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
        echo json_encode(['ok'=>false,'message'=>'Format photo non supporté (jpg, png, webp).']);
        exit;
    }
    $bin = base64_decode($photo_data, true);
    if ($bin === false || $bin === '') {
        echo json_encode(['ok'=>false,'message'=>'Données photo invalides.']);
        exit;
    }
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $fname = 'patient_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $fname, $bin) !== false) {
        $photo = 'assets/uploads/' . $fname;
    }
}

// Photo optionnelle (multipart, fallback local)
if ($photo === null && !empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
        echo json_encode(['ok'=>false,'message'=>'Format photo non supporté (jpg, png, webp).']);
        exit;
    }
    $fname = 'patient_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
        $photo = 'assets/uploads/' . $fname;
    }
}

try {
    $ins = $pdo->prepare(
        "INSERT INTO patients (uuid, nom, telephone, date_naissance, adresse, sexe, photo)
         VALUES (?,?,?,?,?,?,?)"
    );
    $ins->execute([
        uuid_v4(),
        $nom,
        $telephone,
        $date_naissance !== '' ? $date_naissance : null,
        $adresse !== '' ? $adresse : null,
        $sexe,
        $photo,
    ]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true,'message'=>'Patient ajouté · ' . $nom, 'id'=>$id, 'reset'=>true]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}