"""IT Backup: copy to a Windows file share (SMB), against a real SMB server (Samba in a pod,
what a Windows share speaks). Settings from the page, connection test, copies checked by
sha256 on the share, a share that goes down then comes back, retention on the share, and the
password never shown nor stored in clear.

Leaves the backup settings as they were and removes the test share + its backups.
"""
import json
import os
import re
import secrets
import subprocess
import time

from lib import ADMIN, BASE, Checks, login

check = Checks('backup-remote')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
PAGE = BASE + '/plugins/itbackup/front/backup.php'
DIR = '/var/lib/glpi-backups'
SMB = 'itbackup-test-smb'
SMB_PASS = 'Sh@re-' + secrets.token_hex(6)
SUB = 'glpi/itsm'


def k(*args, stdin=None):
    return subprocess.run(['kubectl', '-n', NS, *args], input=stdin, capture_output=True, text=True)


def pod(cmd):
    return k('exec', POD, '-c', CONTAINER, '--', 'sh', '-c', cmd).stdout


def smb(cmd):
    return k('exec', f'deploy/{SMB}', '--', 'sh', '-c', cmd).stdout


def backups():
    return sorted(n for n in pod(f'ls -1 {DIR}').split() if re.match(r'^backup-\d{8}-\d{6}-(manual|scheduled)$', n))


def manifest(name):
    return json.loads(pod(f'cat {DIR}/{name}/manifest.json') or '{}')


def token(s):
    return re.search(r'name="_glpi_csrf_token" value="([^"]+)"', s.get(PAGE).text).group(1)


def post(s, **data):
    return s.post(PAGE, data={'_glpi_csrf_token': token(s), **data})


def settings(**over):
    base = {'remote_enabled': 1, 'smb_host': SMB, 'smb_share': 'backup', 'smb_path': SUB, 'smb_domain': '',
            'smb_user': 'glpisvc', 'smb_pass': SMB_PASS}
    base.update(over)
    return base


def wait_for(fn, timeout=240):
    end = time.time() + timeout
    while time.time() < end:
        v = fn()
        if v:
            return v
        time.sleep(4)
    return None


def wait_idle(s):
    return wait_for(lambda: 'spinner-border' not in s.get(PAGE).text, 300)


def backup_now(s):
    before = set(backups())
    post(s, backup_now=1)
    new = wait_for(lambda: sorted(set(backups()) - before), 300) or []
    time.sleep(2)
    wait_idle(s)  # the copy to the share runs after the backup is listed
    wait_for(lambda: pod(f'test -f {DIR}/.lock && flock -n {DIR}/.lock true && echo free'), 300)
    return new


# a Windows-style share: \\itbackup-test-smb\backup, user glpisvc
k('apply', '-f', '-', stdin=f"""
apiVersion: apps/v1
kind: Deployment
metadata: {{ name: {SMB}, labels: {{ app: {SMB} }} }}
spec:
  replicas: 1
  selector: {{ matchLabels: {{ app: {SMB} }} }}
  template:
    metadata: {{ labels: {{ app: {SMB} }} }}
    spec:
      containers:
        - name: samba
          image: dockurr/samba:latest
          imagePullPolicy: IfNotPresent
          env: [{{ name: NAME, value: backup }}, {{ name: USER, value: glpisvc }}, {{ name: PASS, value: "{SMB_PASS}" }}]
          ports: [{{ containerPort: 445 }}]
          readinessProbe: {{ tcpSocket: {{ port: 445 }}, periodSeconds: 3 }}
---
apiVersion: v1
kind: Service
metadata: {{ name: {SMB} }}
spec: {{ selector: {{ app: {SMB} }}, ports: [{{ port: 445 }}] }}
""")
check('test file share is up', k('rollout', 'status', f'deploy/{SMB}', '--timeout=180s').returncode == 0)

admin = login(*ADMIN)
before_cfg = {m[0]: m[1] for m in re.findall(r'name="(smb_\w+)" value="([^"]*)"', admin.get(PAGE).text)}
local_before = set(backups())

# 1. wrong password -> the test says so, nothing is enabled silently
r = post(admin, test_remote=1, **settings(smb_pass='wrong-password'))
page = admin.get(PAGE).text
check('connection test with a wrong password fails', 'แต่เชื่อมต่อไม่ได้' in r.text, re.findall(r'บันทึกแล้ว[^<]{0,160}', r.text))

# 2. right password -> connection test passes
r = post(admin, test_remote=1, **settings())
check('connection test passes (write / read / delete)', 'ลบไฟล์ทดสอบผ่าน' in r.text, re.findall(r'บันทึกแล้ว[^<]{0,160}', r.text))
check('test file removed from the share', not smb(f'ls -A /shared/{SUB} 2>/dev/null').strip())
page = admin.get(PAGE).text
check('password never shown on the page', SMB_PASS not in page)
stored = subprocess.run(['kubectl', '-n', NS, 'exec', 'deploy/mariadb', '--', 'sh', '-c',
                         'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" glpi -N -e "select value from glpi_configs where context=\'plugin:itbackup\' and name=\'smb_pass\'"'],
                        capture_output=True, text=True).stdout.strip()
check('password stored encrypted', stored and SMB_PASS not in stored, stored[:20])
# empty password field keeps the stored one
post(admin, save_remote=1, **settings(smb_pass=''))
r = post(admin, test_remote=1, **settings(smb_pass=''))
check('saving with an empty password field keeps the password', 'ลบไฟล์ทดสอบผ่าน' in r.text, re.findall(r'บันทึกแล้ว[^<]{0,160}', r.text))

# 3. Backup now -> on the share, identical
new = backup_now(admin)
check('Backup now made a backup', new, backups())
if new:
    b = new[-1]
    m = manifest(b)
    check('manifest: copied to the file share', m.get('remote', {}).get('status') == 'ok', m.get('remote'))
    on_share = smb(f'cd /shared/{SUB}/{b} && sha256sum database.sql.gz files.tar.gz manifest.json')
    for f in ('database.sql.gz', 'files.tar.gz'):
        check(f'{f} on the share is identical (sha256)', f"{m['files'][f]['sha256']}  {f}" in on_share, on_share)
    page = admin.get(PAGE).text
    check('page: "copy แล้ว" on that backup', re.search(rf'data-backup="{b}".*?copy แล้ว', page, re.S))
    check('page: health ok mentions the share', 'alert-success' in page and 'backup' in page)

# 4. share down -> local backup still made, red on the page; back up -> catch up
k('scale', f'deploy/{SMB}', '--replicas=0')
wait_for(lambda: not k('get', 'pods', '-l', f'app={SMB}', '-o', 'name').stdout.strip(), 120)
down = backup_now(admin)
check('share down: local backup still made', down, backups())
if down:
    check('share down: backup marked "copy failed"', manifest(down[-1]).get('remote', {}).get('status') == 'failed', manifest(down[-1]).get('remote'))
    page = admin.get(PAGE).text
    check('share down: page health is red and says why', 'alert-danger' in page and 'file share' in page)
k('scale', f'deploy/{SMB}', '--replicas=1')
k('rollout', 'status', f'deploy/{SMB}', '--timeout=180s')
if down:
    # retention on the share: an "old" backup there must be pruned
    # (made as root inside the share's container: give it to the share's owner, like a file
    # the service account would have written)
    smb(f'mkdir -p /shared/{SUB}/backup-20200101-000000-manual && echo x > /shared/{SUB}/backup-20200101-000000-manual/manifest.json'
        f' && chown -R $(stat -c %u:%g /shared) /shared/glpi')
    post(admin, sync_remote=1)
    wait_for(lambda: manifest(down[-1]).get('remote', {}).get('status') == 'ok', 240)
    wait_for(lambda: pod(f'flock -n {DIR}/.lock true && echo free'), 240)
    check('share back: "copy ที่ค้าง" catches up the failed one', manifest(down[-1]).get('remote', {}).get('status') == 'ok', manifest(down[-1]).get('remote'))
    check('share back: health green again', 'alert-success' in admin.get(PAGE).text)
    check('old backup on the share pruned (keep_days)', 'backup-20200101-000000-manual' not in smb(f'ls /shared/{SUB}'))

# 5. a technician can't change it
tech = login('itchat.test.tech')
r = tech.post(PAGE, data={'_glpi_csrf_token': 'x', 'save_remote': 1, **settings(smb_host='evil.example.com')})
check('technician may not change the file share', 'evil.example.com' not in admin.get(PAGE).text)

# restore: previous settings, remove test backups + share
post(admin, save_remote=1, remote_enabled=0,  # the test turns it off again
     smb_host=before_cfg.get('smb_host') or SMB, smb_share=before_cfg.get('smb_share') or 'backup',
     smb_path=before_cfg.get('smb_path') or SUB, smb_domain=before_cfg.get('smb_domain', ''),
     smb_user=before_cfg.get('smb_user') or 'glpisvc', smb_pass='')
if not before_cfg.get('smb_host'):
    subprocess.run(['kubectl', '-n', NS, 'exec', 'deploy/mariadb', '--', 'sh', '-c',
                    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" glpi -e "delete from glpi_configs where context=\'plugin:itbackup\' and name in (\'remote_enabled\',\'smb_host\',\'smb_share\',\'smb_path\',\'smb_domain\',\'smb_user\',\'smb_pass\')"'],
                   capture_output=True)
for b in set(backups()) - local_before:
    pod(f'rm -rf {DIR}/{b}')
# backups that were there before: forget they were copied to the (now deleted) test share
for b in local_before:
    pod(f"php -r '$f=\"{DIR}/{b}/manifest.json\"; $m=json_decode(file_get_contents($f),true); unset($m[\"remote\"]);"
        f" file_put_contents($f, json_encode($m, JSON_PRETTY_PRINT).\"\\n\");'")
pod(f'rm -f {DIR}/remote-status.json')
k('delete', 'deploy', SMB, '--wait=false')
k('delete', 'service', SMB)

check.done()
