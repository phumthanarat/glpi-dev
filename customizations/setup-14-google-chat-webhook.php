<?php

/**
 * Scaffolds a Google Chat "new ticket" alert. Same mechanism as
 * setup-13-chat-webhooks.php (GLPI's native Webhook, Setup > Webhooks)
 * and created **inactive** with a placeholder URL.
 *
 * Google Chat incoming webhooks only exist for Google Workspace accounts.
 * Personal @gmail.com accounts can't create them. Like Slack/Discord,
 * the URL itself (key + token query params) is the secret.
 *
 * Google Chat text supports *bold* and <url|label> links, so the message
 * links straight to the ticket. Same `|raw` escaping rule as setup-13
 * (see its docblock).
 *
 * Run inside the app container:
 *   php customizations/setup-14-google-chat-webhook.php
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
} else {
    $category_id = $category->add(['name' => 'Integrations', 'entities_id' => 0]);
    echo "WebhookCategory #$category_id created: Integrations\n";
}

global $CFG_GLPI;
$ticket_url = rtrim($CFG_GLPI['url_base'], '/') . '/front/ticket.form.php?id={{ item.id }}';

$name = 'Google Chat - New Ticket';
$webhook = new Webhook();
if ($webhook->getFromDBByCrit(['name' => $name])) {
    echo "Webhook #{$webhook->getID()} '$name' already exists, skipping\n";
} else {
    $id = $webhook->add([
        'name'                 => $name,
        'comment'              => 'Scaffolded, inactive until a real URL is set. Google Chat (Workspace only): '
            . 'open the Space > space name > Apps & integrations > Webhooks > Add webhook, '
            . 'copy the "https://chat.googleapis.com/v1/spaces/.../messages?key=...&token=..." URL into this webhook\'s URL field.',
        'entities_id'          => 0,
        'is_recursive'         => 1,
        'webhookcategories_id' => $category_id,
        'itemtype'             => 'Ticket',
        'event'                => 'new',
        'http_method'          => 'post',
        'url'                  => 'https://chat.googleapis.com/v1/spaces/REPLACE_WITH_SPACE_ID/messages?key=REPLACE_WITH_KEY&token=REPLACE_WITH_TOKEN',
        'use_default_payload'  => 0,
        'payload'              => '{"text": "🎫 *New ticket #{{ item.id }}*: {{ item.name|raw }} (priority: {{ item.priority }})\n'
            . '<' . $ticket_url . '|Open in GLPI>"}',
        'custom_headers'       => ['Content-Type' => 'application/json; charset=UTF-8'],
        'is_active'            => 0,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create webhook '$name': " . $DB->error() . "\n");
        exit(1);
    }
    echo "Webhook #$id created: $name (inactive)\n";
}

echo "\nTo activate: Setup > Webhooks > '$name', replace the placeholder URL with the\n";
echo "real Google Chat webhook URL, tick Active, Save. Test via the webhook form's\n";
echo "'Test' tab, or create a real ticket.\n";
echo "Done.\n";
