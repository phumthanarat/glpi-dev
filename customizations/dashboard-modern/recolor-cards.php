<?php

/**
 * Re-colors every card in every GLPI dashboard (Central, Assets,
 * Assistance, Mini tickets, IT Helpdesk KPI) with one coordinated
 * palette instead of GLPI's default mismatched pastel assortment —
 * part of the "modernize dashboards" pass alongside
 * dashboard-override.css.
 *
 * Ticket-status cards get a semantic color (late=red, waiting=amber,
 * solved=green, etc.); everything else cycles deterministically
 * through the brand palette by card_id so re-running this produces the
 * same result.
 *
 * Only touches the `color` key inside each row's `card_options` JSON —
 * widgettype/limit/use_gradient and everything else is left as-is.
 *
 * Run inside the app container:
 *   php customizations/dashboard-modern/recolor-cards.php
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

global $DB;

// Brand-coordinated palette (blue/teal primary, semantic accents).
$CYCLE = ['#2563eb', '#0d9488', '#0891b2', '#6366f1', '#64748b', '#f59e0b'];

$SEMANTIC = [
    'bn_count_tickets_late'                  => '#ef4444', // overdue — red
    'bn_count_tickets_expired_by_tech'       => '#ef4444',
    'bn_count_tickets_expired_by_tech_group' => '#ef4444',
    'bn_count_tickets_waiting'               => '#f59e0b', // pending — amber
    'bn_count_tickets_incoming'              => '#2563eb', // new — brand blue
    'bn_count_tickets_assigned'              => '#0891b2', // in progress — cyan
    'bn_count_tickets_planned'               => '#6366f1', // scheduled — indigo
    'bn_count_tickets_solved'                => '#22c55e', // done — green
    'bn_count_tickets_closed'                => '#64748b', // archived — slate
    'bn_count_Ticket'                        => '#2563eb',
    'bn_count_Problem'                       => '#f59e0b',
    'bn_count_Change'                        => '#0d9488',
    'bn_count_TicketRecurrent'               => '#6366f1',
];

$rows = iterator_to_array($DB->request(['FROM' => 'glpi_dashboards_items']));

$card_ids = array_values(array_unique(array_column($rows, 'card_id')));
sort($card_ids);
$cycle_index = array_flip($card_ids);

function colorFor(string $card_id, array $cycle_index, array $cycle, array $semantic): string
{
    if (isset($semantic[$card_id])) {
        return $semantic[$card_id];
    }
    $i = $cycle_index[$card_id] ?? 0;
    return $cycle[$i % count($cycle)];
}

$updated = 0;
foreach ($rows as $row) {
    $opts = json_decode($row['card_options'] ?? '{}', true) ?: [];
    $new_color = colorFor($row['card_id'], $cycle_index, $CYCLE, $SEMANTIC);
    if (($opts['color'] ?? null) === $new_color) {
        continue;
    }
    $opts['color'] = $new_color;
    $DB->update('glpi_dashboards_items', ['card_options' => json_encode($opts)], ['id' => $row['id']]);
    $updated++;
}

echo "Recolored $updated of " . count($rows) . " dashboard cards across all dashboards.\n";
