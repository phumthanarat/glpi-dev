<?php

/**
 * Adds 4 more custom Asset Definitions, rounding out a realistic IT
 * department's equipment categories beyond what GLPI's stock types and
 * the first 3 custom types (IT Equipment, Mobile Devices, Backup Media,
 * IoT Devices) already cover:
 *
 *   - Security Equipment : access control / surveillance / auth hardware
 *   - AV & Conference     : meeting-room / presentation hardware
 *   - Power Equipment     : UPS / surge protection / backup power
 *   - Spare Parts         : individual stocked components (not bulk bins)
 *
 * Run inside the app container:
 *   php customizations/seed-more-assettypes-2.php
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

function seedItems(?object $def, array $names, int $entities_id, string $note): void
{
    if (!$def) {
        return;
    }
    $classname = $def->getAssetClassName();
    foreach ($names as $name) {
        $asset = new $classname();
        $id = $asset->add(['name' => "[Ex.] $name", 'entities_id' => $entities_id, 'comment' => $note]);
        echo "$classname #$id: [Ex.] $name\n";
    }
}

// ------------------------------------------------------------
// Security Equipment: access control, surveillance, auth hardware
// ------------------------------------------------------------
seedItems(
    createAssetType('SecurityEquipment', 'Security Equipment', $default_capacities, $profiles_rights, $note),
    [
        'CCTV Camera - Server Room Entrance',
        'CCTV Camera - Main Office Door',
        'Biometric Fingerprint Scanner',
        'Badge Access Reader - Server Room',
        'YubiKey Security Token #1',
        'YubiKey Security Token #2',
    ],
    $entities_id,
    $note
);

// ------------------------------------------------------------
// AV & Conference Equipment
// ------------------------------------------------------------
seedItems(
    createAssetType('AVEquipment', 'AV & Conference Equipment', $default_capacities, $profiles_rights, $note),
    [
        'Meeting Room Projector',
        'Video Conferencing Camera - Boardroom',
        'Conference Room Speakerphone',
        'Wireless Presenter Clicker',
    ],
    $entities_id,
    $note
);

// ------------------------------------------------------------
// Power Equipment: UPS / surge protection / backup power
// ------------------------------------------------------------
seedItems(
    createAssetType('PowerEquipment', 'Power Equipment', $default_capacities, $profiles_rights, $note),
    [
        'UPS - Server Rack A1',
        'UPS - Network Closet',
        'Surge Protector - Workstation Row',
        'Backup Generator (building-wide)',
    ],
    $entities_id,
    $note
);

// ------------------------------------------------------------
// Spare Parts: individually tracked components
// (distinct from the generic "Spare Parts Bin" bulk item under
// IT Equipment — these are trackable, higher-value individual units)
// ------------------------------------------------------------
seedItems(
    createAssetType('SparePart', 'Spare Parts', $default_capacities, $profiles_rights, $note),
    [
        'Spare RAM Module 16GB DDR4',
        'Spare SSD 1TB NVMe',
        'Spare Power Supply Unit 650W',
        'Spare Network Card (NIC)',
        'Spare Laptop Battery',
    ],
    $entities_id,
    $note
);

echo "Done.\n";
