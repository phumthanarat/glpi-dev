<?php

/**
 * Writes watermark-override.css into the root entity's "Custom CSS",
 * replacing any previous watermark block in place (bounded by the
 * `/* ITDEV-WATERMARK-START *\/` ... `/* ITDEV-WATERMARK-END *\/`
 * markers) and appending it if no such block exists yet — so re-running
 * this after editing the watermark design updates it instead of
 * duplicating it. Everything else already in custom_css_code (logo/,
 * topbar-modern/) is left untouched.
 *
 * Backs up the previous full custom_css_code/enable_custom_css value
 * first.
 *
 * Run inside the app container:
 *   php customizations/watermark/apply-watermark.php
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

$css_path = __DIR__ . '/watermark-override.css';
if (!is_readable($css_path)) {
    fwrite(STDERR, "watermark-override.css not found next to this script.\n");
    exit(1);
}
$watermark_css = trim(file_get_contents($css_path));

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
    "-- Root entity custom CSS state before watermark/apply-watermark.php ran.\n" .
    "UPDATE glpi_entities SET enable_custom_css = $prev_enabled, " .
    "custom_css_code = '$escaped_css' WHERE id = 0;\n"
);
echo "Previous state backed up to watermark/BACKUP_previous_state.sql\n";

$start = '/* ITDEV-WATERMARK-START */';
$end   = '/* ITDEV-WATERMARK-END */';
$start_pos = strpos($prev_css, $start);
$end_pos   = strpos($prev_css, $end);

if ($start_pos !== false && $end_pos !== false) {
    $new_css = substr($prev_css, 0, $start_pos)
        . $watermark_css
        . substr($prev_css, $end_pos + strlen($end));
    echo "Replacing existing watermark block in place.\n";
} else {
    $new_css = rtrim($prev_css) . "\n\n" . $watermark_css . "\n";
    echo "No existing watermark block found, appending.\n";
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

echo "Applied. Root entity Custom CSS now has the centered IT-DEV watermark.\n";
echo "Revert: run the SQL in watermark/BACKUP_previous_state.sql, or clear it in\n";
echo "Administration > Entities > Root entity > Configuration.\n";
