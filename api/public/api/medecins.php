<?php
/**
 * /api/medecins.php — Liste et recherche de médecins (espace patient).
 *
 * GET                     → tous les médecins (id, prenom, nom, telephone, specialite, photo, bio, horaires)
 * GET ?phone=+22890...    → recherche par numéro de téléphone (E.164 normalisé)
 * GET ?id=3               → fiche complète d'un médecin par ID
 *
 * Réponse : { results: [...] } ou { medecin: {...} }
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/patient.php';

$user = auth_require();
csrf_require();

$pdo = db_connect();
if (!$pdo) {
    echo json_encode(['results' => []]);
    exit;
}

// Garantir le schéma enrichi (colonnes bio, horaires sur medecins)
ensure_patient_schema();

// ── GET ?id=X → fiche complète ──
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    try {
        $stmt = $pdo->prepare('SELECT id, prenom, nom, telephone, specialite, photo, bio, horaires, accepte_rdv FROM medecins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $med = $stmt->fetch();
        if ($med) {
            echo json_encode(['medecin' => $med], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'not_found', 'message' => 'Médecin introuvable.']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'database', 'message' => 'Erreur serveur.']);
    }
    exit;
}

// ── GET ?phone=X → recherche par téléphone ──
$phone = trim($_GET['phone'] ?? '');
if ($phone !== '') {
    $clean = preg_replace('/[^\d+]/', '', $phone);
    // Recherche tolérante : LIKE sur le numéro nettoyé
    try {
        $stmt = $pdo->prepare(
            'SELECT id, prenom, nom, telephone, specialite, photo, bio, horaires, accepte_rdv
             FROM medecins
             WHERE REPLACE(REPLACE(REPLACE(telephone, " ", ""), "-", ""), ".", "") LIKE :p
             ORDER BY prenom, nom'
        );
        $stmt->execute([':p' => '%' . $clean . '%']);
        $results = $stmt->fetchAll();
        echo json_encode(['results' => $results], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'database', 'message' => 'Erreur serveur.']);
    }
    exit;
}

// ── GET → tous les médecins ──
try {
    $medecins = $pdo->query(
        'SELECT id, prenom, nom, telephone, specialite, photo, bio, horaires, accepte_rdv
         FROM medecins ORDER BY prenom, nom'
    )->fetchAll();
    echo json_encode(['results' => $medecins], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'database', 'message' => 'Erreur serveur.']);
}
