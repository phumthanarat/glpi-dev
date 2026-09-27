<?php

/**
 * Scaffolds Slack, Discord, and Microsoft Teams "new ticket" alerts,
 * same mechanism and same caveat as setup-12-line-webhook.php: GLPI's
 * native Webhook feature (Setup > Webhooks), no plugin, fully custom
 * URL/headers/JSON body per platform, created **inactive** with
 * placeholder URLs since there's nothing real to send to yet.
 *
 * All three platforms authenticate via the webhook URL itself (no
 * Authorization header needed, unlike LINE) - the URL *is* the secret,
 * so treat it accordingly once real ones are filled in.
 *
 * Payload uses `|raw` (not `|json_encode`) on interpolated ticket
 * fields. GLPI's Webhook::getWebhookBody() already backslash-escapes
 * quotes in every value before Twig renders the template (for JSON
 * safety) — `|raw` just stops Twig's *separate* HTML autoescaping from
 * then mangling that into `&quot;`. Stacking `|json_encode` on top
 * double-escapes and produces invalid JSON — verified by rendering a
 * ticket name containing a literal `"` through both and diffing.
 *
 * Run inside the app container:
 *   php customizations/setup-13-chat-webhooks.php
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

$category = new WebhookCategory();
if ($category->getFromDBByCrit(['name' => 'Integrations'])) {
    $category_id = $category->getID();
} else {
    $category_id = $category->add(['name' => 'Integrations', 'entities_id' => 0]);
    echo "WebhookCategory #$category_id created: Integrations\n";
}

$msg_text = '🎫 New ticket #{{ item.id }}: {{ item.name|raw }} (priority: {{ item.priority }})';

$webhooks = [
    [
        'name'    => 'Slack - New Ticket',
        'url'     => 'https://hooks.slack.com/services/REPLACE_WITH_SLACK_WEBHOOK_PATH',
        'payload' => "{\"text\": \"$msg_text\"}",
        'headers' => ['Content-Type' => 'application/json'],
        'howto'   => "Slack app settings > Incoming Webhooks > Add New Webhook to Workspace, "
            . 'pick a channel, copy the full "https://hooks.slack.com/services/..." URL into this webhook\'s URL field.',
    ],
    [
        'name'    => 'Discord - New Ticket',
        'url'     => 'https://discord.com/api/webhooks/REPLACE_WITH_WEBHOOK_ID/REPLACE_WITH_WEBHOOK_TOKEN',
        'payload' => "{\"content\": \"$msg_text\"}",
        'headers' => ['Content-Type' => 'application/json'],
        'howto'   => 'Discord channel Settings > Integrations > Webhooks > New Webhook, '
            . 'copy the Webhook URL into this webhook\'s URL field.',
    ],
    [
        'name'    => 'Microsoft Teams - New Ticket',
        'url'     => 'https://REPLACE_WITH_TENANT.webhook.office.com/webhookb2/REPLACE_WITH_WEBHOOK_PATH',
        'payload' => '{"@type": "MessageCard", "@context": "http://schema.org/extensions", '
            . '"summary": "New GLPI Ticket", "themeColor": "0078D7", '
            . '"title": "New Ticket #{{ item.id }}", "text": "{{ item.name|raw }}"}',
        'headers' => ['Content-Type' => 'application/json'],
        'howto'   => 'Teams channel > ... > Connectors (or Workflows on newer tenants) > Incoming Webhook > '
            . 'Configure, copy the URL into this webhook\'s URL field.',
    ],
];

$webhook = new Webhook();
foreach ($webhooks as $w) {
    if ($webhook->getFromDBByCrit(['name' => $w['name']])) {
        echo "Webhook #{$webhook->getID()} '{$w['name']}' already exists, skipping\n";
        continue;
    }
    $id = $webhook->add([
        'name'                 => $w['name'],
        'comment'              => "Scaffolded, inactive until a real URL is set. {$w['howto']}",
        'entities_id'          => 0,
        'is_recursive'         => 1,
        'webhookcategories_id' => $category_id,
        'itemtype'             => 'Ticket',
        'event'                => 'new',
        'http_method'          => 'post',
        'url'                  => $w['url'],
        'use_default_payload'  => 0,
        'payload'              => $w['payload'],
        'custom_headers'       => $w['headers'],
        'is_active'            => 0,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create webhook '{$w['name']}': " . $DB->error() . "\n");
        continue;
    }
    echo "Webhook #$id created: {$w['name']} (inactive)\n";
}

echo "\nTo activate any of these: Setup > Webhooks > pick one, replace the placeholder\n";
echo "URL with a real one from that platform, tick Active, Save. Test via the webhook\n";
echo "form's 'Test' tab, or just create a real ticket.\n";
echo "Done.\n";
