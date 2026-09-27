"""Negative / security checks: authentication, CSRF, isolation between requesters, role
checks, input validation, file-type handling, closed-chat rules, read receipts and a
concurrency check."""
import concurrent.futures as cf

import requests

from lib import ADMIN, API, BASE, PREFIX, Checks, close_open_chat, get, login, poll, post, upload

check = Checks('security')
u1, u2 = login('itchat.test.user1'), login('itchat.test.user2')
tech, admin = login('itchat.test.tech'), login(*ADMIN)
for s in (u1, u2):
    close_open_chat(s)
CFG = BASE + '/plugins/itchat/front/config.form.php'

c, r = poll(u2)
check('self-service user is a requester, not a tech', r['is_tech'] is False, r)
c, r = poll(tech)
check('technician profile is a tech', r['is_tech'] is True, r)

c, r = post(u1, action='send', conv=0, content=f'{PREFIX} security user1 private chat')
conv1 = r['conv']
c, r = upload(u1, conv1, 'secret.png', b'\x89PNG\r\n\x1a\n' + b'\0' * 64, 'image/png')
file_msg = r.get('id')
check('setup: upload', c == 200 and file_msg, r)

# --- authentication / CSRF
anon = requests.Session()
check('anonymous poll blocked', anon.get(API, params={'action': 'poll'}, allow_redirects=False).status_code in (302, 401, 403))
check('anonymous file download blocked', anon.get(API, params={'action': 'file', 'msg': file_msg}, allow_redirects=False).status_code in (302, 401, 403))
check('anonymous config page blocked', anon.get(CFG, allow_redirects=False).status_code in (302, 401, 403))
check('POST without CSRF token -> 403', post(u1, csrf=False, action='send', content='x')[0] == 403)
r = u1.post(API, data={'action': 'send', 'content': 'x'}, headers={'X-Requested-With': 'XMLHttpRequest', 'X-Glpi-Csrf-Token': 'forged'})
check('POST with forged CSRF token -> 403', r.status_code == 403, r.status_code)

# --- isolation between requesters
c, r = poll(u2, conv=conv1)
check('user2 cannot read user1 chat', r['conv'] is None and r['messages'] == [], r)
check('user2 cannot download user1 file', get(u2, action='file', msg=file_msg).status_code == 404)
check('user2 cannot close user1 chat', post(u2, action='close', conv=conv1)[0] == 404)
c, r = post(u2, action='send', conv=conv1, content=f'{PREFIX} injected?')
conv2 = r.get('conv')
check('user2 send with conv=user1 lands in own chat', c == 200 and conv2 != conv1, r)
c, r = poll(u1, conv=conv1)
check('nothing injected into user1 chat', not any('injected' in m['content'] for m in r['messages']))

# --- role checks
check('requester cannot claim', post(u1, action='claim', conv=conv1)[0] == 403)
check('requester cannot create ticket', post(u1, action='toticket', conv=conv1, type=1)[0] == 403)
check('requester cannot open ticket dialog', get(u1, action='ticketform', conv=conv1).status_code == 403)
check('technician cannot open config page', tech.get(CFG, allow_redirects=False).status_code == 403)
check('requester cannot open config page', u1.get(CFG, allow_redirects=False).status_code in (302, 403))
check('admin can open config page', admin.get(CFG).status_code == 200)

# --- input validation
check('empty message rejected', post(u1, action='send', conv=conv1, content='')[0] == 400)
check('whitespace-only message rejected', post(u1, action='send', conv=conv1, content='  \n ')[0] == 400)
post(u1, action='send', conv=conv1, content='ก' * 5000)
c, p = poll(u1, conv=conv1)
check('long message truncated to 2000 chars', max(len(m['content']) for m in p['messages']) == 2000)
xss = '<script>alert(1)</script><img src=x onerror=alert(2)>'
post(u1, action='send', conv=conv1, content=xss)
c, p = poll(u1, conv=conv1)
check('HTML kept as plain text (rendered with textContent)', any(m['content'] == xss for m in p['messages']))
check('unknown action -> 400', get(u1, action='nope').status_code == 400)
check('send via GET -> 405', get(u1, action='send', content='x').status_code == 405)
check('file for unknown message -> 404', get(u1, action='file', msg=99999999).status_code == 404)
for bad in ({'type': 7}, {'type': 1, 'urgency': 9}, {'type': 1, 'itilcategories_id': 99999999}):
    check(f'ticket dialog input rejected {bad}', post(tech, action='toticket', conv=conv1, **bad)[0] == 400)

# --- file types
for name, data in [('run.exe', b'MZ' + b'\0' * 50), ('shell.php', b'<?php echo 1;'), ('noext', b'abc'), ('x.js', b'alert(1)')]:
    c, r = upload(u1, conv1, name, data)
    check(f'{name} rejected', c == 400, (c, r))
c, r = upload(u1, conv1, '../../../etc/evil.png', b'\x89PNG\r\n\x1a\n' + b'\0' * 64, 'image/png')
check('path-traversal filename accepted but sanitised', c == 200, r)
ids = {}
for label, name, data, mime in [
    ('html', 'page.html', b'<html><script>alert(1)</script></html>', 'text/html'),
    ('svg', 'draw.svg', b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'),
    ('php-as-png', 'fake.php.png', b'<?php system($_GET[1]); ?>', 'image/png'),
]:
    ids[label] = upload(u1, conv1, name, data, mime)[1].get('id')
c, p = poll(u1, conv=conv1)
names = [m['file']['name'] for m in p['messages'] if m.get('file')]
check('stored filenames have no path separators', all('/' not in n and '\\' not in n for n in names), names)
for label, mid in ids.items():
    fr = get(u1, action='file', msg=mid)
    check(f'{label} served as attachment, never rendered', fr.status_code == 200
          and fr.headers.get('Content-Disposition', '').strip().startswith('attachment'), fr.headers.get('Content-Disposition'))
svg = next(m['file'] for m in p['messages'] if m['id'] == ids['svg'])
check('svg not shown inline as an image', svg['is_image'] is False, svg)

# --- closed-chat rules
c, r = post(tech, action='toticket', conv=conv1, type=1)
check('ticket from chat with files', c == 200 and r.get('tickets_id'), r)
page = admin.get(f'{BASE}/front/ticket.form.php?id={r.get("tickets_id")}').text
check('ticket page escapes chat HTML', '<script>alert(1)</script>' not in page)
post(tech, action='close', conv=conv1)
check('tech cannot send to closed chat', post(tech, action='send', conv=conv1, content='x')[0] == 400)
check('cannot create ticket from closed chat', post(tech, action='toticket', conv=conv1, type=1)[0] == 400)
check('cannot claim closed chat', post(tech, action='claim', conv=conv1)[0] == 400)
post(u2, action='close', conv=conv2)

# --- unread / receipts, tech -> requester, incl. reply-then-close
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} receipts')
conv3 = r['conv']
c, r = post(tech, action='send', conv=conv3, content='ตอบแล้วครับ')
tmsg = r['id']
c, t = poll(tech, conv=conv3, open=0)
check('tech receipt unread while requester away', t['peer_read'] < tmsg, t['peer_read'])
post(tech, action='close', conv=conv3)
c, u = poll(u2, open=0)
check('requester still sees reply after tech closed (badge + chat)', u['unread'] >= 1 and u['conv'] and u['conv']['id'] == conv3, u)
poll(u2, conv=conv3)
c, t = poll(tech, conv=conv3, open=0)
check('tech receipt read after requester opened', t['peer_read'] >= tmsg, t['peer_read'])
c, u = poll(u2, open=0)
check('requester badge cleared after reading', u['unread'] == 0 and u['conv'] is None, u)

# --- requester writes then closes: tech must still see it as unread
c, t0 = poll(tech, open=0)
c, r = post(u2, action='send', conv=0, content=f'{PREFIX} wrote then closed')
conv4 = r['conv']
post(u2, action='close', conv=conv4)
c, t = poll(tech, open=0)
item = next((x for x in t['list'] if x['id'] == conv4), None)
check('closed chat with unread message still counted and listed', item and item['unread'] == 1 and t['unread'] == t0['unread'] + 1, (item, t0['unread'], t['unread']))
poll(tech, conv=conv4)

# --- concurrency: parallel first messages from one requester
with cf.ThreadPoolExecutor(10) as ex:
    outs = list(ex.map(lambda k: post(u2, action='send', conv=0, content=f'{PREFIX} parallel {k}'), range(10)))
convs = {o[1].get('conv') for o in outs if o[0] == 200}
check('10 parallel sends all succeed', all(o[0] == 200 for o in outs), [o[0] for o in outs])
check('parallel sends end in ONE conversation', len(convs) == 1, convs)
for cid in convs:
    post(u2, action='close', conv=cid)

check.done()
