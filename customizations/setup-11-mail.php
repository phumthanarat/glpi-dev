<?php

/**
 * Wires up actual email delivery for dev/testing, using mailpit
 * (k8s/overlays/local-dev/mailpit-*.yaml — same image the repo's own
 * docker-compose.yaml dev stack already uses) as a fake SMTP server
 * that captures everything instead of sending it anywhere real.
 *
 * Fixes two things found by testing end-to-end, not just guessed at:
 *
 *   1. `use_notifications` was 0 — the master switch for GLPI's whole
 *      notification system, separate from `notifications_mailing`
 *      (which only picks the delivery method). With it off, nothing
 *      gets queued at all regardless of any NotificationTemplate/event
 *      config — every notification built in setup-06/07/08 was a
 *      no-op until this was flipped on.
 *   2. No user in this install had an email address (glpi_useremails
 *      was completely empty) — so even with notifications enabled,
 *      GLPI had nowhere to send them. Added a dev.glpi.labs address
 *      for every existing user/test account.
 *
 * Verified: created a ticket, confirmed 2 rows in
 * glpi_queuednotifications, ran the cron (k8s/base/cronjob.yaml),
 * and confirmed via mailpit's API (http://mailpit:8025/api/v1/messages
 * in-cluster, http://localhost:30825/ from the host) that both emails
 * actually arrived.
 *
 * Production: pass the real relay in env (install.sh does):
 *   SMTP_HOST, SMTP_PORT (587 = STARTTLS, 465 = TLS, 25), SMTP_USER, SMTP_PASSWORD,
 *   MAIL_FROM, MAIL_FROM_NAME, SMTP_VERIFY_CERT (1 default, 0 for self-signed)
 * Without SMTP_HOST it keeps the dev setup: mailpit, and @dev.glpi.labs addresses for the
 * built-in accounts (only then - real users get their address from LDAP / their profile).
 *
 * Run inside the app container:
 *   php customizations/setup-11-mail.php
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Super-Admin session for this CLI run, without a password: these scripts only run inside the
// app container (which has the database credentials anyway), and must keep working after the
// admin password is changed (setup-21).
$auth = new Auth();
$auth->user = new User();
if (PHP_SAPI !== 'cli' || !$auth->user->getFromDBbyName('glpi')) {
    fwrite(STDERR, "Run from the command line in the GLPI container ('glpi' user needed).\n");
    exit(1);
}
$auth->auth_succeded = true;
$auth->user_present  = true;
Session::init($auth);

$env = static fn(string $k, string $default = '') => ($v = getenv($k)) !== false && $v !== '' ? $v : $default;
$dev = $env('SMTP_HOST') === '';

$smtp = [
    'use_notifications'      => 1,
    'notifications_mailing'  => 1,
    'smtp_mode'              => MAIL_SMTP,
    'smtp_host'              => $dev ? 'mailpit' : $env('SMTP_HOST'),
    'smtp_port'              => (int) ($dev ? 1025 : $env('SMTP_PORT', '587')),
    'smtp_username'          => $env('SMTP_USER'),
    'smtp_check_certificate' => $dev ? 0 : (int) $env('SMTP_VERIFY_CERT', '1'),
    'from_email'             => $env('MAIL_FROM', 'itsm@dev.glpi.labs'),
    'from_email_name'        => $env('MAIL_FROM_NAME', 'IT-DEV ITSM'),
];
if ($env('SMTP_PASSWORD') !== '') {
    $smtp['smtp_passwd'] = $env('SMTP_PASSWORD'); // stored encrypted (GLPI key)
}
Config::setConfigurationValues('core', $smtp);
echo "Notification config set: smtp {$smtp['smtp_host']}:{$smtp['smtp_port']}, from {$smtp['from_email']}\n";

if (!$dev) {
    echo "Done (production relay; built-in accounts' addresses left alone).\n";
    exit(0);
}

global $DB;

$emails = [
    'glpi'         => 'glpi@dev.glpi.labs',
    'post-only'    => 'post-only@dev.glpi.labs',
    'tech'         => 'tech@dev.glpi.labs',
    'normal'       => 'normal@dev.glpi.labs',
    'test.somchai' => 'somchai@dev.glpi.labs',
    'test.suda'    => 'suda@dev.glpi.labs',
];

$useremail = new UserEmail();
foreach ($emails as $login => $email) {
    $u = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login]])->current();
    if (!$u) {
        echo "$login: user not found, skipping\n";
        continue;
    }
    if ($useremail->getFromDBByCrit(['users_id' => $u['id'], 'email' => $email])) {
        echo "$login: email already set, skipping\n";
        continue;
    }
    $id = $useremail->add([
        'users_id'   => $u['id'],
        'email'      => $email,
        'is_default' => 1,
    ]);
    echo "$login: " . ($id ? "email set to $email" : 'FAILED') . "\n";
}

echo "Done.\n";
