<?php

namespace Plugin\NodeAutoRename\Services;

use App\Models\Server;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use Illuminate\Support\Facades\Log;

class NodeAutoRenameService
{
    private array $config = [];
    private ?string $maxMindDbPath = null;
    private bool $maxMindDbPathResolved = false;

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

        [$ip, $country] = $this->resolveCountryContext($server, $host);
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

    private function resolveCountryContext(Server $server, string $namingHost): array
    {
        foreach ($this->resolveLookupHostCandidates($server, $namingHost) as $lookupHost) {
            $ip = $this->resolvePreferredIp($lookupHost);
            if ($ip === null) {
                continue;
            }

            $country = $this->resolveCountryLabelByIp($ip);
            if ($country !== null) {
                return [$ip, $country];
            }
        }

        return [null, $this->resolveCountryLabel(null)];
    }

    private function resolveLookupHostCandidates(Server $server, string $namingHost): array
    {
        $candidates = [];
        $nodeHost = $this->normalizeHost($server->host ?? null);

        if ((string) $server->type === Server::TYPE_TROJAN && $nodeHost !== null) {
            $candidates[] = $nodeHost;
        }

        $candidates[] = $namingHost;

        if ((string) $server->type !== Server::TYPE_TROJAN && $nodeHost !== null) {
            $candidates[] = $nodeHost;
        }

        $candidates = array_filter($candidates, static fn($value): bool => is_string($value) && $value !== '');
        return array_values(array_unique($candidates));
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
        $country = $this->resolveCountryLabelByIp($ip);
        if ($country !== null) {
            return $country;
        }

        if ($this->configBool('rename_when_country_unknown', false)) {
            return $this->configString('unknown_country_label', '未知');
        }

        return null;
    }

    private function resolveCountryLabelByIp(?string $ip): ?string
    {
        if ($ip === null || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (!$this->isPublicIp($ip)) {
            return null;
        }

        $provider = $this->resolveGeoProvider();
        if ($provider === 'maxmind') {
            return $this->resolveCountryByMaxMind($ip);
        }

        if ($provider === 'ip2region') {
            return $this->resolveCountryByIp2Region($ip);
        }

        // auto: prefer MaxMind first, fallback to ip2region.
        return $this->resolveCountryByMaxMind($ip) ?? $this->resolveCountryByIp2Region($ip);
    }

    private function resolveGeoProvider(): string
    {
        $value = strtolower(trim((string) ($this->config['geo_provider'] ?? 'auto')));
        return in_array($value, ['auto', 'maxmind', 'ip2region'], true) ? $value : 'auto';
    }

    private function resolveCountryByIp2Region(string $ip): ?string
    {
        try {
            $reader = new \Ip2Region();

            if (method_exists($reader, 'search')) {
                $country = $this->extractCountry((string) $reader->search($ip));
                if ($country !== null) {
                    return $country;
                }
            }

            if (method_exists($reader, 'simple')) {
                $country = $this->extractCountry((string) $reader->simple($ip));
                if ($country !== null) {
                    return $country;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[NodeAutoRename] ip2region lookup failed', [
                'ip' => $ip,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function resolveCountryByMaxMind(string $ip): ?string
    {
        $dbPath = $this->resolveMaxMindDbPath();
        if ($dbPath === null) {
            return null;
        }

        $country = $this->resolveCountryByMaxMindExtension($dbPath, $ip);
        if ($country !== null) {
            return $country;
        }

        $country = $this->resolveCountryByMaxMindDbReader($dbPath, $ip);
        if ($country !== null) {
            return $country;
        }

        return $this->resolveCountryByGeoIp2Reader($dbPath, $ip);
    }

    private function resolveCountryByMaxMindExtension(string $dbPath, string $ip): ?string
    {
        if (!function_exists('maxminddb_open') || !function_exists('maxminddb_get') || !function_exists('maxminddb_close')) {
            return null;
        }

        $mode = defined('MAXMINDDB_MODE_MEMORY') ? MAXMINDDB_MODE_MEMORY : (defined('MAXMINDDB_MODE_FILE') ? MAXMINDDB_MODE_FILE : 0);
        $db = @maxminddb_open($dbPath, $mode);
        if ($db === false || $db === null) {
            return null;
        }

        try {
            $record = @maxminddb_get($db, $ip);
        } catch (\Throwable $e) {
            Log::warning('[NodeAutoRename] maxmind extension lookup failed', [
                'ip' => $ip,
                'db_path' => $dbPath,
                'message' => $e->getMessage(),
            ]);
            return null;
        } finally {
            @maxminddb_close($db);
        }

        return is_array($record) ? $this->extractCountryFromMaxMindArray($record) : null;
    }

    private function resolveCountryByGeoIp2Reader(string $dbPath, string $ip): ?string
    {
        if (!class_exists('\\GeoIp2\\Database\\Reader')) {
            return null;
        }

        try {
            $readerClass = '\\GeoIp2\\Database\\Reader';
            $reader = new $readerClass($dbPath);

            try {
                $record = $reader->country($ip);
            } catch (\Throwable) {
                $record = $reader->city($ip);
            }

            return is_object($record) ? $this->extractCountryFromGeoIp2Record($record) : null;
        } catch (\Throwable $e) {
            Log::warning('[NodeAutoRename] geoip2 reader lookup failed', [
                'ip' => $ip,
                'db_path' => $dbPath,
                'message' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if (isset($reader) && is_object($reader) && method_exists($reader, 'close')) {
                $reader->close();
            }
        }
    }

    private function resolveCountryByMaxMindDbReader(string $dbPath, string $ip): ?string
    {
        if (!class_exists('\\MaxMind\\Db\\Reader')) {
            return null;
        }

        try {
            $readerClass = '\\MaxMind\\Db\\Reader';
            $reader = new $readerClass($dbPath);
            $record = $reader->get($ip);
            return is_array($record) ? $this->extractCountryFromMaxMindArray($record) : null;
        } catch (\Throwable $e) {
            Log::warning('[NodeAutoRename] maxmind db reader lookup failed', [
                'ip' => $ip,
                'db_path' => $dbPath,
                'message' => $e->getMessage(),
            ]);
            return null;
        } finally {
            if (isset($reader) && is_object($reader) && method_exists($reader, 'close')) {
                $reader->close();
            }
        }
    }

    private function extractCountryFromMaxMindArray(array $record): ?string
    {
        foreach (['country', 'registered_country', 'represented_country'] as $key) {
            $country = $record[$key] ?? null;
            if (!is_array($country)) {
                continue;
            }

            $label = $this->pickCountryNameFromArray($country);
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    private function extractCountryFromGeoIp2Record(object $record): ?string
    {
        foreach (['country', 'registeredCountry', 'representedCountry'] as $key) {
            $country = $record->{$key} ?? null;
            if (!is_object($country)) {
                continue;
            }

            $label = $this->pickCountryNameFromObject($country);
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    private function pickCountryNameFromArray(array $country): ?string
    {
        $names = $country['names'] ?? null;
        if (is_array($names)) {
            foreach ($this->resolveCountryLocales() as $locale) {
                $name = $names[$locale] ?? null;
                $normalized = $this->normalizeCountryLabel(is_string($name) ? $name : null);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $this->normalizeCountryLabel(
            is_string($country['iso_code'] ?? null) ? (string) $country['iso_code'] : null
        );
    }

    private function pickCountryNameFromObject(object $country): ?string
    {
        $names = $country->names ?? null;
        if (is_array($names)) {
            foreach ($this->resolveCountryLocales() as $locale) {
                $name = $names[$locale] ?? null;
                $normalized = $this->normalizeCountryLabel(is_string($name) ? $name : null);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return $this->normalizeCountryLabel(
            is_string($country->isoCode ?? null) ? (string) $country->isoCode : null
        );
    }

    private function resolveCountryLocales(): array
    {
        $raw = $this->config['country_locales'] ?? 'zh-CN,zh,en';
        if (is_array($raw)) {
            $values = $raw;
        } else {
            $values = preg_split('/[\s,]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $locales = [];
        foreach ($values as $value) {
            $locale = trim((string) $value);
            if ($locale !== '') {
                $locales[] = $locale;
            }
        }

        if (!in_array('en', $locales, true)) {
            $locales[] = 'en';
        }

        return array_values(array_unique($locales));
    }

    private function normalizeCountryLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);
        if ($label === '' || $label === '0') {
            return null;
        }

        return $label;
    }

    private function resolveMaxMindDbPath(): ?string
    {
        if ($this->maxMindDbPathResolved) {
            return $this->maxMindDbPath;
        }

        $this->maxMindDbPathResolved = true;

        $rawPath = trim((string) ($this->config['maxmind_db_path'] ?? ''));
        if ($rawPath === '') {
            $this->maxMindDbPath = $this->resolveDefaultMaxMindDbPath();
            return $this->maxMindDbPath;
        }

        $path = $this->normalizePath($rawPath);
        if (!is_file($path) || !is_readable($path)) {
            Log::warning('[NodeAutoRename] maxmind db file not available', [
                'configured' => $rawPath,
                'resolved' => $path,
            ]);
            $this->maxMindDbPath = null;
            return null;
        }

        $this->maxMindDbPath = $path;
        return $this->maxMindDbPath;
    }

    private function resolveDefaultMaxMindDbPath(): ?string
    {
        $candidates = [];

        if (function_exists('storage_path')) {
            $candidates[] = storage_path('app/geoip/GeoLite2-City.mmdb');
            $candidates[] = storage_path('app/geoip/GeoLite2-Country.mmdb');
        }

        if (function_exists('base_path')) {
            $candidates[] = base_path('storage/app/geoip/GeoLite2-City.mmdb');
            $candidates[] = base_path('storage/app/geoip/GeoLite2-Country.mmdb');
        } else {
            $base = getcwd() ?: '';
            if ($base !== '') {
                $candidates[] = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage/app/geoip/GeoLite2-City.mmdb';
                $candidates[] = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'storage/app/geoip/GeoLite2-Country.mmdb';
            }
        }

        foreach (array_values(array_unique($candidates)) as $path) {
            if (is_string($path) && is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1) {
            return $path;
        }

        $basePath = function_exists('base_path') ? base_path() : getcwd();
        return rtrim((string) $basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
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
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $ips = $this->resolveDomainIps($host);
        if ($ips === []) {
            return null;
        }

        return $this->pickPreferredIp($ips);
    }

    private function pickPreferredIp(array $ips): ?string
    {
        $validIps = [];
        foreach ($ips as $ip) {
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                $validIps[] = $ip;
            }
        }

        if ($validIps === []) {
            return null;
        }

        foreach ($validIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $this->isPublicIp($ip)) {
                return $ip;
            }
        }

        foreach ($validIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $this->isPublicIp($ip)) {
                return $ip;
            }
        }

        foreach ($validIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $validIps[0] ?? null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
