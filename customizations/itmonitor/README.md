# IT Monitor — alerts when GLPI has a problem

**glpi-monitor** (k8s/base/monitor-deployment.yaml, script k8s/base/monitor/monitor.py, Python
stdlib) runs next to GLPI, in its own pod, so it still alerts when GLPI is down. Every minute:

| check | from | problem when |
|---|---|---|
| GLPI | `GET http://glpi-app:8080/status.php` | no answer / not HTTP 200 |
| database, cron tasks, mail collectors, filesystem | GLPI's own status (same call) | not OK (e.g. database down, cron stuck) |
| HTTPS | the certificate on the public address | expires in < 14 days, or no TLS |
| backup | the glpi-backups volume (read-only) | last backup failed or older than 26 h; copy to the file share failed |

A problem is alerted after it's seen **twice in a row** (a single blip is ignored), again every
6 h while it lasts, and **"recovered"** when it's gone. Optional daily "all OK" message at a
chosen hour: if it stops arriving, the monitor itself (or the cluster) is down.

**Setup > Monitoring** (config update right): the checks and their state, the last alerts and
how each channel answered, where to send (e-mail — with its own SMTP so it works while GLPI is
down —, Microsoft Teams Workflows webhook, Google Chat, Slack, LINE Messaging API, any JSON
webhook), daily report hour, check interval, **Send test**. Stored in the Secret `glpi-monitor`
(the page may only touch that Secret), re-read by the monitor every round.
install.sh creates the Secret once from install.env (`ALERT_*`, SMTP); later the page owns it.

Limits: it lives in the same cluster; a dead node / cluster can't alert — hence the daily
message, and ideally an outside uptime check of the public URL too.

Test: `tests/run.sh monitor` (19 checks): channels set from the page reach a webhook receiver
(Teams Adaptive Card, Google Chat / Slack text, generic JSON) and mailpit; the **database is
really stopped** -> "problem" alert on every channel within minutes, started again ->
"recovered", no alert storm.
