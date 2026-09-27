<?php

/**
 * Step 5+6 of the ITSM rollout plan: Business Rules for Tickets.
 *
 * GLPI has one rule engine for both jobs (RuleTicket) - there is no
 * separate "Assignment Rule" vs "SLA Rule" system, they're the same
 * object with different actions:
 *
 *   5) Auto-assign by Category -> Technician group
 *   6) Auto-assign SLA (TTO+TTR) by Priority
 *
 * Category -> Group mapping. Note: the original category tree (step 3)
 * has no top-level "Application" category - "Application" only exists
 * as a leaf under Software. Kept as-is here (matches the plan's intent
 * literally); Hardware/Software/Account fall through to Helpdesk as the
 * first-line team, since the plan never assigned them elsewhere.
 *
 * Rule order matters: GLPI runs rules in ranking order (creation order,
 * by default) and a later rule's "assign" action overwrites an earlier
 * one's for the same field. Since "Application" is a child of
 * "Software", a ticket in that category matches both the specific
 * Application rule AND the general Hardware/Software/Account catch-all
 * - so the catch-all MUST be created first (lower ranking, runs first)
 * and the specific Application rule created after it (higher ranking,
 * runs last and wins). Don't reorder these two without re-verifying.
 *
 *   Network (+children)      -> Network Team
 *   Server (+children)       -> System Team
 *   Application (leaf)       -> Application Team
 *   Hardware/Software/Account (+children) -> Helpdesk / Service Desk
 *
 * Priority -> SLA mapping (GLPI has 6 native priority levels, the plan's
 * table has 4 - mapped as the closest reasonable fit):
 *
 *   6 Major, 5 Very high  -> Critical SLA
 *   4 High                -> High SLA
 *   3 Medium              -> Medium SLA
 *   2 Low, 1 Very low     -> Low SLA
 *
 * Run inside the app container:
 *   php customizations/setup-05-business-rules.php
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

function categoryId(string $name): int
{
    $cat = new ITILCategory();
    if (!$cat->getFromDBByCrit(['name' => $name])) {
        fwrite(STDERR, "Category '$name' not found - run setup-03-categories.php first.\n");
        exit(1);
    }
    return (int) $cat->getID();
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

function slaId(string $name): int
{
    $sla = new SLA();
    if (!$sla->getFromDBByCrit(['name' => $name])) {
        fwrite(STDERR, "SLA '$name' not found - run setup-04-sla.php first.\n");
        exit(1);
    }
    return (int) $sla->getID();
}

/**
 * Creates (or reuses, by name) a RuleTicket with the given criteria and
 * actions. $criteria/$actions are lists of ['criteria'|'field' => ...,
 * 'condition'|'action_type' => ..., 'pattern'|'value' => ...].
 */
function ensureRule(string $name, string $match, array $criteria, array $actions): void
{
    $rule = new RuleTicket();
    if ($rule->getFromDBByCrit(['name' => $name, 'sub_type' => 'RuleTicket'])) {
        echo "Rule #{$rule->getID()} already exists: $name, skipping\n";
        return;
    }

    $rules_id = $rule->add([
        'name'        => $name,
        'sub_type'    => 'RuleTicket',
        'entities_id' => 0,
        'is_recursive' => 1,
        'is_active'   => 1,
        'match'       => $match,
        'condition'   => RuleTicket::ONADD | RuleTicket::ONUPDATE,
    ]);
    if (!$rules_id) {
        global $DB;
        fwrite(STDERR, "Failed to create rule '$name': " . $DB->error() . "\n");
        exit(1);
    }

    $rc = new RuleCriteria();
    foreach ($criteria as $c) {
        $rc->add([
            'rules_id'  => $rules_id,
            'criteria'  => $c['criteria'],
            'condition' => $c['condition'],
            'pattern'   => $c['pattern'],
        ]);
    }

    $ra = new RuleAction();
    foreach ($actions as $a) {
        $ra->add([
            'rules_id'    => $rules_id,
            'action_type' => $a['action_type'],
            'field'       => $a['field'],
            'value'       => $a['value'],
        ]);
    }

    echo "Rule #$rules_id created: $name (" . count($criteria) . " criteria, " . count($actions) . " actions)\n";
}

// ------------------------------------------------------------
// 5) Auto-assignment by Category
// ------------------------------------------------------------
ensureRule(
    'Auto-assign: Network -> Network Team',
    Rule::AND_MATCHING,
    [['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => categoryId('Network')]],
    [['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => groupId('Network Team')]]
);

ensureRule(
    'Auto-assign: Server -> System Team',
    Rule::AND_MATCHING,
    [['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => categoryId('Server')]],
    [['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => groupId('System Team')]]
);

// Catch-all created BEFORE the more specific Application rule below, so
// it gets a lower ranking and runs first - see the ordering note above.
ensureRule(
    'Auto-assign: Hardware/Software/Account -> Helpdesk',
    Rule::OR_MATCHING,
    [
        ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => categoryId('Hardware')],
        ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => categoryId('Software')],
        ['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_UNDER, 'pattern' => categoryId('Account')],
    ],
    [['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => groupId('Helpdesk / Service Desk')]]
);

ensureRule(
    'Auto-assign: Application -> Application Team',
    Rule::AND_MATCHING,
    [['criteria' => 'itilcategories_id', 'condition' => Rule::PATTERN_IS, 'pattern' => categoryId('Application')]],
    [['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => groupId('Application Team')]]
);

// ------------------------------------------------------------
// 6) SLA assignment by Priority
// ------------------------------------------------------------
ensureRule(
    'SLA: Critical priority (Major/Very high)',
    Rule::OR_MATCHING,
    [
        ['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 6],
        ['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 5],
    ],
    [
        ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => slaId('Critical - TTO')],
        ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => slaId('Critical - TTR')],
    ]
);

ensureRule(
    'SLA: High priority',
    Rule::AND_MATCHING,
    [['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 4]],
    [
        ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => slaId('High - TTO')],
        ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => slaId('High - TTR')],
    ]
);

ensureRule(
    'SLA: Medium priority',
    Rule::AND_MATCHING,
    [['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 3]],
    [
        ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => slaId('Medium - TTO')],
        ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => slaId('Medium - TTR')],
    ]
);

ensureRule(
    'SLA: Low priority (Low/Very low)',
    Rule::OR_MATCHING,
    [
        ['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 2],
        ['criteria' => 'priority', 'condition' => Rule::PATTERN_IS, 'pattern' => 1],
    ],
    [
        ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => slaId('Low - TTO')],
        ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => slaId('Low - TTR')],
    ]
);

echo "Done.\n";
