<?php
/**
 * Router pour le serveur PHP intégré : php -S 127.0.0.1:8080 router.php
 * Sert les fichiers statiques d'assets/ et passe le reste aux .php.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && file_exists($file) && !is_dir($file)) {
    return false; // laisse le serveur intégré servir le fichier
}
if ($path === '/' || $path === '') {
    require __DIR__ . '/dashboard.php';
    return true;
}
if (str_ends_with($path, '.php') && file_exists(__DIR__ . $path)) {
    require __DIR__ . $path;
    return true;
}
http_response_code(404);
echo '<h1>404 — Page introuvable</h1>';
return true;
