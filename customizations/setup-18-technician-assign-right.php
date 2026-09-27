<?php

/**
 * Profile right: give the Technician profile the Ticket "Assign" right.
 *
 * Why: when a technician opens a ticket on behalf of a user (Central > Assistance >
 * Tickets > + Add), the form pre-fills the technician under "Assigned to", but GLPI only
 * keeps assignees on save when the profile has Ticket > Assign
 * (CommonITILObject::transformActorsInput). The stock Technician profile only has
 * "Assign to me" (OWN), so the assignee was silently dropped and the ticket landed on the
 * team with nobody owning it (found by customizations/itchat-dev/tests/test_central.py).
 *
 * Side effect, accepted on purpose: technicians can also assign tickets to other people.
 *
 * Idempotent: only adds the bit if it is missing. Users already logged in get the new
 * right at their next login.
 *
 * Run inside the app container:
 *   php customizations/setup-18-technician-assign-right.php
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

$profile = new Profile();
if (!$profile->getFromDBByCrit(['name' => 'Technician'])) {
    fwrite(STDERR, "Profile 'Technician' not found\n");
    exit(1);
}

$right = new ProfileRight();
if (!$right->getFromDBByCrit(['profiles_id' => $profile->getID(), 'name' => 'ticket'])) {
    fwrite(STDERR, "Technician has no 'ticket' right row\n");
    exit(1);
}

$current = (int) $right->fields['rights'];
if ($current & Ticket::ASSIGN) {
    echo "Technician already has Ticket > Assign (rights=$current), skipping\n";
    exit(0);
}

$new = $current | Ticket::ASSIGN;
ProfileRight::updateProfileRights($profile->getID(), ['ticket' => $new]);
echo "Technician Ticket rights: $current -> $new (added Assign)\n";
echo "Done. Logged-in technicians get it at their next login.\n";
