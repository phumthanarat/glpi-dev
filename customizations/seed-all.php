<?php

/**
 * Seeds sample/test data across every content-listing menu in GLPI
 * (Assets, Assistance, Management, and a few Tools/Administration
 * items), so nothing shows "No results found" on a fresh install.
 *
 * All created records are named with a "[Ex.] " prefix and, where the
 * itemtype has a comment/content field, carry a note explaining they are
 * sample data — so they're always identifiable and safe to bulk-delete
 * later (e.g. `DELETE FROM glpi_xxx WHERE name LIKE '[Ex.]%'`).
 *
 * Run inside the app container:
 *   php customizations/seed-all.php
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

$results = [
    'ok'      => [],
    'skipped' => [],
];

/**
 * @param class-string $itemtype
 */
function seed(string $itemtype, array $input, array &$results): ?int
{
    try {
        $item = new $itemtype();
        $id = $item->add($input);
        if (!$id) {
            $results['skipped'][] = "$itemtype: add() returned falsy for '{$input['name']}'";
            return null;
        }
        $results['ok'][] = "$itemtype #$id: {$input['name']}";
        return (int) $id;
    } catch (\Throwable $e) {
        $results['skipped'][] = "$itemtype: '{$input['name']}' — " . $e->getMessage();
        return null;
    }
}

// ------------------------------------------------------------
// Assets
// ------------------------------------------------------------
seed(NetworkEquipment::class, ['name' => '[Ex.] SW-CORE-01', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Peripheral::class, ['name' => '[Ex.] USB Dock - Reception', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Printer::class, ['name' => '[Ex.] Printer 2nd floor', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(CartridgeItem::class, ['name' => '[Ex.] Toner HP 26A', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(ConsumableItem::class, ['name' => '[Ex.] A4 Paper ream', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Phone::class, ['name' => '[Ex.] Reception phone', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Rack::class, ['name' => '[Ex.] Rack A1', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Enclosure::class, ['name' => '[Ex.] Blade Enclosure 1', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(PDU::class, ['name' => '[Ex.] PDU-A1-01', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(PassiveDCEquipment::class, ['name' => '[Ex.] Patch Panel A1', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Cable::class, ['name' => '[Ex.] Cable A1-01', 'entities_id' => $entities_id, 'comment' => $note], $results);

// ------------------------------------------------------------
// Assistance
// ------------------------------------------------------------
seed(Problem::class, [
    'name'        => '[Ex.] Intermittent network outages in building B',
    'content'     => $note,
    'entities_id' => $entities_id,
], $results);
seed(Change::class, [
    'name'        => '[Ex.] Upgrade core switch firmware',
    'content'     => $note,
    'entities_id' => $entities_id,
], $results);

// ------------------------------------------------------------
// Management
// ------------------------------------------------------------
seed(SoftwareLicense::class, [
    'name'         => '[Ex.] Office 2021 - Volume License',
    'softwares_id' => 1, // '[Ex.] Microsoft Office', created by seed-software.php
    'number'       => 10,
    'entities_id'  => $entities_id,
    'comment'      => $note,
], $results);
seed(Budget::class, [
    'name' => '[Ex.] IT Budget 2026', 'entities_id' => $entities_id, 'comment' => $note,
    'begin_date' => '2026-01-01', 'end_date' => '2026-12-31',
], $results);
seed(Supplier::class, ['name' => '[Ex.] Acme Hardware Supplier', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Contact::class, ['name' => '[Ex.] Doe', 'firstname' => 'John', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Contract::class, ['name' => '[Ex.] Hardware Maintenance Contract', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Document::class, ['name' => '[Ex.] Network diagram', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Line::class, ['name' => '[Ex.] +1-555-0100', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Certificate::class, ['name' => '[Ex.] wildcard.example.com', 'entities_id' => $entities_id, 'comment' => $note, 'dns_name' => 'example.com'], $results);
seed(Datacenter::class, ['name' => '[Ex.] Main Datacenter', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Cluster::class, ['name' => '[Ex.] Prod Cluster', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Domain::class, ['name' => '[Ex.] example.com', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Appliance::class, ['name' => '[Ex.] Firewall Appliance', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Database::class, ['name' => '[Ex.] prod-db-01', 'entities_id' => $entities_id, 'comment' => $note], $results);

// ------------------------------------------------------------
// Tools
// ------------------------------------------------------------
seed(Project::class, [
    'name' => '[Ex.] Office network refresh', 'content' => $note, 'entities_id' => $entities_id,
], $results);
seed(Reminder::class, ['name' => '[Ex.] Renew SSL certificates', 'text' => $note, 'users_id' => 2], $results);
seed(RSSFeed::class, ['name' => '[Ex.] GLPI project news', 'url' => 'https://glpi-project.org/feed/', 'users_id' => 2], $results);
seed(KnowbaseItem::class, ['name' => '[Ex.] How to reset your password', 'answer' => $note, 'users_id' => 2], $results);
seed(ReservationItem::class, ['itemtype' => 'Computer', 'items_id' => 1, 'entities_id' => $entities_id, 'comment' => $note], $results);

// ------------------------------------------------------------
// Administration
// ------------------------------------------------------------
seed(Group::class, ['name' => '[Ex.] IT Support Team', 'entities_id' => $entities_id, 'comment' => $note], $results);
seed(Entity::class, ['name' => '[Ex.] Branch Office', 'entities_id' => $entities_id, 'comment' => $note], $results);

echo "\n=== OK (" . count($results['ok']) . ") ===\n";
foreach ($results['ok'] as $line) {
    echo "  + $line\n";
}

echo "\n=== SKIPPED (" . count($results['skipped']) . ") ===\n";
foreach ($results['skipped'] as $line) {
    echo "  - $line\n";
}
