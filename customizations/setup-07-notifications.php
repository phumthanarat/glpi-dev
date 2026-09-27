<?php

/**
 * Step 8 of the ITSM rollout plan: Notifications.
 *
 * GLPI already ships active default notifications for most of the
 * events the plan lists (audited against glpi_notifications):
 *
 *   New Ticket          -> event=new            already active
 *   Ticket Assigned      -> assign_user/assign_group  already active
 *   Ticket Follow-up      -> add_followup/update_followup  already active
 *   Ticket Solved        -> event=solved         already active
 *   Ticket Closed        -> event=closed         already active
 *   SLA Approaching      -> event=recall         already active (used by step 7)
 *   Ticket Status Changed -> event=update         WAS INACTIVE - this
 *                            script activates it (generic "ticket
 *                            updated" event, covers status changes)
 *   SLA Breached         -> no such event exists natively; GLPI only
 *                            flags overdue tickets ("late") and reports
 *                            them in dashboards. Approximated here the
 *                            same way step 7 built escalation: one more
 *                            SlaLevel per SLA at execution_time=0 (the
 *                            due date itself) that fires the same
 *                            'recall' notification - a real "you have
 *                            just breached SLA" alert, not a native
 *                            distinct event.
 *
 * The default "Ticket Recall" notification (id=24) uses GLPI's big
 * shared generic template (id=4, used by many other events too) - it's
 * not SLA-specific and editing it in place would change every other
 * event that reuses it. Instead this script creates a NEW dedicated
 * "SLA Escalation Alert" template (short, SLA-focused, matching the
 * plan's example email) and re-points the Recall notification at it.
 *
 * Run inside the app container:
 *   php customizations/setup-07-notifications.php
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

// ------------------------------------------------------------
// 1) Enable "Ticket Status Changed" (event=update, was inactive)
// ------------------------------------------------------------
$notif = new Notification();
if ($notif->getFromDBByCrit(['itemtype' => 'Ticket', 'event' => 'update'])) {
    if ((int) $notif->fields['is_active'] === 1) {
        echo "Notification 'update' already active, skipping\n";
    } else {
        $notif->update(['id' => $notif->getID(), 'is_active' => 1]);
        echo "Notification #{$notif->getID()} 'update' (Ticket Status Changed) activated\n";
    }
} else {
    fwrite(STDERR, "Notification event 'update' for Ticket not found - unexpected, check GLPI version.\n");
}

// ------------------------------------------------------------
// 2) SLA Breach level (execution_time=0) per TTR SLA
// ------------------------------------------------------------
function slaId(string $name): int
{
    $sla = new SLA();
    if (!$sla->getFromDBByCrit(['name' => $name])) {
        fwrite(STDERR, "SLA '$name' not found - run setup-04-sla.php first.\n");
        exit(1);
    }
    return (int) $sla->getID();
}

foreach (['Critical - TTR', 'High - TTR', 'Medium - TTR', 'Low - TTR'] as $sla_name) {
    $slas_id = slaId($sla_name);
    $priority_label = str_replace(' - TTR', '', $sla_name);
    $name = "$priority_label Breach (100%, SLA due)";

    $level = new SlaLevel();
    if ($level->getFromDBByCrit(['name' => $name, 'slas_id' => $slas_id])) {
        echo "SlaLevel #{$level->getID()} already exists: $name, skipping\n";
        continue;
    }

    $id = $level->add([
        'name'           => $name,
        'slas_id'        => $slas_id,
        'execution_time' => 0,
        'is_active'      => 1,
        'entities_id'    => 0,
        'is_recursive'   => 1,
        'match'          => Rule::AND_MATCHING,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create SlaLevel '$name': " . $DB->error() . "\n");
        continue;
    }
    (new SlaLevelAction())->add([
        'slalevels_id' => $id,
        'action_type'  => 'send',
        'field'        => 'recall',
        'value'        => 1,
    ]);
    echo "SlaLevel #$id created: $name (execution_time=0s, breach alert)\n";
}

// ------------------------------------------------------------
// 3) Dedicated "SLA Escalation Alert" template, matching the plan's
//    example email format, re-pointing the Recall notification at it.
// ------------------------------------------------------------
$template = new NotificationTemplate();
if ($template->getFromDBByCrit(['name' => 'SLA Escalation Alert', 'itemtype' => 'Ticket'])) {
    $templates_id = (int) $template->getID();
    echo "NotificationTemplate #$templates_id 'SLA Escalation Alert' already exists, reusing.\n";
} else {
    $templates_id = $template->add([
        'name'     => 'SLA Escalation Alert',
        'itemtype' => 'Ticket',
        'comment'  => 'SLA-focused alert used by the "Ticket Recall" notification (escalation levels + breach), see setup-06/07.',
    ]);
    if (!$templates_id) {
        global $DB;
        fwrite(STDERR, "Failed to create NotificationTemplate: " . $DB->error() . "\n");
        exit(1);
    }
    echo "NotificationTemplate #$templates_id created: SLA Escalation Alert\n";

    $subject = '[GLPI] SLA Warning - Ticket ##ticket.id##';
    $content = <<<'TXT'
Ticket: ##ticket.id##
Title: ##ticket.title##

Priority: ##ticket.priority##
Category: ##ticket.category##
Assigned to: ##ticket.assigntogroups## ##ticket.assigntousers##

SLA Deadline (Time to resolve): ##ticket.duedate##

View ticket: ##ticket.url##
TXT;

    (new NotificationTemplateTranslation())->add([
        'notificationtemplates_id' => $templates_id,
        'language'                 => '',
        'subject'                  => $subject,
        'content_text'             => $content,
        'content_html'             => nl2br(htmlspecialchars($content)),
    ]);
    echo "  translation added (subject + content)\n";
}

// Re-point the "Ticket Recall" notification's mailing mode at the new template.
$link = new Notification_NotificationTemplate();
$recall = new Notification();
if (!$recall->getFromDBByCrit(['itemtype' => 'Ticket', 'event' => 'recall'])) {
    fwrite(STDERR, "Notification 'recall' not found - unexpected.\n");
    exit(1);
}
if ($link->getFromDBByCrit(['notifications_id' => $recall->getID(), 'mode' => 'mailing'])) {
    if ((int) $link->fields['notificationtemplates_id'] === $templates_id) {
        echo "Recall notification already points at the new template, skipping\n";
    } else {
        $link->update(['id' => $link->getID(), 'notificationtemplates_id' => $templates_id]);
        echo "Recall notification (#{$recall->getID()}) re-pointed to template #$templates_id\n";
    }
} else {
    fwrite(STDERR, "Notification_NotificationTemplate link for recall/mailing not found - unexpected.\n");
}

echo "Done.\n";
