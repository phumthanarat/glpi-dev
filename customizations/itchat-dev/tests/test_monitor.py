"""glpi-monitor + IT Monitor page, for real: channels set on Setup > Monitoring reach a webhook
receiver (Teams / Google Chat / Slack / generic payloads) and mailpit (e-mail); the checks are
green; the database is really stopped -> a "problem" alert arrives on every channel, it comes
back -> "recovered". Leaves the alert settings as they were.

Env: MAILPIT_URL (default http://localhost:30825)
"""
import json
import os
import re
import subprocess
import time

import requests

from lib import ADMIN, BASE, Checks, login

check = Checks('monitor')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
PAGE = BASE + '/plugins/itmonitor/front/monitoring.php'
MAILPIT = os.environ.get('MAILPIT_URL', 'http://localhost:30825').rstrip('/')
RCV = 'itmonitor-test-hook'
MAIL_TO = 'monitor-test@dev.glpi.labs'


def k(*args, stdin=None):
    return subprocess.run(['kubectl', '-n', NS, *args], input=stdin, capture_output=True, text=True)


def received():
    out = k('exec', f'deploy/{RCV}', '--', 'cat', '/tmp/hits.jsonl').stdout
    return [json.loads(l) for l in out.splitlines() if l.strip()]


def monitor_status():
    out = k('exec', POD, '-c', CONTAINER, '--', 'curl', '-s', 'http://glpi-monitor:8080/status').stdout
    try:
        return json.loads(out)
    except ValueError:
        return {}


def token(s):
    return re.search(r'name="_glpi_csrf_token" value="([^"]+)"', s.get(PAGE).text).group(1)


def wait_for(fn, timeout, step=5):
    end = time.time() + timeout
    while time.time() < end:
        v = fn()
        if v:
            return v
        time.sleep(step)
    return None


def mails(text):
    r = requests.get(f'{MAILPIT}/api/v1/search', params={'query': f'to:"{MAIL_TO}" subject:"{text}"'}, timeout=10)
    return r.json().get('messages', []) if r.ok else []


# a webhook receiver: records every POST (path + JSON body)
HOOK_PY = """
import json, http.server
class H(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
        with open('/tmp/hits.jsonl', 'a') as f:
            f.write(json.dumps({'path': self.path, 'body': json.loads(body)}) + '\\n')
        self.send_response(200); self.end_headers(); self.wfile.write(b'ok')
    def log_message(self, *a): pass
open('/tmp/hits.jsonl', 'a').close()
http.server.HTTPServer(('0.0.0.0', 8080), H).serve_forever()
"""
k('create', 'configmap', RCV, f'--from-literal=hook.py={HOOK_PY}', '--dry-run=client', '-o', 'yaml')
cm = k('create', 'configmap', RCV, f'--from-literal=hook.py={HOOK_PY}', '--dry-run=client', '-o', 'yaml').stdout
k('apply', '-f', '-', stdin=cm)
k('apply', '-f', '-', stdin=f"""
apiVersion: apps/v1
kind: Deployment
metadata: {{ name: {RCV}, labels: {{ app: {RCV} }} }}
spec:
  replicas: 1
  selector: {{ matchLabels: {{ app: {RCV} }} }}
  template:
    metadata: {{ labels: {{ app: {RCV} }} }}
    spec:
      containers:
        - name: hook
          image: python:3.12-alpine
          imagePullPolicy: IfNotPresent
          command: ["python", "-u", "/s/hook.py"]
          ports: [{{ containerPort: 8080 }}]
          readinessProbe: {{ tcpSocket: {{ port: 8080 }}, periodSeconds: 2 }}
          volumeMounts: [{{ name: s, mountPath: /s }}]
      volumes: [{{ name: s, configMap: {{ name: {RCV} }} }}]
---
apiVersion: v1
kind: Service
metadata: {{ name: {RCV} }}
spec: {{ selector: {{ app: {RCV} }}, ports: [{{ port: 8080 }}] }}
""")
check('webhook receiver up', k('rollout', 'status', f'deploy/{RCV}', '--timeout=120s').returncode == 0)
hook = f'http://{RCV}:8080'

admin = login(*ADMIN)
page = admin.get(PAGE).text
check('admin opens Setup > Monitoring', 'itmonitor-form' in page)
check('technician may not open it', 'itmonitor-form' not in login('itchat.test.tech').get(PAGE).text)
before = dict(re.findall(r'name="(ALERT_EMAILS|HEARTBEAT_HOUR|INTERVAL|ALERT_LINE_TO)" value="([^"]*)"', page))
st = monitor_status()
check('monitor running, all checks green', st.get('checks') and all(c['ok'] for c in st['checks'].values()),
      {n: c['detail'] for n, c in st.get('checks', {}).items()})
check('monitor checks GLPI, database, cron, HTTPS, backup', {'app', 'db', 'crontasks', 'https', 'backup'} <= set(st.get('checks', {})), sorted(st.get('checks', {})))

# channels -> receiver + mailpit, check every 10 s for the test
r = admin.post(PAGE, data={'_glpi_csrf_token': token(admin), 'save': 1, 'ALERT_EMAILS': MAIL_TO, 'HEARTBEAT_HOUR': '', 'INTERVAL': 10,
                           'ALERT_TEAMS_URL': hook + '/teams', 'ALERT_GCHAT_URL': hook + '/gchat', 'ALERT_SLACK_URL': hook + '/slack',
                           'ALERT_WEBHOOK_URL': hook + '/generic', 'ALERT_LINE_TOKEN': '', 'ALERT_LINE_TO': '',
                           'SMTP_HOST': 'mailpit', 'SMTP_PORT': '1025', 'SMTP_USER': '', 'SMTP_FROM': 'glpi-monitor@dev.glpi.labs'})
check('channels saved', 'บันทึกแล้ว' in r.text)
check('webhook URLs shown masked, not in clear', hook + '/teams' not in admin.get(PAGE).text)
want = {'email', 'teams', 'google_chat', 'slack', 'webhook'}
check('monitor picked up the new channels', wait_for(lambda: want <= set(monitor_status().get('channels', [])), 150), monitor_status().get('channels'))

# test alert from the page
r = admin.post(PAGE, data={'_glpi_csrf_token': token(admin), 'test': 1})
check('test alert: every channel ok', all(f'{c} ✔' in r.text for c in want), re.findall(r'ส่งทดสอบแล้ว[^<]*', r.text))
hits = received()
by = {h['path']: h['body'] for h in hits}
check('Teams got an Adaptive Card', by.get('/teams', {}).get('attachments', [{}])[0].get('contentType') == 'application/vnd.microsoft.card.adaptive', by.get('/teams'))
check('Google Chat and Slack got text', 'ทดสอบการแจ้งเตือน' in by.get('/gchat', {}).get('text', '') and 'ทดสอบการแจ้งเตือน' in by.get('/slack', {}).get('text', ''))
check('generic webhook got structured JSON', by.get('/generic', {}).get('level') == 'info' and by.get('/generic', {}).get('title'), by.get('/generic'))
check('e-mail arrived', wait_for(lambda: mails('ทดสอบการแจ้งเตือน'), 60))

# a real outage: stop the database
n0 = len(received())
k('scale', 'deploy/mariadb', '--replicas=0')
problem = wait_for(lambda: [h for h in received()[n0:] if h['path'] == '/generic' and h['body']['level'] == 'problem'], 180)
check('database down: "problem" alert within ~3 min', problem, received()[n0:])
if problem:
    lines = ' '.join(problem[0]['body']['lines'])
    check('the alert says what is wrong (database / GLPI)', 'ฐานข้อมูล' in lines or 'GLPI' in lines, lines)
    check('problem alert on every chat channel', {h['path'] for h in received()[n0:] if 'ระบบมีปัญหา' in json.dumps(h['body'], ensure_ascii=False)} >= {'/teams', '/gchat', '/slack', '/generic'})
    check('problem alert by e-mail', wait_for(lambda: mails('ระบบมีปัญหา'), 60))
n1 = len(received())
k('scale', 'deploy/mariadb', '--replicas=1')
k('rollout', 'status', 'deploy/mariadb', '--timeout=180s')
rec = wait_for(lambda: [h for h in received()[n1:] if h['path'] == '/generic' and h['body']['level'] == 'recovered'], 240)
check('database back: "recovered" alert', rec, received()[n1:])
check('no alert storm (one problem + one recovered per channel, not one per check round)',
      len([h for h in received()[n0:] if h['path'] == '/generic']) <= 4, len([h for h in received()[n0:] if h['path'] == '/generic']))

# restore settings, remove the receiver
wait_for(lambda: requests.get(BASE + '/', timeout=10).status_code == 200, 120)
admin = login(*ADMIN)
admin.post(PAGE, data={'_glpi_csrf_token': token(admin), 'save': 1, 'ALERT_EMAILS': before.get('ALERT_EMAILS', ''),
                       'HEARTBEAT_HOUR': before.get('HEARTBEAT_HOUR', ''), 'INTERVAL': before.get('INTERVAL', '60'),
                       'ALERT_TEAMS_URL': '-', 'ALERT_GCHAT_URL': '-', 'ALERT_SLACK_URL': '-', 'ALERT_WEBHOOK_URL': '-',
                       'ALERT_LINE_TOKEN': '', 'ALERT_LINE_TO': before.get('ALERT_LINE_TO', ''),
                       'SMTP_HOST': 'mailpit', 'SMTP_PORT': '1025', 'SMTP_USER': '', 'SMTP_FROM': 'glpi-monitor@dev.glpi.labs'})
k('delete', 'deploy', RCV, '--wait=false')
k('delete', 'service', RCV)
k('delete', 'configmap', RCV)

check.done()
