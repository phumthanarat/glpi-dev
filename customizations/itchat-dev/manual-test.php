<?php

/**
 * Accounts for trying IT Chat by hand (see manual-test.sh, which runs this in the app container):
 * requesters that open chats, and one technician per technician profile so every kind of
 * transfer can be tried. demo.inactive is a deactivated technician: never a transfer target.
 *
 *   php manual-test.php users   create them all (or reset name/profile/password; technicians get
 *                               a new 2FA secret); prints the transfer list, then
 *                               {"login": "password", ..., "_totp": {"login": "secret"}} as the last line
 *   php manual-test.php clean   delete them with their chats, tickets and the Problems that
 *                               only link those tickets
 */

chdir('/var/www/glpi');
require '/var/www/glpi/vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

const MANUAL_USERS = [
    'demo.user'       => ['firstname' => 'ผู้แจ้ง 1',            'profile' => 'Self-Service', 'active' => true],
    'demo.user2'      => ['firstname' => 'ผู้แจ้ง 2',            'profile' => 'Self-Service', 'active' => true],
    'demo.tech'       => ['firstname' => 'ช่าง 1',              'profile' => 'Technician',   'active' => true],
    'demo.tech2'      => ['firstname' => 'ช่าง 2',              'profile' => 'Technician',   'active' => true],
    'demo.hotliner'   => ['firstname' => 'Hotliner',            'profile' => 'Hotliner',     'active' => true],
    'demo.supervisor' => ['firstname' => 'หัวหน้าช่าง',          'profile' => 'Supervisor',   'active' => true],
    'demo.inactive'   => ['firstname' => 'ช่าง (ปิดใช้งาน)',     'profile' => 'Technician',   'active' => false],
];

global $DB;

switch ($argv[1] ?? '') {
    case 'users':
        $creds = [];
        foreach (MANUAL_USERS as $login => $def) {
            $profile = new Profile();
            if (!$profile->getFromDBByCrit(['name' => $def['profile']])) {
                fwrite(STDERR, "profile {$def['profile']} not found\n");
                exit(1);
            }
            $password = 'Demo' . bin2hex(random_bytes(5)) . '!9';
            $user = new User();
            if ($user->getFromDBbyName($login)) {
                $id = $user->getID();
                // Direct SQL: User::update() refuses password changes without a logged-in session.
                $ok = $DB->update('glpi_users', [
                    'firstname'            => $def['firstname'],
                    'password'             => Auth::getPasswordHash($password),
                    'password_last_update' => $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'),
                ], ['id' => $id]);
            } else {
                $id = (int) $user->add([
                    'name'        => $login,
                    'realname'    => 'ทดสอบ',
                    'firstname'   => $def['firstname'],
                    'password'    => $password,
                    'password2'   => $password,
                    'entities_id' => 0,
                ]);
                $ok = $id > 0;
            }
            if (!$ok) {
                fwrite(STDERR, "could not create $login\n");
                exit(1);
            }
            // GLPI gives every new user the default profile (Self-Service) as its default one; a
            // technician logged in with that lands on the Helpdesk and is not offered for transfers.
            $pu = new Profile_User();
            if (!$pu->getFromDBByCrit(['users_id' => $id, 'profiles_id' => $profile->getID()])) {
                $pu->add(['users_id' => $id, 'profiles_id' => $profile->getID(), 'entities_id' => 0, 'is_recursive' => 1]);
            }
            $DB->update('glpi_profiles_users', ['is_default_profile' => 0], ['users_id' => $id]);
            $DB->update('glpi_profiles_users', ['is_default_profile' => 1], ['users_id' => $id, 'profiles_id' => $profile->getID()]);
            $DB->update('glpi_users', ['profiles_id' => $profile->getID(), 'is_active' => (int) $def['active']], ['id' => $id]);
            if ($def['active']) {
                $creds[$login] = $password;
            }
            // 2FA is enforced for central profiles (setup-24) and the grace period ends for good:
            // give technicians a known secret, like tests/fixtures.php does.
            if ($def['active'] && $profile->fields['interface'] === 'central') {
                $totp = new \Glpi\Security\TOTPManager();
                $secret = $totp->createSecret();
                $totp->setSecretForUser($id, $secret);
                // backup codes exist, so GLPI doesn't stop the first login on its "your backup codes" page
                $totp->regenerateBackupCodes($id);
                $creds['_totp'][$login] = $secret;
            }
        }
        Plugin::load('itchat');
        echo 'transfer list: ', implode(', ', plugin_itchat_technicians()), "\n";
        echo json_encode($creds, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'clean':
        $uids = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'id', 'FROM' => 'glpi_users', 'WHERE' => ['name' => array_keys(MANUAL_USERS)],
        ])), 'id'));
        if ($uids === []) {
            echo "nothing to delete\n";
            break;
        }
        $done = ['chats' => 0, 'tickets' => 0, 'problems' => 0, 'users' => 0];
        $tickets = array_map('intval', array_column(iterator_to_array($DB->request([
            'SELECT' => 'tickets_id', 'DISTINCT' => true, 'FROM' => 'glpi_tickets_users', 'WHERE' => ['users_id' => $uids],
        ])), 'tickets_id'));
        foreach (
            $DB->request([
                'FROM'  => 'glpi_plugin_itchat_conversations',
                'WHERE' => ['OR' => ['users_id' => $uids, 'users_id_tech' => $uids]],
            ]) as $conv
        ) {
            if ((int) $conv['tickets_id'] > 0) {
                $tickets[] = (int) $conv['tickets_id'];
            }
            foreach (
                $DB->request([
                    'SELECT' => 'documents_id',
                    'FROM'   => 'glpi_plugin_itchat_messages',
                    'WHERE'  => ['plugin_itchat_conversations_id' => $conv['id'], 'documents_id' => ['>', 0]],
                ]) as $m
            ) {
                (new Document())->delete(['id' => $m['documents_id']], true);
            }
            $DB->delete('glpi_plugin_itchat_messages', ['plugin_itchat_conversations_id' => $conv['id']]);
            $DB->delete('glpi_plugin_itchat_conversations', ['id' => $conv['id']]);
            $done['chats']++;
        }
        $tickets = array_values(array_unique($tickets));
        if ($tickets !== []) {
            // Problems made from these tickets ("Problem" type in the dialog), unless a real ticket uses them too.
            foreach (
                $DB->request([
                    'SELECT' => 'problems_id', 'DISTINCT' => true, 'FROM' => 'glpi_problems_tickets', 'WHERE' => ['tickets_id' => $tickets],
                ]) as $row
            ) {
                $others = countElementsInTable('glpi_problems_tickets', [
                    'problems_id' => $row['problems_id'], 'NOT' => ['tickets_id' => $tickets],
                ]);
                if ($others === 0 && (new Problem())->delete(['id' => $row['problems_id']], true)) {
                    $done['problems']++;
                }
            }
            foreach ($tickets as $tid) {
                if ((new Ticket())->delete(['id' => $tid], true)) {
                    $done['tickets']++;
                }
            }
        }
        foreach ($uids as $uid) {
            if ((new User())->delete(['id' => $uid], true)) {
                $done['users']++;
            }
        }
        echo json_encode($done), "\n";
        break;

    default:
        fwrite(STDERR, "usage: php manual-test.php users|clean\n");
        exit(2);
}
