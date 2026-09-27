<?php
// Assigns a technician + team group to every "[Bulk1000]" Ticket/Change/
// Problem (see seed-bulk1000-tickets-changes.php / seed-bulk1000-
// problems.php) whose status is past "New" (INCOMING), round-robining
// across the technician users and assignable groups already in the DB.
// Items still in INCOMING stay unassigned, matching the "New = not yet
// triaged" use case.
//
// Technician/group ids below are specific to this instance's seeded
// users (customizations/seed-it-department.php) and groups - adjust if
// run against a different dataset.
//
// Run inside the app container:
//   php customizations/assign-bulk1000.php
//
// Safe to run once; re-running will add duplicate actor assignments (no
// idempotency check).

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

$technicians = [4, 11, 12]; // tech, test.somchai, test.suda
$groups = [7, 8, 9, 10, 11]; // Helpdesk/Service Desk, Network, System, Application, IT Manager

function assignBulk1000(
    string $itemtype,
    string $userLinkClass,
    string $groupLinkClass,
    string $fkField,
    array $technicians,
    array $groups
): int {
    global $DB;
    $table = $itemtype::getTable();
    $ids = array_column(
        iterator_to_array($DB->request([
            'SELECT' => ['id'],
            'FROM'   => $table,
            'WHERE'  => [
                'name'   => ['LIKE', '[Bulk1000]%'],
                'status' => ['!=', CommonITILObject::INCOMING],
            ],
        ])),
        'id'
    );

    $count = 0;
    foreach ($ids as $i => $id) {
        $tech = $technicians[$i % count($technicians)];
        $group = $groups[$i % count($groups)];

        $userLink = new $userLinkClass();
        $userLink->add([
            $fkField            => $id,
            'users_id'          => $tech,
            'type'              => CommonITILActor::ASSIGN,
            'use_notification'  => 0,
        ]);

        $groupLink = new $groupLinkClass();
        $groupLink->add([
            $fkField    => $id,
            'groups_id' => $group,
            'type'      => CommonITILActor::ASSIGN,
        ]);

        $count++;
    }
    return $count;
}

echo "Assigning tickets...\n";
$n = assignBulk1000('Ticket', 'Ticket_User', 'Group_Ticket', 'tickets_id', $technicians, $groups);
echo "  assigned $n tickets\n";

echo "Assigning changes...\n";
$n = assignBulk1000('Change', 'Change_User', 'Change_Group', 'changes_id', $technicians, $groups);
echo "  assigned $n changes\n";

echo "Assigning problems...\n";
$n = assignBulk1000('Problem', 'Problem_User', 'Group_Problem', 'problems_id', $technicians, $groups);
echo "  assigned $n problems\n";

echo "Done.\n";
