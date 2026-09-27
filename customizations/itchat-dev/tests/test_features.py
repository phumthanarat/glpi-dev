"""1.5.0 features: off-hours auto-reply, canned replies, idle auto-close + retention.

Uses fixtures.php to force "outside business hours" (a test calendar with no working hours,
restored afterwards) and to age the test chats before running the hourly automatic action
once. Running that action is the same as the scheduled cron run: it also applies to real
chats that are past the configured limits.
"""
import json
import os
import subprocess

from lib import PDF, PREFIX, Checks, close_open_chat, login, png, poll, post, upload

check = Checks('features')
u1, u2, tech = login('itchat.test.user1'), login('itchat.test.user2'), login('itchat.test.tech')
for s in (u1, u2):
    close_open_chat(s)


def fixture(*args):
    out = subprocess.run(
        ['kubectl', '-n', os.environ.get('NS', 'glpi'), 'exec', os.environ['ITCHAT_TEST_POD'],
         '-c', os.environ.get('CONTAINER', 'glpi-app'), '--', 'php', '/tmp/itchat-tests/fixtures.php', *map(str, args)],
        capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


# --- canned replies
c, r = poll(tech, open=0, canned=1)
check('tech receives canned replies', isinstance(r.get('canned'), list) and len(r['canned']) >= 1, r.get('canned'))
c, r = poll(tech, open=0)
check('canned replies only sent when asked', r.get('canned') is None)
c, r = poll(u1, open=0, canned=1)
check('requester never receives canned replies', r.get('canned') is None)

# --- off-hours auto-reply
info = fixture('offhours', 'on')
try:
    c, r = post(u1, action='send', conv=0, content=f'{PREFIX} off-hours first message')
    conv = r['conv']
    c, p = poll(u1, conv=conv)
    auto = [m for m in p['messages'] if m['system']]
    check('off-hours: auto-reply added to a new chat', any(m['content'] == info['message'] for m in auto), auto)
    post(u1, action='send', conv=conv, content=f'{PREFIX} second message')
    c, p = poll(u1, conv=conv)
    check('off-hours: auto-reply only once per chat', sum(1 for m in p['messages'] if m['content'] == info['message']) == 1)
    c, t = poll(tech, open=0)
    item = next((x for x in t['list'] if x['id'] == conv), None)
    check('off-hours: auto-reply does not count as read by the tech', item and item['unread'] >= 2, item)
    post(u1, action='close', conv=conv)
finally:
    restored = fixture('offhours', 'off')
check('real calendar setting restored after the test', 'restored' in restored, restored)

# --- idle auto-close + retention (automatic action)
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} will go idle')
idle = r['conv']
c, r = post(u1, action='send', conv=0, content=f'{PREFIX} old, file not on a ticket')
old = r['conv']
upload(u1, old, 'old.png', png(), 'image/png')
post(u1, action='close', conv=old)
c, r = post(u1, action='send', conv=0, content=f'{PREFIX} old, file attached to ticket')
kept = r['conv']
upload(u1, kept, 'kept.pdf', PDF, 'application/pdf')
c, r = post(tech, action='toticket', conv=kept, type=1)
check('setup: ticket with the file', c == 200, r)
post(tech, action='close', conv=kept)

res = fixture('maintenance', idle, old, kept)
check('automatic action registered', res['cron_registered'], res)
if res['idle_hours'] > 0:
    check(f'idle chat (> {res["idle_hours"]} h) auto-closed', res['idle_status'] == 'closed', res)
    check('auto-close leaves a system message', (res['idle_last_message'] or '').startswith('ปิดอัตโนมัติ'), res['idle_last_message'])
if res['retention_days'] > 0:
    check(f'closed chat older than {res["retention_days"]} days deleted', not res['old_exists'] and res['old_messages'] == 0, res)
    check('its unlinked file deleted', not res['old_doc_exists'], res)
    check('file attached to a ticket kept', res['kept_doc_exists'], res)
c, u = poll(u2, open=0)
check('requester sees the auto-closed chat state', u['conv'] is None or u['conv']['status'] == 'closed', u)

check.done()
