"""Plain GLPI ticket lifecycle (not only chat tickets), exercising this instance's ITSM setup:
category -> team rules (setup-05), priority -> SLA rules + escalation levels (setup-04/06),
Request -> group-manager approval (setup-08), take / followup / task / solution,
requester approves or refuses the solution, visibility between requesters, notifications.
Each step runs as the real test user inside the pod (fixtures.php ticket-lifecycle)."""
import json
import os
import subprocess

from lib import BASE, Checks, login

check = Checks('tickets')

out = subprocess.run(
    ['kubectl', '-n', os.environ.get('NS', 'glpi'), 'exec', os.environ['ITCHAT_TEST_POD'],
     '-c', os.environ.get('CONTAINER', 'glpi-app'), '--',
     'env', 'ITCHAT_TEST_CREDS=' + os.environ['ITCHAT_TEST_CREDS'],
     'php', '/tmp/itchat-tests/fixtures.php', 'ticket-lifecycle'],
    capture_output=True, text=True, check=True).stdout
res = json.loads(out.strip().splitlines()[-1])
check('scenario ran without error', 'error' not in res, res.get('error'))
S = {s['step'].split(' ')[0]: s for s in res['steps']}
st = lambda k: S[k]['state']

NEW, ASSIGNED, SOLVED, CLOSED = 1, 2, 5, 6
WAITING, ACCEPTED = 2, 3


def sla_for(priority):
    """setup-05 SLA rules: 5/6 Critical, 4 High, 3 Medium, 1/2 Low."""
    level = 'Critical' if priority >= 5 else 'High' if priority == 4 else 'Medium' if priority == 3 else 'Low'
    return f'{level} - TTO', f'{level} - TTR'


# --- A: incident, network, high urgency
a = st('A1')
check('A incident: requester is the creator', a['requesters'] == ['itchat.test.user1'], a['requesters'])
check('A incident: category rule -> Network Team', a['assign_groups'] == ['Network Team'], a['assign_groups'])
check('A incident: group assignment sets status "assigned"', a['status'] == ASSIGNED, a['status'])
check('A incident: SLA follows priority', (a['sla_tto'], a['sla_ttr']) == sla_for(a['priority']), (a['priority'], a['sla_tto'], a['sla_ttr']))
check('A incident: TTO / TTR due dates computed', a['time_to_own'] and a['time_to_resolve'], (a['time_to_own'], a['time_to_resolve']))
check('A incident: SLA escalation levels queued', a['escalation_levels'] >= 1, a['escalation_levels'])
check('A incident: no approval for incidents', a['validations'] == [] and a['global_validation'] in (0, 1), a['validations'])
vis = S['A2']['extra']
check('A visibility: requester can see own ticket', vis['user1'] is True)
check('A visibility: other requester cannot', vis['user2'] is False)
check('A visibility: technician can', vis['tech'] is True)
check('A tech takes the ticket', S['A3']['extra']['assigned'] and 'itchat.test.tech' in st('A3')['assignees'], st('A3')['assignees'])
check('A task time recorded on the ticket (30 min)', S['A4']['extra']['actiontime'] == 1800, S['A4']['extra'])
check('A solution -> status solved', st('A4')['status'] == SOLVED, st('A4')['status'])
check('A requester approves -> closed', st('A5')['status'] == CLOSED, st('A5')['status'])

# --- B: request, account, approval
b = st('B1')
check('B request: type Request', b['type'] == 2)
check('B request: approval sent to the group manager', b['validations'] == [{'to': 'itchat.test.tech', 'status': WAITING}], b['validations'])
check('B request: ticket waiting for approval', b['global_validation'] == WAITING, b['global_validation'])
check('B request: Account category -> Helpdesk team', b['assign_groups'] == ['Helpdesk / Service Desk'], b['assign_groups'])
check('B request: SLA follows priority', (b['sla_tto'], b['sla_ttr']) == sla_for(b['priority']), (b['priority'], b['sla_ttr']))
check('B manager approves', S['B2']['extra']['approved'] and st('B2')['global_validation'] == ACCEPTED, st('B2')['global_validation'])
check('B solved by tech2', st('B3')['status'] == SOLVED and 'itchat.test.tech2' in st('B3')['assignees'], (st('B3')['status'], st('B3')['assignees']))
check('B refusing without a reason is rejected', S['B4']['extra']['refuse_without_reason_accepted'] is False)
check('B requester refuses with reason -> reopened', st('B4')['status'] in (NEW, ASSIGNED), st('B4')['status'])

# --- C: no category, no group
c = st('C1')
check('C no category: no team assigned by rules', c['assign_groups'] == [], c['assign_groups'])
check('C no category: still gets an SLA from priority', (c['sla_tto'], c['sla_ttr']) == sla_for(c['priority']), (c['priority'], c['sla_ttr']))
check('C requester without group: no requester group', c['requester_groups'] == [], c['requester_groups'])

# --- D: very high urgency, server
d = st('D1')
check('D server: Storage category -> System Team', d['assign_groups'] == ['System Team'], d['assign_groups'])
check('D very high urgency -> higher priority than C', d['priority'] > c['priority'], (d['priority'], c['priority']))
check('D SLA follows priority', (d['sla_tto'], d['sla_ttr']) == sla_for(d['priority']), (d['priority'], d['sla_ttr']))
check('D TTR earlier than C (tighter SLA)', d['time_to_resolve'] < c['time_to_resolve'], (d['time_to_resolve'], c['time_to_resolve']))

# --- notifications (informational: test users have no e-mail, recipients are the other actors)
print('info: queued notifications per ticket:', {k: v['state']['queued_notifications'] for k, v in S.items()})
check('notifications are queued during the lifecycle', st('A5')['queued_notifications'] >= 1, st('A5')['queued_notifications'])

# --- the same visibility through the web UI
tid_a = S['A1']['ticket']
page = lambda s: s.get(f'{BASE}/front/ticket.form.php?id={tid_a}')
r = page(login('itchat.test.user2'))
check('web: other requester gets access denied', r.status_code in (403, 404) or 'Access denied' in r.text[:4000], r.status_code)
r = page(login('itchat.test.user1'))
check('web: requester opens own ticket', r.status_code == 200 and 'Access denied' not in r.text[:4000], r.status_code)
r = page(login('itchat.test.tech'))
check('web: technician opens it', r.status_code == 200 and 'Access denied' not in r.text[:4000], r.status_code)

check.done()
