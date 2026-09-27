#!/usr/bin/env bash
#
# Installs / upgrades the community plugins listed in customizations/community-plugins.txt
# on the GLPI pods (called by install.sh; can be run on its own).
#
#   1. fetch    each tarball into the cache (PLUGIN_CACHE, default ~/.cache/glpi-itsm/plugins);
#               already-cached files are reused, so an offline machine can be pre-seeded
#   2. verify   sha256 against the pinned value - a mismatch stops everything
#   3. copy     onto the shared plugins volume through one live pod, only plugins whose
#               version on the volume differs
#   4. restart  rolling restart if anything was copied (opcache validate_timestamps=0)
#   5. install  plugin:install + plugin:activate for each plugin not Enabled at its version
#
# Env: NS (default glpi), APP_LABEL (default app=glpi-app), CONTAINER (default glpi-app)
set -euo pipefail
NS=${NS:-glpi}
APP_LABEL=${APP_LABEL:-app=glpi-app}
CONTAINER=${CONTAINER:-glpi-app}
HERE=$(cd "$(dirname "$0")" && pwd)
LIST=$HERE/../community-plugins.txt
CACHE=${PLUGIN_CACHE:-$HOME/.cache/glpi-itsm/plugins}
REMOTE=/var/www/glpi/plugins

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die() { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }
live_pod() {
    kubectl -n "$NS" get pods -l "$APP_LABEL" -o json | python3 -c '
import json, sys
for p in sorted(json.load(sys.stdin)["items"], key=lambda p: p["metadata"]["creationTimestamp"]):
    st = p.get("status", {})
    if p["metadata"].get("deletionTimestamp") or st.get("phase") != "Running":
        continue
    if all(c.get("ready") for c in st.get("containerStatuses", [])):
        print(p["metadata"]["name"]); break'
}
kexec() { kubectl -n "$NS" exec "$POD" -c "$CONTAINER" -- "$@"; }

mapfile -t PLUGINS < <(grep -vE '^\s*(#|$)' "$LIST")
[ ${#PLUGINS[@]} -gt 0 ] || { echo "no community plugins listed"; exit 0; }
mkdir -p "$CACHE"
WORK=$(mktemp -d); trap 'rm -rf "$WORK"' EXIT

step "1-2. fetch + verify (cache $CACHE)"
for line in "${PLUGINS[@]}"; do
    read -r name version sha url <<<"$line"
    file=$CACHE/$(basename "$url")
    if [ ! -f "$file" ] || ! echo "$sha  $file" | sha256sum -c --quiet 2>/dev/null; then
        curl -fsSL --retry 3 -o "$file.part" "$url" || die "$name: download failed ($url)"
        mv "$file.part" "$file"
    fi
    echo "$sha  $file" | sha256sum -c --quiet || { rm -f "$file"; die "$name $version: sha256 mismatch - refusing to install"; }
    # extract with Python (no bzip2 binary needed); refuse absolute or ../ paths
    python3 - "$file" "$WORK" "$name" <<'PY'
import sys, tarfile
src, dst, name = sys.argv[1:4]
with tarfile.open(src, 'r:*') as t:
    for m in t.getmembers():
        parts = m.name.split('/')
        if m.name.startswith('/') or '..' in parts or parts[0] != name:
            sys.exit(f'{name}: unexpected path in archive: {m.name}')
    try:
        t.extractall(dst, filter='data')
    except TypeError:  # Python < 3.12: paths were checked above
        t.extractall(dst)
PY
    printf '  %-15s %-9s ok\n' "$name" "$version"
done

POD=$(live_pod); [ -n "$POD" ] || die "no ready pod"
installed_version() {  # version string in the plugin's setup.php on the volume, or empty
    kexec sh -c "grep -oE \"_VERSION['\\\"]?\\s*,\\s*['\\\"][0-9.]+\" $REMOTE/$1/setup.php 2>/dev/null | grep -oE '[0-9.]+\$' | head -1" || true
}

step "3. copy to $POD:$REMOTE"
changed=0
for line in "${PLUGINS[@]}"; do
    read -r name version _ _ <<<"$line"
    if [ "$(installed_version "$name")" = "$version" ]; then
        printf '  %-15s %-9s already on the volume\n' "$name" "$version"
        continue
    fi
    kexec rm -rf "$REMOTE/$name.new"
    kubectl -n "$NS" cp "$WORK/$name" "$POD:$REMOTE/$name.new" -c "$CONTAINER"
    kexec sh -c "rm -rf '$REMOTE/$name' && mv '$REMOTE/$name.new' '$REMOTE/$name'"
    printf '  %-15s %-9s copied\n' "$name" "$version"
    changed=1
done

if [ $changed = 1 ]; then
    step "4. rolling restart (new PHP code)"
    kubectl -n "$NS" rollout restart deploy/glpi-app
    kubectl -n "$NS" rollout status deploy/glpi-app --timeout=600s
    for _ in $(seq 60); do
        [ -z "$(kubectl -n "$NS" get pods -l "$APP_LABEL" -o jsonpath='{range .items[?(@.metadata.deletionTimestamp)]}{.metadata.name}{end}')" ] && break
        sleep 3
    done
    POD=$(live_pod)
else
    step "4. restart not needed"
fi

# plugin:list table row "| name | Title | version | Enabled | ..." -> is <name> Enabled at <version>?
enabled_at() {
    echo "$1" | awk -F'|' -v n="$2" -v v="$3" '
        { for (i = 1; i <= NF; i++) { gsub(/^ +| +$/, "", $i) } }
        $2 == n && $4 == v && $5 == "Enabled" { found = 1 }
        END { exit !found }'
}

step "5. install + activate"
list=$(kexec php bin/console plugin:list 2>/dev/null)
for line in "${PLUGINS[@]}"; do
    read -r name version _ _ <<<"$line"
    if enabled_at "$list" "$name" "$version"; then
        printf '  %-15s %-9s enabled\n' "$name" "$version"
        continue
    fi
    kexec php bin/console plugin:install "$name" -u glpi --force --no-interaction >/dev/null || die "$name: plugin:install failed"
    kexec php bin/console plugin:activate "$name" --no-interaction >/dev/null || die "$name: plugin:activate failed"
    list=$(kexec php bin/console plugin:list 2>/dev/null)
    enabled_at "$list" "$name" "$version" || die "$name: not Enabled at $version after install"
    printf '  %-15s %-9s installed + enabled\n' "$name" "$version"
done
printf '\033[32mcommunity plugins ok\033[0m\n'
