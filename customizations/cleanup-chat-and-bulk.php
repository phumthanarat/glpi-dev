<?php

/**
 * Removes the test data that cleanup-seed-data.php doesn't cover:
 *   - every IT Chat conversation/message (itchat plugin tables) and the
 *     GLPI Documents uploaded through the chat (comment "IT Chat #<id>")
 *   - Tickets created from chat (name "แชท: ..." or linked from a conversation)
 *   - the "[Bulk1000]" load-test Tickets / Problems / Changes
 *   - still-unsent queued notifications for all of the above, so the
 *     cron doesn't keep mailing about items that no longer exist
 *
 * Items are purged through the GLPI API (delete(..., force = true)), so
 * their actor links, followups, document links, etc. go with them. The
 * plugin itself and its config (Google Chat URL) are kept.
 *
 * Run inside the app container:
 *   php customizations/cleanup-chat-and-bulk.php
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

/**
 * @param class-string<CommonDBTM> $itemtype
 * @return int[] purged ids
 */
function purgeWhere(string $itemtype, array $where): array
{
    global $DB;
    $ids = array_column(
        iterator_to_array($DB->request(['SELECT' => 'id', 'FROM' => $itemtype::getTable(), 'WHERE' => $where])),
        'id'
    );
    $done = [];
    foreach ($ids as $i => $id) {
        $item = new $itemtype();
        if ($item->delete(['id' => $id], true)) {
            $done[] = (int) $id;
        } else {
            echo "  ! $itemtype #$id: delete returned false\n";
        }
        if (($i + 1) % 250 === 0) {
            echo "  $itemtype: " . ($i + 1) . '/' . count($ids) . "\n";
        }
    }
    echo "$itemtype: purged " . count($done) . '/' . count($ids) . "\n";
    return $done;
}

function dropQueuedNotifications(string $itemtype, array $ids): void
{
    global $DB;
    if ($ids === []) {
        return;
    }
    $n = 0;
    foreach (array_chunk($ids, 500) as $chunk) {
        $DB->delete('glpi_queuednotifications', [
            'itemtype'  => $itemtype,
            'items_id'  => $chunk,
            'sent_time' => null,
        ]);
        $n += $DB->affectedRows();
    }
    echo "  unsent queued notifications dropped for $itemtype: $n\n";
}

// 1. Chat. Tickets are matched by the default "แชท:" title *or* by being linked from a
//    conversation, since the เปิด Ticket dialog (1.3.0+) lets technicians retitle them.
$linked = $DB->tableExists('glpi_plugin_itchat_conversations')
    ? array_column(iterator_to_array($DB->request([
        'SELECT' => 'tickets_id',
        'FROM'   => 'glpi_plugin_itchat_conversations',
        'WHERE'  => ['tickets_id' => ['>', 0]],
    ])), 'tickets_id')
    : [];
$chat_where = $linked !== []
    ? ['OR' => [['name' => ['LIKE', 'แชท:%']], ['id' => $linked]]]
    : ['name' => ['LIKE', 'แชท:%']];
$chat_tickets = purgeWhere(Ticket::class, $chat_where);
dropQueuedNotifications('Ticket', $chat_tickets);
purgeWhere(Document::class, ['comment' => ['LIKE', 'IT Chat #%']]);
if ($DB->tableExists('glpi_plugin_itchat_messages')) {
    $DB->delete('glpi_plugin_itchat_messages', [new \Glpi\DBAL\QueryExpression('1 = 1')]);
    echo 'itchat messages deleted: ' . $DB->affectedRows() . "\n";
    $DB->delete('glpi_plugin_itchat_conversations', [new \Glpi\DBAL\QueryExpression('1 = 1')]);
    echo 'itchat conversations deleted: ' . $DB->affectedRows() . "\n";
}

// 2. [Bulk1000]
foreach ([Ticket::class, Problem::class, Change::class] as $itemtype) {
    $ids = purgeWhere($itemtype, ['name' => ['LIKE', '[Bulk1000]%']]);
    dropQueuedNotifications($itemtype, $ids);
}

echo "Done.\n";
