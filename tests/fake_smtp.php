<?php
// fake_smtp.php — tiny SMTP server for local functional tests.
// Supports: 220 banner, EHLO (AUTH LOGIN/PLAIN, no STARTTLS), AUTH LOGIN,
// MAIL/RCPT/DATA, QUIT. Logs every DATA blob to stdout.
$port = (int)($argv[1] ?? 2525);
$authUser = $argv[2] ?? 'user1';
$authPass = $argv[3] ?? 'pass1';
$failRcpt = $argv[4] ?? '';   // if set, reject RCPT for this address

$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) { fwrite(STDERR, "bind failed: $errstr\n"); exit(1); }
fwrite(STDERR, "fake smtp listening on $port\n");

while (($cli = @stream_socket_accept($server, 300)) !== false) {
    fwrite($cli, "220 mailforge-test ESMTP\r\n");
    $mode = 'cmd'; $dataLines = [];
    while (($line = fgets($cli, 8192)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($mode === 'data') {
            if ($line === '.') {
                $blob = implode("\n", $dataLines);
                fwrite(STDERR, "===== DATA START =====\n" . $blob . "\n===== DATA END =====\n");
                fwrite($cli, "250 2.0.0 OK queued\n");
                $mode = 'cmd';
            } else {
                $dataLines[] = $line;
            }
            continue;
        }
        $u = strtoupper($line);
        if ($u === 'EHLO ' || str_starts_with($u, 'EHLO')) {
            fwrite($cli, "250-mailforge-test\r\n250-8BITMIME\r\n250-AUTH LOGIN PLAIN\r\n250 SIZE 10240000\r\n");
        } elseif (str_starts_with($u, 'AUTH LOGIN')) {
            fwrite($cli, "334 VXNlcm5hbWU6\r\n");
            $line = rtrim((string)fgets($cli, 1024), "\r\n");
            if (base64_decode($line, true) !== $authUser) fwrite($cli, "535 5.7.8 auth failed\n");
            else fwrite($cli, "334 UGFzc3dvcmQ6\r\n");
            $line = rtrim((string)fgets($cli, 1024), "\r\n");
            if (base64_decode($line, true) !== $authPass) fwrite($cli, "535 5.7.8 auth failed\n");
            else fwrite($cli, "235 2.7.0 authenticated\n");
        } elseif (str_starts_with($u, 'AUTH PLAIN')) {
            $parts = explode("\0", base64_decode(trim(substr($u, 11)), true));
            if (($parts[1] ?? '') === $authUser && ($parts[2] ?? '') === $authPass)
                fwrite($cli, "235 2.7.0 authenticated\n");
            else fwrite($cli, "535 5.7.8 auth failed\n");
        } elseif (str_starts_with($u, 'MAIL FROM:')) {
            fwrite($cli, "250 2.1.0 OK\n");
        } elseif (str_starts_with($u, 'RCPT TO:')) {
            if ($failRcpt !== '' && str_contains($line, $failRcpt)) fwrite($cli, "550 5.1.1 no such user\n");
            else fwrite($cli, "250 2.1.5 OK\n");
        } elseif ($u === 'DATA') {
            fwrite($cli, "354 end data with <CR><LF>.<CR><LF>\n");
            $mode = 'data';
        } elseif ($u === 'QUIT') {
            fwrite($cli, "221 2.0.0 bye\n");
            break;
        } elseif ($u === 'RSET' || $u === 'NOOP') {
            fwrite($cli, "250 2.0.0 OK\n");
        } else {
            fwrite($cli, "250 2.0.0 OK\n");
        }
    }
    fclose($cli);
}
