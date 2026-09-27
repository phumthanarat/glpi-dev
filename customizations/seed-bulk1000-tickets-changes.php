<?php
// "[Bulk1000]" load-test dataset: 1000 Tickets + 1000 Changes, cycling
// through the same use-case spread as
// customizations/seed-itil-usecases.php (Incident vs Request, and the
// full status lifecycle) instead of 1000 identical rows, so the volume
// is realistic as well as large. Used to exercise cron/notification
// volume (glpi_queuednotifications) at scale.
//
// NOTE: each add()/update() fires GLPI's normal notification rules, so
// this queues ~2000+ rows into glpi_queuednotifications - the
// queuednotification crontask drains 50/min by default. See
// customizations/README.md or ask about clearing
// glpi_queuednotifications (WHERE sent_time IS NULL) if the backlog
// needs to be cleared rather than left to drain.
//
// Unlike seed-sample-data.php / seed-itil-usecases.php, these are NOT
// marked with the "[Ex.]" prefix, so customizations/cleanup-seed-data.php
// won't touch them - delete by `name LIKE '[Bulk1000]%'` directly.
//
// Run inside the app container:
//   php customizations/seed-bulk1000-tickets-changes.php
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

// Ticket use cases: 7 incident-flavored slots + 3 request-flavored slots per 10,
// matching the ratio in seed-itil-usecases.php.
$ticketUseCases = [
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - reported outage', 'urgency' => 5, 'impact' => 4, 'status' => CommonITILObject::INCOMING],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - assigned to technician', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::ASSIGNED],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - waiting on user', 'urgency' => 3, 'impact' => 2, 'status' => CommonITILObject::WAITING],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - resolved', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::SOLVED, 'solution' => 'Resolved by support technician; user confirmed.'],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - closed', 'urgency' => 2, 'impact' => 1, 'status' => CommonITILObject::CLOSED, 'solution' => 'Closed after confirmation from requester.'],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - minor issue', 'urgency' => 1, 'impact' => 1, 'status' => CommonITILObject::INCOMING],
    ['type' => Ticket::INCIDENT_TYPE, 'label' => 'Incident - major impact', 'urgency' => 4, 'impact' => 5, 'status' => CommonITILObject::ASSIGNED],
    ['type' => Ticket::DEMAND_TYPE, 'label' => 'Request - new equipment', 'urgency' => 2, 'impact' => 1, 'status' => CommonITILObject::INCOMING],
    ['type' => Ticket::DEMAND_TYPE, 'label' => 'Request - access/account change', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::ASSIGNED],
    ['type' => Ticket::DEMAND_TYPE, 'label' => 'Request - fulfilled', 'urgency' => 1, 'impact' => 1, 'status' => CommonITILObject::CLOSED, 'solution' => 'Request fulfilled and closed.'],
];

// Change use cases: spread across the change-specific lifecycle.
$changeUseCases = [
    ['label' => 'Change - proposed', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::INCOMING],
    ['label' => 'Change - under evaluation', 'urgency' => 2, 'impact' => 3, 'status' => Change::EVALUATION],
    ['label' => 'Change - pending approval', 'urgency' => 4, 'impact' => 4, 'status' => CommonITILObject::APPROVAL],
    ['label' => 'Change - emergency, pending approval', 'urgency' => 5, 'impact' => 5, 'status' => CommonITILObject::APPROVAL],
    ['label' => 'Change - in qualification', 'urgency' => 2, 'impact' => 2, 'status' => Change::QUALIFICATION],
    ['label' => 'Change - in test', 'urgency' => 3, 'impact' => 3, 'status' => Change::TEST],
    ['label' => 'Change - assigned/building', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::ASSIGNED],
    ['label' => 'Change - refused', 'urgency' => 2, 'impact' => 2, 'status' => Change::REFUSED],
    ['label' => 'Change - completed', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::CLOSED, 'solution' => 'Change implemented successfully and verified.'],
    ['label' => 'Change - canceled', 'urgency' => 1, 'impact' => 1, 'status' => Change::CANCELED],
];

echo "Creating 1000 tickets across " . count($ticketUseCases) . " use cases...\n";
$ticket = new Ticket();
for ($i = 1; $i <= 1000; $i++) {
    $uc = $ticketUseCases[($i - 1) % count($ticketUseCases)];
    $id = $ticket->add([
        'name'                => "[Bulk1000] Ticket #$i",
        'content'             => "Load-test ticket #$i - {$uc['label']}.",
        'entities_id'         => $entities_id,
        'type'                => $uc['type'],
        'urgency'             => $uc['urgency'],
        'impact'              => $uc['impact'],
        '_users_id_requester' => $requester_id,
        '_skip_auto_assign'   => true,
    ]);
    if (isset($uc['solution'])) {
        addSolution('Ticket', $id, $uc['solution']);
        if ($uc['status'] === CommonITILObject::CLOSED) {
            setStatus('Ticket', $id, CommonITILObject::CLOSED);
        }
    } elseif ($uc['status'] !== CommonITILObject::INCOMING) {
        setStatus('Ticket', $id, $uc['status']);
    }
    if ($i % 100 === 0) {
        echo "  ...$i tickets\n";
    }
}

echo "Creating 1000 changes across " . count($changeUseCases) . " use cases...\n";
$change = new Change();
for ($i = 1; $i <= 1000; $i++) {
    $uc = $changeUseCases[($i - 1) % count($changeUseCases)];
    $id = $change->add([
        'name'                => "[Bulk1000] Change #$i",
        'content'             => "Load-test change #$i - {$uc['label']}.",
        'entities_id'         => $entities_id,
        'urgency'             => $uc['urgency'],
        'impact'              => $uc['impact'],
        '_users_id_requester' => $requester_id,
    ]);
    if (isset($uc['solution'])) {
        addSolution('Change', $id, $uc['solution']);
        if ($uc['status'] === CommonITILObject::CLOSED) {
            setStatus('Change', $id, CommonITILObject::CLOSED);
        }
    } elseif ($uc['status'] !== CommonITILObject::INCOMING) {
        setStatus('Change', $id, $uc['status']);
    }
    if ($i % 100 === 0) {
        echo "  ...$i changes\n";
    }
}

echo "Done.\n";
