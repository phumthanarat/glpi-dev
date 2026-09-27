#!/usr/bin/env bash
#
# One-command install / upgrade of this GLPI ITSM onto a Kubernetes cluster.
#
#   cp install.env.example install.env     # fill in: image, URL, database, SMTP, admin password
#   ./install.sh -c install.env            # asks for confirmation, then does everything below
#
#   1. namespace + Secrets (database, IT mailbox)
#   2. manifests: the overlay with your image, namespace and Ingress host
#   3. database: creates GLPI's tables on an empty database, or updates the schema of an
#      existing GLPI database (one-off Job, same image)
#   4. waits for the app to be ready
#   5. configuration inside a pod, in order: URL, ITSM setup-01..20 (calendar, teams,
#      categories, SLA, rules, approvals, notifications + SMTP, dashboard, request sources,
#      e-mail channel if set), IT Chat + IT QR plugins, branding, daily summary script
#   6. security: new 'glpi' admin password, default accounts deactivated
#   7. checks the app answers
#
# Safe to run again (upgrade, changed settings): every step is idempotent.
#
# Options:  -c FILE   settings file (default install.env)
#           -y        don't ask for confirmation
#           --skip-k8s   only steps 5-7 (configuration of an already running install)
# Needs: kubectl (context = the target cluster), docker (lints the plugins), python3, curl.
set -euo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
CONFIG=install.env
YES=0
SKIP_K8S=0
while [ $# -gt 0 ]; do
    case "$1" in
        -c) CONFIG=$2; shift 2 ;;
        -y|--yes) YES=1; shift ;;
        --skip-k8s) SKIP_K8S=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "unknown option: $1 (see --help)" >&2; exit 2 ;;
    esac
done

step() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
die()  { printf '\033[31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

[ -f "$CONFIG" ] || die "settings file $CONFIG not found (cp install.env.example install.env)"
# KEY=value lines, read as data (not sourced): values may contain spaces, $, quotes...;
# one pair of surrounding quotes is removed. Comments (#) and blank lines are skipped.
while IFS= read -r line || [ -n "$line" ]; do
    line=${line%$'\r'}
    [[ "$line" =~ ^[[:space:]]*(#|$) ]] && continue
    [[ "$line" =~ ^[[:space:]]*([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || die "$CONFIG: cannot read line: $line"
    key=${BASH_REMATCH[1]}; val=${BASH_REMATCH[2]}
    if [[ "$val" =~ ^\"(.*)\"$ || "$val" =~ ^\'(.*)\'$ ]]; then val=${BASH_REMATCH[1]}; fi
    export "$key=$val"
done < "$CONFIG"

NAMESPACE=${NAMESPACE:-glpi}
OVERLAY=${OVERLAY:-k8s/overlays/production}
GLPI_LANGUAGE=${GLPI_LANGUAGE:-en_GB}
KEEP_DEFAULT_ACCOUNTS=${KEEP_DEFAULT_ACCOUNTS:-0}
APP_LABEL=app=glpi-app
CONTAINER=glpi-app

# ---------------------------------------------------------------- 0. checks
for v in IMAGE GLPI_URL DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD GLPI_ADMIN_PASSWORD; do
    val=${!v:-}
    [ -n "$val" ] || die "$v is not set in $CONFIG"
    case "$val" in *CHANGEME*) die "$v still has a CHANGEME placeholder in $CONFIG" ;; esac
done
[ ${#GLPI_ADMIN_PASSWORD} -ge 12 ] || die "GLPI_ADMIN_PASSWORD must be 12+ characters"
[[ "$GLPI_URL" =~ ^https?://[^/]+ ]] || die "GLPI_URL must look like https://itsm.company.com"
# Ingress host: from GLPI_URL unless set (e.g. GLPI_URL on a NodePort, Ingress on a name)
INGRESS_HOST=${INGRESS_HOST:-$(echo "$GLPI_URL" | sed -E 's#^https?://([^/:]+).*#\1#')}
for t in kubectl docker python3 curl; do command -v $t >/dev/null || die "$t is required"; done
[ -d "$HERE/$OVERLAY" ] || die "overlay $OVERLAY not found"

# image "registry:5000/path/name:tag" -> name + tag (a ':' after the last '/' is the tag)
IMAGE_NAME=$IMAGE; IMAGE_TAG=latest
if [[ "${IMAGE##*/}" == *:* ]]; then IMAGE_NAME=${IMAGE%:*}; IMAGE_TAG=${IMAGE##*:}; fi

CONTEXT=$(kubectl config current-context 2>/dev/null) || die "kubectl has no current context"
SHOW_K8S=$OVERLAY; [ $SKIP_K8S = 1 ] && SHOW_K8S="$OVERLAY  (skipped: --skip-k8s)"
SHOW_SMTP=${SMTP_HOST:-"(none: notifications are not delivered)"}
SHOW_INTAKE=${MAIL_INTAKE_HOST:-"(not set, skipped)"}
SHOW_ACCOUNTS="tech/normal/post-only deactivated"; [ "$KEEP_DEFAULT_ACCOUNTS" = 1 ] && SHOW_ACCOUNTS="kept active"
cat <<EOF

  cluster (kubectl context)  $CONTEXT
  namespace                  $NAMESPACE
  overlay                    $SHOW_K8S
  image                      $IMAGE_NAME:$IMAGE_TAG
  URL / Ingress host         $GLPI_URL / $INGRESS_HOST
  database                   $DB_USER@$DB_HOST:$DB_PORT/$DB_NAME
  SMTP                       $SHOW_SMTP
  e-mail ticket channel      $SHOW_INTAKE
  default accounts           $SHOW_ACCOUNTS

EOF
if [ $YES != 1 ]; then
    read -r -p "Install on this cluster? [y/N] " a
    [[ "$a" =~ ^[Yy] ]] || { echo "aborted"; exit 1; }
fi

kx() { kubectl -n "$NAMESPACE" "$@"; }
live_pod() {
    kx get pods -l "$APP_LABEL" -o json | python3 -c '
import json, sys
for p in sorted(json.load(sys.stdin)["items"], key=lambda p: p["metadata"]["creationTimestamp"]):
    st = p.get("status", {})
    if p["metadata"].get("deletionTimestamp") or st.get("phase") != "Running":
        continue
    if all(c.get("ready") for c in st.get("containerStatuses", [])):
        print(p["metadata"]["name"]); break'
}
wait_app() {
    kx rollout status deploy/glpi-app --timeout=600s
    for _ in $(seq 60); do
        [ -z "$(kx get pods -l "$APP_LABEL" -o jsonpath='{range .items[?(@.metadata.deletionTimestamp)]}{.metadata.name}{end}')" ] && break
        sleep 3
    done
}

if [ $SKIP_K8S != 1 ]; then
    # ------------------------------------------------------------ 1. namespace + Secrets
    step "1. namespace + Secrets"
    kubectl create namespace "$NAMESPACE" --dry-run=client -o yaml | kubectl apply -f -
    kx create secret generic db-external \
        --from-literal=host="$DB_HOST" --from-literal=port="$DB_PORT" --from-literal=database="$DB_NAME" \
        --from-literal=user="$DB_USER" --from-literal=password="$DB_PASSWORD" \
        --dry-run=client -o yaml | kubectl apply -f -
    if [ -n "${MAIL_INTAKE_HOST:-}" ]; then
        kx create secret generic mail-intake \
            --from-literal=MAIL_INTAKE_HOST="$MAIL_INTAKE_HOST" --from-literal=MAIL_INTAKE_ADDRESS="${MAIL_INTAKE_ADDRESS:-}" \
            --from-literal=MAIL_INTAKE_LOGIN="${MAIL_INTAKE_LOGIN:-}" --from-literal=MAIL_INTAKE_PASSWORD="${MAIL_INTAKE_PASSWORD:-}" \
            --dry-run=client -o yaml | kubectl apply -f -
    fi

    # ------------------------------------------------------------ 2. manifests
    step "2. manifests ($OVERLAY)"
    GEN=$HERE/k8s/.install
    rm -rf "$GEN"; mkdir -p "$GEN"
    cat > "$GEN/kustomization.yaml" <<EOF
# generated by install.sh - do not edit, not committed
apiVersion: kustomize.config.k8s.io/v1beta1
kind: Kustomization
namespace: $NAMESPACE
resources:
  - ../../$OVERLAY
images:
  - name: glpi
    newName: $IMAGE_NAME
    newTag: "$IMAGE_TAG"
patches:
  - target: { kind: Ingress, name: glpi-app }
    patch: |-
      - op: replace
        path: /spec/rules/0/host
        value: $INGRESS_HOST
EOF
    kubectl apply -k "$GEN"

    # ------------------------------------------------------------ 3. database
    step "3. database (create tables, or update an existing GLPI schema)"
    kx delete job glpi-db-init --ignore-not-found --wait=true
    kx apply -f - <<EOF
apiVersion: batch/v1
kind: Job
metadata:
  name: glpi-db-init
  labels: { app: glpi-db-init }
spec:
  backoffLimit: 0
  ttlSecondsAfterFinished: 86400
  template:
    spec:
      restartPolicy: Never
      securityContext: { fsGroup: 33 }
      containers:
        - name: db-init
          image: $IMAGE_NAME:$IMAGE_TAG
          imagePullPolicy: IfNotPresent
          command: ["sh", "-c"]
          args:
            - |
              set -e
              TABLES=\$(php -r '\$m = new mysqli(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PASSWORD"), getenv("DB_NAME"), (int) getenv("DB_PORT"));
                \$r = \$m->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE \"glpi\\\\_%\"");
                echo \$r->fetch_row()[0];')
              echo "glpi_* tables in \$DB_NAME: \$TABLES"
              DBOPTS="--db-host=\$DB_HOST --db-port=\$DB_PORT --db-name=\$DB_NAME --db-user=\$DB_USER --db-password=\$DB_PASSWORD --reconfigure --no-interaction"
              if [ "\$TABLES" = 0 ]; then
                echo "empty database: installing GLPI"
                php bin/console database:install \$DBOPTS --default-language="$GLPI_LANGUAGE" --no-telemetry
              else
                echo "existing GLPI database: updating the schema if needed"
                php bin/console database:configure \$DBOPTS
                php bin/console database:update --no-interaction
              fi
          env:
            - { name: DB_HOST, valueFrom: { secretKeyRef: { name: db-external, key: host } } }
            - { name: DB_PORT, valueFrom: { secretKeyRef: { name: db-external, key: port } } }
            - { name: DB_NAME, valueFrom: { secretKeyRef: { name: db-external, key: database } } }
            - { name: DB_USER, valueFrom: { secretKeyRef: { name: db-external, key: user } } }
            - { name: DB_PASSWORD, valueFrom: { secretKeyRef: { name: db-external, key: password } } }
          volumeMounts:
            - { name: glpi-data, mountPath: /var/www/glpi/config, subPath: config }
            - { name: glpi-data, mountPath: /var/www/glpi/files, subPath: files }
            - { name: glpi-data, mountPath: /var/www/glpi/marketplace, subPath: marketplace }
            - { name: glpi-data, mountPath: /var/www/glpi/plugins, subPath: plugins }
      volumes:
        - name: glpi-data
          persistentVolumeClaim: { claimName: glpi-data }
EOF
    if ! kx wait --for=condition=complete job/glpi-db-init --timeout=900s 2>/dev/null; then
        kx logs job/glpi-db-init --tail=40 || true
        die "database step failed (logs above). Check DB_* settings and that the database is reachable from the cluster"
    fi
    kx logs job/glpi-db-init | grep -vE 'password|^\s*$' | tail -5

    # ------------------------------------------------------------ 4. app ready
    step "4. app (restart so every pod starts on the ready database)"
    kx rollout restart deploy/glpi-app
    wait_app
fi

# ---------------------------------------------------------------- 5. configuration
POD=$(live_pod); [ -n "$POD" ] || die "no ready glpi-app pod in namespace $NAMESPACE"
REMOTE=/tmp/itsm-install
copy_scripts() {
    kx exec "$POD" -c "$CONTAINER" -- rm -rf "$REMOTE"
    kx exec "$POD" -c "$CONTAINER" -- mkdir -p "$REMOTE"
    tar -C "$HERE/customizations" --exclude='itchat-dev' --exclude='itqr-dev' --exclude='itchat' --exclude='itqr' \
        --exclude='mailpit-config-form' --exclude='seed-*' --exclude='*.sql' --exclude='BACKUP_*' -cf - . \
        | kx exec -i "$POD" -c "$CONTAINER" -- tar -C "$REMOTE" -xf -
}
run() {  # run <script> [VAR=value ...]
    local script=$1; shift
    printf '  %-48s ' "$script"
    local out
    if out=$(kx exec "$POD" -c "$CONTAINER" -- env "$@" php "$REMOTE/$script" 2>&1); then
        echo "ok"
    else
        echo "FAILED"; echo "$out" | tail -15 >&2
        die "$script failed"
    fi
}

step "5. configuration (pod $POD)"
copy_scripts
run setup-00-base-url.php GLPI_URL="$GLPI_URL" GLPI_TIMEZONE="${GLPI_TIMEZONE:-Asia/Bangkok}" GLPI_LANGUAGE="$GLPI_LANGUAGE"
for s in setup-01-calendar setup-02-groups setup-03-categories setup-04-sla setup-05-business-rules \
         setup-06-escalation setup-07-notifications setup-08-incident-request-approval setup-09-dashboard setup-10-cron; do
    run "$s.php"
done
run setup-11-mail.php SMTP_HOST="${SMTP_HOST:-}" SMTP_PORT="${SMTP_PORT:-587}" SMTP_USER="${SMTP_USER:-}" \
    SMTP_PASSWORD="${SMTP_PASSWORD:-}" SMTP_VERIFY_CERT="${SMTP_VERIFY_CERT:-1}" \
    MAIL_FROM="${MAIL_FROM:-}" MAIL_FROM_NAME="${MAIL_FROM_NAME:-}"
for s in setup-12-line-webhook setup-13-chat-webhooks setup-14-google-chat-webhook; do run "$s.php"; done

step "5b. plugins: IT Chat + IT QR"
NS=$NAMESPACE "$HERE/customizations/itchat-dev/deploy.sh" --no-ui-check | grep -E '^\S|==|deployed|ERROR'
NS=$NAMESPACE "$HERE/customizations/itqr-dev/deploy.sh" | grep -E '==|deployed|ERROR'
POD=$(live_pod); copy_scripts   # the plugin deploys may have restarted the pods

step "5c. ITSM configuration that needs the plugins"
for s in setup-16-itchat-dashboard setup-17-requester-group-rule setup-18-technician-assign-right; do run "$s.php"; done
if [ -n "${MAIL_INTAKE_HOST:-}" ]; then
    run setup-19-mail-intake.php MAIL_INTAKE_HOST="$MAIL_INTAKE_HOST" MAIL_INTAKE_LOGIN="${MAIL_INTAKE_LOGIN:-}" \
        MAIL_INTAKE_PASSWORD="${MAIL_INTAKE_PASSWORD:-}" MAIL_INTAKE_ADDRESS="${MAIL_INTAKE_ADDRESS:-}"
else
    echo "  setup-19-mail-intake.php                         skipped (MAIL_INTAKE_HOST not set)"
fi
run setup-20-central-source-phone.php
run setup-22-list-columns.php

step "5d. branding"
for s in logo/apply-logo watermark/apply-watermark topbar-modern/apply-topbar dashboard-modern/apply-dashboard \
         dashboard-modern/recolor-cards rebrand-it-dev/apply-footer row-color/apply-row-color \
         search-modern/apply-search-modern priority-colors/set-priority-colors; do
    run "$s.php"
done
# daily summary CronJob (suspended until a channel is configured) reads its script from the volume
kx exec "$POD" -c "$CONTAINER" -- mkdir -p /var/www/glpi/files/_customizations/daily-summary
kx cp "$HERE/customizations/daily-summary/send-daily-summary.php" \
    "$POD:/var/www/glpi/files/_customizations/daily-summary/send-daily-summary.php" -c "$CONTAINER"
echo "  daily-summary script                             ok"

# ---------------------------------------------------------------- 6. security
step "6. security"
run setup-21-security.php GLPI_ADMIN_PASSWORD="$GLPI_ADMIN_PASSWORD" KEEP_DEFAULT_ACCOUNTS="$KEEP_DEFAULT_ACCOUNTS"
kx exec "$POD" -c "$CONTAINER" -- rm -rf "$REMOTE"

# ---------------------------------------------------------------- 7. checks
step "7. checks"
code=$(kx exec "$POD" -c "$CONTAINER" -- curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/)
[ "$code" = 200 ] || die "GLPI answers HTTP $code inside the pod"
echo "  inside the cluster: HTTP 200"
if ext=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$GLPI_URL/"); then
    echo "  $GLPI_URL: HTTP $ext"
else
    echo "  $GLPI_URL: not reachable from here yet (DNS / Ingress / TLS?)"
fi

ok "
Installed. Open $GLPI_URL and log in as 'glpi' with GLPI_ADMIN_PASSWORD.
Next: users (LDAP or Administration > Users) with a default group and group managers,
chat webhooks (setup-12..14), and a database backup. Full check against this install:
  GLPI_URL=$GLPI_URL ADMIN_PASS='...' NS=$NAMESPACE customizations/itchat-dev/tests/run.sh smoke"
