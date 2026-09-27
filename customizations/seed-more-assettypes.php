<?php

/**
 * Adds three more custom Asset Definitions under the "Assets" menu,
 * rounding out the IT department scenario: Mobile Devices, Backup
 * Media, and IoT Devices — none of which map cleanly to GLPI's stock
 * asset types.
 *
 * Run inside the app container:
 *   php customizations/seed-more-assettypes.php
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

$note = 'Sample/test data seeded for demo purposes - safe to delete.';

global $DB;
$entities_id = $DB->request([
    'SELECT' => 'id',
    'FROM'   => Entity::getTable(),
    'WHERE'  => ['name' => '[Ex.] แผนกคอมพิวเตอร์ (IT Department)'],
])->current()['id'] ?? 0;

$profile = new Profile();
$profile_ids = array_column($profile->find(), 'id');
$profiles_rights = array_fill_keys($profile_ids, ALLSTANDARDRIGHT);

$default_capacities = [
    ['name' => \Glpi\Asset\Capacity\HasHistoryCapacity::class, 'config' => []],
    ['name' => \Glpi\Asset\Capacity\HasNotepadCapacity::class, 'config' => []],
    ['name' => \Glpi\Asset\Capacity\HasDocumentsCapacity::class, 'config' => []],
    ['name' => \Glpi\Asset\Capacity\HasInfocomCapacity::class, 'config' => []],
];

function createAssetType(string $system_name, string $label, array $capacities, array $profiles_rights, string $note): ?object
{
    $definition = new \Glpi\Asset\AssetDefinition();
    $id = $definition->add([
        'system_name'     => $system_name,
        'label'           => $label,
        'is_active'       => true,
        'comment'         => $note,
        'capacities'      => $capacities,
        'profiles'        => $profiles_rights,
        'fields_display'  => [],
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create '$label': " . $DB->error() . "\n");
        return null;
    }
    echo "AssetDefinition #$id created (system_name=$system_name, label='$label')\n";
    return $definition;
}

// ------------------------------------------------------------
// Mobile Devices
// ------------------------------------------------------------
$def = createAssetType('MobileDevice', 'Mobile Devices', $default_capacities, $profiles_rights, $note);
if ($def) {
    $classname = $def->getAssetClassName();
    foreach ([
        'iPhone 14 - CEO',
        'iPhone 13 - Sales Manager',
        'Samsung Galaxy Tab - Warehouse scanner',
        'iPad - Reception kiosk',
    ] as $name) {
        $asset = new $classname();
        $id = $asset->add(['name' => "[Ex.] $name", 'entities_id' => $entities_id, 'comment' => $note]);
        echo "$classname #$id: [Ex.] $name\n";
    }
}

// ------------------------------------------------------------
// Backup Media
// ------------------------------------------------------------
$def = createAssetType('BackupMedia', 'Backup Media', $default_capacities, $profiles_rights, $note);
if ($def) {
    $classname = $def->getAssetClassName();
    foreach ([
        'LTO-9 Tape Set A (offsite rotation)',
        'LTO-9 Tape Set B (onsite rotation)',
        'External Backup Drive - Weekly Full',
        'NAS Backup Volume - Daily Incremental',
    ] as $name) {
        $asset = new $classname();
        $id = $asset->add(['name' => "[Ex.] $name", 'entities_id' => $entities_id, 'comment' => $note]);
        echo "$classname #$id: [Ex.] $name\n";
    }
}

// ------------------------------------------------------------
// IoT Devices
// ------------------------------------------------------------
$def = createAssetType('IoTDevice', 'IoT Devices', $default_capacities, $profiles_rights, $note);
if ($def) {
    $classname = $def->getAssetClassName();
    foreach ([
        'Server Room Temperature Sensor',
        'Server Room Humidity Sensor',
        'Smart PDU Power Monitor',
        'Door Access Sensor - Server Room',
    ] as $name) {
        $asset = new $classname();
        $id = $asset->add(['name' => "[Ex.] $name", 'entities_id' => $entities_id, 'comment' => $note]);
        echo "$classname #$id: [Ex.] $name\n";
    }
}

echo "Done.\n";
