<?php
// mailforge - DKIM signing (RFC 6376, relaxed/relaxed, RSA-SHA256).
// DKIM is what lets a message survive spam filtering on Gmail/Outlook/Yahoo:
// it cryptographically proves the From domain authorised the send, and it is
// the only part of deliverability we control end to end (SPF is the relay's).

function dkim_available(): bool {
    return function_exists('openssl_sign') && function_exists('openssl_pkey_get_private');
}

function dkim_relax_body(string $body): string {
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    $out = [];
    foreach ($lines as $l) $out[] = rtrim($l, " \t");
    while ($out && end($out) === '') array_pop($out);
    if (!$out) return "\r\n";
    return implode("\r\n", $out) . "\r\n";
}

function dkim_relax_header(string $name, string $value): string {
    $v = str_replace(["\r", "\n"], '', $value);      // unfold
    $v = preg_replace('/[ \t]+/', ' ', $v);          // collapse WSP runs
    $v = trim($v);
    return strtolower(trim($name)) . ':' . $v;
}

/**
 * Sign a message. $headers is name => value (in wire order); $body is the raw
 * body (CRLF or LF). Returns ['ok'=>bool,'header'=>string,'error'=>string].
 * The returned header string is the full "DKIM-Signature: ...\r\n" line.
 */
function dkim_sign(array $headers, string $body, string $domain, string $selector, string $privKeyPem, ?array $signList = null): array {
    if (!dkim_available()) return ['ok' => false, 'header' => '', 'error' => 'openssl unavailable'];
    if ($domain === '' || $selector === '' || $privKeyPem === '') {
        return ['ok' => false, 'header' => '', 'error' => 'dkim domain/selector/key incomplete'];
    }

    // Case-insensitive lookup of the headers we intend to sign.
    $lower = [];
    foreach ($headers as $k => $v) $lower[strtolower((string)$k)] = (string)$v;

    if ($signList === null) {
        $signList = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'];
    }
    $signed = [];
    foreach ($signList as $h) {
        if (isset($lower[$h]) && $lower[$h] !== '') $signed[] = $h;
    }
    if (!in_array('from', $signed, true)) {
        return ['ok' => false, 'header' => '', 'error' => 'DKIM requires a From header'];
    }

    $key = @openssl_pkey_get_private($privKeyPem);
    if ($key === false) return ['ok' => false, 'header' => '', 'error' => 'invalid DKIM private key'];

    $bh = base64_encode(hash('sha256', dkim_relax_body($body), true));

    $sigValue = 'v=1; a=rsa-sha256; c=relaxed/relaxed;'
              . ' d=' . $domain . '; s=' . $selector . ';'
              . ' t=' . time() . ';'
              . ' h=' . implode(':', $signed) . ';'
              . ' bh=' . $bh . '; b=';

    $input = '';
    foreach ($signed as $h) $input .= dkim_relax_header($h, $lower[$h]) . "\r\n";
    // The signature header is last and, per RFC 6376, carries no trailing CRLF.
    $input .= dkim_relax_header('dkim-signature', $sigValue);

    $sig = '';
    if (!openssl_sign($input, $sig, $key, OPENSSL_ALGO_SHA256)) {
        return ['ok' => false, 'header' => '', 'error' => 'signing failed'];
    }
    $b = base64_encode($sig);
    $final = $sigValue . $b;

    return ['ok' => true, 'header' => 'DKIM-Signature: ' . $final . "\r\n", 'error' => ''];
}

/** Parse a PEM private key out of user input (tolerates \n literals). */
function dkim_normalise_key(string $pem): string {
    $pem = trim(str_replace(['\\n', '\r'], ["\n", ''], $pem));
    if ($pem === '') return '';
    if (strpos($pem, '-----BEGIN') === false) {
        // Bare base64 body -> wrap it as PKCS#8.
        $body = chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n");
        return "-----BEGIN PRIVATE KEY-----\n" . $body . "-----END PRIVATE KEY-----\n";
    }
    return $pem . "\n";
}

/** Derive the DNS TXT record an operator must publish for this key. */
function dkim_dns_record(string $selector, string $domain, string $privKeyPem): array {
    $key = @openssl_pkey_get_private($privKeyPem);
    if ($key === false) return ['ok' => false, 'error' => 'invalid private key'];
    $details = openssl_pkey_get_details($key);
    if (!$details || empty($details['key'])) return ['ok' => false, 'error' => 'cannot read key details'];
    $pub = preg_replace('/-----[^-]+-----/', '', (string)$details['key']);
    $pub = preg_replace('/\s+/', '', $pub);
    return [
        'ok'   => true,
        'name' => $selector . '._domainkey.' . $domain,
        'value'=> 'v=DKIM1; k=rsa; p=' . $pub,
    ];
}

/** Generate a fresh 2048-bit keypair for an account. */
function dkim_generate_keypair(int $bits = 2048): array {
    if (!dkim_available()) return ['ok' => false, 'error' => 'openssl unavailable'];
    $res = openssl_pkey_new([
        'private_key_bits' => $bits,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'digest_alg'       => 'sha256',
    ]);
    if ($res === false) return ['ok' => false, 'error' => 'key generation failed'];
    $priv = '';
    openssl_pkey_export($res, $priv);
    $details = openssl_pkey_get_details($res);
    return ['ok' => true, 'private' => $priv, 'public' => (string)($details['key'] ?? '')];
}
