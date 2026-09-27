<?php

/**
 * Scaffolds a LINE Notify integration using GLPI 11's native Webhook
 * feature (Setup > Webhooks) — separate system from the email
 * Notifications built in setup-07 (Webhook.php / QueuedWebhook, not
 * Notification/NotificationTemplate). No plugin, no core-file edit:
 * the webhook body/headers are fully custom, and LINE Notify's API is
 * just `POST https://notify-api.line.me/api/notify` with a
 * `Content-Type: application/x-www-form-urlencoded` header and a
 * `message=...` body — GLPI's QueuedWebhook sends the `payload`
 * template's rendered output as a raw body with whatever headers you
 * give it (src/QueuedWebhook.php: RequestOptions::BODY / ::HEADERS),
 * so that's directly expressible without a relay.
 *
 * Created **inactive** (`is_active = 0`) with a placeholder token,
 * since there's no real LINE account to send to yet — see "Activate"
 * below for the one field to fill in once there is one.
 *
 * Note: LINE Notify itself is deprecated by LINE (shutting down) — for
 * anything longer-lived, switch to a LINE Messaging API channel
 * instead. The URL/header/payload here would need to change (LINE's
 * Messaging API expects a JSON body and a channel access token), but
 * the same GLPI Webhook mechanism still applies either way.
 *
 * Payload filter note: use `|raw` (not `|json_encode`) on any
 * interpolated ticket field. GLPI's Webhook::getWebhookBody() already
 * backslash-escapes quotes in every value before Twig renders the
 * template (for JSON safety) — `|raw` just stops Twig's *separate*
 * HTML autoescaping from then mangling that into `&quot;`. Stacking
 * `|json_encode` on top double-escapes. Verified by rendering a ticket
 * name containing a literal `"` through both and diffing the output.
 *
 * Run inside the app container:
 *   php customizations/setup-12-line-webhook.php
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

$category = new WebhookCategory();
if ($category->getFromDBByCrit(['name' => 'Integrations'])) {
    $category_id = $category->getID();
    echo "WebhookCategory #$category_id 'Integrations' already exists, reusing\n";
} else {
    $category_id = $category->add(['name' => 'Integrations', 'entities_id' => 0]);
    echo "WebhookCategory #$category_id created: Integrations\n";
}

$webhook = new Webhook();
if ($webhook->getFromDBByCrit(['name' => 'LINE Notify - New Ticket'])) {
    echo "Webhook #{$webhook->getID()} already exists, skipping\n";
    exit(0);
}

$id = $webhook->add([
    'name'                 => 'LINE Notify - New Ticket',
    'comment'              => 'Scaffolded, inactive until a real LINE Notify token is set. '
        . 'See customizations/setup-12-line-webhook.php for how to activate.',
    'entities_id'          => 0,
    'is_recursive'         => 1,
    'webhookcategories_id' => $category_id,
    'itemtype'             => 'Ticket',
    'event'                => 'new',
    'http_method'          => 'post',
    'url'                  => 'https://notify-api.line.me/api/notify',
    'use_default_payload'  => 0,
    'payload'              => 'message={{ ("New ticket #" ~ item.id ~ ": " ~ item.name '
        . '~ " (priority: " ~ item.priority ~ ")")|raw|url_encode }}',
    'custom_headers'       => [
        'Authorization' => 'Bearer REPLACE_WITH_LINE_NOTIFY_TOKEN',
        'Content-Type'  => 'application/x-www-form-urlencoded',
    ],
    'is_active'            => 0,
]);

if (!$id) {
    global $DB;
    fwrite(STDERR, "Failed to create webhook: " . $DB->error() . "\n");
    exit(1);
}

echo "Webhook #$id created: LINE Notify - New Ticket (inactive)\n";
echo "\nTo activate:\n";
echo "  1. Get a LINE Notify token: https://notify-bot.line.me/my/ (log in, 'Generate token', pick a 1:1 chat or group)\n";
echo "  2. Setup > Webhooks > 'LINE Notify - New Ticket' > replace 'REPLACE_WITH_LINE_NOTIFY_TOKEN' in the Authorization header\n";
echo "  3. Tick 'Active', save\n";
echo "  4. Test with the webhook form's built-in 'Test' tab, or just create a real ticket\n";
echo "Done.\n";
