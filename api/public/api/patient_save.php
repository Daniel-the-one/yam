<?php
/**
 * /api/patient_save.php
 *
 * MODE 1 — Création (doctor) :
 *   POST : nom, telephone, date_naissance, adresse, sexe, photo
 *   Crée un patient avec un UUID stable (utilisé par le QR KondjiPay).
 *
 * MODE 2 — Mise à jour profil (patient) :
 *   POST : patient_id (requis), nom, telephone, date_naissance, adresse, sexe,
 *          groupe_sanguin, allergies, assurance, contact_urgence, photo_profil, bio
 *   Met à jour la fiche patient du compte connecté.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();
csrf_require();

require_once __DIR__ . '/patient.php';
ensure_patient_schema();

function uuid_v4(): string {
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/**
 * Upload photo (base64 ou multipart) → chemin relatif.
 */
function handle_photo_upload(): ?string {
    // Base64 (contourne le WAF multipart d'o2switch)
    $photo_data = trim($_POST['photo_data'] ?? '');
    if ($photo_data !== '') {
        $ext = strtolower(trim($_POST['photo_ext'] ?? 'jpg'));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return null;
        $bin = base64_decode($photo_data, true);
        if ($bin === false || $bin === '') return null;
        $dir = __DIR__ . '/../assets/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $fname = 'patient_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (file_put_contents($dir . '/' . $fname, $bin) !== false) {
            return '/assets/uploads/' . $fname;
        }
    }
    // Multipart
    if (!empty($_FILES['photo']) && is_uploaded_file($_FILES['photo']['tmp_name'])) {
        $dir = __DIR__ . '/../assets/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return null;
        $fname = 'patient_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . '/' . $fname)) {
            return '/assets/uploads/' . $fname;
        }
    }
    return null;
}

// ── Lecture des champs ──
$nom            = trim($_POST['nom'] ?? '');
$telephone      = trim($_POST['telephone'] ?? '');
$date_naissance = trim($_POST['date_naissance'] ?? '');
$adresse        = trim($_POST['adresse'] ?? '');
$sexe           = in_array($_POST['sexe'] ?? '', ['M','F'], true) ? $_POST['sexe'] : null;

// Champs profil patient (nouveaux)
$groupe_sanguin  = trim($_POST['groupe_sanguin'] ?? '');
$allergies       = trim($_POST['allergies'] ?? '');
$assurance       = trim($_POST['assurance'] ?? '');
$contact_urgence = trim($_POST['contact_urgence'] ?? '');
$bio             = trim($_POST['bio'] ?? '');

// ── MODE 2 — Mise à jour profil patient ──
$patientId = (int)($_POST['patient_id'] ?? 0);
if ($patientId > 0) {
    // Vérifier que le patient appartient au compte connecté
    $user = auth_user();
    $pdo = db_connect();
    if (!$pdo) {
        echo json_encode(['ok'=>false,'message'=>'Base indisponible.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, user_id, photo_profil FROM patients WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $patientId]);
    $existing = $stmt->fetch();
    if (!$existing || (int)($existing['user_id'] ?? 0) !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'message'=>'Accès interdit à cette fiche patient.']);
        exit;
    }

    $normTel = normalize_phone_e164($telephone);
    if ($normTel === null && $telephone !== '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'validation', 'message' => 'Numéro de téléphone invalide.']);
        exit;
    }
    if ($normTel !== null) {
        $check = phone_is_taken($normTel, 'patient', $patientId, (int)$user['id']);
        if ($check['taken']) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => 'phone_taken', 'message' => $check['message']]);
            exit;
        }
        $telephone = $normTel;
    }

    // Photo profil (optionnelle)
    $photo_profil = handle_photo_upload();
    // Si pas de nouvelle photo, on garde l'ancienne
    if ($photo_profil === null) {
        $photo_profil = $existing['photo_profil'] ?? null;
    }
    // Si l'utilisateur envoie explicitement photo_profil= pour supprimer
    if (isset($_POST['photo_profil']) && $_POST['photo_profil'] === '') {
        $photo_profil = null;
    }

    try {
        $upd = $pdo->prepare(
            'UPDATE patients SET
                nom = :nom,
                telephone = :tel,
                date_naissance = :dnaiss,
                adresse = :adr,
                sexe = :sexe,
                groupe_sanguin = :gs,
                allergies = :allergies,
                assurance = :assurance,
                contact_urgence = :cu,
                photo_profil = :pp,
                photo = :pp,
                bio = :bio
             WHERE id = :id'
        );
        $upd->execute([
            ':nom'       => $nom !== '' ? $nom : ($existing['nom'] ?? ''),
            ':tel'       => $telephone !== '' ? $telephone : ($existing['telephone'] ?? ''),
            ':dnaiss'    => $date_naissance !== '' ? $date_naissance : null,
            ':adr'       => $adresse !== '' ? $adresse : null,
            ':sexe'      => $sexe,
            ':gs'        => $groupe_sanguin !== '' ? $groupe_sanguin : null,
            ':allergies' => $allergies !== '' ? $allergies : null,
            ':assurance' => $assurance !== '' ? $assurance : null,
            ':cu'        => $contact_urgence !== '' ? $contact_urgence : null,
            ':pp'        => $photo_profil,
            ':bio'       => $bio !== '' ? $bio : null,
            ':id'        => $patientId,
        ]);

        if ($telephone !== '' && !empty($user['id'])) {
            try {
                $pdo->prepare('UPDATE users SET phone_number = ? WHERE id = ?')->execute([$telephone, (int)$user['id']]);
                $_SESSION['phone_number'] = $telephone;
            } catch (Exception $e) {}
        }

        echo json_encode(['ok'=>true, 'message'=>'Profil mis à jour.', 'photo_profil'=>$photo_profil, 'photo'=>$photo_profil]);
    } catch (Exception $e) {
        echo json_encode(['ok'=>false, 'message'=>$e->getMessage()]);
    }
    exit;
}

// ── MODE 1 — Création de patient (médecin) ──
if ($nom === '' || $telephone === '') {
    echo json_encode(['ok'=>false,'message'=>'Nom et téléphone sont obligatoires.']);
    exit;
}

$normTel = normalize_phone_e164($telephone);
if ($normTel === null) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'validation','message'=>'Format de téléphone invalide.']);
    exit;
}

$check = phone_is_taken($normTel, 'patient');
if ($check['taken']) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'phone_taken','message'=>$check['message']]);
    exit;
}
$telephone = $normTel;

$pdo = db_connect();
if (!$pdo) {
    echo json_encode(['ok'=>true,'message'=>'Patient ajouté (mode démo).','reset'=>true]);
    exit;
}

$photo = handle_photo_upload();

try {
    $ins = $pdo->prepare(
        "INSERT INTO patients (uuid, nom, telephone, date_naissance, adresse, sexe, photo, photo_profil)
         VALUES (?,?,?,?,?,?,?,?)"
    );
    $ins->execute([
        uuid_v4(),
        $nom,
        $telephone,
        $date_naissance !== '' ? $date_naissance : null,
        $adresse !== '' ? $adresse : null,
        $sexe,
        $photo,
        $photo,
    ]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['ok'=>true,'message'=>'Patient ajouté · ' . $nom, 'id'=>$id, 'reset'=>true]);
} catch (Exception $e) {
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}
