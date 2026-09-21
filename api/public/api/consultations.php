<?php
/**
 * /api/consultations.php — Lectures de l'espace patient (espace patient).
 *
 *   GET  → dernier dossier médical du patient connecté :
 *          dernière consultation + historique (motif, diagnostic),
 *          via jointure médecin (LEFT JOIN medecins).
 *
 * Réponse : { ok: bool, message?, consultation?: {...}, historique?: [...] }
 * Portable SQLite / MySQL (même logique que les autres api/*.php).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/patient.php';

// Garde d'authentification : 401 si non connecté.
$user = auth_require();

// Fiche patient liée au compte connecté (user_id, fallback téléphone).
$patient = patient_for_user((int)$user['id'], $user['phone_number'] ?? null);
if (!$patient) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Aucune fiche patient liée à ce compte.']);
    exit;
}
$patientId = (int)$patient['id'];

$pdo = db_connect();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Base de données indisponible.']);
    exit;
}

// ---------- GET : dossier (dernière consultation + historique) ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    // Dernière consultation du patient, avec son médecin.
    $consultation = null;
    $historique   = [];
    try {
        $consultation = $pdo->prepare(
            "SELECT c.id, c.date_consultation, c.motif, c.diagnostic, c.notes,
                    m.id    AS medecin_id,
                    m.prenom AS medecin_prenom,
                    m.nom    AS medecin_nom
               FROM consultations c
               LEFT JOIN medecins m ON m.id = c.medecin_id
              WHERE c.patient_id = ?
              ORDER BY c.date_consultation DESC
              LIMIT 1"
        );
        $consultation->execute([$patientId]);
        $consultation = $consultation->fetch() ?: null;
    } catch (Exception $e) {
        // SQLite sans jointure medecin : on retombe sur le SELECT simple.
        error_log('[kondjipro] consultations.jointure: ' . $e->getMessage());
        $consultation = $pdo->prepare(
            "SELECT c.id, c.date_consultation, c.motif, c.diagnostic, c.notes
               FROM consultations c
              WHERE c.patient_id = ?
              ORDER BY c.date_consultation DESC
              LIMIT 1"
        );
        $consultation->execute([$patientId]);
        $consultation = $consultation->fetch() ?: null;
    }

    // Historique complet (pour le dossier « Chronologie »).
    $historique = $pdo->prepare(
        "SELECT id, date_consultation, motif, diagnostic
           FROM consultations
          WHERE patient_id = ?
          ORDER BY date_consultation DESC
          LIMIT 20"
    );
    $historique->execute([$patientId]);
    $historique = $historique->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(
        ['ok' => true, 'consultation' => $consultation, 'historique' => $historique],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']);
