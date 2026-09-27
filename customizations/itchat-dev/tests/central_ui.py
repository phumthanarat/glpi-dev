"""Browser part of test_central.py (Playwright image): a technician opens tickets on behalf of
requesters from the Central interface (Assistance > Tickets > + Add), driving the real widgets:
select2 searches for the requester and the category, TinyMCE for the description, the Add button.
Prints one JSON line with the created ticket ids and UI observations.

Env: GLPI_URL, ITCHAT_TEST_CREDS, SHOTS.
"""
import json
import os
import re

from playwright.sync_api import sync_playwright

BASE = os.environ['GLPI_URL'].rstrip('/')
CREDS = json.loads(os.environ['ITCHAT_TEST_CREDS'])
SHOTS = os.environ.get('SHOTS', 'shots')
os.makedirs(SHOTS, exist_ok=True)
out = {'errors': []}


def select2_of(page, select_css):
    return page.locator(select_css).locator('xpath=following-sibling::span[contains(@class,"select2")][1]')


def pick(page, select_css, search, option_text, reloads=False):
    """Open a select2, type into its search box, wait until the wanted result is highlighted
    (AJAX results re-render while loading, so clicking can hit a stale element), press Enter."""
    select2_of(page, select_css).click()
    field = page.locator('.select2-container--open .select2-search__field').last
    field.fill(search)
    page.wait_for_function(
        """(t) => { const h = document.querySelector('.select2-container--open .select2-results__option--highlighted');
                   return h && h.textContent.includes(t); }""", arg=option_text, timeout=15000)
    page.keyboard.press('Enter')
    if reloads:  # the change submits the page: read the value back after the reload instead
        return None
    page.wait_for_timeout(400)
    # select2 applied the value to the underlying <select>?
    return page.evaluate("""(css) => Array.from(document.querySelector(css).selectedOptions).map(o => o.textContent.trim())""", select_css)


def set_native(page, name, value):
    page.wait_for_selector(f'select[name="{name}"]', state='attached', timeout=15000)
    page.evaluate("""([n, v]) => { const s = document.querySelector(`select[name="${n}"]`); s.value = v;
        if (window.jQuery) jQuery(s).trigger('change'); else s.dispatchEvent(new Event('change', {bubbles: true})); }""", [name, str(value)])


step = ['']


def at(name):
    step[0] = name


def new_ticket(page, **kw):
    try:
        return _new_ticket(page, **kw)
    except Exception as e:
        page.screenshot(path=os.path.join(SHOTS, f"central-{kw['shot']}-error.png"), full_page=True)
        out['errors'].append(f"{kw['shot']} at step '{step[0]}': {type(e).__name__}: {str(e).splitlines()[0]} (url {page.url})")
        return None


def _new_ticket(page, *, requester, type_, category_search, category_text, urgency, title, description, shot):
 
    at('open form')
    page.goto(BASE + '/front/ticket.form.php')
    page.wait_for_load_state('networkidle')
    at('type')
    # Type first: on a new ticket, changing it submits the page (this.form.submit()) to reload the
    # form with that type's ticket template - wait for that navigation, not just for network idle
    with page.expect_navigation(timeout=30000):
        set_native(page, 'type', type_)
    page.wait_for_load_state('networkidle')
    page.wait_for_selector('select[name="itilcategories_id"]', state='attached', timeout=15000)
    page.wait_for_timeout(800)
    page.wait_for_selector('select[name="urgency"]', state='attached', timeout=15000)
    # Category next: on a new ticket it too submits the page to apply the category's template,
    # so pick it before anything a reload could lose
    at('category')
    with page.expect_navigation(timeout=30000):
        pick(page, 'select[name="itilcategories_id"]', category_search, category_text, reloads=True)
    page.wait_for_load_state('networkidle')
    page.wait_for_selector('select[data-actor-type="requester"]', state='attached', timeout=15000)
    page.wait_for_timeout(800)
    out.setdefault('picked', []).append(['category', page.evaluate(
        """() => Array.from(document.querySelector('select[name="itilcategories_id"]').selectedOptions).map(o => o.textContent.trim())""")])
    at('requester')
    req = 'select[data-actor-type="requester"]'
    # the form pre-fills the logged-in technician as requester: remove, then pick the real requester.
    # On a new ticket every requester change submits the page too (to refresh the requester's
    # devices/entity), so each step waits for that reload
    def reloaded():
        page.wait_for_load_state('networkidle')
        page.wait_for_selector(req, state='attached', timeout=15000)
        page.wait_for_timeout(800)
    for _ in range(select2_of(page, req).locator('.select2-selection__choice__remove').count()):
        with page.expect_navigation(timeout=30000):
            select2_of(page, req).locator('.select2-selection__choice__remove').first.click()
        reloaded()
    with page.expect_navigation(timeout=30000):
        pick(page, req, requester, requester, reloads=True)
    reloaded()
    out['picked'].append(['requester', page.evaluate(
        """(css) => Array.from(document.querySelector(css).selectedOptions).map(o => o.textContent.trim())""", req)])
    # the technician's profile may only assign tickets to itself: the form pre-fills them in
    # "Assigned to" and locks the field, so check that rather than picking someone
    at('assign')
    out['picked'].append(['assign', page.evaluate(
        """() => Array.from(document.querySelector('select[data-actor-type="assign"]').selectedOptions).map(o => o.textContent.trim())""")])
    at('urgency/title/description')
    set_native(page, 'urgency', urgency)
    page.locator('input[name="name"]:visible').first.fill(title)
    page.evaluate("""(html) => { const t = document.querySelector('textarea[name="content"]');
        if (window.tinymce && tinymce.get(t.id)) { tinymce.get(t.id).setContent(html); tinymce.triggerSave(); } else { t.value = html; } }""",
                  description)
    at('add')
    add = page.locator('button[name="add"]').first
    add.scroll_into_view_if_needed()
    box = add.bounding_box()
    covered = page.evaluate("""([x, y]) => { const e = document.elementFromPoint(x, y);
        return e && e.closest('button[name="add"]') ? null : (e ? (e.className || e.tagName) : 'nothing'); }""",
                            [box['x'] + box['width'] / 2, box['y'] + box['height'] / 2])
    out.setdefault('add_button_covered_by', []).append(covered)
    page.screenshot(path=os.path.join(SHOTS, f'central-{shot}-filled.png'))
    add.click()
    page.wait_for_load_state('networkidle')
    m = re.search(r'ticket\.form\.php\?id=(\d+)', page.url)
    page.screenshot(path=os.path.join(SHOTS, f'central-{shot}-saved.png'))
    return int(m.group(1)) if m else None


with sync_playwright() as pw:
    br = pw.chromium.launch()
    page = br.new_page(viewport={'width': 1500, 'height': 1000})
    page.on('pageerror', lambda e: out['errors'].append(str(e)))
    page.goto(BASE + '/')
    page.fill('input[name="login_name"]', 'itchat.test.tech')
    page.fill('input[name="login_password"]', CREDS['itchat.test.tech'])
    page.press('input[name="login_password"]', 'Enter')
    page.wait_for_load_state('networkidle')
    fab = page.locator('.itchat-fab')

    out['request'] = new_ticket(page, requester='itchat.test.user1', type_=2, category_search='AD Account',
                                category_text='AD Account', urgency=4,
                                title='[itchat-test] CENTRAL ขอสิทธิ์ VPN ให้พนักงานใหม่',
                                description='<p>ผู้ใช้โทรมาขอสิทธิ์ VPN (ช่างเปิดแทน)</p>', shot='request')
    out['fab_visible_on_ticket_page'] = fab.is_visible()
    out['incident'] = new_ticket(page, requester='itchat.test.user2', type_=1, category_search='Printer',
                                 category_text='Printer', urgency=3,
                                 title='[itchat-test] CENTRAL ปริ้นเตอร์ชั้น 3 กระดาษติด',
                                 description='<p>ผู้ใช้เดินมาแจ้งที่โต๊ะ IT</p>', shot='incident')
    br.close()

print(json.dumps(out, ensure_ascii=False))
