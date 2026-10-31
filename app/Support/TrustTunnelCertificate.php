<?php

namespace App\Support;

use App\Models\Server;

final class TrustTunnelCertificate
{
    public const DEFAULT_NAME = 'trusttunnel.kelinode.invalid';

    public static function automatic(array|Server $server): bool
    {
        return data_get($server, 'type') === 'trusttunnel'
            && data_get($server, 'protocol_settings.tls_settings.cert_mode') === 'self';
    }

    public static function serverName(array|Server $server): string
    {
        $name = strtolower(trim((string) data_get($server, 'protocol_settings.tls_settings.server_name', '')));
        return $name !== '' ? $name : (self::automatic($server) ? self::DEFAULT_NAME : (string) data_get($server, 'host', ''));
    }

    public static function requestId(array|Server $server): ?string
    {
        $nonce = data_get($server, 'protocol_settings.tls_settings.pin_request_id');
        if (!self::automatic($server) || !is_string($nonce) || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) return null;
        return substr(hash('sha256', implode('|', [$nonce, (int) data_get($server, 'id'),
            (int) data_get($server, 'machine_id'), self::serverName($server)])), 0, 32);
    }

    public static function normalize(array $settings, ?array $previous = null): array
    {
        $tls = (array) ($settings['tls_settings'] ?? []);
        unset($tls['pin_request_id']);
        if (($tls['cert_mode'] ?? '') === 'self') {
            $tls['server_name'] = strtolower(trim((string) ($tls['server_name'] ?? ''))) ?: self::DEFAULT_NAME;
            $old = (array) ($previous['tls_settings'] ?? []);
            $keep = ($old['cert_mode'] ?? '') === 'self'
                && strtolower(trim((string) ($old['server_name'] ?? ''))) === $tls['server_name']
                && preg_match('/^[a-f0-9]{32}$/D', (string) ($old['pin_request_id'] ?? '')) === 1;
            $tls['pin_request_id'] = $keep ? $old['pin_request_id'] : bin2hex(random_bytes(16));
        }
        $settings['tls_settings'] = $tls;
        return $settings;
    }
}
