<?php

/**
 * Writes dashboard-override.css into the root entity's "Custom CSS",
 * replacing any previous block in place (bounded by the
 * `/* ITDEV-DASHBOARD-START *\/` ... `/* ITDEV-DASHBOARD-END *\/`
 * markers, same pattern as logo/, watermark/, rebrand-it-dev/) and
 * appending it if no such block exists yet.
 *
 * Run inside the app container:
 *   php customizations/dashboard-modern/apply-dashboard.php
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

$css_path = __DIR__ . '/dashboard-override.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "dashboard-override.css not found next to this script.\n");
    exit(1);
}
$dash_css = trim(file_get_contents($css_path));

$entity = new Entity();
if (!$entity->getFromDB(0)) {
    fwrite(STDERR, "Root entity not found.\n");
    exit(1);
}

$backup_path = __DIR__ . '/BACKUP_previous_state.sql';
$prev_enabled = (int) ($entity->fields['enable_custom_css'] ?? 0);
$prev_css     = $entity->fields['custom_css_code'] ?? '';
$escaped_css  = str_replace("'", "\\'", $prev_css);
file_put_contents(
    $backup_path,
    "-- Root entity custom CSS state before dashboard-modern/apply-dashboard.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to dashboard-modern/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-DASHBOARD-START */';
$end   = '/* ITDEV-DASHBOARD-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $dash_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing dashboard block in place.\n";
} else {
    $new_css = rtrim($prev_css) . "\n\n" . $dash_css . "\n";
    echo "No existing dashboard block found, appending.\n";
}

$ok = $entity->update([
    'id'                => 0,
    'enable_custom_css' => 1,
    'custom_css_code'   => $new_css,
]);
if (!$ok) {
    fwrite(STDERR, "Failed to update root entity custom CSS.\n");
    exit(1);
}

echo "Applied. Dashboard cards now use the modernized styling.\n";
echo "Revert: run the SQL in dashboard-modern/BACKUP_previous_state.sql, or clear it in\n";
echo "Administration > Entities > Root entity > Configuration.\n";
