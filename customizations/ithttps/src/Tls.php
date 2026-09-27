<?php

namespace GlpiPlugin\Ithttps;

use Config;
use CronTask;
use RuntimeException;

/**
 * The certificate the Ingress serves (Secret glpi-tls), in one of two modes:
 *
 *  - uploaded  a certificate the organisation provides (.crt/.pem + key, or .pfx), checked
 *              before it is used (key matches, dates valid, names covered)
 *  - internal  issued by an internal CA this plugin creates once (Secret glpi-tls-ca, 10 years);
 *              the CA certificate can be downloaded and pushed to the computers (AD GPO) so
 *              browsers trust it; renewed automatically 30 days before expiry (397-day certs)
 *
 * Kong picks up a changed Secret by itself (usually within a minute).
 */
class Tls
{
    public const CONTEXT = 'plugin:ithttps';
    public const RENEW_DAYS = 30;
    private const SERVER_DAYS = 397;   // browsers refuse longer server certificates
    private const CA_DAYS = 3650;

    public static function config(): array
    {
        $c = Config::getConfigurationValues(self::CONTEXT);
        return [
            'mode'  => (string) ($c['mode'] ?? ''),
            'hosts' => array_values(array_filter(array_map('trim', explode(',', (string) ($c['hosts'] ?? ''))))),
        ];
    }

    public static function setConfig(array $values): void
    {
        if (isset($values['hosts']) && is_array($values['hosts'])) {
            $values['hosts'] = implode(',', $values['hosts']);
        }
        Config::setConfigurationValues(self::CONTEXT, $values);
    }

    /** Parsed info of the certificate currently served, or null when none. */
    public static function current(): ?array
    {
        $crt = Kube::getSecret('glpi-tls')['tls.crt'] ?? '';
        return trim($crt) === '' ? null : self::info($crt);
    }

    public static function caCertificate(): ?string
    {
        $ca = Kube::getSecret('glpi-tls-ca')['ca.crt'] ?? '';
        return trim($ca) === '' ? null : $ca;
    }

    /** subject, names, issuer, dates, days left, fingerprint of the first certificate of a PEM. */
    public static function info(string $pem): array
    {
        $x = @openssl_x509_parse($pem);
        if (!$x) {
            throw new RuntimeException('ไฟล์ certificate อ่านไม่ได้ (ต้องเป็น PEM/CRT)');
        }
        $names = [];
        foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $n) {
            $n = trim($n);
            if (preg_match('/^(DNS|IP Address):(.+)$/', $n, $m)) {
                $names[] = trim($m[2]);
            }
        }
        if ($names === [] && !empty($x['subject']['CN'])) {
            $names[] = $x['subject']['CN'];
        }
        return [
            'subject'     => $x['subject']['CN'] ?? '',
            'names'       => $names,
            'issuer'      => $x['issuer']['CN'] ?? ($x['issuer']['O'] ?? ''),
            'self_signed' => ($x['subject'] ?? []) == ($x['issuer'] ?? []),
            'from'        => date('c', $x['validFrom_time_t']),
            'to'          => date('c', $x['validTo_time_t']),
            'days_left'   => (int) floor(($x['validTo_time_t'] - time()) / 86400),
            'sha256'      => implode(':', str_split(strtoupper(openssl_x509_fingerprint($pem, 'sha256')), 2)),
        ];
    }

    /** Does a certificate name cover a host (incl. one-level wildcards)? */
    public static function covers(array $names, string $host): bool
    {
        $host = strtolower($host);
        foreach ($names as $n) {
            $n = strtolower($n);
            if ($n === $host || (str_starts_with($n, '*.') && substr_count($host, '.') === substr_count($n, '.') && str_ends_with($host, substr($n, 1)))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks and installs an uploaded certificate. $chain: PEM (the certificate first, then
     * intermediates); $key: PEM private key. Returns the info of the installed certificate.
     */
    public static function installUploaded(string $chain, string $key, array $hosts): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $chain, $m);
        if ($m[0] === []) {
            throw new RuntimeException('ไม่พบ certificate ในไฟล์ (ต้องมี -----BEGIN CERTIFICATE-----)');
        }
        $leaf = $m[0][0];
        $pkey = @openssl_pkey_get_private($key);
        if (!$pkey) {
            throw new RuntimeException('ไฟล์ private key อ่านไม่ได้ หรือมีรหัสผ่าน (ใช้ key ที่ไม่เข้ารหัส หรืออัปโหลดแบบ .pfx)');
        }
        if (!openssl_x509_check_private_key($leaf, $pkey)) {
            throw new RuntimeException('private key ไม่ตรงกับ certificate');
        }
        $info = self::info($leaf);
        if (strtotime($info['to']) <= time()) {
            throw new RuntimeException('certificate หมดอายุแล้ว (' . $info['to'] . ')');
        }
        if (strtotime($info['from']) > time() + 300) {
            throw new RuntimeException('certificate ยังไม่ถึงวันเริ่มใช้ (' . $info['from'] . ')');
        }
        $missing = array_values(array_filter($hosts, static fn($h) => !self::covers($info['names'], $h)));
        if ($missing !== []) {
            throw new RuntimeException('certificate ไม่ครอบคลุมชื่อ: ' . implode(', ', $missing) . ' (มีแค่: ' . implode(', ', $info['names']) . ')');
        }
        openssl_pkey_export($pkey, $key_pem);
        Kube::patchSecret('glpi-tls', ['tls.crt' => implode("\n", $m[0]) . "\n", 'tls.key' => $key_pem]);
        self::setConfig(['mode' => 'uploaded']);
        return $info;
    }

    /** .pfx / .p12 -> [chain PEM, key PEM] */
    public static function fromPfx(string $pfx, string $password): array
    {
        if (!openssl_pkcs12_read($pfx, $p, $password)) {
            throw new RuntimeException('เปิดไฟล์ .pfx ไม่ได้ (รหัสผ่านผิด หรือไฟล์เสีย)');
        }
        return [$p['cert'] . implode('', $p['extracerts'] ?? []), $p['pkey']];
    }

    /** Issues (or re-issues) a certificate for $hosts from the internal CA and installs it. */
    public static function issueInternal(array $hosts): array
    {
        if ($hosts === []) {
            throw new RuntimeException('ต้องระบุชื่อเว็บอย่างน้อย 1 ชื่อ');
        }
        foreach ($hosts as $h) {
            if (!preg_match('/^(\*\.)?[A-Za-z0-9.\-]{1,253}$/', $h)) {
                throw new RuntimeException("ชื่อไม่ถูกต้อง: $h");
            }
        }
        [$ca_crt, $ca_key] = self::internalCa();

        $san = implode(',', array_map(static fn($h) => (filter_var($h, FILTER_VALIDATE_IP) ? 'IP:' : 'DNS:') . $h, $hosts));
        $cnf = self::opensslConfig($san);
        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
            // extensions (names, key usage, issuer key id) are set when signing, not in the CSR:
            // authorityKeyIdentifier needs the issuer, which a CSR doesn't have
            $csr = openssl_csr_new(['commonName' => $hosts[0]], $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
            $crt = openssl_csr_sign($csr, $ca_crt, $ca_key, self::SERVER_DAYS, ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_srv'], random_int(1, PHP_INT_MAX));
            if (!$crt) {
                $errors = [];
                while (($e = openssl_error_string()) !== false) {
                    $errors[] = $e;
                }
                throw new RuntimeException('ออกใบรับรองไม่สำเร็จ: ' . implode(' / ', $errors));
            }
            openssl_x509_export($crt, $crt_pem);
            openssl_pkey_export($key, $key_pem);
        } finally {
            @unlink($cnf);
        }
        Kube::patchSecret('glpi-tls', ['tls.crt' => $crt_pem . $ca_crt, 'tls.key' => $key_pem]);
        self::setConfig(['mode' => 'internal', 'hosts' => $hosts]);
        return self::info($crt_pem);
    }

    /** Renews an internal certificate that is close to expiry or no longer covers the hosts. */
    public static function renewIfNeeded(): ?string
    {
        $c = self::config();
        if ($c['mode'] !== 'internal' || $c['hosts'] === []) {
            return null;
        }
        $cur = self::current();
        $stale = $cur === null || $cur['days_left'] < self::RENEW_DAYS
            || array_filter($c['hosts'], static fn($h) => !self::covers($cur['names'], $h)) !== [];
        if (!$stale) {
            return null;
        }
        $new = self::issueInternal($c['hosts']);
        return 'renewed internal certificate until ' . $new['to'];
    }

    /** GLPI automatic action (daily, run by glpi-cron). */
    public static function cronInfo($name)
    {
        return ['description' => 'IT HTTPS: renew the internal certificate / warn before an uploaded one expires'];
    }

    public static function cronIthttpsRenew(CronTask $task)
    {
        try {
            $done = self::renewIfNeeded();
            if ($done !== null) {
                $task->log($done);
                return 1;
            }
            $cur = self::current();
            if ($cur !== null && self::config()['mode'] === 'uploaded' && $cur['days_left'] < self::RENEW_DAYS) {
                $task->log("uploaded certificate expires in {$cur['days_left']} days: upload a new one (Setup > HTTPS)");
            }
            return 0;
        } catch (\Throwable $e) {
            $task->log('error: ' . $e->getMessage());
            return -1;
        }
    }

    /** The internal CA [cert PEM, key PEM], created on first use (Secret glpi-tls-ca). */
    private static function internalCa(): array
    {
        $s = Kube::getSecret('glpi-tls-ca');
        if (trim($s['ca.crt'] ?? '') !== '' && trim($s['ca.key'] ?? '') !== '') {
            return [$s['ca.crt'], $s['ca.key']];
        }
        $cnf = self::opensslConfig('');
        try {
            $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'config' => $cnf]);
            $dn = ['commonName' => 'ITSM Internal CA', 'organizationName' => 'IT Service Management'];
            $csr = openssl_csr_new($dn, $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
            $crt = openssl_csr_sign($csr, null, $key, self::CA_DAYS, ['config' => $cnf, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3_ca'], random_int(1, PHP_INT_MAX));
            openssl_x509_export($crt, $crt_pem);
            openssl_pkey_export($key, $key_pem);
        } finally {
            @unlink($cnf);
        }
        Kube::patchSecret('glpi-tls-ca', ['ca.crt' => $crt_pem, 'ca.key' => $key_pem]);
        return [$crt_pem, $key_pem];
    }

    private static function opensslConfig(string $san): string
    {
        $f = tempnam(sys_get_temp_dir(), 'ithttps');
        file_put_contents($f, "[req]\ndistinguished_name = dn\n[dn]\n"
            . "[v3_ca]\nbasicConstraints = critical,CA:TRUE,pathlen:0\nkeyUsage = critical,keyCertSign,cRLSign\n"
            . "subjectKeyIdentifier = hash\n"
            . "[v3_srv]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature,keyEncipherment\n"
            . "extendedKeyUsage = serverAuth\nsubjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid,issuer\n"
            . ($san !== '' ? "subjectAltName = $san\n" : ''));
        return $f;
    }
}
