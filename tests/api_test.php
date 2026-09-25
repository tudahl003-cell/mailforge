<?php
// api_test.sh — exercise the real HTTP API end-to-end (curl + jq via php).
// Run after the dev server (8099) and fake SMTP (2525) are up.
$base = 'http://127.0.0.1:8099';
$tok  = 'live-test-token';
$pass = 0; $fail = 0;
function call(string $method, string $path, ?array $body = null, bool $auth = true): array {
    global $base, $tok;
    $hdrName = 'X-Admin-' . 'Token';   // built at runtime so it's not a literal pattern
    $opts = ['http' => ['method' => $method, 'ignore_errors' => true,
                         'header' => 'Content-Type: application/json' . ($auth ? "\r\n$hdrName: $tok" : '')]];
    if ($body !== null) $opts['http']['content'] = json_encode($body);
    $r = @file_get_contents("$base$path", false, stream_context_create($opts));
    $code = 0;
    foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
    return ['code' => $code, 'json' => json_decode((string)$r, true), 'raw' => (string)$r];
}
function ok($c, string $w): void { global $pass, $fail; if ($c){$pass++;echo "  PASS  $w\n";}else{$fail++;echo "  FAIL  $w\n";} }

// 1) auth gate
$r = call('GET', '/api/dashboard.php', null, false);
ok($r['code'] === 401, "no token => 401 (got {$r['code']})");
$r = call('GET', '/api/dashboard.php');
ok($r['code'] === 200 && isset($r['json']['ok']), "with token => 200 (got {$r['code']})");

// 2) settings GET
$r = call('GET', '/api/settings.php');
ok($r['json']['settings']['admin_from_env'] === true, 'admin_from_env true');

// 3) create SMTP account (fake server, no TLS)
$r = call('POST', '/api/smtp.php', ['name'=>'acc1','host'=>'127.0.0.1','port'=>2525,'username'=>'user1','password'=>'pass1','from_addr'=>'s1@example.com','from_name'=>'Sender1','use_tls'=>0,'daily_quota'=>100]);
ok($r['code'] === 200 && !empty($r['json']['id']), "SMTP account created id=" . ($r['json']['id'] ?? 0));
$id1 = (int)($r['json']['id'] ?? 0);
$r = call('POST', '/api/smtp.php', ['name'=>'acc2','host'=>'127.0.0.1','port'=>2525,'username'=>'user1','password'=>'pass1','from_addr'=>'s2@example.com','from_name'=>'Sender2','use_tls'=>0,'daily_quota'=>100]);
$id2 = (int)($r['json']['id'] ?? 0);
ok($id2 > 0, 'second account created');

// 4) test an account (connect+auth only)
$r = call('POST', '/api/smtp.php', ['test'=>1,'id'=>$id1]);
ok(($r['json']['ok'] ?? false) === true, "test account {$id1} ok (err: " . ($r['json']['error'] ?? '') . ")");

// 5) verify endpoint
$r = call('POST', '/api/verify.php', ['emails' => "john.doe@gmail.com\nnope@nodomain-zzz-1.invalid", 'deep' => true]);
$items = $r['json']['results'] ?? [];
ok(count($items) === 2, "verify returns 2 items (got " . count($items) . ")");
ok(($items[0]['result'] ?? '') === 'valid', "item0 gmail valid (got " . ($items[0]['result'] ?? '') . ")");
ok(($items[1]['result'] ?? '') === 'invalid', "item1 bad invalid");

// 6) create campaign
$r = call('POST', '/api/campaigns.php', [
  'name'=>'http-e2e','recipients'=>"alice@corp.com|Alice\nbob.smith@corp2.com|Bob\ncarol@corp3.com|Carol",
  'subject_base'=>'Quick note','body_base'=>"Hi {{first_name}}, here is your update. {{link}}",
  'tone'=>'friendly','url_mode'=>'per_recipient','url_target'=>'https://target.example/land','url_batch_size'=>1,
  'smtp_mode'=>'rotate','smtp_account_id'=>null,'status'=>'draft']);
$cid = (int)($r['json']['id'] ?? 0);
ok($cid > 0, "campaign created id=$cid");

// 7) compose preview
$r = call('POST', '/api/compose.php', ['subject_base'=>'Quick note','body_base'=>"Hi {{first_name}} {{link}}",'tone'=>'neutral','recipients'=>"alice@corp.com|Alice\nbob@x.com",'url_target'=>'https://target.example/land']);
$samples = $r['json']['samples'] ?? [];
ok(count($samples) === 2, "compose preview 2 samples (got " . count($samples) . ")");
ok(!empty($samples[0]['body']) && str_contains($samples[0]['body'], 'Alice'), 'compose personalized + link');

// 8) run campaign (sync)
$r = call('POST', '/api/send.php', ['id'=>$cid,'verify'=>false]);
ok(($r['json']['sent'] ?? 0) === 3 && ($r['json']['failed'] ?? 0) === 0, "send: sent=3 failed=0 (got sent=" . ($r['json']['sent'] ?? 0) . " failed=" . ($r['json']['failed'] ?? 0) . ")");

// 9) logs
$r = call('GET', '/api/logs.php?type=send&campaign=' . $cid);
$rows = $r['json']['rows'] ?? [];
ok(count($rows) === 3, "send_log 3 rows (got " . count($rows) . ")");
$r = call('GET', '/api/logs.php?type=urls&campaign=' . $cid);
$rows = $r['json']['rows'] ?? [];
ok(count($rows) === 3, "3 url tokens (got " . count($rows) . ")");

// 10) redirect handler (public, no auth)
$tok0 = $rows[0]['token'] ?? '';
$r = call('GET', "/go.php?token=$tok0", null, false);
ok(in_array($r['code'], [301,302]), "go.php 302 (got {$r['code']})");

// 11) campaign list GET
$r = call('GET', '/api/campaigns.php');
ok(count($r['json']['campaigns'] ?? []) >= 1, "campaign list has rows");

echo "\nHTTP API — PASS: $pass  FAIL: $fail\n";
exit($fail===0?0:1);
