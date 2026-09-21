<?php
/**
 * /api/rdv.php — Rendez-vous KondjiPro (espace patient).
 *
 * GET                     → liste des rendez-vous du patient connecté
 *                            (avec nom du médecin, triés par date croissante).
 * POST                    → création d'un rendez-vous.
 *                            Champs : medecin_id, date_rdv (Y-m-d H:i), motif.
 * POST action=annuler     → annulation (statut 'annule').
 *                            Champs : id.
 *
 * Réponse : { ok: bool, message: string, results?: [...] }
 * Portable SQLite / MySQL (même logique que les autres api/*.php).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/patient.php';

// Garde d'authentification : 401 si non connecté.
$user = auth_require();
csrf_require();

// Fiche patient liée au compte connecté (user_id, fallback téléphone).
$patient = patient_for_user((int)$user['id'], $user['phone_number'] ?? null);
if (!$patient) {
    http_response_code(403);
    echo json_encode([
        'ok'      => false,
        'message' => 'Aucune fiche patient liée à ce compte.',
    ]);
    exit;
}
$patientId = (int)$patient['id'];

$pdo = db_connect();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Base de données indisponible.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ---------- Annulation ----------
if ($method === 'POST' && ($_POST['action'] ?? '') === 'annuler') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Identifiant manquant.']);
        exit;
    }
    $stmt = $pdo->prepare(
        "UPDATE rendez_vous SET statut = 'annule' WHERE id = ? AND patient_id = ?"
    );
    $stmt->execute([$id, $patientId]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['ok' => false, 'message' => 'Rendez-vous introuvable.']);
        exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Rendez-vous annulé.']);
    exit;
}

// ---------- Création ----------
if ($method === 'POST') {
    $medecinId = (int)($_POST['medecin_id'] ?? 0);
    $dateRdv   = trim($_POST['date_rdv'] ?? '');
    $motif     = trim($_POST['motif'] ?? '');

    if ($medecinId <= 0 || $dateRdv === '') {
        echo json_encode(['ok' => false, 'message' => 'Médecin et date/heure sont obligatoires.']);
        exit;
    }

    // Validation + normalisation de la date (portable SQLite/MySQL).
    $ts = strtotime($dateRdv);
    if ($ts === false || $ts < time() - 60) {
        echo json_encode(['ok' => false, 'message' => 'Date/heure invalide ou déjà passée.']);
        exit;
    }
    $dateRdv = date('Y-m-d H:i:s', $ts);

    // Le médecin existe ?
    $stmt = $pdo->prepare('SELECT id FROM medecins WHERE id = ?');
    $stmt->execute([$medecinId]);
    if (!$stmt->fetch()) {
        echo json_encode(['ok' => false, 'message' => 'Médecin introuvable.']);
        exit;
    }

    try {
        $ins = $pdo->prepare(
            "INSERT INTO rendez_vous (patient_id, medecin_id, date_rdv, motif, statut)
             VALUES (?, ?, ?, ?, 'en_attente')"
        );
        $ins->execute([
            $patientId,
            $medecinId,
            $dateRdv,
            $motif !== '' ? $motif : null,
        ]);
        echo json_encode([
            'ok'      => true,
            'message' => 'Rendez-vous enregistré. Le médecin sera notifié.',
            'id'      => (int)$pdo->lastInsertId(),
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Erreur lors de l\'enregistrement.']);
        error_log('[kondjipro] rdv create: ' . $e->getMessage());
    }
    exit;
}

// ---------- Liste ----------
if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare(
            "SELECT r.id, r.date_rdv, r.motif, r.statut, r.created_at,
                    m.prenom AS medecin_prenom, m.nom AS medecin_nom,
                    m.specialite, m.telephone AS medecin_telephone
             FROM rendez_vous r
             LEFT JOIN medecins m ON m.id = r.medecin_id
             WHERE r.patient_id = ?
             ORDER BY r.date_rdv ASC"
        );
        $stmt->execute([$patientId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $r['medecin'] = trim(($r['medecin_prenom'] ?? '') . ' ' . ($r['medecin_nom'] ?? '')) ?: 'Médecin';
            // Format ISO d'abord (à partir de la valeur brute SQL), puis affichage français.
            $ts = strtotime($r['date_rdv']);
            $r['date_rdv_iso'] = $ts ? date('Y-m-d\TH:i', $ts) : null;
            $r['date_rdv']     = $ts ? date('d/m/Y H:i', $ts) : ($r['date_rdv'] ?? '');
        }
        unset($r);

        echo json_encode(['ok' => true, 'results' => $rows], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Erreur de lecture.']);
        error_log('[kondjipro] rdv list: ' . $e->getMessage());
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']);