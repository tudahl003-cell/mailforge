<?php
// api/campaign.php — campaign CRUD
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/mail.php';
require_auth();
$method = $_SERVER['REQUEST_METHOD'];
$in = json_in();

if ($method === 'GET') {
    $oneId = req_int($_GET, 'id', 0);
    if ($oneId) {
        $c = campaign_get($oneId);
        if (!$c) json_out(['ok' => false, 'error' => 'not found'], 404);
        $c['recipient_count'] = count(parse_recipients((string)$c['recipients']));
        json_out(['ok' => true, 'campaign' => $c]);
    }
    $rows = db()->query('SELECT id,name,recipients,subject_base,body_base,tone,url_mode,url_target,url_batch_size,smtp_mode,smtp_account_id,status,created_at FROM campaigns ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)$r['recipients'])), fn($l) => $l !== '' && !str_starts_with(trim($l), '#'));
        $r['recipient_count'] = count($lines);
        unset($r['recipients'], $r['body_base']);  // heavy fields on list view
    }
    json_out(['ok' => true, 'campaigns' => $rows]);
}

if ($method === 'POST') {
    $id = campaign_create([
        'name' => trim(req_str($in, 'name', 'campaign')),
        'recipients' => req_str($in, 'recipients'),
        'subject_base' => req_str($in, 'subject_base'),
        'body_base' => req_str($in, 'body_base'),
        'tone' => in_array(req_str($in, 'tone', 'neutral'), ['neutral','friendly','formal','upbeat'], true) ? req_str($in, 'tone', 'neutral') : 'neutral',
        'personalization' => req_int($in, 'personalization', 1),
        'url_mode' => in_array(req_str($in, 'url_mode', 'per_recipient'), ['per_recipient','per_batch','single'], true) ? req_str($in, 'url_mode', 'per_recipient') : 'per_recipient',
        'url_target' => req_str($in, 'url_target'),
        'url_batch_size' => max(1, req_int($in, 'url_batch_size', 1)),
        'smtp_mode' => in_array(req_str($in, 'smtp_mode', 'rotate'), ['rotate','specific'], true) ? req_str($in, 'smtp_mode', 'rotate') : 'rotate',
        'smtp_account_id' => req_int($in, 'smtp_account_id'),
        'status' => 'draft',
    ]);
    json_out(['ok' => true, 'id' => $id]);
}

if ($method === 'PUT') {
    $id = req_int($in, 'id');
    if (!campaign_get($id)) json_out(['ok' => false, 'error' => 'not found'], 404);
    $allowed = ['name','recipients','subject_base','body_base','tone','personalization',
                'url_mode','url_target','url_batch_size','smtp_mode','smtp_account_id','status'];
    $patch = ['id' => $id];
    foreach ($allowed as $k) if (array_key_exists($k, $in)) $patch[$k] = $in[$k];
    campaign_save($patch);
    json_out(['ok' => true]);
}

if ($method === 'DELETE') {
    $id = req_int($in, 'id');
    db()->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$id]);
    db()->prepare('DELETE FROM url_tokens WHERE campaign_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM send_log WHERE campaign_id = ?')->execute([$id]);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => 'bad method'], 405);
