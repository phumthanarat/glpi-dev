<?php

/**
 * Creates a new custom Asset Definition ("IT Equipment"), for
 * miscellaneous IT-department gear that doesn't fit the standard
 * Computer/NetworkEquipment/Peripheral types, then seeds sample items
 * under it, scoped to the "[Ex.] แผนกคอมพิวเตอร์ (IT Department)" entity.
 *
 * Run inside the app container:
 *   php customizations/seed-it-equipment-assettype.php
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

// Reuse the IT Department entity created by seed-it-department.php.
global $DB;
$entities_id = $DB->request([
    'SELECT' => 'id',
    'FROM'   => Entity::getTable(),
    'WHERE'  => ['name' => '[Ex.] แผนกคอมพิวเตอร์ (IT Department)'],
])->current()['id'] ?? null;
if (!$entities_id) {
    fwrite(STDERR, "IT Department entity not found — run seed-it-department.php first.\n");
    exit(1);
}

$profile = new Profile();
$profile_ids = array_column($profile->find(), 'id');
$profiles_rights = array_fill_keys($profile_ids, ALLSTANDARDRIGHT);

$definition = new \Glpi\Asset\AssetDefinition();
$definition_id = $definition->add([
    'system_name'     => 'ITEquipment',
    'label'           => 'IT Equipment',
    'is_active'       => true,
    'comment'         => $note,
    'capacities'      => [
        ['name' => \Glpi\Asset\Capacity\HasHistoryCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasNotepadCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasDocumentsCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasContractsCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\IsInventoriableCapacity::class, 'config' => []],
    ],
    'profiles'        => $profiles_rights,
    'fields_display'  => [],
]);

if (!$definition_id) {
    global $DB;
    fwrite(STDERR, "Failed to create the 'IT Equipment' asset definition.\n");
    fwrite(STDERR, "DB error: " . $DB->error() . "\n");
    exit(1);
}
echo "AssetDefinition #$definition_id created (system_name=ITEquipment, label='IT Equipment')\n";

$classname = $definition->getAssetClassName();
echo "Custom asset class: $classname\n";

$items = [
    'IT Toolkit (screwdrivers, pliers)',
    'Network Cable Tester',
    'Crimping Tool Set',
    'KVM Switch 8-port',
    'External Backup Drive 4TB',
    'Label Printer',
    'Anti-static Wrist Strap',
    'Spare Parts Bin (RAM/SSD stock)',
    'Loaner Laptop Bag Set',
    'USB-to-Ethernet Adapter Kit',
];

foreach ($items as $name) {
    $asset = new $classname();
    $id = $asset->add([
        'name'        => "[Ex.] $name",
        'entities_id' => $entities_id,
        'comment'     => $note,
    ]);
    echo "$classname #$id: [Ex.] $name\n";
}
