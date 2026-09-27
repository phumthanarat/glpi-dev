# ITSM logo override

Replaces GLPI's stock logo (topbar, collapsed sidebar, login page —
light and dark theme) with a custom "ITSM" mark, using GLPI's
**built-in Entity "Custom CSS"** setting, same approach as
[`topbar-modern/`](../topbar-modern/README.md) — no core files touched.

## Design

- Icon: headset-in-a-badge, blue (`#2563eb`) → teal (`#0d9488`) gradient.
- Wordmark: "ITSM", set in two colorways (white for dark backgrounds,
  dark gray for the light-theme login page).
- Preview (all contexts, both themes): https://claude.ai/artifact/DbY9kFCuHnumGKJkCshzib

## Files

- `icon.svg` — icon only, used for the collapsed-sidebar slot (40×40).
- `lockup-light-text.svg` — icon + white "ITSM" text, used on dark
  backgrounds (topbar, dark-theme login page).
- `lockup-dark-text.svg` — icon + dark-gray "ITSM" text, used on the
  light-theme login page's white background.
- `logo-override.css` — generated file: the 3 SVGs above, base64-embedded
  as data URIs, overriding GLPI's `--glpi-logo*` CSS custom properties
  (see `css/includes/_base.scss` for what `.glpi-logo` normally reads).
  Regenerate it if any SVG changes — see `apply-logo.php`'s inline
  base64 step, or rerun the equivalent Python one-liner.
- `apply-logo.php` — writes `logo-override.css` into the root entity's
  `custom_css_code` (and sets `enable_custom_css=1`), backing up the
  previous value to `BACKUP_previous_state.sql` first.

## Apply / re-apply

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations/logo "$POD":/tmp/customizations/logo -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/logo/apply-logo.php
```

## Revert

Either:
- In the UI: **Administration > Entities > Root entity**, Configuration
  tab, clear/disable "Custom CSS", save.
- Or run the SQL in `BACKUP_previous_state.sql` (captured the moment
  `apply-logo.php` last ran — at the time this was first applied, the
  entity had no custom CSS at all, i.e. `topbar-modern.css` was never
  actually live on this instance despite its own README).
