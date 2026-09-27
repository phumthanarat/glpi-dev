<?php

/**
 * Creates the two test technician accounts on their own, without the rest
 * of seed-it-department.php's "[Ex.]" demo department:
 *   test.somchai (Somchai Techno), test.suda (Suda Netadmin), password from env
 *   TEST_ACCOUNT_PASSWORD (required; not kept in the repo)
 *
 * Unlike the seed version they get the Technician profile on the Root
 * entity (recursive) with Root as default entity. With the seed's
 * non-recursive "[Ex.] IT Department" profile they couldn't open tickets
 * whose requester sits in Root (e.g. every ticket created from IT Chat).
 *
 * Idempotent: existing users are left as they are, only missing
 * profile/email links are added.
 *
 * Note: cleanup-seed-data.php deletes these two logins by name.
 *
 * Run inside the app container:
 *   php customizations/setup-15-technician-accounts.php
 */

$test_password = (string) getenv('TEST_ACCOUNT_PASSWORD');
if ($test_password === '') {
    fwrite(STDERR, "Set TEST_ACCOUNT_PASSWORD (the test technicians' password).\n");
    exit(1);
}

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

$profile = new Profile();
if (!$profile->getFromDBByCrit(['name' => 'Technician'])) {
    fwrite(STDERR, "Technician profile not found.\n");
    exit(1);
}
$technician_profile_id = $profile->getID();

foreach ([
    ['login' => 'test.somchai', 'firstname' => 'Somchai', 'realname' => 'Techno',   'email' => 'somchai@dev.glpi.labs'],
    ['login' => 'test.suda',    'firstname' => 'Suda',    'realname' => 'Netadmin', 'email' => 'suda@dev.glpi.labs'],
] as $u) {
    $user = new User();
    if ($user->getFromDBbyName($u['login'])) {
        $user_id = $user->getID();
        echo "{$u['login']}: already exists (#$user_id), checking links only\n";
    } else {
        $user_id = $user->add([
            'name'         => $u['login'],
            'firstname'    => $u['firstname'],
            'realname'     => $u['realname'],
            'entities_id'  => 0,
            'password'     => $test_password,
            'password2'    => $test_password,
            // User::post_addItem() turns these into the Profile_User row.
            '_profiles_id'   => $technician_profile_id,
            '_entities_id'   => 0,
            '_is_recursive'  => 1,
        ]);
        if (!$user_id) {
            fwrite(STDERR, "{$u['login']}: creation failed\n");
            continue;
        }
        echo "{$u['login']}: created (#$user_id)\n";
    }

    $pu = new Profile_User();
    if (!$pu->getFromDBByCrit(['users_id' => $user_id, 'profiles_id' => $technician_profile_id, 'entities_id' => 0])) {
        $pu->add(['users_id' => $user_id, 'profiles_id' => $technician_profile_id, 'entities_id' => 0, 'is_recursive' => 1]);
        echo "  + Technician on Root entity (recursive)\n";
    } elseif (!$pu->fields['is_recursive']) {
        $pu->update(['id' => $pu->getID(), 'is_recursive' => 1]);
        echo "  ~ Technician on Root entity made recursive\n";
    }

    $email = new UserEmail();
    if (!$email->getFromDBByCrit(['users_id' => $user_id, 'email' => $u['email']])) {
        $email->add(['users_id' => $user_id, 'email' => $u['email'], 'is_default' => 1]);
        echo "  + email {$u['email']}\n";
    }
}

echo "Done.\n";
