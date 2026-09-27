<?php

/**
 * Step 2 of the ITSM rollout plan: Team structure (Groups).
 *
 * GLPI models a "team" as a Group; who-can-do-what is then a Profile
 * assigned to users (handled separately, not per-group). Groups here
 * keep GLPI's default flags (is_assign, is_requester, is_task, etc. all
 * on) so every one of them can receive ticket assignments, be picked as
 * a requester group, and log tasks.
 *
 * Run inside the app container:
 *   php customizations/setup-02-groups.php
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

$teams = [
    ['name' => 'Helpdesk / Service Desk', 'comment' => 'Receives tickets, triages, first-line resolution.'],
    ['name' => 'Network Team',            'comment' => 'Internet, Wi-Fi, switches, firewall.'],
    ['name' => 'System Team',             'comment' => 'Servers, Active Directory, backup, application infrastructure.'],
    ['name' => 'Application Team',        'comment' => 'ERP, HR, accounting, business applications.'],
    ['name' => 'IT Manager',              'comment' => 'Receives escalations, approves requests, reviews dashboards.'],
];

$group = new Group();
foreach ($teams as $t) {
    if ($group->getFromDBByCrit(['name' => $t['name']])) {
        echo "Group #{$group->getID()} already exists: {$t['name']}, skipping\n";
        continue;
    }
    $id = $group->add([
        'name'        => $t['name'],
        'comment'     => $t['comment'],
        'entities_id' => 0,
        'is_recursive' => 1,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create group '{$t['name']}': " . $DB->error() . "\n");
        continue;
    }
    echo "Group #$id created: {$t['name']}\n";
}

echo "Done.\n";
