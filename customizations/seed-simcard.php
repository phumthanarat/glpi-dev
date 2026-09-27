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

$auth = new Auth();
if (!$auth->login('glpi', 'glpi', false)) {
    fwrite(STDERR, "Login as 'glpi' failed.\n");
    exit(1);
}
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
