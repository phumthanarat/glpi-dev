# GLPI on Kubernetes

Kustomize-based manifests for running the `glpi:11.0` image (built from
this repo's `Dockerfile`) on Kubernetes, with the database kept entirely
external to the cluster.

## Layout

```
k8s/
├── base/                    # shared by every environment
│   ├── namespace.yaml
│   ├── deployment.yaml       glpi-app, 3 replicas, initContainer writes
│   │                         config/config_db.php on first boot if missing
│   ├── service.yaml           ClusterIP :8080
│   ├── hpa.yaml                scales 3↔10 replicas on cpu > 70%
│   ├── cronjob.yaml            `php front/cron.php` every 2 min — see
│   │                           customizations/README.md's "Known gaps"
│   │                           note: also needs every CronTask switched
│   │                           to MODE_EXTERNAL (customizations/setup-10-cron.php)
│   │                           or this silently drives nothing
│   └── secret-db-external.example.yaml   template only — never applied as-is
│
├── overlays/
│   ├── local-dev/            what's actually running today, on a
│   │                          single-node Docker Desktop cluster
│   │   ├── pvc.yaml            hostPath, ReadWriteOnce (fine: one node)
│   │   ├── service-nodeport.yaml   :30080, since no Ingress controller
│   │   │                          is installed on this cluster
│   │   ├── mariadb-deployment.yaml   in-cluster stand-in for the
│   │   ├── mariadb-service.yaml       "external" database, local-dev only
│   │   ├── mariadb-pvc.yaml            (see mariadb-deployment.yaml)
│   │   ├── mariadb-secret.example.yaml   template only — never applied as-is
│   │   ├── mailpit-deployment.yaml    dev-only fake SMTP server, catches
│   │   ├── mailpit-service.yaml        every notification email instead of
│   │   │                              sending it — inbox at :30825
│   │   ├── mailpit-relay-secret.example.yaml   template only, pending
│   │   │                              real relay creds — enables mailpit's
│   │   │                              "Release" (resend) feature once applied
│   │   ├── mailpit-config-form-*.yaml   small internal web form (see
│   │   │                              customizations/mailpit-config-form/)
│   │   │                              for setting the relay Secret above
│   │   │                              without a terminal — NodePort
│   │   │                              closed by default, see below
│   │   ├── greenmail-deployment.yaml  dev-only test IT mailbox (IMAP :3143,
│   │   ├── greenmail-service.yaml      SMTP :3025, in-memory, cluster-only)
│   │   │                              that GLPI's Mail Receiver reads for
│   │   │                              the e-mail ticket channel (setup-19)
│   │   ├── mail-intake-secret.example.yaml   template only — the mailbox
│   │   │                              GLPI reads; production: same Secret
│   │   │                              name, the real IT mailbox
│   │   ├── ingress.yaml               Kong Ingress, host dev.glpi.labs
│   │   └── kustomization.yaml
│   │
│   └── production/           for a real multi-node cluster (e.g. 3
│       │                      control-plane + 3 worker)
│       ├── storageclass-longhorn.yaml
│       ├── pvc.yaml            Longhorn, ReadWriteMany (needed: pods can
│       │                       land on any of several workers)
│       ├── ingress.yaml         Kong Ingress (ingressClassName: kong)
│       ├── patch-spread-pods.yaml  spreads replicas across workers
│       └── kustomization.yaml
│
└── INSTALL-production.md    Helm commands for Longhorn + Kong — read
                              before using the production overlay
```

## Why the database isn't in `base/`

GLPI's database is treated as external to the cluster on purpose (see the
architecture write-up this was designed from). The app pods only need a
reachable host/port/user/password, supplied via the `db-external` Secret
— nothing about the database's own scaling, backups or HA is `base/`'s
concern. `overlays/local-dev` adds an in-cluster mariadb anyway (see
below) purely as a convenient stand-in for "external" on a laptop —
`overlays/production` still expects a real external database.

## Usage

```bash
# This cluster, right now — single node, no Ingress controller installed
kubectl apply -k k8s/overlays/local-dev

# A real target cluster — after Longhorn + Kong are installed
# (see INSTALL-production.md) and a real db-external Secret exists
kubectl apply -k k8s/overlays/production
```

Preview what either overlay actually renders before applying:

```bash
kubectl kustomize k8s/overlays/local-dev
kubectl kustomize k8s/overlays/production
```

## The `db-external` Secret

Never committed — `base/secret-db-external.example.yaml` is a template.
Create the real one imperatively:

```bash
kubectl create secret generic db-external --namespace glpi \
  --from-literal=host=<db-host> \
  --from-literal=port=3306 \
  --from-literal=database=glpi \
  --from-literal=user=<db-user> \
  --from-literal=password='<db-password>'
```

On `local-dev`, this currently points at the in-cluster `mariadb`
Service defined in that overlay (see `mariadb-deployment.yaml` and
`mariadb-secret.example.yaml`) — itself just a stand-in until a real
external database exists. Swapping to one later is only ever a Secret
change; nothing else in `k8s/` needs to move.

## The `mailpit-relay` Secret (pending)

Mailpit only captures notification emails today (verified: creating a
ticket queues `glpi_queuednotifications` rows, the CronJob flushes them,
mailpit's API shows them arriving). It also supports **"Release"**
(resend/forward a captured message through a real SMTP server), but
that needs an actual relay — `mailpit-relay-secret.example.yaml` is a
template Secret (`MP_SMTP_RELAY_HOST`/`PORT`/`USERNAME`/`PASSWORD`/
`SECURE`/`ALLOWED_RECIPIENTS`) that `mailpit-deployment.yaml` already
wires in via `envFrom` (`optional: true`, so mailpit runs exactly as
before with no such Secret present — confirmed by rollout).

Once a real relay exists, create the actual secret and restart
(commands in the template file's header comment) — no other change
needed, same "Secret change only" shape as `db-external` above.

Alternatively, use the web form (`mailpit-config-form` — see
`customizations/mailpit-config-form/`) instead of kubectl: it patches
the same Secret and restarts the same Deployment, via a ServiceAccount
whose RBAC Role is scoped to exactly those two named objects
(`mailpit-config-form-rbac.yaml`) — verified it cannot read or touch
anything else in the namespace.

The app and its ClusterIP Service always run (`mailpit-config-form-service.yaml`,
in `kustomization.yaml`'s resource list), but its NodePort
(`mailpit-config-form-nodeport.yaml`) is **not** — the user closed it
after using it once, since there's no auth in front of the form.
Re-open it with:
```
kubectl apply -f k8s/overlays/local-dev/mailpit-config-form-nodeport.yaml
```
then it's reachable at `http://localhost:30826/`. Close it again with
`kubectl delete service mailpit-config-form-nodeport -n glpi`.

Rebuild after changing the app itself:
```
docker build -t mailpit-config-form:local customizations/mailpit-config-form
kubectl rollout restart deployment/mailpit-config-form -n glpi
```

## What's still missing for a genuine 1,000 RPS

`local-dev` proves the shape of this deployment works: pods share
config/sessions correctly, the Service load-balances across replicas,
HPA scales on CPU. It does not itself reach 1,000 sustained RPS — that
needs, roughly in order: a real multi-node cluster, `overlays/production`
applied on it (Longhorn + Kong), Redis for sessions/cache once pods
truly span nodes, and confirming the external database can take the
resulting connection/query load (GLPI's search/list pages are
query-heavy — it's the more likely ceiling before the app tier).
