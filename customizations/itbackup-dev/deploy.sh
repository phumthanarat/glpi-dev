#!/usr/bin/env bash
#
# Deploys customizations/itbackup to the GLPI pods:
#   1. lint      php -l on every PHP file (via docker)
#   2. copy      onto the shared glpi-data PVC (plugins/ subPath) through one live pod
#   3. restart   rolling restart if a PHP file changed (the image runs opcache with
#                validate_timestamps=0, so pods keep the old code otherwise)
#   4. install   plugin:install + activate when the plugin isn't enabled at this version yet
#   5. verify    plugin enabled at the expected version
#
# Usage:  customizations/itbackup-dev/deploy.sh
# Env: NS (default glpi), APP_LABEL (default app=glpi-app), CONTAINER (default glpi-app)
set -euo pipefail
NS=${NS:-glpi}
APP_LABEL=${APP_LABEL:-app=glpi-app}
CONTAINER=${CONTAINER:-glpi-app}
REMOTE=/var/www/glpi/plugins/itbackup
HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(cd "$HERE/../itbackup" && pwd)
VERSION=$(sed -n "s/^define('PLUGIN_ITBACKUP_VERSION', '\(.*\)');/\1/p" "$SRC/setup.php")

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die() { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
live_pod() {
    kubectl -n "$NS" get pods -l "$APP_LABEL" -o json | python3 -c '
import json, sys
for p in json.load(sys.stdin)["items"]:
    st = p.get("status", {})
    if p["metadata"].get("deletionTimestamp") or st.get("phase") != "Running":
        continue
    if all(c.get("ready") for c in st.get("containerStatuses", [])):
        print(p["metadata"]["name"]); break
'
}
kexec() { kubectl -n "$NS" exec "$POD" -c "$CONTAINER" -- "$@"; }

step "1. lint"
(cd "$SRC" && find . -name '*.php' -print0 | xargs -0 -n1 -I{} docker run --rm -v "$SRC":/p -w /p php:8.3-cli php -l {} >/dev/null) || die "php -l failed"
echo "ok (version $VERSION)"

POD=$(live_pod); [ -n "$POD" ] || die "no ready GLPI pod"
step "2. copy to $POD:$REMOTE"
BEFORE=$(kexec sh -c "cd $REMOTE 2>/dev/null && find . -name '*.php' -type f -exec md5sum {} + | sort" || true)
kexec mkdir -p "$REMOTE"
kubectl -n "$NS" cp "$SRC/." "$POD:$REMOTE" -c "$CONTAINER"
AFTER=$(kexec sh -c "cd $REMOTE && find . -name '*.php' -type f -exec md5sum {} + | sort")

if [ "$BEFORE" != "$AFTER" ]; then
    step "3. rolling restart (PHP changed)"
    kubectl -n "$NS" rollout restart deploy/glpi-app
    kubectl -n "$NS" rollout status deploy/glpi-app --timeout=300s
    for _ in $(seq 60); do
        [ -z "$(kubectl -n "$NS" get pods -l "$APP_LABEL" -o jsonpath='{range .items[?(@.metadata.deletionTimestamp)]}{.metadata.name}{end}')" ] && break
        sleep 2
    done
    POD=$(live_pod)
else
    step "3. restart not needed (no PHP change)"
fi

line() { kexec php bin/console plugin:list 2>/dev/null | awk -F'|' '$2 ~ /^ *itbackup *$/'; }
if ! line | grep -q "| *$VERSION *| *Enabled"; then
    step "4. install + activate"
    kexec php bin/console plugin:install itbackup -u glpi --force --no-interaction
    kexec php bin/console plugin:activate itbackup --no-interaction
else
    step "4. already enabled at $VERSION"
fi

step "5. verify"
line | grep -q "| *$VERSION *| *Enabled" || die "itbackup not enabled at $VERSION: $(line)"
printf '\033[32mdeployed itbackup %s\033[0m\n' "$VERSION"
