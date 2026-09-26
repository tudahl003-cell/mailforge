<?php
// BOLT ELITE REDIRECT - JSON API. Auth: X-Admin-Token header or ?token=.

function be_api(string $sub): void {
    $sub = trim($sub, '/');
    $in = json_in();
    header('X-Robots-Tag: noindex, nofollow');

    if ($sub === 'ping') {
        json_out(['ok' => true, 'authed' => is_authed(), 'ai' => be_ai_available(),
                  'configured' => effective_admin_token() !== '', 'version' => '1.0.0']);
    }

    require_auth();

    switch ($sub) {
        case 'templates':
            $out = [];
            foreach (be_tpl_presets() as $id => $d) {
                $out[] = ['id' => $id, 'name' => $d[0], 'family' => $d[1], 'icon' => $d[2],
                          'accent' => $d[3], 'kicker' => $d[4], 'h1' => $d[5], 'sub' => $d[6],
                          'body' => $d[7], 'cta' => $d[8]];
            }
            json_out(['ok' => true, 'templates' => $out]);

        case 'tokens':
            json_out(['ok' => true, 'tokens' => be_token_list(), 'base' => be_base_url()]);

        case 'token':
            $id = req_str($in, 'id');
            if ($id === '') be_err('id required');
            json_out(['ok' => true, 'token' => be_token_get($id)]);

        case 'tokens/save':
            $id = req_str($in, 'id');
            $f = $in;
            if ($id !== '') {
                $t = be_token_update($id, $f);
                json_out(['ok' => true, 'token' => $t, 'created' => false]);
            }
            $t = be_token_create($f);
            json_out(['ok' => true, 'token' => $t, 'created' => true]);

        case 'tokens/delete':
            $id = req_str($in, 'id', (string)($_GET['id'] ?? ''));
            if ($id === '') be_err('id required');
            be_token_delete($id);
            json_out(['ok' => true]);

        case 'tokens/pause':
        case 'tokens/resume':
            $id = req_str($in, 'id', (string)($_GET['id'] ?? ''));
            if ($id === '') be_err('id required');
            $t = be_token_update($id, ['status' => $sub === 'tokens/pause' ? 'paused' : 'active']);
            json_out(['ok' => true, 'token' => $t]);

        case 'stats':
            json_out(['ok' => true] + be_stats());

        case 'hits':
            $tid = req_str($in, 'token_id', (string)($_GET['token_id'] ?? ''));
            $lim = max(1, min(500, req_int($in, 'limit', (int)($_GET['limit'] ?? 100))));
            $db = be_db();
            if ($tid !== '') {
                $rows = be_q("SELECT * FROM hits WHERE token_id=? ORDER BY id DESC LIMIT " . $lim, [$tid]);
            } else {
                $rows = $db->query("SELECT * FROM hits ORDER BY id DESC LIMIT " . $lim)->fetchAll(ASSOC);
            }
            json_out(['ok' => true, 'hits' => $rows]);

        case 'hits/clear':
            $tid = req_str($in, 'token_id', (string)($_GET['token_id'] ?? ''));
            $db = be_db();
            if ($tid !== '') $db->prepare("DELETE FROM hits WHERE token_id=?")->execute([$tid]);
            else $db->exec("DELETE FROM hits");
            json_out(['ok' => true]);

        case 'ai/build':
            $r = be_ai_build($in);
            json_out(['ok' => true, 'result' => $r]);

        case 'settings':
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $patch = [];
                foreach (['tg_bot_token', 'tg_chat_id', 'admin_token', 'llm_key', 'llm_model', 'llm_base'] as $k) {
                    if (array_key_exists($k, $in)) $patch[$k] = (string)$in[$k];
                }
                if ($patch) cfg_set($patch);
                json_out(['ok' => true, 'settings' => be_settings_out()]);
            }
            json_out(['ok' => true, 'settings' => be_settings_out()]);

        case 'alert/test':
            $sent = be_tg("Bolt Elite test alert - if you can read this, alerts are wired up.");
            json_out(['ok' => true, 'sent' => $sent]);

        default:
            be_err('unknown endpoint: ' . $sub, 404);
    }
}

function be_settings_out(): array {
    $bot  = TG_BOT_TOKEN !== '' ? TG_BOT_TOKEN : (string)cfg_get('tg_bot_token', '');
    $chat = TG_CHAT_ID !== '' ? TG_CHAT_ID : (string)cfg_get('tg_chat_id', '');
    $mask = fn($s) => $s === '' ? '' : (substr($s, 0, 4) . str_repeat('*', max(0, strlen($s) - 8)) . substr($s, -4));
    $key  = (string)cfg_get('llm_key', '');
    return [
        'tg_configured' => $bot !== '' && $chat !== '',
        'tg_bot_token'  => $mask($bot),
        'tg_chat_id'    => $chat,
        'tg_source'     => TG_BOT_TOKEN !== '' ? 'env' : ($bot !== '' ? 'settings' : ''),
        'admin_token'   => (string)cfg_get('admin_token', '') !== '' ? 'set' : 'env',
        'llm_model'     => (string)cfg_get('llm_model', LLM_MODEL),
        'llm_base'      => (string)cfg_get('llm_base', LLM_BASE_URL),
        'llm_key'       => $key !== '' ? 'set' : (LLM_API_KEY !== '' ? 'env' : ''),
        'ai_available'  => ($key !== '' || LLM_API_KEY !== ''),
    ];
}

function be_stats(): array {
    $db = be_db();
    $q1 = fn(string $sql) => (int)($db->query($sql)->fetch(ASSOC)['c'] ?? 0);

    $day = 86400;
    $totals = [
        'tokens'      => $q1("SELECT COUNT(*) c FROM tokens"),
        'active'      => $q1("SELECT COUNT(*) c FROM tokens WHERE status='active'"),
        'hits'        => $q1("SELECT COUNT(*) c FROM hits"),
        'hits_24h'    => $q1("SELECT COUNT(*) c FROM hits WHERE ts > " . (time() - $day)),
        'hits_today'  => $q1("SELECT COUNT(*) c FROM hits WHERE ts > " . strtotime('today')),
        'bots'        => $q1("SELECT COUNT(*) c FROM hits WHERE is_bot=1"),
        'countries'   => $q1("SELECT COUNT(DISTINCT country_code) c FROM hits WHERE country_code != ''"),
        'visitors'    => (int)($db->query("SELECT COUNT(DISTINCT ip) c FROM hits")->fetch(ASSOC)['c'] ?? 0),
    ];

    $by_day = [];
    for ($i = 13; $i >= 0; $i--) {
        $start = strtotime('today') - $i * $day;
        $end = $start + $day;
        $c = (int)($db->query("SELECT COUNT(*) c FROM hits WHERE ts >= $start AND ts < $end")->fetch(ASSOC)['c'] ?? 0);
        $by_day[] = ['date' => date('Y-m-d', $start), 'hits' => $c];
    }

    $grp = function (string $col) use ($db) {
        $rows = $db->query("SELECT " . $col . " k, COUNT(*) c FROM hits WHERE " . $col . " != '' GROUP BY " . $col . " ORDER BY c DESC LIMIT 8")->fetchAll(ASSOC);
        return array_map(fn($r) => ['k' => $r['k'], 'c' => (int)$r['c']], $rows);
    };

    $top_tokens = [];
    $rows = $db->query("SELECT id,name,slug,hits,uniq,status,target FROM tokens ORDER BY hits DESC LIMIT 10")->fetchAll(ASSOC);
    foreach ($rows as $r) $top_tokens[] = $r;

    $recent = $db->query("SELECT * FROM hits ORDER BY id DESC LIMIT 15")->fetchAll(ASSOC);

    return [
        'totals'     => $totals,
        'by_day'     => $by_day,
        'countries'  => $grp('country'),
        'cc'         => $grp('country_code'),
        'devices'    => $grp('device'),
        'os'         => $grp('os'),
        'browsers'   => $grp('browser'),
        'isps'       => $grp('isp'),
        'refs'       => $grp('referrer'),
        'top_tokens' => $top_tokens,
        'recent'     => $recent,
    ];
}
