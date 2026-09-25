<?php
// api/smtp.php — SMTP account pool CRUD + test
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/smtp.php';
require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$in = json_in();

if ($method === 'GET') {
    json_out(['ok' => true, 'accounts' => smtp_list_accounts()]);
}

if ($method === 'POST') {
    // POST ?test=1 → connectivity test for one account
    if (!empty($in['test'])) {
        $acc = smtp_get_account(req_int($in, 'id'));
        if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
        json_out(smtp_test_account($acc));
    }
    $name = trim(req_str($in, 'name'));
    $host = trim(req_str($in, 'host'));
    $port = req_int($in, 'port', 587);
    $user = req_str($in, 'username');
    $pass = (string)($in['password'] ?? '');
    $from = trim(req_str($in, 'from_addr', $user !== '' ? $user . '@' . $host : ''));
    if ($host === '' || $from === '') json_out(['ok' => false, 'error' => 'host and from_addr required'], 400);
    db()->prepare('INSERT INTO smtp_accounts (name, host, port, username, password, from_addr, from_name, use_tls, daily_quota, active) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $host, $port, $user, $pass, $from,
                   trim(req_str($in, 'from_name')), req_int($in, 'use_tls', 1),
                   max(0, req_int($in, 'daily_quota', 500)), req_int($in, 'active', 1)]);
    $id = (int)db()->lastInsertId();
    json_out(['ok' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $id = req_int($in, 'id');
    $acc = smtp_get_account($id);
    if (!$acc) json_out(['ok' => false, 'error' => 'not found'], 404);
    $map = ['name' => 'name', 'host' => 'host', 'port' => 'port', 'username' => 'username',
            'from_addr' => 'from_addr', 'from_name' => 'from_name', 'use_tls' => 'use_tls',
            'daily_quota' => 'daily_quota', 'active' => 'active'];
    $sets = []; $vals = [];
    foreach ($map as $k => $col) {
        if (!array_key_exists($k, $in)) continue;
        $sets[] = "$col = ?"; $vals[] = (int)$in[$k] === 0 && !is_int($in[$k]) && in_array($col, ['port','use_tls','daily_quota','active']) ? (int)$in[$k] : $in[$k];
    }
    if (array_key_exists('password', $in) && $in['password'] !== '' && !str_starts_with($in['password'], '•')) {
        $sets[] = 'password = ?'; $vals[] = (string)$in['password'];
    }
    if ($sets) {
        $vals[] = $id;
        db()->prepare('UPDATE smtp_accounts SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
    }
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = req_int($in, 'id');
    db()->prepare('DELETE FROM smtp_accounts WHERE id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'bad method'], 405);
