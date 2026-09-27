<?php

/**
 * Creates a new custom Asset Definition ("Labs test"), which GLPI 11's
 * Asset Definition Manager turns into a brand-new menu entry under
 * "Assets" automatically (Setup > Assets feature) - no core code
 * changes needed. Then seeds one sample record of that new type.
 *
 * Run inside the app container:
 *   php customizations/seed-labs-test-assettype.php
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
    'system_name'     => 'LabsTest',
    'label'           => 'Labs test',
    'is_active'       => true,
    'comment'         => $note,
    'capacities'      => [
        ['name' => \Glpi\Asset\Capacity\HasHistoryCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasNotepadCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasDocumentsCapacity::class, 'config' => []],
        ['name' => \Glpi\Asset\Capacity\HasInfocomCapacity::class, 'config' => []],
    ],
    'profiles'        => $profiles_rights,
    'fields_display'  => [],
]);

if (!$definition_id) {
    global $DB;
    fwrite(STDERR, "Failed to create the 'Labs test' asset definition.\n");
    fwrite(STDERR, "DB error: " . $DB->error() . "\n");
    foreach (Session::getNewMessages() as $msgs) {
        foreach ((array) $msgs as $m) {
            fwrite(STDERR, "GLPI message: $m\n");
        }
    }
    exit(1);
}
echo "AssetDefinition #$definition_id created (system_name=LabsTest, label='Labs test')\n";

// The concrete class is dynamically generated from the definition's
// system_name.
$classname = $definition->getAssetClassName();

echo "Custom asset class: $classname\n";

$asset = new $classname();
$asset_id = $asset->add([
    'name'        => '[Ex.] Microscope A1',
    'entities_id' => 0,
    'comment'     => $note,
]);
echo "$classname #$asset_id created\n";
