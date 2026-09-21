<?php
/**
 * /api/auth/register.php — Création de compte KondjiPro.
 *
 * POST (JSON ou formulaire) :
 *   name         : nom complet (requis)
 *   phone_number : numéro de téléphone (requis, normalisé E.164, +228 par défaut)
 *   password     : mot de passe (requis, min 8 caractères)
 *   role         : 'patient' (défaut) ou 'medecin'
 *   device_id    : identifiant d'appareil (optionnel)
 *
 * Réponse :
 *   201 { user: {...}, message: "Compte créé avec succès." }
 *   400 { error: "validation", message: "..." }
 *   409 { error: "phone_taken", message: "Ce numéro de téléphone est déjà utilisé." }
 *
 * L'utilisateur est connecté automatiquement après l'inscription (session PHP).
 * Si le rôle est 'medecin', une ligne est aussi créée dans la table `medecins`
 * (profil affiché dans le header) quand la table existe.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../patient.php';

session_start_secure();
ensure_users_table();

// Rate limiting : max 5 inscriptions par IP toutes les 15 minutes.
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rl = rate_limit_check('register:' . $ip, 5, 900);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . $rl['retry_after']);
    echo json_encode([
        'error'      => 'rate_limited',
        'message'    => 'Trop de tentatives d\'inscription. Réessayez dans ' . $rl['retry_after'] . ' secondes.',
        'retry_after' => $rl['retry_after'],
    ]);
    exit;
}

// Lecture du corps (JSON ou POST classique).
$raw = file_get_contents('php://input');
$body = [];
if ($raw !== '' && $raw !== false) {
    $json = json_decode($raw, true);
    if (is_array($json)) $body = $json;
}
$name        = trim($body['name']        ?? ($_POST['name']        ?? ''));
$phone       = trim($body['phone_number'] ?? ($_POST['phone_number'] ?? ''));
$password    = (string)($body['password'] ?? ($_POST['password'] ?? ''));
$role        = strtolower(trim($body['role'] ?? ($_POST['role'] ?? 'patient')));
$device_id   = trim($body['device_id'] ?? ($_POST['device_id'] ?? ''));

// ---------- Validation ----------
if ($name === '' || $phone === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        'error'   => 'validation',
        'message' => 'Nom, numéro de téléphone et mot de passe sont requis.',
    ]);
    exit;
}
if (mb_strlen($password) < 8) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'validation',
        'message' => 'Le mot de passe doit contenir au moins 8 caractères.',
    ]);
    exit;
}
if (!in_array($role, ['patient', 'medecin'], true)) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'validation',
        'message' => 'Le rôle doit être "patient" ou "medecin".',
    ]);
    exit;
}

// Normalisation du téléphone en E.164 (même logique que l'API Laravel).
$phoneE164 = normalize_phone_e164($phone);
if ($phoneE164 === null) {
    http_response_code(400);
    echo json_encode([
        'error'   => 'validation',
        'message' => 'Numéro de téléphone invalide.',
    ]);
    exit;
}

$pdo = db_connect();
if (!$pdo) {
    http_response_code(503);
    echo json_encode([
        'error'   => 'database',
        'message' => 'Base de données indisponible.',
    ]);
    exit;
}

// ---------- Unicité du téléphone ----------
$stmt = $pdo->prepare('SELECT id FROM users WHERE phone_number = :p LIMIT 1');
$stmt->execute([':p' => $phoneE164]);
if ($stmt->fetch()) {
    http_response_code(409);
    echo json_encode([
        'error'   => 'phone_taken',
        'message' => 'Ce numéro de téléphone est déjà utilisé.',
    ]);
    exit;
}

// ---------- Username unique ----------
$base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?: 'user');
$username = $base;
$i = 1;
while (true) {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
    $stmt->execute([':u' => $username]);
    if (!$stmt->fetch()) break;
    $username = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $i++;
    if ($i > 5) break; // sécurité : ne pas boucler indéfiniment
}

// ---------- Insertion ----------
$now = date('Y-m-d H:i:s');
$hash = password_hash($password, PASSWORD_BCRYPT);

$stmt = $pdo->prepare(
    'INSERT INTO users (name, username, phone_number, phone_verified, password, role, solde, device_id, platform, is_online, created_at, updated_at)
     VALUES (:name, :username, :phone, 0, :password, :role, 0, :device_id, :platform, 1, :created, :updated)'
);
$stmt->execute([
    ':name'      => $name,
    ':username'  => $username,
    ':phone'     => $phoneE164,
    ':password'  => $hash,
    ':role'      => $role,
    ':device_id' => $device_id !== '' ? $device_id : null,
    ':platform'  => 'web',
    ':created'   => $now,
    ':updated'   => $now,
]);
$userId = (int)$pdo->lastInsertId();

// ---------- Profil médecin (si rôle medecin et table medecins présente) ----------
if ($role === 'medecin') {
    try {
        $pdo->query('SELECT COUNT(*) FROM medecins');
        $parts = preg_split('/\s+/', trim($name), 2);
        $prenom = $parts[0] ?? '';
        $nom    = $parts[1] ?? '';
        $stmt = $pdo->prepare(
            'INSERT INTO medecins (prenom, nom, telephone, specialite, created_at)
             VALUES (:prenom, :nom, :telephone, :specialite, :created)'
        );
        $stmt->execute([
            ':prenom'     => $prenom,
            ':nom'        => $nom,
            ':telephone'  => $phoneE164,
            ':specialite' => 'Médecin généraliste',
            ':created'    => $now,
        ]);
    } catch (PDOException $e) {
        // Table medecins absente : non bloquant, le compte est créé.
        error_log('[kondjipro] register: table medecins indisponible: ' . $e->getMessage());
    }
} else {
    // ---------- Fiche patient liée (rôle patient) ----------
    // Crée la fiche `patients` rattachée au compte (user_id) si elle n'existe
    // pas déjà (ex. fiche préexistante avec le même téléphone).
    $patient = ensure_patient_for_user($userId, $name, $phoneE164);
    if ($patient) {
        $_SESSION['patient_id'] = (int)$patient['id'];
    }
}

// ---------- Session auto ----------
$user = auth_attempt($phoneE164, $password);
if (!$user) {
    $user = [
        'id'           => $userId,
        'name'         => $name,
        'username'     => $username,
        'phone_number' => $phoneE164,
        'role'         => $role,
    ];
}

http_response_code(201);
echo json_encode([
    'user'    => $user,
    'message' => 'Compte créé avec succès.',
]);