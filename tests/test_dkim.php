<?php
// Independent DKIM round-trip check: sign, then re-derive the signing input
// from the produced header and verify with the public key.
require __DIR__ . '/../include/dkim.php';

$kp = dkim_generate_keypair(2048);
if (!$kp['ok']) { echo "keygen failed\n"; exit(1); }

$headers = [
    'From'         => 'Acme Billing <billing@acme-example.com>',
    'To'           => '<someone@gmail.com>',
    'Subject'      => '=?UTF-8?B?' . base64_encode('Your invoice is ready') . '?=',
    'Date'         => date('r'),
    'Message-ID'   => '<abc123def456@acme-example.com>',
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => 'base64',
];
$body = "Hello there,\r\n\r\nYour invoice is ready.   \r\n\r\nRegards\r\n\r\n";

$r = dkim_sign($headers, $body, 'acme-example.com', 'mf2026', $kp['private']);
if (!$r['ok']) { echo "sign failed: {$r['error']}\n"; exit(1); }
$header = trim($r['header']);

echo "signed header length: " . strlen($header) . "\n";
foreach (['v=1', 'a=rsa-sha256', 'c=relaxed/relaxed', 'd=acme-example.com', 's=mf2026', 'bh=', 'b='] as $needle) {
    echo (strpos($header, $needle) !== false ? "  ok   " : "  FAIL ") . "contains $needle\n";
}

// --- independent verification ---
$value = trim(substr($header, strlen('DKIM-Signature:')));
// Parse tags manually: parse_str() URL-decodes values and would turn '+' in
// the base64 into spaces, corrupting bh/b.
$tags = [];
foreach (explode(';', $value) as $part) {
    $part = trim($part);
    if ($part === '') continue;
    $eq = strpos($part, '=');
    if ($eq === false) continue;
    $tags[trim(substr($part, 0, $eq))] = substr($part, $eq + 1);
}
$hList = explode(':', $tags['h'] ?? '');
$bSig  = (string)($tags['b'] ?? '');

$lower = [];
foreach ($headers as $k => $v) $lower[strtolower($k)] = (string)$v;

$input = '';
foreach ($hList as $h) {
    if (!isset($lower[$h])) { echo "  FAIL signed header missing: $h\n"; exit(1); }
    $input .= dkim_relax_header($h, $lower[$h]) . "\r\n";
}
// Rebuild the signature header with an empty b= (what the signer signed).
$bPos = strrpos($value, 'b=');
if ($bPos === false) { echo "  FAIL no b= tag in signature header\n"; exit(1); }
$emptyB = substr($value, 0, $bPos) . 'b=';
if (substr($emptyB, -2) !== 'b=') { echo "  FAIL could not blank b= (hex=[" . bin2hex(substr($emptyB, -4)) . "])\n"; exit(1); }
$input .= dkim_relax_header('dkim-signature', $emptyB);

$pub = openssl_pkey_get_public($kp['public']);
$ok  = openssl_verify($input, base64_decode($bSig), $pub, OPENSSL_ALGO_SHA256);
echo ($ok === 1 ? "  ok   " : "  FAIL ") . "signature verifies against the public key (result=$ok)\n";

// --- body hash check ---
$bh = base64_encode(hash('sha256', dkim_relax_body($body), true));
echo (($tags['bh'] ?? '') === $bh ? "  ok   " : "  FAIL ") . "body hash matches canonicalised body\n";

// --- tamper detection ---
// Flip a signed header (From domain) - verification must fail.
$tampered = str_replace('billing@acme-example.com', 'billing@evil-example.com', $input);
if ($tampered === $input) { echo "  FAIL tamper test did not modify the signed input\n"; exit(1); }
$bad = openssl_verify($tampered, base64_decode($bSig), $pub, OPENSSL_ALGO_SHA256);
echo ($bad === 0 ? "  ok   " : "  FAIL ") . "tampered header fails verification (result=$bad)\n";

// A different body must not match the recorded bh.
$otherBh = base64_encode(hash('sha256', dkim_relax_body("Different body\r\n"), true));
echo ($otherBh !== ($tags['bh'] ?? '') ? "  ok   " : "  FAIL ") . "different body produces a different bh\n";

// --- normalisation helpers ---
echo (dkim_normalise_key($kp['private']) !== '' ? "  ok   " : "  FAIL ") . "PEM key accepted\n";
$rec = dkim_dns_record('mf2026', 'acme-example.com', $kp['private']);
echo (($rec['ok'] && $rec['name'] === 'mf2026._domainkey.acme-example.com' && strpos($rec['value'], 'v=DKIM1') === 0)
      ? "  ok   " : "  FAIL ") . "DNS record: " . ($rec['name'] ?? '?') . "\n";
echo (strpos($rec['value'], 'PRIVATE') === false ? "  ok   " : "  FAIL ") . "DNS record contains no private key material\n";
