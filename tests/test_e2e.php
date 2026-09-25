<?php
// test_e2e.php — functional test of mailforge core (CLI).
// Starts a fake SMTP server (port 2525), then exercises:
//   1) validator (syntax, MX, disposable, role)
//   2) template composer uniqueness + link injection
//   3) smtp_send against the fake server (auth, headers, DATA)
//   4) URL token store/lookup/public URL
//   5) run_campaign end-to-end (validate + rotate 2 accounts + links)
error_reporting(E_ALL & ~E_DEPRECATED);
putenv('ADMIN_TOKEN=test-secret');
define('MF_TEST', 1);
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../include/validate.php';
require_once __DIR__ . '/../include/ai.php';
require_once __DIR__ . '/../include/url.php';
require_once __DIR__ . '/../include/smtp.php';
require_once __DIR__ . '/../include/mail.php';

$pass = 0; $fail = 0;
function ok($cond, string $what): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else { $fail++; echo "  FAIL  $what\n"; }
}

// fresh DB
@unlink(DB_FILE); db();

echo "== 1) validator ==\n";
$v1 = verify_email('john.doe@gmail.com', true, false);
ok($v1['result'] === 'valid', "gmail.com => valid (got {$v1['result']})");
ok($v1['score'] >= 50, "gmail score >= 50 (got {$v1['score']})");
$v2 = verify_email('nope@nodomain-zzz-123456.invalid', true, false);
ok($v2['result'] === 'invalid', "bad TLD => invalid (got {$v2['result']})");
$v3 = verify_email('hello@10minutemail.com', true, false);
ok($v3['result'] === 'risky' || !empty($v3['checks']['disposable']), "disposable domain flagged (got {$v3['result']})");
$v4 = verify_email('not-an-email', true, false);
ok($v4['result'] === 'invalid', "garbage => invalid (got {$v4['result']})");
$v5 = verify_email('info@somecorp.com', true, false);
ok(($v5['checks']['role'] ?? '') !== '', 'role address detected (got ' . json_encode($v5['checks']['role'] ?? '') . ')');

echo "== 2) composer uniqueness + link injection ==\n";
$recips = parse_recipients("alice@corp.com|Alice|Acme\nbob.smith@corp2.com\n|Carol\n" . "dave@corp3.com");
ok(count($recips) === 4, "parse_recipients -> 4 (got " . count($recips) . ")");
$camp = ['id' => 99, 'subject_base' => 'Quick question about your account', 'body_base' => "Hi {{first_name}}, I have something that might be useful to you. {{link}}", 'tone' => 'neutral', 'personalization' => 1];
$bodies = []; $subjects = []; $linksOk = true;
$fakeLink = 'https://example.test/go.php?token=' . str_repeat('a', 28);
foreach ($recips as $rec) {
    [$s, $b] = compose_for($camp, $rec, $fakeLink);
    $subjects[] = $s; $bodies[] = $b;
    if (!str_contains($b, $fakeLink)) $linksOk = false;
}
ok($linksOk, 'every body contains the exact link');
ok(count(array_unique($bodies)) === 4, '4 unique bodies (got ' . count(array_unique($bodies)) . ')');
ok(count(array_unique($subjects)) >= 3, 'subjects vary (unique=' . count(array_unique($subjects)) . ')');
ok(str_contains($bodies[0], 'Alice'), "personalized with first name");

echo "== 3) smtp_send vs fake server ==\n";
// spawn the fake SMTP server (single-process loop) for the rest of the test
$fake = proc_open([PHP_BINARY, __DIR__ . '/fake_smtp.php', '2525', 'user1', 'pass1'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $fakePipes);
register_shutdown_function(function () use ($fake, &$fakePipes) {
    if (is_resource($fake)) { proc_terminate($fake); proc_close($fake); }
    foreach ($fakePipes as $p) if (is_resource($p)) fclose($p);
});
$up = false;
for ($i = 0; $i < 50; $i++) {
    $t = @stream_socket_client('tcp://127.0.0.1:2525', $e, $s, 1);
    if ($t) { fclose($t); $up = true; break; }
    usleep(100000);
}
ok($up, 'fake SMTP server is up');
$acc = [
    'id' => 1, 'name' => 'fake', 'host' => '127.0.0.1', 'port' => 2525,
    'username' => 'user1', 'password' => 'pass1', 'from_addr' => 'sender@example.com',
    'from_name' => 'Sender One', 'use_tls' => 0, 'daily_quota' => 100, 'active' => 1, 'last_used' => 0, 'last_error' => '',
];
$r = smtp_send($acc, [
    'from' => 'sender@example.com', 'from_name' => 'Sender One',
    'to' => 'alice@corp.com', 'subject' => 'Test subject ünïcode',
    'body' => "line1\nline2", 'body_type' => 'text', 'headers' => ['Reply-To' => 'reply@example.com'],
]);
ok($r['ok'], "smtp_send ok (err: " . $r['error'] . ")");
ok($r['msgid'] !== '', 'msgid assigned: ' . $r['msgid']);

echo "== 3b) smtp_send failure path (auth fail) ==\n";
$badAcc = $acc; $badAcc['password'] = 'wrong';
$r2 = smtp_send($badAcc, ['from' => 'sender@example.com', 'to' => 'a@b.com', 'subject' => 'x', 'body' => 'y']);
ok(!$r2['ok'], "bad password fails (err: " . $r2['error'] . ")");

echo "== 4) url tokens ==\n";
url_store('deadbeefdeadbeef', 'https://target.example/landing', 7, 3);
$row = url_lookup('deadbeefdeadbeef');
ok($row && $row['target'] === 'https://target.example/landing', 'url store + lookup roundtrip');
cfg_set(['app_base' => 'https://mf.example.com']);
$pub = url_public('deadbeefdeadbeef');
ok($pub === 'https://mf.example.com/go.php?token=deadbeefdeadbeef', "url_public format ($pub)");
cfg_set(['app_base' => '']);
$campUrl = ['id' => 7, 'url_mode' => 'per_batch', 'url_target' => 'https://t.example/x', 'url_batch_size' => 2];
$toks = url_build_tokens($campUrl, 5);
ok(count($toks) === 3, "per_batch size 2 -> 3 tokens for 5 recips (got " . count($toks) . ")");
ok(url_token_for($campUrl, 0, $toks) === url_token_for($campUrl, 1, $toks), 'recips 0-1 share batch 0');
ok(url_token_for($campUrl, 2, $toks) !== url_token_for($campUrl, 0, $toks), 'recip 2 gets a different token');

echo "== 5) run_campaign end-to-end ==\n";
// two real accounts in the pool pointing at the fake server (same creds —
// the fake server accepts one pair; rotation is about account selection)
foreach ([[1, 'user1', 'pass1', 's1@example.com'], [2, 'user1', 'pass1', 's2@example.com']] as $i => $a) {
    db()->prepare('INSERT INTO smtp_accounts (name, host, port, username, password, from_addr, from_name, use_tls, daily_quota, active) VALUES (?,?,?,?,?,?,?,0,50,1)')
        ->execute(["acct" . ($i+1), '127.0.0.1', 2525, $a[1], $a[2], $a[3], 'Sender ' . ($i+1)]);
}
$cid = campaign_create([
    'name' => 'e2e', 'recipients' => "alice@corp.com|Alice\nbob.smith@corp2.com|Bob\ncarol.doe@corp3.com|Carol",
    'subject_base' => 'Your update', 'body_base' => "Here is your update. {{link}}",
    'tone' => 'friendly', 'personalization' => 1,
    'url_mode' => 'per_recipient', 'url_target' => 'https://target.example/land', 'url_batch_size' => 1,
    'smtp_mode' => 'rotate', 'smtp_account_id' => null, 'status' => 'draft',
]);
ok($cid > 0, "campaign created (id $cid)");
$summary = run_campaign($cid, ['verify' => false]);
ok($summary['sent'] === 3 && $summary['failed'] === 0, "3 sent, 0 failed (got sent={$summary['sent']} failed={$summary['failed']} " . ($summary['results'][0]['reason'] ?? '') . ")");
// rotation: with 3 sends + in-request alternation, both accounts should be used
$logRows = db()->query('SELECT smtp_account_id, recipient, link, status FROM send_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
ok(count($logRows) === 3, '3 send_log rows');
ok(count(array_unique(array_map(fn($r) => $r['smtp_account_id'], $logRows))) === 2, 'both SMTP accounts rotated');
ok(count(array_unique(array_map(fn($r) => $r['link'], $logRows))) === 3, '3 unique rotating links');
// each recipient's log row has their link; check link hits via url_tokens
$hits = db()->query('SELECT COUNT(*) n FROM url_tokens WHERE campaign_id = ' . $cid)->fetchColumn();
ok((int)$hits === 3, "3 url tokens persisted for campaign (got $hits)");
// campaign status
$c = campaign_get($cid);
ok($c['status'] === 'done', "campaign status done (got {$c['status']})");
// daily counter bumped (1 from the standalone send in section 3 + 3 campaign)
ok(smtp_sent_today(1) + smtp_sent_today(2) === 4, "daily send counters total 4 (got " . (smtp_sent_today(1) + smtp_sent_today(2)) . ")");

echo "== 6) verify-in-campaign skip path ==\n";
$cid2 = campaign_create([
    'name' => 'e2e2', 'recipients' => "bad@nodomain-zzz-98765.invalid\nalice@corp.com",
    'subject_base' => 'x', 'body_base' => 'y {{link}}', 'tone' => 'neutral', 'personalization' => 1,
    'url_mode' => 'single', 'url_target' => 'https://t.example', 'url_batch_size' => 1,
    'smtp_mode' => 'rotate', 'smtp_account_id' => null, 'status' => 'draft',
]);
$s2 = run_campaign($cid2, ['verify' => true]);
ok($s2['skipped'] === 1 && $s2['sent'] === 1, "invalid skipped, valid sent (sent={$s2['sent']} skipped={$s2['skipped']})");

echo "\n=============================\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
