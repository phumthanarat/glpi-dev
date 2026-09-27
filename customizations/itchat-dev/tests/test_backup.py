"""IT Backup plugin (customizations/itbackup): Setup > Backups page, "Backup now", downloads,
the glpi-backup CronJob, retention, and who may do what. Every backup is checked for real:
sha256 of both archives, the dump is complete and has every table, glpicrypt.key is in it.

Env: ADMIN_USER / ADMIN_PASS (the 'glpi' Super-Admin; install.sh changes its password).
Removes the backups it made.
"""
import gzip
import io
import json
import os
import re
import subprocess
import time

from lib import ADMIN, BASE, Checks, login

check = Checks('backup')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
PAGE = BASE + '/plugins/itbackup/front/backup.php'
DL = BASE + '/plugins/itbackup/front/download.php'
DIR = '/var/lib/glpi-backups'


def pod(cmd):
    return subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'sh', '-c', cmd],
                          capture_output=True, text=True).stdout


def backups():
    out = pod(f'ls -1 {DIR} 2>/dev/null')
    return sorted(n for n in out.split() if re.match(r'^backup-\d{8}-\d{6}-(manual|scheduled)$', n))


def wait_new(before, kind, timeout=300):
    end = time.time() + timeout
    while time.time() < end:
        new = [b for b in backups() if b not in before and b.endswith(kind)]
        if new:
            return new[-1]
        time.sleep(5)
    return None


def verify(name, label):
    m = json.loads(pod(f'cat {DIR}/{name}/manifest.json') or '{}')
    check(f'{label}: manifest status ok', m.get('status') == 'ok', m)
    sums = pod(f'cd {DIR}/{name} && sha256sum database.sql.gz files.tar.gz')
    for f in ('database.sql.gz', 'files.tar.gz'):
        want = m.get('files', {}).get(f, {}).get('sha256')
        check(f'{label}: {f} sha256 matches the manifest', want and f'{want}  {f}' in sums, (want, sums))
    tail = pod(f'gzip -dc {DIR}/{name}/database.sql.gz | tail -c 200')
    check(f'{label}: database dump complete', '-- Dump completed' in tail, tail[-120:])
    creates = int(pod(f'gzip -dc {DIR}/{name}/database.sql.gz | grep -c "^CREATE TABLE"').strip() or 0)
    check(f'{label}: dump has every table ({m.get("tables")})', creates == m.get('tables') and creates > 400, (creates, m.get('tables')))
    listing = pod(f'tar -tzf {DIR}/{name}/files.tar.gz')
    check(f'{label}: files archive has config_db.php + glpicrypt.key + plugins',
          all(p in listing for p in ('config/config_db.php', 'config/glpicrypt.key', 'plugins/itbackup/setup.php')))
    check(f'{label}: no cache/sessions in the files archive', 'files/_cache/' not in listing and 'files/_sessions/' not in listing)
    return m


made = []
admin = login(*ADMIN)
tech = login('itchat.test.tech')

# page + menu + rights
r = admin.get(PAGE)
check('admin opens Setup > Backups', r.status_code == 200 and 'itbackup-list' in r.text, r.status_code)
check('menu entry under Setup', '/plugins/itbackup/front/backup.php' in admin.get(BASE + '/front/central.php').text)
r = tech.get(PAGE)
check('technician may not open it', r.status_code in (403, 302) or 'itbackup-list' not in r.text, r.status_code)
check('no menu entry for a technician', '/plugins/itbackup/front/backup.php' not in tech.get(BASE + '/front/central.php').text)

# "Backup now"
before = backups()
token = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', admin.get(PAGE).text).group(1)
r = admin.post(PAGE, data={'_glpi_csrf_token': token, 'backup_now': 1})
check('"Backup now" answers without error', r.status_code < 400, r.status_code)
manual = wait_new(before, 'manual')
check('"Backup now" makes a backup', manual, backups())
if manual:
    made.append(manual)
    m = verify(manual, 'manual')
    r = admin.get(PAGE)
    check('it is listed on the page', f'data-backup="{manual}"' in r.text)
    check('health shows ok', 'alert-success' in r.text)

    # downloads
    r = admin.get(DL, params={'backup': manual, 'file': 'database.sql.gz'})
    check('admin downloads the database dump', r.status_code == 200 and r.content[:2] == b'\x1f\x8b'
          and len(r.content) == m['files']['database.sql.gz']['size'], (r.status_code, len(r.content)))
    if r.status_code == 200:
        check('downloaded dump is readable SQL', b'CREATE TABLE' in gzip.GzipFile(fileobj=io.BytesIO(r.content)).read(200000))
    r = tech.get(DL, params={'backup': manual, 'file': 'database.sql.gz'})
    check('technician may not download', r.status_code != 200 or r.content[:2] != b'\x1f\x8b', r.status_code)
    for bad in ({'backup': '../../../etc', 'file': 'passwd'}, {'backup': manual, 'file': '../manifest.json'},
                {'backup': manual, 'file': 'manifest.json'}):
        r = admin.get(DL, params=bad)
        check(f'download refused: {bad}', r.status_code == 404 or r.content[:2] != b'\x1f\x8b', r.status_code)

# the CronJob, plus retention: an "old" copy must be pruned by the next backup
if manual:
    old = 'backup-20200101-000000-manual'
    pod(f'cp -r {DIR}/{manual} {DIR}/{old} && sed -i \'s/"created": "[^"]*"/"created": "2020-01-01T00:00:00+07:00"/\' {DIR}/{old}/manifest.json')
before = backups()
job = f'glpi-backup-test-{int(time.time())}'
subprocess.run(['kubectl', '-n', NS, 'create', 'job', job, '--from=cronjob/glpi-backup'], capture_output=True)
ok = subprocess.run(['kubectl', '-n', NS, 'wait', '--for=condition=complete', f'job/{job}', '--timeout=600s'],
                    capture_output=True).returncode == 0
check('glpi-backup CronJob run succeeds', ok, subprocess.run(['kubectl', '-n', NS, 'logs', f'job/{job}', '--tail=5'],
                                                             capture_output=True, text=True).stdout)
scheduled = wait_new(before, 'scheduled', timeout=60)
check('CronJob made a scheduled backup', scheduled)
if scheduled:
    made.append(scheduled)
    verify(scheduled, 'scheduled')
if manual:
    check('backup older than keep_days pruned', old not in backups(), backups())
subprocess.run(['kubectl', '-n', NS, 'delete', 'job', job, '--wait=false'], capture_output=True)

# delete from the page
if made:
    token = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', admin.get(PAGE).text).group(1)
    r = admin.post(PAGE, data={'_glpi_csrf_token': token, 'delete': made[0]})
    check('delete from the page', r.status_code < 400 and made[0] not in backups(), (r.status_code, backups()))
    r = tech.post(PAGE, data={'_glpi_csrf_token': token, 'delete': made[-1]})
    check('technician may not delete', made[-1] in backups(), backups())
for b in made:
    pod(f'rm -rf {DIR}/{b}')
pod(f'rm -rf {DIR}/backup-20200101-000000-manual')

check.done()
