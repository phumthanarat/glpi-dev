# itchat — live chat between users and technicians, inside GLPI

A normal GLPI plugin (no core file edits, no image rebuild). Adds a floating
💬 button to every logged-in page (central + self-service portal).

| Who | Sees |
|-----|------|
| **Technician**: central interface + `ticket` READALL and UPDATE rights (`plugin_itchat_is_technician()` in `setup.php`; look-only profiles such as Observer / Read-Only chat as requesters) | Inbox of all conversations (open + closed in the last 3 days), unread counts, "รอรับเรื่อง" marker. Can reply, **รับเรื่อง** (claim), **เปิด Ticket** (transcript → new Ticket, requester = chat user, assigned = self), **ปิด** (close). Replying to an unclaimed chat claims it automatically. |
| **Everyone else** (Self-Service etc.) | One conversation with "IT Support". Typing starts one; after it's closed, **เริ่มใหม่** starts a new one. |

**Inbox tabs, search, transfer (1.6.0+):**
- Inbox tabs: ทั้งหมด / ของฉัน (chats I own) / รอรับ (unclaimed). The last tab used is remembered per browser.
- The search box looks through *all* chats, any age: message text, requester name/login, or `#<ticket id>`.
- **โอน** hands a chat to another technician, from the same technician list rule as `plugin_itchat_is_technician()`. A system message shows the handover, and the receiving tech gets a "ได้รับโอนแชท" alert.
  If the chat already has a ticket that is not solved or closed, the ticket follows: the new tech is assigned in place of the previous chat tech (other assigned techs and groups stay), and the message says so ("(Ticket #n ด้วย)"). Solved / closed tickets are left alone.

**Ticket ↔ chat sync (1.6.0+):** after **เปิด Ticket**, sync runs both ways:
- Public followups added on the ticket are copied into the chat, prefixed 🎫. This uses the `item_add` hook on ITILFollowup. Private followups are never copied.
- Chat messages and files sent afterwards are added to the ticket as followups and documents.
- The `_itchat_from_chat` flag stops them from echoing back.

**Satisfaction (1.6.0+):** when a chat is closed, the requester gets 1–5 stars, once. The tech sees the rating in the list and header.
The dashboard cards are *IT Chat: คะแนนความพึงพอใจเฉลี่ย (30 วัน)*, *แชทวันนี้* and *แชทรอรับเรื่อง*. They are in the card picker under "IT Chat", and
`customizations/setup-16-itchat-dashboard.php` adds them to "IT Helpdesk KPI".

**Technician alerts (1.5.0+):** a two-tone beep (bell icon in the panel header turns it off per browser)
and a browser notification when a chat gets a new message while the panel is closed or the tab is in the background.
Permission is asked on the first click of 💬. The tab title also shows `(n)` unread chats. Background tabs poll every 10 s for technicians.

**Off-hours auto-reply (1.5.0+):** when a requester starts a chat outside the selected calendar's working hours
(default "IT Support Business Hours"), a system message with the configured text is added.

**Canned replies (1.5.0+):** the ⚡ button next to 📎 (technicians only) inserts a stored sentence into the input for
editing before sending. The list is kept on the config page, one per line.

**Automatic action `ItchatMaintenance` (1.5.0+, hourly, external cron):** closes chats idle longer than N hours (default 24)
with a system message, and deletes closed chats older than N days (default 90). Files that were attached to a ticket are kept.
Both limits are set on the config page; 0 disables them.

**Unread badge / inbox order:**
- The tech badge on 💬 is the number of *chats* with unread messages, closed ones included, so a message sent right before the user closes is never missed.
- The user badge is the number of unread messages in their chat. After a tech answers and closes, the user still sees that chat and its badge on their next page load, until they read it.
- Tech inbox order: unread, then unclaimed, then other open, then closed. Latest activity comes first within each group.

**Read receipts:** your own messages show "ส่งแล้ว" (sent), which turns into "อ่านแล้ว" (read) once the other side has the chat open.
The tech side counts as one team, so any technician opening the chat marks it read.

**Attachments:** use 📎, paste an image (Ctrl+V) or drag a file onto the chat. Each file is sent as its own message.
It becomes a normal GLPI Document (`documents_id` on the message) via the same `_filename` / GLPI_TMP_DIR path GLPI's own uploader uses.
So the size limit is `document_max_size` (50 MB here), and allowed types come from Setup > Dropdowns > Document types (uploadable only).
Files are served through `ajax/chat.php?action=file&msg=<id>`, gated by *conversation* access rather than GLPI document rights
(self-service users have none). Images show inline, except SVG, which is always a download (same rule as GLPI core).
**เปิด Ticket** opens a dialog before creating the ticket (1.3.0+). It has these fields:
- title, pre-filled with "แชท: " + the user's first message
- type (Incident/Request/Problem/Change), *required with no default* (1.4.0+), so a Request is never filed as an Incident by accident.
  Problem and Change are not GLPI ticket types: Problem files an **Incident** ticket linked to a Problem, Change a **Request**
  ticket linked to a Change (below). Each is offered only to techs who may create or link them
- category, filtered to categories flagged for that type and visible in the tech's entities
- urgency and impact, limited to the values enabled in `urgency_mask` / `impact_mask`; GLPI works out the priority from them
- related device (optional): one of the requester's own items (those whose *User* is them, among GLPI's ticket item types),
  linked to the ticket (Items tab)
- Problem / Change (for that type only, required): **สร้าง … ใหม่จาก Ticket นี้** (default) / link to an open one (not solved or closed, in the tech's entities). A new one is made the way GLPI's own "Create a problem / change from this ticket" does it (`_tickets_id`), with the ticket's assignee. Needs the `problem` / `change` UPDATE right to list/link and CREATE to make one. The API still accepts `type=1` + `problem=<id|-1>` and `type=2` + `change=<id|-1>`. The requester is not told about the Problem / Change.

The ticket is **assigned to the chat's technician**, i.e. whoever holds the chat when it is opened (it may have been
transferred first), or to the tech clicking เปิด Ticket when nobody has claimed the chat yet. The dialog shows who that is.

The ticket also gets a **requester group**, so the "Request needs manager approval" rule (setup-08) can find an approver. That is the user's default group, else their only group, and only if it is flagged `is_requester`.
The dialog tells the technician up front whether a Request will actually go to approval: no group, a group without a manager, or a group whose manager will be asked.
The server re-validates all of them (`action=ticketform` feeds the dialog). GLPI business rules then run as on any new ticket: SLA from priority, and team from category *if those rules are active*.
**เปิด Ticket** also links every chat file to the new ticket (Documents tab) and lists them in the transcript.

Transport is short polling of `ajax/chat.php` (4 s with the panel open, 15 s closed, 30 s in a background tab),
so no websocket server is needed. Unread state is per side (`last_read_user` / `last_read_tech` — shared by the whole tech team).

Files: `setup.php` (hooks + Google Chat helper), `hook.php` (install/uninstall: tables `glpi_plugin_itchat_conversations`, `glpi_plugin_itchat_messages`),
`ajax/chat.php` (JSON API), `front/config.form.php` (settings page), `public/chat.js` + `public/chat.css` (widget).

## Google Chat notification (optional, off by default)

When a user **starts a new chat**, a message goes to a Google Chat space. It includes a link
(`/front/central.php?itchat=<id>`) that opens GLPI with that chat already selected.
To set it up: Setup > Plugins > IT Chat > 🔧 (Super-Admin / `config` UPDATE only), paste the space's webhook URL,
then click "บันทึกและส่งข้อความทดสอบ". Leave the URL empty to turn it off.
The URL is stored in `glpi_configs` (context `plugin:itchat`). Failures never block the chat. They are logged to `files/_log/itchat.log`.
Only Google Workspace accounts can create incoming webhooks (personal @gmail.com can't).
The link uses GLPI's *URL of the application* (Setup > General). Set to `http://localhost:30080` on 2026-09-26. Change it when GLPI gets a real domain.

## Deploy / update

Use `customizations/itchat-dev/deploy.sh` (see `../itchat-dev/README.md`). It copies to the shared `glpi-data` PVC,
restarts only for PHP changes, runs the plugin update only for a version bump, and verifies.
Tests: `customizations/itchat-dev/tests/run.sh smoke|full`.

Disable: Setup > Plugins, or `php bin/console plugin:deactivate itchat`.

## Gotcha found while building

A legacy script in GLPI 11 must `return` a **Symfony** `Response`. `Glpi\Http\JSONResponse` is a
PSR-7 (Guzzle) response, so `LegacyFileLoadController` ignores it and sends an empty 200. Use
`Symfony\Component\HttpFoundation\JsonResponse` instead.

## Also in this plugin (not chat)

`public/chat.js` is the only custom JS on every page, so two unrelated helpers live at its end:
- **Auto-refresh of everything** every *Automatically refresh data* minutes (`refresh_views`, setup-22). GLPI itself only
  refreshes the Ticket list and kanban. Dashboards get their own auto-refresh toggle switched on, every other search list is
  refreshed in place, and the home page's open tab is reloaded. Skipped while the user is busy there (rows ticked, typing,
  a dialog open); item forms are never reloaded.
- **Technicians' default profile** (`plugin_itchat_profile_added()`, ITEM_ADD on Profile_User): GLPI gives every new user
  Self-Service as default profile, so a technician kept landing on the Helpdesk (and chatted as a requester). A user given a
  central profile while their default is a Self-Service one (or none) gets it as default. A central default is left alone.
