# IT HTTPS — the site certificate, managed from GLPI

**Setup > HTTPS** (Super-Admin / "config" update right): the certificate the Ingress serves,
how long it's valid, the names it covers, and two ways to set it:

1. **Upload the organisation's certificate**: `.crt`/`.pem` (+ intermediates) and an
   unencrypted `.key`, or one `.pfx`/`.p12` with its password. Checked before use: the key
   matches, dates are valid, the site names are covered. The private key is never shown.
2. **Internal certificate**: issued by an internal CA the plugin creates once (10 years,
   Secret `glpi-tls-ca`); server certificates last 397 days and are **renewed automatically
   30 days before expiry** (GLPI automatic action `ithttpsRenew`, run by glpi-cron). Download
   the CA (`itsm-internal-ca.crt`) and push it with an AD Group Policy (Computer Configuration >
   Policies > Windows Settings > Security Settings > Public Key Policies > Trusted Root
   Certification Authorities) so browsers trust it.

On a fresh install, `setup-23-https.php` issues an internal certificate for the Ingress host, so
the site is HTTPS from the start; an existing certificate is never replaced by install.sh.

## How it's wired
- the Ingress (Kong) terminates HTTPS with Secret `glpi-tls` and redirects HTTP to HTTPS (301)
- the app pods / glpi-cron run as ServiceAccount `glpi-app`, allowed only `get`/`update`/`patch`
  on `glpi-tls` and `glpi-tls-ca` (k8s/base/https-rbac.yaml); install.sh creates both empty
- Apache trusts `X-Forwarded-Proto: https` from the Ingress, so GLPI knows the request was secure
  and the session cookie gets the `Secure` flag (plain HTTP via the dev NodePort still works)
- Kong picks up a changed Secret by itself, usually within a minute

Let's Encrypt (public DNS name reachable from the internet) is not built in: it needs
cert-manager on the cluster; ask if you want it.

## Test
`tests/run.sh https` (31 checks, through the real Ingress on :443): internal certificate
verifies against the downloaded CA; HTTP -> HTTPS; Secure cookie; upload .crt+.key and .pfx
really change what Kong serves; wrong key / expired / wrong name / wrong .pfx password refused;
renewal job; the pods can't read any other Secret.
