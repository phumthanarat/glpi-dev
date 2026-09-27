<?php
// "[Bulk1000]" load-test dataset, Problem leg: 1000 Problems cycling
// through the same kind of use-case spread as the Ticket/Change batch
// (see seed-bulk1000-tickets-changes.php), for parity with the 1000
// Tickets + 1000 Changes it seeds.
//
// Same notes apply: NOT marked "[Ex.]" (delete by
// `name LIKE '[Bulk1000]%'` directly, not via cleanup-seed-data.php),
// and each add()/update() queues notifications same as the Ticket/Change
// batch.
//
// Run inside the app container:
//   php customizations/seed-bulk1000-problems.php
//
// Safe to run once; re-running will create duplicate entries (no
// idempotency check).

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
$requester_id = 2;

function setStatus(string $itemtype, int $id, int $status): void
{
    $item = new $itemtype();
    $item->update(['id' => $id, 'status' => $status]);
}

function addSolution(string $itemtype, int $id, string $content): void
{
    $item = new $itemtype();
    $item->update([
        'id'               => $id,
        'status'           => CommonITILObject::SOLVED,
        '_add_solution'    => true,
        'solution'         => $content,
        'solutiontypes_id' => 0,
    ]);
}

$problemUseCases = [
    ['label' => 'Problem - reported, awaiting triage', 'urgency' => 4, 'impact' => 4, 'status' => CommonITILObject::INCOMING],
    ['label' => 'Problem - assigned for investigation', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::ASSIGNED],
    ['label' => 'Problem - under observation', 'urgency' => 2, 'impact' => 3, 'status' => CommonITILObject::OBSERVED],
    ['label' => 'Problem - root cause found, fix planned', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::PLANNED],
    ['label' => 'Problem - waiting on vendor', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::WAITING],
    ['label' => 'Problem - resolved', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::SOLVED, 'solution' => 'Root cause identified and permanent fix applied; monitored with no recurrence.'],
    ['label' => 'Problem - closed', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::CLOSED, 'solution' => 'Confirmed resolved and closed after monitoring period.'],
    ['label' => 'Problem - low priority, minor recurring glitch', 'urgency' => 1, 'impact' => 1, 'status' => CommonITILObject::INCOMING],
    ['label' => 'Problem - major, multiple services affected', 'urgency' => 5, 'impact' => 5, 'status' => CommonITILObject::ASSIGNED],
    ['label' => 'Problem - recurring, root cause still unclear', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::OBSERVED],
];

echo "Creating 1000 problems across " . count($problemUseCases) . " use cases...\n";
$problem = new Problem();
for ($i = 1; $i <= 1000; $i++) {
    $uc = $problemUseCases[($i - 1) % count($problemUseCases)];
    $id = $problem->add([
        'name'                => "[Bulk1000] Problem #$i",
        'content'             => "Load-test problem #$i - {$uc['label']}.",
        'entities_id'         => $entities_id,
        'urgency'             => $uc['urgency'],
        'impact'              => $uc['impact'],
        '_users_id_requester' => $requester_id,
    ]);
    if (isset($uc['solution'])) {
        addSolution('Problem', $id, $uc['solution']);
        if ($uc['status'] === CommonITILObject::CLOSED) {
            setStatus('Problem', $id, CommonITILObject::CLOSED);
        }
    } elseif ($uc['status'] !== CommonITILObject::INCOMING) {
        setStatus('Problem', $id, $uc['status']);
    }
    if ($i % 100 === 0) {
        echo "  ...$i problems\n";
    }
}

echo "Done.\n";
