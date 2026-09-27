<?php

/**
 * Adds the foundational dropdowns that were completely empty (States,
 * Locations, Manufacturers) and applies them to the IT department's
 * assets, then adds financial/warranty info (Infocom) to key servers
 * and properly links Tickets/Problems/Changes to real assets + the IT
 * technicians, so the whole scenario reads as a populated, coherent
 * system rather than disconnected records.
 *
 * Run inside the app container:
 *   php customizations/seed-foundations.php
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
    'SELECT' => 'id', 'FROM' => Entity::getTable(),
    'WHERE'  => ['name' => '[Ex.] แผนกคอมพิวเตอร์ (IT Department)'],
])->current()['id'] ?? 0;

$results = ['ok' => [], 'skipped' => []];
function seed(string $itemtype, array $input, array &$results, string $label = ''): ?int
{
    try {
        $item = new $itemtype();
        $id = $item->add($input);
        if (!$id) {
            $results['skipped'][] = "$itemtype: add() returned falsy for '$label'";
            return null;
        }
        $results['ok'][] = "$itemtype #$id: $label";
        return (int) $id;
    } catch (\Throwable $e) {
        $results['skipped'][] = "$itemtype: '$label' — " . $e->getMessage();
        return null;
    }
}

// ------------------------------------------------------------
// States, Locations, Manufacturers
// ------------------------------------------------------------
$state_ids = [];
foreach (['In Use', 'In Stock', 'Under Repair', 'Reserved', 'Retired'] as $name) {
    $id = seed(State::class, ['name' => $name, 'comment' => $note], $results, "state:$name");
    if ($id !== null) {
        $state_ids[$name] = $id;
    }
}

$location_ids = [];
foreach (['Server Room', 'IT Office', 'Network Closet', 'Meeting Room A', 'Reception'] as $name) {
    $id = seed(Location::class, ['name' => $name, 'entities_id' => $entities_id, 'comment' => $note], $results, "location:$name");
    if ($id !== null) {
        $location_ids[$name] = $id;
    }
}

$manufacturer_ids = [];
foreach (['Dell', 'HP', 'Cisco', 'APC', 'Fortinet', 'Lenovo', 'Apple', 'Ubiquiti'] as $name) {
    $id = seed(Manufacturer::class, ['name' => $name, 'comment' => $note], $results, "manufacturer:$name");
    if ($id !== null) {
        $manufacturer_ids[$name] = $id;
    }
}

// ------------------------------------------------------------
// Apply State/Location/Manufacturer to IT department assets
// ------------------------------------------------------------
$assignments = [
    // itemtype => [ [name_like, location, manufacturer, state], ... ]
    Computer::class => [
        ['SRV-DC-01',   'Server Room', 'Dell',  'In Use'],
        ['SRV-FILE-01', 'Server Room', 'Dell',  'In Use'],
        ['SRV-BACKUP-01', 'Server Room', 'HP',  'In Use'],
        ['WKS-IT-01',   'IT Office',   'Lenovo', 'In Use'],
        ['WKS-IT-02',   'IT Office',   'Lenovo', 'In Use'],
    ],
    NetworkEquipment::class => [
        ['SW-CORE-IT-01', 'Network Closet', 'Cisco', 'In Use'],
        ['FW-IT-01',      'Network Closet', 'Fortinet', 'In Use'],
        ['AP-IT-01',      'IT Office',      'Ubiquiti', 'In Use'],
    ],
    Monitor::class => [
        ['MON-IT-01', 'IT Office', 'Dell', 'In Use'],
        ['MON-IT-02', 'IT Office', 'Dell', 'In Use'],
    ],
    Printer::class => [
        ['IT room printer', 'IT Office', 'HP', 'In Use'],
    ],
    Rack::class => [
        ['Server Room Rack', 'Server Room', 'APC', 'In Use'],
    ],
    PDU::class => [
        ['Server Room PDU', 'Server Room', 'APC', 'In Use'],
    ],
];

foreach ($assignments as $itemtype => $rules) {
    foreach ($rules as [$name_like, $loc, $mfr, $state]) {
        $rows = iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => $itemtype::getTable(),
            'WHERE'  => ['name' => ['LIKE', "%$name_like%"]],
        ]));
        foreach ($rows as $row) {
            $item = new $itemtype();
            $ok = $item->update([
                'id'               => $row['id'],
                'locations_id'     => $location_ids[$loc] ?? 0,
                'manufacturers_id' => $manufacturer_ids[$mfr] ?? 0,
                'states_id'        => $state_ids[$state] ?? 0,
            ]);
            $results[$ok ? 'ok' : 'skipped'][] = "$itemtype #{$row['id']}: set location=$loc, manufacturer=$mfr, state=$state";
        }
    }
}

// ------------------------------------------------------------
// Financial / warranty info (Infocom) on the 3 servers
// ------------------------------------------------------------
$infocom = new Infocom();
$server_rows = iterator_to_array($DB->request([
    'SELECT' => ['id', 'name'],
    'FROM'   => Computer::getTable(),
    'WHERE'  => ['name' => ['LIKE', '%SRV-%']],
]));
foreach ($server_rows as $row) {
    $id = seed(Infocom::class, [
        'itemtype'      => 'Computer',
        'items_id'      => $row['id'],
        'buy_date'      => '2024-03-15',
        'use_date'      => '2024-04-01',
        'warranty_date' => '2024-04-01',
        'warranty_duration' => 36,
        'value'         => 85000,
        'comment'       => $note,
    ], $results, "infocom for computer #{$row['id']} ({$row['name']})");
}

// ------------------------------------------------------------
// Link Tickets/Problems/Changes to real IT-department assets + staff
// ------------------------------------------------------------
$tech_user_id = $DB->request([
    'SELECT' => 'id', 'FROM' => User::getTable(), 'WHERE' => ['name' => 'test.somchai'],
])->current()['id'] ?? 0;

$group_id = $DB->request([
    'SELECT' => 'id', 'FROM' => Group::getTable(), 'WHERE' => ['name' => '[Ex.] IT Department Staff'],
])->current()['id'] ?? 0;

$srv_dc = $DB->request([
    'SELECT' => 'id', 'FROM' => Computer::getTable(), 'WHERE' => ['name' => ['LIKE', '%SRV-DC-01%']],
])->current()['id'] ?? 0;

$sw_core = $DB->request([
    'SELECT' => 'id', 'FROM' => NetworkEquipment::getTable(), 'WHERE' => ['name' => ['LIKE', '%SW-CORE-IT-01%']],
])->current()['id'] ?? 0;

$ticket_id = seed(Ticket::class, [
    'name'                => '[Ex.] Domain controller high CPU usage',
    'content'             => "$note\nSRV-DC-01 has been running at 95%+ CPU for the past hour, investigate.",
    'entities_id'         => $entities_id,
    'urgency'             => 4,
    'impact'              => 4,
    '_users_id_requester' => $tech_user_id,
    '_users_id_assign'    => $tech_user_id,
    '_groups_id_assign'   => $group_id,
], $results, 'Domain controller high CPU ticket');
if ($ticket_id !== null && $srv_dc) {
    seed(Item_Ticket::class, [
        'tickets_id' => $ticket_id, 'itemtype' => 'Computer', 'items_id' => $srv_dc,
    ], $results, 'link ticket to SRV-DC-01');
}

$problem_id = seed(Problem::class, [
    'name'        => '[Ex.] Recurring switch reboot on SW-CORE-IT-01',
    'content'     => "$note\nCore switch has rebooted unexpectedly 3 times this month, root cause under investigation.",
    'entities_id' => $entities_id,
], $results, 'Recurring switch reboot problem');
if ($problem_id !== null && $sw_core) {
    seed(Item_Problem::class, [
        'problems_id' => $problem_id, 'itemtype' => 'NetworkEquipment', 'items_id' => $sw_core,
    ], $results, 'link problem to SW-CORE-IT-01');
}

$change_id = seed(Change::class, [
    'name'        => '[Ex.] Replace UPS batteries in server room',
    'content'     => "$note\nScheduled battery replacement for the server room UPS before end of quarter.",
    'entities_id' => $entities_id,
], $results, 'UPS battery replacement change');

echo "\n=== OK (" . count($results['ok']) . ") ===\n";
foreach ($results['ok'] as $line) {
    echo "  + $line\n";
}
echo "\n=== SKIPPED (" . count($results['skipped']) . ") ===\n";
foreach ($results['skipped'] as $line) {
    echo "  - $line\n";
}
