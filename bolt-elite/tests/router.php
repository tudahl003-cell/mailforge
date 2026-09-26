<?php
// Router shim so PHP's built-in server mimics the Apache rewrite rules.
$p = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$root = dirname(__DIR__);

// Emulate the Apache .htaccess denials so the HTTP test mirrors production.
if (preg_match('#^/(data|include|tests)/#', $p) || in_array($p, ['/lib.php', '/entrypoint.sh', '/Dockerfile'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo 'Forbidden';
    return true;
}

if ($p !== '/' && $p !== '' && file_exists($root . $p) && !is_dir($root . $p)) {
    return false; // let the server serve real files (static assets)
}
require $root . '/index.php';
