<?php

/**
 * Writes logo-override.css into the root entity's "Custom CSS",
 * replacing any previous logo block in place (bounded by the
 * `/* ITDEV-LOGO-START *\/` ... `/* ITDEV-LOGO-END *\/` markers, same
 * pattern as watermark/apply-watermark.php and
 * rebrand-it-dev/apply-footer.php) and appending it if no such block
 * exists yet. Everything else already in custom_css_code (watermark/,
 * rebrand-it-dev/ footer block) is left untouched.
 *
 * One-time compatibility fallback: earlier runs of this script (before
 * the logo block had markers) did a full overwrite of custom_css_code,
 * so on an install that predates the markers, the logo block sits
 * unmarked at the very start of the field, ending wherever the next
 * known block's start marker begins. Detected and replaced the same
 * way so re-running this doesn't leave a stale duplicate.
 *
 * Run inside the app container:
 *   php customizations/logo/apply-logo.php
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

$css_path = __DIR__ . '/logo-override.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "logo-override.css not found next to this script.\n");
    exit(1);
}
$logo_css = trim(file_get_contents($css_path));

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
    "-- Root entity custom CSS state before logo/apply-logo.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to logo/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-LOGO-START */';
$end   = '/* ITDEV-LOGO-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $logo_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing (marked) logo block in place.\n";
} else {
    // Legacy fallback: an unmarked logo block from before markers
    // existed, sitting at the start of the field.
    $other_marker_pos = false;
    foreach (['/* ITDEV-WATERMARK-START */', '/* ITDEV-FOOTER-START */'] as $marker) {
        $pos = strpos($prev_css, $marker);
        if ($pos !== false && ($other_marker_pos === false || $pos < $other_marker_pos)) {
            $other_marker_pos = $pos;
        }
    }
    if ($other_marker_pos !== false && str_contains(substr($prev_css, 0, $other_marker_pos), 'ITSM logo override')) {
        $new_css = $logo_css . "\n\n" . ltrim(substr($prev_css, $other_marker_pos));
        echo "Replacing legacy unmarked logo block (found before another known block).\n";
    } else {
        $new_css = rtrim($prev_css) . "\n\n" . $logo_css . "\n";
        echo "No existing logo block found, appending.\n";
    }
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

echo "Applied. Root entity Custom CSS now has the current logo override.\n";
echo "Revert: run the SQL in logo/BACKUP_previous_state.sql, or clear it in\n";
echo "Administration > Entities > Root entity > Configuration.\n";
