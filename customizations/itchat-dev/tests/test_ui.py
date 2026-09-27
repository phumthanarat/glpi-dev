"""Browser test (Playwright/Chromium, run inside the playwright docker image by run.sh).

Requester on a phone-sized screen and a technician on desktop, driving the real widget:
send text + image, unread badge and inbox, claim, read receipts, live reply,
"เปิด Ticket" dialog (type required, category disabled until type, approval hint),
close from the phone and "เริ่มใหม่". Screenshots go to $SHOTS (default ./shots).
"""
import os
import re

from playwright.sync_api import expect, sync_playwright

from lib import BASE, CREDS, PREFIX, Checks, png
from totp import browser_mfa

SHOTS = os.environ.get('SHOTS', 'shots')
os.makedirs(SHOTS, exist_ok=True)
check = Checks('ui')


# Records browser notifications instead of showing them (permission pre-granted).
FAKE_NOTIFICATION = """
window.__itchatNotes = [];
class FakeNotification {
  constructor(title, opts) { window.__itchatNotes.push({title, body: (opts || {}).body}); }
  close() {}
  static requestPermission() { return Promise.resolve('granted'); }
}
FakeNotification.permission = 'granted';
window.Notification = FakeNotification;
"""


def login(br, user, pw, mobile=False, init_script=None):
    ctx = br.new_context(viewport={'width': 390, 'height': 844} if mobile else {'width': 1400, 'height': 900},
                         is_mobile=mobile, has_touch=mobile)
    if init_script:
        ctx.add_init_script(init_script)
    p = ctx.new_page()
    p.goto(BASE + '/')
    p.fill('input[name="login_name"]', user)
    p.fill('input[name="login_password"]', pw)
    p.press('input[name="login_password"]', 'Enter')
    p.wait_for_load_state('networkidle')
    browser_mfa(p, user)
    return p


def fresh_chat(p):
    """Open the widget; if an older closed chat is shown, start a new one."""
    p.click('.itchat-fab')
    p.wait_for_timeout(1500)
    if p.locator('.itchat-actions button:has-text("เริ่มใหม่")').count():
        p.click('.itchat-actions button:has-text("เริ่มใหม่")')


with sync_playwright() as pw:
    br = pw.chromium.launch()
    img = os.path.join(SHOTS, 'upload.png')
    with open(img, 'wb') as fh:
        fh.write(png((250, 200, 120), 300, 160))

    # ---- technician already has GLPI open (panel closed) before the requester writes
    t = login(br, 'itchat.test.tech', CREDS['itchat.test.tech'], init_script=FAKE_NOTIFICATION)
    t.wait_for_timeout(2000)  # first poll only records the current state

    # ---- requester on a phone
    u = login(br, 'itchat.test.user1', CREDS['itchat.test.user1'], mobile=True)
    check('chat button visible on phone', u.locator('.itchat-fab').is_visible())
    fresh_chat(u)
    box = u.locator('.itchat-panel').bounding_box()
    check('panel fits phone width', box['x'] >= 0 and box['x'] + box['width'] <= 390, box)
    check('no horizontal page scroll', u.evaluate('document.documentElement.scrollWidth <= window.innerWidth'))
    check('empty state greeting', u.locator('.itchat-empty').is_visible())
    check('short placeholder', u.locator('.itchat-input').get_attribute('placeholder') == 'พิมพ์ข้อความ…')
    u.fill('.itchat-input', f'{PREFIX} UI ขอสิทธิ์เข้าโฟลเดอร์บัญชี\nบรรทัดที่สอง')
    u.press('.itchat-input', 'Enter')
    expect(u.locator('.itchat-msg.mine .itchat-bubble').last).to_contain_text('บรรทัดที่สอง', timeout=10000)
    check('multi-line text sent', True)
    u.set_input_files('.itchat-form input[type=file]', img)
    expect(u.locator('.itchat-msg.mine .itchat-image img')).to_be_visible(timeout=15000)
    check('image sent from phone', True)
    expect(u.locator('.itchat-msg.mine .itchat-receipt').first).to_have_text('ส่งแล้ว')
    check('receipt says sent', True)
    u.screenshot(path=os.path.join(SHOTS, 'ui-1-phone.png'))

    # ---- technician on desktop
    t.wait_for_function('window.__itchatNotes.length > 0', timeout=30000)
    notes = t.evaluate('window.__itchatNotes')
    check('tech gets a browser notification for the new chat', any('itchat.test.user1' in n['title'] for n in notes), notes)
    check('page title shows unread count', t.title().startswith('('), t.title())
    expect(t.locator('.itchat-badge')).to_be_visible(timeout=20000)
    check('tech badge shows unread without opening the panel', int(t.locator('.itchat-badge').inner_text().rstrip('+')) >= 1)
    t.click('.itchat-fab')
    item = t.locator('.itchat-item', has=t.locator('.badge')).filter(has_text='itchat.test.user1').first
    expect(item).to_be_visible(timeout=10000)
    check('unread chat marked waiting (unclaimed)', item.locator('.itchat-item-meta.waiting').count() == 1)
    item.click()
    expect(t.locator('.itchat-image img')).to_be_visible(timeout=10000)
    t.click('.itchat-actions button:has-text("รับเรื่อง")')
    expect(t.locator('.itchat-system', has_text='รับเรื่องแล้ว')).to_be_visible(timeout=10000)
    check('claim button', True)
    t.click('.itchat-tab[data-filter="mine"]')
    check('tab "ของฉัน" shows my claimed chat', t.locator('.itchat-item', has_text='itchat.test.user1').count() >= 1)
    t.click('.itchat-tab[data-filter="waiting"]')
    check('tab "รอรับ" hides claimed chat', t.locator('.itchat-item.active').count() == 0)
    t.click('.itchat-tab[data-filter="all"]')
    t.fill('.itchat-search', 'itchat.test.user1')
    expect(t.locator('.itchat-list-note')).to_contain_text('ผลการค้นหา', timeout=10000)
    check('search box finds chats', t.locator('.itchat-item').count() >= 1)
    t.fill('.itchat-search', '')
    t.wait_for_timeout(600)
    check('clearing search returns to inbox', t.locator('.itchat-list-note').count() == 0)
    t.click('.itchat-actions button:has-text("โอน")')
    menu = t.locator('.itchat-menu')
    expect(menu).to_be_visible(timeout=10000)
    check('transfer menu lists other technicians', menu.locator('.itchat-menu-item', has_text='itchat.test.tech2').count() == 1)
    t.screenshot(path=os.path.join(SHOTS, 'ui-2b-transfer.png'))
    t.click('.itchat-body')
    check('transfer menu closes on outside click', t.locator('.itchat-menu').count() == 0)
    expect(u.locator('.itchat-msg.mine .itchat-receipt.read')).to_have_count(2, timeout=15000)
    check('requester sees read receipts', True)
    check('sound toggle shown to tech', t.locator('.itchat-sound').is_visible())
    icon_before = t.locator('.itchat-sound i').get_attribute('class')
    t.click('.itchat-sound')
    check('sound toggle switches', t.locator('.itchat-sound i').get_attribute('class') != icon_before)
    t.click('.itchat-sound')
    t.click('.itchat-canned-btn')
    items = t.locator('.itchat-canned-item')
    check('canned replies menu lists items', items.count() >= 1, items.count())
    first_canned = items.first.inner_text()
    items.first.click()
    check('canned reply inserted into the input (not sent)', t.locator('.itchat-input').input_value() == first_canned
          and t.locator('.itchat-canned').is_hidden())
    check('requester UI has no canned/sound buttons', u.locator('.itchat-canned-btn').is_hidden() and u.locator('.itchat-sound').is_hidden())
    t.fill('.itchat-input', 'รับทราบครับ เดี๋ยวดำเนินการให้')
    t.press('.itchat-input', 'Enter')
    expect(u.locator('.itchat-msg.theirs .itchat-bubble', has_text='ดำเนินการ')).to_be_visible(timeout=15000)
    check('requester receives reply live (no reload)', True)

    # ---- ticket dialog
    t.click('.itchat-actions button:has-text("เปิด Ticket")')
    dlg = t.locator('.itchat-modal-card')
    expect(dlg).to_be_visible(timeout=10000)
    type_sel, cat_sel = dlg.locator('select').nth(0), dlg.locator('select').nth(1)
    check('dialog: suggested title', dlg.locator('input').input_value().startswith('แชท: ' + PREFIX))
    check('dialog: type not preselected', type_sel.input_value() == '')
    check('dialog: category disabled until type', cat_sel.is_disabled())
    t.keyboard.press('Escape')
    check('dialog: Esc closes', t.locator('.itchat-modal').count() == 0)
    t.click('.itchat-actions button:has-text("เปิด Ticket")')
    expect(dlg).to_be_visible()
    dlg.locator('button[type=submit]').click()
    t.wait_for_timeout(800)
    check('dialog: cannot submit without type', t.locator('.itchat-modal').count() == 1
          and t.locator('.itchat-system', has_text='เปิด Ticket #').count() == 0)
    type_sel.select_option('2')
    info = dlg.locator('.itchat-group-info').inner_text()
    check('dialog: Request shows approval by group manager', 'หัวหน้ากลุ่มอนุมัติ' in info and '[itchat-test] Group' in info, info)
    cat_sel.select_option(label='IT Support > Account > AD Account')
    t.screenshot(path=os.path.join(SHOTS, 'ui-2-dialog.png'))
    dlg.locator('button[type=submit]').click()
    sysmsg = t.locator('.itchat-system', has_text='เปิด Ticket #')
    expect(sysmsg).to_be_visible(timeout=15000)
    tid = re.search(r'#(\d+)', sysmsg.inner_text()).group(1)
    check('ticket created from dialog', True)
    expect(u.locator('.itchat-actions a', has_text='#' + tid)).to_be_visible(timeout=15000)
    check('requester sees ticket link', True)

    # ---- close from phone, start new
    u.once('dialog', lambda d: d.accept())
    u.click('.itchat-actions button:has-text("ปิด")')
    expect(u.locator('.itchat-actions button:has-text("เริ่มใหม่")')).to_be_visible(timeout=15000)
    check('requester close -> start-new button, input hidden', u.locator('.itchat-form').is_hidden())
    expect(t.locator('.itchat-sub')).to_have_text('ปิดแล้ว', timeout=15000)
    check('tech sees chat closed', True)
    rate = u.locator('.itchat-rate')
    expect(rate).to_be_visible(timeout=10000)
    check('requester is asked for a rating after close', rate.locator('.itchat-star').count() == 5)
    u.screenshot(path=os.path.join(SHOTS, 'ui-3b-rating.png'))
    rate.locator('.itchat-star').nth(3).click()
    expect(u.locator('.itchat-system', has_text='ให้คะแนน ★★★★☆')).to_be_visible(timeout=15000)
    check('rating recorded and rating bar hidden', u.locator('.itchat-rate').is_hidden())
    expect(t.locator('.itchat-sub')).to_contain_text('★★★★', timeout=15000)
    check('tech sees the rating in the header', True)
    u.click('.itchat-actions button:has-text("เริ่มใหม่")')
    check('start-new shows greeting and input immediately', u.locator('.itchat-empty').is_visible() and u.locator('.itchat-form').is_visible())
    t.goto(f'{BASE}/front/ticket.form.php?id={tid}')
    t.wait_for_load_state('networkidle')
    check('tech can open the ticket', 'Access denied' not in t.title(), t.title())
    t.screenshot(path=os.path.join(SHOTS, 'ui-3-ticket.png'))
    br.close()

check.done()
