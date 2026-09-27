"""IT HTTPS (customizations/ithttps) end to end through the real Ingress (Kong on :443):
the internal certificate is served and verifies against the CA downloaded from the page,
HTTP redirects to HTTPS, the session cookie is Secure over HTTPS, uploading the organisation's
certificate (.crt+.key and .pfx) really changes what Kong serves, bad uploads are refused,
the renewal job works, and the pods can touch no other Secret.

Env: TLS_HOST (default dev.glpi.labs), INGRESS_IP (default 127.0.0.1). Leaves an internal
certificate in place at the end.
"""
import os
import re
import socket
import ssl
import subprocess
import tempfile
import time

import requests

from lib import ADMIN, BASE, Checks, login

check = Checks('https')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
HOST = os.environ.get('TLS_HOST', 'dev.glpi.labs')
IP = os.environ.get('INGRESS_IP', '127.0.0.1')
PAGE = BASE + '/plugins/ithttps/front/https.php'
TMP = tempfile.mkdtemp()


def sh(cmd, stdin=None):
    return subprocess.run(cmd, shell=True, input=stdin, capture_output=True, text=True)


def served_pem():
    """The certificate Kong presents for HOST (SNI), whatever it is."""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    with socket.create_connection((IP, 443), timeout=10) as s, ctx.wrap_socket(s, server_hostname=HOST) as t:
        return ssl.DER_cert_to_PEM_cert(t.getpeercert(binary_form=True))


def fingerprint(pem):
    return sh('openssl x509 -noout -fingerprint -sha256', pem).stdout.split('=')[-1].strip()


def issuer(pem):
    return sh('openssl x509 -noout -issuer', pem).stdout


def wait_served(pred, timeout=150):
    end = time.time() + timeout
    while time.time() < end:
        try:
            pem = served_pem()
            if pred(pem):
                return pem
        except OSError:
            pass
        time.sleep(5)
    return None


def verifies(cafile):
    """Full TLS verification (chain + host name) against a CA file."""
    ctx = ssl.create_default_context(cafile=cafile)
    try:
        with socket.create_connection((IP, 443), timeout=10) as s, ctx.wrap_socket(s, server_hostname=HOST):
            return True
    except ssl.SSLError as e:
        return str(e)


def token(s):
    return re.search(r'name="_glpi_csrf_token" value="([^"]+)"', s.get(PAGE).text).group(1)


def flash(r):
    return ' '.join(re.findall(r'(?:ใช้ certificate|ออกใบรับรอง|certificate|private key|ไฟล์|เปิดไฟล์)[^<]{0,160}', r.text))[:400]


def make_cert(name, cn, days=30, key_of=None):
    """A test 'company' CA + certificate for cn. Returns (crt_path, key_path, ca_path)."""
    d = f'{TMP}/{name}'
    os.makedirs(d, exist_ok=True)
    if not os.path.exists(f'{TMP}/ca.crt'):
        sh(f'openssl req -x509 -newkey rsa:2048 -nodes -keyout {TMP}/ca.key -out {TMP}/ca.crt -days 30 -subj "/CN=Test Company CA"'
           ' -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign')
    sh(f'openssl req -newkey rsa:2048 -nodes -keyout {d}/k.key -out {d}/r.csr -subj "/CN={cn}"')
    ext = f'{d}/ext.cnf'
    open(ext, 'w').write(f'subjectAltName=DNS:{cn}\nextendedKeyUsage=serverAuth\n')
    if days < 0:  # already expired
        sh(f'openssl x509 -req -in {d}/r.csr -CA {TMP}/ca.crt -CAkey {TMP}/ca.key -CAcreateserial -out {d}/c.crt '
           f'-extfile {ext} -not_before 20200101000000Z -not_after 20200201000000Z')
    else:
        sh(f'openssl x509 -req -in {d}/r.csr -CA {TMP}/ca.crt -CAkey {TMP}/ca.key -CAcreateserial -out {d}/c.crt -days {days} -extfile {ext}')
    return f'{d}/c.crt', (key_of or f'{d}/k.key'), f'{TMP}/ca.crt'


def upload(s, crt=None, key=None, pfx=None, pfx_password=''):
    files = {}
    if crt:
        files['crt'] = ('cert.crt', open(crt, 'rb'))
        files['key'] = ('cert.key', open(key, 'rb'))
    if pfx:
        files['pfx'] = ('cert.pfx', open(pfx, 'rb'))
    return s.post(PAGE, data={'_glpi_csrf_token': token(s), 'upload': 1, 'hosts': HOST, 'pfx_password': pfx_password}, files=files)


admin = login(*ADMIN)
tech = login('itchat.test.tech')

# page + rights
r = admin.get(PAGE)
check('admin opens Setup > HTTPS', r.status_code == 200 and 'ithttps-upload' in r.text, r.status_code)
check('menu entry under Setup', '/plugins/ithttps/front/https.php' in admin.get(BASE + '/front/central.php').text)
check('technician may not open it', 'ithttps-upload' not in tech.get(PAGE).text)
check('technician may not download the CA', not tech.get(BASE + '/plugins/ithttps/front/ca.php').text.startswith('-----BEGIN'))

# the internal certificate (setup-23 at install)
pem = served_pem()
check('Ingress serves the internal certificate for the host', 'ITSM Internal CA' in issuer(pem), issuer(pem))
ca = admin.get(BASE + '/plugins/ithttps/front/ca.php')
check('CA certificate downloadable from the page', ca.status_code == 200 and ca.text.startswith('-----BEGIN CERTIFICATE'), ca.status_code)
open(f'{TMP}/itsm-ca.crt', 'w').write(ca.text)
check('TLS verifies against the downloaded CA (chain + host name)', verifies(f'{TMP}/itsm-ca.crt') is True, verifies(f'{TMP}/itsm-ca.crt'))
check('page health ok', 'alert-success' in admin.get(PAGE).text)

# HTTP -> HTTPS, Secure cookie over HTTPS, NodePort HTTP still works (dev)
r = requests.get(f'http://{IP}/', headers={'Host': HOST}, allow_redirects=False, timeout=10)
check('HTTP redirects to HTTPS (301)', r.status_code == 301 and r.headers.get('Location', '').startswith(f'https://{HOST}'), (r.status_code, r.headers.get('Location')))
hs = requests.Session()
hs.verify = f'{TMP}/itsm-ca.crt'
url = f'https://{HOST}'
hs.mount(url, requests.adapters.HTTPAdapter())
# resolve HOST to the Ingress IP for this session
_orig = socket.getaddrinfo
socket.getaddrinfo = lambda h, *a, **k: _orig(IP if h == HOST else h, *a, **k)
try:
    page = hs.get(url + '/').text
    tok = re.search(r'name="_glpi_csrf_token" value="([^"]+)"', page).group(1)
    r = hs.post(url + '/front/login.php', data={'login_name': ADMIN[0], 'login_password': ADMIN[1], '_glpi_csrf_token': tok, 'noAUTO': 1}, allow_redirects=False)
    cookies = [c for c in hs.cookies if c.name.startswith('glpi_')]
    check('login over HTTPS works', r.status_code in (302, 303) and 'central' in hs.get(url + '/front/central.php').url, r.status_code)
    check('session cookie is Secure over HTTPS', cookies and all(c.secure for c in cookies), [(c.name, c.secure) for c in cookies])
finally:
    socket.getaddrinfo = _orig
check('dev NodePort (plain HTTP) login still works', 'central' in login(*ADMIN).get(BASE + '/front/central.php').url)

# upload the organisation's certificate: .crt + .key
crt, key, company_ca = make_cert('good', HOST)
r = upload(admin, crt, key)
check('upload .crt + .key accepted', 'ใช้ certificate ที่อัปโหลดแล้ว' in r.text, flash(r))
want = fingerprint(open(crt).read())
check('Kong now serves the uploaded certificate', wait_served(lambda p: fingerprint(p) == want), want)
check('TLS verifies against the company CA', verifies(company_ca) is True, verifies(company_ca))
check('page shows mode "uploaded"', 'อัปโหลดเอง' in admin.get(PAGE).text)

# refused uploads - the served certificate must not change
for label, (c, k, *_), expect in [
    ('key of another certificate', make_cert('other', HOST)[:1] + (make_cert('mismatch', HOST)[1],), 'ไม่ตรงกับ'),
    ('expired certificate', make_cert('old', HOST, days=-1), 'หมดอายุ'),
    ('certificate for another name', make_cert('wrongname', 'other.example.com'), 'ไม่ครอบคลุม'),
]:
    r = upload(admin, c, k)
    check(f'refused: {label}', expect in r.text and 'ใช้ certificate ที่อัปโหลดแล้ว' not in r.text, flash(r))
check('refused uploads did not change the served certificate', fingerprint(served_pem()) == want)

# .pfx with a password
c2, k2, _ = make_cert('pfx', HOST)
sh(f'openssl pkcs12 -export -in {c2} -inkey {k2} -certfile {company_ca} -out {TMP}/c.pfx -passout pass:P@ssw0rd-x')
r = upload(admin, pfx=f'{TMP}/c.pfx', pfx_password='wrong')
check('refused: .pfx with a wrong password', 'เปิดไฟล์ .pfx ไม่ได้' in r.text, flash(r))
r = upload(admin, pfx=f'{TMP}/c.pfx', pfx_password='P@ssw0rd-x')
check('upload .pfx accepted', 'ใช้ certificate ที่อัปโหลดแล้ว' in r.text, flash(r))
want2 = fingerprint(open(c2).read())
check('Kong now serves the .pfx certificate', wait_served(lambda p: fingerprint(p) == want2), want2)
check('private key never shown on the page', 'PRIVATE KEY' not in admin.get(PAGE).text)

# back to the internal CA; the renewal job re-issues when the names change
r = admin.post(PAGE, data={'_glpi_csrf_token': token(admin), 'internal': 1, 'hosts': HOST})
check('switch back to the internal certificate', 'ออกใบรับรองภายในแล้ว' in r.text, flash(r))
check('Kong serves the internal certificate again', wait_served(lambda p: 'ITSM Internal CA' in issuer(p)))
renew = sh(f"""kubectl -n {NS} exec {POD} -c {CONTAINER} -- php -r '
chdir("/var/www/glpi"); require "vendor/autoload.php"; $k = new \\Glpi\\Kernel\\Kernel(); $k->boot();
\\GlpiPlugin\\Ithttps\\Tls::setConfig(["hosts" => ["{HOST}", "extra.{HOST}"]]);
$t = new CronTask(); $t->getFromDBbyName(\\GlpiPlugin\\Ithttps\\Tls::class, "ithttpsRenew");
echo \\GlpiPlugin\\Ithttps\\Tls::cronIthttpsRenew($t);'""")
check('renewal job re-issues when the names change', renew.stdout.strip() == '1', renew.stdout + renew.stderr)
pem = wait_served(lambda p: f'extra.{HOST}' in sh('openssl x509 -noout -ext subjectAltName', p).stdout)
check('renewed certificate served with the new name', pem)
r = admin.post(PAGE, data={'_glpi_csrf_token': token(admin), 'internal': 1, 'hosts': HOST})
check('renewal job does nothing when not needed', sh(f"""kubectl -n {NS} exec {POD} -c {CONTAINER} -- php -r '
chdir("/var/www/glpi"); require "vendor/autoload.php"; $k = new \\Glpi\\Kernel\\Kernel(); $k->boot();
$t = new CronTask(); $t->getFromDBbyName(\\GlpiPlugin\\Ithttps\\Tls::class, "ithttpsRenew");
echo \\GlpiPlugin\\Ithttps\\Tls::cronIthttpsRenew($t);'""").stdout.strip() == '0')

# the pods' ServiceAccount: these two Secrets only
probe_script = ('T=$(cat /var/run/secrets/kubernetes.io/serviceaccount/token); '
                'for s in db-external mariadb-local glpi-tls; do '
                'printf "%s " $s; curl -s -o /dev/null -w "%{http_code}\\n" '
                '--cacert /var/run/secrets/kubernetes.io/serviceaccount/ca.crt -H "Authorization: Bearer $T" '
                f'https://$KUBERNETES_SERVICE_HOST/api/v1/namespaces/{NS}/secrets/$s; done')
probe = subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'sh', '-c', probe_script],
                       capture_output=True, text=True).stdout
check('pods cannot read the database Secrets', 'db-external 403' in probe and 'mariadb-local 403' in probe, probe)
check('pods can read the certificate Secret', 'glpi-tls 200' in probe, probe)

check.done()
