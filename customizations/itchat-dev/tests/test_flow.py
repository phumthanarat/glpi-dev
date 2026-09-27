"""End-to-end chat -> ticket flow, repeated N times with variations.

  python3 test_flow.py [N]      (default 3 = smoke; run.sh full uses 30)

Each round: requester writes (sometimes attaches an image or PDF), the technician sees it
unread at the top of the inbox, opens it (read receipts flip), sometimes claims first,
replies, opens a ticket through the dialog fields (type / category / urgency) and closes.
Tickets are then checked via fixtures.php inspect: requester, assignee, requester group,
approval, category, urgency, attachments, and the team from the category rules.
"""
import sys

from lib import BASE, PDF, PREFIX, Checks, close_open_chat, get, inspect_tickets, login, png, poll, post, upload

N = int(sys.argv[1]) if len(sys.argv) > 1 else 3
check = Checks(f'flow x{N}')

tech = login('itchat.test.tech')
users = {'itchat.test.user1': login('itchat.test.user1'), 'itchat.test.user2': login('itchat.test.user2')}
for s in users.values():
    close_open_chat(s)

# (type, category, team expected from the setup-05 auto-assign rules)
PLAN = [
    (1, 'IT Support > Network > VPN', 'Network Team'),
    (2, 'IT Support > Account > AD Account', 'Helpdesk / Service Desk'),
    (1, 'IT Support > Server > Linux', 'System Team'),
    (1, 'IT Support > Software > Application', 'Application Team'),
    (2, None, None),
]
expect = {}

for i in range(1, N + 1):
    uname = 'itchat.test.user1' if i % 2 else 'itchat.test.user2'
    user = users[uname]
    attach = [None, 'image', 'pdf'][i % 3]
    ttype, cat_name, team = PLAN[i % len(PLAN)]
    try:
        c, r = post(user, action='send', conv=0, content=f'{PREFIX} flow #{i} ปัญหาทดสอบ')
        check(f'#{i} user send', c == 200 and r.get('ok'), r)
        conv, first = r['conv'], r['id']
        if attach == 'image':
            c, r = upload(user, conv, f'screen-{i}.png', png(), 'image/png')
            check(f'#{i} upload image', c == 200, r)
        elif attach == 'pdf':
            c, r = upload(user, conv, f'เอกสาร-{i}.pdf', PDF, 'application/pdf')
            check(f'#{i} upload pdf', c == 200, r)

        c, u = poll(user, conv=conv)
        check(f'#{i} receipt: sent (not read yet)', u['peer_read'] < first, u['peer_read'])
        c, t = poll(tech, open=0)
        item = next((x for x in t['list'] if x['id'] == conv), None)
        check(f'#{i} inbox: chat listed as unread', item and item['unread'] >= 1, item)
        check(f'#{i} inbox: unread chats sorted first',
              all(x['unread'] > 0 for x in t['list'][:t['list'].index(item) + 1]) if item else False)
        check(f'#{i} badge counts it', t['unread'] >= 1, t['unread'])

        if i % 4 == 0:
            c, r = post(tech, action='claim', conv=conv)
            check(f'#{i} claim', c == 200, r)
        c, t = poll(tech, conv=conv)
        files = [m['file'] for m in t['messages'] if m.get('file')]
        check(f'#{i} tech sees attachment', len(files) == (1 if attach else 0), files)
        for f in files:
            check(f'#{i} tech downloads file', tech.get(BASE + f['url']).status_code == 200)
        c, u = poll(user, conv=conv)
        check(f'#{i} receipt: read after tech opened', u['peer_read'] >= first, u['peer_read'])

        c, r = post(tech, action='send', conv=conv, content='รับทราบครับ')
        check(f'#{i} tech reply', c == 200, r)
        c, u = poll(user, conv=conv)
        check(f'#{i} user got reply + claim notice', any(m['content'] == 'รับทราบครับ' for m in u['messages'])
              and any(m['system'] and 'รับเรื่องแล้ว' in m['content'] for m in u['messages']))

        form = get(tech, action='ticketform', conv=conv).json()
        check(f'#{i} dialog: suggested title', form['title'].startswith('แชท: ' + PREFIX), form['title'])
        grp = form['requester_group']
        if uname == 'itchat.test.user1':
            check(f'#{i} dialog: requester group with manager', grp and grp['has_manager'], grp)
        else:
            check(f'#{i} dialog: no requester group', grp is None, grp)
        cat_id = next((c['id'] for c in form['categories'] if c['name'] == cat_name), 0) if cat_name else 0
        check(f'#{i} ticket without type rejected', post(tech, action='toticket', conv=conv)[0] == 400)
        c, r = post(tech, action='toticket', conv=conv, type=ttype, itilcategories_id=cat_id, urgency=4)
        check(f'#{i} toticket', c == 200 and r.get('tickets_id'), r)
        check(f'#{i} second toticket rejected', post(tech, action='toticket', conv=conv, type=1)[0] == 400)
        expect[r['tickets_id']] = dict(user=uname, ttype=ttype, cat=cat_name, team=team, files=1 if attach else 0)

        closer = user if i % 2 == 0 else tech
        c, r = post(closer, action='close', conv=conv)
        check(f'#{i} close', c == 200, r)
        check(f'#{i} no sending into closed chat (tech)', post(tech, action='send', conv=conv, content='x')[0] == 400)
    except Exception as e:  # keep going; report as a failure
        check(f'#{i} exception', False, repr(e)[:200])
        for s in users.values():
            close_open_chat(s)

facts = inspect_tickets(expect.keys()) if expect else {}
for tid, e in expect.items():
    f = facts.get(tid) or {}
    check(f'ticket {tid}: requester', f.get('requesters') == [e['user']], f.get('requesters'))
    check(f'ticket {tid}: assignee is the tech', f.get('assignees') == ['itchat.test.tech'], f.get('assignees'))
    check(f'ticket {tid}: type', f.get('type') == e['ttype'], f.get('type'))
    check(f'ticket {tid}: category', f.get('category') == e['cat'], f.get('category'))
    check(f'ticket {tid}: urgency', f.get('urgency') == 4, f.get('urgency'))
    check(f'ticket {tid}: source "Chat"', f.get('requesttype_name') == 'Chat', f.get('requesttype_name'))
    check(f'ticket {tid}: attachments', f.get('documents') == e['files'], f.get('documents'))
    if e['user'] == 'itchat.test.user1':
        check(f'ticket {tid}: requester group', f.get('requester_groups') == ['[itchat-test] Group'], f.get('requester_groups'))
        if e['ttype'] == 2:
            check(f'ticket {tid}: Request sent for approval to group manager',
                  f.get('approvers') == ['itchat.test.tech'] and f.get('global_validation') == 2, (f.get('approvers'), f.get('global_validation')))
    else:
        check(f'ticket {tid}: no requester group', f.get('requester_groups') == [], f.get('requester_groups'))
        check(f'ticket {tid}: no approval without group', f.get('approvers') == [], f.get('approvers'))
    if e['team']:
        check(f'ticket {tid}: team from category rule', e['team'] in (f.get('assign_groups') or []), f.get('assign_groups'))

check.done()
