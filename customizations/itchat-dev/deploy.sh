#!/usr/bin/env bash
#
# Deploys customizations/itchat to the GLPI pods in one step, without the
# widget disappearing:
#
#   1. lint      php -l on every PHP file, node --check on chat.js (via docker)
#   2. build     content-hashed copies public/dist/chat.<hash>.{js,css} + manifest.json
#                (see plugin_itchat_assets() in setup.php for why)
#   3. copy      onto the shared glpi-data PVC through one live, non-terminating pod.
#                Asset files go first and manifest.json last, so the manifest never
#                points at a file that isn't there yet
#   4. restart   rolling restart ONLY if a PHP file changed (the image runs opcache with
#                validate_timestamps=0). JS/CSS-only changes need none
#   5. update    plugin:install --force + activate ONLY if PLUGIN_ITCHAT_VERSION changed,
#                i.e. a DB migration in hook.php. That is the one case where the plugin is
#                briefly unloaded; bump the version only for schema changes
#   6. verify    plugin enabled at the expected version, new assets served (HTTP 200)
#   6b. browser  real login as a requester and a technician: widget visible, no JS errors.
#                On failure manifest.json is pointed back at the previous build (instant
#                rollback of JS/CSS) and the deploy fails
#   7. prune     dist files older than the previous build
#
# Usage:  customizations/itchat-dev/deploy.sh [--test] [--dry-run]
#   --test     run tests/run.sh smoke against the cluster after deploying
#   --dry-run  lint + build + report what would change, touch nothing
#   --no-ui-check  skip step 6b (e.g. no docker)
# Env for 6b: CHECK_USERS="login:pass,login:pass" (default post-only:postonly,glpi:glpi), GLPI_URL
#
# Env: NS (default glpi), APP_LABEL (default app=glpi-app), CONTAINER (default glpi-app)

set -euo pipefail

NS=${NS:-glpi}
APP_LABEL=${APP_LABEL:-app=glpi-app}
CONTAINER=${CONTAINER:-glpi-app}
REMOTE=/var/www/glpi/plugins/itchat

HERE=$(cd "$(dirname "$0")" && pwd)
SRC=$(cd "$HERE/../itchat" && pwd)
RUN_TESTS=0
DRY_RUN=0
SKIP_UI_CHECK=0
for arg in "$@"; do
    case "$arg" in
        --test) RUN_TESTS=1 ;;
        --dry-run) DRY_RUN=1 ;;
        --no-ui-check) SKIP_UI_CHECK=1 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
die() { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

# A Running pod that isn't being deleted and whose containers are all ready. Right after a
# rollout, .items[0] can still be a terminating pod (commands on it get killed, exit 143).
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

# tests/check_widget.py in the Playwright image; reaches the NodePort via host.docker.internal
widget_check() {
    local url=${GLPI_URL:-http://localhost:30080}
    url=${url/localhost/host.docker.internal}
    url=${url/127.0.0.1/host.docker.internal}
    timeout 300 docker run --rm --add-host host.docker.internal:host-gateway \
        -e GLPI_URL="$url" ${CHECK_USERS:+-e CHECK_USERS="$CHECK_USERS"} \
        -v "$HERE/tests":/tests:ro -w /tests "${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright/python:v1.55.0-noble}" \
        sh -c 'pip install -q --timeout 20 --retries 2 playwright==1.55.0 >/dev/null 2>&1; python check_widget.py'
}

# ------------------------------------------------------------------ 1. lint
step "1. lint"
lint_out=$(docker run --rm -v "$SRC":/src:ro php:8.3-cli sh -c \
    'find /src -name "*.php" -print0 | xargs -0 -n1 php -l 2>&1' || true)
if printf '%s\n' "$lint_out" | grep -v '^No syntax errors' | grep -q .; then
    printf '%s\n' "$lint_out" | grep -v '^No syntax errors' >&2
    die "PHP lint failed"
fi
docker run --rm -v "$SRC":/src:ro node:20-alpine node --check /src/public/chat.js || die "JS syntax error"
echo "ok"

# ------------------------------------------------------------------ 2. build
step "2. build"
BUILD=$(mktemp -d)
trap 'rm -rf "$BUILD"' EXIT
cp -r "$SRC" "$BUILD/itchat"
rm -rf "$BUILD/itchat/public/dist"
mkdir -p "$BUILD/itchat/public/dist"
JS_HASH=$(sha1sum "$SRC/public/chat.js" | cut -c1-10)
CSS_HASH=$(sha1sum "$SRC/public/chat.css" | cut -c1-10)
JS_FILE="dist/chat.$JS_HASH.js"
CSS_FILE="dist/chat.$CSS_HASH.css"
cp "$SRC/public/chat.js" "$BUILD/itchat/public/$JS_FILE"
cp "$SRC/public/chat.css" "$BUILD/itchat/public/$CSS_FILE"
printf '{"js": "%s", "css": "%s", "built": "%s"}\n' "$JS_FILE" "$CSS_FILE" "$(date -Iseconds)" \
    > "$BUILD/itchat/public/dist/manifest.json"
LOCAL_VERSION=$(sed -n "s/^define('PLUGIN_ITCHAT_VERSION', '\([^']*\)');/\1/p" "$SRC/setup.php")
[ -n "$LOCAL_VERSION" ] || die "could not read PLUGIN_ITCHAT_VERSION from setup.php"
echo "version $LOCAL_VERSION  js $JS_FILE  css $CSS_FILE"

# ------------------------------------------------------------------ what changed
step "compare with cluster"
POD=$(live_pod)
[ -n "$POD" ] || die "no ready glpi-app pod in namespace $NS"
echo "pod $POD"

local_php=$(cd "$SRC" && find . -name '*.php' -exec sha1sum {} + | sort -k2)
remote_php=$(kexec sh -c "cd $REMOTE 2>/dev/null && find . -name '*.php' -exec sha1sum {} + | sort -k2" || true)
PHP_CHANGED=0
[ "$local_php" = "$remote_php" ] || PHP_CHANGED=1

DB_VERSION=$(kexec php bin/console plugin:list 2>/dev/null | awk -F'|' '$2 ~ /^ *itchat *$/ {gsub(/ /, "", $4); print $4}')
DB_STATUS=$(kexec php bin/console plugin:list 2>/dev/null | awk -F'|' '$2 ~ /^ *itchat *$/ {gsub(/^ +| +$/, "", $5); print $5}')
VERSION_CHANGED=0
[ "$DB_VERSION" = "$LOCAL_VERSION" ] || VERSION_CHANGED=1

PREV_MANIFEST=$(kexec sh -c "cat $REMOTE/public/dist/manifest.json 2>/dev/null || true")

echo "php changed:      $([ $PHP_CHANGED = 1 ] && echo yes || echo no)"
echo "version:          cluster=${DB_VERSION:-none} ($DB_STATUS) local=$LOCAL_VERSION$([ $VERSION_CHANGED = 1 ] && echo '  -> plugin update needed')"
if [ "$DRY_RUN" = 1 ]; then
    echo; echo "dry run, nothing deployed"; exit 0
fi

# ------------------------------------------------------------------ 3. copy
step "3. copy"
kexec mkdir -p "$REMOTE/public/dist"
kubectl -n "$NS" cp "$BUILD/itchat/public/$JS_FILE" "$POD:$REMOTE/public/$JS_FILE" -c "$CONTAINER"
kubectl -n "$NS" cp "$BUILD/itchat/public/$CSS_FILE" "$POD:$REMOTE/public/$CSS_FILE" -c "$CONTAINER"
mv "$BUILD/itchat/public/dist/manifest.json" "$BUILD/manifest.json"
kubectl -n "$NS" cp "$BUILD/itchat/." "$POD:$REMOTE" -c "$CONTAINER"
kubectl -n "$NS" cp "$BUILD/manifest.json" "$POD:$REMOTE/public/dist/manifest.json" -c "$CONTAINER"
echo "copied"

# ------------------------------------------------------------------ 4. restart
if [ "$PHP_CHANGED" = 1 ]; then
    step "4. rolling restart (PHP changed)"
    kubectl -n "$NS" rollout restart deploy/glpi-app
    kubectl -n "$NS" rollout status deploy/glpi-app --timeout=300s
    # let terminating pods go away so live_pod() can't race them
    for _ in $(seq 1 30); do
        [ -z "$(kubectl -n "$NS" get pods -l "$APP_LABEL" -o jsonpath='{range .items[?(@.metadata.deletionTimestamp)]}{.metadata.name}{end}')" ] && break
        sleep 2
    done
    POD=$(live_pod)
else
    step "4. restart not needed (no PHP change)"
fi

# ------------------------------------------------------------------ 5. plugin update
if [ "$VERSION_CHANGED" = 1 ]; then
    step "5. plugin update ${DB_VERSION:-none} -> $LOCAL_VERSION (widget is unloaded until this finishes)"
    kexec php bin/console plugin:install itchat -u glpi --force --no-interaction
    kexec php bin/console plugin:activate itchat --no-interaction
elif [ "$DB_STATUS" != "Enabled" ]; then
    step "5. plugin is '$DB_STATUS', activating"
    kexec php bin/console plugin:activate itchat --no-interaction
else
    step "5. plugin update not needed"
fi

# ------------------------------------------------------------------ 6. verify
step "6. verify"
line=$(kexec php bin/console plugin:list 2>/dev/null | awk -F'|' '$2 ~ /^ *itchat *$/')
echo "$line" | grep -q "$LOCAL_VERSION" || die "plugin version mismatch after deploy: $line"
echo "$line" | grep -q "Enabled" || die "plugin not enabled after deploy: $line"
for f in "$JS_FILE" "$CSS_FILE"; do
    code=$(kexec curl -s -o /dev/null -w '%{http_code}' "http://localhost:8080/plugins/itchat/$f")
    [ "$code" = 200 ] || die "$f served HTTP $code"
done
echo "plugin enabled at $LOCAL_VERSION, assets served"

# ------------------------------------------------------------------ 6b. browser check + auto rollback
# A syntactically valid chat.js can still throw at runtime and render no widget at all
# (happened in 1.6.0). Load real pages; on failure point manifest.json back at the
# previous build (its files are still there: pruning runs after this).
if [ "$SKIP_UI_CHECK" = 1 ]; then
    step "6b. browser check skipped (--no-ui-check)"
else
    step "6b. browser check (widget renders, no JS errors)"
    if ! widget_check; then
        if [ -n "$PREV_MANIFEST" ]; then
            printf '%s\n' "$PREV_MANIFEST" > "$BUILD/prev-manifest.json"
            kubectl -n "$NS" cp "$BUILD/prev-manifest.json" "$POD:$REMOTE/public/dist/manifest.json" -c "$CONTAINER"
            echo "ROLLED BACK assets to the previous build: $PREV_MANIFEST" >&2
            widget_check || echo "widget still broken after asset rollback (PHP side?)" >&2
        fi
        die "browser check failed"
    fi
fi

# ------------------------------------------------------------------ 7. prune
step "7. prune old assets"
keep="$JS_FILE $CSS_FILE $(printf '%s' "$PREV_MANIFEST" | python3 -c 'import json,sys
try: d = json.load(sys.stdin); print(d.get("js", ""), d.get("css", ""))
except Exception: pass')"
kexec sh -c "cd $REMOTE/public && for f in dist/chat.*; do case \" $keep \" in *\" \$f \"*) ;; *) rm -f \"\$f\"; echo \"removed \$f\";; esac; done"

if [ "$RUN_TESTS" = 1 ]; then
    step "smoke tests"
    "$HERE/tests/run.sh" smoke
fi
printf '\n\033[32mdeployed itchat %s\033[0m\n' "$LOCAL_VERSION"
