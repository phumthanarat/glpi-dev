<?php

/**
 * Makes one backup (see src/Backup.php), or only catches up the copy to the file share.
 * Used by the glpi-backup CronJob (scheduled) and by Setup > Backups (manual / sync, started
 * in the background).
 *
 *   php plugins/itbackup/bin/backup.php [scheduled|manual|sync]
 *
 * Prints one JSON line. Exit code: 0 ok, 1 backup failed, 2 backup ok but the copy to the
 * file share failed (the CronJob shows as failed either way, the page says which).
 */

use GlpiPlugin\Itbackup\Backup;
use GlpiPlugin\Itbackup\Remote;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
chdir(dirname(__DIR__, 3));
require 'vendor/autoload.php';

$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

$type = $argv[1] ?? 'scheduled';
try {
    if ($type === 'sync') {
        $lock = Backup::lock();
        $result = Remote::enabled() ? ['remote_sync' => Remote::sync()] : ['remote_sync' => 'disabled'];
        flock($lock, LOCK_UN);
    } else {
        $result = Backup::run($type);
    }
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
    exit(!empty($result['remote_sync']['failed']) ? 2 : 0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'backup failed: ' . $e->getMessage() . "\n");
    echo json_encode(['status' => 'failed', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), "\n";
    exit(1);
}
