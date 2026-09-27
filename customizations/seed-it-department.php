<?php

/**
 * Builds a coherent "IT / Computer Department" scenario: a dedicated
 * entity, its staff (group + technician users), and the assets that
 * such a department would typically own (servers, workstations, network
 * gear, monitors, printer, rack/PDU, software + licenses, a maintenance
 * contract) - all scoped to that entity.
 *
 * All records are named with a "[Ex.] " prefix so they stay easy to
 * identify and bulk-delete later.
 *
 * Run inside the app container:
 *   php customizations/seed-it-department.php
 */

$TEST_PASSWORD = (string) getenv('TEST_ACCOUNT_PASSWORD');
if ($TEST_PASSWORD === '') {
    fwrite(STDERR, "Set TEST_ACCOUNT_PASSWORD (password of the seeded test accounts).\n");
    exit(1);
}

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
$TECHNICIAN_PROFILE_ID = 6;

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
// Entity: IT / Computer Department
// ------------------------------------------------------------
$entities_id = seed(Entity::class, [
    'name'        => '[Ex.] แผนกคอมพิวเตอร์ (IT Department)',
    'entities_id' => 0,
    'comment'     => $note,
], $results, 'IT Department entity');

if ($entities_id === null) {
    fwrite(STDERR, "Could not create the IT Department entity, aborting.\n");
    exit(1);
}

// ------------------------------------------------------------
// Staff: group + technicians
// ------------------------------------------------------------
$group_id = seed(Group::class, [
    'name'        => '[Ex.] IT Department Staff',
    'entities_id' => $entities_id,
    'comment'     => $note,
], $results, 'IT Department Staff group');

$user_ids = [];
foreach ([
    ['login' => 'test.somchai', 'firstname' => 'Somchai', 'realname' => 'Techno'],
    ['login' => 'test.suda',    'firstname' => 'Suda',    'realname' => 'Netadmin'],
] as $u) {
    $user_id = seed(User::class, [
        'name'        => $u['login'],
        'firstname'   => $u['firstname'],
        'realname'    => $u['realname'],
        'entities_id' => $entities_id,
        '_entities_id' => $entities_id,
        '_profiles_id' => $TECHNICIAN_PROFILE_ID,
        'password'    => $TEST_PASSWORD,
        'password2'   => $TEST_PASSWORD,
        'comment'     => $note,
    ], $results, "{$u['firstname']} {$u['realname']} ({$u['login']})");

    if ($user_id !== null) {
        $user_ids[] = $user_id;
        if ($group_id !== null) {
            seed(Group_User::class, [
                'groups_id' => $group_id,
                'users_id'  => $user_id,
            ], $results, "link user #$user_id to IT group");
        }
    }
}
$tech_user_id = $user_ids[0] ?? 0;

// ------------------------------------------------------------
// Computers: servers + IT staff workstations
// ------------------------------------------------------------
$computer_ids = [];
foreach ([
    '[Ex.] SRV-DC-01 (Domain Controller)',
    '[Ex.] SRV-FILE-01 (File Server)',
    '[Ex.] SRV-BACKUP-01 (Backup Server)',
    '[Ex.] WKS-IT-01 (Technician workstation)',
    '[Ex.] WKS-IT-02 (Technician workstation)',
] as $name) {
    $id = seed(Computer::class, [
        'name'           => $name,
        'entities_id'    => $entities_id,
        'groups_id_tech' => $group_id ?? 0,
        'users_id_tech'  => $tech_user_id,
        'comment'        => $note,
    ], $results, $name);
    if ($id !== null) {
        $computer_ids[] = $id;
    }
}

// ------------------------------------------------------------
// Monitors for the IT workstations
// ------------------------------------------------------------
foreach (['[Ex.] MON-IT-01', '[Ex.] MON-IT-02'] as $name) {
    seed(Monitor::class, [
        'name' => $name, 'entities_id' => $entities_id, 'comment' => $note,
    ], $results, $name);
}

// ------------------------------------------------------------
// Network gear for the department's server room
// ------------------------------------------------------------
foreach ([
    '[Ex.] SW-CORE-IT-01 (Core switch)',
    '[Ex.] FW-IT-01 (Firewall)',
    '[Ex.] AP-IT-01 (Wireless access point)',
] as $name) {
    seed(NetworkEquipment::class, [
        'name' => $name, 'entities_id' => $entities_id,
        'groups_id_tech' => $group_id ?? 0, 'users_id_tech' => $tech_user_id,
        'comment' => $note,
    ], $results, $name);
}

// ------------------------------------------------------------
// Printer, rack + PDU
// ------------------------------------------------------------
seed(Printer::class, ['name' => '[Ex.] Printer IT room', 'entities_id' => $entities_id, 'comment' => $note], $results, 'IT room printer');
$rack_id = seed(Rack::class, ['name' => '[Ex.] Rack Server Room', 'entities_id' => $entities_id, 'comment' => $note], $results, 'Server room rack');
seed(PDU::class, ['name' => '[Ex.] PDU Server Room', 'entities_id' => $entities_id, 'comment' => $note], $results, 'Server room PDU');

// ------------------------------------------------------------
// Software the department relies on + licenses, installed on servers
// ------------------------------------------------------------
$software = new Software();
$version = new SoftwareVersion();
$installs = new Item_SoftwareVersion();

foreach ([
    ['name' => '[Ex.] Windows Server', 'version' => '2022', 'license_qty' => 5],
    ['name' => '[Ex.] Zabbix Monitoring', 'version' => '7.0', 'license_qty' => null],
    ['name' => '[Ex.] ESET Endpoint Antivirus', 'version' => '11', 'license_qty' => 10],
] as $entry) {
    $software_id = seed(Software::class, [
        'name' => $entry['name'], 'entities_id' => $entities_id, 'comment' => $note,
    ], $results, $entry['name']);
    if ($software_id === null) {
        continue;
    }

    try {
        $version_id = $version->add([
            'name' => $entry['version'], 'softwares_id' => $software_id, 'entities_id' => $entities_id,
        ]);
        $results['ok'][] = "SoftwareVersion #$version_id: {$entry['name']} {$entry['version']}";
    } catch (\Throwable $e) {
        $results['skipped'][] = "SoftwareVersion: {$entry['name']} — " . $e->getMessage();
        continue;
    }

    foreach ($computer_ids as $computer_id) {
        try {
            $install_id = $installs->add([
                'itemtype' => 'Computer', 'items_id' => $computer_id,
                'softwareversions_id' => $version_id, 'entities_id' => $entities_id,
            ]);
            $results['ok'][] = "Item_SoftwareVersion #$install_id: {$entry['name']} on computer #$computer_id";
        } catch (\Throwable $e) {
            $results['skipped'][] = "Item_SoftwareVersion on computer #$computer_id — " . $e->getMessage();
        }
    }

    if ($entry['license_qty'] !== null) {
        seed(SoftwareLicense::class, [
            'name'         => "[Ex.] {$entry['name']} License",
            'softwares_id' => $software_id,
            'number'       => $entry['license_qty'],
            'entities_id'  => $entities_id,
            'comment'      => $note,
        ], $results, "{$entry['name']} license x{$entry['license_qty']}");
    }
}

// ------------------------------------------------------------
// Supplier + maintenance contract covering the department's hardware
// ------------------------------------------------------------
$supplier_id = seed(Supplier::class, [
    'name' => '[Ex.] Dell Enterprise Support', 'entities_id' => $entities_id, 'comment' => $note,
], $results, 'Dell Enterprise Support supplier');

$contract_id = seed(Contract::class, [
    'name'        => '[Ex.] Server Hardware Maintenance',
    'entities_id' => $entities_id,
    'comment'     => $note,
], $results, 'Server hardware maintenance contract');

if ($contract_id !== null && $supplier_id !== null) {
    seed(Contract_Supplier::class, [
        'contracts_id' => $contract_id, 'suppliers_id' => $supplier_id,
    ], $results, 'link contract to supplier');
}

if ($contract_id !== null) {
    foreach ($computer_ids as $computer_id) {
        seed(Contract_Item::class, [
            'contracts_id' => $contract_id, 'itemtype' => 'Computer', 'items_id' => $computer_id,
        ], $results, "link contract to computer #$computer_id");
    }
}

echo "\n=== OK (" . count($results['ok']) . ") ===\n";
foreach ($results['ok'] as $line) {
    echo "  + $line\n";
}
echo "\n=== SKIPPED (" . count($results['skipped']) . ") ===\n";
foreach ($results['skipped'] as $line) {
    echo "  - $line\n";
}
