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

## 3. Then apply this app

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
