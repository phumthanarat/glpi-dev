<?php

/**
 * Sends a daily ticket/change summary to LINE (and optionally Slack/
 * Discord/Google Chat, same idea) — a scheduled *report push*, separate from
 * the event-triggered Webhooks in setup-12/13 (those fire per-ticket
 * on creation; this fires once/day with a rollup). Kept as its own
 * standalone script rather than reusing the glpi_webhooks table, since
 * GLPI's Webhook feature is itemtype+event triggered and doesn't have
 * a "on a schedule" trigger type — this is invoked by a k8s CronJob
 * instead (see ../../k8s/base/daily-summary-cronjob.yaml).
 *
 * Uses the same dashboard providers the "IT Helpdesk KPI" dashboard
 * itself uses (Glpi\Dashboard\Provider::nbTicketsGeneric) for
 * open/overdue counts, so the numbers in the message always match what
 * the dashboard shows — not a separately-written (and possibly
 * inconsistent) query.
 *
 * Placeholder tokens below — this does nothing until at least one is
 * filled in with a real value. Safe to run with none set: it just
 * prints the summary and skips sending.
 *
 * Run inside the app container (normally via the daily CronJob):
 *   php customizations/daily-summary/send-daily-summary.php
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

// ------------------------------------------------------------
// Channel config — fill in real values to activate. Any left as
// REPLACE_* / empty are simply skipped.
// ------------------------------------------------------------
$CHANNELS = [
    'line' => [
        'enabled' => false, // flip to true once a real token is set below
        'token'   => 'REPLACE_WITH_LINE_NOTIFY_TOKEN',
    ],
    'slack' => [
        'enabled' => false,
        'webhook_url' => 'https://hooks.slack.com/services/REPLACE_WITH_SLACK_WEBHOOK_PATH',
    ],
    'discord' => [
        'enabled' => false,
        'webhook_url' => 'https://discord.com/api/webhooks/REPLACE_WITH_WEBHOOK_ID/REPLACE_WITH_WEBHOOK_TOKEN',
    ],
    'google_chat' => [
        'enabled' => false, // Google Workspace only; see setup-14-google-chat-webhook.php for how to get the URL
        'webhook_url' => 'https://chat.googleapis.com/v1/spaces/REPLACE_WITH_SPACE_ID/messages?key=REPLACE_WITH_KEY&token=REPLACE_WITH_TOKEN',
    ],
];

// ------------------------------------------------------------
// Gather today's numbers
// ------------------------------------------------------------
global $DB;
$today = date('Y-m-d');

$tickets_today = $DB->request([
    'SELECT' => [new \Glpi\DBAL\QueryExpression('COUNT(*) as c')],
    'FROM'   => 'glpi_tickets',
    'WHERE'  => [new \Glpi\DBAL\QueryExpression('DATE(date_creation) = ' . $DB->quoteValue($today))],
])->current()['c'] ?? 0;

$changes_today = $DB->request([
    'SELECT' => [new \Glpi\DBAL\QueryExpression('COUNT(*) as c')],
    'FROM'   => 'glpi_changes',
    'WHERE'  => [new \Glpi\DBAL\QueryExpression('DATE(date_creation) = ' . $DB->quoteValue($today))],
])->current()['c'] ?? 0;

// Same providers the dashboard's own cards use, so these numbers agree
// with what's on screen.
$open    = \Glpi\Dashboard\Provider::nbTicketsGeneric('notold', ['validation_check_user' => true])['number'] ?? 0;
$overdue = \Glpi\Dashboard\Provider::nbTicketsGeneric('late', ['validation_check_user' => true])['number'] ?? 0;
$solved  = \Glpi\Dashboard\Provider::nbTicketsGeneric('solved', ['validation_check_user' => true])['number'] ?? 0;

$message = "📊 GLPI Daily Summary - $today\n"
    . "Tickets opened today: $tickets_today\n"
    . "Changes opened today: $changes_today\n"
    . "Currently open tickets: $open\n"
    . "Overdue (SLA late): $overdue\n"
    . "Solved (all-time): $solved";

echo "$message\n\n";

// ------------------------------------------------------------
// Send
// ------------------------------------------------------------
function postJson(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $response, 'error' => $err];
}

$sent_any = false;

if ($CHANNELS['line']['enabled'] && $CHANNELS['line']['token'] !== 'REPLACE_WITH_LINE_NOTIFY_TOKEN') {
    $res = postJson(
        'https://notify-api.line.me/api/notify',
        [
            'Authorization: Bearer ' . $CHANNELS['line']['token'],
            'Content-Type: application/x-www-form-urlencoded',
        ],
        'message=' . urlencode("\n" . $message)
    );
    echo "LINE: HTTP {$res['code']}" . ($res['error'] ? " (curl error: {$res['error']})" : '') . "\n";
    $sent_any = true;
}

if ($CHANNELS['slack']['enabled'] && !str_contains($CHANNELS['slack']['webhook_url'], 'REPLACE_WITH')) {
    $res = postJson(
        $CHANNELS['slack']['webhook_url'],
        ['Content-Type: application/json'],
        json_encode(['text' => $message])
    );
    echo "Slack: HTTP {$res['code']}" . ($res['error'] ? " (curl error: {$res['error']})" : '') . "\n";
    $sent_any = true;
}

if ($CHANNELS['discord']['enabled'] && !str_contains($CHANNELS['discord']['webhook_url'], 'REPLACE_WITH')) {
    $res = postJson(
        $CHANNELS['discord']['webhook_url'],
        ['Content-Type: application/json'],
        json_encode(['content' => $message])
    );
    echo "Discord: HTTP {$res['code']}" . ($res['error'] ? " (curl error: {$res['error']})" : '') . "\n";
    $sent_any = true;
}

if ($CHANNELS['google_chat']['enabled'] && !str_contains($CHANNELS['google_chat']['webhook_url'], 'REPLACE_WITH')) {
    $res = postJson(
        $CHANNELS['google_chat']['webhook_url'],
        ['Content-Type: application/json; charset=UTF-8'],
        json_encode(['text' => $message])
    );
    echo "Google Chat: HTTP {$res['code']}" . ($res['error'] ? " (curl error: {$res['error']})" : '') . "\n";
    $sent_any = true;
}

if (!$sent_any) {
    echo "No channel enabled/configured — summary printed above only, nothing sent.\n";
    echo "To activate: edit the \$CHANNELS array at the top of this script with a\n";
    echo "real token/URL and 'enabled' => true, for whichever channel(s) you want.\n";
}
