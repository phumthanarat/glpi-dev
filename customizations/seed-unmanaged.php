<?php

/**
 * Adds sample "Unmanaged" assets (normally populated by network
 * discovery/inventory, but addable directly like any other asset).
 *
 * Run inside the app container:
 *   php customizations/seed-unmanaged.php
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

$entities_id = 0;
$note = 'Sample/test data seeded for demo purposes - safe to delete.';

$unmanaged = new Unmanaged();

foreach ([
    ['name' => '[Ex.] Unknown device 192.168.1.50', 'ip' => '192.168.1.50'],
    ['name' => '[Ex.] Unknown device 192.168.1.51', 'ip' => '192.168.1.51'],
] as $data) {
    $id = $unmanaged->add([
        'name'        => $data['name'],
        'ip'          => $data['ip'],
        'entities_id' => $entities_id,
        'comment'     => $note,
    ]);
    echo "Unmanaged #$id: {$data['name']}\n";
}
