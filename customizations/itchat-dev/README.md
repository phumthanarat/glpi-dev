# itchat-dev: deploy script and tests for the IT Chat plugin

The plugin itself lives in `../itchat/`. This folder is **not** copied to the server.

## Deploy

```bash
customizations/itchat-dev/deploy.sh            # lint, build, copy, restart/update only if needed, verify
customizations/itchat-dev/deploy.sh --test     # ... then run the smoke tests
customizations/itchat-dev/deploy.sh --dry-run  # show what would change
```

What it decides for you:

| You changed | What happens | Widget downtime |
|---|---|---|
| `public/chat.js` / `chat.css` only | new content-hashed files in `public/dist/` and a new `manifest.json`. No restart | none |
| any PHP file | rolling restart of `deploy/glpi-app` (opcache has `validate_timestamps=0`) | none |
| `PLUGIN_ITCHAT_VERSION` in `setup.php` | `plugin:install --force` + `plugin:activate` (runs the migrations in `hook.php`) | a few seconds |

After every deploy, `deploy.sh` logs in as a requester and a technician in a real (headless) browser (`tests/check_widget.py`) and checks
that the 💬 widget renders with no JavaScript errors. If it doesn't, `manifest.json` is pointed back at the previous JS/CSS build (instant rollback),
and the deploy fails. The check was added after 1.6.0 shipped a `chat.js` that parsed fine but threw on load, so no widget appeared.
Accounts come from `CHECK_USERS="login:pass,login:pass"` (default `post-only:postonly,glpi:glpi`, so **set it once those default passwords are changed**).
Use `--no-ui-check` to skip.

So **bump the version only when `hook.php` has something new to install** (tables, columns, automatic actions).
Asset cache-busting no longer depends on the version: GLPI serves plugin assets with a 30-day `max-age`, so
`setup.php` loads the hashed file names listed in `public/dist/manifest.json`.

## Trying it by hand

```bash
customizations/itchat-dev/manual-test.sh up     # test accounts + an incoming chat from each requester
customizations/itchat-dev/manual-test.sh chat   # one more incoming chat from each requester
customizations/itchat-dev/manual-test.sh down   # delete the accounts with their chats, tickets and Problems
```

Accounts (passwords printed by `up`, kept in `~/.itchat-manual-test`): requesters `demo.user`, `demo.user2`; technicians
`demo.tech`, `demo.tech2`, `demo.hotliner`, `demo.supervisor` (one per technician profile, so every transfer can be tried);
`demo.inactive`, a deactivated technician that must not be offered as a transfer target. `up` prints the transfer list GLPI computes.
Technicians get the "2FA is required" page: press Skip during the grace period. A new GLPI user gets Self-Service as its
*default* profile; the script makes the technician profile the default one, otherwise the account lands on the Helpdesk and is
not a technician for IT Chat. Real technicians need the same (Administration > Users > Settings > Default profile).

## Tests

```bash
customizations/itchat-dev/tests/run.sh smoke     # flow x3 + security + features + collab   (~1 min)
customizations/itchat-dev/tests/run.sh full      # flow x30 + security + features + collab + browser UI (~6 min)
customizations/itchat-dev/tests/run.sh flow 100  # just the chat -> ticket flow, 100 rounds
customizations/itchat-dev/tests/run.sh security | features | collab | tickets | helpdesk | central | email | qr | ui
customizations/itchat-dev/tests/run.sh load      # LOAD_PER_CHANNEL (400) tickets through each of the 5 channels, all at once
```

`fixtures.php` runs inside a pod. It creates `itchat.test.user1` / `itchat.test.user2` (Self-Service),
`itchat.test.tech` / `itchat.test.tech2` (Technician) with random passwords, and `[itchat-test] Group`, with user1 as a member and the tech as
manager. `run.sh` tears them down, together with every chat, ticket and file they produced, even when a
test fails (`KEEP_DATA=1` skips that; the next run's setup removes the leftovers). Real users and chats are not touched, with one exception: `features` runs the hourly automatic action
once, exactly as the scheduled cron would.

| Suite | Covers |
|---|---|
| `test_flow.py` | send, image/PDF upload, unread inbox order and badge, claim, read receipts, reply, ticket dialog (type required, category, urgency), requester group and manager approval, team from category rules, attachments on the ticket, close |
| `test_security.py` | anonymous access, CSRF, isolation between requesters, role checks, input validation, blocked/neutralised file types (exe/php/js/html/svg/php-as-png, `../` names), closed-chat rules, unread after close, 10 parallel sends |
| `test_features.py` | off-hours auto-reply (forced via a test calendar, then restored), canned replies, idle auto-close, retention delete (keeps files attached to tickets) |
| `test_collab.py` | transfer rules, ticket ↔ chat followup sync (public/private, no echo, files), rating rules, dashboard providers, search (text/login/#ticket, wildcard escaping, rights) |
| `test_tickets.py` | plain GLPI ticket lifecycle as real users: category→team rules, priority→SLA + escalation levels, Request→manager approval (setup-08 + setup-17), take/followup/task/solution, approve→closed, refuse (reason required)→reopened, visibility, notifications |
| `test_helpdesk.py` + `helpdesk_ui.py` | tickets filed through the real Helpdesk forms ("Report an issue", "Request a service") in a browser: urgency/category/title/description/attachment, then type, category, urgency, requester group, approval, team, SLA, attachment and visibility checked on the resulting tickets |
| `test_central.py` + `central_ui.py` | a technician opens tickets for requesters from Central "+ Add" in a browser: type/category reloads, requester, assignee kept (setup-18), source Phone (setup-20) |
| `test_email.py` | e-mails into the IT mailbox (greenmail), collected by the real glpi-cron: ticket, attachment, Helpdesk team (setup-19), unknown sender and auto-reply refused, reply to GLPI's e-mail -> followup |
| `test_qr.py` + `qr_ui.py` | itqr plugin: asset tab + labels page, scan while logged out -> login -> report on a phone screen -> ticket with the device, category, source "QR code"; tampered labels refused |
| `test_load.py` | load + coverage: helpdesk, central, chat, qr, e-mail at once (`LOAD_PER_CHANNEL`, `LOAD_WORKERS`), every ticket checked against the rules and its channel's source. Keeps the tickets unless `LOAD_PURGE=1` |
| `test_ui.py` | Playwright/Chromium in docker. Covers the requester on a 390 px phone and the tech on desktop, browser notification + title count, sound toggle, canned replies, dialog, close/start-new. Screenshots go to `tests/shots/` |

Kept test data (`KEEP_DATA=1`) can be turned into demo data out of the suite's reach with
`keep-test-data-as-demo.php` (users `demo.*`, `[demo]` titles; next batch `demo2.*`), and removed again with
`delete-demo-data.php` (`tickets <shard> <shards>` in parallel on the pods, then `rest`).

Needs `kubectl`, `python3` + `requests`, and `docker` (lint and the browser test run in containers).
Env overrides: `GLPI_URL` (default `http://localhost:30080`), `NS`, `APP_LABEL`, `CONTAINER`, `ADMIN_USER`/`ADMIN_PASS`.
