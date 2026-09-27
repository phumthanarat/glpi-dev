<?php

/**
 * GLPI's public address ("URL of the application", Setup > General), from env GLPI_URL,
 * e.g. https://itsm.company.com. Links in notification e-mails, webhook payloads
 * (setup-12..14 bake it in when they create them, hence step 00) and QR labels (itqr) use it.
 *
 * Run inside the app container:
 *   GLPI_URL=https://itsm.company.com php customizations/setup-00-base-url.php
 */

$url = rtrim((string) getenv('GLPI_URL'), '/');
if (!preg_match('#^https?://[^/\s]+(/\S*)?$#', $url)) {
    fwrite(STDERR, "Set GLPI_URL to the address users open GLPI with, e.g. https://itsm.company.com\n");
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

Config::setConfigurationValues('core', ['url_base' => $url]);
echo "url_base = $url\n";
