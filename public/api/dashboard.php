<?php
// api/dashboard.php — aggregate counts for the dashboard view.
require_once __DIR__ . '/../../lib.php';
require_auth();

$accs = db()->query('SELECT COUNT(*) n, COALESCE(SUM(active),0) a FROM smtp_accounts')->fetch(PDO::FETCH_ASSOC);
$camps = db()->query('SELECT COUNT(*) n, COALESCE(SUM(status IN ("done","partial")),0) d FROM campaigns')->fetch(PDO::FETCH_ASSOC);
$sent = (int)db()->query('SELECT COUNT(*) FROM send_log WHERE status = "sent"')->fetchColumn();
$failed = (int)db()->query('SELECT COUNT(*) FROM send_log WHERE status = "failed"')->fetchColumn();
$skipped = (int)db()->query('SELECT COUNT(*) FROM send_log WHERE status = "skipped"')->fetchColumn();
$urlHits = (int)db()->query('SELECT COALESCE(SUM(hits),0) FROM url_tokens')->fetchColumn();
$recentSends = db()->query('SELECT id, campaign_id, recipient, status, reason, smtp_msgid, created_at FROM send_log ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
$recentCamps = db()->query('SELECT id, name, status, created_at FROM campaigns ORDER BY id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
$acctRows = db()->query('SELECT id, name, host, port, from_addr, active, last_error, last_used FROM smtp_accounts ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);

// today's sends
$today = (int)db()->query('SELECT COUNT(*) FROM send_log WHERE date(created_at,"unixepoch") = date("now")')->fetchColumn();

json_out(['ok' => true, 'data' => [
    'accounts' => (int)$accs['n'], 'accounts_active' => (int)$accs['a'],    'campaigns' => (int)$camps['n'], 'campaigns_done' => (int)$camps['d'],
    'sent' => $sent, 'failed' => $failed, 'skipped' => $skipped,
    'sent_today' => $today,
    'url_hits' => $urlHits,
    'recent_sends' => $recentSends,
    'recent_campaigns' => $recentCamps,
    'accounts_list' => $acctRows,
]]);
