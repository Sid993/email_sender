#!/usr/bin/env python3
"""
Fake SMTP server for load-testing the bulk mailer (no mail is delivered).

    python3 tools/fake_smtp.py --port 2525 --delay 0.3 --stats stats.json

Failure injection by recipient address:
    *fail550*@...   -> permanent 550 at RCPT
    *temp451*@...   -> 451 at RCPT on the first 2 attempts, then accepted
    *slow*@...      -> 4 s delay before accepting DATA
    *hang*@...      -> never answers DATA (tests timeouts)
Options:
    --user / --password   require AUTH (PLAIN or LOGIN) with these credentials
    --max-conn N          answer 421 to connections beyond N (provider limit)
    --delay S             delay before the final 250 for every message (simulates latency)
    --cert / --key        enable STARTTLS with this certificate (and --ssl-port for implicit TLS)
Writes live counters to --stats (peak concurrent transactions, per-address deliveries).
"""
import argparse, asyncio, base64, json, random, ssl, time
from collections import Counter

ap = argparse.ArgumentParser()
ap.add_argument('--host', default='127.0.0.1')
ap.add_argument('--port', type=int, default=2525)
ap.add_argument('--delay', type=float, default=0.2)
ap.add_argument('--jitter', type=float, default=0.1)
ap.add_argument('--user', default='')
ap.add_argument('--password', default='')
ap.add_argument('--max-conn', type=int, default=0)
ap.add_argument('--stats', default='')
ap.add_argument('--cert', default='')
ap.add_argument('--key', default='')
ap.add_argument('--ssl-port', type=int, default=0)
args = ap.parse_args()
TLS_CTX = None
if args.cert:
    TLS_CTX = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    TLS_CTX.load_cert_chain(args.cert, args.key)

S = {'connections': 0, 'peak_connections': 0, 'in_tx': 0, 'peak_in_tx': 0, 'accepted': 0,
     'rejected': 0, 'auth_fail': 0, 'refused_421': 0, 'started': time.time()}
deliveries = Counter()
temp_attempts = Counter()


def dump():
    if args.stats:
        dup = {k: v for k, v in deliveries.items() if v > 1}
        with open(args.stats, 'w') as f:
            json.dump({**S, 'unique_delivered': len(deliveries), 'duplicates': dup}, f)


async def handle(reader, writer):
    S['connections'] += 1
    S['peak_connections'] = max(S['peak_connections'], S['connections'])
    send = lambda line: writer.write((line + '\r\n').encode())
    in_tx = False
    try:
        if args.max_conn and S['connections'] > args.max_conn:
            S['refused_421'] += 1
            send('421 4.7.0 Too many connections, try again later')
            await writer.drain()
            return
        send('220 fake.smtp ESMTP ready')
        authed = not args.user
        rcpt = []
        while True:
            raw = await reader.readline()
            if not raw:
                break
            line = raw.decode(errors='replace').rstrip('\r\n')
            cmd = line[:4].upper()
            if cmd in ('EHLO', 'HELO'):
                tls_on = writer.get_extra_info('sslcontext') is not None
                starttls = b'250-STARTTLS\r\n' if TLS_CTX and not tls_on else b''
                writer.write(b'250-fake.smtp\r\n' + starttls + b'250-AUTH PLAIN LOGIN\r\n250-PIPELINING\r\n250 8BITMIME\r\n')
            elif cmd == 'STAR' and TLS_CTX:
                send('220 2.0.0 Ready to start TLS')
                await writer.drain()
                await writer.start_tls(TLS_CTX)
                S['tls_upgrades'] = S.get('tls_upgrades', 0) + 1
                continue
            elif cmd == 'AUTH':
                parts = line.split()
                if parts[1].upper() == 'PLAIN':
                    if len(parts) > 2:
                        token = parts[2]
                    else:
                        send('334 ')
                        await writer.drain()
                        token = (await reader.readline()).decode().strip()
                    _, u, p = base64.b64decode(token).decode().split('\0')
                else:  # LOGIN
                    if len(parts) > 2:
                        u = base64.b64decode(parts[2]).decode()
                    else:
                        send('334 VXNlcm5hbWU6')
                        await writer.drain()
                        u = base64.b64decode((await reader.readline()).strip()).decode()
                    send('334 UGFzc3dvcmQ6')
                    await writer.drain()
                    p = base64.b64decode((await reader.readline()).strip()).decode()
                if u == args.user and p == args.password:
                    authed = True
                    send('235 2.7.0 Authentication successful')
                else:
                    S['auth_fail'] += 1
                    send('535 5.7.8 Authentication credentials invalid')
            elif cmd == 'MAIL':
                if not authed:
                    send('530 5.7.0 Authentication required')
                else:
                    rcpt = []
                    if not in_tx:
                        in_tx = True
                        S['in_tx'] += 1
                        S['peak_in_tx'] = max(S['peak_in_tx'], S['in_tx'])
                    send('250 2.1.0 OK')
            elif cmd == 'RCPT':
                addr = line.split(':', 1)[1].strip().strip('<>').lower()
                if 'fail550' in addr:
                    S['rejected'] += 1
                    send('550 5.1.1 Mailbox does not exist')
                elif 'temp451' in addr and temp_attempts[addr] < 2:
                    temp_attempts[addr] += 1
                    send('451 4.3.0 Temporary failure, try later')
                else:
                    rcpt.append(addr)
                    send('250 2.1.5 OK')
            elif cmd == 'DATA':
                send('354 End data with <CR><LF>.<CR><LF>')
                await writer.drain()
                while True:
                    l = await reader.readline()
                    if not l or l in (b'.\r\n', b'.\n'):
                        break
                if any('hang' in r for r in rcpt):
                    await asyncio.sleep(3600)
                delay = args.delay + random.random() * args.jitter + (4 if any('slow' in r for r in rcpt) else 0)
                await asyncio.sleep(delay)
                for r in rcpt:
                    deliveries[r] += 1
                S['accepted'] += len(rcpt)
                send('250 2.0.0 Queued')
                if in_tx:
                    in_tx = False
                    S['in_tx'] -= 1
            elif cmd == 'RSET':
                rcpt = []
                if in_tx:
                    in_tx = False
                    S['in_tx'] -= 1
                send('250 OK')
            elif cmd == 'NOOP':
                send('250 OK')
            elif cmd == 'QUIT':
                send('221 Bye')
                await writer.drain()
                break
            else:
                send('502 Command not implemented')
            await writer.drain()
    except (ConnectionError, asyncio.IncompleteReadError, ValueError, IndexError):
        pass
    finally:
        if in_tx:
            S['in_tx'] -= 1
        S['connections'] -= 1
        writer.close()


async def stats_loop():
    while True:
        dump()
        await asyncio.sleep(0.5)


async def main():
    server = await asyncio.start_server(handle, args.host, args.port, limit=2 ** 20)
    print(f'Fake SMTP listening on {args.host}:{args.port}', flush=True)
    if TLS_CTX and args.ssl_port:
        await asyncio.start_server(handle, args.host, args.ssl_port, ssl=TLS_CTX, limit=2 ** 20)
        print(f'Implicit TLS on {args.host}:{args.ssl_port}', flush=True)
    asyncio.create_task(stats_loop())
    async with server:
        await server.serve_forever()

asyncio.run(main())
