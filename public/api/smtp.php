<?php
// api/smtp.php — SMTP account pool CRUD + test + DKIM helpers
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/smtp.php';
require_once __DIR__ . '/../../include/dkim.php';
require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$in = json_in();

if ($method === 'GET') {
    json_out(['ok' => true, 'accounts' => smtp_list_accounts(),
              'profiles' => array_map(fn($p) => $p['label'], smtp_client_profiles()),
              'tls_modes' => ['auto' => 'Auto (STARTTLS when offered)', 'starttls' => 'Require STARTTLS',
                              'implicit' => 'Implicit TLS (port 465)', 'none' => 'No TLS']]);
}

if ($method === 'POST') {
    // POST ?test=1 → connectivity test for one account
    if (!empty($in['test'])) {
        $acc = smtp_get_account(req_int($in, 'id'));
        if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
        json_out(smtp_test_account($acc));
    }

    // POST ?dkim_generate=1 → mint a keypair for an account and return the DNS record
    if (!empty($in['dkim_generate'])) {
        $id = req_int($in, 'id');
        $acc = smtp_get_account($id);
        if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
        $domain = trim(req_str($in, 'dkim_domain', (string)($acc['dkim_domain'] ?? '')));
        $sel = trim(req_str($in, 'dkim_selector', (string)($acc['dkim_selector'] ?? 'mailforge')));
        if ($domain === '' || $sel === '') json_out(['ok' => false, 'error' => 'dkim_domain and dkim_selector required'], 400);
        $kp = dkim_generate_keypair(2048);
        if (empty($kp['ok'])) json_out(['ok' => false, 'error' => $kp['error'] ?? 'keygen failed'], 500);
        db()->prepare('UPDATE smtp_accounts SET dkim_domain = ?, dkim_selector = ?, dkim_private_key = ? WHERE id = ?')
            ->execute([$domain, $sel, $kp['private'], $id]);
        $rec = dkim_dns_record($sel, $domain, $kp['private']);
        json_out(['ok' => true, 'dns' => $rec, 'note' => 'Publish this TXT record, then send a test to a Gmail account.']);
    }

    // POST ?dkim_dns=1 → DNS record for the account's existing key
    if (!empty($in['dkim_dns'])) {
        $acc = smtp_get_account(req_int($in, 'id'));
        if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
        $key = dkim_normalise_key((string)($acc['dkim_private_key'] ?? ''));
        if ($key === '') json_out(['ok' => false, 'error' => 'no DKIM key on this account'], 400);
        json_out(['ok' => true, 'dns' => dkim_dns_record((string)$acc['dkim_selector'], (string)$acc['dkim_domain'], $key)]);
    }

    $name = trim(req_str($in, 'name'));
    $host = trim(req_str($in, 'host'));
    $port = req_int($in, 'port', 587);
    $user = req_str($in, 'username');
    $pass = (string)($in['password'] ?? '');
    $from = trim(req_str($in, 'from_addr', $user !== '' ? $user . '@' . $host : ''));
    if ($host === '' || $from === '') json_out(['ok' => false, 'error' => 'host and from_addr required'], 400);

    db()->prepare('INSERT INTO smtp_accounts
        (name, host, port, username, password, from_addr, from_name, use_tls, daily_quota, active,
         client_profile, tls_mode, envelope_from, reply_to, dkim_domain, dkim_selector, dkim_private_key, failover)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $host, $port, $user, $pass, $from,
                   trim(req_str($in, 'from_name')), req_int($in, 'use_tls', 1),
                   max(0, req_int($in, 'daily_quota', 500)), req_int($in, 'active', 1),
                   req_str($in, 'client_profile', 'outlook'), req_str($in, 'tls_mode', 'auto'),
                   trim(req_str($in, 'envelope_from')), trim(req_str($in, 'reply_to')),
                   trim(req_str($in, 'dkim_domain')), trim(req_str($in, 'dkim_selector')),
                   (string)($in['dkim_private_key'] ?? ''), req_int($in, 'failover', 1)]);
    $id = (int)db()->lastInsertId();
    json_out(['ok' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $id = req_int($in, 'id');
    $acc = smtp_get_account($id);
    if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
    $strCols = ['name', 'host', 'username', 'from_addr', 'from_name', 'client_profile',
                'tls_mode', 'envelope_from', 'reply_to', 'dkim_domain', 'dkim_selector'];
    $intCols = ['port', 'use_tls', 'daily_quota', 'active', 'failover'];
    $sets = []; $vals = [];
    foreach ($strCols as $col) {
        if (!array_key_exists($col, $in)) continue;
        $sets[] = "$col = ?"; $vals[] = (string)$in[$col];
    }
    foreach ($intCols as $col) {
        if (!array_key_exists($col, $in)) continue;
        $sets[] = "$col = ?"; $vals[] = (int)$in[$col];
    }
    if (array_key_exists('password', $in) && $in['password'] !== '' && !str_starts_with((string)$in['password'], '•')) {
        $sets[] = 'password = ?'; $vals[] = (string)$in['password'];
    }
    // A pasted private key replaces the stored one; empty keeps it.
    if (array_key_exists('dkim_private_key', $in) && trim((string)$in['dkim_private_key']) !== ''
        && !str_starts_with((string)$in['dkim_private_key'], '•')) {
        $sets[] = 'dkim_private_key = ?'; $vals[] = dkim_normalise_key((string)$in['dkim_private_key']);
    }
    if ($sets) {
        $vals[] = $id;
        db()->prepare('UPDATE smtp_accounts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = req_int($in, 'id');
    db()->prepare('DELETE FROM smtp_accounts WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'bad method'], 405);
