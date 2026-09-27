# Topbar / navigation modern overlay

Applies a "minimal flat + bigger icons" look to the top navbar, its
sector dropdowns (Assets, Assistance, Management, ...), and the
user/profile dropdown menu, using GLPI's **built-in Entity "Custom
CSS"** setting instead of editing any core template or SCSS file.

Written 2026-09-14, but had no real effect until 2026-09-15, when
`page_layout` was switched system-wide from `vertical` (left sidebar)
to `horizontal` (this top nav) — see below. Before that the top navbar
existed but wasn't the primary navigation, so this styling was mostly
invisible.

Why this approach instead of editing `css/includes/...`:
- Zero core files touched — survives GLPI core updates/`git pull`.
- Toggleable instantly from the UI or DB, no image rebuild needed.
- Scoped to `.topbar` / `.user-menu` only, so it can't affect anything
  else on the page.

## The layout switch (not part of this CSS file)

GLPI ships **both** a vertical-sidebar and a horizontal-topbar layout
natively — `Config::getConfigurationValues('core', ['page_layout'])`,
one system-wide default plus an optional per-user override (every
user in this install has `NULL`, i.e. inherits the system default).
Switched with:

```php
Config::setConfigurationValues('core', ['page_layout' => 'horizontal']);
```

No CSS or core-file change involved — this is a first-class supported
GLPI setting (Setup > General, or per-user in personal preferences).

## What was changed (this CSS file)

Root entity (`glpi_entities.id = 0`) `custom_css_code` gained the
block between `/* ITDEV-TOPBAR-START */` and `/* ITDEV-TOPBAR-END */`
(see [`apply-topbar.php`](./apply-topbar.php)) — same marker-based
replace-or-append pattern as `logo/`, `watermark/`, `rebrand-it-dev/`,
`dashboard-modern/`, so re-running it updates just this block.

Styling covers:
- Top navbar: flattened, larger icons, rounded hover states.
- Sector dropdowns (`.navbar-nav .dropdown-menu`): card-like with a
  shadow, rounded corners, icon-aligned items — these are now the
  primary way users reach every section of the app.
- User/profile dropdown: same flat-card treatment.

## Apply / re-apply

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations/topbar-modern "$POD":/tmp/customizations/topbar-modern -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/topbar-modern/apply-topbar.php
```

## Revert

- CSS: run the SQL in `BACKUP_previous_state.sql`, or delete the
  `ITDEV-TOPBAR-START`/`END` block in Administration > Entities > Root
  entity > Configuration > Custom CSS.
- Layout: `Config::setConfigurationValues('core', ['page_layout' => 'vertical']);`
  restores the left sidebar as the default (no backup captured for
  this one value — it's a single config key, easy to just set back).
