"""1.6.0 features: transfer between technicians (with the chat's open ticket), ticket <-> chat followup sync,
satisfaction rating (+ dashboard cards), chat history search, and linking the new
ticket to a Problem from the เปิด Ticket dialog."""
import json
import os
import subprocess
import uuid

from lib import PREFIX, Checks, close_open_chat, get, inspect_tickets, login, poll, post, upload, png

check = Checks('collab')
u1, u2 = login('itchat.test.user1'), login('itchat.test.user2')
tech, tech2 = login('itchat.test.tech'), login('itchat.test.tech2')
for s in (u1, u2):
    close_open_chat(s)


def fixture(*args):
    out = subprocess.run(
        ['kubectl', '-n', os.environ.get('NS', 'glpi'), 'exec', os.environ['ITCHAT_TEST_POD'],
         '-c', os.environ.get('CONTAINER', 'glpi-app'), '--', 'php', '/tmp/itchat-tests/fixtures.php', *map(str, args)],
        capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


me = {name: poll(s, open=0)[1]['me'] for name, s in [('tech', tech), ('tech2', tech2), ('u2', u2)]}

# ---------------------------------------------------------------- transfer
c, r = post(u1, action='send', conv=0, content=f'{PREFIX} collab transfer')
conv = r['conv']
post(tech, action='claim', conv=conv)
techs = get(tech, action='techs').json()['techs']
check('tech list offers the other test tech', any(t['id'] == me['tech2'] for t in techs), techs)
check('tech list excludes myself', all(t['id'] != me['tech'] for t in techs))
check('requester cannot list techs', get(u1, action='techs').status_code == 403)
observer = login('itchat.test.observer')
check('Observer (no ticket update right) is not a technician', get(observer, action='techs').status_code == 403)
observer_id = poll(observer, open=0)[1]['me']
check('Observer is not offered as a transfer target', all(t['id'] != observer_id for t in techs), techs)
check('requester cannot transfer', post(u1, action='transfer', conv=conv, users_id=me['tech2'])[0] == 403)
check('cannot transfer to a non-technician', post(tech, action='transfer', conv=conv, users_id=me['u2'])[0] == 400)
c, r = post(tech, action='transfer', conv=conv, users_id=me['tech2'])
check('transfer to tech2', c == 200, r)
check('transfer to the current owner rejected', post(tech, action='transfer', conv=conv, users_id=me['tech2'])[0] == 400)
c, t = poll(tech2, open=0)
item = next((x for x in t['list'] if x['id'] == conv), None)
check('tech2 now owns the chat', item and item['tech_id'] == me['tech2'], item)
c, p = poll(u1, conv=conv)
check('requester sees the transfer notice', any(m['system'] and 'โอนแชทให้' in m['content'] for m in p['messages']))
check('requester header shows the new tech', p['conv']['tech_id'] == me['tech2'])

# ---------------------------------------------------------------- ticket <-> chat sync
c, r = post(tech2, action='toticket', conv=conv, type=1)
tid = r['tickets_id']
before = len(poll(u1, conv=conv)[1]['messages'])
fixture('followup', tid, 'itchat.test.tech2', 0, 'ตอบจากหน้า Ticket: เปลี่ยนสายแลนให้แล้ว')
c, p = poll(u1, conv=conv)
mirrored = [m for m in p['messages'] if m['content'].startswith('🎫') and 'เปลี่ยนสายแลน' in m['content']]
check('public ticket followup appears in the chat', len(mirrored) == 1 and not mirrored[0]['system'], p['messages'][-2:])
fixture('followup', tid, 'itchat.test.tech2', 1, 'โน้ตภายใน ห้ามให้ผู้ใช้เห็น')
c, p = poll(u1, conv=conv)
check('private followup NOT copied to the chat', not any('โน้ตภายใน' in m['content'] for m in p['messages']))
n_before = len(p['messages'])
post(u1, action='send', conv=conv, content='ใช้ได้แล้วครับ ขอบคุณครับ')
upload(u1, conv, 'after-ticket.png', png(), 'image/png')
c, p = poll(u1, conv=conv)
check('chat message after ticket: no echo back into chat (exactly +2 messages)', len(p['messages']) == n_before + 2, len(p['messages']) - n_before)
facts = inspect_tickets([tid])[tid]
fups = facts['followups']
check('requester chat message copied to the ticket as a followup', any('ใช้ได้แล้วครับ' in f for f in fups), fups)
check('file sent after ticket is attached to the ticket', facts['documents'] == 1 and any('after-ticket.png' in f for f in fups), (facts['documents'], fups))
check('followups not duplicated', sum('ใช้ได้แล้วครับ' in f for f in fups) == 1 and sum('เปลี่ยนสายแลน' in f for f in fups) == 1, fups)

# ---------------------------------------------------------------- rating
check('cannot rate an open chat', post(u1, action='rate', conv=conv, value=5)[0] == 400)
post(tech2, action='close', conv=conv)
check('other requester cannot rate', post(u2, action='rate', conv=conv, value=5)[0] == 404)
check('technician cannot rate', post(tech2, action='rate', conv=conv, value=5)[0] == 404)
for bad in (0, 6, -1):
    check(f'rating {bad} rejected', post(u1, action='rate', conv=conv, value=bad)[0] == 400)
c, r = post(u1, action='rate', conv=conv, value=4)
check('rate 4 stars', c == 200, r)
check('second rating rejected', post(u1, action='rate', conv=conv, value=1)[0] == 400)
c, t = poll(tech2, conv=conv, open=0)
check('tech sees the rating', t['conv']['satisfaction'] == 4, t['conv'])
c, u = poll(u1, open=0)
check('own rating message is not an unread badge for the requester', u['unread'] == 0, u['unread'])
dash = fixture('dashboard')
check('dashboard cards registered', set(dash['cards']) == {'itchat_csat_30d', 'itchat_chats_today', 'itchat_waiting'}, dash['cards'])
check('csat card shows an average out of 5', '/ 5' in str(dash['csat']['number']), dash['csat'])
check('chats-today card counts today\'s chats', int(dash['today']['number']) >= 1, dash['today'])

# ---------------------------------------------------------------- search
token = uuid.uuid4().hex[:10]
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} search token {token}')
sconv = r['conv']
post(u2, action='close', conv=sconv)
res = get(tech, action='search', q=token).json()['results']
check('search by message text (closed chat, any age)', [x['id'] for x in res] == [sconv] and token in res[0]['last_msg'], res)
res = get(tech, action='search', q='itchat.test.user2').json()['results']
check('search by requester login', sconv in [x['id'] for x in res], [x['id'] for x in res])
res = get(tech, action='search', q=f'#{tid}').json()['results']
check('search by ticket number', [x['id'] for x in res] == [conv], res)
check('search with 1 char returns nothing', get(tech, action='search', q='a').json()['results'] == [])
check('LIKE wildcards are escaped', get(tech, action='search', q='%%').json()['results'] == [])
check('requester cannot search', get(u1, action='search', q=token).status_code == 403)

# ---------------------------------------------------------------- problem link
for s in (u1, u2):
    close_open_chat(s)
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} problem link: printer jams again')
pconv = r['conv']
form = get(tech, action='ticketform', conv=pconv).json()
check('dialog offers "new Problem" to a technician', form['problems']['create'] is True, form['problems'])
check('dialog lists open Problems', isinstance(form['problems']['open'], list), form['problems'])
check('dialog offers Problem as a type', 'problem' in [t['value'] for t in form['types']], form['types'])
check('type Problem without a Problem refused', post(tech, action='toticket', conv=pconv, type='problem', problem=0)[0] == 400)
check('Problem link refused for a Request', post(tech, action='toticket', conv=pconv, type=2, problem=-1)[0] == 400)
check('unknown Problem id refused', post(tech, action='toticket', conv=pconv, type=1, problem=99999999)[0] == 400)
c, r = post(tech, action='toticket', conv=pconv, type='problem')  # defaults to a new Problem
pid = r.get('problems_id') if isinstance(r, dict) else None
check('new Problem created from the ticket', c == 200 and pid and not r['problem_failed'], r)
ptid = r.get('tickets_id')
check('ticket linked to the new Problem', inspect_tickets([ptid])[ptid]['problems'] == [pid])
check('type Problem files an Incident ticket', inspect_tickets([ptid])[ptid]['type'] == 1, inspect_tickets([ptid])[ptid])
c, p = poll(u2, conv=pconv)
check('requester is not told about the Problem', not any('Problem' in m['content'] for m in p['messages']))
c, r = post(u1, action='send', conv=0, content=f'{PREFIX} problem link: same printer again')
pconv2 = r['conv']
form = get(tech, action='ticketform', conv=pconv2).json()
check('the new Problem is offered for the next incident', pid in [x['id'] for x in form['problems']['open'] or []])
c, r = post(tech, action='toticket', conv=pconv2, type='problem', problem=pid)
check('second incident linked to the existing Problem', c == 200 and r['problems_id'] == pid, r)
check('Problem link visible on the ticket', inspect_tickets([r['tickets_id']])[r['tickets_id']]['problems'] == [pid])
c, r = post(u1, action='send', conv=pconv2, content='x')  # keep the chat usable after linking
check('chat still works after the link', c == 200, r)

# ---------------------------------------------------------------- transfer moves the chat's ticket
for s in (u1, u2):
    close_open_chat(s)
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} transfer with ticket')
tconv = r['conv']
post(tech, action='claim', conv=tconv)
tt = post(tech, action='toticket', conv=tconv, type=1)[1]['tickets_id']
check('ticket starts with the chat tech assigned', inspect_tickets([tt])[tt]['assignees'] == ['itchat.test.tech'], inspect_tickets([tt])[tt])
c, r = post(tech, action='transfer', conv=tconv, users_id=me['tech2'])
check('transfer reports the ticket moved', c == 200 and r.get('ticket_moved') is True, r)
check('ticket reassigned to the new tech in place of the old one',
      inspect_tickets([tt])[tt]['assignees'] == ['itchat.test.tech2'], inspect_tickets([tt])[tt])
c, p = poll(u2, conv=tconv)
check('transfer message mentions the ticket', any(m['system'] and f'Ticket #{tt}' in m['content'] and 'โอนแชทให้' in m['content'] for m in p['messages']))
fixture('ticket-status', tt, 5)  # solved
c, r = post(tech2, action='transfer', conv=tconv, users_id=me['tech'])
check('solved ticket stays with its assignee', c == 200 and r.get('ticket_moved') is False
      and inspect_tickets([tt])[tt]['assignees'] == ['itchat.test.tech2'], (r, inspect_tickets([tt])[tt]))
for s in (u1, u2):
    close_open_chat(s)

check.done()
