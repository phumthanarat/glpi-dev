# Installing prerequisites on the target (production) cluster

`overlays/production` assumes Longhorn and the Kong Ingress Controller are
already installed on the cluster — neither is created by these manifests,
and **neither has been tested against a real cluster from this session**
(the only cluster reachable here is a single-node Docker Desktop cluster
that doesn't even have the iSCSI kernel module Longhorn requires). Verify
chart versions against what's current when you actually run this.

## 1. Longhorn (distributed block storage)

Needs, on every worker node: `open-iscsi` installed and the `iscsi_tcp`
kernel module loadable, plus `nfs-common`/`nfs-utils` for the RWX
(ReadWriteMany) path GLPI's shared `glpi-data` volume needs. Longhorn
ships an environment-check script — run it against the real nodes first:

```bash
curl -sSfL https://raw.githubusercontent.com/longhorn/longhorn/v1.7.2/scripts/environment_check.sh | bash
```

Then install:

```bash
helm repo add longhorn https://charts.longhorn.io
helm repo update
helm install longhorn longhorn/longhorn \
  --namespace longhorn-system --create-namespace \
  --version 1.7.2
```

Confirm the CSI driver is ready before applying the overlay:

```bash
kubectl -n longhorn-system get pods
```

## 2. Kong Ingress Controller

```bash
helm repo add kong https://charts.konghq.com
helm repo update
helm install kong kong/kong \
  --namespace kong --create-namespace \
  --set ingressController.enabled=true \
  --set proxy.type=LoadBalancer
```

`proxy.type=LoadBalancer` needs the target environment to actually
provision one (cloud LB, or MetalLB on bare metal) — swap to `NodePort`
if there isn't one yet, the same way `overlays/local-dev` does it.

## 3. Then install the app: `./install.sh`

One command does the whole install (and later upgrades): Secrets, manifests with your image
and domain, GLPI's tables on the empty database, the ITSM configuration (setup-00..21), both
plugins, branding, and closing the default logins.

```bash
# the image must be in a registry every node can pull from
docker build -t ghcr.io/<you>/glpi-itsm:11.0 . && docker push ghcr.io/<you>/glpi-itsm:11.0

cp install.env.example install.env     # URL, database, SMTP, admin password, image
./install.sh -c install.env            # shows the target cluster + settings, asks, installs
```

It is safe to run again: an existing GLPI database is updated, not reinstalled, and every
configuration step is idempotent. `--skip-k8s` re-runs only the configuration.
Then check the install end to end:
`GLPI_URL=https://... ADMIN_PASS='...' NS=glpi customizations/itchat-dev/tests/run.sh smoke`

The manual way (what install.sh automates) stays below for reference.

## 3b. Manual: apply this app

```bash
# real Secret first (see base/secret-db-external.example.yaml)
kubectl create secret generic db-external --namespace glpi \
  --from-literal=host=<real-db-host> \
  --from-literal=port=3306 \
  --from-literal=database=glpi \
  --from-literal=user=<real-db-user> \
  --from-literal=password='<real-db-password>'

kubectl apply -k k8s/overlays/production
```

### Database setting required on MariaDB 11.6 or later

MariaDB 11.6+ turns `innodb_snapshot_isolation` **on** by default. With it on, GLPI
fails when two people submit the same Helpdesk form at the same moment: one of them gets
HTTP 500 and **no ticket is created** (MariaDB error 1020, "Record has changed since last
read", on `glpi_forms_forms`). The load test (`customizations/itchat-dev/tests/run.sh load`)
found this. Turn it off on the external database, in `my.cnf` / the server's config:

```ini
[mariadb]
innodb_snapshot_isolation = OFF
```

or live with `SET GLOBAL innodb_snapshot_isolation = OFF;` (it also needs the config line, or
it's back after a restart). MySQL and MariaDB ≤ 11.5 don't have this setting and need nothing.
`overlays/local-dev/mariadb-deployment.yaml` sets it with a container arg.
