<?php

namespace GlpiPlugin\Itbackup;

use Config;
use GLPIKey;
use RuntimeException;

/**
 * Off-site copy of the backups to a Windows file share (SMB), with rclone.
 *
 * Local backups stay where they are; after each backup every local backup not yet copied is
 * copied (so a share that was down is caught up on the next run), then the share is pruned
 * with the same retention. The result is recorded in each backup's manifest ("remote") and in
 * remote-status.json (shown on the Backups page, red when the last copy failed).
 *
 * The password is stored encrypted with GLPI's key (secured_configs hook) and reaches rclone
 * only through its environment, obscured, never on a command line.
 */
class Remote
{
    public const KEYS = ['remote_enabled', 'smb_host', 'smb_share', 'smb_path', 'smb_domain', 'smb_user', 'smb_pass'];

    public static function settings(): array
    {
        $c = Config::getConfigurationValues(Backup::CONTEXT, self::KEYS);
        $pass = (string) ($c['smb_pass'] ?? '');
        return [
            'enabled' => (int) ($c['remote_enabled'] ?? 0) === 1,
            'host'    => (string) ($c['smb_host'] ?? ''),
            'share'   => (string) ($c['smb_share'] ?? ''),
            'path'    => trim((string) ($c['smb_path'] ?? 'glpi-backups'), '/'),
            'domain'  => (string) ($c['smb_domain'] ?? ''),
            'user'    => (string) ($c['smb_user'] ?? ''),
            'pass'    => $pass !== '' ? (string) (new GLPIKey())->decrypt($pass) : '',
        ];
    }

    /** Validates what the page submitted; returns an error message or null. */
    public static function validate(array $s): ?string
    {
        if (!preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $s['host'])) {
            return 'ชื่อเครื่อง / IP ของ file server ไม่ถูกต้อง';
        }
        if (!preg_match('/^[^\\\\\/:*?"<>|]{1,80}$/u', $s['share'])) {
            return 'ชื่อ share ไม่ถูกต้อง (ชื่อเดียว เช่น backup ไม่ต้องมี \\\\server\\)';
        }
        if ($s['path'] !== '' && (str_contains($s['path'], '..') || !preg_match('#^[^\\\\:*?"<>|]{1,200}$#u', $s['path']))) {
            return 'โฟลเดอร์ปลายทางไม่ถูกต้อง';
        }
        if ($s['user'] === '') {
            return 'ต้องใส่ชื่อผู้ใช้ของ share';
        }
        return null;
    }

    public static function enabled(): bool
    {
        $s = self::settings();
        return $s['enabled'] && self::validate($s) === null;
    }

    /** \\host\share\path, for display */
    public static function label(array $s): string
    {
        return '\\\\' . $s['host'] . '\\' . $s['share'] . ($s['path'] !== '' ? '\\' . str_replace('/', '\\', $s['path']) : '');
    }

    /** Checks the share: create the folder, write + read back + delete a small file. */
    public static function test(array $s): ?string
    {
        try {
            $probe = '.itbackup-test-' . bin2hex(random_bytes(4));
            self::rclone(['mkdir', self::target($s)], $s, 60);
            self::rclone(['rcat', self::target($s, $probe)], $s, 60, "itbackup connection test\n");
            $back = self::rclone(['cat', self::target($s, $probe)], $s, 60);
            self::rclone(['deletefile', self::target($s, $probe)], $s, 60);
            return str_contains($back, 'itbackup connection test') ? null : 'เขียนไฟล์ได้แต่อ่านกลับไม่ตรง';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Copies every local backup not on the share yet, then prunes the share.
     * Returns ['copied' => [...], 'failed' => [name => error], 'pruned' => [...]].
     */
    public static function sync(): array
    {
        $s = self::settings();
        $out = ['copied' => [], 'failed' => [], 'pruned' => []];
        $backups = array_reverse(Backup::list()); // oldest first
        foreach ($backups as $b) {
            if (($b['remote']['status'] ?? '') === 'ok') {
                continue;
            }
            $dir = Backup::BACKUP_DIR . '/' . $b['name'];
            try {
                self::rclone(['copy', '--no-traverse', $dir, self::target($s, $b['name'])], $s, 3000);
                // verify: same files with the same sizes on the share
                $remote = [];
                foreach (json_decode(self::rclone(['lsjson', self::target($s, $b['name'])], $s, 120), true) ?: [] as $f) {
                    $remote[$f['Name']] = (int) $f['Size'];
                }
                foreach (['database.sql.gz', 'files.tar.gz', 'manifest.json'] as $f) {
                    if (($remote[$f] ?? -1) !== filesize("$dir/$f")) {
                        throw new RuntimeException("$f missing or incomplete on the share");
                    }
                }
                self::mark($b['name'], ['status' => 'ok', 'at' => date('c'), 'target' => self::label($s)]);
                $out['copied'][] = $b['name'];
            } catch (\Throwable $e) {
                self::mark($b['name'], ['status' => 'failed', 'at' => date('c'), 'error' => $e->getMessage()]);
                $out['failed'][$b['name']] = $e->getMessage();
            }
        }
        if ($out['failed'] === []) {
            try {
                $out['pruned'] = self::prune($s);
            } catch (\Throwable $e) {
                $out['failed']['prune'] = $e->getMessage();
            }
        }
        @file_put_contents(Backup::BACKUP_DIR . '/remote-status.json', json_encode([
            'at'     => date('c'),
            'target' => self::label($s),
            'status' => $out['failed'] === [] ? 'ok' : 'failed',
            'error'  => $out['failed'] === [] ? null : implode('; ', array_map(
                static fn($k, $v) => "$k: $v",
                array_keys($out['failed']),
                $out['failed']
            )),
            'copied' => $out['copied'],
        ], JSON_PRETTY_PRINT) . "\n");
        return $out;
    }

    public static function lastStatus(): array
    {
        return json_decode((string) @file_get_contents(Backup::BACKUP_DIR . '/remote-status.json'), true) ?: [];
    }

    /** Same retention as local: backups older than keep_days, never the newest. */
    private static function prune(array $s): array
    {
        $names = array_filter(
            array_map(static fn($l) => rtrim($l, '/'), explode("\n", trim(self::rclone(['lsf', '--dirs-only', self::target($s)], $s, 120)))),
            [Backup::class, 'isValidName']
        );
        rsort($names);
        $keep_until = time() - Backup::config()['keep_days'] * DAY_TIMESTAMP;
        $deleted = [];
        foreach (array_slice($names, 1) as $n) {
            if (preg_match('/^backup-(\d{8})-(\d{6})-/', $n, $m) && strtotime("$m[1] $m[2]") < $keep_until) {
                self::rclone(['purge', self::target($s, $n)], $s, 600);
                $deleted[] = $n;
            }
        }
        return $deleted;
    }

    private static function mark(string $name, array $remote): void
    {
        $file = Backup::BACKUP_DIR . "/$name/manifest.json";
        $m = json_decode((string) @file_get_contents($file), true) ?: [];
        $m['remote'] = $remote;
        file_put_contents($file, json_encode($m, JSON_PRETTY_PRINT) . "\n");
    }

    private static function target(array $s, string $sub = ''): string
    {
        return 'dest:' . $s['share'] . ($s['path'] !== '' ? '/' . $s['path'] : '') . ($sub !== '' ? '/' . $sub : '');
    }

    private static function rclone(array $args, array $s, int $timeout, ?string $stdin = null): string
    {
        $env = [
            'PATH'                     => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME'                     => sys_get_temp_dir(),
            'RCLONE_CONFIG'            => sys_get_temp_dir() . '/itbackup-rclone.conf', // unused, env remote below
            'RCLONE_CONFIG_DEST_TYPE'  => 'smb',
            'RCLONE_CONFIG_DEST_HOST'  => $s['host'],
            'RCLONE_CONFIG_DEST_USER'  => $s['user'],
            'RCLONE_CONFIG_DEST_PASS'  => self::obscure($s['pass']),
            'RCLONE_CONFIG_DEST_DOMAIN' => $s['domain'] !== '' ? $s['domain'] : 'WORKGROUP',
            'RCLONE_CONTIMEOUT'        => '20s',
            'RCLONE_TIMEOUT'           => '120s',
            'RCLONE_RETRIES'           => '2',
        ];
        return self::run(array_merge(['timeout', (string) $timeout, 'rclone'], $args), $env, $stdin);
    }

    private static function obscure(string $pass): string
    {
        return trim(self::run(['rclone', 'obscure', '-'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir()], $pass));
    }

    private static function run(array $cmd, array $env, ?string $stdin): string
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('rclone is not available in this image');
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            // last meaningful rclone line, without our credentials (they're only in env anyway)
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $err))));
            $msg = $lines === [] ? "exit $code" : end($lines);
            throw new RuntimeException($code === 124 ? 'หมดเวลาเชื่อมต่อ file share' : preg_replace('/^\S+ \S+ ERROR : /', '', $msg));
        }
        return (string) $out;
    }
}
