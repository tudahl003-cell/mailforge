<?php
// BOLT ELITE REDIRECT - front controller.
// Routes: /api/* (JSON), /admin (SPA), /{slug} + /r/{slug} + /t/{id} (public).

require __DIR__ . '/lib.php';
require __DIR__ . '/include/ua.php';
require __DIR__ . '/include/geo.php';
require __DIR__ . '/include/tokens.php';
require __DIR__ . '/include/templates.php';
require __DIR__ . '/include/tpl_engine.php';
require __DIR__ . '/include/ai.php';

$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = '/' . trim($path, '/');
if ($path === '//') $path = '/';

if (str_starts_with($path, '/static/')) {
    $rel  = preg_replace('#[^A-Za-z0-9._/-]#', '', substr($path, 8));
    $base = realpath(__DIR__ . '/public/static');
    $file = realpath(__DIR__ . '/public/static/' . ltrim((string)$rel, '/'));
    if ($base && $file && str_starts_with($file, $base) && is_file($file)) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8',
                  'png' => 'image/png', 'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml',
                  'ico' => 'image/x-icon', 'woff2' => 'font/woff2', 'json' => 'application/json'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/include/api.php';
    be_api(substr($path, 5));
    exit;
}

// Root + admin both open the admin console: pasting the bare domain must land
// somewhere real, never a 404. /health stays a plain health probe.
if ($path === '/' || $path === '/admin' || $path === '/admin/panel') {
    require __DIR__ . '/include/admin.php';
    be_admin_page();
    exit;
}

if ($path === '/health') {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode(['ok' => true, 'service' => 'bolt-elite-redirect']);
    exit;
}

require __DIR__ . '/include/serve.php';
be_serve($path);
