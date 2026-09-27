<?php

/**
 * Login security on install (changed later on Setup > Security, itsecurity plugin):
 *   IDLE_TIMEOUT_MINUTES  idle timeout of web sessions (default 60, 0 = off)
 *   TFA_PROFILES          profiles that must use 2FA (default: the central ones
 *                         Super-Admin, Admin, Supervisor, Technician, Hotliner)
 *   TFA_GRACE_DAYS        days people may still skip enrolling (default 7)
 * The grace period starts the first time this runs; re-runs don't restart it.
 *
 *   php customizations/setup-24-login-security.php
 */

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

global $CFG_GLPI, $DB;

$env = static fn(string $k, string $d) => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
$minutes = max(0, (int) $env('IDLE_TIMEOUT_MINUTES', '60'));
$profiles = array_values(array_filter(array_map('trim', explode(',', $env('TFA_PROFILES', 'Super-Admin,Admin,Supervisor,Technician,Hotliner')))));
$grace = max(0, (int) $env('TFA_GRACE_DAYS', '7'));

Config::setConfigurationValues('plugin:itsecurity', ['idle_minutes' => $minutes]);
echo "Idle timeout: " . ($minutes > 0 ? "$minutes min" : 'off') . "\n";

foreach ($DB->request(['SELECT' => ['id', 'name', '2fa_enforced'], 'FROM' => 'glpi_profiles']) as $p) {
    $want = in_array($p['name'], $profiles, true) ? 1 : 0;
    if ((int) $p['2fa_enforced'] !== $want) {
        (new Profile())->update(['id' => $p['id'], '2fa_enforced' => $want]);
    }
}
echo "2FA enforced for: " . implode(', ', $profiles) . "\n";

$values = ['2fa_grace_days' => $grace];
if ($grace > 0 && empty($CFG_GLPI['2fa_grace_date_start'])) {
    $values['2fa_grace_date_start'] = date('Y-m-d H:i:s');
}
Config::setConfigurationValues('core', $values);
echo "2FA grace period: $grace days" . (isset($values['2fa_grace_date_start']) ? ' (starts now)' : ' (already running)') . "\n";
