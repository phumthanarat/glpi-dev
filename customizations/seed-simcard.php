<?php

/**
 * Adds a sample SIM card component (DeviceSimcard catalog entry) and
 * attaches an instance of it (Item_DeviceSimcard) to the sample Phone,
 * linked to the sample Line, so the "SIM cards" listing isn't empty.
 *
 * Run inside the app container:
 *   php customizations/seed-simcard.php
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
$note = 'Sample/test data seeded for demo purposes - safe to delete.';

$device = new DeviceSimcard();
$device_id = $device->add([
    'designation' => '[Ex.] SIM Card - Nano',
    'entities_id' => $entities_id,
    'comment'     => $note,
]);
echo "DeviceSimcard #$device_id\n";

$item = new Item_DeviceSimcard();
$item_id = $item->add([
    'itemtype'           => 'Phone',
    'items_id'           => 1, // '[Ex.] Reception phone', created by seed-all.php
    'devicesimcards_id'  => $device_id,
    'lines_id'           => 1, // '[Ex.] +1-555-0100', created by seed-all.php
    'serial'             => '8991-0000-0000-0001',
    'msin'               => '0000000001',
    'entities_id'        => $entities_id,
    'comment'            => $note,
]);
echo "Item_DeviceSimcard #$item_id\n";
