"""The three Service Catalog forms of setup-25 (new equipment, new employee, system access):
listed in the catalog, rendered without errors in a real browser, and each submitted by a
requester -> the ticket has the title built from the answers, the answers in its description,
type Request, the right category, the Helpdesk team, an SLA; manager approval for equipment and
access but not for a new employee; the 5 onboarding tasks on the new-employee ticket.
"""
import json
import os
import re
import subprocess

from lib import BASE, Checks, inspect_tickets, login

check = Checks('service-forms')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
XHR = {'X-Requested-With': 'XMLHttpRequest'}


def fixtures(*args):
    out = subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', *args],
                         capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


FORMS = fixtures('forms')
user = login('itchat.test.user1')      # has a requester group whose manager is itchat.test.tech


def submit(form_name, answers):
    f = FORMS[form_name]
    q = f['questions']
    data = {'forms_id': f['id']}
    for label, value in answers.items():
        key = f'answers_{q[label]}'
        if isinstance(value, list):
            data[key + '[]'] = value
        else:
            data[key] = value
    r = user.post(BASE + '/Form/SubmitAnswers', data=data, headers={**XHR, 'X-Glpi-Csrf-Token': user.csrf})
    links = r.json().get('links_to_created_items', []) if r.status_code == 200 else []
    m = re.search(r'id=(\d+)', ' '.join(links))
    return (int(m.group(1)) if m else None), r


check('3 forms exist', {'ขออุปกรณ์ใหม่', 'แจ้งพนักงานเข้าใหม่', 'ขอสิทธิ์เข้าใช้ระบบ'} <= set(FORMS), sorted(FORMS))
catalog = user.get(BASE + '/ServiceCatalog').text
check('listed in the Service Catalog for users', all(n in catalog for n in ('ขออุปกรณ์ใหม่', 'แจ้งพนักงานเข้าใหม่', 'ขอสิทธิ์เข้าใช้ระบบ')))

# mandatory fields are enforced
tid, r = submit('ขออุปกรณ์ใหม่', {'จำนวน': '1'})
check('missing mandatory answers are refused (no ticket)', tid is None, (r.status_code, r.text[:200]))

eq, r = submit('ขออุปกรณ์ใหม่', {'ประเภทอุปกรณ์': '1', 'จำนวน': '2', 'สำหรับใคร': 'น้องใหม่ บัญชี',
                                  'ต้องการภายในวันที่': '2026-10-15', 'เหตุผล / รายละเอียด': '[itchat-test] เครื่องเดิมเปิดไม่ติด'})
check('equipment form -> ticket', eq, (r.status_code, r.text[:300]))
ob, r = submit('แจ้งพนักงานเข้าใหม่', {'ชื่อ-นามสกุล': 'สมหญิง ทดสอบ', 'ตำแหน่ง': 'เจ้าหน้าที่บัญชี', 'แผนก': 'บัญชี',
                                        'วันเริ่มงาน': '2026-10-20', 'หัวหน้างาน': 'คุณสมชาย', 'อุปกรณ์ที่ต้องใช้': ['1', '3'],
                                        'บัญชี / ระบบที่ต้องใช้': ['1', '2', '4'], 'หมายเหตุ': '[itchat-test] นั่งโต๊ะ B12'})
check('new-employee form -> ticket', ob, (r.status_code, r.text[:300]))
ac, r = submit('ขอสิทธิ์เข้าใช้ระบบ', {'ระบบ': '1', 'ชื่อระบบ / โฟลเดอร์': r'\\fileserver\บัญชี', 'ระดับสิทธิ์': ['2'],
                                       'ใช้ตั้งแต่': '2026-10-01', 'เหตุผล': '[itchat-test] ปิดงบประจำเดือน'})
check('access form -> ticket', ac, (r.status_code, r.text[:300]))

facts = inspect_tickets([t for t in (eq, ob, ac) if t])
if eq:
    f = facts[eq]
    check('equipment: title from the answers', f['name'] == 'ขออุปกรณ์ใหม่: Notebook × 2', f['name'])
    check('equipment: answers in the description', all(x in f['content'] for x in ('น้องใหม่ บัญชี', 'เครื่องเดิมเปิดไม่ติด', '2026-10-15')), f['content'][:300])
    check('equipment: Request, category Hardware', f['type'] == 2 and f['category'] == 'IT Support > Hardware', (f['type'], f['category']))
    check('equipment: Helpdesk team + SLA', 'Helpdesk / Service Desk' in f['assign_groups'] and f['sla_ttr'] > 0, (f['assign_groups'], f['sla_ttr']))
    check('equipment: manager approval requested', f['approvers'] == ['itchat.test.tech'], f['approvers'])
if ob:
    f = facts[ob]
    check('new employee: title from the answers', f['name'] == 'พนักงานใหม่: สมหญิง ทดสอบ (บัญชี) เริ่ม 2026-10-20', f['name'])
    check('new employee: chosen devices / accounts in the description', all(x in f['content'] for x in ('Notebook', 'จอเสริม', 'Login (AD)', 'ERP')), f['content'][:400])
    check('new employee: category New employee', f['category'] == 'IT Support > Account > New employee', f['category'])
    check('new employee: Helpdesk team (category under Account)', 'Helpdesk / Service Desk' in f['assign_groups'], f['assign_groups'])
    check('new employee: 5-task onboarding checklist', len(f['tasks']) == 5 and 'สร้างบัญชี login' in f['tasks'][0], f['tasks'])
    check('new employee: no manager approval', f['approvers'] == [], f['approvers'])
if ac:
    f = facts[ac]
    check('access: title from the answers', f['name'] == 'ขอสิทธิ์: File server / โฟลเดอร์กลาง (อ่าน / แก้ไข)', f['name'])
    check('access: category Access request + Helpdesk', f['category'] == 'IT Support > Account > Access request' and 'Helpdesk / Service Desk' in f['assign_groups'], (f['category'], f['assign_groups']))
    check('access: manager approval requested', f['approvers'] == ['itchat.test.tech'], f['approvers'])

# the pages render in a real browser, without JavaScript errors
url = BASE.replace('localhost', 'host.docker.internal')
proc = subprocess.run(
    ['timeout', '300', 'docker', 'run', '--rm', '--add-host', 'host.docker.internal:host-gateway', '-e', f'GLPI_URL={url}',
     '-e', 'ITCHAT_TEST_CREDS', '-e', f'FORM_IDS={",".join(str(FORMS[n]["id"]) for n in ("ขออุปกรณ์ใหม่", "แจ้งพนักงานเข้าใหม่", "ขอสิทธิ์เข้าใช้ระบบ"))}',
     '-v', f'{os.path.dirname(os.path.abspath(__file__))}:/tests:ro', '-v', f'{os.environ.get("SHOTS", "/tmp")}:/shots', '-w', '/tests',
     os.environ.get('PLAYWRIGHT_IMAGE', 'mcr.microsoft.com/playwright/python:v1.55.0-noble'),
     'sh', '-c', 'pip install -q --timeout 20 --retries 2 playwright==1.55.0 >/dev/null 2>&1; python - <<"EOF"\n'
     'import json, os\nfrom playwright.sync_api import sync_playwright\n'
     'C = json.loads(os.environ["ITCHAT_TEST_CREDS"]); B = os.environ["GLPI_URL"]; out = {"errors": [], "fields": {}}\n'
     'with sync_playwright() as pw:\n'
     '    p = pw.chromium.launch().new_page(viewport={"width": 1400, "height": 1000})\n'
     '    p.on("pageerror", lambda e: out["errors"].append(str(e)))\n'
     '    p.goto(B + "/"); p.fill("input[name=login_name]", "itchat.test.user1"); p.fill("input[name=login_password]", C["itchat.test.user1"])\n'
     '    p.press("input[name=login_password]", "Enter"); p.wait_for_load_state("networkidle")\n'
     '    for fid in os.environ["FORM_IDS"].split(","):\n'
     '        p.goto(B + "/Form/Render/" + fid); p.wait_for_load_state("networkidle")\n'
     '        out["fields"][fid] = p.locator("[data-glpi-form-renderer-question]").count()\n'
     '        p.screenshot(path=f"/shots/service-form-{fid}.png", full_page=True)\n'
     'print(json.dumps(out))\nEOF'],
    capture_output=True, text=True)
line = [l for l in proc.stdout.splitlines() if l.startswith('{')]
ui = json.loads(line[-1]) if line else {}
check('forms render in a browser without JavaScript errors', line and ui['errors'] == [], (proc.returncode, ui.get('errors'), proc.stderr[-300:]))
check('every question rendered (5 / 8 / 7)', list(ui.get('fields', {}).values()) == [5, 8, 7], ui.get('fields'))

check.done()
