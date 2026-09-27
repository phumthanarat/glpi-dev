<?php

/**
 * Seeds a small, varied set of Tickets, Changes and Problems covering the
 * main ITIL use cases (incident vs. request, the full status lifecycle,
 * and the Problem -> Change -> Ticket linkage) so the ITSM menus have
 * realistic-looking demo data instead of the "No results found" empty
 * state, or the flat all-identical rows seed-sample-data.php's 3 tickets
 * give.
 *
 * Everything is marked with the "[Ex.]" prefix, same convention as
 * seed-sample-data.php / seed-foundations.php, so it's picked up by
 * customizations/cleanup-seed-data.php.
 *
 * Run inside the app container:
 *   php customizations/seed-itil-usecases.php
 *
 * Safe to run once; re-running will create duplicate entries (no
 * idempotency check).
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
$requester_id = 2; // default 'glpi' super-admin user id

function setStatus(string $itemtype, int $id, int $status): void
{
    $item = new $itemtype();
    $item->update(['id' => $id, 'status' => $status]);
}

function addSolution(string $itemtype, int $id, string $content): void
{
    $item = new $itemtype();
    $item->update([
        'id'                => $id,
        'status'            => CommonITILObject::SOLVED,
        '_add_solution'     => true,
        'solution'          => $content,
        'solutiontypes_id'  => 0,
    ]);
}

echo "Creating tickets...\n";
$ticket = new Ticket();
$ticket_ids = [];
foreach ([
    // Incidents, across the lifecycle
    ['name' => '[Ex.] Email server not responding', 'content' => 'Company-wide mail server is down, no inbound/outbound mail since 09:10.', 'type' => Ticket::INCIDENT_TYPE, 'urgency' => 5, 'impact' => 5, 'status' => CommonITILObject::INCOMING],
    ['name' => '[Ex.] Laptop keeps blue-screening', 'content' => 'User laptop crashes with a blue screen several times a day, suspect driver issue.', 'type' => Ticket::INCIDENT_TYPE, 'urgency' => 3, 'impact' => 2, 'status' => CommonITILObject::ASSIGNED],
    ['name' => '[Ex.] VPN drops every few minutes', 'content' => 'Remote worker VPN connection is unstable. Asked user to confirm ISP is not the cause.', 'type' => Ticket::INCIDENT_TYPE, 'urgency' => 3, 'impact' => 2, 'status' => CommonITILObject::WAITING],
    ['name' => '[Ex.] Password reset for locked account', 'content' => 'User account was locked after failed login attempts; identity verified and password reset.', 'type' => Ticket::INCIDENT_TYPE, 'urgency' => 2, 'impact' => 1, 'status' => CommonITILObject::SOLVED, 'solution' => 'Password reset and account unlocked; user confirmed access restored.'],
    ['name' => '[Ex.] Printer on 2nd floor out of toner', 'content' => 'The shared printer on the 2nd floor is out of toner and needs a replacement cartridge.', 'type' => Ticket::INCIDENT_TYPE, 'urgency' => 2, 'impact' => 1, 'status' => CommonITILObject::CLOSED, 'solution' => 'Toner cartridge replaced.'],
    // Service requests
    ['name' => '[Ex.] New employee onboarding - laptop + accounts', 'content' => 'New hire starting Monday needs a laptop, email account, and access to the shared drive.', 'type' => Ticket::DEMAND_TYPE, 'urgency' => 3, 'impact' => 2, 'status' => CommonITILObject::INCOMING],
    ['name' => '[Ex.] Request: additional monitor for workstation', 'content' => 'Sales team member requests a second monitor for their workstation.', 'type' => Ticket::DEMAND_TYPE, 'urgency' => 2, 'impact' => 1, 'status' => CommonITILObject::ASSIGNED],
] as $data) {
    $id = $ticket->add([
        'name'                => $data['name'],
        'content'             => $data['content'],
        'entities_id'         => $entities_id,
        'type'                => $data['type'],
        'urgency'             => $data['urgency'],
        'impact'              => $data['impact'],
        '_users_id_requester' => $requester_id,
        '_skip_auto_assign'   => true,
    ]);
    echo "  - {$data['name']} => id $id\n";
    if (isset($data['solution'])) {
        addSolution('Ticket', $id, $data['solution']);
        if ($data['status'] === CommonITILObject::CLOSED) {
            setStatus('Ticket', $id, CommonITILObject::CLOSED);
        }
    } elseif ($data['status'] !== CommonITILObject::INCOMING) {
        setStatus('Ticket', $id, $data['status']);
    }
    $ticket_ids[$data['name']] = $id;
}

echo "Creating problems...\n";
$problem = new Problem();
$problem_ids = [];
foreach ([
    ['name' => '[Ex.] Recurring mail server outages', 'content' => 'Mail server has gone down 3 times this month; investigating a common root cause.', 'urgency' => 4, 'impact' => 4, 'status' => CommonITILObject::INCOMING],
    ['name' => '[Ex.] Repeated laptop crashes across sales team', 'content' => 'Several sales laptops from the same batch are crashing; suspected faulty graphics driver.', 'urgency' => 3, 'impact' => 3, 'status' => CommonITILObject::ASSIGNED],
    ['name' => '[Ex.] Mail server outages - root cause found', 'content' => 'Root cause: mail server was swapping under memory pressure during backup window.', 'urgency' => 4, 'impact' => 4, 'status' => CommonITILObject::SOLVED, 'solution' => 'Increased mail server RAM and rescheduled the backup window; monitoring confirms no further swap events.'],
] as $data) {
    $id = $problem->add([
        'name'                => $data['name'],
        'content'             => $data['content'],
        'entities_id'         => $entities_id,
        'urgency'             => $data['urgency'],
        'impact'              => $data['impact'],
        '_users_id_requester' => $requester_id,
    ]);
    echo "  - {$data['name']} => id $id\n";
    if (isset($data['solution'])) {
        addSolution('Problem', $id, $data['solution']);
    } elseif ($data['status'] !== CommonITILObject::INCOMING) {
        setStatus('Problem', $id, $data['status']);
    }
    $problem_ids[$data['name']] = $id;
}

echo "Creating changes...\n";
$change = new Change();
$change_ids = [];
foreach ([
    ['name' => '[Ex.] Upgrade mail server RAM', 'content' => 'Increase mail server RAM from 16GB to 32GB to fix the swap-under-load root cause.', 'urgency' => 4, 'impact' => 4, 'status' => CommonITILObject::INCOMING],
    ['name' => '[Ex.] Migrate file server to new storage array', 'content' => 'Move shared file storage to the new SAN before the old array goes out of support.', 'urgency' => 2, 'impact' => 3, 'status' => Change::EVALUATION],
    ['name' => '[Ex.] Emergency firewall rule for active exploit', 'content' => 'Block outbound traffic on the affected port pending vendor patch; needs urgent approval.', 'urgency' => 5, 'impact' => 5, 'status' => CommonITILObject::APPROVAL],
    ['name' => '[Ex.] Quarterly OS patch rollout - completed', 'content' => 'Routine quarterly patching across all workstations and servers.', 'urgency' => 2, 'impact' => 2, 'status' => CommonITILObject::CLOSED, 'solution' => 'All endpoints patched and rebooted successfully during the maintenance window.'],
] as $data) {
    $id = $change->add([
        'name'                => $data['name'],
        'content'             => $data['content'],
        'entities_id'         => $entities_id,
        'urgency'             => $data['urgency'],
        'impact'              => $data['impact'],
        '_users_id_requester' => $requester_id,
    ]);
    echo "  - {$data['name']} => id $id\n";
    if (isset($data['solution'])) {
        addSolution('Change', $id, $data['solution']);
        if ($data['status'] === CommonITILObject::CLOSED) {
            setStatus('Change', $id, CommonITILObject::CLOSED);
        }
    } elseif ($data['status'] !== CommonITILObject::INCOMING) {
        setStatus('Change', $id, $data['status']);
    }
    $change_ids[$data['name']] = $id;
}

echo "Linking problem -> change -> ticket chain...\n";
$change_problem = new Change_Problem();
$change_problem->add([
    'changes_id'  => $change_ids['[Ex.] Upgrade mail server RAM'],
    'problems_id' => $problem_ids['[Ex.] Mail server outages - root cause found'],
]);
echo "  - Change 'Upgrade mail server RAM' <-> Problem 'root cause found'\n";

$problem_ticket = new Problem_Ticket();
$problem_ticket->add([
    'problems_id' => $problem_ids['[Ex.] Recurring mail server outages'],
    'tickets_id'  => $ticket_ids['[Ex.] Email server not responding'],
]);
echo "  - Problem 'Recurring mail server outages' <-> Ticket 'Email server not responding'\n";

$change_ticket = new Change_Ticket();
$change_ticket->add([
    'changes_id' => $change_ids['[Ex.] Upgrade mail server RAM'],
    'tickets_id' => $ticket_ids['[Ex.] Email server not responding'],
]);
echo "  - Change 'Upgrade mail server RAM' <-> Ticket 'Email server not responding'\n";

echo "Done.\n";
