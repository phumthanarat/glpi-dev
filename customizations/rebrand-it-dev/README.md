# "IT-DEV" rebrand

Replaces the literal word "GLPI" everywhere it's visible on the page
with "IT-DEV". Three spots existed; only one was fixable without
touching core files — the other two have no config/DB-driven override
point in GLPI, so per the user's explicit go-ahead this is the one
customization in this repo that **does** edit core source files.

| Spot | Fix | Core file touched? |
|---|---|---|
| Footer "GLPI Copyright (C) ..." link | CSS-only (`footer-override.css`, same Entity Custom CSS mechanism as `logo/`/`watermark/`) | No |
| Browser tab title (`<title>...- GLPI</title>`) | `$CFG_GLPI['app_name']` default | **Yes** — `src/autoload/CFG_GLPI.php` |
| Login page auth-method dropdown ("GLPI internal database") | Literal `__('GLPI internal database')` strings | **Yes** — `src/Auth.php` (2 occurrences) |

## Why the other two needed a core edit

- The tab title comes from `{{ config('app_name') }}` in
  `templates/layout/parts/head.html.twig`, which reads
  `$CFG_GLPI['app_name']` — a hardcoded PHP default
  (`src/autoload/CFG_GLPI.php`), not a `glpi_configs` DB value. No
  admin-UI setting exists for it.
- The dropdown option text is literal `__('GLPI internal database')`
  baked into `Auth::getMethodName()` / `Auth::getLoginAuthMethods()`
  (`src/Auth.php`) — a hardcoded English source string, not something
  a translation override or DB config can reach either.
- CSS can't touch either: a `<title>` tag isn't stylable at all, and
  `<option>` content inside a native `<select>` doesn't respect the
  `content` property reliably across browsers.
- GLPI has no "Custom JavaScript" equivalent to Entity > Custom CSS, so
  a DOM-text-walker script wasn't an available option either.

## Files

- `footer-override.css` / `apply-footer.php` — same pattern as
  `watermark/`: writes into the root entity's `custom_css_code`,
  replacing the block between `/* ITDEV-FOOTER-START */` and
  `/* ITDEV-FOOTER-END */` markers on re-run.
- `core-changes.patch` — `git diff` of the two core files, for
  reference / reapplying after a `git pull` from upstream GLPI (this
  patch will very likely need re-review after any Auth.php or
  CFG_GLPI.php upstream change — it's a plain literal-string edit, not
  something upstream knows to preserve).

## Apply

CSS part (safe to re-run any time):

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations/rebrand-it-dev "$POD":/tmp/customizations/rebrand-it-dev -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/rebrand-it-dev/apply-footer.php
```

Core part — already committed to `src/Auth.php` and
`src/autoload/CFG_GLPI.php` in this working tree. To make it live:

```bash
docker build -t glpi:11.0 .
kubectl -n glpi rollout restart deployment glpi-app
kubectl -n glpi rollout status deployment glpi-app
```

(This is the one part of this repo's `customizations/` that requires a
full image rebuild + pod rollout — everything else in `logo/`,
`watermark/`, `topbar-modern/`, and the ITSM `setup-*.php` scripts
takes effect immediately against the running database, no rebuild
needed.)

## Revert

- Footer: run the SQL in `BACKUP_previous_state.sql`, or delete the
  `ITDEV-FOOTER-START`/`END` block from Administration > Entities >
  Root entity > Configuration > Custom CSS.
- Core files: `git checkout -- src/Auth.php src/autoload/CFG_GLPI.php`
  (or reverse-apply `core-changes.patch`), then rebuild the image and
  roll out again.
