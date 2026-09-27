<?php

namespace GlpiPlugin\Ithttps;

use RuntimeException;

/**
 * Minimal Kubernetes API client for the two Secrets this plugin may touch (glpi-tls,
 * glpi-tls-ca), using the pod's ServiceAccount (k8s/base/https-rbac.yaml: get / patch only).
 */
class Kube
{
    private const SA = '/var/run/secrets/kubernetes.io/serviceaccount';

    public static function available(): bool
    {
        return is_readable(self::SA . '/token') && getenv('KUBERNETES_SERVICE_HOST') !== false;
    }

    /** Decoded data of a Secret ([] when empty), or throws. */
    public static function getSecret(string $name): array
    {
        $s = self::request('GET', self::path($name));
        return array_map('base64_decode', $s['data'] ?? []);
    }

    /** Replaces the given keys of a Secret (merge patch). */
    public static function patchSecret(string $name, array $data): void
    {
        self::request('PATCH', self::path($name), ['data' => array_map('base64_encode', $data)]);
    }

    private static function path(string $name): string
    {
        $ns = trim((string) @file_get_contents(self::SA . '/namespace')) ?: 'glpi';
        return '/api/v1/namespaces/' . rawurlencode($ns) . '/secrets/' . rawurlencode($name);
    }

    private static function request(string $method, string $path, ?array $body = null): array
    {
        if (!self::available()) {
            throw new RuntimeException('not running in Kubernetes (no ServiceAccount token)');
        }
        $host = getenv('KUBERNETES_SERVICE_HOST');
        $port = getenv('KUBERNETES_SERVICE_PORT') ?: '443';
        $ch = curl_init('https://' . (str_contains($host, ':') ? "[$host]" : $host) . ':' . $port . $path);
        $headers = ['Authorization: Bearer ' . trim(file_get_contents(self::SA . '/token')), 'Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/merge-patch+json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CAINFO         => self::SA . '/ca.crt',
            CURLOPT_TIMEOUT        => 15,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException("Kubernetes API unreachable: $err");
        }
        $json = json_decode((string) $raw, true) ?: [];
        if ($code >= 300) {
            throw new RuntimeException("Kubernetes API $code: " . ($json['message'] ?? substr((string) $raw, 0, 200)));
        }
        return $json;
    }
}
