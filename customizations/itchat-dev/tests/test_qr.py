"""QR labels (customizations/itqr): qr_ui.py drives a real browser, then the tickets are checked
server-side."""
import json
import os
import subprocess

from lib import BASE, Checks, inspect_tickets

check = Checks('qr')
here = os.path.dirname(os.path.abspath(__file__))
shots = os.environ.get('SHOTS', os.path.join(here, 'shots'))
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')

qr = json.loads(subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', 'qr-assets'],
                               capture_output=True, text=True, check=True).stdout.strip().splitlines()[-1])
url = BASE.replace('localhost', 'host.docker.internal').replace('127.0.0.1', 'host.docker.internal')
proc = subprocess.run(
    ['timeout', '400', 'docker', 'run', '--rm', '--add-host', 'host.docker.internal:host-gateway',
     '-e', f'GLPI_URL={url}', '-e', 'ITCHAT_TEST_CREDS', '-e', f'QR={json.dumps(qr)}', '-e', 'SHOTS=/shots',
     '-v', f'{here}:/tests:ro', '-v', f'{shots}:/shots', '-w', '/tests',
     os.environ.get('PLAYWRIGHT_IMAGE', 'mcr.microsoft.com/playwright/python:v1.55.0-noble'),
     'sh', '-c', 'pip install -q --timeout 20 --retries 2 playwright==1.55.0 >/dev/null 2>&1; python qr_ui.py'],
    capture_output=True, text=True)
lines = [l for l in proc.stdout.splitlines() if l.startswith('{')]
check('browser run finished', proc.returncode == 0 and lines, (proc.returncode, proc.stderr[-1200:]))
if not lines:
    check.done()
ui = json.loads(lines[-1])
check('no JavaScript / step errors', ui['errors'] == [], ui['errors'])

# technician side
check('tech: "QR แจ้งปัญหา" tab shows the label URL', ui.get('tab_url') == qr['printer']['url'], (ui.get('tab_url'), qr['printer']['url']))
check('tech: labels page, one label per readable asset (unknown id skipped)', ui.get('labels') == 1 and ui.get('label_has_qr') == 1, ui.get('labels'))
check('tech: label shows the device name and serial', 'Printer ชั้น 2' in ui.get('label_text', '') and 'PRN-TEST-001' in ui.get('label_text', ''), ui.get('label_text'))

# user side
p, again, c = ui['printer'], ui['printer_again'], ui['computer']
check('scan while logged out asks to log in first', p.get('login_asked'), p)
check('after login the user lands back on the report page', p.get('back_on_report_page'), p)
check('report page shows the scanned device', p.get('device_shown') == '[itchat-test] Printer ชั้น 2', p.get('device_shown'))
check('report page fits a phone screen', p.get('page_overflows_phone') is False, p.get('page_overflows_phone'))
check('submitting opens the new ticket', p.get('ticket') and c.get('ticket') and again.get('ticket'), (p.get('after_url'), c.get('after_url')))
check('confirmation shows the ticket number', p.get('ticket') and f"#{p['ticket']}" in p.get('flash', ''), p.get('flash', '')[:300])
check('second report on the same device: "already reported" hint', again.get('open_hint') and '1 รายการ' in again['open_hint'], again.get('open_hint'))
check('no hint on a device without open tickets', c.get('open_hint') is None, c.get('open_hint'))

ids = [i for i in (p.get('ticket'), again.get('ticket'), c.get('ticket')) if i]
facts = inspect_tickets(ids) if ids else {}
if p.get('ticket'):
    f = facts[p['ticket']]
    check('printer ticket: requester is the scanning user', f['requesters'] == ['itchat.test.user1'], f['requesters'])
    check('printer ticket: printer linked', f['items'] == [f"Printer:{qr['printer']['id']}"], f['items'])
    check('printer ticket: default title names the device', f['name'] == 'แจ้งปัญหา: [itchat-test] Printer ชั้น 2', f['name'])
    check('printer ticket: description kept', 'กระดาษติดทุกแผ่น' in f['content'], f['content'][:200])
    check('printer ticket: category Printer (from the asset type)', f['category'] == 'IT Support > Hardware > Printer', f['category'])
    check('printer ticket: Helpdesk team + SLA via the existing rules', 'Helpdesk / Service Desk' in f['assign_groups'] and f['sla_ttr'] > 0, (f['assign_groups'], f['sla_ttr']))
    check('printer ticket: urgency High as chosen', f['urgency'] == 4, f['urgency'])
    check('printer ticket: photo attached', f['documents'] == 1, f['documents'])
    check('printer ticket: source "QR code"', f['requesttype_name'] == 'QR code', f['requesttype_name'])
    check('printer ticket: requester group from the user\'s default group', f['requester_groups'] == ['[itchat-test] Group'], f['requester_groups'])
if again.get('ticket'):
    f = facts[again['ticket']]
    check('second ticket: own title kept', f['name'] == '[itchat-test] QR ปริ้นเตอร์พิมพ์ซีด', f['name'])
if c.get('ticket'):
    f = facts[c['ticket']]
    check('computer ticket: computer linked, category Computer', f['items'] == [f"Computer:{qr['computer']['id']}"] and f['category'] == 'IT Support > Hardware > Computer',
          (f['items'], f['category']))
    check('computer ticket: default urgency Medium, no photo', f['urgency'] == 3 and f['documents'] == 0, (f['urgency'], f['documents']))

for label, t in ui.get('tampered', {}).items():
    check(f'tampered label ({label}) refused, device not revealed', t['form'] == 0 and not t['leaks_name'] and t['message'], t)

check.done()
