<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SubscriptionTrustedEgressResolver
{
    private const CACHE_KEY = 'subscription_control:trusted_egress:auto:v1';
    private const SERVER_TABLES = [
        'v2_server',
        'v2_server_trojan',
        'v2_server_vmess',
        'v2_server_vless',
        'v2_server_shadowsocks',
        'v2_server_hysteria',
    ];

    public function __construct(private readonly array $config = [])
    {
    }

    public function resolve(): string
    {
        $manual = $this->normalizeEntries($this->parseStoredList($this->config['trusted_egress_ips'] ?? ''));
        $auto = $this->configBool('enable_auto_trusted_node_ips', true)
            ? $this->resolveAutoEntries()
            : [];

        return implode("\n", $this->normalizeEntries(array_merge($manual, $auto)));
    }

    public function resolveFromRows(array $serverRows, array $machineRows = []): array
    {
        $entries = [];

        foreach ($serverRows as $row) {
            if (!is_array($row)) {
                $row = (array) $row;
            }

            $host = $this->normalizeHost((string) ($row['host'] ?? ''));
            if ($host !== '') {
                $entries = array_merge($entries, $this->trustedEntriesFromHost($host));
            }

            $entries = array_merge($entries, $this->parseStoredList($row['ips'] ?? ''));
        }

        if ($this->configBool('enable_auto_trusted_machine_ips', true)) {
            foreach ($machineRows as $row) {
                if (!is_array($row)) {
                    $row = (array) $row;
                }
                $entries = array_merge($entries, $this->trustedEntriesFromMachineRow($row));
            }
        }

        return $this->normalizeEntries($entries);
    }

    private function resolveAutoEntries(): array
    {
        $ttl = $this->configInt('auto_trusted_node_ip_cache_ttl_seconds', 300, 60, 3600);
        $cacheKey = self::CACHE_KEY . ':' . hash('sha256', json_encode([
            'node_dns' => $this->configBool('enable_auto_trusted_node_dns', false),
            'machine_ips' => $this->configBool('enable_auto_trusted_machine_ips', true),
            'machine_stale' => $this->configInt('auto_trusted_machine_stale_seconds', 900, 60, 86400),
        ], JSON_UNESCAPED_SLASHES));

        return Cache::remember($cacheKey, $ttl, function (): array {
            return $this->resolveFromRows(
                $this->collectServerRows(),
                $this->collectMachineRows()
            );
        });
    }

    private function collectServerRows(): array
    {
        $rows = [];

        foreach (self::SERVER_TABLES as $table) {
            try {
                if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'host')) {
                    continue;
                }

                $columns = ['host'];
                if (Schema::hasColumn($table, 'ips')) {
                    $columns[] = 'ips';
                }

                $query = DB::table($table)->select($columns);
                if (Schema::hasColumn($table, 'enabled')) {
                    $query->where('enabled', true);
                }

                foreach ($query->orderBy('id')->limit(2000)->get() as $row) {
                    $rows[] = (array) $row;
                }
            } catch (\Throwable $e) {
                Log::warning('[SubscriptionControl] 可信节点出口读取失败', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $rows;
    }

    private function collectMachineRows(): array
    {
        try {
            if (!Schema::hasTable('v2_server_machine') || !Schema::hasColumn('v2_server_machine', 'load_status')) {
                return [];
            }

            $columns = ['load_status'];
            $query = DB::table('v2_server_machine')->select($columns);

            if (Schema::hasColumn('v2_server_machine', 'is_active')) {
                $query->where('is_active', true);
            }

            if (Schema::hasColumn('v2_server_machine', 'last_seen_at')) {
                $staleSeconds = $this->configInt('auto_trusted_machine_stale_seconds', 900, 60, 86400);
                $query->where('last_seen_at', '>=', time() - $staleSeconds);
            }

            return $query->orderBy('id')->limit(1000)->get()
                ->map(static fn($row): array => (array) $row)
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 可信机器出口读取失败', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function trustedEntriesFromMachineRow(array $row): array
    {
        $status = $row['load_status'] ?? null;
        if (is_string($status)) {
            $decoded = json_decode($status, true);
            $status = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($status)) {
            return [];
        }

        return $this->parseStoredList([
            data_get($status, 'ip.public_ipv4'),
            data_get($status, 'ip.public_ipv6'),
            data_get($status, 'ip.panel_seen'),
        ]);
    }

    private function trustedEntriesFromHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        if (!$this->configBool('enable_auto_trusted_node_dns', false) || !$this->isValidHostname($host)) {
            return [];
        }

        return $this->resolveDnsRecords($host);
    }

    private function resolveDnsRecords(string $host): array
    {
        $entries = [];

        try {
            foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
                foreach (['ip', 'ipv6'] as $field) {
                    $ip = (string) ($record[$field] ?? '');
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        $entries[] = $ip;
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 节点域名解析失败', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }

        if (empty($entries)) {
            foreach (@gethostbynamel($host) ?: [] as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    $entries[] = $ip;
                }
            }
        }

        return $entries;
    }

    private function parseStoredList(mixed $value): array
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                $items = array_merge($items, $this->parseStoredList($item));
            }
            return $items;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $this->parseStoredList($decoded);
        }

        return array_values(array_filter(
            array_map('trim', preg_split('/[\s，,]+/', $value) ?: []),
            static fn(string $item): bool => $item !== ''
        ));
    }

    private function normalizeEntries(array $entries): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            $entry = $this->normalizeTrustedEntry((string) $entry);
            if ($entry !== null) {
                $normalized[strtolower($entry)] = $entry;
            }
        }

        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    private function normalizeTrustedEntry(string $entry): ?string
    {
        $entry = trim($entry);
        if ($entry === '') {
            return null;
        }

        if (!str_contains($entry, '/')) {
            $host = $this->normalizeHost($entry);
            return filter_var($host, FILTER_VALIDATE_IP) ? $host : null;
        }

        [$network, $prefix] = array_map('trim', explode('/', $entry, 2));
        $network = $this->normalizeHost($network);
        if ($network === '' || $prefix === '' || !ctype_digit($prefix)) {
            return null;
        }

        $bytes = @inet_pton($network);
        if ($bytes === false) {
            return null;
        }

        $maxPrefix = strlen($bytes) * 8;
        $prefixInt = (int) $prefix;
        if ($prefixInt < 0 || $prefixInt > $maxPrefix) {
            return null;
        }

        return "{$network}/{$prefixInt}";
    }

    private function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '[') && str_contains($value, ']')) {
            $value = substr($value, 1, strpos($value, ']') - 1);
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }

        if (str_contains($value, '://')) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : $value;
        }

        if (substr_count($value, ':') === 1) {
            [$host, $port] = explode(':', $value, 2);
            if (ctype_digit($port)) {
                $value = $host;
            }
        }

        return strtolower(trim($value, " \t\n\r\0\x0B[]"));
    }

    private function isValidHostname(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_contains($host, '..')) {
            return false;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
    }

    private function configBool(string $key, bool $default): bool
    {
        return filter_var($this->config[$key] ?? $default, FILTER_VALIDATE_BOOL);
    }

    private function configInt(string $key, int $default, int $min, int $max): int
    {
        $value = (int) ($this->config[$key] ?? $default);
        return max($min, min($max, $value));
    }
}
