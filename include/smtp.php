<?php
// ============================================================
//  SMTP engine — minimal, dependency-free client over PHP
//  sockets. STARTTLS, AUTH LOGIN/PLAIN, one message per call.
//  Pool rotation + per-account daily quota + health tracking.
// ============================================================
require_once __DIR__ . '/../lib.php';

// ------------------------------------------------------------
//  Pool access
// ------------------------------------------------------------
function smtp_list_accounts(): array {
    $rows = db()->query('SELECT * FROM smtp_accounts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['password'] = '••••••••';
        $r['use_tls'] = (int)$r['use_tls'];
        $r['daily_quota'] = (int)$r['daily_quota'];
        $r['last_used'] = (int)$r['last_used'];
        $r['sent_today'] = smtp_sent_today((int)$r['id']);
    }
    return $rows;
}
function smtp_get_account(int $id): ?array {
    $st = db()->prepare('SELECT * FROM smtp_accounts WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

// Daily send counter (file per account+day, no schema churn).
function smtp_sent_today(int $id): int {
    return (int)@file_get_contents(DATA_DIR . '/cnt_' . $id . '_' . date('Ymd'));
}
function smtp_count_send(int $id): void {
    $f = DATA_DIR . '/cnt_' . $id . '_' . date('Ymd');
    $n = (int)@file_get_contents($f);
    @file_put_contents($f, (string)($n + 1), LOCK_EX);
}

/**
 * Pick the next account.
 *  'rotate'   -> active + within daily quota, least-recently-used first.
 *  'specific' -> the given account id (bypasses quota/health).
 */
function smtp_best_account(int $account_id, string $mode = 'rotate'): ?array {
    if ($mode === 'specific' && $account_id) {
        return smtp_get_account($account_id);
    }
    static $lastPick = 0;   // in-request alternation (same-second sends)
    $rows = db()->query('SELECT * FROM smtp_accounts WHERE active = 1 ORDER BY last_used ASC, id ASC')
                   ->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    foreach ($rows as $i => $r) {
        if ((int)$r['daily_quota'] > 0 && smtp_sent_today((int)$r['id']) >= (int)$r['daily_quota']) continue;
        // skip the account we just used this request, if another is available
        if (count($rows) > 1 && (int)$r['id'] === $lastPick) {
            $next = $rows[($i + 1) % count($rows)];
            if ((int)$next['daily_quota'] === 0 ||
                smtp_sent_today((int)$next['id']) < (int)$next['daily_quota']) {
                $lastPick = (int)$next['id'];
                return $next;
            }
        }
        $lastPick = (int)$r['id'];
        return $r;
    }
    return $rows[0] ?? null;   // everything over quota -> least-used anyway
}

function smtp_touch(int $id, string $err = ''): void {
    // Bumps last_used (drives 'rotate' ordering) and records/clears the
    // last error. Called on every send outcome.
    try {
        db()->prepare('UPDATE smtp_accounts SET last_used = ?, last_error = ? WHERE id = ?')
            ->execute([now(), $err, $id]);
    } catch (Throwable $e) {}
}

// ------------------------------------------------------------
//  Socket primitives. Multi-line replies: "250-..." continues,
//  "250 " (space) terminates.
// ------------------------------------------------------------
function smtp_reply($sock): array {
    // Returns [code, full_text]
    $lines = [];
    $code = 0;
    while (($line = fgets($sock, 1024)) !== false) {
        $lines[] = $line;
        if (strlen($line) >= 4) {
            $code = (int)substr($line, 0, 3);
            if ($line[3] === ' ') break;      // final line
        }
    }
    return [$code, rtrim(implode('', $lines))];
}

function smtp_cmd($sock, string $cmd, int $expect = 250) {
    fwrite($sock, $cmd . "\r\n");
    [$code, $text] = smtp_reply($sock);
    if ($code !== $expect) {
        throw new RuntimeException("SMTP $code (expected $expect) for '$cmd': " . substr($text, 0, 200));
    }
    return $text;
}

/** RCPT TO that does NOT throw on rejection — returns the code. */
function smtp_rcpt_to($sock, string $addr): int {
    fwrite($sock, "RCPT TO:<$addr>\r\n");
    [$code] = smtp_reply($sock);
    return $code;
}

function smtp_connect(array $acc, int $timeout = 15) {
    // Returns the socket resource on success, or ['err' => string].
    $host = (string)$acc['host'];
    $port = (int)($acc['port'] ?: 587);
    $sock = @stream_socket_client("tcp://$host:$port", $errno, $errstr, $timeout);
    if (!$sock) {
        $e = "connect failed: $errstr ($errno)";
        smtp_touch((int)$acc['id'], $e);
        return ['err' => $e];
    }
    stream_set_timeout($sock, $timeout);

    try {
        [$code] = smtp_reply($sock);
        if ($code !== 220) {
            $e = "bad banner (code $code)";
            smtp_touch((int)$acc['id'], $e);
            fclose($sock);
            return ['err' => $e];
        }

        $ehloName = $acc['host'];   // EHLO identity = the host we speak to
        // smtp_cmd returns the full (multi-line) EHLO capability block.
        $caps = smtp_cmd($sock, 'EHLO ' . $ehloName, 250);

        if ((int)$acc['use_tls'] && stripos($caps, 'STARTTLS') !== false) {
            smtp_cmd($sock, 'STARTTLS', 220);
            $ok = stream_socket_enable_crypto($sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if ($ok !== true) throw new RuntimeException('TLS handshake failed');
            $caps = smtp_cmd($sock, 'EHLO ' . $ehloName, 250);
        }

        $user = (string)$acc['username'];
        if ($user !== '') {
            if (!smtp_auth($sock, $user, (string)$acc['password'], $caps)) {
                throw new RuntimeException('authentication failed');
            }
        }
        return $sock;
    } catch (Throwable $e) {
        @fclose($sock);
        smtp_touch((int)$acc['id'], $e->getMessage());
        return ['err' => $e->getMessage()];
    }
}

function smtp_auth($sock, string $user, string $pass, string $caps): bool {
    $capsU = strtoupper($caps);
    if (strpos($capsU, 'AUTH LOGIN') !== false) {
        try {
            smtp_cmd($sock, 'AUTH LOGIN', 334);
            fwrite($sock, base64_encode($user) . "\r\n");
            [$c1] = smtp_reply($sock);
            if ($c1 !== 334) return false;
            fwrite($sock, base64_encode($pass) . "\r\n");
            [$c2] = smtp_reply($sock);
            return $c2 === 235;
        } catch (Throwable $e) { return false; }
    }
    if (strpos($capsU, 'AUTH PLAIN') !== false) {
        try {
            $r = smtp_cmd($sock, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $pass), 235);
            return true;
        } catch (Throwable $e) { return false; }
    }
    // No AUTH advertised — assume open relay (some private pools allow it).
    return true;
}

// ------------------------------------------------------------
//  Send one message.
//  $msg: from, from_name, to, subject, body (text), body_html?,
//        headers (assoc), body_type 'html'|'text'
// ------------------------------------------------------------
function smtp_send(array $acc, array $msg): array {
    $sock = smtp_connect($acc);
    if (is_array($sock)) return ['ok' => false, 'msgid' => '', 'error' => $sock['err']];

    $fromAddr  = (string)($msg['from'] ?? ($acc['from_addr'] ?: 'postmaster@' . $acc['host']));
    $fromName  = (string)($msg['from_name'] ?? ($acc['from_name'] ?? ''));
    $to        = (string)$msg['to'];
    $domain    = explode('@', $fromAddr)[1] ?: 'mailforge.local';
    $msgid     = '<' . new_token(16) . '@' . $domain . '>';
    $boundary  = 'mf_' . new_token(12);
    $type      = ($msg['body_type'] ?? 'text') === 'html' ? 'html' : 'text';

    $from = $fromName !== ''
        ? '=?UTF-8?B?' . base64_encode($fromName) . "?= <$fromAddr>"
        : "<$fromAddr>";

    $headers = [
        'From'         => $from,
        'To'           => "<$to>",
        'Subject'      => '=?UTF-8?B?' . base64_encode((string)$msg['subject']) . '?=',
        'Date'         => date('r'),
        'Message-ID'   => $msgid,
        'MIME-Version' => '1.0',
    ];
    foreach (($msg['headers'] ?? []) as $k => $v) {
        if ($k && !isset($headers[$k])) $headers[$k] = $v;
    }

    if ($type === 'html') {
        $headers['Content-Type'] = "multipart/alternative; boundary=\"$boundary\"";
        $payload = "--$boundary\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: base64\r\n\r\n"
                 . chunk_split(base64_encode((string)$msg['body']), 76, "\r\n") . "\r\n";
        if (!empty($msg['body_html'])) {
            $payload .= "--$boundary\r\n"
                      . "Content-Type: text/html; charset=UTF-8\r\n"
                      . "Content-Transfer-Encoding: base64\r\n\r\n"
                      . chunk_split(base64_encode((string)$msg['body_html']), 76, "\r\n") . "\r\n";
        }
        $payload .= "--$boundary--\r\n";
    } else {
        $headers['Content-Type'] = "text/plain; charset=UTF-8";
        $headers['Content-Transfer-Encoding'] = 'base64';
        $payload = chunk_split(base64_encode((string)$msg['body']), 76, "\r\n");
    }

    $head = '';
    foreach ($headers as $k => $v) $head .= "$k: $v\r\n";
    $head .= "\r\n";
    $data  = preg_replace('/^\./m', '..', $head . $payload);

    $result = ['ok' => false, 'msgid' => $msgid, 'error' => ''];
    try {
        smtp_cmd($sock, 'MAIL FROM:<' . $fromAddr . '>', 250);
        fwrite($sock, "RCPT TO:<$to>\r\n");
        [$code, $text] = smtp_reply($sock);
        if ($code !== 250) {
            $result['error'] = "RCPT rejected ($code): " . substr($text, 0, 160);
            @fclose($sock);
            return $result;
        }
        smtp_cmd($sock, 'DATA', 354);
        fwrite($sock, $data . "\r\n.\r\n");
        [$code, $text] = smtp_reply($sock);
        if ($code !== 250) {
            $result['error'] = 'message not accepted (' . $code . '): ' . substr($text, 0, 160);
            @fclose($sock);
            return $result;
        }
        $result['ok'] = true;
        smtp_count_send((int)$acc['id']);
        smtp_cmd($sock, 'QUIT', 221);
        fclose($sock);
        smtp_touch((int)$acc['id'], '');
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        @fclose($sock);
        smtp_touch((int)$acc['id'], $result['error']);
    }
    return $result;
}

/** Connectivity test for one account: connect + AUTH only, then QUIT. */
function smtp_test_account(array $acc): array {
    $sock = smtp_connect($acc);
    if (is_array($sock)) return ['ok' => false, 'error' => $sock['err']];
    $err = '';
    try {
        smtp_cmd($sock, 'QUIT', 221);
    } catch (Throwable $e) { $err = $e->getMessage(); }
    fclose($sock);
    return ['ok' => true, 'error' => $err];
}
