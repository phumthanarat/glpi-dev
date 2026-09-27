<?php

/**
 * Adds sample Software + SoftwareVersion data, and installs some of it
 * on the computers created by seed-sample-data.php.
 *
 * Run inside the app container:
 *   php customizations/seed-software.php
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

echo "Creating software + versions...\n";
$software = new Software();
$version = new SoftwareVersion();
$installs = new Item_SoftwareVersion();

$catalog = [
    ['name' => '[Ex.] Microsoft Office', 'version' => '2021'],
    ['name' => '[Ex.] Google Chrome', 'version' => '128.0'],
    ['name' => '[Ex.] 7-Zip', 'version' => '23.01'],
    ['name' => '[Ex.] Adobe Acrobat Reader', 'version' => 'DC 2024'],
];

// Computers created by seed-sample-data.php (ids 1..3), install a couple
// of software versions on each so "Installations" tabs aren't empty either.
$computer_ids = [1, 2, 3];

foreach ($catalog as $entry) {
    $software_id = $software->add([
        'name'        => $entry['name'],
        'entities_id' => $entities_id,
    ]);
    echo "  - {$entry['name']} => software id $software_id\n";

    $version_id = $version->add([
        'name'          => $entry['version'],
        'softwares_id'  => $software_id,
        'entities_id'   => $entities_id,
    ]);
    echo "      version {$entry['version']} => id $version_id\n";

    foreach ($computer_ids as $computer_id) {
        // Install every other software on every other computer, just to
        // have some variety instead of everything-on-everything.
        if (($computer_id + $software_id) % 2 === 0) {
            continue;
        }
        $install_id = $installs->add([
            'itemtype'            => 'Computer',
            'items_id'            => $computer_id,
            'softwareversions_id' => $version_id,
            'entities_id'         => $entities_id,
        ]);
        echo "      installed on computer #$computer_id => id $install_id\n";
    }
}

echo "Done.\n";
