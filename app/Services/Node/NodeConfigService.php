<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerService;
use App\Utils\Helper;

class NodeConfigService
{
    public function buildCacheEntry($node, bool $isV2Node): array
    {
        $response = $this->buildResponse($node, $isV2Node);
        $body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $eTag = sha1($body === false ? '' : $body);

        return [
            'etag' => $eTag,
            'body' => $body === false ? '{}' : $body,
        ];
    }

    public function buildResponse($node, bool $isV2Node): array
    {
        $nodeType = (string) $node->type;
        $protocolSettings = is_array($node->protocol_settings) ? $node->protocol_settings : [];

        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                    '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->getServerKeyCreatedAt(), 16),
                    '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->getServerKeyCreatedAt(), 32),
                    '2022-blake3-chacha20-poly1305' => Helper::getServerKey($node->getServerKeyCreatedAt(), 32),
                    default => null
                }
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls']
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => $protocolSettings['server_name'],
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                    2 => $protocolSettings['reality_settings'],
                    default => $protocolSettings['tls_settings']
                }
            ],
            'hysteria' => [
                ...$baseConfig,
                'port' => (string) $node->port,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                    1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                    2 => [
                        'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                        'obfs-password' => $protocolSettings['obfs']['password'] ?? null
                    ],
                    default => []
                }
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => (bool) data_get($protocolSettings, 'zero_rtt_handshake', false),
                'heartbeat' => "3s",
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $this->resolveAnyTlsServerName($node, $protocolSettings),
                'padding_scheme' => (array) data_get($protocolSettings, 'padding_scheme', []),
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings') ?: [],
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings') ?: [],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings') ?: [],
            ],
            'mieru' => [
                ...$baseConfig,
                'port' => (string) $node->port,
                'ports' => (string) data_get($node, 'ports', ''),
                'server_port' => (int) $serverPort,
                'transport' => strtoupper(trim((string) data_get($protocolSettings, 'transport', 'tcp'))) ?: 'TCP',
                'multiplexing' => trim((string) data_get($protocolSettings, 'multiplexing', 'MULTIPLEXING_LOW')) ?: 'MULTIPLEXING_LOW',
            ],
            default => []
        };

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            'device_limit_fallback' => max(0, min(2147483647, (int) admin_setting('device_limit_fallback', 0))),
            'realtime' => $this->buildRealtimeBaseConfig(),
        ];

        $routeIds = (array) data_get($node, 'route_ids', []);
        if (!empty($routeIds)) {
            $response['routes'] = ServerService::getRoutes($routeIds);
        }

        if ($isV2Node) {
            $response = $this->adaptForV2Node($response, $node);
        }

        return $response;
    }

    public function adaptForV2Node(array $response, $node): array
    {
        $nodeType = (string) $node->type;
        $protocolSettings = is_array($node->protocol_settings) ? $node->protocol_settings : [];

        $protocol = $this->mapV2NodeProtocol($nodeType, $protocolSettings);
        $response['protocol'] = $protocol;

        if (array_key_exists('networkSettings', $response)) {
            $response['network_settings'] = $response['networkSettings'];
        } else {
            $response['network_settings'] = data_get($protocolSettings, 'network_settings') ?: null;
        }

        if ($protocol === 'anytls' && empty($response['network'])) {
            $response['network'] = 'tcp';
        }

        $tls = $this->getV2NodeTlsValue($nodeType, $protocolSettings);
        if ($tls !== null) {
            $response['tls'] = $tls;
        }

        $tlsSettings = $this->buildV2NodeTlsSettings($node, $nodeType, $protocolSettings, $tls ?? 0);
        if (!empty($tlsSettings)) {
            $response['tls_settings'] = $tlsSettings;
        }

        if ($protocol === 'hysteria2') {
            $upMbps = (int) ($response['up_mbps'] ?? 0);
            $downMbps = (int) ($response['down_mbps'] ?? 0);
            $response['ignore_client_bandwidth'] = $upMbps === 0 && $downMbps === 0;

            if (array_key_exists('obfs-password', $response) && !array_key_exists('obfs_password', $response)) {
                $response['obfs_password'] = $response['obfs-password'];
            }
            if (array_key_exists('obfs_password', $response) && !array_key_exists('obfs-password', $response)) {
                $response['obfs-password'] = $response['obfs_password'];
            }
        }

        if ($protocol === 'vless') {
            $response['encryption'] = (string) data_get($protocolSettings, 'encryption', '');
            $response['encryption_settings'] = [
                'mode' => (string) data_get($protocolSettings, 'encryption_settings.mode', ''),
                'ticket' => (string) data_get($protocolSettings, 'encryption_settings.ticket', ''),
                'server_padding' => (string) data_get($protocolSettings, 'encryption_settings.server_padding', ''),
                'private_key' => (string) data_get($protocolSettings, 'encryption_settings.private_key', ''),
            ];
        }

        if (isset($response['base_config']) && is_array($response['base_config'])) {
            $response['base_config'] += [
                'node_report_min_traffic' => max(0, (int) admin_setting('node_report_min_traffic', 0)),
                'device_online_min_traffic' => max(0, (int) admin_setting('device_online_min_traffic', 0)),
            ];
        }

        return $response;
    }

    public function buildV2NodeTlsSettings($node, string $nodeType, array $protocolSettings, int $tls): array
    {
        if ($tls <= 0) {
            return [];
        }

        $baseTlsSettings = $this->getBaseTlsSettingsForV2Node($nodeType, $protocolSettings, $tls);
        $serverName = $this->resolveV2NodeServerName($node, $nodeType, $protocolSettings, $tls, $baseTlsSettings);

        $tlsSettings = $baseTlsSettings;
        $tlsSettings['server_name'] = $serverName;
        $alpn = $this->resolveV2NodeAlpn($protocolSettings, $baseTlsSettings);
        if (!empty($alpn)) {
            $tlsSettings['alpn'] = $alpn;
        }
        if ($nodeType === 'anytls') {
            $tlsSettings['allow_insecure'] = Helper::resolveAnyTlsAllowInsecure($protocolSettings);
        }

        if ($tls === 2) {
            $tlsSettings['dest'] = (string) data_get($tlsSettings, 'dest', '');
            $tlsSettings['server_port'] = (string) data_get($tlsSettings, 'server_port', '');
            $tlsSettings['short_id'] = (string) data_get($tlsSettings, 'short_id', '');
            $tlsSettings['private_key'] = (string) data_get($tlsSettings, 'private_key', '');
            $tlsSettings['mldsa65Seed'] = (string) data_get($tlsSettings, 'mldsa65Seed', '');
            $xver = data_get($tlsSettings, 'xver');
            if ($xver === null || $xver === '') {
                $xver = '0';
            }
            $tlsSettings['xver'] = (string) $xver;
            return $tlsSettings;
        }

        $tlsSettings['cert_mode'] = (string) data_get($tlsSettings, 'cert_mode', 'file');
        $tlsSettings['cert_file'] = (string) data_get($tlsSettings, 'cert_file', '');
        $tlsSettings['key_file'] = (string) data_get($tlsSettings, 'key_file', '');
        $tlsSettings['provider'] = (string) data_get($tlsSettings, 'provider', '');
        $tlsSettings['dns_env'] = (string) data_get($tlsSettings, 'dns_env', '');
        $tlsSettings['reject_unknown_sni'] = (string) data_get($tlsSettings, 'reject_unknown_sni', '0');

        return $tlsSettings;
    }

    private function mapV2NodeProtocol(string $nodeType, array $protocolSettings): string
    {
        if ($nodeType !== 'hysteria') {
            return $nodeType;
        }

        return (int) data_get($protocolSettings, 'version', 2) === 2 ? 'hysteria2' : 'hysteria';
    }

    private function getV2NodeTlsValue(string $nodeType, array $protocolSettings): ?int
    {
        return match ($nodeType) {
            'vmess', 'vless', 'socks', 'naive', 'http' => (int) data_get($protocolSettings, 'tls', 0),
            'trojan', 'hysteria', 'tuic' => 1,
            'anytls' => $this->resolveAnyTlsMode($protocolSettings),
            'shadowsocks' => 0,
            default => null,
        };
    }

    private function getBaseTlsSettingsForV2Node(string $nodeType, array $protocolSettings, int $tls): array
    {
        if (in_array($nodeType, ['vless', 'anytls'], true) && $tls === 2) {
            return (array) data_get($protocolSettings, 'reality_settings', []);
        }

        return (array) data_get($protocolSettings, 'tls_settings', []);
    }

    private function resolveV2NodeAlpn(array $protocolSettings, array $baseTlsSettings): array
    {
        $values = data_get($protocolSettings, 'alpn', data_get($baseTlsSettings, 'alpn', []));
        $normalized = [];
        foreach ((array) $values as $value) {
            $text = trim((string) $value);
            if ($text === '' || in_array($text, $normalized, true)) {
                continue;
            }
            $normalized[] = $text;
        }

        return $normalized;
    }

    private function resolveV2NodeServerName($node, string $nodeType, array $protocolSettings, int $tls, array $baseTlsSettings): string
    {
        $serverName = match ($nodeType) {
            'trojan' => (string) data_get($protocolSettings, 'server_name', ''),
            'hysteria', 'tuic' => (string) data_get($protocolSettings, 'tls.server_name', ''),
            'anytls' => Helper::resolveAnyTlsServerName($protocolSettings),
            default => (string) data_get($baseTlsSettings, 'server_name', ''),
        };

        if (in_array($nodeType, ['vless', 'anytls'], true) && $tls === 2) {
            $serverName = (string) data_get($protocolSettings, 'reality_settings.server_name', $serverName);
        }

        return $serverName ?: (string) $node->host;
    }

    private function resolveAnyTlsServerName($node, array $protocolSettings): string
    {
        return Helper::resolveAnyTlsServerName($protocolSettings) ?: (string) $node->host;
    }

    private function resolveAnyTlsMode(array $protocolSettings): int
    {
        $mode = (int) data_get($protocolSettings, 'tls_mode', 0);
        if (in_array($mode, [1, 2], true)) {
            return $mode;
        }

        $realitySettings = (array) data_get($protocolSettings, 'reality_settings', []);
        $hasRealityConfig = collect($realitySettings)->contains(function ($value, $key) {
            if ($key === 'allow_insecure') {
                return (bool) $value;
            }
            return $value !== null && $value !== '';
        });

        return $hasRealityConfig ? 2 : 1;
    }

    private function buildRealtimeBaseConfig(): array
    {
        $settings = app(NodeRealtimeSettings::class);
        $url = $settings->resolvedPublicUrl();
        $enabled = $settings->enabled() && $url !== '';

        return [
            'enabled' => $enabled,
            'url' => $enabled ? $url : '',
            'ping_interval' => $settings->pingInterval(),
        ];
    }
}
