<?php

/**
 * Keeps what a test run left behind (KEEP_DATA=1, e.g. the 2,000-ticket load test) as permanent
 * demo data, out of reach of the test suite: tests/run.sh wipes everything named itchat.test.* /
 * "[itchat-test]..." at the start of every run.
 *
 *   users   itchat.test.user1/user2/tech/tech2 -> demo.user1/... (next kept run: demo2.*, demo3.*),
 *           e-mails @dev.glpi.labs follow
 *   group   "[itchat-test] Group"               -> "[demo] Group"
 *   tickets "[itchat-test] ..." / "แชท: [itchat-test] ..." -> "[demo] ..." / "แชท: [demo] ..."
 *   assets  printers / computers / locations "[itchat-test] ..." -> "[demo] ..."
 *
 * Direct SQL for the ticket titles: going through Ticket::update() would queue a notification
 * e-mail per ticket. Passwords are left as they are (random from the test run: nobody can log in
 * as these accounts). Remove it all later by searching for "[demo]".
 *
 * Run inside the app container, after the test run finished:
 *   php keep-test-data-as-demo.php
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

global $DB;

$from = '[itchat-test]';
// first free batch name: demo, demo2, demo3, ... (each kept test run gets its own users/group,
// so nothing has to be merged; every batch's tickets are titled "[demo...]")
$n = 1;
while ((new User())->getFromDBbyName('demo' . ($n > 1 ? $n : '') . '.user1')) {
    $n++;
}
$batch = 'demo' . ($n > 1 ? $n : '');
$to    = "[$batch]";
$done  = ['batch' => $batch];

foreach (['user1', 'user2', 'tech', 'tech2'] as $who) {
    $u = new User();
    if (!$u->getFromDBbyName("itchat.test.$who")) {
        continue;
    }
    $DB->update('glpi_users', ['name' => "$batch.$who", 'firstname' => "$batch.$who", 'realname' => 'Demo'], ['id' => $u->getID()]);
    $DB->update('glpi_useremails', ['email' => "$batch.$who@dev.glpi.labs"], ['users_id' => $u->getID()]);
    $done['users'][] = "$batch.$who";
}

$rename = static function (string $table, string $pattern) use ($DB, $from, $to): int {
    $n = 0;
    foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => $table, 'WHERE' => ['name' => ['LIKE', $pattern]]]) as $r) {
        $DB->update($table, ['name' => str_replace($from, $to, $r['name'])], ['id' => $r['id']]);
        $n++;
    }
    return $n;
};
$done['group']     = $rename('glpi_groups', '[itchat-test] Group');
// anywhere in the title: QR tickets are "แจ้งปัญหา: [itchat-test] <device>", chat ones "แชท: [itchat-test] ..."
$done['tickets']   = $rename('glpi_tickets', '%[itchat-test]%');
$done['printers']  = $rename('glpi_printers', '[itchat-test]%');
$done['computers'] = $rename('glpi_computers', '[itchat-test]%');
$done['locations'] = $rename('glpi_locations', '[itchat-test]%');
// locations keep a denormalised complete name
foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_locations', 'WHERE' => ['completename' => ['LIKE', '%[itchat-test]%']]]) as $r) {
    $DB->update('glpi_locations', ['completename' => str_replace($from, $to, $r['completename'])], ['id' => $r['id']]);
}

echo json_encode($done, JSON_UNESCAPED_UNICODE), "\n";
