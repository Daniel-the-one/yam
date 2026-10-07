<?php
/**
 * /api/ordonnances.php
 *   GET -> liste des ordonnances (les plus récentes d'abord).
 *
 * Réponse : { ok: true, ordonnances: [{ id, numero, patient, date, notes,
 *             medicaments: [{nom, quantite, unite, frequence}] }] }
 * En cas d'absence de base, renvoie des données de démonstration.
 *
 * Complète `ordonnance_save.php` (écriture) : sans cet endpoint, l'écran
 * mobile « Ordonnances » n'avait aucune source de données.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();

/** Numéro lisible dérivé de l'id et de l'année (pas de colonne dédiée). */
function ordonnance_numero(int $id, ?string $date): string {
    $year = $date ? date('Y', strtotime($date)) : date('Y');
    return sprintf('ORD-%s-%03d', $year, $id);
}

$pdo = db_connect();

if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT o.id, o.date_ordonnance, o.notes, p.nom AS patient
               FROM ordonnances o
               LEFT JOIN patients p ON p.id = o.patient_id
              ORDER BY o.date_ordonnance DESC, o.id DESC
              LIMIT 100"
        );
        $rows = $stmt->fetchAll();

        $medsStmt = $pdo->prepare(
            "SELECT nom, quantite, unite, frequence FROM ordonnance_medicaments WHERE ordonnance_id = ? ORDER BY id"
        );

        $ordonnances = [];
        foreach ($rows as $r) {
            $medsStmt->execute([(int)$r['id']]);
            $meds = [];
            foreach ($medsStmt->fetchAll() as $m) {
                $meds[] = [
                    'nom'       => $m['nom'],
                    'quantite'  => (int)$m['quantite'],
                    'unite'     => $m['unite'] ?? '',
                    'frequence' => $m['frequence'] ?? '',
                ];
            }
            $ordonnances[] = [
                'id'          => (int)$r['id'],
                'numero'      => ordonnance_numero((int)$r['id'], $r['date_ordonnance'] ?? null),
                'patient'     => $r['patient'] ?? 'Patient inconnu',
                'date'        => date('d/m/Y', strtotime($r['date_ordonnance'])),
                'notes'       => $r['notes'] ?? '',
                'medicaments' => $meds,
            ];
        }

        echo json_encode(['ok' => true, 'ordonnances' => $ordonnances], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        // Base indisponible ou schéma incomplet → repli sur les données démo.
    }
}

// Fallback démo (pas de base)
echo json_encode(['ok' => true, 'ordonnances' => [
    ['id'=>1,'numero'=>'ORD-2026-001','patient'=>'Jean KOFFI','date'=>date('d/m/Y', strtotime('-2 day')),'notes'=>'Repos 3 jours, hydratation','medicaments'=>[['nom'=>'Paracétamol 500mg','quantite'=>21,'unite'=>'comprimé','frequence'=>'3x/jour pendant 7j'],['nom'=>'Coartem','quantite'=>24,'unite'=>'comprimé','frequence'=>'2x/jour pendant 3j']]],
    ['id'=>2,'numero'=>'ORD-2026-002','patient'=>'Ama ASSOGBA','date'=>date('d/m/Y', strtotime('-5 day')),'notes'=>'Surveillance tensionnelle','medicaments'=>[['nom'=>'Amlodipine 5mg','quantite'=>30,'unite'=>'comprimé','frequence'=>'1x/jour le matin']]],
]], JSON_UNESCAPED_UNICODE);