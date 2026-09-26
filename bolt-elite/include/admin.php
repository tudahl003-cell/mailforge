<?php
// BOLT ELITE REDIRECT - admin SPA shell.

function be_admin_page(): void {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    echo '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Bolt Elite Redirect</title>
<link rel="stylesheet" href="/static/style.css">
</head>
<body>
<div id="app"></div>
<script src="/static/app.js"></script>
</body>
</html>';
}
