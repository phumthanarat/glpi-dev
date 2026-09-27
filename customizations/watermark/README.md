# "IT-DEV" watermark

One large, faint "IT-DEV" mark centered in the viewport on every
logged-in and logged-out page, so this environment is never mistaken
for production. Applied via the same Entity "Custom CSS" mechanism as
[`logo/`](../logo/README.md) and
[`topbar-modern/`](../topbar-modern/README.md) — merged into whatever
was already in `custom_css_code`, not a replacement.

(v1 tiled the mark across the whole page via a repeating SVG background;
replaced with this single centered text per feedback — simpler, and
reads as an intentional watermark rather than a busy pattern.)

## How it works

`body::before` renders the text `"IT-DEV"` directly (no image), fixed
and centered (`position: fixed; top/left: 50%; transform:
translate(-50%,-50%) rotate(-20deg)`), at `11vw` so it scales with the
viewport, in low-opacity gray (`rgba(124,135,148,0.09)`).
`pointer-events: none` and a max z-index keep it purely visual — never
blocks a click, never scrolls out of view since it's fixed to the
viewport rather than the page.

## Files

- `watermark-override.css` — the whole thing, wrapped in
  `/* ITDEV-WATERMARK-START */` ... `/* ITDEV-WATERMARK-END */`
  markers so `apply-watermark.php` can find and replace just this
  block on a re-run, without touching the logo/topbar CSS around it.
- `apply-watermark.php` — writes `watermark-override.css` into the root
  entity's `custom_css_code`: replaces the block between the markers if
  one exists, appends it otherwise. Backs up the previous full value to
  `BACKUP_previous_state.sql` first.

## Apply / re-apply

```bash
POD=$(kubectl -n glpi get pods -l app=glpi-app -o jsonpath='{.items[0].metadata.name}')
kubectl -n glpi cp customizations/watermark "$POD":/tmp/customizations/watermark -c glpi-app
kubectl -n glpi exec "$POD" -c glpi-app -- php /tmp/customizations/watermark/apply-watermark.php
```

## Revert

Run the SQL in `BACKUP_previous_state.sql` — it restores the *entire*
`custom_css_code` to what it was immediately before this script last
ran. To remove *only* the watermark and keep other custom CSS, edit
`custom_css_code` in Administration > Entities > Root entity >
Configuration and delete everything between the
`/* ITDEV-WATERMARK-START */` and `/* ITDEV-WATERMARK-END */` markers.
