"""Shared helpers for the itchat test suite (see run.sh).

Credentials come from the environment, set by run.sh from fixtures.php:
  GLPI_URL           base URL (default http://localhost:30080)
  ITCHAT_TEST_CREDS  JSON {"itchat.test.user1": "...", "itchat.test.user2": "...", "itchat.test.tech": "..."}
  ADMIN_USER/ADMIN_PASS  a Super-Admin, only for the config-page checks (default glpi/glpi)
"""
import json
import os
import re
import struct
import sys
import zlib

import requests

BASE = os.environ.get('GLPI_URL', 'http://localhost:30080').rstrip('/')
API = BASE + '/plugins/itchat/ajax/chat.php'
CREDS = json.loads(os.environ.get('ITCHAT_TEST_CREDS', '{}'))
ADMIN = (os.environ.get('ADMIN_USER', 'glpi'), os.environ.get('ADMIN_PASS', 'glpi'))
PREFIX = '[itchat-test]'


class Session(requests.Session):
    csrf = ''
    login_name = ''


def login(name, password=None):
    password = password if password is not None else CREDS[name]
    s = Session()
    s.login_name = name
    page = s.get(BASE + '/').text
    token = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', page).group(1)
    s.post(BASE + '/front/login.php', data={
        'login_name': name, 'login_password': password, '_glpi_csrf_token': token, 'noAUTO': 1,
    })
    page = s.get(BASE + '/front/central.php').text
    if 'glpi:csrf_token' not in page:
        page = s.get(BASE + '/Helpdesk').text
    m = re.search(r'<meta property="glpi:csrf_token" content="([^"]+)"', page)
    if not m:
        raise RuntimeError(f'login failed for {name}')
    s.csrf = m.group(1)
    return s


def _json(r):
    try:
        return r.json()
    except ValueError:
        return r.text[:200]


def poll(s, open=1, **kw):
    kw.update(action='poll', open=open)
    r = s.get(API, params=kw, headers={'X-Requested-With': 'XMLHttpRequest'})
    return r.status_code, _json(r)


def post(s, csrf=True, files=None, **data):
    h = {'X-Requested-With': 'XMLHttpRequest'}
    if csrf:
        h['X-Glpi-Csrf-Token'] = s.csrf
    r = s.post(API, data=data, files=files, headers=h)
    return r.status_code, _json(r)


def get(s, **params):
    return s.get(API, params=params, headers={'X-Requested-With': 'XMLHttpRequest'})


def upload(s, conv, name, data, mime='application/octet-stream'):
    return post(s, action='send', conv=conv, files={'file': (name, data, mime)})


def close_open_chat(s):
    """Requester side: close whatever chat is currently open so the next send starts fresh."""
    c, r = poll(s, open=0)
    if isinstance(r, dict) and r.get('conv') and r['conv']['status'] == 'open':
        post(s, action='close', conv=r['conv']['id'])


def png(rgb=(120, 160, 220), w=64, h=48):
    raw = b''.join(b'\0' + bytes(rgb) * w for _ in range(h))

    def chunk(t, d):
        return struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


PDF = (b'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>'
       b'endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n')


class Checks:
    """Collects PASS/FAIL lines; exit code 1 if anything failed."""

    def __init__(self, suite):
        self.suite, self.results = suite, []

    def __call__(self, name, ok, detail=''):
        ok = bool(ok)
        self.results.append((name, ok, detail))
        print(('PASS ' if ok else 'FAIL ') + name + ('' if ok else f'  -> {detail}'), flush=True)
        return ok

    def done(self):
        failed = [r for r in self.results if not r[1]]
        print(f'\n[{self.suite}] {len(self.results) - len(failed)}/{len(self.results)} passed', flush=True)
        sys.exit(1 if failed else 0)


def inspect_tickets(ids):
    """Ticket facts via fixtures.php inside the pod (requesters, groups, approvers, ...)."""
    import subprocess
    ns, pod = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD']
    out = subprocess.run(
        ['kubectl', '-n', ns, 'exec', pod, '-c', os.environ.get('CONTAINER', 'glpi-app'), '--',
         'php', '/tmp/itchat-tests/fixtures.php', 'inspect', ','.join(map(str, ids))],
        capture_output=True, text=True, check=True).stdout
    return {int(k): v for k, v in json.loads(out.strip().splitlines()[-1]).items()}
