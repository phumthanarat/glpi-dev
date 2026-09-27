"""Browser part of test_qr.py (Playwright image): QR labels (itqr plugin) as people use them.

- technician: the asset's "QR แจ้งปัญหา" tab and the printable labels page
- users on a phone-sized screen: open a label's URL while logged out -> log in -> report page for
  that device -> describe, pick urgency, attach a photo -> submit -> their new ticket
- a tampered label is refused without revealing the device

Env: GLPI_URL, ITCHAT_TEST_CREDS, QR (JSON from `fixtures.php qr-assets`), SHOTS.
Prints one JSON line with observations.
"""
import json
import os
import re

from playwright.sync_api import sync_playwright

BASE = os.environ['GLPI_URL'].rstrip('/')
CREDS = json.loads(os.environ['ITCHAT_TEST_CREDS'])
QR = json.loads(os.environ['QR'])
SHOTS = os.environ.get('SHOTS', 'shots')
os.makedirs(SHOTS, exist_ok=True)
out = {'errors': []}
PHONE = {'width': 390, 'height': 844}


def local(url):
    """Labels carry GLPI's url_base (localhost:30080); the browser runs in a container."""
    return re.sub(r'^https?://[^/]+', BASE, url)


def login(page, user):
    page.fill('input[name="login_name"]', user)
    page.fill('input[name="login_password"]', CREDS[user])
    page.press('input[name="login_password"]', 'Enter')
    page.wait_for_load_state('networkidle')


def shot(page, name):
    page.screenshot(path=os.path.join(SHOTS, f'qr-{name}.png'), full_page=True)


def png(w=64, h=48, rgb=(200, 90, 60)):
    """A small real PNG (a phone photo stand-in)."""
    import struct
    import zlib
    raw = b''.join(b'\x00' + bytes(rgb) * w for _ in range(h))
    chunk = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b'')


def report(browser, user, url, *, content, urgency=None, title=None, photo=False, name):
    ctx = browser.new_context(viewport=PHONE, is_mobile=True, has_touch=True)
    page = ctx.new_page()
    page.on('pageerror', lambda e: out['errors'].append(f'{name}: {e}'))
    r = {}
    try:
        page.goto(local(url))
        page.wait_for_load_state('networkidle')
        r['login_asked'] = page.locator('input[name="login_name"]').count() > 0
        if r['login_asked']:
            login(page, user)
        r['back_on_report_page'] = '/plugins/itqr/front/report.php' in page.url
        if not r['back_on_report_page']:  # GLPI didn't bring the user back: scan again, now logged in
            page.goto(local(url))
            page.wait_for_load_state('networkidle')
        r['device_shown'] = page.locator('.itqr-device-name').inner_text().strip()
        r['open_hint'] = page.locator('.itqr-open-hint').inner_text().strip() if page.locator('.itqr-open-hint').count() else None
        r['page_overflows_phone'] = page.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1')
        page.fill('#itqr-content', content)
        if title:
            page.fill('#itqr-name', title)
        if urgency:
            page.check(f'input[name="urgency"][value="{urgency}"]')
        if photo:
            page.set_input_files('#itqr-photo', files=[{'name': 'jam.png', 'mimeType': 'image/png', 'buffer': png()}])
        shot(page, f'{name}-filled')
        page.click('button[name="report"]')
        page.wait_for_load_state('networkidle')
        m = re.search(r'ticket\.form\.php\?id=(\d+)', page.url)
        r['ticket'] = int(m.group(1)) if m else None
        r['after_url'] = page.url
        r['flash'] = page.locator('body').inner_text()[:4000]
        shot(page, f'{name}-saved')
    except Exception as e:
        shot(page, f'{name}-error')
        out['errors'].append(f'{name}: {type(e).__name__}: {str(e).splitlines()[0]} (url {page.url})')
    ctx.close()
    return r


with sync_playwright() as pw:
    br = pw.chromium.launch()

    # technician: asset tab + labels page
    ctx = br.new_context(viewport={'width': 1400, 'height': 1000})
    page = ctx.new_page()
    page.on('pageerror', lambda e: out['errors'].append(f'tech: {e}'))
    page.goto(BASE + '/')
    login(page, 'itchat.test.tech')
    try:
        page.goto(f"{BASE}/front/printer.form.php?id={QR['printer']['id']}")
        page.wait_for_load_state('networkidle')
        page.get_by_role('tab', name=re.compile('QR แจ้งปัญหา')).first.click()
        page.wait_for_selector('.itqr-tab-qr svg', timeout=15000)
        out['tab_url'] = page.locator('.itqr-url').inner_text().strip()
        shot(page, 'tech-tab')
        page.goto(f"{BASE}/plugins/itqr/front/labels.php?itemtype=Printer&ids={QR['printer']['id']},999999")
        page.wait_for_load_state('networkidle')
        out['labels'] = page.locator('.label').count()
        out['label_text'] = page.locator('.label').first.inner_text()
        out['label_has_qr'] = page.locator('.label svg').count()
        shot(page, 'labels')
    except Exception as e:
        shot(page, 'tech-error')
        out['errors'].append(f'tech: {type(e).__name__}: {str(e).splitlines()[0]} (url {page.url})')
    ctx.close()

    # user1: printer, high urgency, photo
    out['printer'] = report(br, 'itchat.test.user1', QR['printer']['url'], name='printer', urgency=4, photo=True,
                            content='กระดาษติดทุกแผ่น ไฟสีส้มกะพริบ [itchat-test]')
    # user2: the same printer again -> should see the "already reported" hint
    out['printer_again'] = report(br, 'itchat.test.user2', QR['printer']['url'], name='printer-again',
                                  title='[itchat-test] QR ปริ้นเตอร์พิมพ์ซีด', content='พิมพ์ออกมาสีซีดมาก [itchat-test]')
    # user2: computer, defaults only
    out['computer'] = report(br, 'itchat.test.user2', QR['computer']['url'], name='computer',
                             content='เปิดเครื่องแล้วจอฟ้า [itchat-test]')

    # tampered labels: wrong signature / someone else's signature on another id
    ctx = br.new_context(viewport=PHONE, is_mobile=True)
    page = ctx.new_page()
    page.goto(BASE + '/')
    login(page, 'itchat.test.user1')
    tampered = {}
    for label, url in {
        'bad_signature': re.sub(r's=[0-9a-f]+', 's=' + '0' * 24, QR['printer']['url']),
        # the printer's signature on another id (+1: a fresh database gives the test printer and
        # computer the same id, so "the computer's id" could be the printer's own)
        'other_id': re.sub(r'id=\d+', f"id={QR['printer']['id'] + 1}", QR['printer']['url']),
    }.items():
        assert url != QR['printer']['url'], f'{label}: tampered URL equals the real one'
        resp = page.goto(local(url))
        page.wait_for_load_state('networkidle')
        body = page.locator('body').inner_text()
        tampered[label] = {'status': resp.status if resp else None, 'form': page.locator('.itqr-form').count(),
                           'leaks_name': 'PC-ACC-07' in body or 'Printer ชั้น 2' in body, 'message': 'QR code นี้ไม่ถูกต้อง' in body}
    shot(page, 'tampered')
    out['tampered'] = tampered
    ctx.close()
    br.close()

print(json.dumps(out, ensure_ascii=False))
