<?php
// ============================================================
//  mailforge — core library
//  SQLite persistence, auth, JSON helpers, rate limits, alerts.
// ============================================================

define('APP_ROOT',  __DIR__);
define('DATA_DIR',  __DIR__ . '/data');
define('DB_FILE',   DATA_DIR . '/mailforge.sqlite');
define('CONFIG_DIR',DATA_DIR . '/config');
define('ALERT_DIR', DATA_DIR . '/alerts');

@mkdir(DATA_DIR,   0775, true);
@mkdir(CONFIG_DIR, 0775, true);
@mkdir(ALERT_DIR,  0775, true);

define('ADMIN_TOKEN', (string)($_ENV['ADMIN_TOKEN'] ?? getenv('ADMIN_TOKEN') ?: ''));
define('TG_BOT_TOKEN', (string)($_ENV['TG_BOT_TOKEN'] ?? getenv('TG_BOT_TOKEN') ?: ''));
define('TG_CHAT_ID',   (string)($_ENV['TG_CHAT_ID'] ?? getenv('TG_CHAT_ID') ?: ''));
define('LLM_API_KEY',  (string)($_ENV['LLM_API_KEY'] ?? getenv('LLM_API_KEY') ?: ''));
define('LLM_BASE_URL', (string)($_ENV['LLM_BASE_URL'] ?? getenv('LLM_BASE_URL') ?: 'https://api.openai.com/v1'));
define('LLM_MODEL',    (string)($_ENV['LLM_MODEL'] ?? getenv('LLM_MODEL') ?: 'gpt-4o-mini'));
define('APP_BASE',     (string)($_ENV['APP_BASE'] ?? getenv('APP_BASE') ?: ''));

// ------------------------------------------------------------
//  SQLite
// ------------------------------------------------------------
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        db_migrate($pdo);
    }
    return $pdo;
}

function db_migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS smtp_accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '',
        host TEXT NOT NULL,
        port INTEGER NOT NULL DEFAULT 587,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        from_addr TEXT NOT NULL,
        from_name TEXT NOT NULL DEFAULT '',
        use_tls INTEGER NOT NULL DEFAULT 1,
        daily_quota INTEGER NOT NULL DEFAULT 500,
        active INTEGER NOT NULL DEFAULT 1,
        last_used INTEGER NOT NULL DEFAULT 0,
        last_error TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '',
        recipients TEXT NOT NULL,          -- one email per line
        subject_base TEXT NOT NULL DEFAULT '',
        body_base TEXT NOT NULL,           -- {{link}}, {{first_name}} tokens
        tone TEXT NOT NULL DEFAULT 'neutral',
        personalization INTEGER NOT NULL DEFAULT 1,
        url_mode TEXT NOT NULL DEFAULT 'per_recipient',  -- per_recipient | per_batch | single
        url_target TEXT NOT NULL DEFAULT '',
        url_batch_size INTEGER NOT NULL DEFAULT 1,
        smtp_mode TEXT NOT NULL DEFAULT 'rotate',         -- rotate | specific
        smtp_account_id INTEGER,
        status TEXT NOT NULL DEFAULT 'draft',             -- draft | sending | done | partial | failed
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS url_tokens (
        token TEXT PRIMARY KEY,
        target TEXT NOT NULL,
        campaign_id INTEGER,
        batch INTEGER NOT NULL DEFAULT 0,
        hits INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS send_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        campaign_id INTEGER,
        recipient TEXT NOT NULL,
        smtp_account_id INTEGER,
        subject TEXT NOT NULL DEFAULT '',
        body TEXT NOT NULL DEFAULT '',
        link TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL,              -- sent | failed | skipped
        reason TEXT NOT NULL DEFAULT '',
        score INTEGER NOT NULL DEFAULT 0,  -- verifier score 0-100 (0 = not verified)
        smtp_msgid TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS verify_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        result TEXT NOT NULL,             -- valid | risky | invalid | unknown
        detail TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_log_campaign ON send_log(campaign_id, id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_url_campaign ON url_tokens(campaign_id)");
}

// ------------------------------------------------------------
//  Config (settings the user can change at runtime, persisted)
// ------------------------------------------------------------
function cfg(): array {
    $f = CONFIG_DIR . '/settings.json';
    $d = @json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function cfg_set(array $patch): void {
    $cur = cfg();
    $cur = array_merge($cur, $patch);
    @file_put_contents(CONFIG_DIR . '/settings.json', json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
function cfg_get(string $key, $default = null) {
    $c = cfg();
    return $c[$key] ?? $default;
}

// ------------------------------------------------------------
//  JSON helpers
// ------------------------------------------------------------
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function json_in(): array {
    $raw = (string)file_get_contents('php://input');
    $j = json_decode($raw ?: '{}', true);
    return is_array($j) ? $j : [];
}
function req_str(array $in, string $k, string $d = ''): string {
    $v = $in[$k] ?? $_GET[$k] ?? $_POST[$k] ?? null;
    return $v !== null ? (string)$v : $d;
}
function req_int(array $in, string $k, int $d = 0): int {
    $v = $in[$k] ?? $_GET[$k] ?? $_POST[$k] ?? null;
    return ($v !== null && is_numeric($v)) ? (int)$v : $d;
}

// ------------------------------------------------------------
//  Auth + rate limiting
// ------------------------------------------------------------
function is_authed(): bool {
    $tok = effective_admin_token();
    $t = (string)($_GET['token'] ?? ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? ''));
    if ($t === '' && str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        $t = (string)($_POST['token'] ?? '');
    }
    return hash_equals($tok, $t);
}

/**
 * Effective admin token: env ADMIN_TOKEN wins (set on Railway);
 * otherwise the runtime token stored in settings.json. Empty = open
 * (dev only). The Settings tab can create/rotate the settings-level
 * token; once the env var is set it takes precedence and is shown
 * masked.
 */
function effective_admin_token(): string {
    if (ADMIN_TOKEN !== '') return ADMIN_TOKEN;
    return (string)cfg_get('admin_token', '');
}
function require_auth(): void {
    // Throttle wrong-token guesses per IP (60/10s) — the gate is public.
    if (!is_authed()) {
        if (!rate_allows('auth:' . ip(), 60, 10)) {
            json_out(['error' => 'too many attempts'], 429);
        }
        json_out(['error' => 'unauthorized'], 401);
    }
}
function rate_allows(string $key, int $max, int $windowSec): bool {
    $f = DATA_DIR . '/rate_' . md5($key) . '_' . intdiv(time(), $windowSec);
    $n = (int)@file_get_contents($f);
    $n++;
    @file_put_contents($f, (string)$n, LOCK_EX);
    return $n <= $max;
}

// ------------------------------------------------------------
//  Misc
// ------------------------------------------------------------
function ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}
function tg(string $msg): void {
    if (TG_BOT_TOKEN === '' || TG_CHAT_ID === '') return;
    $url = 'https://api.telegram.org/bot' . TG_BOT_TOKEN . '/sendMessage';
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['chat_id' => TG_CHAT_ID, 'text' => $msg, 'parse_mode' => 'HTML']),
    ]]);
    @file_get_contents($url, false, $ctx);
}
function new_token(int $bytes = 16): string {
    return substr(bin2hex(random_bytes($bytes)), 0, $bytes * 2);
}
function now(): int { return time(); }
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
