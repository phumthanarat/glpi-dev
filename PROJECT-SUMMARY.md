# ITSM deployment summary

This repo is upstream GLPI 11 (`README.md`, `CHANGELOG.md`, etc. below
are GLPI's own) plus a full ITSM rollout, branding, and a Kubernetes
deployment layered on top — all of it in `k8s/` and `customizations/`,
none of it touching GLPI's own code except one documented exception.
This file is the map; the two directories below have the real detail.

## What's running

```
                         Kong Ingress
                      dev.glpi.labs (:80)
                              |
                    +---------+---------+
                    |     glpi-app      |  3 replicas, HPA 3<->10 @ cpu>70%
                    |   (Deployment)    |
                    +----+---------+----+
                         |         |
              +----------+    +---+----------+
              |  mariadb  |    |   mailpit    |  dev-only fake SMTP
              | (in-cluster|   | (dev-only)   |  inbox: localhost:30825
              |  stand-in) |    +--------------+
              +-----------+
                         ^
                         |
                 glpi-cron CronJob
              (php front/cron.php, */2 * * * *)
```

Namespace `glpi` on a single-node Docker Desktop cluster
(`k8s/overlays/local-dev`). Everything here is dev-only wiring — see
`k8s/overlays/production` + `k8s/INSTALL-production.md` for the real
multi-node path (Longhorn, real external DB, real SMTP relay).

**Access:** http://dev.glpi.labs/ (add `127.0.0.1 dev.glpi.labs` to
`/etc/hosts`), or `http://localhost:30080/` without that. Login
`glpi`/`glpi`. Mail inbox (dev only): `http://localhost:30825/`.

## `k8s/` — the cluster itself

Kustomize base + local-dev/production overlays. Base assumes the
database is external to the cluster on purpose (see `k8s/README.md`);
local-dev adds in-cluster stand-ins (mariadb, mailpit) purely for
convenience on a laptop. Also here: the Kong Ingress, the HPA, and
`glpi-cron` — **required** for anything time-based (SLA escalation,
queued notification delivery) to actually fire; GLPI's own cron tasks
default to a mode that only runs on random web traffic otherwise (see
`customizations/README.md`'s "Known gaps", now fixed).

## `customizations/` — everything configured inside GLPI

Two unrelated things live here, both as idempotent PHP scripts run
against the live pod (`kubectl cp` + `kubectl exec`), not core-file
edits (one documented exception, see below):

- **`setup-01` through `setup-11`**: the ITSM rollout — business
  calendar, teams (Groups), a 25-entry category tree, SLA (4 priority
  tiers x TTO/TTR), Business Rules (auto-assign group + SLA),
  escalation (SlaLevel at 50/75/90%/breach), notifications (custom SLA
  alert template, real SMTP via mailpit), Incident/Request approval,
  a KPI dashboard, and the cron/mail fixes that make all of the above
  actually fire and actually deliver — each piece was verified against
  a live ticket, not just written and assumed correct (see that
  README's per-step table for exactly what was tested).
- **`logo/`, `watermark/`, `topbar-modern/`, `dashboard-modern/`,
  `rebrand-it-dev/`**: branding — a custom "ITSM" logo (squircle badge,
  status dot, three sizes for topbar/sidebar/login), a centered
  "IT-DEV" watermark, a modernized horizontal top-nav (GLPI's native
  `page_layout: horizontal` setting + custom CSS, swapped from the
  default left sidebar), recolored/restyled dashboard cards across
  every dashboard, and the "GLPI" -> "IT-DEV" text rebrand.
  **`rebrand-it-dev/` is the one exception to "no core-file edits"**:
  two spots (browser tab title, login dropdown label) had no
  config-driven override anywhere in GLPI, so those needed a real
  source change + image rebuild — see its README for exactly what and
  why, plus the saved patch file.
- **`seed-*.php` / `cleanup-seed-data.php`**: separate from the above —
  sample data (every menu populated, `[Ex.]`-prefixed, one command to
  remove it all again).

Full detail, the exact menu path to edit each thing later, test
account credentials, and the known gaps that are genuinely still open
(SLA Compliance % as a single number, Reopen Rate, real users needing
a default group for approval to trigger) are in
[`customizations/README.md`](./customizations/README.md).

## Verification philosophy

Nothing in here was declared done because the code compiled — every
piece was checked against the running system: test tickets through
the actual Business Rules, a live escalation timeline watched in real
time against mailpit, dashboard cards invoked through their real data
providers, a manager-approval chain resolved to a real user. Where
that testing surfaced a real bug (rule ranking order, requester-group
requirement for approvals, CronTask mode defaults, the master
notification switch being off, no user having an email address, a
CronTask's own frequency throttling a faster CronJob), it's called out
in the relevant README rather than glossed over — several of the
"known gaps" entries above exist precisely because testing found them.
