<?php
/**
 * /api/factures.php
 *   GET -> liste des factures (les plus récentes d'abord).
 *
 * Réponse : { ok: true, factures: [{ id, numero, patient, montant, date,
 *             statut, actes: [{code, libelle, prix}] }] }
 * En cas d'absence de base, renvoie des données de démonstration.
 *
 * Complète `facture_save.php` (écriture) : sans cet endpoint, l'écran mobile
 * « Factures » n'avait aucune source de données et affichait une liste figée.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();

/** Numéro lisible dérivé de l'id et de l'année (pas de colonne dédiée). */
function facture_numero(int $id, ?string $date): string {
    $year = $date ? date('Y', strtotime($date)) : date('Y');
    return sprintf('FAC-%s-%03d', $year, $id);
}

$pdo = db_connect();

if ($pdo) {
    try {
        $stmt = $pdo->query(
            "SELECT f.id, f.date_facture, f.total, f.statut, p.nom AS patient
               FROM factures f
               LEFT JOIN patients p ON p.id = f.patient_id
              ORDER BY f.date_facture DESC, f.id DESC
              LIMIT 100"
        );
        $rows = $stmt->fetchAll();

        $actesStmt = $pdo->prepare(
            "SELECT code, libelle, prix FROM facture_actes WHERE facture_id = ? ORDER BY id"
        );

        $factures = [];
        foreach ($rows as $r) {
            $actesStmt->execute([(int)$r['id']]);
            $actes = [];
            foreach ($actesStmt->fetchAll() as $a) {
                $actes[] = [
                    'code'    => $a['code'],
                    'libelle' => $a['libelle'],
                    'prix'    => (int)$a['prix'],
                ];
            }
            $factures[] = [
                'id'      => (int)$r['id'],
                'numero'  => facture_numero((int)$r['id'], $r['date_facture'] ?? null),
                'patient' => $r['patient'] ?? 'Patient inconnu',
                'montant' => (int)$r['total'],
                'date'    => date('d/m/Y', strtotime($r['date_facture'])),
                'statut'  => $r['statut'] ?? 'en_attente',
                'actes'   => $actes,
            ];
        }

        echo json_encode(['ok' => true, 'factures' => $factures], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        // Base indisponible ou schéma incomplet → repli sur les données démo.
    }
}

// Fallback démo (pas de base)
echo json_encode(['ok' => true, 'factures' => [
    ['id'=>1,'numero'=>'FAC-2026-001','patient'=>'Jean KOFFI','montant'=>5000,'date'=>date('d/m/Y', strtotime('-2 day')),'statut'=>'payee','actes'=>[['code'=>'C001','libelle'=>'Consultation','prix'=>5000]]],
    ['id'=>2,'numero'=>'FAC-2026-002','patient'=>'Ama ASSOGBA','montant'=>3000,'date'=>date('d/m/Y', strtotime('-1 day')),'statut'=>'en_attente','actes'=>[['code'=>'C002','libelle'=>'Pansement','prix'=>3000]]],
    ['id'=>3,'numero'=>'FAC-2026-003','patient'=>'Kossi AGBEKO','montant'=>2500,'date'=>date('d/m/Y'),'statut'=>'en_attente','actes'=>[['code'=>'C003','libelle'=>'Injection','prix'=>2500]]],
]], JSON_UNESCAPED_UNICODE);