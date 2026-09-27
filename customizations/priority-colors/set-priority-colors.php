<?php

/**
 * GLPI's stock priority colors (glpi_configs: priority_1..priority_6)
 * are all the SAME hue (red/pink) at different opacities:
 *   1 Very Low  #fff2f2   4 High      #ffbfbf
 *   2 Low       #ffe0e0   5 Very High #ffadad
 *   3 Medium    #ffcece   6 Major     #ff5555
 * Hard to tell apart at a glance, especially 1-3.
 *
 * Replaced with a proper red -> green severity scale (Major=red,
 * calming down to Very Low=green) - the standard severity/heat-map
 * convention (PagerDuty, Datadog, etc.), immediately legible:
 *   1 Very Low  #22c55e (green)     4 High      #f59e0b (amber)
 *   2 Low       #84cc16 (lime)      5 Very High #f97316 (orange)
 *   3 Medium    #eab308 (yellow)    6 Major     #dc2626 (red)
 *
 * This changes the color EVERYWHERE GLPI renders a priority badge
 * (ticket/problem/change lists, Kanban cards, timelines, Project) -
 * not just the custom row-color.css tint, which reads these same
 * config values and needs updating alongside this (see
 * customizations/row-color/row-color.css).
 *
 * Run inside the app container:
 *   php customizations/priority-colors/set-priority-colors.php
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

global $DB;
$before = [];
foreach (range(1, 6) as $i) {
    $row = $DB->request(['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'core', 'name' => "priority_$i"]])->current();
    $before["priority_$i"] = $row['value'] ?? '';
}
file_put_contents(
    __DIR__ . '/BACKUP_previous_colors.php',
    "<?php\n// Previous priority_1..6 values, before set-priority-colors.php ran.\n// Restore with: Config::setConfigurationValues('core', require __DIR__ . '/BACKUP_previous_colors.php');\nreturn " . var_export($before, true) . ";\n"
);
echo "Previous colors backed up to priority-colors/BACKUP_previous_colors.php\n";

Config::setConfigurationValues('core', [
    'priority_1' => '#22c55e', // Very Low - green
    'priority_2' => '#84cc16', // Low - lime
    'priority_3' => '#eab308', // Medium - yellow
    'priority_4' => '#f59e0b', // High - amber
    'priority_5' => '#f97316', // Very High - orange
    'priority_6' => '#dc2626', // Major - red
]);

echo "Priority colors updated (Very Low->green through Major->red).\n";
