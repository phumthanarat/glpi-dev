<?php

/**
 * Writes search-modern.css into the root entity's "Custom CSS",
 * replacing any previous block in place (bounded by the
 * `/* ITDEV-SEARCHMODERN-START *\/` ... `/* ITDEV-SEARCHMODERN-END *\/`
 * markers, same pattern as every other customizations/*  apply
 * script) and appending it if no such block exists yet.
 *
 * Run inside the app container:
 *   php customizations/search-modern/apply-search-modern.php
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

$css_path = __DIR__ . '/search-modern.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "search-modern.css not found next to this script.\n");
    exit(1);
}
$search_css = trim(file_get_contents($css_path));

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
    "-- Root entity custom CSS state before search-modern/apply-search-modern.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to search-modern/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-SEARCHMODERN-START */';
$end   = '/* ITDEV-SEARCHMODERN-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $search_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing search-modern block in place.\n";
} else {
    $new_css = rtrim($prev_css) . "\n\n" . $search_css . "\n";
    echo "No existing search-modern block found, appending.\n";
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
echo "Done. Root entity custom_css_code updated (" . strlen($new_css) . " bytes), enable_custom_css=1.\n";
