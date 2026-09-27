"""IT Security (customizations/itsecurity) + setup-24: 2FA and the idle timeout, as users meet them.

2FA: technicians must answer the TOTP prompt (a wrong code is refused), self-service users are
not asked, a technician without 2FA gets the setup page and may skip it only during the grace
period, "reset 2FA" on the page works. Idle timeout: an idle session is closed (also when a
background widget keeps polling), an active one is not. Plus GLPI's /status.php (vhost fix).
Leaves the settings as they were.
"""
import json
import os
import re
import subprocess
import time

import requests

from lib import ADMIN, BASE, CREDS, Checks, login
from totp import code, fresh_code

check = Checks('login-security')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
PAGE = BASE + '/plugins/itsecurity/front/security.php'


def fixtures(*args):
    out = subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', *args],
                         capture_output=True, text=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def password_step(user):
    """Log in with the password only; returns (session, response after the password)."""
    s = requests.Session()
    page = s.get(BASE + '/').text
    tok = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', page).group(1)
    r = s.post(BASE + '/front/login.php', data={'login_name': user, 'login_password': CREDS[user], '_glpi_csrf_token': tok, 'noAUTO': 1})
    return s, r


def logged_in(s):
    r = s.get(BASE + '/front/central.php')
    return r.status_code == 200 and 'glpi:csrf_token' in r.text and '/front/central.php' in r.url


def token_of(text):
    return re.search(r'name="_glpi_csrf_token" value="([^"]+)"', text).group(1)


admin = login(*ADMIN)
page = admin.get(PAGE).text
check('admin opens Setup > Security', 'itsecurity-settings' in page)
check('technician may not open it', 'itsecurity-settings' not in login('itchat.test.tech').get(PAGE).text)
before = {
    'idle': re.search(r'name="idle_minutes" value="(\d+)"', page).group(1),
    'grace': re.search(r'name="grace_days" value="(\d+)"', page).group(1),
    'profiles': re.findall(r'name="tfa_profiles\[\]" value="(\d+)" checked', page),
}
check('setup-24 enforces 2FA for Technician by default', re.search(r'value="\d+" checked>\s*<span class="form-check-label">Technician', page))

# --- 2FA
s, r = password_step('itchat.test.tech')
check('technician: password alone leads to the 2FA prompt', '/MFA/Prompt' in r.url, r.url)
bad = str((int(code(json.loads(os.environ['ITCHAT_TEST_CREDS'])['_totp']['itchat.test.tech'])) + 1) % 1000000).zfill(6)
s.post(BASE + '/MFA/Verify', data={'totp_code': bad, '_glpi_csrf_token': token_of(r.text)})
check('technician: a wrong code is refused', not logged_in(s))
s, r = password_step('itchat.test.tech')
s.post(BASE + '/MFA/Verify', data={'totp_code': fresh_code(CREDS['_totp']['itchat.test.tech']), '_glpi_csrf_token': token_of(r.text)})
check('technician: the right code logs in', logged_in(s))
s, r = password_step('itchat.test.user1')
check('self-service user: no 2FA asked', '/MFA/' not in r.url and 'glpi:csrf_token' in s.get(BASE + '/Helpdesk').text, r.url)

# reset 2FA from the page -> setup page, skippable during the grace period
uid = fixtures('user-ids', 'itchat.test.tech2')['itchat.test.tech2']
r = admin.post(PAGE, data={'_glpi_csrf_token': token_of(admin.get(PAGE).text), 'reset_2fa': uid})
check('reset 2FA from the page', 'รีเซ็ต 2FA ของ itchat.test.tech2 แล้ว' in r.text)
row = re.search(r'data-user="itchat.test.tech2".*?</tr>', admin.get(PAGE).text, re.S)
check('page lists tech2 as "not set up"', row and 'ยังไม่ตั้ง' in row.group(0))
s, r = password_step('itchat.test.tech2')
check('not enrolled + enforced: setup page (QR code) instead of the prompt', '/MFA/Setup' in r.url, r.url)
check('during the grace period the setup page offers "Skip"', 'name="skip_mfa"' in r.text)
s.post(BASE + '/MFA/Verify', data={'skip_mfa': 1, '_glpi_csrf_token': token_of(r.text)})
check('skipping during the grace period logs in', logged_in(s))
CREDS['_totp']['itchat.test.tech2'] = fixtures('totp-set', 'itchat.test.tech2')['secret']
os.environ['ITCHAT_TEST_CREDS'] = json.dumps(CREDS)

# --- idle timeout: 1 minute for the test
r = admin.post(PAGE, data={'_glpi_csrf_token': token_of(admin.get(PAGE).text), 'save': 1, 'idle_minutes': 1,
                           'grace_days': before['grace'], 'tfa_profiles[]': before['profiles']})
check('idle timeout saved', 'value="1"' in admin.get(PAGE).text)
idle = login('itchat.test.tech')         # does nothing
polling = login('itchat.test.tech')      # only a background widget polling (XHR GET)
active = login('itchat.test.tech')       # clicks around
check('three technician sessions logged in', all(logged_in(x) for x in (idle, polling, active)))
end = time.time() + 75
while time.time() < end:
    polling.get(BASE + '/plugins/itchat/ajax/chat.php', params={'action': 'poll', 'open': 0}, headers={'X-Requested-With': 'XMLHttpRequest'})
    active.get(BASE + '/front/ticket.php')
    admin.get(PAGE)
    time.sleep(15)
r = idle.get(BASE + '/front/central.php')
check('idle session closed after the timeout (back to login, "session expired")', not logged_in(idle) and ('error=3' in r.url or 'login_name' in r.text), r.url)
check('background polling does not keep a session alive', not logged_in(polling))
check('an active session stays logged in', logged_in(active))
r = idle.get(BASE + '/plugins/itchat/ajax/chat.php', params={'action': 'poll', 'open': 0}, headers={'X-Requested-With': 'XMLHttpRequest'})
check('expired session: background requests get 401, not a page', r.status_code in (401, 403), r.status_code)

# restore
admin.post(PAGE, data={'_glpi_csrf_token': token_of(admin.get(PAGE).text), 'save': 1, 'idle_minutes': before['idle'],
                       'grace_days': before['grace'], 'tfa_profiles[]': before['profiles']})
check('settings restored', f'name="idle_minutes" value="{before["idle"]}"' in admin.get(PAGE).text)

# --- GLPI status endpoint (vhost now routes *.php that aren't files to GLPI)
r = requests.get(BASE + '/status.php', headers={'Accept': 'application/json'}, timeout=15)
body = r.json() if r.headers.get('content-type', '').startswith('application/json') else {}
check('GET /status.php answers GLPI\'s status (JSON)', r.status_code == 200 and 'glpi' in body, (r.status_code, r.text[:120]))
check('status: database OK', body.get('db', {}).get('status') == 'OK', body.get('db'))
check('status: cron tasks OK (none stuck)', body.get('crontasks', {}).get('status') == 'OK', body.get('crontasks'))

check.done()
