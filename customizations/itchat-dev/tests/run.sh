#!/usr/bin/env bash
#
# itchat test suite against the running cluster.
#
#   tests/run.sh smoke       flow x3 + security + features          (~1 min, used by deploy.sh --test)
#   tests/run.sh full        flow x30 + security + features + UI    (~4 min)
#   tests/run.sh features    off-hours reply, canned replies, idle auto-close / retention
#   tests/run.sh collab      transfer, ticket<->chat sync, rating + dashboard cards, search
#   tests/run.sh tickets     plain GLPI ticket lifecycle: team/SLA rules, approval, solve/close/reopen, visibility
#   tests/run.sh helpdesk    tickets filed through the real Helpdesk forms in a browser
#   tests/run.sh central     a technician opens tickets for requesters from the Central form
#   tests/run.sh qr          QR labels (itqr plugin): asset tab, labels page, scan -> report on a phone
#   tests/run.sh load        LOAD_PER_CHANNEL (400) tickets through each of the 5 channels, all checked
#                            against the rules (not part of full: ~30-40 min, leaves a mail backlog)
#   tests/run.sh email       e-mails -> tickets, replies -> followups, via the real mailbox + cron (~5 min)
#   tests/run.sh flow [N]    only the chat -> ticket flow, N rounds (default 3)
#   tests/run.sh security    only the negative/security checks
#   tests/run.sh ui          only the browser test
#
# Creates its own accounts/group via fixtures.php (itchat.test.*, "[itchat-test] Group") and
# removes them afterwards (unless KEEP_DATA=1), together with every chat, ticket and file they produced.
# Real chats and users are not touched.
#
# Needs: kubectl access, python3 + requests, docker (browser test uses the Playwright image).
# Env: GLPI_URL (default http://localhost:30080), NS, APP_LABEL, CONTAINER,
#      ADMIN_USER/ADMIN_PASS (Super-Admin for config-page checks, default glpi/glpi),
#      SHOTS (screenshot dir, default tests/shots)

set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
MODE=${1:-smoke}
export NS=${NS:-glpi}
APP_LABEL=${APP_LABEL:-app=glpi-app}
export CONTAINER=${CONTAINER:-glpi-app}
export GLPI_URL=${GLPI_URL:-http://localhost:30080}
export SHOTS=${SHOTS:-$HERE/shots}
PLAYWRIGHT_IMAGE=${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright/python:v1.55.0-noble}

# The OLDEST ready pod: the HPA scales glpi-app up under load and, when it scales back down,
# removes the newest pods first - a test holding on to one of those would lose its pod mid-run.
POD=$(kubectl -n "$NS" get pods -l "$APP_LABEL" -o json | python3 -c '
import json, sys
for p in sorted(json.load(sys.stdin)["items"], key=lambda p: p["metadata"]["creationTimestamp"]):
    st = p.get("status", {})
    if not p["metadata"].get("deletionTimestamp") and st.get("phase") == "Running" \
            and all(c.get("ready") for c in st.get("containerStatuses", [])):
        print(p["metadata"]["name"]); break')
[ -n "$POD" ] || { echo "no ready glpi-app pod" >&2; exit 1; }
export ITCHAT_TEST_POD=$POD

kexec() { kubectl -n "$NS" exec "$POD" -c "$CONTAINER" -- "$@"; }
kexec mkdir -p /tmp/itchat-tests
kubectl -n "$NS" cp "$HERE/fixtures.php" "$POD:/tmp/itchat-tests/fixtures.php" -c "$CONTAINER"

teardown() {
    echo; echo "== teardown"
    if [ "${KEEP_DATA:-0}" = 1 ]; then
        echo "skipped (KEEP_DATA=1): test users, tickets and chats stay; the next run's setup removes them"
        return
    fi
    kexec php /tmp/itchat-tests/fixtures.php teardown
}
trap teardown EXIT

echo "== fixtures (pod $POD)"
setup_out=$(kexec php /tmp/itchat-tests/fixtures.php setup) || { echo "fixture setup failed" >&2; exit 1; }
ITCHAT_TEST_CREDS=$(printf '%s\n' "$setup_out" | tail -1 | python3 -c 'import json,sys; print(json.dumps(json.load(sys.stdin)["users"]))')
export ITCHAT_TEST_CREDS
echo "test accounts: $(printf '%s' "$ITCHAT_TEST_CREDS" | python3 -c 'import json,sys; print(", ".join(json.load(sys.stdin)))')"

FAILED=()
run() {
    local name=$1; shift
    echo; echo "== $name"
    if ! "$@"; then FAILED+=("$name"); fi
}
py() { (cd "$HERE" && python3 "$@"); }
ui() {
    # the browser runs in a container: reach the host's NodePort via host.docker.internal
    local url=${GLPI_URL/localhost/host.docker.internal}
    url=${url/127.0.0.1/host.docker.internal}
    docker run --rm --add-host host.docker.internal:host-gateway \
        -e GLPI_URL="$url" -e ITCHAT_TEST_CREDS -e SHOTS=/shots \
        -v "$HERE":/tests:ro -v "$SHOTS":/shots -w /tests "$PLAYWRIGHT_IMAGE" \
        sh -c 'pip install -q requests playwright==1.55.0 >/dev/null 2>&1; python test_ui.py'
}
mkdir -p "$SHOTS"

case "$MODE" in
    smoke)    run "flow x3" py test_flow.py 3; run security py test_security.py; run features py test_features.py; run collab py test_collab.py ;;
    # email first: flow x30 queues hundreds of notification e-mails, and the e-mail test waits
    # for GLPI's confirmation e-mail, which would sit behind that backlog
    full)     run email py test_email.py; run "flow x30" py test_flow.py 30; run security py test_security.py; run features py test_features.py; run collab py test_collab.py; run tickets py test_tickets.py; run helpdesk py test_helpdesk.py; run central py test_central.py; run qr py test_qr.py; run ui ui ;;
    features) run features py test_features.py ;;
    collab)   run collab py test_collab.py ;;
    tickets)  run tickets py test_tickets.py ;;
    helpdesk) run helpdesk py test_helpdesk.py ;;
    central)  run central py test_central.py ;;
    email)    run email py test_email.py ;;
    qr)       run qr py test_qr.py ;;
    load)     run load py test_load.py ;;
    flow)     run "flow x${2:-3}" py test_flow.py "${2:-3}" ;;
    security) run security py test_security.py ;;
    ui)       run ui ui ;;
    *) echo "usage: $0 smoke|full|flow [N]|security|features|collab|tickets|helpdesk|central|email|qr|load|ui" >&2; exit 2 ;;
esac

echo
if [ ${#FAILED[@]} -eq 0 ]; then
    printf '\033[32mALL PASSED (%s)\033[0m\n' "$MODE"
else
    printf '\033[31mFAILED: %s\033[0m\n' "${FAILED[*]}"
    exit 1
fi
