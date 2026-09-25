<?php
// api/send.php — run a campaign.
//   POST {id, verify, min_score, jitter_min, jitter_max, max_send, unsubscribe, background}
//   GET  ?id=N → status of a background run (from worker result file / campaign status)
require_once __DIR__ . '/../../lib.php';
require_once __DIR__ . '/../../include/mail.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $id = req_int($_GET, 'id');
    if (!campaign_get($id)) json_out(['ok' => false, 'error' => 'campaign not found'], 404);
    $resFile = DATA_DIR . '/results/' . $id . '.json';
    $res = is_array(@json_decode((string)@file_get_contents($resFile), true)) ? @json_decode((string)@file_get_contents($resFile), true) : null;
    $jobFile = DATA_DIR . '/jobs/' . $id . '.json';
    $running = campaign_get($id)['status'] === 'sending';
    json_out(['ok' => true, 'id' => $id,
              'queued' => file_exists($jobFile) && !$res,
              'running' => $running,
              'result' => $res['summary'] ?? null]);
}

if ($method !== 'POST') json_out(['ok' => false, 'error' => 'GET or POST only'], 405);

$in = json_in();
$id = req_int($in, 'id');
$camp = campaign_get($id);
if (!$camp) json_out(['ok' => false, 'error' => 'campaign not found'], 404);
if ($camp['status'] === 'sending') json_out(['ok' => false, 'error' => 'already sending'], 409);

$opts = [
    'verify'      => !empty($in['verify']),
    'min_score'   => req_int($in, 'min_score', 0),
    'jitter_min'  => max(0, (float)($in['jitter_min'] ?? 0)),
    'jitter_max'  => max(0, (float)($in['jitter_max'] ?? 0)),
    'max_send'    => req_int($in, 'max_send', 0),
    'unsubscribe' => !empty($in['unsubscribe']),
];

// Background mode: enqueue for the worker (durable, survives big batches,
// no Apache timeout). Poll GET /api/send.php?id=N.
if (!empty($in['background'])) {
    campaign_save(['id' => $id, 'status' => 'sending']);
    @unlink(DATA_DIR . '/results/' . $id . '.json');
    @mkdir(DATA_DIR . '/jobs', 0775, true);
    @file_put_contents(DATA_DIR . '/jobs/' . $id . '.json',
        json_encode(['id' => $id, 'opts' => $opts]), LOCK_EX);
    json_out(['ok' => true, 'background' => true, 'id' => $id,
              'note' => 'queued — poll GET /api/send.php?id=' . $id]);
}

// Synchronous: run the whole batch in this request.
set_time_limit(3600);
$summary = run_campaign($id, $opts);
json_out($summary);
