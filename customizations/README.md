# GLPI customizations

One-off PHP scripts that configure/seed this GLPI instance, run once
against a live pod via `php bin/console`-style bootstrapping (they
`chdir()` into `/var/www/glpi` and boot the GLPI kernel themselves —
no web server needed). Every script is idempotent: re-running it skips
whatever it already created.

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations "$POD":/tmp/customizations -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/setup-01-calendar.php
```

## ITSM rollout (`setup-*.php`)

Implements a helpdesk/service-desk structure: teams, categories, SLA,
escalation, notifications, self-service portal, incident/request
approval, and a KPI dashboard. Run in numeric order — later scripts
depend on records earlier ones create (by name lookup, not hardcoded
IDs).

| # | Script | What it builds | Edit later via (GLPI menu) |
|---|--------|-----------------|------------------------------|
| 0 | `setup-00-base-url.php` | GLPI's public address (`url_base`) from `GLPI_URL`. First, because links in e-mails, the webhook payloads of setup-12..14 and the QR labels are built from it | Setup > General > URL of the application |
| 1 | `setup-01-calendar.php` | "IT Support Business Hours" calendar: Mon-Fri 08:30-17:30, lunch break 12:00-13:00 excluded, Sat/Sun closed | Setup > Calendars |
| 2 | `setup-02-groups.php` | 5 teams as Groups: Helpdesk/Service Desk, Network Team, System Team, Application Team, IT Manager | Administration > Groups |
| 3 | `setup-03-categories.php` | 25-entry category tree: IT Support > Hardware/Software/Network/Account/Server, each with leaf subcategories | Setup > Ticket categories |
| 4 | `setup-04-sla.php` | SLM "IT Support SLM" + 8 SLAs (Critical/High/Medium/Low x TTO/TTR), all on the business calendar | Setup > SLA |
| 5 | `setup-05-business-rules.php` | Business Rules for Tickets: auto-assign group by category, auto-assign SLA by priority (8 rules total, one engine for both) | Setup > Rules > Business rules for tickets |
| 6 | `setup-06-escalation.php` | 12 SlaLevels (50%/75%/90% of each TTR, converted to absolute seconds-before-due) that fire GLPI's native "recall" notification | Setup > SLA > pick one > "Escalation levels" tab |
| 7 | `setup-07-notifications.php` | Activates the `update` (status-changed) notification event; adds a breach-time (0%) SlaLevel per SLA; creates a dedicated "SLA Escalation Alert" template and re-points the Recall notification at it | Setup > Notifications, Setup > Notification templates |
| 8 | `setup-08-incident-request-approval.php` | Business Rule: ticket type=Request auto-requests approval from the requester's group manager | Setup > Rules > Business rules for tickets |
| 9 | `setup-09-dashboard.php` | "IT Helpdesk KPI" dashboard (12 stock cards: open/pending/overdue/resolved counts, by-team, by-agent, by-category, by-department, SLA compliance, resolution-time trend, volume trend) | Assistance > pick "IT Helpdesk KPI" from the dashboard dropdown (drag/resize cards directly there) |
| 10 | `setup-10-cron.php` | Switches every CronTask from MODE_INTERNAL to MODE_EXTERNAL so `k8s/base/cronjob.yaml`'s CronJob (`php front/cron.php` every 2 min) actually drives them — see the "Known gaps" note below, this was a real bug, not just a missing nice-to-have | Setup > Automatic actions |
| 11 | `setup-11-mail.php` | **Production:** real relay from env `SMTP_HOST` / `SMTP_PORT` / `SMTP_USER` / `SMTP_PASSWORD` / `MAIL_FROM` (install.sh passes them); without `SMTP_HOST` the dev setup below. Dev: | Enables `use_notifications` (the master switch — was off, silently no-opped everything from step 7/8), points SMTP at mailpit (`k8s/overlays/local-dev/mailpit-*.yaml`), and gives every test user an email address (none had one). Verified end-to-end via mailpit's API — see its "Known gaps" note below | Setup > General > Notification settings; inbox at http://localhost:30825/ |
| 12 | `setup-12-line-webhook.php` | Scaffolds a LINE Notify integration on ticket creation, using GLPI's native **Webhook** feature (a separate system from Notifications — custom URL/headers/body, no plugin needed). Created **inactive** with a placeholder token; see the script's own printed instructions to activate | Setup > Webhooks > "LINE Notify - New Ticket" |
| 13 | `setup-13-chat-webhooks.php` | Same mechanism, three more: Slack, Discord, Microsoft Teams "new ticket" alerts, each **inactive** with a placeholder URL. Payload correctness (valid JSON with special characters in the ticket title) verified by test-rendering through GLPI's actual Twig sandbox before writing the DB rows — see the script's docblock for the `\|raw` vs `\|json_encode` pitfall found doing that | Setup > Webhooks > pick one |
| 14 | `setup-14-google-chat-webhook.php` | Same mechanism for **Google Chat** (Hangouts' replacement): "new ticket" message with a link to the ticket. **Inactive**, placeholder URL. Google Chat incoming webhooks need a Google Workspace account. Payload JSON validity verified by rendering it for a real ticket | Setup > Webhooks > "Google Chat - New Ticket" |
| 15 | `setup-15-technician-accounts.php` | Test technicians `test.somchai` / `test.suda` (password from env `TEST_ACCOUNT_PASSWORD`, emails @dev.glpi.labs) with Technician on **Root, recursive** and Root as default entity. Without that they can't open tickets from Root-entity requesters, e.g. tickets created from IT Chat | Administration > Users |
| 16 | `setup-16-itchat-dashboard.php` | Appends the IT Chat cards (satisfaction avg 30 d, chats today, chats waiting) as a new bottom row of "IT Helpdesk KPI". Keeps existing cards and skips ones already there | Assistance > Dashboard > IT Helpdesk KPI |
| 17 | `setup-17-requester-group-rule.php` | Business rule (ON ADD, placed before the approval rule): the requester's **default group** becomes the ticket's requester group, via GLPI's native "Default group from user" action. Without it the step-8 approval never fired for Requests filed from the Helpdesk/e-mail. Only works for users with a default group set | Setup > Rules > Business rules for tickets |
| 18 | `setup-18-technician-assign-right.php` | Profile right: **Technician** gets Ticket > **Assign**. Without it, a technician opening a ticket on behalf of a user from Central (+ Add) saw themselves pre-filled under "Assigned to", but GLPI silently dropped the assignee on save (only "Assign to me" was granted), leaving the ticket on the team with no owner. Side effect, accepted: technicians can assign tickets to others too. Verified by `itchat-dev/tests/run.sh central` | Administration > Profiles > Technician > Assistance > Tickets > Assign |
| 19 | `setup-19-mail-intake.php` | **E-mail channel**: a Mail Receiver on the mailbox from the `mail-intake` Secret (local-dev: the in-cluster **greenmail** test mailbox, `k8s/overlays/local-dev/greenmail-*.yaml`), the `mailgate` cron every 2 min, and a Business rule sending e-mail tickets that have no team (no category -> no category rule matched) to Helpdesk / Service Desk. Only senders matching a GLPI user's e-mail are accepted; unknown senders and auto-replies are refused. Replies to GLPI's notification e-mails become followups (`[GLPI #0000123]` subject tag / In-Reply-To). Run with the Secret's values in env (see the script header). Verified by `itchat-dev/tests/run.sh email` (20 checks, through the real cron) | Setup > Receivers; Setup > Rules > Business rules for tickets |
| 20 | `setup-20-central-source-phone.php` | Ticket template **Central (technicians)** (Default's mandatory/hidden fields + request source predefined = **Phone**) on the Technician / Hotliner / Supervisor profiles, so every channel has its own source for reports: Helpdesk forms -> Helpdesk, e-mail -> E-Mail, IT Chat -> **Chat** (itchat 1.7.0), QR -> QR code, Central "+ Add" -> Phone (the technician can change it). Verified by `run.sh central` and `run.sh load` | Assistance > Ticket templates; Administration > Profiles > (profile) > Ticket template |
| 21 | `setup-21-security.php` | Closes GLPI's public default logins: sets the `glpi` Super-Admin password from `GLPI_ADMIN_PASSWORD` (12+ chars, GLPI's password policy applies) and deactivates `tech` / `normal` / `post-only` (`KEEP_DEFAULT_ACCOUNTS=1` keeps them). Run last by `install.sh` | Administration > Users |
| 22 | `setup-22-list-columns.php` | Default columns of the Ticket / Problem / Change lists for technicians (Central): ID, title, entity, status, time to resolve (+ to own) with progress, last update, opening date, priority, requester, technician, category, SLA exceeded. Users' own column choices are kept | Search list > wrench icon (Select default items to show) |
| — | `daily-summary/send-daily-summary.php` + `k8s/base/daily-summary-cronjob.yaml` | Scheduled daily rollup (tickets/changes opened today, open/overdue/solved counts — same numbers the dashboard shows, same provider functions) pushed to LINE/Slack/Discord/Google Chat. Separate mechanism from setup-12/13's per-ticket Webhooks: no event-trigger fits "once a day", so this is a k8s CronJob (18:00 Asia/Bangkok, **suspended** until a real token is set) running a script deployed onto the `glpi-data` PVC — see the CronJob's own comments for why (not a ConfigMap: kustomize blocks referencing files outside `k8s/`) and the exact deploy command | Setup > Webhooks doesn't cover this one — edit `$CHANNELS` directly in the script |

No separate script exists for the plan's items 9 (Self-Service Portal)
and the Incident/Request *type* distinction (part of item 11) — both
are native GLPI behavior with nothing to configure; they were verified
against this instance, not built.

### Known gaps (not fixable by a config script)

- **Auto-assign rules were inactive** until 2026-09-26. The 4 `Auto-assign: <category> -> <team>` rules from step 5
  were found switched off (cause unknown) and were re-activated then. The SLA rules were already active.

- **SLA Compliance %** as a single number: GLPI only ships on-time/late
  *count* breakdowns (used in the dashboard), not a percentage card.
  Needs a custom PHP dashboard provider class.
- **Reopen Rate**: no stock metric at all; would need a query over
  ticket status-change history.
- ~~Escalation/notifications need real cron~~ **Fixed**: `k8s/base/cronjob.yaml`
  runs `php front/cron.php` every 2 minutes (there's no `bin/console`
  cron command in GLPI 11 — the earlier version of this note was
  wrong about that), and `setup-10-cron.php` switched every CronTask
  to MODE_EXTERNAL so the CronJob actually drives them (they default
  to MODE_INTERNAL, which only fires probabilistically on web
  traffic — the CronJob alone silently touched nothing until this).
  Verified: `glpi_crontasks.lastrun` for `slaticket`/`queuednotification`
  advances on each run.
- ~~No email actually sends~~ **Fixed**: `use_notifications` (the
  system-wide master switch — distinct from `notifications_mailing`,
  which only picks the delivery method) was `0`, so nothing from
  step 7/8 was ever queued regardless of template config, and on top
  of that no user had an email address at all. `setup-11-mail.php`
  fixes both and points SMTP at a dev-only mailpit instance
  (`k8s/overlays/local-dev/mailpit-*.yaml`). Verified: real ticket
  creation queued 2 notifications, the CronJob sent them, mailpit's
  API confirmed both arrived. **mailpit is dev-only** — a real
  deployment needs a real SMTP relay's host/port/credentials in place
  of `mailpit:1025`.
- **Approval only triggers with a requester group set** on the ticket
  (`_groups_id_requester`), not just an individual requester user.
  `setup-17-requester-group-rule.php` (2026-09-26) now adds the requester's
  *default group* automatically. **Users still need a default group**
  (Administration > Users, or LDAP sync). Users without one skip approval.
  Tickets from IT Chat also fall back to the user's only group.

### Test accounts

| User | Password | Profile |
|------|----------|---------|
| `glpi` | `glpi` | Super-Admin |
| `post-only` | `postonly` | Self-Service (portal) |

| `test.somchai` | the `TEST_ACCOUNT_PASSWORD` used at setup | Technician on Root entity (recursive) |
| `test.suda` | the `TEST_ACCOUNT_PASSWORD` used at setup | Technician on Root entity (recursive) |

(The two `test.*` accounts are created by `setup-15-technician-accounts.php`.
They were recreated that way on 2026-09-26 after the `[Ex.]` cleanup removed the seed versions.)

## Sample/demo data (`seed-*.php`, `cleanup-seed-data.php`)

Separate from the ITSM rollout above — these populate every menu
(Assets, Management, Assistance, Tools, Administration) with example
records so nothing shows "No results found" on a fresh install. Every
record name starts with `[Ex.]`, and every record's `comment` field
(where the itemtype has one) is set to the same fixed note text — both
used by `cleanup-seed-data.php` to find and purge them later without
touching real data.

Run all of them in this order (later ones depend on entities/users
earlier ones create):

```
seed-it-department.php
seed-software.php
seed-sample-data.php
seed-all.php
seed-foundations.php
seed-more-assettypes.php
seed-more-assettypes-2.php
seed-unmanaged.php
seed-simcard.php
seed-labs-test-assettype.php
seed-it-equipment-assettype.php
seed-notebook-ex-assettype.php
```

To remove all of it again: `php customizations/cleanup-seed-data.php`.
Note that this also deletes the `test.somchai` / `test.suda` accounts and the
`[Ex.]` IT Department entity.

`cleanup-chat-and-bulk.php` removes what that script doesn't cover. That is every itchat
conversation/message plus the files uploaded through it, Tickets created from chat
(`แชท: ...`), the `[Bulk1000]` Tickets/Problems/Changes, and their unsent queued
notifications.

Both were run on 2026-09-26. A full DB dump taken just before is at
`/home/admins/glpi-backups/glpi-before-cleanup-20260926.sql.gz` (outside the repo).

## `dashboard-modern/`

Recolors every card in every dashboard (Central, Assets, Assistance,
Mini tickets, IT Helpdesk KPI — 76 cards, 5 dashboards) with one
coordinated palette instead of GLPI's mismatched defaults, and
restyles the card chrome (rounder corners, soft shadow + hover lift,
bolder big-number type) via the same Entity Custom CSS mechanism. See
[dashboard-modern](./dashboard-modern/README.md).

## `topbar-modern/`, `logo/`, `watermark/`, `rebrand-it-dev/`

Cosmetic/branding changes, mostly CSS-only via Entity Custom CSS —
nothing there touches core files, and they all share the same
`custom_css_code` field so their apply scripts append/replace their
own marked block rather than overwrite each other.
**`rebrand-it-dev/` is the one exception**: two spots (browser tab
title, login auth-method dropdown) had no config-driven override in
GLPI at all, so those are real core-file edits — needs a Docker image
rebuild + pod rollout to take effect, unlike everything else here.
**`topbar-modern/` pairs with a non-CSS change**: system-wide
`page_layout` was switched from `vertical` (left sidebar) to
`horizontal` (top nav) via `Config::setConfigurationValues()` — a
native GLPI setting, not a hack — and this CSS makes that top nav look
modern. See each one's own README:
[topbar-modern](./topbar-modern/README.md), [logo](./logo/README.md),
[watermark](./watermark/README.md),
[rebrand-it-dev](./rebrand-it-dev/README.md).

## `itbackup/` — backups + Setup > Backups page

Daily database + files backups (`glpi-backup` CronJob, 02:00) onto their own volume, and a page
for admins: status, Backup now, download, delete, retention. Restore stays on the command line.
Details: [`itbackup/README.md`](./itbackup/README.md).

## Community plugins (`community-plugins.txt`)

Installed by `install.sh` through `community-plugins/deploy.sh`, pinned by version + sha256
(a changed download is refused), all checked for GLPI 11.0.x:

| Plugin | Why |
|---|---|
| **OAuth IMAP** 1.5.4 | lets the Mail Receiver (setup-19) read a Microsoft 365 / Google mailbox, which no longer accept IMAP passwords. Setup > OAuth IMAP applications |
| **Escalade** 2.10.8 | escalation between the teams (Helpdesk -> Network / System / Application) with its history |
| **Fields** 1.24.5 | custom fields on tickets, assets, users (employee id, department, asset number...) without code |
| **Data Injection** 2.15.11 | CSV import (assets, users) for go-live; then print the QR labels |
| **Tag** 2.14.7 | tags on tickets and assets (VIP, project...) |

To add or upgrade one: edit the line (URL + `sha256sum` of the tarball), re-run `install.sh`.
Offline install: put the tarballs in `~/.cache/glpi-itsm/plugins/` (or `PLUGIN_CACHE`) first.

## `itqr/` — QR labels: scan to report a problem

GLPI 11 plugin. Technicians print QR labels from an asset's **QR แจ้งปัญหา** tab (or the
**Print QR labels** massive action); users scan with their phone, log in, and get a short form
for that device. The ticket gets the device linked, a category from the device type (so team /
SLA rules apply) and the source "QR code". Links are HMAC-signed, so ids can't be guessed.
Deploy with `itqr-dev/deploy.sh`; tested by `itchat-dev/tests/run.sh qr`. **Set GLPI's
url_base to the real address before printing labels.** Details: [`itqr/README.md`](./itqr/README.md).

## `itchat/`

A real GLPI plugin (not a config script): live chat between end users and
technicians via a floating 💬 button on every page. Technicians get an
inbox and can claim, close, or turn a chat into a Ticket. It can optionally ping a
Google Chat space when a new chat starts (plugin config page). It's deployed to
the `plugins/` dir on the PVC. See [itchat](./itchat/README.md).
