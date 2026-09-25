<?php
// ============================================================
//  Rotating link URLs
//  Per recipient OR per batch (N recipients share one URL) OR a
//  single shared URL. Each token is a fresh opaque path that
//  302-redirects to the target. Lets you rotate link identity
//  across a campaign and later see which link each recipient
//  actually hit.
// ============================================================
require_once __DIR__ . '/../lib.php';

/**
 * Build the ordered list of link tokens a campaign will use.
 * Recipient at 0-based $index uses  url_token_for($camp, $index, $tokens) .
 */
function url_build_tokens(array $camp, int $recCount): array {
    $mode   = (string)($camp['url_mode'] ?? 'per_recipient');
    $target = (string)($camp['url_target'] ?? '');
    $batch  = max(1, (int)($camp['url_batch_size'] ?? 1));
    $campId = (int)($camp['id'] ?? 0);

    $tokens = [];
    if ($mode === 'single') {
        $t = new_token(14); url_store($t, $target, $campId, 0);
        $tokens[] = $t;
    } elseif ($mode === 'per_batch') {
        $nBatches = max(1, intdiv($recCount - 1, $batch) + 1);
        for ($b = 0; $b < $nBatches; $b++) {
            $t = new_token(14); url_store($t, $target, $campId, $b);
            $tokens[] = $t;
        }
    } else { // per_recipient
        for ($i = 0; $i < $recCount; $i++) {
            $t = new_token(14); url_store($t, $target, $campId, $i);
            $tokens[] = $t;
        }
    }
    return $tokens;
}

function url_token_for(array $camp, int $index, array $tokens): ?string {
    if (!$tokens) return null;
    $mode = (string)($camp['url_mode'] ?? 'per_recipient');
    if ($mode === 'single') return $tokens[0];
    if ($mode === 'per_batch') {
        $b = intdiv($index, max(1, (int)($camp['url_batch_size'] ?? 1)));
        return $tokens[min($b, count($tokens) - 1)];
    }
    return $tokens[min($index, count($tokens) - 1)];
}

function url_store(string $token, string $target, int $campId, int $batch): void {
    try {
        db()->prepare('INSERT INTO url_tokens (token, target, campaign_id, batch) VALUES (?,?,?,?)')
            ->execute([$token, $target, $campId, $batch]);
    } catch (Throwable $e) {}
}

function url_lookup(string $token): ?array {
    $st = db()->prepare('SELECT * FROM url_tokens WHERE token = ?');
    $st->execute([$token]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function url_base(): string {
    $b = (string)cfg_get('app_base', APP_BASE);
    if ($b !== '') return rtrim($b, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return "$scheme://$host";
}

function url_public(string $token): string {
    // ?token= form (not /go/<token>) so it works with zero Apache config.
    return url_base() . '/go.php?token=' . $token;
}
