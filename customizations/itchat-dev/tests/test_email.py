"""E-mail channel (customizations/setup-19-mail-intake.php), end to end against the running cluster:
users' e-mails go over SMTP into the IT mailbox (greenmail in local-dev), the real glpi-cron CronJob
runs the Mail Receiver, tickets / followups appear, GLPI's confirmation e-mail reaches the user
(mailpit), and replying to that e-mail lands on the same ticket. Nothing is collected by hand, so
this also proves the cron wiring.

Env: MAILPIT_URL (default http://localhost:30825), MAIL_WAIT (seconds per wait, default 330).
"""
import base64
import json
import os
import secrets
import subprocess
import time

import requests

from lib import Checks, inspect_tickets, png

check = Checks('email')
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
MAILPIT = os.environ.get('MAILPIT_URL', 'http://localhost:30825').rstrip('/')
WAIT = int(os.environ.get('MAIL_WAIT', '330'))
DOMAIN = 'dev.glpi.labs'
marker = f'[itchat-test] MAIL {secrets.token_hex(3)}'


def fixtures(*args, stdin=None):
    out = subprocess.run(['kubectl', '-n', NS, 'exec', '-i', POD, '-c', CONTAINER, '--',
                          'php', '/tmp/itchat-tests/fixtures.php', *args],
                         input=stdin, capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


def send(**mail):
    return fixtures('mail-send', stdin=json.dumps(mail, ensure_ascii=False))


refused_seen = {}


def status():
    """GLPI empties its refused-mail list at every collect (it only shows the last run), so the
    refusals seen across polls are accumulated here."""
    st = fixtures('mail-status', marker)
    for r in st['refused']:
        refused_seen[(r['from'], r['subject'])] = r
    st['refused'] = list(refused_seen.values())
    return st


def wait_for(what, cond, timeout=WAIT):
    """Poll until cond(value) is truthy; the Mail Receiver runs every 2 min from glpi-cron."""
    end = time.time() + timeout
    while True:
        v = what()
        if cond(v) or time.time() > end:
            return v
        time.sleep(10)


def mailpit_find(to, text):
    r = requests.get(f'{MAILPIT}/api/v1/search', params={'query': f'to:"{to}" subject:"{text}"'}, timeout=10)
    r.raise_for_status()
    return r.json().get('messages', [])


# 1. three e-mails at once: a real user with a photo, a stranger, an out-of-office auto-reply
t0 = time.time()
send(**{'from': f'itchat.test.user1@{DOMAIN}', 'subject': f'{marker} ปริ้นเตอร์ชั้น 2 กระดาษติด',
        'body': 'ปริ้นเตอร์ชั้น 2 ขึ้นไฟแดง กระดาษติดทุกแผ่น แนบรูปหน้าจอเครื่องมาด้วยครับ',
        'attach': {'name': 'printer.png', 'mime': 'image/png', 'base64': base64.b64encode(png()).decode()}})
send(**{'from': 'stranger@example.com', 'subject': f'{marker} outsider wants a ticket', 'body': 'not a GLPI user'})
send(**{'from': f'itchat.test.user1@{DOMAIN}', 'subject': f'{marker} Out of office', 'body': 'I am away',
        'headers': {'Auto-Submitted': 'auto-replied'}})

st = wait_for(status, lambda s: s['tickets'] and s['refused'])
check('Mail Receiver ran from the glpi-cron CronJob (every 2 min)', st['tickets'], (round(time.time() - t0), st))
check('mailgate cron: every 2 minutes, external mode', st['mailgate']['frequency'] == 120 and st['mailgate']['mode'] == 2, st['mailgate'])
check('exactly one ticket (user\'s e-mail only, not the auto-reply or the stranger)', len(st['tickets']) == 1, st['tickets'])
check('stranger\'s e-mail refused: unknown sender', [(r['from'], r['reason']) for r in st['refused']] == [('stranger@example.com', 1)], st['refused'])
if not st['tickets']:
    check.done()
tid = st['tickets'][0]
f = inspect_tickets([tid])[tid]
check('ticket: requester is the sender', f['requesters'] == ['itchat.test.user1'], f['requesters'])
check('ticket: title from the subject', f['name'] == f'{marker} ปริ้นเตอร์ชั้น 2 กระดาษติด', f['name'])
check('ticket: description from the body', 'กระดาษติดทุกแผ่น' in f['content'], f['content'][:200])
check('ticket: source "E-Mail"', f['requesttype'] == st['mail_requesttype'], (f['requesttype'], st['mail_requesttype']))
check('ticket: attachment kept', f['documents'] >= 1, f['documents'])
check('ticket: no category -> Helpdesk team (setup-19 rule)', f['assign_groups'] == ['Helpdesk / Service Desk'], f['assign_groups'])
check('ticket: requester group from the user\'s default group', f['requester_groups'] == ['[itchat-test] Group'], f['requester_groups'])
check('ticket: SLA assigned', f['sla_ttr'] > 0)
check('ticket: Incident, no approval', f['type'] == 1 and f['approvers'] == [], (f['type'], f['approvers']))

# 2. GLPI's "new ticket" e-mail reaches the user, tagged with the ticket number
tag = f'#{tid:07d}'
msgs = wait_for(lambda: mailpit_find(f'itchat.test.user1@{DOMAIN}', tag), bool)
check('user receives the "new ticket" e-mail with the ticket number', msgs, tag)
if not msgs:
    check.done()
notif = msgs[-1]

# 3. the user hits Reply on that e-mail; a stranger tries to reply to the same ticket
reply_text = f'{marker} REPLY เปลี่ยนตลับหมึกแล้วก็ยังติดอยู่ครับ'
send(**{'from': f'itchat.test.user1@{DOMAIN}', 'subject': 'Re: ' + notif['Subject'],
        'body': reply_text + '\n\n> (quoted original e-mail)', 'headers': {'In-Reply-To': notif['MessageID'], 'References': notif['MessageID']}})
send(**{'from': 'stranger@example.com', 'subject': 'Re: ' + notif['Subject'] + f' {marker} outsider reply',
        'body': f'{marker} OUTSIDER please close this'})

st = wait_for(status, lambda s: s['followups'] and len(s['refused']) >= 2)
check('reply becomes a followup on the same ticket', [x['ticket'] for x in st['followups']] == [tid], st['followups'])
check('followup source "E-Mail"', st['followups'] and st['followups'][0]['requesttype'] == st['mail_requesttype'], st['followups'])
check('no new ticket from the reply', len(st['tickets']) == 1, st['tickets'])
# reason 1 = unknown sender, 3 = not allowed to add followups: either way it is refused
check('stranger\'s reply refused', any(r['from'] == 'stranger@example.com' and 'outsider reply' in r['subject'] and r['reason'] in (1, 3)
                                                            for r in st['refused']), st['refused'])
check('stranger\'s reply not on the ticket', all('OUTSIDER' not in x for x in inspect_tickets([tid])[tid]['followups']))
f = inspect_tickets([tid])[tid]
check('followup text is the reply, not the quoted e-mail', f['followups'] and 'เปลี่ยนตลับหมึก' in f['followups'][-1], f['followups'])

check.done()
