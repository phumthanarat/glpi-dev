<?php

/**
 * Reverts everything added by seed-it-department.php, seed-software.php,
 * seed-sample-data.php, seed-all.php, seed-foundations.php,
 * seed-more-assettypes.php, seed-more-assettypes-2.php,
 * seed-unmanaged.php, seed-simcard.php, seed-labs-test-assettype.php,
 * seed-it-equipment-assettype.php and seed-notebook-ex-assettype.php,
 * restoring the database to the plain post-`database:install` default
 * state (default users/profiles/entity/dropdowns only).
 *
 * Matches records the same two ways every seed script marks them:
 *   - name starting with "[Ex."
 *   - comment exactly equal to the seed note text
 * plus an explicit list for the two seeded users, which carry neither
 * marker (usernames don't take a display-name prefix).
 *
 * Deleting each custom Asset Definition cascades (via GLPI core) the
 * deletion of every item of that custom type - no need to delete those
 * items individually first.
 *
 * Run inside the app container:
 *   php customizations/cleanup-seed-data.php
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

global $DB;
$note = 'Sample/test data seeded for demo purposes - safe to delete.';

$results = ['ok' => [], 'skipped' => []];

/**
 * Deletes every row of $itemtype matching the seed marker (name prefix
 * or exact comment), by exact id (force purge, no history).
 *
 * @param class-string $itemtype
 */
function purgeMarked(string $itemtype, array &$results, string $note): void
{
    global $DB;
    $table = $itemtype::getTable();
    $has_name = $DB->fieldExists($table, 'name');
    $has_comment = $DB->fieldExists($table, 'comment');

    $conditions = [];
    if ($has_name) {
        // Matches both the current "[Ex.]" prefix and the older "[TEST]"/
        // "[TEST-IT]" one, so this cleans up either generation of seed data.
        $conditions[] = ['name' => ['LIKE', '[Ex.%']];
        $conditions[] = ['name' => ['LIKE', '[TEST%']];
    }
    if ($has_comment) {
        $conditions[] = ['comment' => $note];
    }
    if ($conditions === []) {
        $results['skipped'][] = "$itemtype: no name/comment field to match on, skipped";
        return;
    }
    $where = count($conditions) > 1 ? ['OR' => $conditions] : $conditions[0];

    $rows = iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => $where]));
    foreach ($rows as $row) {
        $item = new $itemtype();
        $ok = $item->delete(['id' => $row['id']], true);
        $results[$ok ? 'ok' : 'skipped'][] = "$itemtype #{$row['id']}" . ($ok ? '' : ' (delete returned false)');
    }
}

/**
 * Deletes exact-name matches, for the few records seeded without any
 * [Ex.] marker (e.g. usernames).
 *
 * @param class-string $itemtype
 */
function purgeByNames(string $itemtype, array $names, array &$results): void
{
    global $DB;
    $table = $itemtype::getTable();
    $rows = iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => $table, 'WHERE' => ['name' => $names]]));
    foreach ($rows as $row) {
        $item = new $itemtype();
        $ok = $item->delete(['id' => $row['id']], true);
        $results[$ok ? 'ok' : 'skipped'][] = "$itemtype #{$row['id']}" . ($ok ? '' : ' (delete returned false)');
    }
}

// ------------------------------------------------------------
// Phase A: Assistance / Tools (leaves)
// ------------------------------------------------------------
foreach ([Ticket::class, Problem::class, Change::class, Project::class, Reminder::class, RSSFeed::class, KnowbaseItem::class] as $t) {
    purgeMarked($t, $results, $note);
}

// ------------------------------------------------------------
// Phase B: Hardware assets
// ------------------------------------------------------------
foreach ([
    Computer::class, Monitor::class, NetworkEquipment::class, Printer::class, Rack::class, PDU::class,
    Peripheral::class, CartridgeItem::class, ConsumableItem::class, Phone::class, Enclosure::class,
    PassiveDCEquipment::class, Cable::class, Unmanaged::class,
] as $t) {
    purgeMarked($t, $results, $note);
}

// ------------------------------------------------------------
// Phase C: Software (cascades versions/installs/licenses)
// ------------------------------------------------------------
purgeMarked(Software::class, $results, $note);
purgeMarked(SoftwareLicense::class, $results, $note); // any left over, if not cascaded

// ------------------------------------------------------------
// Phase D: Management extras
// ------------------------------------------------------------
foreach ([
    Supplier::class, Contract::class, Contact::class, Document::class, Line::class, Certificate::class,
    Datacenter::class, Cluster::class, Domain::class, Appliance::class, Database::class, Budget::class,
    DeviceSimcard::class,
] as $t) {
    purgeMarked($t, $results, $note);
}

// ------------------------------------------------------------
// Phase E: Custom Asset Definitions (cascades their items)
// ------------------------------------------------------------
foreach ([
    'LabsTest', 'ITEquipment', 'MobileDevice', 'BackupMedia', 'IoTDevice',
    'SecurityEquipment', 'AVEquipment', 'PowerEquipment', 'SparePart', 'NotebookEx',
] as $system_name) {
    $definition = new \Glpi\Asset\AssetDefinition();
    if ($definition->getFromDBBySystemName($system_name)) {
        $ok = $definition->delete(['id' => $definition->getID()], true);
        $results[$ok ? 'ok' : 'skipped'][] = "AssetDefinition ($system_name)" . ($ok ? '' : ' (delete returned false)');
    }
}

// ------------------------------------------------------------
// Phase F: Foundational dropdowns
// ------------------------------------------------------------
foreach ([State::class, Location::class, Manufacturer::class] as $t) {
    purgeMarked($t, $results, $note);
}

// ------------------------------------------------------------
// Phase G: Org structure (groups/users/entities), last
// ------------------------------------------------------------
purgeMarked(Group::class, $results, $note);
purgeByNames(User::class, ['test.somchai', 'test.suda'], $results);
purgeMarked(Entity::class, $results, $note);

echo "\n=== OK (" . count($results['ok']) . ") ===\n";
foreach ($results['ok'] as $line) {
    echo "  + $line\n";
}
echo "\n=== SKIPPED (" . count($results['skipped']) . ") ===\n";
foreach ($results['skipped'] as $line) {
    echo "  - $line\n";
}
