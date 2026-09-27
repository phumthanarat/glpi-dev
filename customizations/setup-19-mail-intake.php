<?php

/**
 * E-mail channel: e-mails sent to the IT mailbox become tickets, replies become followups.
 *
 * Uses GLPI's own Mail Receiver (Setup > Receivers) - no code:
 *   1. a Mail Receiver on the mailbox from the `mail-intake` Secret
 *      (local-dev: the greenmail test mailbox, k8s/overlays/local-dev/greenmail-*.yaml;
 *      production: the real IT mailbox, same Secret name)
 *   2. the `mailgate` cron task every 2 minutes (default 10), matching the glpi-cron CronJob,
 *      up to 100 e-mails per run (default 10; measured ~0.6 s per e-mail)
 *   3. a Business rule: e-mail tickets arrive without a category, so none of the
 *      category -> team rules from setup-05 apply; this sends those to Helpdesk / Service Desk
 *
 * Kept as GLPI ships it, on purpose:
 *   - only senders that match a GLPI user's e-mail are accepted (use_anonymous_helpdesk = 0);
 *     unknown senders are refused, so the mailbox can't be used to spam the helpdesk
 *   - the mail-collector rules (GLPI notifications / auto-replies are ignored, entity = Root)
 *   - replies are matched to their ticket by the "[GLPI #0000123]" tag GLPI puts in the
 *     subject of its notifications
 *
 * Idempotent: the receiver is updated in place (found by name), the rule is created once.
 *
 * Run inside the app container, with the Secret's values in the environment:
 *   MAIL_INTAKE_HOST=... MAIL_INTAKE_LOGIN=... MAIL_INTAKE_PASSWORD=... \
 *     php customizations/setup-19-mail-intake.php
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

$env = [];
foreach (['MAIL_INTAKE_HOST', 'MAIL_INTAKE_LOGIN', 'MAIL_INTAKE_PASSWORD'] as $key) {
    $env[$key] = (string) getenv($key);
    if ($env[$key] === '') {
        fwrite(STDERR, "$key is not set (take it from the mail-intake Secret)\n");
        exit(1);
    }
}

// 1. Mail Receiver
$name = 'IT Support mailbox';
$collector = new MailCollector();
$input = [
    'name'                   => $name,
    'host'                   => $env['MAIL_INTAKE_HOST'],
    'login'                  => $env['MAIL_INTAKE_LOGIN'],
    'passwd'                 => $env['MAIL_INTAKE_PASSWORD'],
    'is_active'              => 1,
    'requester_field'        => MailCollector::REQUESTER_FIELD_FROM,
    'add_to_to_observer'     => 0,
    'add_cc_to_observer'     => 1,
    'collect_only_unread'    => 1,
    'create_user_from_email' => 0,
    'use_mail_date'          => 0,
    'comment'                => 'E-mails to the IT mailbox become tickets; replies become followups. Managed by customizations/setup-19-mail-intake.php',
];
if ($collector->getFromDBByCrit(['name' => $name])) {
    $collector->update(['id' => $collector->getID()] + $input);
    echo "Mail Receiver #{$collector->getID()} '$name' updated\n";
} else {
    $id = $collector->add($input);
    if (!$id) {
        fwrite(STDERR, "Could not create the Mail Receiver\n");
        exit(1);
    }
    $collector->getFromDB($id);
    echo "Mail Receiver #$id '$name' created\n";
}

// Prove the credentials work now rather than at the first cron run.
try {
    $collector->connect();
    echo "Connected to {$env['MAIL_INTAKE_HOST']} as {$env['MAIL_INTAKE_LOGIN']}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect to the mailbox: {$e->getMessage()}\n");
    exit(1);
}

// 2. mailgate cron every 2 minutes
$cron = new CronTask();
if ($cron->getFromDBbyName(MailCollector::class, 'mailgate')) {
    $cron->update([
        'id'        => $cron->getID(),
        'state'     => CronTask::STATE_WAITING,
        'mode'      => CronTask::MODE_EXTERNAL,
        'frequency' => 2 * MINUTE_TIMESTAMP,
        // e-mails per run (GLPI default 10 = ~300/hour). 100 = ~3,000/hour; one run of 100
        // takes ~60 s, inside the glpi-cron CronJob's activeDeadlineSeconds (k8s/base/cronjob.yaml)
        'param'     => 100,
    ]);
    echo "Cron task mailgate: every 2 minutes, up to 100 e-mails per run, run by the glpi-cron CronJob\n";
}

// 3. Business rule: e-mail tickets without a team -> Helpdesk / Service Desk
$rule_name = 'Auto-assign: E-mail tickets without a team -> Helpdesk';
$rule = new RuleTicket();
if ($rule->getFromDBByCrit(['name' => $rule_name, 'sub_type' => RuleTicket::class])) {
    echo "Rule #{$rule->getID()} '$rule_name' already exists, skipping\n";
} else {
    $group = new Group();
    if (!$group->getFromDBByCrit(['name' => 'Helpdesk / Service Desk'])) {
        fwrite(STDERR, "Group 'Helpdesk / Service Desk' not found (run setup-02 first)\n");
        exit(1);
    }
    $rules_id = $rule->add([
        'name'         => $rule_name,
        'sub_type'     => RuleTicket::class,
        'match'        => 'AND',
        'is_active'    => 1,
        'entities_id'  => 0,
        'is_recursive' => 1,
        'condition'    => RuleTicket::ONADD,
        'description'  => 'E-mail tickets have no category, so the category -> team rules never match them.',
    ]);
    (new RuleCriteria())->add([
        'rules_id'  => $rules_id,
        'criteria'  => 'requesttypes_id',
        'condition' => Rule::PATTERN_IS,
        'pattern'   => RequestType::getDefault('mail'),
    ]);
    (new RuleCriteria())->add([
        'rules_id'  => $rules_id,
        'criteria'  => '_groups_id_assign',
        'condition' => Rule::PATTERN_DOES_NOT_EXISTS,
        'pattern'   => 1,
    ]);
    (new RuleAction())->add([
        'rules_id'    => $rules_id,
        'action_type' => 'assign',
        'field'       => '_groups_id_assign',
        'value'       => $group->getID(),
    ]);
    echo "Rule #$rules_id created: $rule_name\n";
}

echo "Done. Senders must have their address on their GLPI user (Administration > Users > Emails).\n";
