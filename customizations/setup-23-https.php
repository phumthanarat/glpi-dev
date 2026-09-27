<?php

/**
 * HTTPS on first install: when the Ingress has no certificate yet (empty Secret glpi-tls),
 * issue one from the internal CA for TLS_HOSTS (install.sh passes the Ingress host), so the
 * site is HTTPS from the start. An uploaded or existing certificate is never replaced here;
 * later changes are made on Setup > HTTPS (ithttps plugin, installed before this runs).
 *
 *   TLS_HOSTS=itsm.company.com php customizations/setup-23-https.php
 */

$hosts = array_values(array_filter(array_map('trim', explode(',', (string) getenv('TLS_HOSTS')))));
if ($hosts === []) {
    fwrite(STDERR, "Set TLS_HOSTS (comma separated host names of the site)\n");
    exit(1);
}

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Super-Admin session for this CLI run, without a password (see setup-01 for why).
$auth = new Auth();
$auth->user = new User();
if (PHP_SAPI !== 'cli' || !$auth->user->getFromDBbyName('glpi')) {
    fwrite(STDERR, "Run from the command line in the GLPI container ('glpi' user needed).\n");
    exit(1);
}
$auth->auth_succeded = true;
$auth->user_present  = true;
Session::init($auth);

use GlpiPlugin\Ithttps\Kube;
use GlpiPlugin\Ithttps\Tls;

if (!Kube::available()) {
    echo "Not on Kubernetes: nothing to do (HTTPS is the reverse proxy's job)\n";
    exit(0);
}
$cur = Tls::current();
if ($cur !== null) {
    echo "Certificate already installed (" . implode(', ', $cur['names']) . ", {$cur['days_left']} days left): unchanged\n";
    if (Tls::config()['hosts'] === []) {
        Tls::setConfig(['hosts' => $hosts]);
    }
    exit(0);
}
$info = Tls::issueInternal($hosts);
echo 'Internal certificate issued for ' . implode(', ', $info['names']) . ' until ' . $info['to'] . "\n";
