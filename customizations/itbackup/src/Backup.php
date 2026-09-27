<?php

namespace GlpiPlugin\Itbackup;

use Config;
use DirectoryIterator;
use RuntimeException;

/**
 * GLPI backups: one directory per backup under the backup volume (BACKUP_DIR),
 *
 *   backup-20260927-020000-scheduled/
 *     database.sql.gz   mariadb-dump --single-transaction of GLPI's database
 *     files.tar.gz      config/ (incl. glpicrypt.key), files/ (documents, pictures...),
 *                       marketplace/, plugins/ - without cache, sessions, tmp, logs
 *     manifest.json     when, type, GLPI version, sizes, sha256 of both archives, duration
 *
 * A backup is written into ".partial-<name>" and renamed when complete, so a listed backup is
 * always a finished one. One at a time (flock). Old ones are pruned after keep_days (the newest
 * successful backup is never pruned).
 */
class Backup
{
    public const BACKUP_DIR = '/var/lib/glpi-backups';
    public const CONTEXT = 'plugin:itbackup';
    private const NAME_RE = '/^backup-\d{8}-\d{6}-(scheduled|manual)$/';

    public static function config(): array
    {
        $c = Config::getConfigurationValues(self::CONTEXT);
        return [
            'keep_days' => max(1, min(365, (int) ($c['keep_days'] ?? 14))),
        ];
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_RE, $name);
    }

    public static function available(): bool
    {
        return is_dir(self::BACKUP_DIR) && is_writable(self::BACKUP_DIR);
    }

    /** Is a backup running right now (lock held by another process)? */
    public static function isRunning(): bool
    {
        $fh = @fopen(self::BACKUP_DIR . '/.lock', 'c');
        if (!$fh) {
            return false;
        }
        $free = flock($fh, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($fh, LOCK_UN);
        }
        fclose($fh);
        return !$free;
    }

    /** Finished backups, newest first, with their manifest. */
    public static function list(): array
    {
        $out = [];
        if (!is_dir(self::BACKUP_DIR)) {
            return $out;
        }
        foreach (new DirectoryIterator(self::BACKUP_DIR) as $d) {
            if (!$d->isDir() || !self::isValidName($d->getFilename())) {
                continue;
            }
            $m = json_decode((string) @file_get_contents($d->getPathname() . '/manifest.json'), true) ?: [];
            $out[] = ['name' => $d->getFilename()] + $m;
        }
        usort($out, static fn($a, $b) => strcmp($b['name'], $a['name']));
        return $out;
    }

    /** Result of the last run (success or failure), written by run(). */
    public static function lastStatus(): array
    {
        return json_decode((string) @file_get_contents(self::BACKUP_DIR . '/last-run.json'), true) ?: [];
    }

    public static function delete(string $name): bool
    {
        if (!self::isValidName($name) || !is_dir(self::BACKUP_DIR . '/' . $name)) {
            return false;
        }
        self::rmTree(self::BACKUP_DIR . '/' . $name);
        return !is_dir(self::BACKUP_DIR . '/' . $name);
    }

    /**
     * Makes a backup. Returns the manifest. Throws on failure (and records it in last-run.json).
     */
    public static function run(string $type): array
    {
        global $DB;

        if (!in_array($type, ['scheduled', 'manual'], true)) {
            throw new RuntimeException("unknown backup type $type");
        }
        if (!self::available()) {
            throw new RuntimeException('backup volume ' . self::BACKUP_DIR . ' is missing or not writable');
        }
        $lock = fopen(self::BACKUP_DIR . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('another backup is running');
        }

        $start = microtime(true);
        $name  = 'backup-' . date('Ymd-His') . '-' . $type;
        $tmp   = self::BACKUP_DIR . '/.partial-' . $name;
        $final = self::BACKUP_DIR . '/' . $name;
        try {
            @mkdir($tmp, 0700, true);

            // 1. database: mariadb-dump, consistent snapshot, password through the environment
            [$host, $port, $socket] = self::splitHost((string) $DB->dbhost);
            $args = ['mariadb-dump', '--single-transaction', '--quick', '--routines', '--triggers', '--events',
                '--hex-blob', '--default-character-set=utf8mb4', '--host=' . $host, '--user=' . $DB->dbuser];
            if ($port !== null) {
                $args[] = '--port=' . $port;
            }
            if ($socket !== null) {
                $args[] = '--socket=' . $socket;
            }
            $args[] = $DB->dbdefault;
            self::pipeToGzip($args, ['MYSQL_PWD' => rawurldecode((string) $DB->dbpassword)], "$tmp/database.sql.gz");
            $tail = self::gzTail("$tmp/database.sql.gz");
            if (!str_contains($tail, '-- Dump completed')) {
                throw new RuntimeException('database dump is incomplete');
            }

            // 2. files: what GLPI keeps on its volume, without disposable data
            $cmd = ['tar', '-C', GLPI_ROOT, '--exclude=files/_cache', '--exclude=files/_sessions', '--exclude=files/_tmp',
                '--exclude=files/_uploads', '--exclude=files/_log', '--exclude=files/_lock', '--exclude=files/_graphs',
                '--exclude=files/_rss', '-czf', "$tmp/files.tar.gz", 'config', 'files', 'marketplace', 'plugins'];
            self::exec($cmd);

            $tables = (int) $DB->request([
                'COUNT' => 'n', 'FROM' => 'information_schema.tables',
                'WHERE' => ['table_schema' => $DB->dbdefault],
            ])->current()['n'];
            $manifest = [
                'created'     => date('c'),
                'type'        => $type,
                'glpi'        => GLPI_VERSION,
                'database'    => $DB->dbdefault,
                'tables'      => $tables,
                'files'       => [
                    'database.sql.gz' => ['size' => filesize("$tmp/database.sql.gz"), 'sha256' => hash_file('sha256', "$tmp/database.sql.gz")],
                    'files.tar.gz'    => ['size' => filesize("$tmp/files.tar.gz"), 'sha256' => hash_file('sha256', "$tmp/files.tar.gz")],
                ],
                'duration_s'  => round(microtime(true) - $start, 1),
                'status'      => 'ok',
            ];
            file_put_contents("$tmp/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT) . "\n");
            if (!rename($tmp, $final)) {
                throw new RuntimeException('could not finalise ' . $final);
            }
            self::writeStatus(['name' => $name] + $manifest);
            $manifest['pruned'] = self::prune();
            return ['name' => $name] + $manifest;
        } catch (\Throwable $e) {
            self::rmTree($tmp);
            self::writeStatus(['name' => $name, 'created' => date('c'), 'type' => $type, 'status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Deletes backups older than keep_days, never the newest one. Returns the deleted names. */
    public static function prune(): array
    {
        $keep_until = time() - self::config()['keep_days'] * DAY_TIMESTAMP;
        $deleted = [];
        foreach (array_slice(self::list(), 1) as $b) {
            $t = strtotime($b['created'] ?? '') ?: 0;
            if ($t > 0 && $t < $keep_until && self::delete($b['name'])) {
                $deleted[] = $b['name'];
            }
        }
        // leftovers of a crashed run
        foreach (glob(self::BACKUP_DIR . '/.partial-*') ?: [] as $p) {
            if (filemtime($p) < time() - DAY_TIMESTAMP) {
                self::rmTree($p);
            }
        }
        return $deleted;
    }

    private static function writeStatus(array $status): void
    {
        @file_put_contents(self::BACKUP_DIR . '/last-run.json', json_encode($status, JSON_PRETTY_PRINT) . "\n");
    }

    /** "host", "host:3306", "host:/path/sock" -> [host, port|null, socket|null] */
    private static function splitHost(string $h): array
    {
        $parts = explode(':', $h, 2);
        if (count($parts) === 1 || $parts[1] === '') {
            return [$parts[0], null, null];
        }
        return is_numeric($parts[1]) ? [$parts[0], (int) $parts[1], null] : [$parts[0], null, $parts[1]];
    }

    private static function pipeToGzip(array $cmd, array $env, string $target): void
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start ' . $cmd[0]);
        }
        $gz = gzopen($target, 'wb6');
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1 << 16);
            if ($chunk !== false && $chunk !== '') {
                gzwrite($gz, $chunk);
            }
        }
        gzclose($gz);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException($cmd[0] . " failed ($code): " . trim((string) $err));
        }
    }

    private static function exec(array $cmd): void
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start ' . $cmd[0]);
        }
        stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        // tar: 1 = "file changed as we read it" (live files) - the archive is still complete
        if ($code !== 0 && !($cmd[0] === 'tar' && $code === 1)) {
            throw new RuntimeException($cmd[0] . " failed ($code): " . trim((string) $err));
        }
    }

    /** Last ~200 bytes of a gzip file's content. */
    private static function gzTail(string $file): string
    {
        $gz = gzopen($file, 'rb');
        $tail = '';
        while (!gzeof($gz)) {
            $tail = substr($tail . gzread($gz, 1 << 16), -200);
        }
        gzclose($gz);
        return $tail;
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
