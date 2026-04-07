<?php

namespace Plugin\NodeAutoRename\Services;

use App\Models\Server;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\Log;

class NodeAutoRenameService
{
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function sync(?int $serverId = null, bool $dryRun = false, bool $force = false): array
    {
        $query = Server::query()->orderBy('sort')->orderBy('id');
        if ($serverId !== null) {
            $query->whereKey($serverId);
        }

        $allowedTypes = $this->resolveIncludedTypes();
        if ($allowedTypes !== []) {
            $query->whereIn('type', $allowedTypes);
        }

        $result = [
            'scanned' => 0,
            'renamed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'changed' => [],
            'errors' => [],
        ];

        $changedServerIds = [];
        foreach ($query->get() as $server) {
            $result['scanned']++;

            try {
                $context = $this->buildContext($server);
                if ($context === null) {
                    $result['skipped']++;
                    continue;
                }

                $newName = $context['name'];
                $oldName = trim((string) ($server->name ?? ''));
                if ($newName === '' || $newName === $oldName) {
                    $result['skipped']++;
                    continue;
                }

                if (!$force && !$this->shouldRename($oldName)) {
                    $result['skipped']++;
                    continue;
                }

                if (!$dryRun) {
                    $server->forceFill(['name' => $newName])->save();
                    $changedServerIds[] = (int) $server->id;
                }

                $result['renamed']++;
                $result['changed'][] = [
                    'id' => (int) $server->id,
                    'old_name' => $oldName,
                    'new_name' => $newName,
                    'country' => $context['country'],
                    'ip' => $context['ip'],
                ];
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['errors'][] = [
                    'id' => (int) $server->id,
                    'message' => $e->getMessage(),
                ];
                Log::warning('[NodeAutoRename] rename failed', [
                    'server_id' => $server->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!$dryRun && $changedServerIds !== []) {
            app(NodeRealtimePublisher::class)->invalidateConfigForServers(
                array_values(array_unique($changedServerIds)),
                'plugin.node_auto_rename.renamed'
            );
        }

        return $result;
    }

    private function shouldRename(string $oldName): bool
    {
        if ($oldName === '') {
            return true;
        }

        return $this->configBool('overwrite_existing', true);
    }

    private function buildContext(Server $server): ?array
    {
        $host = $this->resolveNamingHost($server);
        if ($host === null) {
            return null;
        }

        $ip = $this->resolvePreferredIp($host);
        $country = $this->resolveCountryLabel($ip);
        if ($country === null) {
            return null;
        }

        $name = $this->renderTemplate($server, $host, $ip, $country);
        if ($name === '') {
            return null;
        }

        return [
            'name' => $name,
            'country' => $country,
            'ip' => $ip ?? '',
        ];
    }

    private function resolveNamingHost(Server $server): ?string
    {
        return $this->normalizeHost($this->resolveNamingHostValue($server));
    }

    private function resolveNamingHostValue(Server $server): mixed
    {
        if ((string) $server->type === Server::TYPE_TROJAN) {
            foreach ([
                'server_name',
                'tls_settings.server_name',
                'tls.server_name',
            ] as $path) {
                $serverName = trim((string) data_get($server->protocol_settings, $path, ''));
                if ($serverName !== '') {
                    return $serverName;
                }
            }
        }

        return $server->host ?? null;
    }

    private function renderTemplate(Server $server, string $host, ?string $ip, string $country): string
    {
        $template = trim($this->configString('template', '{protocol}-{country}-{node_id}'));
        if ($template === '') {
            $template = '{protocol}-{country}-{node_id}';
        }

        $rendered = strtr($template, [
            '{protocol}' => $this->resolveProtocolLabel($server),
            '{country}' => $country,
            '{node_id}' => $this->resolveNodeId($server),
            '{id}' => (string) $server->id,
            '{code}' => trim((string) ($server->code ?? '')),
            '{host}' => $host,
            '{ip}' => $ip ?? '',
            '{runtime}' => strtoupper(trim((string) ($server->runtime ?? Server::RUNTIME_GENERIC))),
        ]);

        $rendered = preg_replace('/\s+/', ' ', trim($rendered));
        return is_string($rendered) ? $rendered : '';
    }

    private function resolveProtocolLabel(Server $server): string
    {
        return match ((string) $server->type) {
            Server::TYPE_HYSTERIA => (int) data_get($server->protocol_settings, 'version', 2) === 2 ? 'HY2' : 'HYSTERIA',
            Server::TYPE_SHADOWSOCKS => 'SS',
            default => strtoupper((string) $server->type),
        };
    }

    private function resolveNodeId(Server $server): string
    {
        $code = trim((string) ($server->code ?? ''));
        return $code !== '' ? $code : (string) $server->id;
    }

    private function resolveCountryLabel(?string $ip): ?string
    {
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            try {
                $country = $this->extractCountry((string) (new \Ip2Region())->simple($ip));
                if ($country !== null) {
                    return $country;
                }
            } catch (\Throwable $e) {
                Log::warning('[NodeAutoRename] country lookup failed', [
                    'ip' => $ip,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if ($this->configBool('rename_when_country_unknown', false)) {
            return $this->configString('unknown_country_label', '未知');
        }

        return null;
    }

    private function extractCountry(string $region): ?string
    {
        $region = trim($region);
        if ($region === '') {
            return null;
        }

        foreach (preg_split('/\|+/', $region) ?: [] as $part) {
            $value = trim((string) $part);
            if ($value === '' || $value === '0') {
                continue;
            }
            if (in_array($value, ['内网IP', '局域网', 'LAN'], true)) {
                return null;
            }
            return $value;
        }

        return null;
    }

    private function normalizeHost(mixed $host): ?string
    {
        if ($host === null) {
            return null;
        }

        $raw = trim((string) $host);
        if ($raw === '') {
            return null;
        }

        $parsed = str_contains($raw, '://') ? parse_url($raw) : parse_url('tcp://' . $raw);
        if (is_array($parsed) && isset($parsed['host']) && is_string($parsed['host']) && trim($parsed['host']) !== '') {
            $raw = (string) $parsed['host'];
        }

        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            $raw = substr($raw, 1, -1);
        }

        $raw = trim(rtrim($raw, '.'));
        return $raw !== '' ? $raw : null;
    }

    private function resolvePreferredIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $host;
        }

        $ips = $this->resolveDomainIps($host);
        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $ips[0] ?? null;
    }

    private function resolveDomainIps(string $domain): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return [];
        }

        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return [$domain];
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $domain)) {
            try {
                $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($ascii) && trim($ascii) !== '') {
                    $domain = trim($ascii);
                }
            } catch (\Throwable) {
            }
        }

        $ips = [];
        if (function_exists('dns_get_record')) {
            $aRecords = @dns_get_record($domain, DNS_A);
            if (is_array($aRecords)) {
                foreach ($aRecords as $record) {
                    $ip = $record['ip'] ?? null;
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }

            $aaaaRecords = @dns_get_record($domain, DNS_AAAA);
            if (is_array($aaaaRecords)) {
                foreach ($aaaaRecords as $record) {
                    $ip = $record['ipv6'] ?? null;
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        if ($ips === [] && function_exists('gethostbynamel')) {
            $v4s = @gethostbynamel($domain);
            if (is_array($v4s)) {
                foreach ($v4s as $ip) {
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function configBool(string $key, bool $default): bool
    {
        $value = $this->config[$key] ?? $default;
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function configString(string $key, string $default): string
    {
        $value = $this->config[$key] ?? $default;
        $value = trim((string) $value);
        return $value !== '' ? $value : $default;
    }

    private function resolveIncludedTypes(): array
    {
        $raw = $this->config['include_types'] ?? '';
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $values = preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $types = [];
        foreach ($values as $value) {
            $type = Server::normalizeType($value);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }
}
