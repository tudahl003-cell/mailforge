<?php
// BOLT ELITE REDIRECT - core library
// SQLite persistence, config, auth, alerts, HTTP helpers.

define('APP_ROOT',   __DIR__);
define('DATA_DIR',   getenv('BE_DATA_DIR') ?: (__DIR__ . '/data'));
define('DB_FILE',    DATA_DIR . '/bolt.sqlite');
define('CONFIG_DIR', DATA_DIR . '/config');
define('CACHE_DIR',  DATA_DIR . '/cache');

@mkdir(DATA_DIR,   0775, true);
@mkdir(CONFIG_DIR, 0775, true);
@mkdir(CACHE_DIR,  0775, true);

define('ASSOC', PDO::FETCH_ASSOC);

define('ADMIN_TOKEN',  (string)($_ENV['ADMIN_TOKEN'] ?? getenv('ADMIN_TOKEN') ?: ''));
define('TG_BOT_TOKEN', (string)($_ENV['TG_BOT_TOKEN'] ?? getenv('TG_BOT_TOKEN') ?: ''));

define('LLM_API_KEY',  (string)($_ENV['LLM_API_KEY'] ?? getenv('LLM_API_KEY') ?: ''));
define('LLM_BASE_URL', (string)($_ENV['LLM_BASE_URL'] ?? getenv('LLM_BASE_URL') ?: 'https://api.openai.com/v1'));
define('LLM_MODEL',    (string)($_ENV['LLM_MODEL'] ?? getenv('LLM_MODEL') ?: 'gpt-4o-mini'));

// --- SQLite ---
function be_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        be_migrate($pdo);
    }
    return $pdo;
}

function be_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tokens (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL DEFAULT '',
        slug TEXT NOT NULL DEFAULT '',
        domain TEXT NOT NULL DEFAULT '*',
        mode TEXT NOT NULL DEFAULT 'redirect',
        status TEXT NOT NULL DEFAULT 'active',
        redirect_code INTEGER NOT NULL DEFAULT 302,
        target TEXT NOT NULL DEFAULT '',
        template TEXT NOT NULL DEFAULT 'update',
        branding TEXT NOT NULL DEFAULT '{}',
        params_mode TEXT NOT NULL DEFAULT 'merge',
        param_allow TEXT NOT NULL DEFAULT '',
        param_block TEXT NOT NULL DEFAULT '',
        alert_tg INTEGER NOT NULL DEFAULT 0,
        delay INTEGER NOT NULL DEFAULT 0,
        max_hits INTEGER NOT NULL DEFAULT 0,
        notes TEXT NOT NULL DEFAULT '',
        hits INTEGER NOT NULL DEFAULT 0,
        uniq INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT 0,
        paused_at INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_tok_slug ON tokens(slug)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_id TEXT NOT NULL,
        ts INTEGER NOT NULL DEFAULT 0,
        ip TEXT NOT NULL DEFAULT '',
        country TEXT NOT NULL DEFAULT '',
        country_code TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        isp TEXT NOT NULL DEFAULT '',
        org TEXT NOT NULL DEFAULT '',
        asn TEXT NOT NULL DEFAULT '',
        device TEXT NOT NULL DEFAULT '',
        os TEXT NOT NULL DEFAULT '',
        os_ver TEXT NOT NULL DEFAULT '',
        browser TEXT NOT NULL DEFAULT '',
        br_ver TEXT NOT NULL DEFAULT '',
        is_bot INTEGER NOT NULL DEFAULT 0,
        bot TEXT NOT NULL DEFAULT '',
        lang TEXT NOT NULL DEFAULT '',
        referrer TEXT NOT NULL DEFAULT '',
        host TEXT NOT NULL DEFAULT '',
        path TEXT NOT NULL DEFAULT '',
        params TEXT NOT NULL DEFAULT '',
        ua TEXT NOT NULL DEFAULT ''
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hits_token ON hits(token_id, ts)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_hits_ts ON hits(ts)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS geo_cache (
        ip TEXT PRIMARY KEY,
        country TEXT NOT NULL DEFAULT '',
        country_code TEXT NOT NULL DEFAULT '',
        region TEXT NOT NULL DEFAULT '',
        city TEXT NOT NULL DEFAULT '',
        isp TEXT NOT NULL DEFAULT '',
        org TEXT NOT NULL DEFAULT '',
        asn TEXT NOT NULL DEFAULT '',
        timezone TEXT NOT NULL DEFAULT '',
        lat TEXT NOT NULL DEFAULT '',
        lon TEXT NOT NULL DEFAULT '',
        source TEXT NOT NULL DEFAULT '',
        lookup_at INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS seq (k TEXT PRIMARY KEY, v INTEGER NOT NULL DEFAULT 0)");
}

// --- Config / settings ---
function cfg(): array {
    static $c = null;
    if ($c === null) {
        $rows = be_db()->query("SELECT k,v FROM settings")->fetchAll(ASSOC);
        $c = [];
        foreach ($rows as $r) $c[$r['k']] = $r['v'];
    }
    return $c;
}
function cfg_get(string $k, $d = null) {
    $c = cfg();
    return array_key_exists($k, $c) ? $c[$k] : $d;
}
function cfg_set(array $patch): void {
    $db = be_db();
    $st = $db->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v");
    foreach ($patch as $k => $v) $st->execute([$k, is_scalar($v) ? (string)$v : json_encode($v)]);
}

// --- JSON helpers ---
function be_json($v): array {
    if (is_array($v)) return $v;
    $d = json_decode((string)$v, true);
    return is_array($d) ? $d : [];
}
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function json_in(): array {
    $raw = (string)file_get_contents('php://input');
    $j = json_decode($raw ?: '{}', true);
    if (!is_array($j)) $j = $_POST ?: [];
    return $j;
}
function be_err(string $msg, int $code = 400): void { json_out(['ok' => false, 'error' => $msg], $code); }
function req_str(array $in, string $k, string $d = ''): string {
    $v = $in[$k] ?? $_GET[$k] ?? $_POST[$k] ?? null;
    return $v !== null ? (string)$v : $d;
}
function req_int(array $in, string $k, int $d = 0): int {
    $v = $in[$k] ?? $_GET[$k] ?? $_POST[$k] ?? null;
    return ($v !== null && is_numeric($v)) ? (int)$v : $d;
}
function req_bool(array $in, string $k): bool {
    $v = $in[$k] ?? $_GET[$k] ?? $_POST[$k] ?? null;
    return !empty($v) && $v !== '0' && $v !== 'false';
}

// --- HTTP ---
function be_http_json(string $url, int $timeoutMs = 2000): ?array {
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeoutMs / 1000, 'ignore_errors' => true,
        'header' => "User-Agent: BoltElite/1.0\r\nAccept: application/json\r\n",
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}
function be_http_post_json(string $url, array $payload, array $headers = [], int $timeoutMs = 30000): ?array {
    $hdr = "Content-Type: application/json\r\n" . implode('', array_map(fn($h) => $h . "\r\n", $headers));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => $timeoutMs / 1000, 'ignore_errors' => true,
        'header' => $hdr, 'content' => json_encode($payload),
    ]]);
    $r = @file_get_contents($url, false, $ctx);
    if ($r === false) return null;
    $j = json_decode($r, true);
    return is_array($j) ? $j : null;
}

// --- Auth + rate limits ---
function effective_admin_token(): string {
    if (ADMIN_TOKEN !== '') return ADMIN_TOKEN;
    return (string)cfg_get('admin_token', '');
}
function is_authed(): bool {
    $t = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if ($t === '' && str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        $t = (string)($_POST['token'] ?? '');
    }
    $eff = effective_admin_token();
    return $eff !== '' && hash_equals($eff, $t);
}
function require_auth(): void {
    if (!is_authed()) {
        if (!rate_allows('auth:' . be_ip(), 60, 600)) json_out(['ok' => false, 'error' => 'too many attempts'], 429);
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}
function rate_allows(string $key, int $max, int $windowSec): bool {
    $f = DATA_DIR . '/rate_' . md5($key) . '_' . intdiv(time(), max(1, $windowSec));
    $n = (int)@file_get_contents($f);
    $n++;
    @file_put_contents($f, (string)$n, LOCK_EX);
    return $n <= $max;
}

// --- Misc ---
function be_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        $v = (string)($_SERVER[$k] ?? '');
        if ($v !== '') {
            $first = trim(explode(',', $v)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
    }
    return '0.0.0.0';
}
function be_now(): int { return time(); }
function be_esc($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function be_ts(int $ts): string { return $ts ? date('Y-m-d H:i', $ts) : '-'; }
function be_new_token(int $len = 10): string {
    $abc = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) $s .= $abc[random_int(0, strlen($abc) - 1)];
    return $s;
}
function be_tg(string $msg): bool {
    $bot = TG_BOT_TOKEN !== '' ? TG_BOT_TOKEN : (string)cfg_get('tg_bot_token', '');
    $chat = (string)cfg_get('tg_chat_id', '');
    if ($bot === '' || $chat === '') return false;
    foreach (explode(',', $chat) as $c) {
        $c = trim($c);
        if ($c === '') continue;
        be_http_post_json('https://api.telegram.org/bot' . $bot . '/sendMessage',
            ['chat_id' => $c, 'text' => $msg, 'parse_mode' => 'HTML',
             'disable_web_page_preview' => true], [], 6000);
    }
    return true;
}
function be_seq(string $k): int {
    $db = be_db();
    $db->prepare("INSERT INTO seq (k,v) VALUES (?,1) ON CONFLICT(k) DO UPDATE SET v=v+1")->execute([$k]);
    return (int)(be_q1("SELECT v FROM seq WHERE k=?", [$k])['v'] ?? 0);
}

// Prepared-statement helpers (PDO::query() cannot bind parameters).
function be_q(string $sql, array $args = []): array {
    $st = be_db()->prepare($sql);
    $st->execute($args);
    return $st->fetchAll(ASSOC);
}
function be_q1(string $sql, array $args = []): array {
    $st = be_db()->prepare($sql);
    $st->execute($args);
    $r = $st->fetch(ASSOC);
    return is_array($r) ? $r : [];
}
function be_exec(string $sql, array $args = []): void {
    $st = be_db()->prepare($sql);
    $st->execute($args);
}
function be_base_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $https = (($_SERVER['HTTPS'] ?? '') === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . $host;
}
