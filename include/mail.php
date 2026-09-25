<?php
// ============================================================
//  Send orchestration
//  validate -> compose (per-recipient unique) -> pick SMTP acct
//  -> inject rotating URL -> send -> log. Optional per-recipient
//  jitter delay and verify-before-send.
// ============================================================
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/url.php';
require_once __DIR__ . '/smtp.php';

function campaign_get(int $id): ?array {
    $st = db()->prepare('SELECT * FROM campaigns WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function campaign_save(array $fields): int {
    $cols = ['name','recipients','subject_base','body_base','tone','personalization',
             'url_mode','url_target','url_batch_size','smtp_mode','smtp_account_id','status'];
    $sets = []; $vals = [];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $fields)) continue;
        $sets[] = "$c = ?";
        $vals[] = $fields[$c];
    }
    if (!$sets) return 0;
    $vals[] = $fields['id'];
    db()->prepare('UPDATE campaigns SET ' . implode(',', $sets) . ' WHERE id = ?')->execute($vals);
    return (int)$fields['id'];
}
function campaign_create(array $fields): int {
    $cols = ['name','recipients','subject_base','body_base','tone','personalization',
             'url_mode','url_target','url_batch_size','smtp_mode','smtp_account_id','status'];
    $sets = []; $vals = [];
    foreach ($cols as $c) {
        if (!array_key_exists($c, $fields)) continue;
        $sets[] = "$c"; $vals[] = $fields[$c];
    }
    if (!$sets) return 0;
    db()->prepare('INSERT INTO campaigns (' . implode(',', $sets) . ') VALUES (' . implode(',', array_fill(0, count($sets), '?')) . ')')
       ->execute($vals);
    return (int)db()->lastInsertId();
}

/**
 * Run a campaign. Synchronous (one request does the whole batch).
 * Options:
 *   verify        bool  verify each address first; skip 'invalid' (keep 'risky')
 *   min_score     int   only send when verifier score >= min (0 = ignore)
 *   jitter_min/max float  random per-recipient pause seconds (spam pacing)
 *   max_send      int   stop after N successful sends
 * Returns a summary + per-recipient results.
 */
function run_campaign(int $id, array $opts = []): array {
    $camp = campaign_get($id);
    if (!$camp) return ['ok' => false, 'error' => 'campaign not found'];

    $recips = parse_recipients((string)$camp['recipients']);
    if (!$recips) return ['ok' => false, 'error' => 'no recipients parsed'];

    $verify     = !empty($opts['verify']);
    $minScore   = max(0, (int)($opts['min_score'] ?? 0));
    $jitterMin  = max(0, (float)($opts['jitter_min'] ?? 0.0));
    $jitterMax  = max(0, (float)($opts['jitter_max'] ?? 0.0));
    if ($jitterMax < $jitterMin) $jitterMax = $jitterMin;
    $maxSend    = (int)($opts['max_send'] ?? 0);

    // Build rotating link set (persisted, survives page reloads)
    $tokens = url_build_tokens($camp, count($recips));

    // SMTP account selection
    $mode = (string)($camp['smtp_mode'] ?? 'rotate');
    $fixed = $mode === 'specific' ? smtp_get_account((int)$camp['smtp_account_id']) : null;
    if ($fixed === null && $mode === 'specific') {
        return ['ok' => false, 'error' => 'no active SMTP account with that id'];
    }

    $sent = 0; $failed = 0; $skipped = 0;
    $results = [];
    $acctUsed = 0;

    for ($i = 0; $i < count($recips); $i++) {
        $rec = $recips[$i];
        $email = $rec['email'];
        if ($maxSend && $sent >= $maxSend) {
            $results[] = ['i' => $i, 'email' => $email, 'status' => 'skipped', 'reason' => 'max_send reached'];
            $skipped++;
            continue;
        }

        // 1) verify
        $score = 0; $vres = '';
        if ($verify) {
            $v = verify_email($email, true);
            $score = (int)$v['score']; $vres = $v['result'];
            db()->prepare('INSERT INTO verify_log (email, result, detail) VALUES (?,?,?)')
                ->execute([$email, $vres, json_encode($v['detail'] ?? [])]);
            if ($vres === 'invalid' || ($v['checks']['disposable'] ?? '') || ($minScore > 0 && $score < $minScore)) {
                $results[] = ['i' => $i, 'email' => $email, 'status' => 'skipped',
                              'reason' => 'verified ' . $vres . " (score $score)", 'score' => $score];
                $skipped++;
                continue;
            }
        }

        // 2) compose
        $token = url_token_for($camp, $i, $tokens);
        $link  = $token ? url_public($token) : '';
        $composeOpts = $camp;
        [$subject, $body, $bodyHtml, $engine] = compose_for($composeOpts, $rec, $link);

        // 3) pick account
        $acct = $fixed !== null ? $fixed : smtp_best_account(0, 'rotate');
        if ($acct === null) {
            $results[] = ['i' => $i, 'email' => $email, 'status' => 'failed', 'reason' => 'no SMTP account available'];
            $failed++;
            // stop the whole batch — nothing to send with
            $rest = count($recips) - $i - 1;
            $skipped += $rest;
            break;
        }
        $acctUsed++;

        // 4) headers with deliverability defaults
        $headers = [
            'Reply-To' => $acct['from_addr'],
            'X-Mailer' => '',   // blank is best
        ];
        // List-Unsubscribe: optional (helps some ESPs, hurts others). Off by default.
        if (!empty($opts['unsubscribe'])) {
            $headers['List-Unsubscribe'] = '<mailto:' . $acct['from_addr'] . '>';
        }

        // 5) send
        $msg = [
            'from' => $acct['from_addr'],
            'from_name' => $acct['from_name'] ?: null,
            'to' => $email,
            'subject' => $subject,
            'body' => $body,
            'body_html' => $bodyHtml,
            'body_type' => $bodyHtml ? 'html' : 'text',
            'headers' => array_filter($headers, fn($v) => $v !== null && $v !== ''),
        ];
        $r = smtp_send($acct, $msg);

        if ($r['ok']) {
            $sent++;
            $status = 'sent'; $reason = 'ok';
        } else {
            $failed++;
            $status = 'failed'; $reason = $r['error'];
        }
        db()->prepare('INSERT INTO send_log (campaign_id, recipient, smtp_account_id, subject, body, link, status, reason, score, smtp_msgid) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $email, (int)$acct['id'], $subject, $body, $link, $status, $reason, $score, $r['msgid'] ?? '']);

        $results[] = ['i' => $i, 'email' => $email, 'status' => $status,
                      'reason' => $reason, 'score' => $score, 'engine' => $engine,
                      'account' => $acct['name'] ?: $acct['host'] . ':' . $acct['port'],
                      'subject' => $subject, 'link' => $link];

        // 6) pacing
        if ($jitterMax > 0 && $i < count($recips) - 1) {
            $d = $jitterMin + mt_rand(0, 1000) / 1000.0 * max(0, $jitterMax - $jitterMin);
            usleep((int)($d * 1_000_000));
        }
    }

    // finalize status
    $status = $failed === 0 ? 'done' : ($sent > 0 ? 'partial' : 'failed');
    campaign_save(['id' => $id, 'status' => $status]);

    // alert
    tg("<b>mailforge</b> — campaign #" . $id . " (" . esc((string)$camp['name']) . ")\n"
       . "sent: $sent   failed: $failed   skipped: $skipped\n"
       . "SMTP accounts used: $acctUsed");

    return ['ok' => true, 'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped,
            'status' => $status, 'results' => array_slice($results, 0, 500),
            'truncated' => count($results) > 500 ? count($results) - 500 : 0];
}
