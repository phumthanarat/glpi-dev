"""Browser part of test_helpdesk.py (runs in the Playwright image).

Requesters file tickets through GLPI 11's real Helpdesk forms (/Form/Render/<id>) exactly as a
person would: pick urgency and category, type title + description, attach a file, press Submit.
Prints one JSON line: ticket ids per scenario + anything that went wrong.

Env: GLPI_URL, ITCHAT_TEST_CREDS, CATEGORIES (JSON completename -> id), SHOTS.
"""
import json
import os
import re

from playwright.sync_api import sync_playwright

from totp import browser_mfa

BASE = os.environ['GLPI_URL'].rstrip('/')
CREDS = json.loads(os.environ['ITCHAT_TEST_CREDS'])
CATS = json.loads(os.environ['CATEGORIES'])
SHOTS = os.environ.get('SHOTS', 'shots')
os.makedirs(SHOTS, exist_ok=True)
out = {'errors': []}


def login(br, user):
    page = br.new_page(viewport={'width': 1400, 'height': 1000})
    page.on('pageerror', lambda e: out['errors'].append(f'{user}: JS error {e}'))
    page.goto(BASE + '/')
    page.fill('input[name="login_name"]', user)
    page.fill('input[name="login_password"]', CREDS[user])
    page.press('input[name="login_password"]', 'Enter')
    page.wait_for_load_state('networkidle')
    browser_mfa(page, user)
    return page


def set_select(page, name, value, label=None):
    """GLPI renders selects through select2 (options may be loaded by AJAX): set the underlying
    <select> directly, adding the option if needed, and fire change like select2 does."""
    return page.evaluate("""([name, value, label]) => {
        const s = document.querySelector(`select[name="${name}"]`);
        if (!s) return false;
        let o = Array.from(s.options).find(o => o.value === String(value));
        if (!o) { o = new Option(label || String(value), String(value), true, true); s.appendChild(o); }
        s.value = String(value);
        s.dispatchEvent(new Event('change', {bubbles: true}));
        if (window.jQuery) jQuery(s).trigger('change');
        return s.value === String(value);
    }""", [name, value, label])


def set_rich_text(page, name, html):
    page.evaluate("""([name, html]) => {
        const t = document.querySelector(`textarea[name="${name}"]`);
        if (window.tinymce && tinymce.get(t.id)) { tinymce.get(t.id).setContent(html); tinymce.triggerSave(); }
        else { t.value = html; }
    }""", [name, html])


def fill_and_submit(page, form_id, *, urgency=None, category=None, title=None, description=None, attach=None, shot=''):
    page.goto(f'{BASE}/Form/Render/{form_id}')
    page.wait_for_load_state('networkidle')
    names = page.eval_on_selector_all('[name]', 'es => es.map(e => [e.name, e.tagName, e.type || "", '
                                      '(e.closest("[data-glpi-form-renderer-question]") || {}).innerText || ""])')
    by_label = {}
    for name, tag, typ, qtext in names:
        label = qtext.strip().split('\n')[0].rstrip(' *').strip().lower()
        if label and name.startswith('answers_'):
            by_label.setdefault(label, []).append((name, tag, typ))
    field = lambda label, tag: next((n for n, t, _ in by_label.get(label, []) if t == tag), None)

    if urgency is not None and field('urgency', 'SELECT'):
        set_select(page, field('urgency', 'SELECT'), urgency)
    if category is not None:
        cat_select = next((n for n, t, _ in by_label.get('category', []) if t == 'SELECT' and n.endswith('[items_id]')), None)
        if cat_select:
            set_select(page, cat_select, CATS[category], category)
        else:
            out['errors'].append(f'form {form_id}: no category question')
    if title is not None and field('title', 'INPUT'):
        page.fill(f'input[name="{field("title", "INPUT")}"]', title)
    desc = field('description', 'TEXTAREA')
    if description is not None and desc:
        set_rich_text(page, desc, description)
    if attach:
        uploader = page.locator('input[type=file][name^="_uploader_answers_"]').last
        uploader.set_input_files(attach)
        page.wait_for_load_state('networkidle')
        page.wait_for_timeout(1500)
    page.screenshot(path=os.path.join(SHOTS, f'helpdesk-{shot}-filled.png'), full_page=True)
    page.get_by_role('button', name='Submit').click()
    page.wait_for_load_state('networkidle')
    page.wait_for_timeout(1500)
    page.screenshot(path=os.path.join(SHOTS, f'helpdesk-{shot}-submitted.png'), full_page=True)
    ids = sorted({int(m) for m in re.findall(r'ticket\.form\.php\?id=(\d+)', page.content())})
    return {'ids': ids, 'url': page.url, 'fields': sorted(by_label)}


with sync_playwright() as pw:
    br = pw.chromium.launch()
    img = os.path.join(SHOTS, 'helpdesk-upload.png')
    tmp = br.new_page(viewport={'width': 500, 'height': 200})
    tmp.set_content('<body style="font:28px sans-serif;background:#fecaca;padding:30px">VPN error 809</body>')
    tmp.screenshot(path=img)
    tmp.close()

    u1 = login(br, 'itchat.test.user1')
    out['incident'] = fill_and_submit(
        u1, 1, urgency=4, category='IT Support > Network > VPN',
        title='[itchat-test] HELPDESK VPN ต่อไม่ได้', description='<p>VPN ขึ้น error 809 ตามรูป</p>',
        attach=img, shot='incident')
    out['request'] = fill_and_submit(
        u1, 2, category='IT Support > Account > AD Account',
        title='[itchat-test] HELPDESK ขอสิทธิ์โฟลเดอร์บัญชี', description='<p>ขอสิทธิ์อ่าน/เขียนโฟลเดอร์ \\\\fs01\\บัญชี</p>',
        shot='request')
    u1.goto(BASE + '/front/ticket.php')
    u1.wait_for_load_state('networkidle')
    out['my_tickets_page_lists'] = [i for i in out['incident']['ids'] + out['request']['ids'] if f'id={i}' in u1.content()]
    u1.screenshot(path=os.path.join(SHOTS, 'helpdesk-my-tickets.png'), full_page=True)

    u2 = login(br, 'itchat.test.user2')
    out['user2'] = fill_and_submit(
        u2, 1, urgency=2, title='[itchat-test] HELPDESK เมาส์เสีย', description='<p>เมาส์ดับเบิลคลิกเอง</p>', shot='user2')
    br.close()

print(json.dumps(out, ensure_ascii=False))
