"""glpi-monitor: watches GLPI from outside the app pods and alerts when something breaks.

Every INTERVAL seconds:
  app      GET http://glpi-app:8080/status.php - GLPI answers, and its own checks: database,
           cron tasks, mail collectors, filesystem
  https    the certificate served on the public address: expires in < 14 days = problem
  backup   the newest backup (glpi-backups volume): failed, or older than 26 h; copy to the
           file share failed
A problem is alerted after it's seen twice in a row (no alert for one blip), then every
REMIND hours while it lasts, and "recovered" when it's gone. Optional daily "all OK" message
(if it stops arriving, the monitor itself is down).

Channels (any number): e-mail (own SMTP: works while GLPI is down), Microsoft Teams, Google
Chat, Slack, LINE Messaging API, generic JSON webhook.

Settings: the files of the mounted Secret glpi-monitor (/etc/glpi-monitor/<KEY>), re-read on
every round, so changes from GLPI (Setup > Monitoring) apply without a restart.
HTTP :8080  GET /status (JSON)   POST /test (header X-Monitor-Token) -> test alert, results.
Python standard library only.
"""
import datetime
import json
import os
import smtplib
import socket
import ssl
import threading
import time
import traceback
import urllib.request
from email.message import EmailMessage
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

CONF_DIR = os.environ.get('MONITOR_CONFIG_DIR', '/etc/glpi-monitor')
BACKUPS = os.environ.get('BACKUP_DIR', '/var/lib/glpi-backups')
TZ = datetime.timezone(datetime.timedelta(hours=7))  # Asia/Bangkok, no tzdata in the image

state = {'checks': {}, 'started': None, 'last_round': None, 'last_heartbeat_day': None, 'sent': []}
lock = threading.Lock()


def conf(key, default=''):
    try:
        with open(os.path.join(CONF_DIR, key), encoding='utf-8') as f:
            v = f.read().strip()
            return v if v != '' else default
    except OSError:
        return os.environ.get(key, default)


def now():
    return datetime.datetime.now(TZ)


def http_get(url, timeout=10, context=None):
    req = urllib.request.Request(url, headers={'Accept': 'application/json', 'User-Agent': 'glpi-monitor'})
    with urllib.request.urlopen(req, timeout=timeout, context=context) as r:
        return r.status, r.read().decode('utf-8', 'replace')


# ---------------------------------------------------------------- checks -> {name: (ok, detail)}
def check_app():
    out = {}
    url = conf('APP_URL', 'http://glpi-app:8080') + '/status.php'
    try:
        code, body = http_get(url, timeout=15)
        data = json.loads(body)
    except Exception as e:  # noqa: BLE001
        return {'app': (False, f'GLPI ไม่ตอบ ({type(e).__name__}: {str(e)[:120]})')}
    out['app'] = (code == 200, 'GLPI ตอบปกติ' if code == 200 else f'GLPI ตอบ HTTP {code}')
    labels = {'db': 'ฐานข้อมูล', 'crontasks': 'งาน cron', 'mail_collectors': 'รับอีเมล', 'filesystem': 'พื้นที่ไฟล์'}
    for key, label in labels.items():
        s = (data.get(key) or {}).get('status', 'NO_DATA')
        if s in ('OK', 'NO_DATA'):
            out[key] = (True, f'{label} ปกติ')
        else:
            extra = (data.get(key) or {}).get('status_msg', '')
            out[key] = (False, f'{label}: {s} {extra}'.strip())
    return out


def check_https():
    target = conf('PUBLIC_URL', '')
    if not target.startswith('https://'):
        return {}
    host = target.split('://', 1)[1].split('/', 1)[0].split(':')[0]
    connect = conf('PUBLIC_CONNECT', f'{host}:443')  # e.g. the Ingress service inside the cluster
    chost, cport = connect.rsplit(':', 1)
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        with socket.create_connection((chost, int(cport)), timeout=10) as s, ctx.wrap_socket(s, server_hostname=host) as t:
            der = t.getpeercert(binary_form=True)
        not_after = _cert_not_after(der)
        days = (not_after - datetime.datetime.now(datetime.timezone.utc)).days
        warn = int(conf('CERT_WARN_DAYS', '14'))
        return {'https': (days >= warn, f'certificate HTTPS เหลือ {days} วัน' + ('' if days >= warn else ' (ต่ออายุ / อัปโหลดใหม่ที่ Setup > HTTPS)'))}
    except Exception as e:  # noqa: BLE001
        return {'https': (False, f'เชื่อมต่อ HTTPS ไม่ได้ ({type(e).__name__}: {str(e)[:120]})')}


def _cert_not_after(der):
    # minimal DER walk: Certificate -> tbsCertificate -> validity -> notAfter (stdlib has no parser
    # for binary certs without verification); good enough for UTCTime / GeneralizedTime
    def read(b, i):
        tag, ln = b[i], b[i + 1]
        i += 2
        if ln & 0x80:
            n = ln & 0x7F
            ln = int.from_bytes(b[i:i + n], 'big')
            i += n
        return tag, i, ln
    _, i, _ = read(der, 0)              # Certificate
    _, i, _ = read(der, i)              # tbsCertificate
    if der[i] == 0xA0:                  # [0] version
        _, j, ln = read(der, i)
        i = j + ln
    for _ in range(3):                  # serial, signature alg, issuer
        _, j, ln = read(der, i)
        i = j + ln
    _, i, _ = read(der, i)              # validity
    _, j, ln = read(der, i)             # notBefore
    i = j + ln
    tag, j, ln = read(der, i)           # notAfter
    s = der[j:j + ln].decode()
    fmt = '%y%m%d%H%M%SZ' if tag == 0x17 else '%Y%m%d%H%M%SZ'
    return datetime.datetime.strptime(s, fmt).replace(tzinfo=datetime.timezone.utc)


def check_backup():
    if not os.path.isdir(BACKUPS):
        return {}
    out = {}
    try:
        with open(os.path.join(BACKUPS, 'last-run.json'), encoding='utf-8') as f:
            last = json.load(f)
    except (OSError, ValueError):
        return {'backup': (False, 'ยังไม่มี backup เลย')}
    names = sorted(n for n in os.listdir(BACKUPS) if n.startswith('backup-'))
    newest = None
    if names:
        try:
            with open(os.path.join(BACKUPS, names[-1], 'manifest.json'), encoding='utf-8') as f:
                newest = json.load(f)
        except (OSError, ValueError):
            pass
    max_h = float(conf('BACKUP_MAX_AGE_HOURS', '26'))
    if last.get('status') == 'failed':
        out['backup'] = (False, f"backup ล้มเหลว: {last.get('error', '')[:200]}")
    elif newest is None:
        out['backup'] = (False, 'ไม่พบ backup ที่สมบูรณ์')
    else:
        age = (now() - datetime.datetime.fromisoformat(newest['created'])).total_seconds() / 3600
        out['backup'] = (age <= max_h, f'backup ล่าสุดเมื่อ {age:.0f} ชั่วโมงก่อน' + ('' if age <= max_h else ' (เกินกำหนด: ตรวจ CronJob glpi-backup)'))
    try:
        with open(os.path.join(BACKUPS, 'remote-status.json'), encoding='utf-8') as f:
            remote = json.load(f)
        out['backup_share'] = (remote.get('status') == 'ok', 'copy ไป file share ปกติ' if remote.get('status') == 'ok'
                               else f"copy ไป file share ล้มเหลว: {str(remote.get('error', ''))[:200]}")
    except (OSError, ValueError):
        pass
    return out


# ---------------------------------------------------------------- channels
def channels():
    return {k: v for k, v in {
        'email': conf('ALERT_EMAILS'),
        'teams': conf('ALERT_TEAMS_URL'),
        'google_chat': conf('ALERT_GCHAT_URL'),
        'slack': conf('ALERT_SLACK_URL'),
        'line': conf('ALERT_LINE_TOKEN') and conf('ALERT_LINE_TO'),
        'webhook': conf('ALERT_WEBHOOK_URL'),
    }.items() if v}


def post_json(url, payload, headers=None):
    req = urllib.request.Request(url, data=json.dumps(payload).encode(), method='POST',
                                 headers={'Content-Type': 'application/json', **(headers or {})})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.status


def send(title, lines, level):
    """level: problem | recovered | info. Returns {channel: 'ok' | error}."""
    icon = {'problem': '🔴', 'recovered': '🟢', 'info': 'ℹ️'}[level]
    site = conf('SITE_NAME', 'GLPI ITSM')
    text = f'{icon} [{site}] {title}\n' + '\n'.join(f'• {l}' for l in lines) + f"\n{now():%Y-%m-%d %H:%M} · {conf('PUBLIC_URL', '')}"
    results = {}
    for ch, target in channels().items():
        try:
            if ch == 'email':
                msg = EmailMessage()
                msg['Subject'] = f'{icon} [{site}] {title}'
                msg['From'] = conf('SMTP_FROM', 'glpi-monitor@localhost')
                msg['To'] = ', '.join(a.strip() for a in target.split(',') if a.strip())
                msg.set_content(text)
                port = int(conf('SMTP_PORT', '587'))
                cls = smtplib.SMTP_SSL if port == 465 else smtplib.SMTP
                with cls(conf('SMTP_HOST', 'localhost'), port, timeout=20) as s:
                    if port == 587:
                        s.starttls(context=ssl.create_default_context() if conf('SMTP_VERIFY_CERT', '1') == '1' else ssl._create_unverified_context())
                    if conf('SMTP_USER'):
                        s.login(conf('SMTP_USER'), conf('SMTP_PASSWORD'))
                    s.send_message(msg)
            elif ch == 'teams':  # Teams "Workflows" webhook (Adaptive Card)
                post_json(target, {'type': 'message', 'attachments': [{'contentType': 'application/vnd.microsoft.card.adaptive', 'content': {
                    '$schema': 'http://adaptivecards.io/schemas/adaptive-card.json', 'type': 'AdaptiveCard', 'version': '1.4',
                    'body': [{'type': 'TextBlock', 'text': text, 'wrap': True}]}}]})
            elif ch in ('google_chat', 'slack'):
                post_json(target, {'text': text})
            elif ch == 'line':  # LINE Messaging API push
                post_json('https://api.line.me/v2/bot/message/push', {'to': conf('ALERT_LINE_TO'), 'messages': [{'type': 'text', 'text': text[:4900]}]},
                          {'Authorization': 'Bearer ' + conf('ALERT_LINE_TOKEN')})
            elif ch == 'webhook':
                post_json(target, {'site': site, 'level': level, 'title': title, 'lines': lines, 'text': text, 'at': now().isoformat()})
            results[ch] = 'ok'
        except Exception as e:  # noqa: BLE001
            results[ch] = f'{type(e).__name__}: {str(e)[:160]}'
    with lock:
        state['sent'] = ([{'at': now().isoformat(), 'level': level, 'title': title, 'results': results}] + state['sent'])[:20]
    print(json.dumps({'alert': title, 'level': level, 'results': results}, ensure_ascii=False), flush=True)
    return results


# ---------------------------------------------------------------- loop
def round_once():
    results = {}
    for fn in (check_app, check_https, check_backup):
        try:
            results.update(fn())
        except Exception:  # noqa: BLE001
            results[fn.__name__] = (False, 'ตรวจไม่สำเร็จ: ' + traceback.format_exc(limit=1).strip().splitlines()[-1])
    remind = float(conf('REMIND_HOURS', '6')) * 3600
    new_problems, recovered, reminders = [], [], []
    t = time.time()
    with lock:
        for name, (ok, detail) in results.items():
            c = state['checks'].setdefault(name, {'ok': True, 'fails': 0, 'alerted': False, 'since': None, 'last_alert': 0})
            c['detail'] = detail
            if ok:
                if c['alerted']:
                    recovered.append(f'{detail} (มีปัญหาตั้งแต่ {c["since"]})')
                c.update(ok=True, fails=0, alerted=False, since=None)
            else:
                c['fails'] += 1
                c['ok'] = False
                c['since'] = c['since'] or now().strftime('%H:%M')
                if not c['alerted'] and c['fails'] >= int(conf('FAILS_BEFORE_ALERT', '2')):
                    new_problems.append(detail)
                    c['alerted'], c['last_alert'] = True, t
                elif c['alerted'] and t - c['last_alert'] >= remind:
                    reminders.append(f'{detail} (ตั้งแต่ {c["since"]})')
                    c['last_alert'] = t
        # checks that disappeared (e.g. file share turned off) are dropped
        for name in list(state['checks']):
            if name not in results:
                del state['checks'][name]
        state['last_round'] = now().isoformat()
    if new_problems:
        send('ระบบมีปัญหา', new_problems, 'problem')
    if reminders:
        send('ยังมีปัญหาอยู่', reminders, 'problem')
    if recovered:
        send('กลับมาปกติแล้ว', recovered, 'recovered')
    hb = conf('HEARTBEAT_HOUR', '')
    if hb.isdigit() and now().hour == int(hb) and state['last_heartbeat_day'] != now().date().isoformat():
        state['last_heartbeat_day'] = now().date().isoformat()
        with lock:
            bad = [c['detail'] for c in state['checks'].values() if not c['ok']]
        send('รายงานประจำวัน: ' + ('ปกติทุกอย่าง' if not bad else f'มีปัญหา {len(bad)} เรื่อง'),
             bad or [c['detail'] for c in state['checks'].values()], 'info' if not bad else 'problem')


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, body):
        data = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path.startswith('/status'):
            with lock:
                self._json(200, {'started': state['started'], 'last_round': state['last_round'],
                                 'interval': int(conf('INTERVAL', '60')), 'channels': sorted(channels()),
                                 'checks': state['checks'], 'sent': state['sent']})
        elif self.path.startswith('/healthz'):
            self._json(200, {'ok': True})
        else:
            self._json(404, {})

    def do_POST(self):
        token = conf('MONITOR_TOKEN')
        if not self.path.startswith('/test') or not token or self.headers.get('X-Monitor-Token') != token:
            return self._json(403, {'error': 'forbidden'})
        if not channels():
            return self._json(400, {'error': 'no alert channel configured'})
        self._json(200, send('ทดสอบการแจ้งเตือน', ['ถ้าเห็นข้อความนี้ แปลว่าช่องทางนี้ใช้ได้'], 'info'))

    def log_message(self, *a):
        pass


def main():
    state['started'] = now().isoformat()
    threading.Thread(target=ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever, daemon=True).start()
    print(f'glpi-monitor started, channels: {sorted(channels())}', flush=True)
    while True:
        t0 = time.time()
        round_once()
        time.sleep(max(5, int(conf('INTERVAL', '60')) - (time.time() - t0)))


if __name__ == '__main__':
    main()
