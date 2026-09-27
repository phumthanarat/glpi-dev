<?php

/**
 * Example custom Asset Definition ("Notebook (Example)"), showing the
 * minimal recipe to add a new item type under the "Assets" menu with no
 * core code changes - see seed-labs-test-assettype.php for the same
 * pattern with fewer comments.
 *
 * Run inside the app container:
 *   php customizations/seed-notebook-ex-assettype.php
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

// Grant full standard rights to every existing profile so the new type
// is immediately visible/usable regardless of which profile is active.
$profile = new Profile();
$profile_ids = array_column($profile->find(), 'id');
$profiles_rights = array_fill_keys($profile_ids, ALLSTANDARDRIGHT);

$definition = new \Glpi\Asset\AssetDefinition();
$definition_id = $definition->add([
    'system_name'     => 'NotebookEx',
    'label'           => 'Notebook (Example)',
    'is_active'       => true,
    'comment'         => $note,
    'capacities'      => [
        ['name' => \Glpi\Asset\Capacity\HasHistoryCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasNotepadCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasDocumentsCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasInfocomCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasContractsCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasSoftwaresCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\IsInventoriableCapacity::class, 'config' => []],
    ],
    'profiles'        => $profiles_rights,
    'fields_display'  => [],
]);

if (!$definition_id) {
    global $DB;
    fwrite(STDERR, "Failed to create the 'Notebook (Example)' asset definition.\n");
    fwrite(STDERR, "DB error: " . $DB->error() . "\n");
    foreach (Session::getNewMessages() as $msgs) {
        foreach ((array) $msgs as $m) {
            fwrite(STDERR, "GLPI message: $m\n");
        }
    }
    exit(1);
}
echo "AssetDefinition #$definition_id created (system_name=NotebookEx, label='Notebook (Example)')\n";

// The concrete class is dynamically generated from the definition's
// system_name.
$classname = $definition->getAssetClassName();
echo "Custom asset class: $classname\n";

foreach ([
    'Dell Latitude 5440 - Sales',
    'Lenovo ThinkPad T14 - Engineering',
    'MacBook Pro 14" - Design Team',
] as $name) {
    $asset = new $classname();
    $id = $asset->add([
        'name'        => "[Ex.] $name",
        'entities_id' => 0,
        'comment'     => $note,
    ]);
    echo "$classname #$id: [Ex.] $name\n";
}
