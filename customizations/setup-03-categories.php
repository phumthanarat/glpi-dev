<?php

/**
 * Step 3 of the ITSM rollout plan: Ticket Category tree.
 *
 * IT Support
 * +-- Hardware  (Computer, Notebook, Printer, Monitor)
 * +-- Software  (Windows, Microsoft 365, Antivirus, Application)
 * +-- Network   (Internet, Wi-Fi, LAN, VPN)
 * +-- Account   (AD Account, Email, Password Reset)
 * +-- Server    (Windows Server, Linux, Backup, Storage)
 *
 * These categories are what Business Rules (step 5/6) will key off of
 * for auto-assignment and SLA/OLA selection.
 *
 * Run inside the app container:
 *   php customizations/setup-03-categories.php
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

/**
 * Creates (or reuses) a category under $parent_id, returns its id.
 */
function ensureCategory(string $name, int $parent_id): int
{
    $cat = new ITILCategory();
    if ($cat->getFromDBByCrit(['name' => $name, 'itilcategories_id' => $parent_id])) {
        echo "Category #{$cat->getID()} already exists: $name (parent #$parent_id), skipping\n";
        return (int) $cat->getID();
    }
    $id = $cat->add([
        'name'               => $name,
        'itilcategories_id'  => $parent_id,
        'entities_id'        => 0,
        'is_recursive'       => 1,
        'is_helpdeskvisible' => 1,
    ]);
    if (!$id) {
        global $DB;
        fwrite(STDERR, "Failed to create category '$name': " . $DB->error() . "\n");
        exit(1);
    }
    echo "Category #$id created: $name (parent #$parent_id)\n";
    return (int) $id;
}

$tree = [
    'Hardware' => ['Computer', 'Notebook', 'Printer', 'Monitor'],
    'Software' => ['Windows', 'Microsoft 365', 'Antivirus', 'Application'],
    'Network'  => ['Internet', 'Wi-Fi', 'LAN', 'VPN'],
    'Account'  => ['AD Account', 'Email', 'Password Reset'],
    'Server'   => ['Windows Server', 'Linux', 'Backup', 'Storage'],
];

$root_id = ensureCategory('IT Support', 0);

foreach ($tree as $branch => $leaves) {
    $branch_id = ensureCategory($branch, $root_id);
    foreach ($leaves as $leaf) {
        ensureCategory($leaf, $branch_id);
    }
}

echo "Done.\n";
