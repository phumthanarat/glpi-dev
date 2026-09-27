"""Load + coverage: LOAD_PER_CHANNEL tickets (default 400 -> 2000 total) through every ticket
channel, several at a time, then every ticket is checked against the business rules.

  helpdesk  Helpdesk forms "Report an issue" / "Request a service" (POST /Form/SubmitAnswers)
  central   a technician's "+ Add" form on behalf of a requester (POST front/ticket.form.php)
  chat      IT Chat: user writes -> technician opens a ticket from the chat -> closes it
  qr        QR label report page (POST plugins/itqr/front/report.php)
  email     e-mails into the IT mailbox, collected by the real glpi-cron CronJob

Every request is what the browser sends: a real login session, GLPI's CSRF token, the same
fields; the browser suites (helpdesk, central, qr, ui) prove the pages produce those requests.

Env: LOAD_PER_CHANNEL (400), LOAD_WORKERS (8), LOAD_MAIL_WAIT (seconds, 2400),
     LOAD_PURGE=1 to delete the load tickets at the end (default: kept; with KEEP_DATA=1 for run.sh).
"""
import json
import os
import re
import statistics
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from threading import Lock, local

from lib import API, BASE, Checks, inspect_tickets, login

N = int(os.environ.get('LOAD_PER_CHANNEL', '400'))
WORKERS = int(os.environ.get('LOAD_WORKERS', '8'))
MAIL_WAIT = int(os.environ.get('LOAD_MAIL_WAIT', '2400'))
NS, POD, CONTAINER = os.environ.get('NS', 'glpi'), os.environ['ITCHAT_TEST_POD'], os.environ.get('CONTAINER', 'glpi-app')
check = Checks(f'load {N * 5}')
TAG = f'[itchat-test] LOAD{int(time.time()) % 100000}'
XHR = {'X-Requested-With': 'XMLHttpRequest'}
USERS = ['itchat.test.user1', 'itchat.test.user2']
TECH = 'itchat.test.tech'


def fixtures(*args, stdin=None):
    out = subprocess.run(['kubectl', '-n', NS, 'exec', '-i', POD, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', *args],
                         input=stdin, capture_output=True, text=True, check=True).stdout
    return json.loads(out.strip().splitlines()[-1])


CATS = fixtures('categories')
FORMS = fixtures('forms')
QR = fixtures('qr-assets')
# (category, team from the setup-05 rules); None = no category
PLAN = [
    ('IT Support > Network > VPN', 'Network Team'),
    ('IT Support > Account > AD Account', 'Helpdesk / Service Desk'),
    ('IT Support > Server > Linux', 'System Team'),
    ('IT Support > Software > Application', 'Application Team'),
    ('IT Support > Hardware > Printer', 'Helpdesk / Service Desk'),
    (None, None),
]
URGENCY = [2, 3, 4, 5]

# one login per worker thread and user: GLPI serialises requests on one PHP session
_tls = local()


def session(user):
    if not hasattr(_tls, 's'):
        _tls.s = {}
    if user not in _tls.s:
        _tls.s[user] = login(user)
    return _tls.s[user]


def ticket_id_from(r):
    m = re.search(r'ticket\.form\.php\?id=(\d+)', r.headers.get('Location', '') + ' ' + r.url + ' ' + r.text[:2000])
    return int(m.group(1)) if m else None


def job_helpdesk(i):
    user = USERS[i % 2]
    form = FORMS['Report an issue'] if i % 2 else FORMS['Request a service']
    q = form['questions']
    cat, team = PLAN[i % len(PLAN)]
    s = session(user)
    data = {
        'forms_id': form['id'],
        f"answers_{q['Urgency']}": URGENCY[i % 4],
        f"answers_{q['Title']}": f'{TAG} helpdesk #{i}',
        f"answers_{q['Description']}": f'<p>{TAG} helpdesk #{i} รายละเอียดปัญหา</p>',
    }
    if cat:
        data[f"answers_{q['Category']}[itemtype]"] = 'ITILCategory'
        data[f"answers_{q['Category']}[items_id]"] = CATS[cat]
    r = s.post(BASE + '/Form/SubmitAnswers', data=data, headers={**XHR, 'X-Glpi-Csrf-Token': s.csrf})
    links = r.json().get('links_to_created_items', []) if r.status_code == 200 else []
    m = re.search(r'id=(\d+)', ' '.join(links))
    return (int(m.group(1)) if m else None), dict(user=user, type=1 if i % 2 else 2, cat=cat, team=team, urgency=URGENCY[i % 4]), r.status_code


_central_source = {}


def central_form_source(t):
    """The request source the technician's "+ Add" form pre-selects (setup-20: Phone), sent back
    as-is like the browser does."""
    if 'id' not in _central_source:
        # the form's fields come in the "main" tab, loaded by AJAX like the browser does
        html = t.get(BASE + '/ajax/common.tabs.php', headers=XHR, params={
            '_target': '/front/ticket.form.php', '_itemtype': 'Ticket', '_glpi_tab': 'Ticket$main', 'id': 0, 'withtemplate': ''}).text
        start = html.find('name="requesttypes_id"')
        if start < 0:
            start = html.find("name='requesttypes_id'")
        block = html[start:html.find('</select>', start)] if start >= 0 else ''
        opt = re.search(r'<option\b[^>]*\bselected\b[^>]*>', block)
        val = re.search(r'value=["\']?(\d+)', opt.group(0)) if opt else None
        if not val:
            raise RuntimeError('central form: no pre-selected request source found')
        _central_source['id'] = int(val.group(1))
    return _central_source['id']


def job_central(i):
    user = USERS[i % 2]
    cat, team = PLAN[i % len(PLAN)]
    t = session(TECH)
    ids = fixtures_user_ids()
    source = central_form_source(t)
    r = t.post(BASE + '/front/ticket.form.php', allow_redirects=False, headers={**XHR, 'X-Glpi-Csrf-Token': t.csrf}, data={
        'add': 1, '_glpi_csrf_token': t.csrf, 'entities_id': 0,
        'name': f'{TAG} central #{i}', 'content': f'<p>{TAG} central #{i} ผู้ใช้โทรแจ้ง</p>',
        'type': 1 if i % 2 else 2, 'itilcategories_id': CATS[cat] if cat else 0, 'urgency': URGENCY[i % 4],
        'requesttypes_id': source,
        '_actors': json.dumps({
            'requester': [{'itemtype': 'User', 'items_id': ids[user], 'use_notification': 1}],
            'observer': [],
            'assign': [{'itemtype': 'User', 'items_id': ids[TECH], 'use_notification': 1}],
        }),
    })
    return ticket_id_from(r), dict(user=user, type=1 if i % 2 else 2, cat=cat, team=team, urgency=URGENCY[i % 4], assignee=TECH), r.status_code


_chat_locks = {u: Lock() for u in USERS}


def job_chat(i):
    user = USERS[i % 2]
    # IT Chat allows ONE open chat per user (a second "send" joins it): two workers chatting as
    # the same user at once would share a chat. Real people don't; one chat at a time per user.
    with _chat_locks[user]:
        return _job_chat(i, user)


def _job_chat(i, user):
    cat, team = PLAN[i % len(PLAN)]
    u, t = session(user), session(TECH)
    h = lambda s: {**XHR, 'X-Glpi-Csrf-Token': s.csrf}
    r = u.post(API, data={'action': 'send', 'conv': 0, 'content': f'{TAG} chat #{i} ขอความช่วยเหลือ'}, headers=h(u)).json()
    conv = r['conv']
    r = t.post(API, headers=h(t), data={'action': 'toticket', 'conv': conv, 'type': 1 if i % 2 else 2,
                                        'itilcategories_id': CATS[cat] if cat else 0, 'urgency': URGENCY[i % 4]})
    tid = r.json().get('tickets_id') if r.status_code == 200 else None
    t.post(API, data={'action': 'close', 'conv': conv}, headers=h(t))
    return tid, dict(user=user, type=1 if i % 2 else 2, cat=cat, team=team, urgency=URGENCY[i % 4]), r.status_code


def job_qr(i):
    user = USERS[i % 2]
    asset = QR['printer'] if i % 2 else QR['computer']
    params = dict(re.findall(r'[?&](t|id|s)=([^&]+)', asset['url']))
    s = session(user)
    r = s.post(BASE + '/plugins/itqr/front/report.php', allow_redirects=False, headers={**XHR, 'X-Glpi-Csrf-Token': s.csrf}, data={
        **params, '_glpi_csrf_token': s.csrf, 'report': 1, 'urgency': [2, 3, 4][i % 3],
        'content': f'{TAG} qr #{i} เครื่องมีปัญหา',
    })
    cat = QR['categories'][params['t']]
    return ticket_id_from(r), dict(user=user, type=1, cat=cat, team='Helpdesk / Service Desk', urgency=[2, 3, 4][i % 3],
                                   item=f"{params['t']}:{params['id']}"), r.status_code


_uids = {}


def fixtures_user_ids():
    if not _uids:
        _uids.update(fixtures('user-ids', *USERS, TECH))
    return _uids


def run_channel(name, job):
    t0 = time.time()
    results, errors, lat = [], [], []

    def timed(i):
        s = time.time()
        try:
            tid, exp, status = job(i)
        except Exception as e:  # noqa: BLE001 - counted as a failed request
            return None, None, f'{type(e).__name__}: {e}', time.time() - s
        return tid, exp, (None if tid else f'HTTP {status}'), time.time() - s

    with ThreadPoolExecutor(WORKERS) as pool:
        for f in as_completed([pool.submit(timed, i) for i in range(1, N + 1)]):
            tid, exp, err, dt = f.result()
            lat.append(dt)
            if err:
                errors.append(err)
            else:
                results.append((tid, exp))
    wall = time.time() - t0
    stats = dict(ok=len(results), errors=len(errors), wall_s=round(wall, 1), per_s=round(len(results) / wall, 1),
                 p50_ms=round(statistics.median(lat) * 1000), p95_ms=round(sorted(lat)[int(len(lat) * 0.95) - 1] * 1000))
    print(f'  {name:9s} {stats}', flush=True)
    check(f'{name}: {N} tickets created, no failed request', len(results) == N and not errors, (stats, errors[:3]))
    return results, stats


def email_channel():
    t0 = time.time()
    mails = [{'from': f'{USERS[i % 2]}@dev.glpi.labs', 'subject': f'{TAG} email #{i}', 'body': f'{TAG} email #{i} ปัญหาทางอีเมล'}
             for i in range(1, N + 1)]
    fixtures('mail-send-batch', stdin=json.dumps(mails, ensure_ascii=False))
    prev = fixtures('mailgate-param', '100')['previous']  # 10 per run would take N/10 cron runs
    try:
        end = time.time() + MAIL_WAIT
        while True:
            st = fixtures('mail-status', f'{TAG} email #')
            if len(st['tickets']) >= N or time.time() > end:
                break
            time.sleep(20)
    finally:
        fixtures('mailgate-param', str(prev))
    wall = time.time() - t0
    stats = dict(ok=len(st['tickets']), wall_s=round(wall, 1), per_s=round(len(st['tickets']) / wall, 2))
    print(f'  email     {stats}', flush=True)
    check(f'email: {N} tickets imported by the cron', len(st['tickets']) == N, stats)
    return [(tid, dict(user=None, type=1, cat=None, team='Helpdesk / Service Desk', urgency=3)) for tid in st['tickets']], stats


def pods():
    j = json.loads(subprocess.run(['kubectl', '-n', NS, 'get', 'pods', '-l', 'app=glpi-app', '-o', 'json'], capture_output=True, text=True).stdout)
    return {p['metadata']['name']: sum(c['restartCount'] for c in p['status'].get('containerStatuses', [])) for p in j['items']}


before = pods()
print(f'{N} tickets per channel, all 5 channels at once, {WORKERS} requests at a time per channel, tag {TAG}', flush=True)
all_results, stats = {}, {}
# All channels at once, like real traffic: the e-mail channel mostly waits for the cron (every
# 2 min), so the web channels run meanwhile instead of after it.
t_load = time.time()
with ThreadPoolExecutor(5) as channels:
    futures = {'email': channels.submit(email_channel)}
    for name, job in [('helpdesk', job_helpdesk), ('central', job_central), ('chat', job_chat), ('qr', job_qr)]:
        futures[name] = channels.submit(run_channel, name, job)
    for name, fut in futures.items():
        all_results[name], stats[name] = fut.result()
print(f'  all channels done in {round(time.time() - t_load)} s', flush=True)
after = pods()
check('no GLPI pod restarted during the load', all(after.get(k, 0) == v for k, v in before.items()), (before, after))

# verify every ticket
ids = [tid for res in all_results.values() for tid, _ in res]
facts = {}
for k in range(0, len(ids), 250):
    facts.update(inspect_tickets(ids[k:k + 250]))
sources = {}
for name, res in all_results.items():
    bad = {'requester': [], 'category': [], 'team': [], 'sla': [], 'urgency': [], 'approval': [], 'item': [], 'assignee': [], 'type': []}
    for tid, exp in res:
        f = facts.get(tid)
        if not f:
            bad['requester'].append((tid, 'missing'))
            continue
        sources.setdefault(name, {}).setdefault(f['requesttype_name'], 0)
        sources[name][f['requesttype_name']] += 1
        if exp['user'] and f['requesters'] != [exp['user']]:
            bad['requester'].append((tid, f['requesters']))
        if not exp['user'] and len(f['requesters']) != 1:
            bad['requester'].append((tid, f['requesters']))
        if f['category'] != exp['cat']:
            bad['category'].append((tid, f['category'], exp['cat']))
        if exp['team'] and exp['team'] not in f['assign_groups']:
            bad['team'].append((tid, f['assign_groups'], exp['team']))
        if not exp['team'] and f['assign_groups']:
            bad['team'].append((tid, f['assign_groups'], None))
        if f['sla_ttr'] <= 0:
            bad['sla'].append(tid)
        if f['urgency'] != exp['urgency']:
            bad['urgency'].append((tid, f['urgency'], exp['urgency']))
        if f['type'] != exp['type']:
            bad['type'].append((tid, f['type'], exp['type']))
        # Requests from user1 (group with a manager) need the manager's approval; nothing else does
        needs = f['type'] == 2 and f['requesters'] == ['itchat.test.user1']
        if needs != (f['approvers'] == [TECH]) or (not needs and f['approvers']):
            bad['approval'].append((tid, f['type'], f['requesters'], f['approvers']))
        if exp.get('item') and f['items'] != [exp['item']]:
            bad['item'].append((tid, f['items']))
        if exp.get('assignee') and exp['assignee'] not in f['assignees']:
            bad['assignee'].append((tid, f['assignees']))
    for what, lst in bad.items():
        if what == 'item' and name != 'qr' or what == 'assignee' and name != 'central':
            continue
        check(f'{name}: {what} correct on all {len(res)} tickets', not lst, (len(lst), lst[:3]))

print('\nsource (request type) per channel:', json.dumps(sources, ensure_ascii=False), flush=True)
EXPECTED_SOURCE = {'email': 'E-Mail', 'helpdesk': 'Helpdesk', 'central': 'Phone', 'chat': 'Chat', 'qr': 'QR code'}
for name, want in EXPECTED_SOURCE.items():
    check(f'{name}: every ticket has source "{want}" (channels tell apart in reports)', sources.get(name) == {want: len(all_results[name])}, sources.get(name))
print('throughput:', json.dumps(stats, ensure_ascii=False), flush=True)

# The load tickets are kept by default (to look at them in GLPI; run with KEEP_DATA=1 so run.sh's
# teardown keeps them too). LOAD_PURGE=1 purges them in parallel, one shard per GLPI pod
# (run.sh's teardown deletes one at a time on one pod: ~6 min for 2000).
if os.environ.get('LOAD_PURGE') != '1':
    print(f'kept {len(ids)} load tickets (tag {TAG})', flush=True)
    check.done()
t_purge = time.time()
live = [p for p in pods()] or [POD]
for p in live:
    subprocess.run(['kubectl', '-n', NS, 'exec', p, '-c', CONTAINER, '--', 'mkdir', '-p', '/tmp/itchat-tests'], capture_output=True)
    subprocess.run(['kubectl', '-n', NS, 'cp', os.path.join(os.path.dirname(os.path.abspath(__file__)), 'fixtures.php'),
                    f'{p}:/tmp/itchat-tests/fixtures.php', '-c', CONTAINER], capture_output=True)
shards = [ids[k::len(live) * 2] for k in range(len(live) * 2)]


def purge(k):
    p = live[k % len(live)]
    r = subprocess.run(['kubectl', '-n', NS, 'exec', '-i', p, '-c', CONTAINER, '--', 'php', '/tmp/itchat-tests/fixtures.php', 'purge-tickets'],
                       input=','.join(map(str, shards[k])), capture_output=True, text=True)
    return json.loads(r.stdout.strip().splitlines()[-1])['purged'] if r.returncode == 0 else 0


with ThreadPoolExecutor(len(shards)) as pool:
    purged = sum(pool.map(purge, range(len(shards))))
print(f'purged {purged}/{len(ids)} load tickets in {round(time.time() - t_purge)} s', flush=True)
check.done()
