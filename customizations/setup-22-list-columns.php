<?php

/**
 * Default columns of the Ticket / Problem / Change lists (Assistance > ...), for everyone who
 * hasn't picked their own (Search > "Select default items to show", per user).
 *
 * GLPI's defaults leave out what the service desk works with; these add ID, entity, the SLA
 * progress (time to resolve / to own, and whether it's exceeded), requester, technician and
 * category - the columns that were set up by hand on the first install.
 *
 * Idempotent: replaces the global (users_id = 0) Central list of these three itemtypes.
 * Run inside the app container:
 *   php customizations/setup-22-list-columns.php
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

global $DB;

// search option numbers, in display order
$columns = [
    // ID, Title, Entity, Status, Time to resolve + progress, Time to own + progress, Last update,
    // Opening date, Priority, Requester, Technician, Category, Time to resolve exceeded
    Ticket::class  => [2, 1, 80, 12, 151, 158, 19, 15, 3, 4, 5, 7, 82],
    // ID, Title, Entity, Status, Time to resolve, Last update, Opening date, Priority, Category
    Problem::class => [2, 1, 80, 12, 151, 19, 15, 3, 7],
    Change::class  => [2, 1, 80, 12, 151, 19, 15, 3, 7],
];

foreach ($columns as $itemtype => $nums) {
    $options = (new $itemtype())->searchOptions();
    foreach ($nums as $num) {
        if (!isset($options[$num])) {
            fwrite(STDERR, "$itemtype: no search option $num in this GLPI version\n");
            exit(1);
        }
    }
    $DB->delete(DisplayPreference::getTable(), ['itemtype' => $itemtype, 'users_id' => 0, 'interface' => 'central']);
    foreach (array_values($nums) as $rank => $num) {
        $DB->insert(DisplayPreference::getTable(), [
            'itemtype'  => $itemtype,
            'num'       => $num,
            'rank'      => $rank + 1,
            'users_id'  => 0,
            'interface' => 'central',
        ]);
    }
    echo "$itemtype list: " . count($nums) . " columns\n";
}
echo "Done. Users who customised their own columns keep them.\n";
