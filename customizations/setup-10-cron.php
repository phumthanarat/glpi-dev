<?php

/**
 * Companion to k8s/base/cronjob.yaml: switches every GLPI CronTask
 * still in its default MODE_INTERNAL (1) to MODE_EXTERNAL (2).
 *
 * GLPI ships all automatic actions (slaticket/olaticket — the SLA
 * escalation levels from setup-06/07, queuednotification — the thing
 * that actually sends the emails, closeticket, alertnotclosed, ...) in
 * MODE_INTERNAL by default, which only fires probabilistically on
 * normal HTTP page loads (`CronTask::launch(CronTask::MODE_INTERNAL)`,
 * triggered from inside a web request). `php front/cron.php` run from
 * a crontab/CronJob (there's no `bin/console` cron command in GLPI 11)
 * only ever launches MODE_EXTERNAL tasks
 * (`CronTask::launch(CronTask::MODE_EXTERNAL, ...)` in front/cron.php)
 * — so with a real CronJob now running every 2 minutes
 * (k8s/base/cronjob.yaml), every task needs to be MODE_EXTERNAL or the
 * CronJob simply never touches it, silently.
 *
 * This was found the hard way: the CronJob ran successfully (exit 0)
 * but glpi_crontasks.lastrun for slaticket/olaticket never advanced
 * until this switch.
 *
 * Run inside the app container:
 *   php customizations/setup-10-cron.php
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

global $DB;

$rows = iterator_to_array($DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => 'glpi_crontasks',
    'WHERE'  => ['mode' => CronTask::MODE_INTERNAL],
]));

$task = new CronTask();
$updated = 0;
foreach ($rows as $row) {
    $ok = $task->update(['id' => $row['id'], 'mode' => CronTask::MODE_EXTERNAL]);
    echo "{$row['name']}: " . ($ok ? 'switched to EXTERNAL' : 'FAILED') . "\n";
    if ($ok) {
        $updated++;
    }
}

echo "Updated $updated of " . count($rows) . " tasks.\n";
echo "Done.\n";
