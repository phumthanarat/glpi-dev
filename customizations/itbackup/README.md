# IT Backup — GLPI backups with a page to manage them

**Setup > Backups** (Super-Admin / anyone with the "config" update right):
status (red when the last backup failed or is older than 26 h), **Backup now**, the list with
downloads (database / files) and delete, how long to keep backups, how to restore.

## What a backup is
One directory per backup on the `glpi-backups` volume (`/var/lib/glpi-backups`):

| file | content |
|---|---|
| `database.sql.gz` | `mariadb-dump --single-transaction` of GLPI's database (consistent while GLPI runs) |
| `files.tar.gz` | `config/` (incl. `glpicrypt.key`, which decrypts the passwords stored in GLPI), `files/` (documents, pictures), `marketplace/`, `plugins/` — without cache, sessions, tmp, logs |
| `manifest.json` | date, type, GLPI version, table count, size + sha256 of both archives |

A backup is only listed once complete (written to `.partial-*`, then renamed); one at a time;
backups older than *keep days* (default 14) are deleted, never the newest one.

## When
- **daily 02:00 Bangkok** by the `glpi-backup` CronJob (`k8s/base/backup-cronjob.yaml`, 1 h deadline)
- **Backup now** on the page (runs in the background, the page refreshes until it's done)

## Restore (command line only — it replaces everything)
```bash
zcat database.sql.gz | kubectl exec -i -n glpi deploy/mariadb -- \
  sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" glpi'
kubectl exec -i -n glpi deploy/glpi-app -c glpi-app -- tar -C /var/www/glpi -xzf - < files.tar.gz
kubectl rollout restart deploy/glpi-app -n glpi
```
Always restore the database and `files.tar.gz` of the **same** backup (the key must match).

## Copy to a Windows file share (off-site)
On the page, **ปลายทางสำรอง: Windows file share**: file server, share, folder, domain, user,
password (stored encrypted with GLPI's key, never shown again; empty field = keep it), switch
"copy every backup here", **บันทึก + ทดสอบการเชื่อมต่อ** (writes, reads back and deletes a test file).

After every backup, each local backup not copied yet is copied with rclone (SMB), checked
(same files, same sizes on the share) and marked in its manifest; the share is pruned with the
same retention. A share that's down doesn't fail the local backup: the page turns red, the
CronJob exits 2, and the next run (or **copy ที่ค้างตอนนี้**) catches up.
Use an account that can only write to that folder.

Needs rclone in the image (Dockerfile installs it): rebuild the image after pulling this.

## Keep a copy elsewhere
The volume is on the cluster: a lost cluster / disk loses the backups too. Copy them off
regularly, e.g. `kubectl cp glpi/<pod>:/var/lib/glpi-backups ./glpi-backups -c glpi-app`,
or to a NAS / S3 bucket. Backups contain every password and document of GLPI: store them
encrypted, with restricted access.

## Deploy / test
`customizations/itbackup-dev/deploy.sh` (install.sh does it). `tests/run.sh backup`: 33 checks
(rights, Backup now, sha256 / completeness of real backups, downloads + path traversal, the
CronJob, retention, delete) + 20 against a real SMB server (Samba pod): connection test, password
encrypted and never shown, sha256 on the share, share down then back, retention on the share.
