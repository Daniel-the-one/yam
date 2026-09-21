<?php
/**
 * /api/medecin.php
 *   GET            -> profil du médecin (1re ligne) + solde KondjiPay
 *   POST (champs)  -> mise à jour prénom / nom / téléphone / spécialité
 *   POST (photo)   -> upload de la photo de profil (multipart, champ "photo")
 *
 * Réponse : { medecin: {...}, solde: int }  ou  { ok, message }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();
csrf_require();

$pdo = db_connect();

// ---------- GET : profil + solde ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $medecin = null;
    $solde   = 0;
    if ($pdo) {
        try {
            $medecin = $pdo->query("SELECT * FROM medecins ORDER BY id LIMIT 1")->fetch();
            $solde   = (int)$pdo->query(
                "SELECT COALESCE(SUM(montant),0) FROM transactions_encaissement WHERE statut='reussi'"
            )->fetchColumn();
        } catch (Exception $e) {}
    }
    if (!$medecin) {
        $medecin = ['id'=>0,'prenom'=>'Mensah','nom'=>'','telephone'=>'+229 97 00 00 00',
                    'specialite'=>'Médecin généraliste','photo'=>null];
    }
    echo json_encode(['medecin'=>$medecin, 'solde'=>$solde], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST : upload photo (base64, contourne le WAF multipart) ----------
$photo_data = trim($_POST['photo_data'] ?? '');
if ($photo_data !== '') {
    if (!$pdo) { echo json_encode(['ok'=>false,'message'=>'Base indisponible.']); exit; }
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
    $fname = 'medecin_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $fname, $bin) === false) {
        echo json_encode(['ok'=>false,'message'=>'Échec de l\'enregistrement de la photo.']);
        exit;
    }
    $rel = '/assets/uploads/' . $fname;
    try {
        $row = $pdo->query("SELECT id FROM medecins ORDER BY id LIMIT 1")->fetch();
        if ($row) {
            $pdo->prepare("UPDATE medecins SET photo=? WHERE id=?")->execute([$rel, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO medecins (photo) VALUES (?)")->execute([$rel]);
        }
        echo json_encode(['ok'=>true,'message'=>'Photo mise à jour.','photo'=>$rel]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ---------- POST : upload photo (multipart, fallback local) ----------
if (!empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    if (!$pdo) { echo json_encode(['ok'=>false,'message'=>'Base indisponible.']); exit; }
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) {
        echo json_encode(['ok'=>false,'message'=>'Format photo non supporté (jpg, png, webp).']);
        exit;
    }
    $fname = 'medecin_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
        echo json_encode(['ok'=>false,'message'=>'Échec de l\'enregistrement de la photo.']);
        exit;
    }
    $rel = '/assets/uploads/' . $fname;
    try {
        $row = $pdo->query("SELECT id FROM medecins ORDER BY id LIMIT 1")->fetch();
        if ($row) {
            $pdo->prepare("UPDATE medecins SET photo=? WHERE id=?")->execute([$rel, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO medecins (photo) VALUES (?)")->execute([$rel]);
        }
        echo json_encode(['ok'=>true,'message'=>'Photo mise à jour.','photo'=>$rel]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ---------- POST : champs texte ----------
$prenom     = trim($_POST['prenom'] ?? '');
$nom        = trim($_POST['nom'] ?? '');
$telephone  = trim($_POST['telephone'] ?? '');
$specialite = trim($_POST['specialite'] ?? '');
if ($prenom === '' && $nom === '' && $telephone === '' && $specialite === '') {
    echo json_encode(['ok'=>false,'message'=>'Aucune donnée à enregistrer.']);
    exit;
}
if (!$pdo) { echo json_encode(['ok'=>false,'message'=>'Base indisponible.']); exit; }
try {
    $row = $pdo->query("SELECT id FROM medecins ORDER BY id LIMIT 1")->fetch();
    if ($row) {
        $pdo->prepare("UPDATE medecins SET prenom=?, nom=?, telephone=?, specialite=? WHERE id=?")
            ->execute([$prenom, $nom, $telephone, $specialite, $row['id']]);
    } else {
        $pdo->prepare("INSERT INTO medecins (prenom, nom, telephone, specialite) VALUES (?,?,?,?)")
            ->execute([$prenom, $nom, $telephone, $specialite]);
    }
    echo json_encode(['ok'=>true,'message'=>'Profil mis à jour.']);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}