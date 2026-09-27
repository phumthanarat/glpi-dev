<?php

/**
 * Writes footer-override.css into the root entity's "Custom CSS",
 * replacing any previous block in place (bounded by the
 * `/* ITDEV-FOOTER-START *\/` ... `/* ITDEV-FOOTER-END *\/` markers,
 * same pattern as watermark/apply-watermark.php) and appending it if
 * no such block exists yet. Everything else already in
 * custom_css_code (logo/, watermark/, topbar-modern/) is left
 * untouched.
 *
 * This is one of three IT-DEV rebrand changes — see
 * customizations/rebrand-it-dev/README.md for the other two, which
 * needed real core-file edits (no CSS/DB-config equivalent exists for
 * a browser tab title or a <select><option> label).
 *
 * Run inside the app container:
 *   php customizations/rebrand-it-dev/apply-footer.php
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

$css_path = __DIR__ . '/footer-override.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "footer-override.css not found next to this script.\n");
    exit(1);
}
$footer_css = trim(file_get_contents($css_path));

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
    "-- Root entity custom CSS state before rebrand-it-dev/apply-footer.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to rebrand-it-dev/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-FOOTER-START */';
$end   = '/* ITDEV-FOOTER-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $footer_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing footer block in place.\n";
} else {
    $new_css = rtrim($prev_css) . "\n\n" . $footer_css . "\n";
    echo "No existing footer block found, appending.\n";
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

echo "Applied. Footer copyright text now reads IT-DEV.\n";
echo "Revert: run the SQL in rebrand-it-dev/BACKUP_previous_state.sql, or clear it in\n";
echo "Administration > Entities > Root entity > Configuration.\n";
