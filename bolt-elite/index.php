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

if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/include/api.php';
    be_api(substr($path, 5));
    exit;
}

if ($path === '/admin' || $path === '/admin/panel') {
    require __DIR__ . '/include/admin.php';
    be_admin_page();
    exit;
}

if ($path === '/' || $path === '/health') {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex,nofollow"><title>404</title>'
       . '<div style="font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
       . 'display:grid;place-items:center;height:100vh;color:#5b6472">'
       . '<div style="text-align:center"><div style="font-size:44px;font-weight:700;color:#111418">404</div>'
       . '<div>This page could not be found.</div></div></div>';
    exit;
}

require __DIR__ . '/include/serve.php';
be_serve($path);
