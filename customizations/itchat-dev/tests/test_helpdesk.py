"""Tickets filed through the real Helpdesk forms in a browser (helpdesk_ui.py in the
Playwright image), then checked server-side: type, category, urgency, team rule, SLA,
requester group (setup-17) and manager approval, attachment, visibility."""
import json
import os
import subprocess

from lib import BASE, Checks, inspect_tickets, login

check = Checks('helpdesk')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')


def fixture(*args):
    out = subprocess.run(['kubectl', '-n', NS, 'exec', POD, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', *map(str, args)],
                         capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


cats = fixture('categories')
url = BASE.replace('localhost', 'host.docker.internal').replace('127.0.0.1', 'host.docker.internal')
here = os.path.dirname(os.path.abspath(__file__))
shots = os.environ.get('SHOTS', os.path.join(here, 'shots'))
proc = subprocess.run(
    ['timeout', '400', 'docker', 'run', '--rm', '--add-host', 'host.docker.internal:host-gateway',
     '-e', f'GLPI_URL={url}', '-e', 'ITCHAT_TEST_CREDS', '-e', f'CATEGORIES={json.dumps(cats)}', '-e', 'SHOTS=/shots',
     '-v', f'{here}:/tests:ro', '-v', f'{shots}:/shots', '-w', '/tests',
     os.environ.get('PLAYWRIGHT_IMAGE', 'mcr.microsoft.com/playwright/python:v1.55.0-noble'),
     'sh', '-c', 'pip install -q --timeout 20 --retries 2 playwright==1.55.0 >/dev/null 2>&1; python helpdesk_ui.py'],
    capture_output=True, text=True)
lines = [l for l in proc.stdout.splitlines() if l.startswith('{')]
check('browser run finished', proc.returncode == 0 and lines, (proc.returncode, proc.stderr[-800:]))
if not lines:
    check.done()
ui = json.loads(lines[-1])
check('no JavaScript errors while filling the forms', ui['errors'] == [], ui['errors'])

inc, req, u2 = ui['incident']['ids'], ui['request']['ids'], ui['user2']['ids']
check('"Report an issue" submitted -> one ticket', len(inc) == 1, ui['incident'])
check('"Request a service" submitted -> one ticket', len(req) == 1, ui['request'])
check('user2 "Report an issue" -> one ticket', len(u2) == 1, ui['user2'])
# cross-check with the database: exactly these tickets belong to the requesters
# subset, not equality: in `run.sh full` earlier suites have already filed tickets for user1
owned = fixture('tickets-of', 'itchat.test.user1')
check('tickets really owned by user1', (inc + req) and set(inc + req) <= set(owned), (inc + req, owned))
check('"my tickets" page lists both of user1\'s tickets', sorted(ui['my_tickets_page_lists']) == sorted(inc + req), ui['my_tickets_page_lists'])

facts = inspect_tickets(inc + req + u2)
if inc:
    f = facts[inc[0]]
    check('incident: type Incident', f['type'] == 1, f['type'])
    check('incident: category from the form', f['category'] == 'IT Support > Network > VPN', f['category'])
    check('incident: urgency from the form (High)', f['urgency'] == 4, f['urgency'])
    check('incident: requester', f['requesters'] == ['itchat.test.user1'], f['requesters'])
    check('incident: requester group from default group (setup-17)', f['requester_groups'] == ['[itchat-test] Group'], f['requester_groups'])
    check('incident: team from category rule', 'Network Team' in f['assign_groups'], f['assign_groups'])
    check('incident: SLA assigned', f['sla_ttr'] > 0, f['sla_ttr'])
    check('incident: attachment on the ticket', f['documents'] >= 1, f['documents'])
    check('incident: no approval', f['approvers'] == [], f['approvers'])
if req:
    f = facts[req[0]]
    check('request: type Request', f['type'] == 2, f['type'])
    check('request: category from the form', f['category'] == 'IT Support > Account > AD Account', f['category'])
    check('request: approval sent to group manager', f['approvers'] == ['itchat.test.tech'] and f['global_validation'] == 2,
          (f['approvers'], f['global_validation']))
    check('request: team from category rule', 'Helpdesk / Service Desk' in f['assign_groups'], f['assign_groups'])
if u2:
    f = facts[u2[0]]
    check('user2 (no group): no requester group, no approval', f['requester_groups'] == [] and f['approvers'] == [], f)
    check('user2: urgency Low from the form', f['urgency'] == 2, f['urgency'])
    check('user2: no category -> no team', f['assign_groups'] == [], f['assign_groups'])

if inc:
    page = lambda s: s.get(f'{BASE}/front/ticket.form.php?id={inc[0]}')
    check('web: technician opens the helpdesk ticket', 'Access denied' not in page(login('itchat.test.tech')).text[:4000])
    r = page(login('itchat.test.user2'))
    check('web: another requester cannot', r.status_code in (403, 404) or 'Access denied' in r.text[:4000], r.status_code)

check.done()
