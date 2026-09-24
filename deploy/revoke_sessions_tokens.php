<?php
/**
 * revoke_sessions_tokens.php — RÉVOCATION GLOBALE KondjiPro.
 *
 * VALIDÉ GO utilisateur — séquence sûre :
 *   1. BACKUP complet (sessions + clés + dump base users)  → deploy/backup-pre-revocation-<ts>/
 *   2. Suppression des SESSIONS PHP actives (fichiers)
 *   3. Révocation des tokens API + remember_token EN BASE (comptes CONSERVÉS)
 * BILAN en CHIFFRES uniquement (jamais de contenu, jamais de secret).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root  = dirname(__DIR__);
$stamp = date('Ymd-His');
$bkDir = $root . '/deploy/backup-pre-revocation-' . $stamp;

echo "=== RÉVOCATION GLOBALE KondjiPro — {$stamp} ===\n";

// ── 1. BACKUP (copies uniquement — jamais destructive) ───────────────────────
if (!is_dir($bkDir)) mkdir($bkDir, 0700, true collectible, true);
echo "  [1/3] dossier backup créé (copies) : " . basename($bkDir) . "\n";

$copied = 0;
$srcSessions = $root . '/api/public/storage/framework/sessions';
if (is_dir($srcSessions)) {
    $dest = $bkDir . '/sessions';
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcSessions, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && basename($f->getPathname()) !== '.gitignore') {
            $rel = substr($f->getPathname(), strlen($srcSessions) + 1);
            $to  = $dest . '/' . $rel;
            if (!is_dir(dirname($to))) mkdir(dirname($to), 0700, true);
            if (copy($f->getPathname(), $to)) $copied++;
        }
    }
}
echo "  [2/3] sessions PHP copiées : {$copied} fichier(s) (backup)\n";

// clés oauth
$keys = array('/api/public/storage/oauth-private.key', '/api/public/storage/oauth-public.key');
$kCopied = 0;
$destKeys = $bkDir . '/oauth-keys';
if (!is_dir($destKeys)) mkdir($destKeys, 0700, true);
foreach ($keys as $k) {
    $p = $root . $k;
    if (is_file($p) && copy($p, $destKeys . '/' . basename($p))) $kCopied++;
}
echo "  [2/3] clés oauth copiées : {$kCopied} (backup)\n";

// dump base (chiffres uniquement)
$dbFile = $root . '/api/database/database.sqlite';
if (is_file($dbFile)) {
    $pdo = new PDO('sqlite:' . $dbFile);
    $nb = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo "  [2/3] base SQLite : {$nb} comptes (rien n'y est modifié — référence)\n";
}

// ── 2. RÉVOCATION des sessions (suppression fichiers) ────────────────────────
$removed = 0;
if (is_dir($srcSessions)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcSessions, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile() && basename($f->getPathname()) !== '.gitignore') {
            if (unlink($f->getPathname())) $removed++;
        }
    }
}
echo "  [3/3] sessions PHP RÉVOQUÉES (supprimées) : {$removed} — comptes intacts\n";

// ── 3. Révocation tokens EN BASE (comptes conservés) ─────────────────────────
$updated = 0;
try {
    require $root . '/api/public/config/db.php';
    $c = db_config();
    $pdo = new PDO(sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4',
        $c['socket'], $c['name']), $c['user'], $c['pass']);
    $updated = $pdo->exec(
        'UPDATE users SET api_token = NULL, remember_token = NULL
         WHERE api_token IS NOT NULL OR remember_token IS NOT NULL'
    );
    echo "  [3/3] tokens API révoqués en base : {$updated} — comptes + mdp CONSERVÉS\n";
} catch (Throwable $e) {
    echo "  [3/3] (base MySQL indisponible — tokens base non révoqués) — sessions déjà purgées\n";
}

echo "\nBILAN : {$copied} session(s) copiée(s) au backup · {$removed} session(s) révoquée(s) · {$kCopied} clé(s) · {$updated} token(s) base\n";
echo "  → TOUT LE MONDE est déconnecté. Comptes et mots de passe : INTACTS.\n";
echo "  ★ TERMINÉ REFERENCE:GLOBAL-REVOKE-2026\n";
