<?php
// BOLT ELITE REDIRECT - IP / country / geo intelligence.
// Cached lookups via keyless ip-api.com (free tier, ~45 req/min) with an
// offline fallback so the redirect path NEVER blocks on external lookups.

function be_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v && preg_match('/^([0-9a-fA-F:.]+)$/', trim(explode(',', $v)[0]))) {
            return trim(explode(',', $v)[0]);
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function be_geo(?string $ip): array {
    $ip = (string)$ip;
    $out = ['ip'=>$ip,'country'=>'','country_code'=>'','region'=>'','city'=>'','isp'=>'','org'=>'','asn'=>'','timezone'=>'','lat'=>'','lon'=>'','source'=>''];
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return $out;

    // Private / reserved ranges never need an external lookup.
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $out['country'] = 'Local';
        $out['source'] = 'local';
        $out['lookup_at'] = (string)time();
        $db = be_db();
        $cols = array_keys($out);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare("INSERT OR REPLACE INTO geo_cache (" . implode(',', $cols) . ") VALUES ($ph)")
           ->execute(array_values($out));
        return $out;
    }

    $db = be_db();
    $row = be_q1("SELECT * FROM geo_cache WHERE ip=?", [$ip]);
    if ($row) {
        foreach ($out as $k => $v) if (isset($row[$k])) $out[$k] = $row[$k];
        return $out;
    }

    // Keyless lookup. Non-blocking: 1.2s timeout, never throws.
    $j = be_http_json("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,message,country,countryCode,regionName,city,isp,org,as,query,lat,lon", 1200);
    if ($j && ($j['status'] ?? '') === 'success') {
        $out['country']     = (string)($j['country'] ?? '');
        $out['country_code']= (string)($j['countryCode'] ?? '');
        $out['region']      = (string)($j['regionName'] ?? '');
        $out['city']        = (string)($j['city'] ?? '');
        $out['isp']         = (string)($j['isp'] ?? '');
        $out['org']         = (string)($j['org'] ?? '');
        $out['asn']         = (string)($j['as'] ?? '');
        $out['lat']         = (string)($j['lat'] ?? '');
        $out['lon']         = (string)($j['lon'] ?? '');
        $out['source']      = 'ip-api';
    } else {
        $out['source'] = 'offline';
    }

    $out['lookup_at'] = (string)time();
    $db->exec("DELETE FROM geo_cache WHERE lookup_at < " . (time() - 86400 * 14));
    $cols = array_keys($out);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $db->prepare("INSERT OR REPLACE INTO geo_cache (" . implode(',', $cols) . ") VALUES ($ph)")
       ->execute(array_values($out));
    return $out;
}

// Lightweight in-process throttle so a burst of hits doesn't burn the
// keyless quota (ip-api is ~45 req/min free).
function be_geo_throttled(bool &$throttled): void {
    $throttled = false;
    static $last = [0 => 0];
    $now = microtime(true);
    if ($now - $last[0] < 1.4) { $throttled = true; }
    $last[0] = $now;
}
