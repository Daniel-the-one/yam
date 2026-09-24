<?php
/**
 * migrate.php — Migration SQLite -> MySQL pour la PRODUCTION o2switch.
 * -------------------------------------------------------------------------
 * Ce script est prévu pour être :
 *   - testé en local en CLI (php deploy/prod-migration/migrate.php)
 *   - exécuté en production via HTTP (pas de SSH chez o2switch), protégé
 *     par un token secret, puis SUPPRIMÉ du serveur.
 *
 * Il effectue, de façon idempotente :
 *   1. YAM     : création du schéma MySQL (yam-schema.sql)
 *   2. YAM     : copie des données SQLite -> MySQL
 *   3. KONDJI  : création du schéma MySQL (kondjipro-schema.sql)
 *   4. KONDJI  : données d'exemple si la base est vide (kondjipro-seed.sql)
 *
 * Configuration : migrate-config.json (même dossier), NON versionné :
 * {
 *   "token": "secret-pour-acces-web",
 *   "sqlite_path": "/chemin/vers/database.sqlite",
 *   "yam":       { "host":"localhost","port":3306,"name":"...","user":"...","pass":"..." },
 *   "kondjipro": { "host":"localhost","port":3306,"name":"...","user":"...","pass":"..." },
 *   "tables": ["users","devices","call_offers","call_sessions","appels","personal_access_tokens"]
 * }
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function out(string $s): void { echo $s . "\n"; }
function fail(string $s): void { echo 'ERREUR: ' . $s . "\n"; }

$baseDir    = __DIR__;
$configFile = $baseDir . '/migrate-config.json';

if (!is_file($configFile)) {
    fail('migrate-config.json introuvable.');
    exit(1);
}
$cfg = json_decode((string)file_get_contents($configFile), true);
if (!is_array($cfg)) {
    fail('migrate-config.json illisible (JSON invalide).');
    exit(1);
}

// --- Garde d'accès pour l'exécution web ---
if (!$isCli) {
    $token = (string)($_GET['t'] ?? '');
    $expected = (string)($cfg['token'] ?? '');
    if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
        http_response_code(404);
        exit('not found');
    }
}

set_time_limit(300);

// --- Helpers SQL ---------------------------------------------------------

/** Découpe un fichier SQL en instructions (gère chaînes et commentaires). */
function split_sql(string $sql): array
{
    $stmts = [];
    $buf = '';
    $inSingle = $inDouble = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($inSingle) {
            $buf .= $ch;
            if ($ch === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") { $buf .= $sql[++$i]; }
                else { $inSingle = false; }
            }
            continue;
        }
        if ($inDouble) {
            $buf .= $ch;
            if ($ch === '"') {
                if ($i + 1 < $len && $sql[$i + 1] === '"') { $buf .= $sql[++$i]; }
                else { $inDouble = false; }
            }
            continue;
        }
        if ($ch === "'") { $inSingle = true; $buf .= $ch; continue; }
        if ($ch === '"') { $inDouble = true; $buf .= $ch; continue; }

        // Commentaire ligne : -- ... \n
        if ($ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") { $i++; }
            continue;
        }
        // Commentaire bloc : /* ... */
        // Cas particulier : les commentaires conditionnels /*! ... */ (MySQL)
        // et /*M! ... */ (MariaDB) contiennent du SQL EXÉCUTABLE (ex. le
        // SET FOREIGN_KEY_CHECKS=0 de mysqldump) : on les conserve.
        if ($ch === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
            $conditional = ($sql[$i + 2] ?? '') === '!'
                || (($sql[$i + 2] ?? '') === 'M' && ($sql[$i + 3] ?? '') === '!');
            $start = $i;
            $i += 2;
            while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) { $i++; }
            if ($conditional) {
                $buf .= substr($sql, $start, $i + 1 - $start + 1);
            }
            $i++; // saute le '/'
            continue;
        }
        if ($ch === ';') {
            $stmt = trim($buf);
            if ($stmt !== '') { $stmts[] = $stmt; }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $stmt = trim($buf);
    if ($stmt !== '') { $stmts[] = $stmt; }
    return $stmts;
}

function run_sql_file(PDO $pdo, string $file): int
{
    if (!is_file($file)) { throw new RuntimeException('Fichier SQL introuvable: ' . $file); }
    $count = 0;
    // Les DROP/CREATE d'un dump peuvent se gêner entre eux (clés étrangères) :
    // on neutralise les contraintes le temps de l'application du schéma.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach (split_sql((string)file_get_contents($file)) as $stmt) {
            $pdo->exec($stmt);
            $count++;
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    return $count;
}

function connect(array $c): PDO
{
    $socket = $c['socket'] ?? null;
    $dsn = $socket
        ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $c['name'])
        : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);
    return new PDO($dsn, $c['user'], $c['pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function columns(PDO $pdo, string $table, string $driver): array
{
    $cols = [];
    if ($driver === 'sqlite') {
        foreach ($pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")') as $r) {
            $cols[$r['name']] = strtolower((string)$r['type']);
        }
    } else {
        foreach ($pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`') as $r) {
            $cols[$r['Field']] = strtolower((string)$r['Type']);
        }
    }
    return $cols;
}

function tables(PDO $pdo, string $driver): array
{
    $list = [];
    $sql = $driver === 'sqlite'
        ? "SELECT name FROM sqlite_master WHERE type='table'"
        : 'SHOW TABLES';
    foreach ($pdo->query($sql) as $r) { $list[] = array_values($r)[0]; }
    return $list;
}

function sanitize($value, string $mysqlType)
{
    if ($value === null) return null;
    if ($value === '' && preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|date|datetime|timestamp|time|year)/', $mysqlType)) {
        return null;
    }
    return $value;
}

/** Copie les lignes SQLite -> MySQL (colonnes communes uniquement). */
function copy_table(PDO $src, PDO $dst, string $table): int
{
    $srcCols = columns($src, $table, 'sqlite');
    $dstCols = columns($dst, $table, 'mysql');
    $common = array_values(array_intersect(array_keys($dstCols), array_keys($srcCols)));
    if (!$common) { return -1; }

    $dst->exec('DELETE FROM `' . $table . '`');
    $count = (int)$src->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn();
    if ($count === 0) { return 0; }

    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $common));
    $ph = implode(', ', array_map(fn($c) => ":{$c}", $common));
    $insert = $dst->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$ph})");
    $select = $src->query('SELECT ' . implode(', ', array_map(fn($c) => "\"{$c}\"", $common)) . " FROM \"{$table}\"");
    foreach ($select as $row) {
        $params = [];
        foreach ($common as $c) { $params[":{$c}"] = sanitize($row[$c], $dstCols[$c]); }
        $insert->execute($params);
    }
    return $count;
}

// --- Exécution -----------------------------------------------------------

out('=== Migration SQLite -> MySQL (production) ===');
out('SQLite source : ' . ($cfg['sqlite_path'] ?? '(non défini)'));

$errors = 0;

// 1 + 2 : YAM
try {
    out("\n--- YAM ---");
    $yam = connect($cfg['yam']);
    out('Connexion MySQL OK (' . ($cfg['yam']['name'] ?? '?') . ')');
    $n = run_sql_file($yam, $baseDir . '/yam-schema.sql');
    out("Schéma YAM appliqué ({$n} instructions).");

    $sqlitePath = (string)($cfg['sqlite_path'] ?? '');
    if ($sqlitePath === '' || !is_file($sqlitePath)) {
        throw new RuntimeException('Base SQLite introuvable: ' . $sqlitePath);
    }
    $src = new PDO('sqlite:' . $sqlitePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $srcTables = tables($src, 'sqlite');
    $yamTables = tables($yam, 'mysql');
    $tables = $cfg['tables'] ?? ['users', 'devices', 'call_offers', 'call_sessions', 'appels', 'personal_access_tokens'];

    $yam->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables as $t) {
        if (!in_array($t, $srcTables, true)) { out("  {$t}: absente de SQLite — ignorée"); continue; }
        if (!in_array($t, $yamTables, true))  { out("  {$t}: absente de MySQL — IGNORÉE"); $errors++; continue; }
        $c = copy_table($src, $yam, $t);
        out("  {$t}: {$c} ligne(s) copiée(s)");
    }
    $yam->exec('SET FOREIGN_KEY_CHECKS=1');
} catch (Throwable $e) {
    fail('YAM: ' . $e->getMessage());
    $errors++;
}

// 3 + 4 : KondjiPro
try {
    out("\n--- KondjiPro ---");
    $kpro = connect($cfg['kondjipro']);
    out('Connexion MySQL OK (' . ($cfg['kondjipro']['name'] ?? '?') . ')');
    $n = run_sql_file($kpro, $baseDir . '/kondjipro-schema.sql');
    out("Schéma KondjiPro appliqué ({$n} instructions).");

    $existing = (int)$kpro->query('SELECT COUNT(*) FROM patients')->fetchColumn();
    if ($existing === 0) {
        $n = run_sql_file($kpro, $baseDir . '/kondjipro-seed.sql');
        out("Données d'exemple insérées ({$n} instructions).");
    } else {
        out("Données présentes ({$existing} patients) — seed ignoré.");
    }
} catch (Throwable $e) {
    fail('KondjiPro: ' . $e->getMessage());
    $errors++;
}

out("\n=== Terminé" . ($errors ? " avec {$errors} erreur(s)" : ' sans erreur') . ' ===');
if (!$isCli) {
    out('⚠️  Pensez à SUPPRIMER ce script et migrate-config.json du serveur.');
}
exit($errors ? 2 : 0);
