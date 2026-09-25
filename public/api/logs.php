<?php
// api/logs.php — send_log + verify_log + url hits
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/url.php';
require_auth();
$in = json_in();
$type = req_str($in, 'type', 'send');
$camp = req_int($in, 'campaign', 0);
$limit = min(1000, max(1, req_int($in, 'limit', 200)));
$offset = max(0, req_int($in, 'offset', 0));

if ($type === 'send') {
    $w = []; $p = [];
    if ($camp) { $w[] = 'campaign_id = ?'; $p[] = $camp; }
    $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
    $total = (int)db()->query('SELECT COUNT(*) FROM send_log' . $where)->fetchColumn();
    $q = 'SELECT * FROM send_log' . $where . ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
    $st = db()->prepare($q); $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'type' => 'send', 'total' => $total, 'rows' => $rows]);
}

if ($type === 'verify') {
    $q = 'SELECT id, email, result, detail, created_at FROM verify_log ORDER BY id DESC LIMIT ' . $limit;
    $rows = db()->query($q)->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'type' => 'verify', 'rows' => $rows]);
}

if ($type === 'urls') {
    $q = 'SELECT token, target, campaign_id, batch, hits, created_at FROM url_tokens';
    $p = [];
    if ($camp) { $q .= ' WHERE campaign_id = ?'; $p[] = $camp; }
    $st = db()->prepare($q . ' ORDER BY created_at DESC, token DESC LIMIT ' . $limit);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    json_out(['ok' => true, 'type' => 'urls', 'rows' => $rows, 'base' => url_base()]);
}

json_out(['ok' => false, 'error' => 'unknown type'], 400);
