<?php
// BOLT ELITE REDIRECT - public serving: resolve token, log intel, alert, forward.

function be_resolve_token(string $key, string $host): ?array {
    $db = be_db();
    $key = trim($key, '/');
    if ($key === '') return null;
    $key = urldecode($key);
    $slug = preg_replace('#[^A-Za-z0-9_-]#', '', basename($key));
    if ($slug === '') return null;

    $t = be_q1("SELECT * FROM tokens WHERE slug=? LIMIT 1", [$slug]);
    if (!$t) $t = be_q1("SELECT * FROM tokens WHERE id=? LIMIT 1", [$slug]);
    if (!$t) return null;

    if (($t['status'] ?? 'active') !== 'active') return null;
    $dom = strtolower((string)($t['domain'] ?? '*'));
    if ($dom !== '' && $dom !== '*' && $dom !== strtolower($host)) return null;
    if ((int)$t['max_hits'] > 0 && (int)$t['hits'] >= (int)$t['max_hits']) return null;

    $t['branding'] = be_json($t['branding']);
    if (!is_array($t['branding'])) $t['branding'] = [];
    return $t;
}

function be_forward_params(string $target, array $tok): string {
    $mode = (string)($tok['params_mode'] ?? 'merge');
    if ($mode === 'strip') return $target;

    $incoming = $_GET ?? [];
    $deny  = array_filter(array_map('trim', explode(',', (string)($tok['param_block'] ?? ''))));
    $allow = array_filter(array_map('trim', explode(',', (string)($tok['param_allow'] ?? ''))));
    $internal = ['slug', 't', 'token', 'ref'];

    $keep = [];
    if ($mode === 'forward_only') {
        foreach ($allow as $a) if (isset($incoming[$a])) $keep[$a] = $incoming[$a];
    } else {
        foreach ($incoming as $k => $v) {
            if (in_array($k, $internal, true) || in_array($k, $deny, true)) continue;
            if ($allow && !in_array($k, $allow, true)) continue;
            if (is_array($v)) continue;
            $keep[$k] = $v;
        }
    }
    if (!$keep) return $target;

    $frag = '';
    if (strpos($target, '#') !== false) { [$target, $frag] = explode('#', $target, 2); $frag = '#' . $frag; }
    $sep = strpos($target, '?') === false ? '?' : '&';
    return $target . $sep . http_build_query($keep) . $frag;
}

function be_log_hit(array $tok): array {
    $db  = be_db();
    $ua  = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $dev = be_parse_ua($ua);
    $ip  = be_ip();
    $geo = be_geo($ip);

    $row = [
        'token_id'=> (string)$tok['id'],
        'ts'      => time(),
        'ip'      => $ip,
        'country' => (string)($geo['country'] ?? ''),
        'country_code' => (string)($geo['country_code'] ?? ''),
        'region'  => (string)($geo['region'] ?? ''),
        'city'    => (string)($geo['city'] ?? ''),
        'isp'     => (string)($geo['isp'] ?? ''),
        'org'     => (string)($geo['org'] ?? ''),
        'asn'     => (string)($geo['asn'] ?? ''),
        'device'  => (string)$dev['device'],
        'os'      => (string)$dev['os'],
        'os_ver'  => (string)$dev['os_ver'],
        'browser' => (string)$dev['browser'],
        'br_ver'  => (string)$dev['br_ver'],
        'is_bot'  => $dev['is_bot'] ? 1 : 0,
        'bot'     => (string)($dev['bot'] ?? ''),
        'lang'    => substr((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 0, 60),
        'referrer'=> substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 400),
        'host'    => (string)($_SERVER['HTTP_HOST'] ?? ''),
        'path'    => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
        'params'  => substr((string)($_SERVER['QUERY_STRING'] ?? ''), 0, 300),
        'ua'      => substr($ua, 0, 400),
    ];
    $cols = array_keys($row);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $db->prepare("INSERT INTO hits (" . implode(',', $cols) . ") VALUES ($ph)")->execute(array_values($row));

    $db->prepare("UPDATE tokens SET hits = hits + 1 WHERE id=?")->execute([(string)$tok['id']]);
    $recent = (int)(be_q1("SELECT COUNT(DISTINCT ip) c FROM hits WHERE token_id=? AND ts > ?",
        [(string)$tok['id'], time() - 86400])['c'] ?? 0);
    $db->prepare("UPDATE tokens SET uniq=? WHERE id=?")->execute([$recent, (string)$tok['id']]);

    return array_merge($row, ['geo_source' => (string)($geo['source'] ?? '')]);
}

function be_alert(array $tok, array $hit): void {
    if (empty($tok['alert_tg'])) return;
    $flag = $hit['country_code'] ? strtoupper((string)$hit['country_code']) : '-';
    $msg = "<b>Bolt hit</b> - " . be_esc((string)$tok['name']) . "\n"
         . "\xF0\x9F\x8C\x90 " . be_esc(trim($hit['country'] . ' ' . $hit['city'] ?: 'unknown')) . " (" . be_esc($flag) . ")\n"
         . "\xF0\x9F\x96\xA5 " . be_esc($hit['ip']) . " - " . be_esc($hit['isp']) . "\n"
         . "\xF0\x9F\x92\xBB " . be_esc($hit['device'] . ' / ' . $hit['os'] . ' ' . $hit['os_ver'] . ' / ' . $hit['browser'] . ' ' . $hit['br_ver']) . "\n"
         . (($hit['referrer'] ?? '') !== '' ? "\xF0\x9F\x94\x97 " . be_esc($hit['referrer']) . "\n" : '')
         . "\xF0\x9F\x8E\xAF " . be_esc((string)$tok['target']);
    be_tg($msg);
}

function be_serve(string $path): void {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $key = $path;
    if (preg_match('#^/(?:r|t)/(.+)$#', $path, $m)) $key = $m[1];

    $tok = be_resolve_token($key, $host);
    if (!$tok) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<meta name="robots" content="noindex,nofollow"><title>404</title>'
           . '<div style="font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;'
           . 'display:grid;place-items:center;height:100vh;color:#5b6472">'
           . '<div style="text-align:center"><div style="font-size:44px;font-weight:700;color:#111418">404</div>'
           . '<div>This page could not be found.</div></div></div>';
        return;
    }

    $hit = be_log_hit($tok);
    try { be_alert($tok, $hit); } catch (Throwable $e) {}

    $target = be_forward_params((string)$tok['target'], $tok);
    header('X-Robots-Tag: noindex, nofollow');

    $mode = (string)$tok['mode'];
    if ($mode === 'redirect') {
        $code = (int)$tok['redirect_code'];
        if (!in_array($code, [301,302,303,307,308], true)) $code = 302;
        http_response_code($code);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ' . $target);
        return;
    }

    // landing / chain: render the template, optionally auto-forward.
    if ($mode === 'chain' && (int)$tok['delay'] <= 0) $tok['delay'] = 5;
    $html = be_tpl_render((string)$tok['template'], $tok, [
        'target' => $target,
        'host'   => $host,
        'brand'  => (string)($tok['branding']['brand'] ?? ''),
    ]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $html;
}
