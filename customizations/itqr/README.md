# IT QR — "scan to report a problem" labels

A GLPI 11 plugin: print a QR label for an asset, stick it on the device, and users report a
problem with *that* device by scanning it with their phone. No core file is touched.

## What people see

| Who | Where | What |
|---|---|---|
| Technician | Asset page (Computer, Monitor, Printer, Network device, Peripheral, Phone) > tab **QR แจ้งปัญหา** | The QR, the link inside it, **พิมพ์ป้าย** (print label) |
| Technician | Asset list > select several > Actions > **Print QR labels** | One printable sheet, 70 x 40 mm labels, cut along the dashed lines |
| User | Scan the label | Log in (only the first time), then a short form with the device already filled in: symptoms, optional title, urgency, optional photo (phone camera) |

The ticket it creates has:

- the device linked (Items tab of the ticket)
- requester = the person who scanned
- a category from the device type (`Label::CATEGORIES`), so the existing category -> team
  and priority -> SLA business rules apply (Printer / Computer / Monitor -> Helpdesk, network device -> Network Team)
- request source **QR code** (created on install; usable in searches, reports and rules)
- the device's entity when the user has access to it, otherwise the user's own

If the device already has open tickets, the form tells the user how many (count only, no
details), to cut duplicate reports.

## Security

- The QR link carries an HMAC signature keyed on GLPI's own secret key
  (`config/glpicrypt.key`). A changed id or signature is refused before anything about the device
  is shown, so users can't list asset names by trying ids. Changing GLPI's key invalidates all
  printed labels.
- Users need to be logged in and allowed to create tickets. `report.php` checks the login
  itself (the firewall is told not to): GLPI's default for a visitor without a session is
  "Your session has expired", which confuses someone scanning a label for the first time.
- Photos: images only (checked by content, not by name), 10 MB max, stored like any other
  GLPI attachment.

## Deploy / change

```
customizations/itqr-dev/deploy.sh          # lint, copy to the plugins PVC, restart if PHP changed, install/activate
customizations/itchat-dev/tests/run.sh qr  # browser test: tech tab + labels, scan -> report on a phone-sized screen
```

The label link uses GLPI's **url_base** (Setup > General > "URL of the application"). Set it to
the real address users' phones can reach **before printing labels**; labels printed with
`http://localhost:30080` only work on this machine.
