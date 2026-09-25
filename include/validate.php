<?php
// ============================================================
//  Email checker + verifier
//  Layers: syntax -> domain/MX/DNS -> disposable/role heuristics
//          -> (optional) live SMTP RCPT probe against a pool.
//  Returns a 0-100 score + structured detail.
// ============================================================
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/smtp.php';

function domain_mx(string $domain): ?array {
    // Returns [priority, weight, host] for the best MX, or null on NXDOMAIN
    // / no mail route. getmxrr() returns true and fills $weights with
    // [host => weight] pairs (lowest weight = highest priority).
    if (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A')) return null; // NXDOMAIN
    $weights = [];
    if (!@getmxrr($domain, $weights)) return null;
    if (!is_array($weights) || !count($weights)) return null;
    $best = array_keys($weights, min($weights))[0];
    return [0, (int)min($weights), (string)$best];
}

function ip_reachable(string $host): bool {
    $a = gethostbyname($host);
    if ($a === $host) return false;
    if (filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        // Allow private (localhost testing) but flag it
        return true;
    }
    return true;
}

function is_disposable_domain(string $domain): bool {
    static $list = null;
    if ($list === null) {
        $list = [
            'mailinator.com','guerrillamail.com','10minutemail.com','throwawaymail.com',
            'tempmail.com','temp-mail.org','sharklasers.com','guerrillamailblock.com',
            'grr.la','trashmail.com','trashmail.net','fakeinbox.com','maildrop.cc',
            'discard.email','getnada.com','nada.email','moakt.com','mytemp.email',
            'tempinbox.com','yopmail.com','yopmail.fr','tempmail.org','fakeinbox.in',
            'mailnesia.com','tempmailer.com','dispostable.com','mailcatch.com',
            'spamgourmet.com','spamgourmet.net','spamgourmet.org','mytempaddress.com',
            'burnermail.io','1chance.com','spam4.me','tempinbox.com','throwam.com',
            'mailmoat.com','tempinbox.net','temp-mail.com','throwawaymail.org',
        ];
    }
    return in_array(strtolower($domain), $list, true);
}

function is_role_address(string $email): string {
    $local = strtolower(explode('@', $email)[0]);
    $roles = ['info','sales','support','contact','admin','webmaster','postmaster',
              'office','billing','hr','jobs','careers','press','media','team',
              'hello','inquiries','customerservice','service'];
    return in_array($local, $roles, true) ? $local : '';
}

/**
 * Full verification.
 *  $deep   = true runs DNS/MX heuristics (the "verifier"). false = syntax only.
 *  $probe  = true additionally runs a live SMTP RCPT check (no mail sent).
 *  $acct   = optional pre-picked SMTP account for the probe (avoids re-selecting).
 */
function verify_email(string $email, bool $deep = true, bool $probe = false, ?array $acct = null): array {
    $email = trim($email);
    $base = ['email' => $email, 'result' => 'unknown', 'score' => 0,
             'detail' => '', 'checks' => []];

    // 1. Syntax
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $base['result'] = 'invalid'; $base['score'] = 0;
        $base['detail'] = 'fails RFC syntax check';
        $base['checks']['syntax'] = 'fail';
        return $base;
    }
    $base['checks']['syntax'] = 'ok';
    $base['score'] = 30;

    [$local, $domain] = array_pad(explode('@', $email), 2, '');
    $domain = strtolower($domain);
    $base['checks']['role'] = is_role_address($email);

    // 2. Domain + MX + DNS (only when deep)
    if ($deep) {
        $disposable = is_disposable_domain($domain);
        if ($disposable) $base['checks']['disposable'] = 'suspected throwaway domain';
        $role = $base['checks']['role'];

        $mx = domain_mx($domain);
        if ($mx === null) {
            $base['checks']['mx'] = 'fail';
            if ($disposable) {
                $base['result'] = 'risky'; $base['score'] = 10;
                $base['detail'] = 'disposable domain with no mail route';
            } else {
                $base['result'] = 'invalid'; $base['score'] = 5;
                $base['detail'] = 'domain does not exist (NXDOMAIN) or has no mail route';
            }
            return $base;
        }
        $base['checks']['mx'] = 'ok';
        $base['checks']['mx_host'] = $mx[2];

        if (ip_reachable($mx[2])) {
            $base['checks']['mx_ip'] = 'ok';
            $base['result'] = 'valid';
            $base['score'] = 80;
        } else {
            $base['checks']['mx_ip'] = 'fail';
            $base['result'] = 'risky';
            $base['score'] = 45;
            $base['detail'] = 'MX host does not resolve to an IP';
        }

        // Heuristic downgrades (independent of MX)
        if ($disposable) {
            $base['result'] = 'risky';
            $base['score'] = min($base['score'], 40);
        } elseif ($role) {
            $base['checks']['role_note'] = 'role address (' . $role . ') — may not be read by an individual';
            $base['result'] = 'risky';
            $base['score'] = min($base['score'], 65);
        }
    } else {
        $base['checks']['mx'] = 'skipped';
        $base['result'] = 'valid';   // syntax-valid, unverified
        $base['score'] = 40;
    }

    // 4. Live SMTP RCPT probe (optional)
    if ($probe) {
        $p = smtp_rcpt_probe($email, $acct);
        $base['checks']['smtp'] = $p;
        if ($p === 'valid') {
            $base['result'] = 'valid'; $base['score'] = 98;
        } elseif ($p === 'invalid') {
            $base['result'] = 'invalid'; $base['score'] = 10;
        } else {
            $base['detail'] .= ($base['detail'] ? '; ' : '') . 'smtp probe inconclusive (' . $p . ')';
        }
    }

    // 5. Log
    try {
        db()->prepare('INSERT INTO verify_log (email, result, detail) VALUES (?,?,?)')
            ->execute([$email, $base['result'], $base['detail']]);
    } catch (Throwable $e) {}

    return $base;
}

/**
 * Live SMTP RCPT probe: connect, EHLO, MAIL FROM, RCPT TO, QUIT.
 * No DATA — nothing is actually sent. Returns valid|invalid|inconclusive.
 * Reuses $acct if provided; otherwise picks the best pool account.
 */
function smtp_rcpt_probe(string $email, ?array $acct = null): string {
    if ($acct === null) $acct = smtp_best_account(0);
    if (!$acct) return 'no_pool';
    $sock = smtp_connect($acct, 12);
    if (is_array($sock)) return 'inconclusive';
    try {
        smtp_cmd($sock, 'EHLO ' . ($acct['host'] ?: 'mailforge.local'), 250);
        smtp_cmd($sock, 'MAIL FROM:<' . ($acct['from_addr'] ?: 'postmaster@' . $acct['host']) . '>', 250);
        $code = smtp_rcpt_to($sock, $email);
        smtp_cmd($sock, 'QUIT', 221);
        if ($code === 250) return 'valid';
        if (in_array($code, [550, 551, 553, 513], true)) return 'invalid';
        return 'inconclusive';
    } catch (Throwable $e) {
        return 'inconclusive';
    } finally {
        @fclose($sock);
    }
}
