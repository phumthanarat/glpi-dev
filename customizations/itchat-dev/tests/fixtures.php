<?php

/**
 * Test fixtures for the itchat test suite. Runs inside a GLPI pod
 * (tests/run.sh copies and runs it).
 *
 *   php fixtures.php setup      creates the test accounts and group, prints JSON credentials
 *   php fixtures.php teardown   removes everything the tests created
 *   php fixtures.php inspect 12,13  prints facts about those tickets as JSON (for assertions)
 *   php fixtures.php offhours on|off           force/restore "outside business hours"
 *   php fixtures.php maintenance <idle> <old> <old_kept>   age chats, run the automatic action once
 *   php fixtures.php mail-send      sends a test e-mail into the IT mailbox (JSON on stdin)
 *   php fixtures.php mail-status <subject-marker>  tickets / refused mails / mailgate state for it
 *   php fixtures.php qr-assets      creates a test printer + computer, prints their QR report URLs
 *   php fixtures.php purge-tickets  ids on stdin: purge those tickets (test users' only), for the load test
 *   php fixtures.php totp-set <login>  (re-)enrol a 2FA test account, prints its TOTP secret
 *   php fixtures.php forms          active Helpdesk forms: {form name: {id, questions: {name: id}}}
 *   php fixtures.php mail-send-batch   like mail-send, a JSON list of e-mails on stdin (one SMTP connection)
 *   php fixtures.php mailgate-param <n>|restore   e-mails fetched per mailgate run (load test), restore = 100 (setup-19)
 *
 * Everything is namespaced so real data is never touched:
 *   users   itchat.test.user1 / itchat.test.user2 (Self-Service), itchat.test.tech / tech2 (Technician),
 *           each with the address <login>@dev.glpi.labs
 *   group   "[itchat-test] Group" (requester group, itchat.test.tech as manager, user1 member)
 *   assets  "[itchat-test] ..." printers / computers (QR label tests)
 *   chats   every conversation whose requester is one of the test users, plus their
 *           messages, uploaded Documents and the Tickets created from them
 * Passwords are random per run.
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

// Must be named $kernel: isAPI() reads the global of that name.
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

global $DB;

const USERS = [
    'itchat.test.user1' => 'Self-Service',
    'itchat.test.user2' => 'Self-Service',
    'itchat.test.tech'  => 'Technician',
    'itchat.test.tech2' => 'Technician',
    // look-only central profile: must chat as a requester, never be offered as a transfer target
    'itchat.test.observer' => 'Observer',
    // Super-Admin for the suites that need one, instead of the real 'glpi' account (whose
    // password and 2FA belong to the people running GLPI)
    'itchat.test.admin' => 'Super-Admin',
];
// accounts that get a TOTP secret (2FA is enforced for central profiles: setup-24)
const TOTP_USERS = ['itchat.test.tech', 'itchat.test.tech2', 'itchat.test.admin'];
const GROUP = '[itchat-test] Group';
const MAIL_DOMAIN = 'dev.glpi.labs';

function userId(string $login): int
{
    $u = new User();
    return $u->getFromDBbyName($login) ? $u->getID() : 0;
}

function teardown(): array
{
    global $DB;
    $done = ['tickets' => 0, 'documents' => 0, 'conversations' => 0, 'users' => 0, 'groups' => 0];
    $uids = array_filter(array_map('userId', array_keys(USERS)));
    $ticket_ids = [];
    $doc_ids = [];

    if ($uids !== [] && $DB->tableExists('glpi_plugin_itchat_conversations')) {
        $convs = iterator_to_array($DB->request([
            'SELECT' => ['id', 'tickets_id'],
            'FROM'   => 'glpi_plugin_itchat_conversations',
            'WHERE'  => ['users_id' => $uids],
        ]));
        $conv_ids = array_column($convs, 'id');
        $ticket_ids = array_filter(array_map('intval', array_column($convs, 'tickets_id')));
        if ($conv_ids !== []) {
            $doc_ids = array_filter(array_map('intval', array_column(iterator_to_array($DB->request([
                'SELECT' => 'documents_id',
                'FROM'   => 'glpi_plugin_itchat_messages',
                'WHERE'  => ['plugin_itchat_conversations_id' => $conv_ids],
            ])), 'documents_id')));
            $DB->delete('glpi_plugin_itchat_messages', ['plugin_itchat_conversations_id' => $conv_ids]);
            $DB->delete('glpi_plugin_itchat_conversations', ['id' => $conv_ids]);
            $done['conversations'] = count($conv_ids);
        }
    }

    // Tickets requested by the test users (e.g. filed through the Helpdesk forms).
    if ($uids !== []) {
        $ticket_ids = array_merge($ticket_ids, array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'tickets_id', 'FROM' => 'glpi_tickets_users',
            'WHERE'  => ['users_id' => $uids, 'type' => CommonITILActor::REQUESTER],
        ])), 'tickets_id')));
    }

    // Tickets whose chat is already gone (e.g. deleted by the retention test) are found by
    // their default title; files attached to any of these tickets go with them.
    $ticket_ids = array_unique(array_merge($ticket_ids, array_map('intval', array_column(iterator_to_array($DB->request([
        'SELECT' => 'id',
        'FROM'   => 'glpi_tickets',
        'WHERE'  => ['OR' => [['name' => ['LIKE', 'แชท: [itchat-test]%']], ['name' => ['LIKE', '[itchat-test]%']]]],
    ])), 'id'))));
    // Problems made from test chats (title "แชท: [itchat-test]...") or by the tests ("[itchat-test]...").
    $done['problems'] = 0;
    foreach (
        $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_problems',
            'WHERE'  => ['OR' => [['name' => ['LIKE', 'แชท: [itchat-test]%']], ['name' => ['LIKE', '[itchat-test]%']]]],
        ]) as $r
    ) {
        if ((new Problem())->delete(['id' => $r['id']], true)) {
            $done['problems']++;
        }
    }
    if ($ticket_ids !== []) {
        $doc_ids = array_merge($doc_ids, array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'documents_id',
            'FROM'   => 'glpi_documents_items',
            'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $ticket_ids],
        ])), 'documents_id')));
        foreach ($ticket_ids as $tid) {
            if ((new Ticket())->delete(['id' => $tid], true)) {
                $done['tickets']++;
            }
        }
        $DB->delete('glpi_queuednotifications', ['itemtype' => 'Ticket', 'items_id' => $ticket_ids, 'sent_time' => null]);
    }
    foreach (array_unique(array_filter($doc_ids)) as $did) {
        $doc = new Document();
        // only files nothing else uses any more
        if ($doc->getFromDB($did) && countElementsInTable('glpi_documents_items', ['documents_id' => $did]) === 0
            && $doc->delete(['id' => $did], true)) {
            $done['documents']++;
        }
    }

    // assets created for the QR label tests
    foreach (['Printer', 'Computer'] as $itemtype) {
        foreach ($DB->request(['SELECT' => 'id', 'FROM' => $itemtype::getTable(), 'WHERE' => ['name' => ['LIKE', '[itchat-test]%']]]) as $r) {
            (new $itemtype())->delete(['id' => $r['id']], true);
        }
    }
    $loc = new Location();
    if ($loc->getFromDBByCrit(['name' => '[itchat-test] ชั้น 2 ฝ่ายบัญชี'])) {
        $loc->delete(['id' => $loc->getID()], true);
    }
    // e-mails the mail receiver refused during the e-mail tests
    $DB->delete('glpi_notimportedemails', ['subject' => ['LIKE', '%[itchat-test]%']]);
    $cal = new Calendar();
    if ($cal->getFromDBByCrit(['name' => '[itchat-test] Closed'])) {
        $prev = Config::getConfigurationValues('plugin:itchat', ['_itchat_test_prev_calendar'])['_itchat_test_prev_calendar'] ?? 'unset';
        if ($prev === 'unset') {
            Config::deleteConfigurationValues('plugin:itchat', ['calendars_id']);
        } else {
            Config::setConfigurationValues('plugin:itchat', ['calendars_id' => $prev]);
        }
        Config::deleteConfigurationValues('plugin:itchat', ['_itchat_test_prev_calendar']);
        $cal->delete(['id' => $cal->getID()], true);
    }

    $g = new Group();
    if ($g->getFromDBByCrit(['name' => GROUP]) && $g->delete(['id' => $g->getID()], true)) {
        $done['groups']++;
    }
    foreach ($uids as $uid) {
        if ((new User())->delete(['id' => $uid], true)) {
            $done['users']++;
        }
    }
    return $done;
}

switch ($argv[1] ?? '') {
    case 'setup':
        teardown(); // leftovers from an interrupted run
        $creds = [];
        foreach (USERS as $login => $profile_name) {
            $profile = new Profile();
            if (!$profile->getFromDBByCrit(['name' => $profile_name])) {
                fwrite(STDERR, "profile $profile_name not found\n");
                exit(1);
            }
            $password = 'T' . bin2hex(random_bytes(8)) . '!9a';
            $id = (new User())->add([
                'name'          => $login,
                'realname'      => 'Test',
                'firstname'     => $login,
                'password'      => $password,
                'password2'     => $password,
                'entities_id'   => 0,
                '_profiles_id'  => $profile->getID(),
                '_entities_id'  => 0,
                '_is_recursive' => 1,
            ]);
            if (!$id) {
                fwrite(STDERR, "could not create $login\n");
                exit(1);
            }
            (new UserEmail())->add(['users_id' => $id, 'email' => $login . '@' . MAIL_DOMAIN, 'is_default' => 1]);
            $creds[$login] = $password;
        }
        $gid = (new Group())->add(['name' => GROUP, 'entities_id' => 0, 'is_recursive' => 1, 'is_requester' => 1]);
        (new Group_User())->add(['groups_id' => $gid, 'users_id' => userId('itchat.test.user1')]);
        // like an LDAP-synced user: the group is also user1's default group (setup-17 rule uses it)
        (new User())->update(['id' => userId('itchat.test.user1'), 'groups_id' => $gid]);
        (new Group_User())->add(['groups_id' => $gid, 'users_id' => userId('itchat.test.tech'), 'is_manager' => 1]);
        // 2FA: a known TOTP secret per central account, so the suites can answer the MFA prompt
        $totp = new \Glpi\Security\TOTPManager();
        $creds['_totp'] = [];
        foreach (TOTP_USERS as $login) {
            $secret = $totp->createSecret();
            $totp->setSecretForUser(userId($login), $secret);
            // like a user who already went through enrolment: backup codes exist, so GLPI
            // doesn't stop the next login on its one-time "your backup codes" page
            $totp->regenerateBackupCodes(userId($login));
            $creds['_totp'][$login] = $secret;
        }
        echo json_encode(['users' => $creds, 'group' => GROUP]), "\n";
        break;

    case 'inspect':
        // php fixtures.php inspect <ticket id>[,<ticket id>...]  -> JSON facts per ticket
        $out = [];
        foreach (array_filter(array_map('intval', explode(',', $argv[2] ?? ''))) as $tid) {
            $t = new Ticket();
            if (!$t->getFromDB($tid)) {
                $out[$tid] = null;
                continue;
            }
            $users = static function (int $type) use ($DB, $tid): array {
                $o = [];
                foreach ($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid, 'type' => $type]]) as $r) {
                    $u = new User();
                    $o[] = $u->getFromDB($r['users_id']) ? $u->fields['name'] : null;
                }
                return $o;
            };
            $groups = static function (int $type) use ($DB, $tid): array {
                $o = [];
                foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $tid, 'type' => $type]]) as $r) {
                    $g = new Group();
                    $o[] = $g->getFromDB($r['groups_id']) ? $g->fields['name'] : null;
                }
                return $o;
            };
            $approvers = [];
            foreach ($DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $tid]]) as $v) {
                $u = new User();
                $approvers[] = ($v['itemtype_target'] === 'User' && $u->getFromDB($v['items_id_target'])) ? $u->fields['name'] : $v['itemtype_target'];
            }
            $cat = new ITILCategory();
            $out[$tid] = [
                'name'              => $t->fields['name'],
                'type'              => (int) $t->fields['type'],
                'category'          => $cat->getFromDB($t->fields['itilcategories_id']) ? $cat->fields['completename'] : null,
                'urgency'           => (int) $t->fields['urgency'],
                'priority'          => (int) $t->fields['priority'],
                'status'            => (int) $t->fields['status'],
                'requesttype'       => (int) $t->fields['requesttypes_id'],
                'requesttype_name'  => (new RequestType())->getFromDB($t->fields['requesttypes_id']) ? RequestType::getFriendlyNameById($t->fields['requesttypes_id']) : null,
                'items'             => array_values(array_map(static fn($r) => $r['itemtype'] . ':' . $r['items_id'],
                    iterator_to_array($DB->request(['FROM' => 'glpi_items_tickets', 'WHERE' => ['tickets_id' => $tid]])))),
                'type'              => (int) $t->fields['type'],
                'entity'            => (int) $t->fields['entities_id'],
                'content'           => Glpi\RichText\RichText::getTextFromHtml((string) $t->fields['content'], false, false, false, true),
                'global_validation' => (int) $t->fields['global_validation'],
                'requesters'        => $users(CommonITILActor::REQUESTER),
                'assignees'         => $users(CommonITILActor::ASSIGN),
                'requester_groups'  => $groups(CommonITILActor::REQUESTER),
                'assign_groups'     => $groups(CommonITILActor::ASSIGN),
                'approvers'         => $approvers,
                'documents'         => countElementsInTable('glpi_documents_items', ['itemtype' => 'Ticket', 'items_id' => $tid]),
                'problems'          => array_values(array_map('intval', array_column(iterator_to_array(
                    $DB->request(['SELECT' => 'problems_id', 'FROM' => 'glpi_problems_tickets', 'WHERE' => ['tickets_id' => $tid]])
                ), 'problems_id'))),
                'sla_ttr'           => (int) $t->fields['slas_id_ttr'],
                'tasks'             => array_values(array_map(static fn($r) => Glpi\RichText\RichText::getTextFromHtml((string) $r['content'], false, false, false, true),
                    iterator_to_array($DB->request(['FROM' => 'glpi_tickettasks', 'WHERE' => ['tickets_id' => $tid], 'ORDER' => 'id'])))),
                'followups'         => array_values(array_map(
                    static fn($f) => Glpi\RichText\RichText::getTextFromHtml((string) $f['content'], false, false, false, true),
                    iterator_to_array($DB->request(['FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tid], 'ORDER' => 'id']))
                )),
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'offhours':
        // php fixtures.php offhours on|off : point the plugin at a test calendar with no working
        // hours (always "outside business hours"), remembering the real setting; "off" restores it.
        $cal = new Calendar();
        if (($argv[2] ?? '') === 'on') {
            if (!$cal->getFromDBByCrit(['name' => '[itchat-test] Closed'])) {
                $cal->add(['name' => '[itchat-test] Closed', 'entities_id' => 0, 'is_recursive' => 1]);
            }
            $prev = Config::getConfigurationValues('plugin:itchat', ['calendars_id'])['calendars_id'] ?? null;
            Config::setConfigurationValues('plugin:itchat', ['calendars_id' => $cal->getID(), '_itchat_test_prev_calendar' => $prev ?? 'unset']);
            echo json_encode(['calendar' => $cal->getID(), 'message' => plugin_itchat_config()['offhours_message']], JSON_UNESCAPED_UNICODE), "\n";
        } else {
            $prev = Config::getConfigurationValues('plugin:itchat', ['_itchat_test_prev_calendar'])['_itchat_test_prev_calendar'] ?? 'unset';
            if ($prev === 'unset') {
                Config::deleteConfigurationValues('plugin:itchat', ['calendars_id']);
            } else {
                Config::setConfigurationValues('plugin:itchat', ['calendars_id' => $prev]);
            }
            Config::deleteConfigurationValues('plugin:itchat', ['_itchat_test_prev_calendar']);
            if ($cal->getFromDBByCrit(['name' => '[itchat-test] Closed'])) {
                $cal->delete(['id' => $cal->getID()], true);
            }
            echo json_encode(['restored' => $prev]), "\n";
        }
        break;

    case 'maintenance':
        // Ages the test users' chats, runs the hourly automatic action once, reports what happened.
        // conv ids: argv[2] = open chat to go idle, argv[3] = closed chat to expire (its file is
        // unlinked), argv[4] = closed chat to expire whose file was attached to a ticket.
        $conf = plugin_itchat_config();
        [$idle, $old, $old_kept] = array_map('intval', array_slice($argv, 2, 3));
        $ago = static fn(int $sec) => date('Y-m-d H:i:s', time() - $sec);
        $DB->update('glpi_plugin_itchat_conversations', ['date_mod' => $ago(($conf['idle_close_hours'] + 1) * HOUR_TIMESTAMP)], ['id' => $idle]);
        $DB->update('glpi_plugin_itchat_conversations', ['date_mod' => $ago(($conf['retention_days'] + 1) * DAY_TIMESTAMP)], ['id' => [$old, $old_kept]]);
        $doc_of = static fn(int $conv) => (int) ($DB->request(['SELECT' => 'documents_id', 'FROM' => 'glpi_plugin_itchat_messages',
            'WHERE' => ['plugin_itchat_conversations_id' => $conv, 'documents_id' => ['>', 0]], 'LIMIT' => 1])->current()['documents_id'] ?? 0);
        $doc_old = $doc_of($old);
        $doc_kept = $doc_of($old_kept);
        \GlpiPlugin\Itchat\Maintenance::cronItchatMaintenance();
        $conv = $DB->request(['FROM' => 'glpi_plugin_itchat_conversations', 'WHERE' => ['id' => $idle]])->current();
        $last = $DB->request(['FROM' => 'glpi_plugin_itchat_messages', 'WHERE' => ['plugin_itchat_conversations_id' => $idle], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
        echo json_encode([
            'idle_hours'            => $conf['idle_close_hours'],
            'retention_days'        => $conf['retention_days'],
            'idle_status'           => $conv['status'] ?? null,
            'idle_last_message'     => $last['content'] ?? null,
            'old_exists'            => countElementsInTable('glpi_plugin_itchat_conversations', ['id' => $old]) > 0,
            'old_messages'          => countElementsInTable('glpi_plugin_itchat_messages', ['plugin_itchat_conversations_id' => $old]),
            'old_doc_exists'        => $doc_old > 0 && (new Document())->getFromDB($doc_old),
            'kept_exists'           => countElementsInTable('glpi_plugin_itchat_conversations', ['id' => $old_kept]) > 0,
            'kept_doc_exists'       => $doc_kept > 0 && (new Document())->getFromDB($doc_kept),
            'cron_registered'       => (new CronTask())->getFromDBbyName(\GlpiPlugin\Itchat\Maintenance::class, \GlpiPlugin\Itchat\Maintenance::TASK),
        ], JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'mail-send':
        // JSON on stdin: {from, subject, body, [to], [attach: {name, base64, mime}], [headers: {..}]}.
        // Delivered over SMTP to the mailbox the Mail Receiver reads (greenmail in local-dev),
        // exactly like a user's mail client would.
        $m = json_decode(stream_get_contents(STDIN), true);
        $email = (new \Symfony\Component\Mime\Email())
            ->from($m['from'])
            ->to($m['to'] ?? (getenv('MAIL_INTAKE_ADDRESS') ?: 'it-support@' . MAIL_DOMAIN))
            ->subject($m['subject'])
            ->text($m['body']);
        if (!empty($m['attach'])) {
            $email->attach(base64_decode($m['attach']['base64']), $m['attach']['name'], $m['attach']['mime']);
        }
        foreach ($m['headers'] ?? [] as $k => $v) {
            if (in_array(strtolower($k), ['in-reply-to', 'references'], true)) {
                $email->getHeaders()->addIdHeader($k, $v);
            } else {
                $email->getHeaders()->addTextHeader($k, $v);
            }
        }
        $dsn = getenv('MAIL_TEST_SMTP_DSN') ?: 'smtp://greenmail:3025';
        (new \Symfony\Component\Mailer\Mailer(\Symfony\Component\Mailer\Transport::fromDsn($dsn)))->send($email);
        echo json_encode(['sent' => $m['subject'], 'message_id' => $email->getHeaders()->get('Message-ID')?->getBodyAsString()]), "\n";
        break;

    case 'qr-assets':
        $loc = new Location();
        $locations_id = $loc->add(['name' => '[itchat-test] ชั้น 2 ฝ่ายบัญชี', 'entities_id' => 0, 'is_recursive' => 1]);
        $printer = (new Printer())->add(['name' => '[itchat-test] Printer ชั้น 2', 'entities_id' => 0, 'serial' => 'PRN-TEST-001', 'locations_id' => $locations_id]);
        $computer = (new Computer())->add(['name' => '[itchat-test] PC-ACC-07', 'entities_id' => 0, 'serial' => 'PC-TEST-007']);
        $url = static function (string $itemtype, int $id): string {
            $item = new $itemtype();
            $item->getFromDB($id);
            return \GlpiPlugin\Itqr\Label::reportUrl($item);
        };
        echo json_encode([
            'printer'  => ['id' => $printer, 'url' => $url('Printer', $printer)],
            'computer' => ['id' => $computer, 'url' => $url('Computer', $computer)],
            'categories' => \GlpiPlugin\Itqr\Label::CATEGORIES,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        break;

    case 'purge-tickets':
        // ids on stdin (comma separated): purge those tickets, only if a test user is requester
        $uids = array_filter(array_map('userId', array_keys(USERS)));
        $n = 0;
        foreach (array_filter(array_map('intval', explode(',', stream_get_contents(STDIN)))) as $tid) {
            if (countElementsInTable('glpi_tickets_users', ['tickets_id' => $tid, 'users_id' => $uids, 'type' => CommonITILActor::REQUESTER]) > 0
                && (new Ticket())->delete(['id' => $tid], true)) {
                $DB->delete('glpi_queuednotifications', ['itemtype' => 'Ticket', 'items_id' => $tid, 'sent_time' => null]);
                $n++;
            }
        }
        echo json_encode(['purged' => $n]), "\n";
        break;

    case 'totp-set':
        // php fixtures.php totp-set <login> : (re-)enrol a test account in 2FA, prints the secret
        if (!in_array($argv[2] ?? '', TOTP_USERS, true)) {
            fwrite(STDERR, "not a 2FA test account\n");
            exit(1);
        }
        $totp = new \Glpi\Security\TOTPManager();
        $secret = $totp->createSecret();
        $totp->setSecretForUser(userId($argv[2]), $secret);
        $totp->regenerateBackupCodes(userId($argv[2]));
        echo json_encode(['secret' => $secret]), "\n";
        break;

    case 'user-ids':
        // php fixtures.php user-ids <login>... -> {login: id}
        echo json_encode(array_combine(array_slice($argv, 2), array_map('userId', array_slice($argv, 2)))), "\n";
        break;

    case 'forms':
        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_forms_forms', 'WHERE' => ['is_active' => 1, 'is_deleted' => 0]]) as $f) {
            $q = [];
            foreach ($DB->request([
                'SELECT' => ['q.id', 'q.name'], 'FROM' => 'glpi_forms_questions AS q',
                'INNER JOIN' => ['glpi_forms_sections AS s' => ['ON' => ['s' => 'id', 'q' => 'forms_sections_id']]],
                'WHERE' => ['s.forms_forms_id' => $f['id']],
            ]) as $r) {
                $q[$r['name']] = (int) $r['id'];
            }
            $out[$f['name']] = ['id' => (int) $f['id'], 'questions' => $q];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'mail-send-batch':
        $mailer = new \Symfony\Component\Mailer\Mailer(\Symfony\Component\Mailer\Transport::fromDsn(getenv('MAIL_TEST_SMTP_DSN') ?: 'smtp://greenmail:3025'));
        $sent = 0;
        foreach (json_decode(stream_get_contents(STDIN), true) as $m) {
            $mailer->send((new \Symfony\Component\Mime\Email())
                ->from($m['from'])
                ->to($m['to'] ?? (getenv('MAIL_INTAKE_ADDRESS') ?: 'it-support@' . MAIL_DOMAIN))
                ->subject($m['subject'])
                ->text($m['body']));
            $sent++;
        }
        echo json_encode(['sent' => $sent]), "\n";
        break;

    case 'mailgate-param':
        $cron = new CronTask();
        $cron->getFromDBbyName(MailCollector::class, 'mailgate');
        $prev = (int) $cron->fields['param'];
        $cron->update(['id' => $cron->getID(), 'param' => ($argv[2] ?? '') === 'restore' ? 100 : max(1, (int) ($argv[2] ?? 10))]);
        echo json_encode(['previous' => $prev]), "\n";
        break;

    case 'mail-status':
        // php fixtures.php mail-status <marker> : tickets whose title contains <marker>, e-mails
        // with it refused by the receiver, followups containing it, and when mailgate last ran
        $marker = $argv[2] ?? '';
        $like = '%' . $marker . '%';
        $cron = new CronTask();
        $cron->getFromDBbyName(MailCollector::class, 'mailgate');
        echo json_encode([
            'tickets'   => array_map('intval', array_column(iterator_to_array($DB->request([
                'SELECT' => 'id', 'FROM' => 'glpi_tickets', 'WHERE' => ['name' => ['LIKE', $like]], 'ORDER' => 'id',
            ])), 'id')),
            'refused'   => array_values(array_map(static fn($r) => ['from' => $r['from'], 'subject' => $r['subject'], 'reason' => (int) $r['reason']], iterator_to_array($DB->request([
                'FROM' => 'glpi_notimportedemails', 'WHERE' => ['subject' => ['LIKE', $like]],
            ])))),
            'followups' => array_values(array_map(static fn($r) => ['ticket' => (int) $r['items_id'], 'requesttype' => (int) $r['requesttypes_id']], iterator_to_array($DB->request([
                'FROM' => 'glpi_itilfollowups', 'WHERE' => ['itemtype' => 'Ticket', 'content' => ['LIKE', $like]],
            ])))),
            'mail_requesttype' => (int) RequestType::getDefault('mail'),
            'mailgate'  => ['frequency' => (int) $cron->fields['frequency'], 'mode' => (int) $cron->fields['mode'], 'lastrun' => $cron->fields['lastrun']],
        ], JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'followup':
        // php fixtures.php followup <ticket id> <login> <private 0|1> <text...> : add a followup as that user
        [$tid, $login, $private] = [(int) $argv[2], $argv[3], (int) $argv[4]];
        $text = implode(' ', array_slice($argv, 5));
        $id = (new ITILFollowup())->add([
            'itemtype'   => Ticket::class,
            'items_id'   => $tid,
            'users_id'   => userId($login),
            'content'    => '<p>' . htmlescape($text) . '</p>',
            'is_private' => $private,
        ]);
        echo json_encode(['followup' => $id]), "\n";
        break;

    case 'ticket-status':
        // php fixtures.php ticket-status <ticket id> <status> : set the status directly (no rules, no notifications)
        $DB->update('glpi_tickets', ['status' => (int) $argv[3]], ['id' => (int) $argv[2]]);
        echo json_encode(['status' => (int) $argv[3]]), "\n";
        break;

    case 'ticket-lifecycle':
        // Full GLPI ticket lifecycle, each step performed as the real test user (credentials
        // from env ITCHAT_TEST_CREDS, as printed by `setup`). Prints observations as JSON.
        $creds = json_decode((string) getenv('ITCHAT_TEST_CREDS'), true) ?: [];
        $as = static function (string $login) use ($creds): void {
            $a = new Auth();
            if (!$a->login($login, $creds[$login] ?? '', true)) {
                throw new RuntimeException("login $login failed");
            }
            Session::init($a);
        };
        $cat = static fn(string $completename) => (int) ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_itilcategories',
            'WHERE' => ['completename' => $completename]])->current()['id'] ?? 0);
        $queued = static fn(int $tid) => countElementsInTable('glpi_queuednotifications', ['itemtype' => 'Ticket', 'items_id' => $tid]);
        $snap = static function (int $tid) use ($DB, $queued): array {
            $t = new Ticket();
            $t->getFromDB($tid);
            $name = static fn($table, $id) => $id ? ($DB->request(['SELECT' => 'name', 'FROM' => $table, 'WHERE' => ['id' => $id]])->current()['name'] ?? null) : null;
            $groups = static fn(int $type) => array_values(array_map(static fn($r) => $name('glpi_groups', $r['groups_id']),
                iterator_to_array($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $tid, 'type' => $type]]))));
            $users = static fn(int $type) => array_values(array_map(static fn($r) => $name('glpi_users', $r['users_id']),
                iterator_to_array($DB->request(['FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $tid, 'type' => $type]]))));
            return [
                'status'            => (int) $t->fields['status'],
                'type'              => (int) $t->fields['type'],
                'urgency'           => (int) $t->fields['urgency'],
                'priority'          => (int) $t->fields['priority'],
                'sla_tto'           => $name('glpi_slas', $t->fields['slas_id_tto']),
                'sla_ttr'           => $name('glpi_slas', $t->fields['slas_id_ttr']),
                'time_to_own'       => $t->fields['time_to_own'],
                'time_to_resolve'   => $t->fields['time_to_resolve'],
                'escalation_levels' => countElementsInTable('glpi_slalevels_tickets', ['tickets_id' => $tid]),
                'requesters'        => $users(CommonITILActor::REQUESTER),
                'requester_groups'  => $groups(CommonITILActor::REQUESTER),
                'assignees'         => $users(CommonITILActor::ASSIGN),
                'assign_groups'     => $groups(CommonITILActor::ASSIGN),
                'global_validation' => (int) $t->fields['global_validation'],
                'validations'       => array_values(array_map(static fn($v) => ['to' => $name('glpi_users', $v['items_id_target']), 'status' => (int) $v['status']],
                    iterator_to_array($DB->request(['FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $tid]])))),
                'queued_notifications' => $queued($tid),
                'takeintoaccount_delay' => (int) $t->fields['takeintoaccount_delay_stat'],
            ];
        };
        $out = ['steps' => []];
        $step = static function (string $label, int $tid, $extra = null) use (&$out, $snap) {
            $out['steps'][] = ['step' => $label, 'ticket' => $tid, 'state' => $snap($tid), 'extra' => $extra];
        };

        try {
            // A. Incident with a Network category, high urgency, by user1 (member of the test group)
            $as('itchat.test.user1');
            $a = (new Ticket())->add([
                'name' => '[itchat-test] TICKET incident: VPN ต่อไม่ได้', 'content' => '<p>VPN ต่อจากบ้านไม่ได้</p>',
                'type' => Ticket::INCIDENT_TYPE, 'itilcategories_id' => $cat('IT Support > Network > VPN'), 'urgency' => 4,
                '_users_id_requester' => Session::getLoginUserID(),
            ]);
            $step('A1 incident created by requester', $a);
            $can = ['user1' => (new Ticket())->can($a, READ)];
            $as('itchat.test.user2');
            $can['user2'] = (new Ticket())->can($a, READ);
            $as('itchat.test.tech');
            $can['tech'] = (new Ticket())->can($a, READ);
            $step('A2 visibility', $a, $can);
            $t = new Ticket();
            $t->getFromDB($a);
            $took = $t->canAssignToMe() && (new Ticket_User())->add(['tickets_id' => $a, 'users_id' => Session::getLoginUserID(), 'type' => CommonITILActor::ASSIGN]);
            $step('A3 technician takes it', $a, ['assigned' => (bool) $took]);
            (new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $a, 'content' => '<p>กำลังตรวจสอบ VPN gateway</p>']);
            (new TicketTask())->add(['tickets_id' => $a, 'content' => '<p>restart VPN service</p>', 'actiontime' => 1800, 'state' => Planning::DONE]);
            (new ITILSolution())->add(['itemtype' => Ticket::class, 'items_id' => $a, 'content' => '<p>restart VPN service แล้ว ใช้งานได้</p>']);
            $step('A4 followup + task + solution', $a, [
                'actiontime' => (int) $DB->request(['SELECT' => 'actiontime', 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => $a]])->current()['actiontime'],
            ]);
            $as('itchat.test.user1');
            (new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $a, 'add_close' => 1, 'content' => '']);
            $step('A5 requester approves the solution', $a);

            // B. Request for an account, by user1 -> manager approval -> solution refused
            $b = (new Ticket())->add([
                'name' => '[itchat-test] TICKET request: ขอสิทธิ์ AD', 'content' => '<p>ขอสิทธิ์เข้าโฟลเดอร์บัญชี</p>',
                'type' => Ticket::DEMAND_TYPE, 'itilcategories_id' => $cat('IT Support > Account > AD Account'), 'urgency' => 3,
                '_users_id_requester' => Session::getLoginUserID(),
            ]);
            $step('B1 request created by requester', $b);
            $as('itchat.test.tech'); // group manager = approver
            $v = $DB->request(['SELECT' => 'id', 'FROM' => 'glpi_ticketvalidations', 'WHERE' => ['tickets_id' => $b]])->current();
            $approved = $v && (new TicketValidation())->update(['id' => $v['id'], 'status' => CommonITILValidation::ACCEPTED, 'comment_validation' => 'อนุมัติ']);
            $step('B2 manager approves', $b, ['approved' => (bool) $approved]);
            $as('itchat.test.tech2');
            $t = new Ticket();
            $t->getFromDB($b);
            if ($t->canAssignToMe()) {
                (new Ticket_User())->add(['tickets_id' => $b, 'users_id' => Session::getLoginUserID(), 'type' => CommonITILActor::ASSIGN]);
            }
            (new ITILSolution())->add(['itemtype' => Ticket::class, 'items_id' => $b, 'content' => '<p>เพิ่มสิทธิ์ให้แล้ว</p>']);
            $step('B3 solved by tech2', $b);
            $as('itchat.test.user1');
            $refused_empty = (new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $b, 'add_reopen' => 1, 'content' => '']);
            (new ITILFollowup())->add(['itemtype' => Ticket::class, 'items_id' => $b, 'add_reopen' => 1, 'content' => '<p>ยังเข้าไม่ได้</p>']);
            $step('B4 requester refuses the solution', $b, ['refuse_without_reason_accepted' => (bool) $refused_empty]);

            // C. Incident without category by user2 (no group), low urgency
            $as('itchat.test.user2');
            $c = (new Ticket())->add([
                'name' => '[itchat-test] TICKET incident: เมาส์เสีย', 'content' => '<p>เมาส์ดับเบิลคลิกเอง</p>',
                'type' => Ticket::INCIDENT_TYPE, 'urgency' => 2, '_users_id_requester' => Session::getLoginUserID(),
            ]);
            $step('C1 uncategorised incident, no group', $c);
            // D. Very high urgency incident on a server
            $d = (new Ticket())->add([
                'name' => '[itchat-test] TICKET incident: file server ล่ม', 'content' => '<p>เข้า file server ไม่ได้ทั้งแผนก</p>',
                'type' => Ticket::INCIDENT_TYPE, 'itilcategories_id' => $cat('IT Support > Server > Storage'), 'urgency' => 5,
                '_users_id_requester' => Session::getLoginUserID(),
            ]);
            $step('D1 very high urgency server incident', $d);
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'categories':
        // completename => id, for tests that drive GLPI forms
        $out = [];
        foreach ($DB->request(['SELECT' => ['id', 'completename'], 'FROM' => 'glpi_itilcategories']) as $c) {
            $out[$c['completename']] = (int) $c['id'];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'tickets-of':
        // php fixtures.php tickets-of <login> : ids of tickets where that user is requester
        $ids = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'tickets_id', 'FROM' => 'glpi_tickets_users',
            'WHERE'  => ['users_id' => userId($argv[2] ?? ''), 'type' => CommonITILActor::REQUESTER],
        ])), 'tickets_id'));
        echo json_encode($ids), "\n";
        break;

    case 'dashboard':
        echo json_encode([
            'csat'   => \GlpiPlugin\Itchat\Dashboard::csat(),
            'today'  => \GlpiPlugin\Itchat\Dashboard::chatsToday(),
            'waiting' => \GlpiPlugin\Itchat\Dashboard::waiting(),
            'cards'  => array_keys(plugin_itchat_dashboard_cards([])),
        ], JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'teardown':
        echo json_encode(teardown()), "\n";
        break;

    default:
        fwrite(STDERR, "usage: php fixtures.php setup|teardown\n");
        exit(2);
}
