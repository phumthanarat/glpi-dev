<?php

/**
 * Makes one backup (see src/Backup.php). Used by the glpi-backup CronJob (scheduled) and by
 * Setup > Backups > "Backup now" (manual, started in the background).
 *
 *   php plugins/itbackup/bin/backup.php [scheduled|manual]
 *
 * Prints one JSON line; exit code 1 on failure (the CronJob shows as failed).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}
chdir(dirname(__DIR__, 3));
require 'vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$type = $argv[1] ?? 'scheduled';
try {
    $result = \GlpiPlugin\Itbackup\Backup::run($type);
    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'backup failed: ' . $e->getMessage() . "\n");
    echo json_encode(['status' => 'failed', 'error' => $e->getMessage()]), "\n";
    exit(1);
}
