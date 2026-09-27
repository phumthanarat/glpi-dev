<?php

/**
 * Step 7 of the ITSM rollout plan: SLA Escalation.
 *
 * GLPI does not model escalation as "% of SLA duration" - SlaLevel rows
 * fire at a fixed offset (seconds) before/after the ticket's due date,
 * so each SLA's 50%/75%/90% points are pre-computed here from its own
 * TTR duration and written as absolute execution_time values.
 *
 *   Critical TTR=2h(7200s):   50%=-3600s   75%=-1800s   90%=-720s
 *   High     TTR=4h(14400s):  50%=-7200s   75%=-3600s   90%=-1440s
 *   Medium   TTR=8h(28800s):  50%=-14400s  75%=-7200s   90%=-2880s
 *   Low      TTR=3d(259200s): 50%=-129600s 75%=-64800s  90%=-25920s
 *
 * Each SlaLevel is itself a RuleTicket subtype (LevelAgreementLevel
 * extends RuleTicket) whose special 'recall' action triggers GLPI's
 * built-in "Automatic reminders of SLA" notification (Setup >
 * Notifications > Ticket, event "recall") - that's what actually sends
 * mail, not a bespoke action here.
 *
 * The plan's original 3 targets (Agent / Team Leader / IT Manager) only
 * partially map onto what exists so far:
 *   50% -> recall only. Goes to whoever the "recall" NotificationTemplate
 *          targets by default (assigned technician + assigned group) -
 *          i.e. the Agent.
 *   75% -> recall + append the "IT Manager" group as an observer on the
 *          ticket. There is no separate "Team Leader" group yet (see
 *          setup-02-groups.php) so this step approximates that tier by
 *          looping in IT Manager one level early instead.
 *   90% -> recall again. IT Manager is already an observer from the 75%
 *          level by this point, so they receive it too - the "notify IT
 *          Manager" tier.
 *   100% (breach) is not a level at all - it's the due date itself
 *          passing, which GLPI flags natively (ticket "late" status,
 *          the existing "Tickets by SLA status" dashboard widget) -
 *          nothing to create here.
 *
 * Actual differentiated message wording per level (step 8: Notification)
 * is a separate task - this step only wires up *when* and *to whom*.
 *
 * Run inside the app container:
 *   php customizations/setup-06-escalation.php
 */

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

function slaId(string $name): int
{
    $sla = new SLA();
    if (!$sla->getFromDBByCrit(['name' => $name])) {
        fwrite(STDERR, "SLA '$name' not found - run setup-04-sla.php first.\n");
        exit(1);
    }
    return (int) $sla->getID();
}

function groupId(string $name): int
{
    $group = new Group();
    if (!$group->getFromDBByCrit(['name' => $name])) {
        fwrite(STDERR, "Group '$name' not found - run setup-02-groups.php first.\n");
        exit(1);
    }
    return (int) $group->getID();
}

function ensureSlaLevel(string $name, int $slas_id, int $execution_time, array $actions): void
{
    $level = new SlaLevel();
    if ($level->getFromDBByCrit(['name' => $name, 'slas_id' => $slas_id])) {
        echo "SlaLevel #{$level->getID()} already exists: $name, skipping\n";
        return;
    }

    $id = $level->add([
        'name'           => $name,
        'slas_id'        => $slas_id,
        'execution_time' => $execution_time,
        'is_active'      => 1,
        'entities_id'    => 0,
        'is_recursive'   => 1,
        'match'          => Rule::AND_MATCHING,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create SlaLevel '$name': " . $DB->error() . "\n");
        exit(1);
    }

    $ra = new SlaLevelAction();
    foreach ($actions as $a) {
        $ra->add([
            'slalevels_id' => $id,
            'action_type'  => $a['action_type'],
            'field'        => $a['field'],
            'value'        => $a['value'],
        ]);
    }

    echo "SlaLevel #$id created: $name (execution_time={$execution_time}s, " . count($actions) . " actions)\n";
}

$it_manager_group = groupId('IT Manager');

$plan = [
    'Critical - TTR' => 7200,
    'High - TTR'     => 14400,
    'Medium - TTR'   => 28800,
    'Low - TTR'      => 259200,
];

foreach ($plan as $sla_name => $total_seconds) {
    $slas_id = slaId($sla_name);
    $priority_label = str_replace(' - TTR', '', $sla_name);

    ensureSlaLevel(
        "$priority_label 50% (notify Agent)",
        $slas_id,
        -(int) round($total_seconds * 0.50),
        [['action_type' => 'send', 'field' => 'recall', 'value' => 1]]
    );

    ensureSlaLevel(
        "$priority_label 75% (notify Team Leader / IT Manager early)",
        $slas_id,
        -(int) round($total_seconds * 0.25),
        [
            ['action_type' => 'send', 'field' => 'recall', 'value' => 1],
            ['action_type' => 'assign', 'field' => '_groups_id_observer', 'value' => $it_manager_group],
        ]
    );

    ensureSlaLevel(
        "$priority_label 90% (notify IT Manager)",
        $slas_id,
        -(int) round($total_seconds * 0.10),
        [['action_type' => 'send', 'field' => 'recall', 'value' => 1]]
    );
}

echo "Done.\n";
