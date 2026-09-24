<?php
/**
 * migrate_sqlite_to_mysql.php
 * -------------------------------------------------------------------------
 * Copie les DONNÉES d'une base SQLite (ancienne base Laravel YAM) vers MySQL.
 *
 * Le SCHÉMA MySQL doit déjà exister. On le crée d'abord avec :
 *     php artisan migrate --force
 * Ce script ne fait ensuite QUE recopier les lignes.
 *
 * Usage :
 *   php database/migrate_sqlite_to_mysql.php [--sqlite=CHEMIN] [--tables=a,b,c] [--dry-run]
 *
 * Configuration MySQL (variables d'environnement, sinon valeurs par défaut) :
 *   DB_HOST (127.0.0.1)  DB_PORT (3306)  DB_DATABASE (yam)
 *   DB_USERNAME (yam)    DB_PASSWORD ('')
 *
 * Le script est idempotent : il vide chaque table cible avant de la remplir.
 * Il conserve les identifiants (id) d'origine et désactive temporairement les
 * contraintes de clés étrangères le temps de l'import.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

$opts = getopt('', ['sqlite::', 'tables::', 'dry-run', 'help']);

if (isset($opts['help'])) {
    fwrite(STDOUT, "Usage: php database/migrate_sqlite_to_mysql.php [--sqlite=...] [--tables=a,b,c] [--dry-run]\n");
    exit(0);
}

$sqlitePath = $opts['sqlite'] ?? (getenv('SRC_SQLITE') ?: __DIR__ . '/database.sqlite');
// Tables métier à migrer. On exclut volontairement les tables éphémères
// (sessions, cache, jobs) et la table `migrations` (déjà remplie par artisan).
$defaultTables = ['users', 'devices', 'call_offers', 'call_sessions', 'appels', 'personal_access_tokens'];
$tables = isset($opts['tables']) ? array_filter(array_map('trim', explode(',', (string)$opts['tables']))) : $defaultTables;
$dryRun = isset($opts['dry-run']);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_DATABASE') ?: 'yam';
$dbUser = getenv('DB_USERNAME') ?: 'yam';
$dbPass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

if (!is_file($sqlitePath)) {
    fwrite(STDERR, "[ERREUR] Base SQLite introuvable : {$sqlitePath}\n");
    exit(1);
}

echo "=== Migration SQLite -> MySQL ===\n";
echo "  Source SQLite : {$sqlitePath}\n";
echo "  Cible MySQL   : {$dbUser}@{$dbHost}:{$dbPort}/{$dbName}\n";
echo "  Tables        : " . implode(', ', $tables) . "\n";
echo $dryRun ? "  Mode          : DRY-RUN (aucune écriture)\n" : "  Mode          : RÉEL\n";
echo "--------------------------------\n";

$src = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$dst = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName),
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

/** Colonnes d'une table SQLite : [nom => type déclaré]. */
function sqlite_columns(PDO $src, string $table): array
{
    $cols = [];
    foreach ($src->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")') as $row) {
        $cols[$row['name']] = strtolower((string)$row['type']);
    }
    return $cols;
}

/** Colonnes d'une table MySQL : [nom => type]. */
function mysql_columns(PDO $dst, string $table): array
{
    $cols = [];
    $stmt = $dst->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    foreach ($stmt as $row) {
        $cols[$row['Field']] = strtolower((string)$row['Type']);
    }
    return $cols;
}

/** Tables existantes côté MySQL. */
function mysql_tables(PDO $dst): array
{
    $tables = [];
    foreach ($dst->query('SHOW TABLES') as $row) {
        $tables[] = array_values($row)[0];
    }
    return $tables;
}

/** Tables existantes côté SQLite. */
function sqlite_tables(PDO $src): array
{
    $tables = [];
    foreach ($src->query("SELECT name FROM sqlite_master WHERE type='table'") as $row) {
        $tables[] = $row['name'];
    }
    return $tables;
}

/**
 * Nettoie une valeur selon le type MySQL cible :
 * les chaînes vides sont converties en NULL pour les colonnes numériques
 * et temporelles (SQLite est laxiste, MySQL strict ne l'est pas).
 */
function sanitize_value($value, string $mysqlType)
{
    if ($value === null) return null;
    if ($value === '') {
        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|date|datetime|timestamp|time|year)/', $mysqlType)) {
            return null;
        }
    }
    return $value;
}

$srcTables = sqlite_tables($src);
$dstTables = mysql_tables($dst);
$total = 0;
$errors = 0;

if (!$dryRun) {
    $dst->exec('SET FOREIGN_KEY_CHECKS=0');
}

foreach ($tables as $table) {
    echo "→ {$table} : ";
    if (!in_array($table, $srcTables, true)) {
        echo "ignorée (absente de SQLite)\n";
        continue;
    }
    if (!in_array($table, $dstTables, true)) {
        echo "IGNORÉE (table MySQL absente — lancez artisan migrate)\n";
        $errors++;
        continue;
    }

    $srcCols = sqlite_columns($src, $table);
    $dstCols = mysql_columns($dst, $table);
    $common = array_values(array_intersect(array_keys($dstCols), array_keys($srcCols)));
    if (!$common) {
        echo "IGNORÉE (aucune colonne commune)\n";
        $errors++;
        continue;
    }

    $count = (int)$src->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    echo "{$count} ligne(s)";
    if ($dryRun) {
        echo " — simulé\n";
        $total += $count;
        continue;
    }

    try {
        $dst->beginTransaction();
        // Table vidée pour rendre l'import rejouable sans doublons.
        $dst->exec('DELETE FROM `' . $table . '`');

        if ($count > 0) {
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $common));
            $placeholders = implode(', ', array_map(fn($c) => ":{$c}", $common));
            $insert = $dst->prepare("INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})");
            $select = $src->query('SELECT ' . implode(', ', array_map(fn($c) => "\"{$c}\"", $common)) . " FROM \"{$table}\"");

            foreach ($select as $row) {
                $params = [];
                foreach ($common as $col) {
                    $params[":{$col}"] = sanitize_value($row[$col], $dstCols[$col]);
                }
                $insert->execute($params);
            }
        }
        $dst->commit();
        echo " — copiées" . ($srcCols !== $dstCols ? " (colonnes communes: " . implode(', ', $common) . ")" : "") . "\n";
        $total += $count;
    } catch (Throwable $e) {
        if ($dst->inTransaction()) {
            $dst->rollBack();
        }
        echo "ERREUR : " . $e->getMessage() . "\n";
        $errors++;
    }
}

if (!$dryRun) {
    $dst->exec('SET FOREIGN_KEY_CHECKS=1');
}

echo "--------------------------------\n";
echo "Total lignes copiées : {$total}" . ($errors ? " | {$errors} erreur(s)" : " | 0 erreur") . "\n";
exit($errors ? 2 : 0);
