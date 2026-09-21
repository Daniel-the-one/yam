<?php
/**
 * Connexion PDO à la base "kondjipro" — supporte MySQL ET SQLite.
 *
 * Le driver est choisi via `KPRO_DB_DRIVER` (ou le fichier local) :
 *   - 'mysql'  : connexion MySQL/MariaDB (host/port ou socket Unix) ;
 *   - 'sqlite' : fichier SQLite (`KPRO_DB_SQLITE`), utilisé quand MySQL
 *                n'est pas disponible (hébergement sans accès cPanel).
 *                Le fichier doit idéalement être stocké HORS du docroot.
 *
 * Ordre de résolution de la configuration (du plus prioritaire au moins) :
 *   1. fichier local `config/db.local.php` (tableau PHP) — recommandé en prod
 *      car il permet de garder les identifiants hors du code versionné ;
 *   2. variables d'environnement KPRO_DB_* ;
 *   3. valeurs par défaut de développement.
 *
 * Si la connexion échoue, db_connect() renvoie null : les pages basculent
 * alors en mode démonstration (données figées) sans planter.
 */

/**
 * Renvoie la configuration de connexion résolue.
 */
function db_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $cfg = [
        // 'mysql' (défaut) ou 'sqlite' (mode serveur sans cPanel).
        'driver'  => getenv('KPRO_DB_DRIVER') ?: 'mysql',
        'host'    => getenv('KPRO_DB_HOST') ?: '127.0.0.1',
        'port'    => getenv('KPRO_DB_PORT') ?: '3306',
        'name'    => getenv('KPRO_DB_NAME') ?: 'kondjipro',
        'user'    => getenv('KPRO_DB_USER') ?: 'root',
        'pass'    => getenv('KPRO_DB_PASS') !== false ? getenv('KPRO_DB_PASS') : '',
        'charset' => 'utf8mb4',
        // Si renseigné, la connexion MySQL passe par un socket Unix (utile en dev).
        'socket'  => getenv('KPRO_DB_SOCKET') ?: null,
        // Chemin du fichier SQLite (utilisé si driver = sqlite).
        'sqlite'  => getenv('KPRO_DB_SQLITE') ?: null,
    ];

    // Surcharge locale non déployée (aucune valeur sensible dans le code).
    if (is_file(__DIR__ . '/db.local.php')) {
        $local = require __DIR__ . '/db.local.php';
        if (is_array($local)) {
            $cfg = array_merge($cfg, $local);
        }
    }
    return $cfg;
}

// Constantes historiques conservées (dérivées de la configuration résolue).
if (!defined('DB_HOST'))    define('DB_HOST', db_config()['host']);
if (!defined('DB_PORT'))    define('DB_PORT', db_config()['port']);
if (!defined('DB_NAME'))    define('DB_NAME', db_config()['name']);
if (!defined('DB_USER'))    define('DB_USER', db_config()['user']);
if (!defined('DB_PASS'))    define('DB_PASS', db_config()['pass']);
if (!defined('DB_CHARSET')) define('DB_CHARSET', db_config()['charset']);

/**
 * Renvoie une instance PDO, ou null en cas d'échec.
 * L'échec est mémorisé pour la durée de la requête afin de ne pas
 * retenter une connexion impossible à chaque appel.
 */
function db_connect(): ?PDO {
    static $pdo = null;
    static $attempted = false;
    if ($attempted) return $pdo;
    $attempted = true;

    $cfg = db_config();
    $driver = $cfg['driver'] ?? 'mysql';

    if ($driver === 'sqlite') {
        $path = (string)($cfg['sqlite'] ?? '');
        if ($path === '' || (!is_file($path) && !is_writable(dirname($path)))) {
            error_log('[kondjipro] Fichier SQLite introuvable ou non inscriptible: ' . $path);
            return $pdo; // reste null → mode démo
        }
        $dsn = 'sqlite:' . $path;
    } elseif (!empty($cfg['socket'])) {
        $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $cfg['socket'], $cfg['name'], $cfg['charset']);
    } else {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $cfg['host'], $cfg['port'], $cfg['name'], $cfg['charset']);
    }

    try {
        $pdo = new PDO($dsn, $driver === 'sqlite' ? null : $cfg['user'], $driver === 'sqlite' ? null : $cfg['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if ($driver === 'sqlite') {
            // SQLite n'applique pas les clés étrangères par défaut.
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } catch (PDOException $e) {
        // Mode démo sans base — on journalise sans exposer les identifiants.
        error_log('[kondjipro] Connexion BDD indisponible: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/**
 * Petit helper pour calculer un âge à partir d'une date de naissance.
 */
function age_from_birth(?string $dob): ?int {
    if (!$dob) return null;
    try {
        $d = new DateTime($dob);
        $n = new DateTime('today');
        return $d->diff($n)->y;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Initiales colorées : renvoie un tableau [couleur_hex, lettres]
 * à partir du nom (utilisé pour l'avatar patient).
 */
function patient_avatar(?string $nom): array {
    $nom = trim((string)$nom);
    $palette = ['#10b981', '#f97316', '#6366f1', '#ec4899', '#0ea5e9', '#eab308'];
    $idx = 0;
    if ($nom !== '') {
        $idx = abs(crc32($nom)) % count($palette);
    }
    $lettres = '';
    foreach (preg_split('/\s+/', $nom) as $w) {
        $lettres .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($lettres) >= 2) break;
    }
    if ($lettres === '') $lettres = '?';
    return [$palette[$idx], $lettres];
}
