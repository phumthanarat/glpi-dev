<?php

/**
 * Business rule: on ticket creation, put the requester's *default group* on the ticket as
 * requester group (GLPI's native "Default group from user" action, no code).
 *
 * Why: the approval rule from setup-08 ("Request type requires manager approval") sends
 * the approval to the manager of the ticket's *requester group*, but GLPI never adds that
 * group by itself. Without this rule, Requests filed from the Helpdesk / e-mail skipped
 * approval entirely (found by customizations/itchat-dev/tests/test_tickets.py). Tickets
 * created from IT Chat already set the group in the plugin.
 *
 * Only takes effect for users whose default group is set (Administration > Users >
 * user > "Default group", or by LDAP sync). The rule is placed right before the approval
 * rule. Idempotent: an existing rule with this name is left alone.
 *
 * Run inside the app container:
 *   php customizations/setup-17-requester-group-rule.php
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

$name = 'Requester group: default group of the requester';
$rule = new RuleTicket();
if ($rule->getFromDBByCrit(['name' => $name, 'sub_type' => RuleTicket::class])) {
    echo "Rule #{$rule->getID()} '$name' already exists, skipping\n";
    exit(0);
}

$rules_id = $rule->add([
    'name'        => $name,
    'sub_type'    => RuleTicket::class,
    'match'       => 'AND',
    'is_active'   => 1,
    'entities_id' => 0,
    'is_recursive' => 1,
    'condition'   => RuleTicket::ONADD,
    'description' => 'Adds the requester\'s default group as requester group, so the manager-approval rule for Requests can find an approver.',
]);
if (!$rules_id) {
    fwrite(STDERR, "Could not create the rule\n");
    exit(1);
}
(new RuleCriteria())->add([
    'rules_id'  => $rules_id,
    'criteria'  => '_users_id_requester',
    'condition' => Rule::PATTERN_EXISTS,
    'pattern'   => 1,
]);
(new RuleAction())->add([
    'rules_id'    => $rules_id,
    'action_type' => 'defaultfromuser',
    'field'       => '_groups_id_requester',
    'value'       => 1,
]);
echo "Rule #$rules_id created: $name\n";

// Place it just before the approval rule, for readability of the rule list.
$approval = new RuleTicket();
if ($approval->getFromDBByCrit(['name' => 'Approval: Request type requires manager approval', 'sub_type' => RuleTicket::class])) {
    (new RuleTicketCollection())->moveRule($rules_id, $approval->getID(), RuleCollection::MOVE_BEFORE);
    echo "Moved before rule #{$approval->getID()} (approval)\n";
}

echo "Done. Remember: users need a default group (Administration > Users) for this to apply.\n";
