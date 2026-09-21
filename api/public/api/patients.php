<?php
/**
 * /api/patients.php
 *   ?q=…        -> recherche (JSON liste)
 *   ?id=…       -> détail + historique (JSON)
 *
 * Réponse : { results: [...] }  ou  { patient: {...}, historique: [...] }
 * En cas d'absence de base, renvoie des données de démonstration.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

// Garde d'authentification : 401 si non connecté.
require_once __DIR__ . '/auth.php';
auth_require();

$pdo  = db_connect();
$demo = [
    ['id'=>1,'uuid'=>'a1b2c3d4-1111-4aaa-8bbb-000000000001','nom'=>'Jean KOFFI',   'telephone'=>'+229 90 12 34 56','date_naissance'=>'1985-04-12','sexe'=>'M','adresse'=>'Cotonou, Akpakpa'],
    ['id'=>2,'uuid'=>'a1b2c3d4-2222-4bbb-8ccc-000000000002','nom'=>'Ama ASSOGBA',  'telephone'=>'+229 96 78 11 22','date_naissance'=>'1992-09-23','sexe'=>'F','adresse'=>'Porto-Novo, Centre'],
    ['id'=>3,'uuid'=>'a1b2c3d4-3333-4ccc-8ddd-000000000003','nom'=>'Kossi AGBEKO', 'telephone'=>'+229 95 44 33 22','date_naissance'=>'1978-01-30','sexe'=>'M','adresse'=>'Calavi, Godomey'],
];

function color_for($nom) {
    [$c] = patient_avatar($nom);
    return $c;
}
function enrich($p) {
    [$c] = patient_avatar($p['nom']);
    $p['couleur'] = $c;
    $p['age']     = age_from_birth($p['date_naissance'] ?? null);
    $photo = !empty($p['photo_profil']) ? $p['photo_profil'] : ($p['photo'] ?? null);
    if (!empty($photo) && !str_starts_with($photo, '/')) {
        $photo = '/' . $photo;
    }
    $p['photo']        = $photo;
    $p['photo_profil'] = $photo;
    // Fallback : si la colonne uuid n'existe pas encore en base, on en
    // fabrique une stable à partir de l'id (le QR reste fonctionnel).
    if (empty($p['uuid'])) {
        $p['uuid'] = sprintf('kpro-%08d-%s', (int)$p['id'], substr(md5('kpro'.$p['id'].$p['telephone']), 0, 12));
    }
    return $p;
}

if ($pdo) {
    try {
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
            $stmt->execute([(int)$_GET['id']]);
            $patient = $stmt->fetch();
            if (!$patient) { echo json_encode(['patient'=>null,'historique'=>[]]); exit; }

            $patient = enrich($patient);
            $histo = [];

            $cs = $pdo->prepare("SELECT date_consultation, motif, diagnostic FROM consultations WHERE patient_id=? ORDER BY date_consultation DESC LIMIT 20");
            $cs->execute([$patient['id']]);
            foreach ($cs->fetchAll() as $r) $histo[] = [
                'type'=>'consult','titre'=>$r['motif'] ?? 'Consultation',
                'desc'=>$r['diagnostic'] ?? '', 'date'=>date('d/m/Y · H:i', strtotime($r['date_consultation']))
            ];

            $os = $pdo->prepare("SELECT o.date_ordonnance, o.notes, m.nom FROM ordonnances o LEFT JOIN ordonnance_medicaments m ON m.ordonnance_id=o.id WHERE o.patient_id=? ORDER BY o.date_ordonnance DESC LIMIT 20");
            $os->execute([$patient['id']]);
            foreach ($os->fetchAll() as $r) $histo[] = [
                'type'=>'ord','titre'=>$r['nom'] ?? 'Ordonnance',
                'desc'=>$r['notes'] ?? '', 'date'=>date('d/m/Y · H:i', strtotime($r['date_ordonnance']))
            ];

            $fs = $pdo->prepare("SELECT f.date_facture, f.total, fa.libelle FROM factures f LEFT JOIN facture_actes fa ON fa.facture_id=f.id WHERE f.patient_id=? ORDER BY f.date_facture DESC LIMIT 20");
            $fs->execute([$patient['id']]);
            foreach ($fs->fetchAll() as $r) $histo[] = [
                'type'=>'facture','titre'=>($r['libelle'] ?? 'Facture') . ' · ' . number_format((int)$r['total'], 0, ',', ' ') . ' FCFA',
                'desc'=>'', 'date'=>date('d/m/Y · H:i', strtotime($r['date_facture']))
            ];

            echo json_encode(['patient'=>$patient, 'historique'=>$histo], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $q = trim($_GET['q'] ?? '');
        if ($q !== '') {
            $stmt = $pdo->prepare("SELECT * FROM patients WHERE nom LIKE ? OR telephone LIKE ? ORDER BY nom LIMIT 20");
            $stmt->execute(['%'.$q.'%', '%'.$q.'%']);
        } else {
            $stmt = $pdo->query("SELECT * FROM patients ORDER BY nom LIMIT 20");
        }
        $rows = array_map('enrich', $stmt->fetchAll());
        echo json_encode(['results'=>$rows], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Exception $e) {
        // fallback démo
    }
}

// Fallback démo (pas de base)
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    foreach ($demo as $p) if ((int)$p['id']===$id) {
        $p = enrich($p);
        $histo = [
            ['type'=>'consult','titre'=>'Fièvre persistante','desc'=>'Paludisme simple','date'=>date('d/m/Y · H:i', strtotime('-2 day'))],
            ['type'=>'ord','titre'=>'Paracétamol 500mg','desc'=>'3x/jour pendant 7j','date'=>date('d/m/Y · H:i', strtotime('-2 day'))],
            ['type'=>'facture','titre'=>'Consultation · 5 000 FCFA','desc'=>'','date'=>date('d/m/Y · H:i', strtotime('-2 day'))],
            ['type'=>'analyse','titre'=>'NFS complète','desc'=>'Résultat normal','date'=>date('d/m/Y · H:i', strtotime('-1 day'))],
        ];
        echo json_encode(['patient'=>$p,'historique'=>$histo], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['patient'=>null,'historique'=>[]]);
    exit;
}

$q = mb_strtolower(trim($_GET['q'] ?? ''));
$rows = array_values(array_filter($demo, function($p) use ($q){
    if ($q==='') return true;
    return mb_strpos(mb_strtolower($p['nom']), $q) !== false || mb_strpos($p['telephone'], $q) !== false;
}));
echo json_encode(['results'=>array_map('enrich', $rows)], JSON_UNESCAPED_UNICODE);
