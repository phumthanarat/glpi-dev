<?php

/**
 * One-off script to seed a small amount of sample data (computers,
 * monitors, tickets) into an otherwise-empty GLPI install, so the
 * asset/ticket menus have something to show instead of "No results found".
 *
 * Run inside the app container:
 *   php customizations/seed-sample-data.php
 *
 * Safe to run once; re-running will create duplicate entries (no
 * idempotency check), so remove/adjust if run more than once.
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

$entities_id = 0;

echo "Creating computers...\n";
$computer = new Computer();
$computer_ids = [];
foreach ([
    ['name' => '[Ex.] DESKTOP-FIN-001', 'serial' => 'SN-FIN-0001', 'comment' => 'Finance department workstation'],
    ['name' => '[Ex.] LAPTOP-SALES-014', 'serial' => 'SN-SAL-0014', 'comment' => 'Sales laptop'],
    ['name' => '[Ex.] SRV-APP-01', 'serial' => 'SN-SRV-0001', 'comment' => 'Application server'],
] as $data) {
    $id = $computer->add([
        'name'        => $data['name'],
        'serial'      => $data['serial'],
        'comment'     => $data['comment'],
        'entities_id' => $entities_id,
    ]);
    echo "  - {$data['name']} => id $id\n";
    $computer_ids[] = $id;
}

echo "Creating monitors...\n";
$monitor = new Monitor();
foreach ([
    ['name' => '[Ex.] MON-FIN-001', 'serial' => 'SN-MON-0001'],
    ['name' => '[Ex.] MON-SALES-014', 'serial' => 'SN-MON-0014'],
] as $data) {
    $id = $monitor->add([
        'name'        => $data['name'],
        'serial'      => $data['serial'],
        'entities_id' => $entities_id,
    ]);
    echo "  - {$data['name']} => id $id\n";
}

echo "Creating tickets...\n";
$ticket = new Ticket();
foreach ([
    [
        'name'    => '[Ex.] Cannot connect to VPN',
        'content' => 'User reports being unable to connect to the company VPN since this morning.',
        'urgency' => 3,
        'impact'  => 3,
    ],
    [
        'name'    => '[Ex.] Request: new monitor for workstation',
        'content' => 'Sales team member requests a second monitor for their workstation.',
        'urgency' => 2,
        'impact'  => 2,
    ],
    [
        'name'    => '[Ex.] Printer on 2nd floor out of toner',
        'content' => 'The shared printer on the 2nd floor is out of toner and needs a replacement cartridge.',
        'urgency' => 2,
        'impact'  => 1,
    ],
] as $data) {
    $id = $ticket->add([
        'name'                  => $data['name'],
        'content'               => $data['content'],
        'entities_id'           => $entities_id,
        'urgency'               => $data['urgency'],
        'impact'                => $data['impact'],
        '_users_id_requester'   => 2, // default 'glpi' super-admin user id
        '_skip_auto_assign'     => true,
    ]);
    echo "  - {$data['name']} => id $id\n";
}

echo "Done.\n";
