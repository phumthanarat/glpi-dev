<?php

/**
 * Writes row-color.css into the root entity's "Custom CSS", replacing
 * any previous block in place (bounded by the
 * `/* ITDEV-ROWCOLOR-START *\/` ... `/* ITDEV-ROWCOLOR-END *\/`
 * markers, same pattern as logo/, watermark/, rebrand-it-dev/,
 * dashboard-modern/, topbar-modern/) and appending it if no such
 * block exists yet.
 *
 * Run inside the app container:
 *   php customizations/row-color/apply-row-color.php
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

$css_path = __DIR__ . '/row-color.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "row-color.css not found next to this script.\n");
    exit(1);
}
$row_color_css = trim(file_get_contents($css_path));

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
    "-- Root entity custom CSS state before row-color/apply-row-color.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to row-color/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-ROWCOLOR-START */';
$end   = '/* ITDEV-ROWCOLOR-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $row_color_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing row-color block in place.\n";
} else {
    $new_css = rtrim($prev_css) . "\n\n" . $row_color_css . "\n";
    echo "No existing row-color block found, appending.\n";
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
