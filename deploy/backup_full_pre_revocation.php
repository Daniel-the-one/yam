<?php
/**
 * deploy-backup.php — BACKUP COMPLET avant révocation auth KondjiPro/KondjiPay.
 *
 * CE SCRIPT NE FAIT QUE DE LA COPIE / DU CHIFFRAGE.
 * IL NE RÉVOQUE RIEN, IL NE SUPPRIME RIEN, IL NE MODIFIE AUCUNE SESSION.
 *
 * Sortie (console, ZÉRO secret) :
 *   - un dossier deploy/backup-pre-revocation-<horodatage>/ contenant LA COPIE
 *     des sessions PHP + clés, et un dump des tokens (hashés/occultés);
 *   - des CHIFFRES uniquement (comptages), jamais de valeur.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$root   = dirname(__DIR__);
$stamp  = date('Ymd-His');
$bkDir  = $root . '/deploy/backup-pre-revocation-' . $stamp;

echo "=== BACKUP PRÉ-RÉVOCATION — {$stamp} ===\n\n";

// ── 1. Dossier de backup (permissions restrictives) ─────────────────────────
if (!is_dir($bkDir)) {
    mkdir($bkDir, 0700, true);
    echo "  [1/5] dossier créé : deploy/backup-pre-revocation-{$stamp}\n";
} else {
    echo "  [1/5] dossier déjà présent\n";
}

// ── 2. Copie des SESSIONS PHP actives (fichiers de session, copie seule) ────
$sessionsSrc = $root . '/api/public/storage/framework/sessions';
$copied = 0;
if (is_dir($sessionsSrc)) {
    $dest = $bkDir . '/sessions';
    // Copie récursive (copie, jamais suppression)
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sessionsSrc, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile()) {
            $rel = substr($file->getPathname(), strlen($sessionsSrc) + 1);
            $to  = $dest . '/' . $rel;
            if (!is_dir(dirname($to))) mkdir(dirname($to), 0700, true);
            if (copy($file->getPathname(), $to)) $copied++;
        }
    }
    echo "  [2/5] sessions PHP copiées : {$copied} fichier(s)\n";
} else {
    echo "  [2/5] dossier sessions absent — rien à copier\n";
}

// ── 3. Copie des CLÉS oauth (fichiers) — copie seule ─────────────────────────
$keys = [
    $root . '/api/public/storage/oauth-private.key',
    $root . '/api/public/storage/oauth-public.key',
];
$keysCopied = 0;
$destKeys = $bkDir . '/oauth-keys';
if (!is_dir($destKeys)) mkdir($destKeys, 0700, true);
foreach ($keys as $k) {
    if (is_file($k)) {
        if (copy($k, $destKeys . '/' . basename($k))) $keysCopied++;
    }
}
echo "  [3/5] clés oauth copiées : {$keysCopied} fichier(s)\n";

// ── 4. Dump BDD (table users + tokens) — chiffres seulement ─────────────────
echo "  [4/5] dump dbbd (users + tokens) — CHIFFRES SEULEMENT :\n";
$dbfile = $root . '/api/database/database.sqlite';
if (is_file($dbfile)) {
    $pdo = new PDO('sqlite:' . $dbfile);
    $nUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $nTokens = 0;
    try { $nTokens = (int)$pdo->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn(); } catch (Throwable $e) {}
    echo "    users : {$nUsers} · tokens oauth : {$nTokens}\n";
} else {
    echo "    (fichier sqlite absent — dump ignoré)\n";
}

// ── 5. Bilan — CHIFFRES seulement ───────────────────────────────────────────
echo "\n  [5/5] BILAN BACKUP (copie seule — RIEN n'a été révoqué) :\n";
echo "    dossier : deploy/backup-pre-revocation-{$stamp}\n";
echo "    sessions copiées : {$copied} · clés : {$keysCopied}\n";
echo "    → NEXT : confirmation avant toute révocation.\n";
echo "\n=== BACKUP TERMINÉ (aucune écriture destructive) ===\n";
