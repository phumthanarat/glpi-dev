<?php

/**
 * Deletes the demo data made by keep-test-data-as-demo.php: users demo.* / demo2.* / demoN.*,
 * their tickets and chats (with messages, files and queued notifications), and the "[demo...]"
 * group, printers, computers and locations. Nothing else is touched.
 *
 *   php delete-demo-data.php tickets <shard> <shards>   purge this shard of the demo tickets
 *                                                     (run the shards in parallel, one per pod)
 *   php delete-demo-data.php rest                     chats, users, group, assets, locations
 *                                                     (after all ticket shards finished)
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

$demo_users = array_map('intval', array_column(iterator_to_array($DB->request([
    'SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => ['REGEXP', '^demo[0-9]*\\.(user1|user2|tech|tech2)$']],
])), 'id'));

// documents attached to these tickets / chats, deleted when nothing else uses them
$purge_docs = static function (array $doc_ids) use ($DB): int {
    $n = 0;
    foreach (array_unique(array_filter($doc_ids)) as $did) {
        $doc = new Document();
        if ($doc->getFromDB($did) && countElementsInTable('glpi_documents_items', ['documents_id' => $did]) === 0
            && $doc->delete(['id' => $did], true)) {
            $n++;
        }
    }
    return $n;
};

switch ($argv[1] ?? '') {
    case 'tickets':
        [$shard, $shards] = [(int) ($argv[2] ?? 0), max(1, (int) ($argv[3] ?? 1))];
        $ids = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'id', 'FROM' => 'glpi_tickets',
            'WHERE'  => ['name' => ['LIKE', '%[demo%'], new \Glpi\DBAL\QueryExpression("MOD(`id`, $shards) = $shard")],
        ])), 'id'));
        $done = ['tickets' => 0, 'documents' => 0];
        foreach ($ids as $tid) {
            // only tickets whose requester is a demo user
            if (countElementsInTable('glpi_tickets_users', ['tickets_id' => $tid, 'users_id' => $demo_users, 'type' => CommonITILActor::REQUESTER]) === 0) {
                continue;
            }
            $docs = array_column(iterator_to_array($DB->request([
                'SELECT' => 'documents_id', 'FROM' => 'glpi_documents_items', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid],
            ])), 'documents_id');
            if ((new Ticket())->delete(['id' => $tid], true)) {
                $DB->delete('glpi_queuednotifications', ['itemtype' => 'Ticket', 'items_id' => $tid, 'sent_time' => null]);
                $done['tickets']++;
                $done['documents'] += $purge_docs($docs);
            }
        }
        echo json_encode($done), "\n";
        break;

    case 'rest':
        $done = [];
        if ($demo_users !== []) {
            $convs = array_column(iterator_to_array($DB->request([
                'SELECT' => 'id', 'FROM' => 'glpi_plugin_itchat_conversations', 'WHERE' => ['users_id' => $demo_users],
            ])), 'id');
            if ($convs !== []) {
                $docs = array_column(iterator_to_array($DB->request([
                    'SELECT' => 'documents_id', 'FROM' => 'glpi_plugin_itchat_messages', 'WHERE' => ['plugin_itchat_conversations_id' => $convs],
                ])), 'documents_id');
                $DB->delete('glpi_plugin_itchat_messages', ['plugin_itchat_conversations_id' => $convs]);
                $DB->delete('glpi_plugin_itchat_conversations', ['id' => $convs]);
                $done['chats'] = count($convs);
                $done['chat_documents'] = $purge_docs(array_map('intval', $docs));
            }
            $done['users'] = 0;
            foreach ($demo_users as $uid) {
                if ((new User())->delete(['id' => $uid], true)) {
                    $done['users']++;
                }
            }
        }
        foreach (['Group' => 'groups', 'Printer' => 'printers', 'Computer' => 'computers', 'Location' => 'locations'] as $itemtype => $key) {
            $done[$key] = 0;
            foreach ($DB->request(['SELECT' => 'id', 'FROM' => $itemtype::getTable(), 'WHERE' => ['name' => ['LIKE', '[demo%']]]) as $r) {
                if ((new $itemtype())->delete(['id' => $r['id']], true)) {
                    $done[$key]++;
                }
            }
        }
        echo json_encode($done), "\n";
        break;

    default:
        fwrite(STDERR, "usage: php delete-demo-data.php tickets <shard> <shards> | rest\n");
        exit(2);
}
