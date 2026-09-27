"""Tickets opened by a technician on behalf of requesters from the Central interface
(central_ui.py in a real browser), then checked server-side."""
import json
import os
import subprocess

from lib import BASE, Checks, inspect_tickets

check = Checks('central')
here = os.path.dirname(os.path.abspath(__file__))
shots = os.environ.get('SHOTS', os.path.join(here, 'shots'))
url = BASE.replace('localhost', 'host.docker.internal').replace('127.0.0.1', 'host.docker.internal')
proc = subprocess.run(
    ['timeout', '400', 'docker', 'run', '--rm', '--add-host', 'host.docker.internal:host-gateway',
     '-e', f'GLPI_URL={url}', '-e', 'ITCHAT_TEST_CREDS', '-e', 'SHOTS=/shots',
     '-v', f'{here}:/tests:ro', '-v', f'{shots}:/shots', '-w', '/tests',
     os.environ.get('PLAYWRIGHT_IMAGE', 'mcr.microsoft.com/playwright/python:v1.55.0-noble'),
     'sh', '-c', 'pip install -q --timeout 20 --retries 2 playwright==1.55.0 >/dev/null 2>&1; python central_ui.py'],
    capture_output=True, text=True)
lines = [l for l in proc.stdout.splitlines() if l.startswith('{')]
check('browser run finished', proc.returncode == 0 and lines, (proc.returncode, proc.stderr[-1200:]))
if not lines:
    check.done()
ui = json.loads(lines[-1])
check('no JavaScript errors', ui['errors'] == [], ui['errors'])
check('Add button is not covered by the chat button', all(c is None for c in ui['add_button_covered_by']), ui['add_button_covered_by'])
check('chat button still visible on the ticket page', ui['fab_visible_on_ticket_page'])
check('request saved (redirected to the new ticket)', ui['request'], ui)
check('incident saved (redirected to the new ticket)', ui['incident'], ui)

facts = inspect_tickets([i for i in (ui['request'], ui['incident']) if i])
if ui['request']:
    f = facts[ui['request']]
    check('request: requester is the user, not the technician', f['requesters'] == ['itchat.test.user1'], f['requesters'])
    check('request: type Request', f['type'] == 2, f['type'])
    check('request: category chosen in the form', f['category'] == 'IT Support > Account > AD Account', f['category'])
    check('request: urgency High', f['urgency'] == 4, f['urgency'])
    check('request: technician assigned (form default "assign me")', 'itchat.test.tech' in f['assignees'], f['assignees'])
    check('request: requester group from the user\'s default group', f['requester_groups'] == ['[itchat-test] Group'], f['requester_groups'])
    check('request: approval sent to the group manager', f['approvers'] == ['itchat.test.tech'] and f['global_validation'] == 2,
          (f['approvers'], f['global_validation']))
    check('request: team from category rule', 'Helpdesk / Service Desk' in f['assign_groups'], f['assign_groups'])
    check('request: SLA assigned', f['sla_ttr'] > 0)
    check('request: source "Phone" (technicians\' template, setup-20)', f['requesttype_name'] == 'Phone', f['requesttype_name'])
if ui['incident']:
    f = facts[ui['incident']]
    check('incident: requester user2', f['requesters'] == ['itchat.test.user2'], f['requesters'])
    check('incident: type Incident', f['type'] == 1, f['type'])
    check('incident: category Printer', f['category'] == 'IT Support > Hardware > Printer', f['category'])
    check('incident: Hardware category -> Helpdesk team', 'Helpdesk / Service Desk' in f['assign_groups'], f['assign_groups'])
    check('incident: no approval', f['approvers'] == [], f['approvers'])

check.done()
