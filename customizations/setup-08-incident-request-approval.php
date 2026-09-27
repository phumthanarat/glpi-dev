<?php

/**
 * Step 11 of the ITSM rollout plan: separate Incident vs Request, with
 * Request needing manager approval before IT acts on it.
 *
 * GLPI's Ticket already natively distinguishes the two via its `type`
 * field (Ticket::INCIDENT_TYPE=1, Ticket::DEMAND_TYPE=2, chosen per
 * ticket on the form/portal) - nothing to build for that part.
 *
 * What's missing is the approval step. This adds one Business Rule:
 *
 *   IF   ticket.type = Request
 *   THEN send an approval request to the requester's group manager
 *        (GLPI's native "requester group manager" validation target -
 *        resolves per-ticket to whoever has is_manager=1 in the
 *        requester's group, no fixed approver hardcoded here)
 *
 * A ticket then can't move forward as "IT ดำเนินการ" until that
 * TicketValidation is approved - this is GLPI's built-in approval
 * workflow (TicketValidation), not a bespoke one.
 *
 * Run inside the app container:
 *   php customizations/setup-08-incident-request-approval.php
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

$name = 'Approval: Request type requires manager approval';

$rule = new RuleTicket();
if ($rule->getFromDBByCrit(['name' => $name, 'sub_type' => 'RuleTicket'])) {
    echo "Rule #{$rule->getID()} already exists: $name, skipping\n";
    exit(0);
}

$rules_id = $rule->add([
    'name'         => $name,
    'sub_type'     => 'RuleTicket',
    'entities_id'  => 0,
    'is_recursive' => 1,
    'is_active'    => 1,
    'match'        => Rule::AND_MATCHING,
    'condition'    => RuleTicket::ONADD,
]);
if (!$rules_id) {
    global $DB;
    fwrite(STDERR, "Failed to create rule: " . $DB->error() . "\n");
    exit(1);
}

(new RuleCriteria())->add([
    'rules_id'  => $rules_id,
    'criteria'  => 'type',
    'condition' => Rule::PATTERN_IS,
    'pattern'   => Ticket::DEMAND_TYPE,
]);

(new RuleAction())->add([
    'rules_id'    => $rules_id,
    'action_type' => 'add_validation',
    'field'       => 'users_id_validate_requester_supervisor',
    'value'       => 1,
]);

echo "Rule #$rules_id created: $name\n";
echo "Done.\n";
