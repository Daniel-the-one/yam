<?php
/**
 * /api/medecin.php
 *   GET  -> profil du médecin connecté + solde KondjiPay
 *   POST -> mise à jour du profil (prénom, nom, téléphone, spécialité, bio, horaires, photo)
 *
 * Réponse : { ok: bool, medecin: {...}, solde: int, photo?: string }
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/patient.php';

$user = auth_require();
csrf_require();

$pdo = db_connect();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Base indisponible.']);
    exit;
}

ensure_patient_schema();

// Identifier le médecin connecté
function get_current_medecin(PDO $pdo, array $user): ?array {
    $medId = $_SESSION['medecin_id'] ?? null;
    if ($medId) {
        $stmt = $pdo->prepare('SELECT * FROM medecins WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$medId]);
        $row = $stmt->fetch();
        if ($row) return $row;
    }

    $phone = $user['phone_number'] ?? '';
    if ($phone !== '') {
        $clean = preg_replace('/[^\d]/', '', $phone);
        $stmt = $pdo->prepare('SELECT * FROM medecins WHERE telephone = ? OR telephone = ? LIMIT 1');
        $stmt->execute([$phone, $clean]);
        $row = $stmt->fetch();
        if (!$row && strlen($clean) >= 8) {
            $stmt = $pdo->prepare('SELECT * FROM medecins WHERE telephone LIKE ? LIMIT 1');
            $stmt->execute(['%' . substr($clean, -8)]);
            $row = $stmt->fetch();
        }
        if ($row) {
            $_SESSION['medecin_id'] = (int)$row['id'];
            return $row;
        }
    }

    // Fallback : premier médecin de la base
    $row = $pdo->query('SELECT * FROM medecins ORDER BY id LIMIT 1')->fetch();
    if ($row) {
        $_SESSION['medecin_id'] = (int)$row['id'];
        return $row;
    }

    return null;
}

$currentMedecin = get_current_medecin($pdo, $user);

// ---------- GET : profil + solde ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $solde = 0;
    try {
        $solde = (int)$pdo->query(
            "SELECT COALESCE(SUM(montant),0) FROM transactions_encaissement WHERE statut='reussi'"
        )->fetchColumn();
    } catch (Exception $e) {}

    if (!$currentMedecin) {
        $currentMedecin = [
            'id' => 0,
            'prenom' => $user['name'] ?? 'Médecin',
            'nom' => '',
            'telephone' => $user['phone_number'] ?? '',
            'specialite' => 'Médecin généraliste',
            'photo' => null,
            'bio' => null,
            'horaires' => null,
            'accepte_rdv' => 1,
        ];
    }

    if (!empty($currentMedecin['photo']) && !str_starts_with($currentMedecin['photo'], '/')) {
        $currentMedecin['photo'] = '/' . $currentMedecin['photo'];
    }

    echo json_encode(['ok' => true, 'medecin' => $currentMedecin, 'solde' => $solde], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- POST : mise à jour profil + photo ----------
$newPhoto = null;
$photoUploaded = false;

// 1. Upload via base64 (contourne d'éventuels WAF multipart)
$photo_data = trim($_POST['photo_data'] ?? '');
if ($photo_data !== '') {
    $ext = strtolower(trim($_POST['photo_ext'] ?? 'jpg'));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Format photo non supporté (jpg, png, webp).']);
        exit;
    }
    $bin = base64_decode($photo_data, true);
    if ($bin === false || $bin === '') {
        echo json_encode(['ok' => false, 'message' => 'Données photo invalides.']);
        exit;
    }
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $fname = 'medecin_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (file_put_contents($dir . '/' . $fname, $bin) === false) {
        echo json_encode(['ok' => false, 'message' => 'Échec de l\'enregistrement de la photo.']);
        exit;
    }
    $newPhoto = '/assets/uploads/' . $fname;
    $photoUploaded = true;
}

// 2. Upload via multipart ($_FILES)
if (!$photoUploaded && !empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Format photo non supporté (jpg, png, webp).']);
        exit;
    }
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $fname = 'medecin_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
        echo json_encode(['ok' => false, 'message' => 'Échec de l\'enregistrement de la photo.']);
        exit;
    }
    $newPhoto = '/assets/uploads/' . $fname;
    $photoUploaded = true;
}

// 3. Suppression explicite de la photo
if (!$photoUploaded && isset($_POST['photo']) && $_POST['photo'] === '') {
    $newPhoto = null;
    $photoUploaded = true;
}

// Récupération des champs texte
$prenom      = isset($_POST['prenom']) ? trim($_POST['prenom']) : ($currentMedecin['prenom'] ?? '');
$nom         = isset($_POST['nom']) ? trim($_POST['nom']) : ($currentMedecin['nom'] ?? '');
$telephone   = isset($_POST['telephone']) ? trim($_POST['telephone']) : ($currentMedecin['telephone'] ?? '');
$specialite  = isset($_POST['specialite']) ? trim($_POST['specialite']) : ($currentMedecin['specialite'] ?? 'Médecin généraliste');
$bio         = isset($_POST['bio']) ? trim($_POST['bio']) : ($currentMedecin['bio'] ?? null);
$horaires    = isset($_POST['horaires']) ? trim($_POST['horaires']) : ($currentMedecin['horaires'] ?? null);
$accepte_rdv = isset($_POST['accepte_rdv']) ? (int)$_POST['accepte_rdv'] : (int)($currentMedecin['accepte_rdv'] ?? 1);

try {
    if ($currentMedecin && !empty($currentMedecin['id'])) {
        $photoToSave = $photoUploaded ? $newPhoto : ($currentMedecin['photo'] ?? null);
        $stmt = $pdo->prepare(
            'UPDATE medecins SET
                prenom = :prenom,
                nom = :nom,
                telephone = :telephone,
                specialite = :specialite,
                bio = :bio,
                horaires = :horaires,
                accepte_rdv = :accepte_rdv,
                photo = :photo
             WHERE id = :id'
        );
        $stmt->execute([
            ':prenom'      => $prenom,
            ':nom'         => $nom,
            ':telephone'   => $telephone,
            ':specialite'  => $specialite,
            ':bio'         => $bio !== '' ? $bio : null,
            ':horaires'    => $horaires !== '' ? $horaires : null,
            ':accepte_rdv' => $accepte_rdv,
            ':photo'       => $photoToSave,
            ':id'          => $currentMedecin['id'],
        ]);
        $targetId = $currentMedecin['id'];
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO medecins (prenom, nom, telephone, specialite, bio, horaires, accepte_rdv, photo)
             VALUES (:prenom, :nom, :telephone, :specialite, :bio, :horaires, :accepte_rdv, :photo)'
        );
        $stmt->execute([
            ':prenom'      => $prenom,
            ':nom'         => $nom,
            ':telephone'   => $telephone,
            ':specialite'  => $specialite,
            ':bio'         => $bio !== '' ? $bio : null,
            ':horaires'    => $horaires !== '' ? $horaires : null,
            ':accepte_rdv' => $accepte_rdv,
            ':photo'       => $newPhoto,
        ]);
        $targetId = (int)$pdo->lastInsertId();
        $_SESSION['medecin_id'] = $targetId;
    }

    $refreshed = $pdo->prepare('SELECT * FROM medecins WHERE id = ?');
    $refreshed->execute([$targetId]);
    $updated = $refreshed->fetch();
    if ($updated && !empty($updated['photo']) && !str_starts_with($updated['photo'], '/')) {
        $updated['photo'] = '/' . $updated['photo'];
    }

    echo json_encode([
        'ok'      => true,
        'message' => 'Profil mis à jour.',
        'photo'   => $updated['photo'] ?? null,
        'medecin' => $updated,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}