<?php

namespace App\Support;

use Illuminate\Support\Arr;

class UserClientCompatibilityService
{
    private const IMPORT_CLIENTS = [
        ['key' => 'clashmeta', 'label' => 'Clash Meta', 'client_name' => 'meta'],
        ['key' => 'clashverge', 'label' => 'Clash Verge', 'client_name' => 'verge'],
        ['key' => 'shadowrocket', 'label' => 'Shadowrocket', 'client_name' => 'shadowrocket'],
        ['key' => 'quantumult', 'label' => 'Quantumult X', 'client_name' => 'quantumult-x'],
        ['key' => 'surge', 'label' => 'Surge', 'client_name' => 'surge'],
        ['key' => 'loon', 'label' => 'Loon', 'client_name' => 'loon'],
        ['key' => 'stash', 'label' => 'Stash', 'client_name' => 'stash'],
        ['key' => 'surfboard', 'label' => 'Surfboard', 'client_name' => 'surfboard'],
        ['key' => 'nekobox', 'label' => 'NekoBox', 'client_name' => 'nekobox'],
        ['key' => 'v2rayng', 'label' => 'v2rayNG', 'client_name' => 'v2rayng'],
        ['key' => 'singbox', 'label' => 'sing-box', 'client_name' => 'sing-box'],
        ['key' => 'hiddify', 'label' => 'Hiddify', 'client_name' => 'hiddify'],
        ['key' => 'karing', 'label' => 'Karing', 'client_name' => 'karing'],
    ];

    public function __construct(
        protected ProtocolCapabilityService $capabilities
    ) {
    }

    public function summarize(array $servers): array
    {
        $normalizedServers = array_values(array_filter(array_map(
            fn ($server) => is_array($server) ? $server : (array) $server,
            $servers
        ), fn (array $server) => !empty($server['type'])));

        $clients = [];
        foreach (self::IMPORT_CLIENTS as $client) {
            $status = 'allow';
            $reasons = [];

            foreach ($normalizedServers as $server) {
                $assessment = $this->capabilities->assessClientSupport($client['client_name'], $server);
                $assessmentStatus = (string) ($assessment['status'] ?? 'block');
                $reason = trim((string) ($assessment['reason'] ?? ''));

                if ($assessmentStatus === 'block') {
                    $status = 'block';
                    if ($reason !== '') {
                        $reasons[] = $reason;
                    }
                    break;
                }

                if ($assessmentStatus === 'partial' && $status !== 'block') {
                    $status = 'partial';
                    if ($reason !== '') {
                        $reasons[] = $reason;
                    }
                }
            }

            $clients[] = [
                'key' => $client['key'],
                'label' => $client['label'],
                'status' => $status,
                'reasons' => array_values(array_unique(array_filter($reasons))),
            ];
        }

        $protocols = [];
        foreach ($normalizedServers as $server) {
            $protocols[] = $this->formatProtocolLabel($server);
        }

        $protocols = array_values(array_unique(array_filter($protocols)));

        return [
            'protocols' => $protocols,
            'clients' => $clients,
            'allow_clients' => array_values(array_filter($clients, fn (array $client) => $client['status'] === 'allow')),
            'partial_clients' => array_values(array_filter($clients, fn (array $client) => $client['status'] === 'partial')),
            'blocked_clients' => array_values(array_filter($clients, fn (array $client) => $client['status'] === 'block')),
        ];
    }

    private function formatProtocolLabel(array $server): string
    {
        $type = strtolower(trim((string) ($server['type'] ?? '')));
        $settings = Arr::get($server, 'protocol_settings', []);
        $version = (int) Arr::get($settings, 'version', 0);

        return match ($type) {
            'hysteria' => $version === 2 ? 'Hysteria2' : 'Hysteria',
            'tuic' => $version > 0 ? "TUIC {$version}" : 'TUIC',
            'vmess' => 'VMess',
            'vless' => 'VLESS',
            'trojan' => 'Trojan',
            'shadowsocks' => 'Shadowsocks',
            'anytls' => 'AnyTLS',
            'socks' => 'SOCKS',
            'http' => 'HTTP',
            'mieru' => 'Mieru',
            'naive' => 'Naive',
            default => strtoupper($type),
        };
    }
}
