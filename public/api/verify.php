<?php
// api/verify.php — email checker / verifier
//   POST {emails: "a@x.com\nb@y.com" | [...], deep: bool, smtp_probe: bool}
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/validate.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);
$in = json_in();
$raw = $in['emails'] ?? $in['email'] ?? '';
if (is_array($raw)) $list = array_map('trim', $raw);
else $list = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,|;/', (string)$raw)), fn($l) => $l !== ''));
$list = array_slice($list, 0, 2000);
if (!$list) json_out(['ok' => false, 'error' => 'no emails supplied'], 400);

$deep = !empty($in['deep']) || !empty($in['mx']);
$probe = !empty($in['smtp_probe']);

$acct = $probe ? smtp_best_account(0, 'rotate') : null;

$results = [];
$counts = ['valid' => 0, 'risky' => 0, 'invalid' => 0, 'unknown' => 0];
foreach ($list as $email) {
    $v = verify_email($email, $deep, $probe, $acct);
    $counts[$v['result']] = ($counts[$v['result']] ?? 0) + 1;
    db()->prepare('INSERT INTO verify_log (email, result, detail) VALUES (?,?,?)')
        ->execute([$email, $v['result'], json_encode($v['detail'] ?? [])]);
    $results[] = ['email' => $email, 'result' => $v['result'],
                  'score' => (int)$v['score'], 'detail' => $v['detail'] ?? []];
}
json_out(['ok' => true, 'count' => count($results), 'totals' => $counts, 'results' => $results]);
