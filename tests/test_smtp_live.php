<?php
// mailforge - live SMTP tests against a sink.
// Usage: php tests/test_smtp_live.php <sink_port> <sink_jsonl>

$port = (int)($argv[1] ?? 0);
$jsonl = (string)($argv[2] ?? '');
if (!$port || $jsonl === '') { fwrite(STDERR, "need sink port + jsonl path\n"); exit(2); }

putenv('MF_DATA_DIR=' . sys_get_temp_dir() . '/mf_live_' . bin2hex(random_bytes(4)));

require __DIR__ . '/../lib.php';
require __DIR__ . '/../include/smtp.php';
require __DIR__ . '/../include/dkim.php';
require __DIR__ . '/../include/mail.php';

$pass = 0; $fail = 0;
function ok(bool $c, string $label): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; }
}
function sink_read(string $f): array {
    $rows = [];
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
            $j = json_decode($l, true);
            if (is_array($j)) $rows[] = $j;
        }
    }
    return $rows;
}
function hdr(string $raw, string $name): string {
    if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.*)$/mi', $raw, $m)) return trim($m[1]);
    return '';
}

$kp = dkim_generate_keypair(2048);
ok(!empty($kp['ok']), 'test keypair generated');

// --- accounts: one dead (for failover), one pointing at the sink ---
$ins = db()->prepare("INSERT INTO smtp_accounts
  (name,host,port,username,password,from_addr,from_name,use_tls,daily_quota,active,last_used,
   dkim_selector,dkim_private_key,dkim_domain,envelope_from,reply_to,client_profile,tls_mode,failover)
  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$ins->execute(['dead', '127.0.0.1', 1, '', '', 'billing@acme-example.com', 'Acme Billing', 0, 0, 1, 0,
               '', '', '', '', '', 'outlook', 'none', 1]);
$deadId = (int)db()->lastInsertId();
$ins->execute(['sink', '127.0.0.1', $port, 'user@acme-example.com', 'pw',
               'billing@acme-example.com', 'Acme Billing', 0, 0, 1, 500,
               'mf2026', $kp['private'], 'acme-example.com', 'bounce@acme-example.com',
               'reply@acme-example.com', 'outlook', 'none', 1]);
$sinkId = (int)db()->lastInsertId();
ok($deadId && $sinkId, 'two accounts inserted');

// --- 1) direct send: structure, DKIM, envelope ---
$acc = smtp_get_account($sinkId);
$r = smtp_send($acc, [
    'to' => 'someone@gmail.com',
    'subject' => 'Invoice #4821 is ready',
    'body' => "Hello,\r\n\r\nYour invoice is ready.\r\n",
    'headers' => ['Reply-To' => 'reply@acme-example.com'],
]);
ok(!empty($r['ok']), 'direct send accepted by sink (' . ($r['error'] ?? '') . ')');

$rows = sink_read($jsonl);
ok(count($rows) === 1, 'sink captured 1 message (got ' . count($rows) . ')');
if ($rows) {
    $msg = $rows[0];
    $raw = $msg['data'];
    ok($msg['from'] === '<bounce@acme-example.com>', 'envelope MAIL FROM uses envelope_from (' . $msg['from'] . ')');
    ok(($msg['rcpt'][0] ?? '') === '<someone@gmail.com>', 'envelope RCPT TO correct');
    ok(hdr($raw, 'X-Mailer') === 'Microsoft Outlook 16.0', 'outlook profile X-Mailer present');
    ok(hdr($raw, 'DKIM-Signature') !== '', 'DKIM-Signature header added');
    ok(strpos(hdr($raw, 'DKIM-Signature'), 'd=acme-example.com') !== false, 'DKIM d= is the signing domain');
    ok(strpos(hdr($raw, 'DKIM-Signature'), 's=mf2026') !== false, 'DKIM s= selector');
    ok(strpos(hdr($raw, 'DKIM-Signature'), 'bh=') !== false, 'DKIM body hash present');
    ok(hdr($raw, 'Reply-To') === 'reply@acme-example.com', 'Reply-To honoured');
    ok(preg_match('/^<[0-9a-f]{24}@acme-example\.com>$/', hdr($raw, 'Message-ID')) === 1,
        'Message-ID uses the from domain (' . hdr($raw, 'Message-ID') . ')');
    ok(hdr($raw, 'MIME-Version') === '1.0', 'MIME-Version set');
    ok(strpos($raw, 'Content-Transfer-Encoding: base64') !== false, 'body encoded');
    // Order: DKIM right after From.
    $lines = explode("\n", $raw);
    $fromIdx = null; $dkimIdx = null;
    foreach ($lines as $i => $l) {
        if (stripos($l, 'From:') === 0) $fromIdx = $i;
        if (stripos($l, 'DKIM-Signature:') === 0) $dkimIdx = $i;
    }
    ok($dkimIdx !== null && $fromIdx !== null && $dkimIdx === $fromIdx + 1, 'DKIM-Signature sits directly after From');
}

// --- 2) profiles change the fingerprint ---
foreach ([['apple', 'Apple Mail'], ['gmail', 'Gmail'], ['none', '']] as [$prof, $needle]) {
    $acc2 = smtp_get_account($sinkId);
    $acc2['client_profile'] = $prof;
    smtp_send($acc2, ['to' => 'p@gmail.com', 'subject' => 'Profile check', 'body' => "x\r\n"]);
}
$rows = sink_read($jsonl);
$last3 = array_slice($rows, -3);
ok(count($last3) === 3, 'three profile messages captured');
if (count($last3) === 3) {
    ok(strpos(hdr($last3[0]['data'], 'X-Mailer'), 'Apple Mail') !== false, 'apple profile mailer');
    ok(strpos(hdr($last3[1]['data'], 'X-Mailer'), 'Gmail') !== false, 'gmail profile mailer');
    ok(hdr($last3[2]['data'], 'X-Mailer') === '', 'none profile omits X-Mailer');
    ok(preg_match('/^<[0-9A-Za-z+\/=]{20,}@acme-example\.com>$/', hdr($last3[1]['data'], 'Message-ID')) === 1,
        'gmail profile uses a base64-style Message-ID at the from domain');
    ok(strpos(smtp_make_msgid('b64', 'gmail.com'), '@mail.gmail.com>') !== false,
        'gmail from-domain yields a mail.gmail.com Message-ID host');
    ok(preg_match('/^<[0-9A-F-]{20,}@/', hdr($last3[0]['data'], 'Message-ID')) === 1,
        'apple profile uses a UUID-style Message-ID');
}

// --- 3) no DKIM configured -> no signature header ---
$acc3 = smtp_get_account($sinkId);
$acc3['dkim_domain'] = ''; $acc3['dkim_selector'] = ''; $acc3['dkim_private_key'] = '';
smtp_send($acc3, ['to' => 'n@gmail.com', 'subject' => 'No dkim', 'body' => "x\r\n"]);
$rows = sink_read($jsonl);
$last = end($rows);
ok(hdr($last['data'], 'DKIM-Signature') === '', 'no DKIM header when the account has no key');

// --- 4) campaign failover: least-recently-used is the dead account ---
db()->prepare('UPDATE smtp_accounts SET last_used = 0 WHERE id = ?')->execute([$deadId]);
db()->prepare('UPDATE smtp_accounts SET last_used = time() WHERE id = ?')->execute([$sinkId]);
$before = count(sink_read($jsonl));

db()->prepare("INSERT INTO campaigns (name,recipients,subject_base,body_base,tone,personalization,url_mode,url_target,url_batch_size,smtp_mode,status)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)")
   ->execute(['failover test', "one@gmail.com\ntwo@yahoo.com", 'Subject {{first_name}}',
              "Hi {{first_name}},\r\n\r\nLink: {{link}}\r\n", 'neutral', 1, 'single',
              'https://example.com/target', 1, 'rotate', 'draft']);
$campId = (int)db()->lastInsertId();

$res = run_campaign($campId, []);
ok(($res['sent'] ?? 0) === 2, 'campaign sent both recipients via failover (sent=' . ($res['sent'] ?? 0) . ')');
ok(count(sink_read($jsonl)) === $before + 2, 'sink received both messages after failover');
$first = $res['results'][0] ?? [];
ok(!empty($first['account']) && strpos((string)$first['account'], 'sink') !== false,
    'failover landed on the live account (' . ($first['account'] ?? '?') . ')');

echo "\n" . str_repeat('-', 46) . "\n";
echo "PASS: $pass   FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);