<?php

/**
 * Closes GLPI's well-known default logins before the system is used for real.
 *
 * GLPI installs four accounts with public passwords: glpi/glpi (Super-Admin), tech/tech,
 * normal/normal, post-only/postonly.
 *   - glpi: password set from env GLPI_ADMIN_PASSWORD (required, 12+ characters)
 *   - tech, normal, post-only: deactivated (not deleted: GLPI refers to them in its own
 *     sample rights). KEEP_DEFAULT_ACCOUNTS=1 keeps them active, e.g. on a test install.
 *
 * Idempotent: the password is (re)set on every run, the accounts stay deactivated.
 * Run inside the app container:
 *   GLPI_ADMIN_PASSWORD='...' php customizations/setup-21-security.php
 */

$password = (string) getenv('GLPI_ADMIN_PASSWORD');
if (mb_strlen($password) < 12 || in_array($password, ['glpi', 'glpiglpiglpi', 'password1234'], true)) {
    fwrite(STDERR, "Set GLPI_ADMIN_PASSWORD to the new 'glpi' admin password (12+ characters).\n");
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

$admin = new User();
$admin->getFromDBbyName('glpi');
// GLPI's password policy (Setup > Authentication) applies; a refusal is reported, not ignored
$admin->update(['id' => $admin->getID(), 'password' => $password, 'password2' => $password]);
$check = new User();
$check->getFromDB($admin->getID());
if (!Auth::checkPassword($password, (string) $check->fields['password'])) {
    fwrite(STDERR, "Could not set the 'glpi' password (GLPI's password policy may refuse it):\n");
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'][ERROR] ?? [] as $m) {
        fwrite(STDERR, '  ' . strip_tags($m) . "\n");
    }
    exit(1);
}
echo "glpi: password set\n";

$keep = getenv('KEEP_DEFAULT_ACCOUNTS') === '1';
foreach (['tech', 'normal', 'post-only'] as $login) {
    $u = new User();
    if (!$u->getFromDBbyName($login)) {
        continue;
    }
    if ($keep) {
        echo "$login: kept active (KEEP_DEFAULT_ACCOUNTS=1)\n";
        continue;
    }
    $u->update(['id' => $u->getID(), 'is_active' => 0]);
    echo "$login: deactivated\n";
}
echo "Done.\n";
