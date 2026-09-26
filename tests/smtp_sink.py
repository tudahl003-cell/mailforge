#!/usr/bin/env python3
"""Minimal SMTP sink for tests.

Accepts connections, speaks just enough SMTP, and appends each received
message to a JSONL file so a test can assert on the envelope and raw data.

Usage: smtp_sink.py <port> <out.jsonl>
"""
import json
import socket
import sys
import threading

PORT = int(sys.argv[1])
OUT = sys.argv[2]


def handle(conn, addr):
    f = conn.makefile('rwb')
    captured = {'from': '', 'rcpt': [], 'data': ''}
    try:
        f.write(b'220 sink.local ESMTP ready\r\n')
        f.flush()
        in_data = False
        data_lines = []
        while True:
            line = f.readline()
            if not line:
                break
            text = line.decode('utf-8', 'replace').rstrip('\r\n')
            if in_data:
                if text == '.':
                    in_data = False
                    captured['data'] = '\r\n'.join(data_lines)
                    f.write(b'250 2.0.0 Ok: queued\r\n')
                    f.flush()
                    with open(OUT, 'a') as out:
                        out.write(json.dumps(captured) + '\n')
                    captured = {'from': '', 'rcpt': [], 'data': ''}
                    data_lines = []
                    continue
                data_lines.append(line[1:] if line.startswith(b'..') else line)
                data_lines[-1] = data_lines[-1].decode('utf-8', 'replace').rstrip('\r\n')
                continue
            upper = text.upper()
            if upper.startswith('EHLO') or upper.startswith('HELO'):
                f.write(b'250-sink.local\r\n250-SIZE 10485760\r\n250 AUTH LOGIN PLAIN\r\n')
            elif upper.startswith('AUTH LOGIN'):
                f.write(b'334 VXNlcm5hbWU6\r\n'); f.flush()
                f.readline(); f.write(b'334 UGFzc3dvcmQ6\r\n'); f.flush(); f.readline()
                f.write(b'235 2.7.0 Authentication successful\r\n')
            elif upper.startswith('AUTH PLAIN'):
                f.write(b'235 2.7.0 Authentication successful\r\n')
            elif upper.startswith('MAIL FROM'):
                captured['from'] = text.split(':', 1)[1].strip()
                f.write(b'250 2.1.0 Ok\r\n')
            elif upper.startswith('RCPT TO'):
                captured['rcpt'].append(text.split(':', 1)[1].strip())
                f.write(b'250 2.1.5 Ok\r\n')
            elif upper.startswith('DATA'):
                in_data = True
                f.write(b'354 End data with <CR><LF>.<CR><LF>\r\n')
            elif upper.startswith('QUIT'):
                f.write(b'221 2.0.0 Bye\r\n'); f.flush()
                break
            elif upper.startswith('RSET'):
                f.write(b'250 2.0.0 Ok\r\n')
            else:
                f.write(b'250 2.0.0 Ok\r\n')
            f.flush()
    except Exception:
        pass
    finally:
        try:
            f.close()
        except Exception:
            pass
        conn.close()


def main():
    srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    srv.bind(('127.0.0.1', PORT))
    srv.listen(16)
    print('sink listening on %d' % PORT, flush=True)
    while True:
        conn, addr = srv.accept()
        threading.Thread(target=handle, args=(conn, addr), daemon=True).start()


if __name__ == '__main__':
    main()
