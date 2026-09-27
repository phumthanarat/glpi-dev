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
 * Run inside the app container:
 *   php customizations/setup-11-mail.php
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$auth = new Auth();
if (!$auth->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Login as 'glpi' failed.\n");
    exit(1);
}
Session::init($auth);

Config::setConfigurationValues('core', [
    'use_notifications'      => 1,
    'notifications_mailing'  => 1,
    'smtp_mode'              => MAIL_SMTP,
    'smtp_host'              => 'mailpit:1025',
    'smtp_username'          => '',
    'smtp_check_certificate' => 0,
    'from_email'             => 'itsm@dev.glpi.labs',
    'from_email_name'        => 'IT-DEV ITSM',
]);
echo "Notification config set: use_notifications=1, notifications_mailing=1, smtp_host=mailpit:1025\n";

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
