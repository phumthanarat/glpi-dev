<?php

/**
 * Step 12+13 of the ITSM rollout plan: IT Manager Dashboard + KPIs.
 *
 * Built entirely from GLPI's stock dashboard cards (confirmed working -
 * these are the exact card_id/provider values used by GLPI's own
 * built-in "Assistance" dashboard, read directly from
 * glpi_dashboards_items on this install). No custom PHP provider code
 * needed for most of the plan's requested breakdowns.
 *
 * Covered natively:
 *   Open/Pending/Overdue/Resolved counts  -> bn_count_tickets_* cards
 *   By Agent                              -> top_ticket_user_assign
 *   By Team                               -> top_ticket_group_assign
 *   By Category                           -> top_ticket_ITILCategory
 *   By Department (Entity)                -> top_ticket_Entity
 *   SLA compliance (on-time vs late)      -> bn_count_tickets_expired_by_tech(_group)
 *   Avg Resolution Time (trend)           -> ticket_times
 *   Volume by week/month                  -> ticket_evolution
 *
 * NOT natively available (flagged during the original feasibility
 * review, not addressed here - would need a custom PHP dashboard
 * provider class, out of scope for a config script):
 *   - "SLA Compliance %" as a single number (only on-time/late counts
 *     exist as stock cards, per technician/group)
 *   - "Reopen Rate" (no stock metric; would need to query ticket
 *     status-change history)
 *
 * Run inside the app container:
 *   php customizations/setup-09-dashboard.php
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

// GLPI's sample dashboards off: only the real ones (incl. "IT Helpdesk KPI" below)
Config::setConfigurationValues('core', ['is_demo_dashboards' => 0]);

function card(string $card_id, int $x, int $y, int $w, int $h, string $widgettype, string $color): array
{
    return [
        'x' => $x, 'y' => $y, 'width' => $w, 'height' => $h,
        'gridstack_id' => $card_id . '_' . bin2hex(random_bytes(8)),
        'card_id' => $card_id,
        'card_options' => [
            'color' => $color,
            'widgettype' => $widgettype,
            'use_gradient' => '0',
            'limit' => '7',
        ],
    ];
}

$items = [
    // Row 0: headline counts
    card('bn_count_tickets_incoming', 0, 0, 3, 2, 'bigNumber', '#a0e19d'),
    card('bn_count_tickets_waiting',  3, 0, 3, 2, 'bigNumber', '#ffcb7d'),
    card('bn_count_tickets_late',     6, 0, 3, 2, 'bigNumber', '#f8911f'),
    card('bn_count_tickets_solved',   9, 0, 3, 2, 'bigNumber', '#515151'),

    // Row 1: by Team / by Agent
    card('top_ticket_group_assign', 0, 2, 6, 3, 'donut', '#cae3c4'),
    card('top_ticket_user_assign',  6, 2, 6, 3, 'donut', '#eaf5f7'),

    // Row 2: by Category / by Department
    card('top_ticket_ITILCategory', 0, 5, 6, 3, 'donut', '#f1f5ef'),
    card('top_ticket_Entity',       6, 5, 6, 3, 'donut', '#f7f1f0'),

    // Row 3: SLA compliance by Team / by Agent
    card('bn_count_tickets_expired_by_tech_group', 0, 8, 6, 3, 'hBars', '#f0967b'),
    card('bn_count_tickets_expired_by_tech',        6, 8, 6, 3, 'hBars', '#ffdc64'),

    // Row 4: trends
    card('ticket_times',    0, 11, 6, 3, 'areas', '#f3f7f8'),
    card('ticket_evolution', 6, 11, 6, 3, 'areas', '#f3f7f8'),
    // Row 14: open Changes (setup-16 then adds the IT Chat cards below it)
    card('bn_count_Change', 0, 14, 3, 2, 'bigNumber', '#0d9488'),
];

$dashboard = new Glpi\Dashboard\Dashboard();
if ($dashboard->getFromDB('it-helpdesk-kpi')) {
    echo "Dashboard 'it-helpdesk-kpi' already exists, overwriting its items.\n";
    $dashboard->saveItems($items);
} else {
    $key = $dashboard->saveNew('IT Helpdesk KPI', 'core', $items);
    echo "Dashboard created: key=$key\n";
}

echo "Done. View it under Assistance > Dashboard (or the dashboard picker) as 'IT Helpdesk KPI'.\n";
