<?php

/**
 * Adds the IT Chat cards (provided by the itchat plugin's DASHBOARD_CARDS hook) as a new
 * bottom row of the "IT Helpdesk KPI" dashboard from setup-09-dashboard.php:
 *   - average chat satisfaction, last 30 days
 *   - chats started today
 *   - chats waiting for a technician
 * Existing cards are kept as they are (unlike setup-09, which rewrites the whole
 * dashboard); cards already present are skipped, so re-running is harmless.
 *
 * Requires the itchat plugin (>= 1.6.0) to be active.
 *
 * Run inside the app container:
 *   php customizations/setup-16-itchat-dashboard.php
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

if (!Plugin::isPluginActive('itchat')) {
    fwrite(STDERR, "itchat plugin is not active.\n");
    exit(1);
}

$dashboard = new Glpi\Dashboard\Dashboard();
if (!$dashboard->getFromDB('it-helpdesk-kpi')) {
    fwrite(STDERR, "Dashboard 'it-helpdesk-kpi' not found - run setup-09-dashboard.php first.\n");
    exit(1);
}

$items    = Glpi\Dashboard\Item::getForDashboard((int) $dashboard->fields['id']);
$existing = array_column($items, 'card_id');
$bottom   = array_reduce($items, static fn($max, $i) => max($max, $i['y'] + $i['height']), 0);

$new = [
    ['itchat_csat_30d',    '#ffd166'],
    ['itchat_chats_today', '#8ecae6'],
    ['itchat_waiting',     '#f4a261'],
];
$added = 0;
foreach ($new as $i => [$card_id, $color]) {
    if (in_array($card_id, $existing, true)) {
        echo "$card_id already on the dashboard, skipping\n";
        continue;
    }
    $items[] = [
        'x' => $i * 4, 'y' => $bottom, 'width' => 4, 'height' => 2,
        'gridstack_id' => $card_id . '_' . bin2hex(random_bytes(8)),
        'card_id'      => $card_id,
        'card_options' => ['color' => $color, 'widgettype' => 'bigNumber', 'use_gradient' => '0', 'limit' => '7'],
    ];
    $added++;
    echo "+ $card_id\n";
}

if ($added > 0) {
    $dashboard->saveItems($items);
}
echo "Done: $added card(s) added to 'IT Helpdesk KPI' (Assistance > Dashboard).\n";
