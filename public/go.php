<?php
// go.php — public rotating-link redirect. No auth (recipients hit this).
// /go/<token> → 302 to the stored target; increments the hit counter.
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../include/url.php';

// Apache rewrites /go/<token> here as ?token=
$token = (string)($_GET['token'] ?? '');
if ($token === '' || strlen($token) > 64 || !preg_match('/^[a-f0-9]{8,64}$/', $token)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
    exit;
}

$row = url_lookup($token);
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
    exit;
}

// record the hit (who, when)
try {
    db()->prepare('UPDATE url_tokens SET hits = hits + 1 WHERE token = ?')
        ->execute([$token]);
} catch (Throwable $e) {}
tg_hit_log($row, (string)($token));

// redirect
header('Location: ' . $row['target'], true, 302);
echo '';
exit;

/**
 * Append a lightweight hit log line (IP, UA, referer, time) so you can
 * see *who* opened a link and *when* — without storing PII beyond IP/UA.
 */
function tg_hit_log(array $row, string $token): void {
    $f = DATA_DIR . '/hits_' . (int)$row['campaign_id'] . '.log';
    $line = sprintf(
        "%s\t%s\t%s\t%s\t%s\n",
        date('c'),
        ip(),
        $_SERVER['HTTP_USER_AGENT'] ?? '-',
        $_SERVER['HTTP_REFERER'] ?? '-',
        $token
    );
    @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
}
