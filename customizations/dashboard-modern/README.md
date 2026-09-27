# Dashboard modernization

Covers every dashboard in this install — GLPI's built-in **Central**,
**Assets**, **Assistance**, **Mini tickets** dashboards, plus the
custom **IT Helpdesk KPI** one — since GLPI has no per-dashboard CSS
scope, one pass styles them all.

## What changed

1. **Verified first**: every card in every dashboard (76 cards total
   across 5 dashboards) actually renders without error — each card's
   real provider function was invoked directly (not just checked for a
   container div) and confirmed to return live data. 0 failures.
2. **Recolored** (`recolor-cards.php`): GLPI's default palette per card
   is a mismatched assortment of pastel colors picked essentially at
   random per install. Replaced with one coordinated palette:
   - Ticket-status cards get a semantic color: late/SLA-expired = red,
     waiting = amber, solved = green, closed = slate, incoming =
     brand blue, assigned = cyan, planned = indigo.
   - Everything else (asset counts, top-N breakdowns, trend charts)
     cycles deterministically through `#2563eb #0d9488 #0891b2 #6366f1
     #64748b #f59e0b` by card_id, so re-running the script reproduces
     the same result.
   - Only the `color` key inside each row's `card_options` JSON is
     touched — widgettype, limit, use_gradient, everything else stays
     as GLPI/the earlier setup left it.
3. **Restyled** (`dashboard-override.css`, applied via the same Entity
   Custom CSS mechanism as `logo/`, `watermark/`, `rebrand-it-dev/`):
   14px rounded corners (from GLPI's default 3px), a soft shadow with
   a hover lift, bolder/tighter big-number typography, semi-bold card
   labels. No core files touched.

## Files

- `recolor-cards.php` — one-shot: rewrites `card_options.color` on
  every `glpi_dashboards_items` row. Safe to re-run (idempotent: skips
  rows already at the target color).
- `dashboard-override.css` / `apply-dashboard.php` — same
  marker-based (`ITDEV-DASHBOARD-START`/`END`) replace-or-append
  pattern as the other `customizations/*/apply-*.php` scripts.

## Apply / re-apply

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations/dashboard-modern "$POD":/tmp/customizations/dashboard-modern -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/dashboard-modern/recolor-cards.php
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/dashboard-modern/apply-dashboard.php
```

## Revert

- Styling: run the SQL in `BACKUP_previous_state.sql`, or delete the
  `ITDEV-DASHBOARD-START`/`END` block in Administration > Entities >
  Root entity > Configuration > Custom CSS.
- Colors: no automatic revert captured (colors weren't backed up
  per-row) — reset a dashboard to GLPI's stock layout via its own
  "Reset to default" action (Dashboard > toolbar > reset icon), or
  manually re-edit `card_options.color` per card in the dashboard UI.
