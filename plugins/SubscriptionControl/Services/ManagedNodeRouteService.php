<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use App\Models\Plugin as PluginModel;
use App\Models\Server;
use App\Models\ServerRoute;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class ManagedNodeRouteService
{
    public const PLUGIN_CODE = 'subscription_control';
    public const ROUTE_REMARK_PREFIX = '[订阅风控托管]';

    private const MANUAL_KEY = 'manual';
    private const POLICY_BLOCK = 'block';
    private const POLICY_ALLOW = 'allow';
    private const DEFAULT_MAX_PREFIXES_PER_PROVIDER = 300;

    /**
     * ASN values are used for event/provider attribution only. Node-side
     * routes are generated from concrete IP/CIDR prefixes.
     */
    private const PROVIDERS = [
        'ucloud' => [
            'label' => 'UCloud',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [135377, 59077],
            'keywords' => ['ucloud'],
        ],
        'aliyun' => [
            'label' => '阿里云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [45102, 37963, 134963, 24429],
            'keywords' => ['aliyun', 'alibaba', 'alibaba cloud', 'taobao network'],
        ],
        'tencent' => [
            'label' => '腾讯云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [45090, 132203],
            'keywords' => ['tencent', 'tencent cloud'],
        ],
        'huawei' => [
            'label' => '华为云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [136907, 55990, 131444],
            'keywords' => ['huawei cloud', 'huawei clouds', 'huaweicloud'],
        ],
        'baidu' => [
            'label' => '百度云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['baidu cloud', 'baidu netcom'],
        ],
        'volcengine' => [
            'label' => '火山引擎',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['volcengine', 'bytedance', 'byteplus'],
        ],
        'tianyi' => [
            'label' => '天翼云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['tianyi cloud', 'ctyun'],
        ],
        'mobile_cloud' => [
            'label' => '移动云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['china mobile cloud'],
        ],
        'jdcloud' => [
            'label' => '京东云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['jd cloud', 'jcloud'],
        ],
        'kingsoft' => [
            'label' => '金山云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [],
            'keywords' => ['kingsoft cloud'],
        ],
    ];

    public function overview(): array
    {
        $config = $this->configValues();
        $policies = $this->providerPolicies($config);
        $providerCidrs = $this->providerCidrs($config);
        $eventPrefixes = $this->eventPrefixesByProvider($config, $policies);
        $routes = $this->managedRoutes();

        return [
            'plugin_installed' => PluginModel::query()->where('code', self::PLUGIN_CODE)->exists(),
            'enabled' => $this->toBool($config['enable_node_source_ip_managed_routes'] ?? true, true),
            'apply_scope' => 'all_enabled_nodes',
            'max_prefixes_per_provider' => $this->maxPrefixesPerProvider($config),
            'providers' => array_map(function (array $provider) use ($policies, $providerCidrs, $eventPrefixes): array {
                $key = $provider['key'];
                $manualCidrs = $providerCidrs[$key] ?? [];
                $learnedCidrs = $eventPrefixes[$key] ?? [];

                return [
                    ...$provider,
                    'policy' => $policies[$key] ?? self::POLICY_ALLOW,
                    'manual_cidr_count' => count($manualCidrs),
                    'learned_cidr_count' => count($learnedCidrs),
                    'cidr_count' => count(array_values(array_unique([...$manualCidrs, ...$learnedCidrs]))),
                ];
            }, $this->providerDefinitions()),
            'manual_cidr_count' => count($this->manualCidrs($config)),
            'routes' => $routes,
            'bound_route_ids' => array_values(array_column($routes, 'id')),
            'enabled_node_count' => Server::query()->where('enabled', true)->count(),
            'updated_at' => time(),
        ];
    }

    public function saveSettings(array $payload): array
    {
        $plugin = PluginModel::query()->where('code', self::PLUGIN_CODE)->first();
        if (!$plugin) {
            throw new \RuntimeException('订阅风控插件尚未安装');
        }

        $values = $this->rawDbConfig($plugin);

        if (array_key_exists('enabled', $payload)) {
            $values['enable_node_source_ip_managed_routes'] = (bool) $payload['enabled'];
        }

        if (isset($payload['max_prefixes_per_provider']) && is_numeric($payload['max_prefixes_per_provider'])) {
            $values['node_source_ip_managed_max_prefixes_per_provider'] = max(10, min(2000, (int) $payload['max_prefixes_per_provider']));
        }

        if (isset($payload['providers']) && is_array($payload['providers'])) {
            $values['node_source_ip_provider_policy'] = $this->formatProviderPolicies((array) $payload['providers']);
        }

        if (isset($payload['provider_cidrs']) && is_array($payload['provider_cidrs'])) {
            $values['node_source_ip_provider_cidrs'] = $this->formatProviderCidrs((array) $payload['provider_cidrs']);
        }

        $plugin->update([
            'config' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        try {
            app(PluginManager::class)->flushEnabledPluginsCache();
        } catch (\Throwable) {
        }

        return $this->sync($values);
    }

    public function sync(?array $config = null): array
    {
        $config ??= $this->configValues();
        $enabled = $this->toBool($config['enable_node_source_ip_managed_routes'] ?? true, true);
        $policies = $this->providerPolicies($config);
        $manualCidrs = $this->manualCidrs($config);
        $providerCidrs = $this->providerCidrs($config);
        $eventPrefixes = $this->eventPrefixesByProvider($config, $policies);

        $desired = [];
        if ($enabled && $manualCidrs !== []) {
            $desired[self::MANUAL_KEY] = [
                'remarks' => $this->managedRemark(self::MANUAL_KEY, '手动来源 IP 黑名单'),
                'match' => $this->toSourceIpRules($manualCidrs),
            ];
        }

        if ($enabled) {
            foreach ($this->providerDefinitions() as $provider) {
                $key = $provider['key'];
                if (($policies[$key] ?? self::POLICY_ALLOW) !== self::POLICY_BLOCK) {
                    continue;
                }

                $cidrs = array_values(array_unique([
                    ...($providerCidrs[$key] ?? []),
                    ...($eventPrefixes[$key] ?? []),
                ]));

                if ($cidrs === []) {
                    continue;
                }

                sort($cidrs, SORT_NATURAL);
                $desired[$this->providerRouteKey($key)] = [
                    'remarks' => $this->managedRemark($this->providerRouteKey($key), '云厂商 ' . $provider['label']),
                    'match' => $this->toSourceIpRules($cidrs),
                ];
            }
        }

        $affectedServerIds = [];
        $createdRouteIds = [];
        $updatedRouteIds = [];
        $deletedRouteIds = [];
        $activeRouteIds = [];

        DB::transaction(function () use ($desired, &$affectedServerIds, &$createdRouteIds, &$updatedRouteIds, &$deletedRouteIds, &$activeRouteIds): void {
            $existing = ServerRoute::query()
                ->where('remarks', 'like', self::ROUTE_REMARK_PREFIX . '%')
                ->get()
                ->keyBy(fn(ServerRoute $route): string => $this->routeKeyFromRemark((string) $route->remarks));

            foreach ($desired as $key => $routeData) {
                /** @var ServerRoute|null $route */
                $route = $existing->get($key);
                $attributes = [
                    'remarks' => $routeData['remarks'],
                    'action' => 'block',
                    'action_value' => null,
                    'match' => $routeData['match'],
                ];

                if ($route) {
                    $oldMatch = (array) ($route->match ?? []);
                    if ($route->action !== 'block' || $route->action_value !== null || $oldMatch !== $attributes['match'] || $route->remarks !== $attributes['remarks']) {
                        $route->update($attributes);
                        $updatedRouteIds[] = (int) $route->id;
                    }
                } else {
                    $route = ServerRoute::create($attributes);
                    $createdRouteIds[] = (int) $route->id;
                }

                $activeRouteIds[] = (int) $route->id;
            }

            foreach ($existing as $key => $route) {
                if (isset($desired[$key])) {
                    continue;
                }

                $routeId = (int) $route->id;
                $affectedServerIds = array_merge($affectedServerIds, $this->serversContainingRoute($routeId));
                $this->removeRouteFromAllServers($routeId, $affectedServerIds);
                $route->delete();
                $deletedRouteIds[] = $routeId;
            }

            $affectedServerIds = array_merge($affectedServerIds, $this->bindRoutesToEnabledServers($activeRouteIds));
            $affectedServerIds = $this->normalizeIds($affectedServerIds);
        });

        $this->invalidateServers($affectedServerIds, array_values(array_unique([...$createdRouteIds, ...$updatedRouteIds, ...$deletedRouteIds])));

        return [
            'enabled' => $enabled,
            'created_route_ids' => $this->normalizeIds($createdRouteIds),
            'updated_route_ids' => $this->normalizeIds($updatedRouteIds),
            'deleted_route_ids' => $this->normalizeIds($deletedRouteIds),
            'active_route_ids' => $this->normalizeIds($activeRouteIds),
            'affected_server_ids' => $affectedServerIds,
            'manual_cidr_count' => count($manualCidrs),
            'provider_count' => count(array_filter($policies, fn(string $policy): bool => $policy === self::POLICY_BLOCK)),
            'routes' => $this->managedRoutes(),
        ];
    }

    public function providerDefinitions(): array
    {
        return array_map(
            static fn(string $key, array $provider): array => [
                'key' => $key,
                'label' => $provider['label'],
                'default_policy' => $provider['default_policy'],
                'asns' => $provider['asns'],
                'keywords' => $provider['keywords'],
            ],
            array_keys(self::PROVIDERS),
            self::PROVIDERS
        );
    }

    private function managedRoutes(): array
    {
        return ServerRoute::query()
            ->where('remarks', 'like', self::ROUTE_REMARK_PREFIX . '%')
            ->orderBy('id')
            ->get()
            ->map(fn(ServerRoute $route): array => [
                'id' => (int) $route->id,
                'remarks' => (string) $route->remarks,
                'key' => $this->routeKeyFromRemark((string) $route->remarks),
                'match_count' => count((array) ($route->match ?? [])),
                'action' => (string) $route->action,
            ])
            ->all();
    }

    private function configValues(): array
    {
        try {
            $wrapped = app(PluginConfigService::class)->getConfig(self::PLUGIN_CODE);
        } catch (\Throwable) {
            $wrapped = [];
        }

        $values = [];
        foreach ($wrapped as $key => $item) {
            if (is_array($item) && array_key_exists('value', $item)) {
                $values[$key] = $item['value'];
            }
        }

        return array_merge($this->managedDefaults(), $values);
    }

    private function managedDefaults(): array
    {
        return [
            'enable_node_source_ip_managed_routes' => true,
            'node_source_ip_provider_policy' => $this->formatProviderPolicies([]),
            'node_source_ip_provider_cidrs' => '',
            'node_source_ip_managed_max_prefixes_per_provider' => self::DEFAULT_MAX_PREFIXES_PER_PROVIDER,
            'source_ip_deny_cidrs' => '',
        ];
    }

    private function rawDbConfig(PluginModel $plugin): array
    {
        $decoded = json_decode((string) ($plugin->config ?? ''), true);
        return is_array($decoded) ? array_merge($this->managedDefaults(), $decoded) : $this->managedDefaults();
    }

    private function providerPolicies(array $config): array
    {
        $policies = [];
        foreach (self::PROVIDERS as $key => $provider) {
            $policies[$key] = $provider['default_policy'];
        }

        $raw = $config['node_source_ip_provider_policy'] ?? '';
        if (is_array($raw)) {
            foreach ($raw as $key => $policy) {
                $this->assignProviderPolicy($policies, (string) $key, (string) $policy);
            }
            return $policies;
        }

        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $key => $policy) {
                $this->assignProviderPolicy($policies, (string) $key, (string) $policy);
            }
            return $policies;
        }

        foreach (preg_split('/[\r\n,]+/', (string) $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $policy] = array_map('trim', explode('=', $line, 2));
            $this->assignProviderPolicy($policies, $key, $policy);
        }

        return $policies;
    }

    private function assignProviderPolicy(array &$policies, string $key, string $policy): void
    {
        $key = $this->normalizeProviderKey($key);
        if (!isset(self::PROVIDERS[$key])) {
            return;
        }

        $policy = strtolower(trim($policy));
        $policies[$key] = $policy === self::POLICY_ALLOW ? self::POLICY_ALLOW : self::POLICY_BLOCK;
    }

    private function formatProviderPolicies(array $input): string
    {
        $policies = [];
        foreach (self::PROVIDERS as $key => $provider) {
            $value = $input[$key] ?? $provider['default_policy'];
            if (is_array($value)) {
                $value = $value['policy'] ?? $provider['default_policy'];
            }
            $policies[] = $key . '=' . (strtolower((string) $value) === self::POLICY_ALLOW ? self::POLICY_ALLOW : self::POLICY_BLOCK);
        }

        return implode("\n", $policies);
    }

    private function providerCidrs(array $config): array
    {
        $result = [];
        foreach (self::PROVIDERS as $key => $_) {
            $result[$key] = [];
        }

        $raw = $config['node_source_ip_provider_cidrs'] ?? '';
        if (is_array($raw)) {
            foreach ($raw as $key => $cidrs) {
                $key = $this->normalizeProviderKey((string) $key);
                if (!isset($result[$key])) {
                    continue;
                }
                $result[$key] = $this->validCidrs(is_array($cidrs) ? $cidrs : preg_split('/[\r\n,]+/', (string) $cidrs));
            }
            return $result;
        }

        $currentProvider = null;
        foreach (preg_split('/\R/', (string) $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^\[([a-zA-Z0-9_-]+)]$/', $line, $matches)) {
                $candidate = $this->normalizeProviderKey($matches[1]);
                $currentProvider = isset($result[$candidate]) ? $candidate : null;
                continue;
            }

            if ($currentProvider === null) {
                continue;
            }

            $cidr = $this->normalizeCidr($line);
            if ($cidr !== null) {
                $result[$currentProvider][] = $cidr;
            }
        }

        foreach ($result as $key => $cidrs) {
            $result[$key] = array_values(array_unique($cidrs));
        }

        return $result;
    }

    private function formatProviderCidrs(array $input): string
    {
        $sections = [];
        foreach (self::PROVIDERS as $key => $_) {
            $cidrs = $this->validCidrs((array) ($input[$key] ?? []));
            if ($cidrs === []) {
                continue;
            }
            $sections[] = '[' . $key . ']';
            array_push($sections, ...$cidrs);
            $sections[] = '';
        }

        return rtrim(implode("\n", $sections));
    }

    private function manualCidrs(array $config): array
    {
        return $this->validCidrs(preg_split('/[\r\n,]+/', (string) ($config['source_ip_deny_cidrs'] ?? '')) ?: []);
    }

    private function eventPrefixesByProvider(array $config, array $policies): array
    {
        $result = [];
        foreach (self::PROVIDERS as $key => $_) {
            $result[$key] = [];
        }

        if (!$this->toBool($config['enable_node_source_ip_route_learned_prefixes'] ?? true, true)) {
            return $result;
        }

        if (!$this->eventTableAvailable()) {
            return $result;
        }

        $max = $this->maxPrefixesPerProvider($config);
        $since = time() - (3 * 86400);

        try {
            DB::table('v2_subscription_control_event')
                ->select(['client_ip', 'ip_prefix', 'ip_asn', 'ip_org'])
                ->where('created_at', '>=', $since)
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get()
                ->each(function ($row) use (&$result, $policies, $max): void {
                    $event = (array) $row;
                    $providerKey = $this->providerKeyForEvent($event);
                    if ($providerKey === null || ($policies[$providerKey] ?? self::POLICY_ALLOW) !== self::POLICY_BLOCK) {
                        return;
                    }

                    if (count($result[$providerKey]) >= $max) {
                        return;
                    }

                    $cidr = $this->normalizeCidr((string) ($event['ip_prefix'] ?? ''))
                        ?? $this->normalizeCidr((string) ($event['client_ip'] ?? ''));
                    if ($cidr === null) {
                        return;
                    }

                    $result[$providerKey][] = $cidr;
                });
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 托管节点路由读取风控事件失败', [
                'error' => $e->getMessage(),
            ]);
        }

        foreach ($result as $key => $cidrs) {
            $result[$key] = array_slice(array_values(array_unique($cidrs)), 0, $max);
        }

        return $result;
    }

    private function providerKeyForEvent(array $event): ?string
    {
        $asn = isset($event['ip_asn']) && is_numeric($event['ip_asn']) ? (int) $event['ip_asn'] : null;
        $org = strtolower((string) ($event['ip_org'] ?? ''));

        foreach (self::PROVIDERS as $key => $provider) {
            if ($asn !== null && in_array($asn, $provider['asns'], true)) {
                return $key;
            }

            foreach ($provider['keywords'] as $keyword) {
                if ($org !== '' && str_contains($org, strtolower($keyword))) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function eventTableAvailable(): bool
    {
        try {
            return Schema::hasTable('v2_subscription_control_event');
        } catch (\Throwable) {
            return false;
        }
    }

    private function maxPrefixesPerProvider(array $config): int
    {
        $value = $config['node_source_ip_managed_max_prefixes_per_provider'] ?? self::DEFAULT_MAX_PREFIXES_PER_PROVIDER;
        return max(10, min(2000, is_numeric($value) ? (int) $value : self::DEFAULT_MAX_PREFIXES_PER_PROVIDER));
    }

    private function toSourceIpRules(array $cidrs): array
    {
        $rules = array_map(static fn(string $cidr): string => 'source_ip:' . $cidr, $cidrs);
        return array_values(array_unique($rules));
    }

    private function validCidrs(array $values): array
    {
        $cidrs = [];
        foreach ($values as $value) {
            $cidr = $this->normalizeCidr((string) $value);
            if ($cidr !== null) {
                $cidrs[] = $cidr;
            }
        }

        $cidrs = array_values(array_unique($cidrs));
        sort($cidrs, SORT_NATURAL);
        return $cidrs;
    }

    private function normalizeCidr(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'source_ip:')) {
            $value = substr($value, strlen('source_ip:'));
        }

        $ip = $value;
        $prefix = null;
        if (str_contains($value, '/')) {
            [$ip, $prefix] = array_map('trim', explode('/', $value, 2));
            if ($prefix === '' || !ctype_digit($prefix)) {
                return null;
            }
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($prefix !== null && ((int) $prefix < 0 || (int) $prefix > 32)) {
                return null;
            }
            return $prefix === null ? $ip : $ip . '/' . (int) $prefix;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($prefix !== null && ((int) $prefix < 0 || (int) $prefix > 128)) {
                return null;
            }
            return $prefix === null ? $ip : strtolower($ip) . '/' . (int) $prefix;
        }

        return null;
    }

    private function bindRoutesToEnabledServers(array $routeIds): array
    {
        $routeIds = $this->normalizeIds($routeIds);
        if ($routeIds === []) {
            return [];
        }

        $affected = [];
        Server::query()
            ->where('enabled', true)
            ->orderBy('id')
            ->get(['id', 'route_ids'])
            ->each(function (Server $server) use ($routeIds, &$affected): void {
                $current = $this->normalizeIds((array) ($server->route_ids ?? []));
                $next = $this->normalizeIds([...$current, ...$routeIds]);
                if ($current === $next) {
                    return;
                }

                $server->route_ids = $next;
                $server->save();
                $affected[] = (int) $server->id;
            });

        return $this->normalizeIds($affected);
    }

    private function removeRouteFromAllServers(int $routeId, array &$affectedServerIds): void
    {
        Server::query()
            ->orderBy('id')
            ->get(['id', 'route_ids'])
            ->each(function (Server $server) use ($routeId, &$affectedServerIds): void {
                $current = $this->normalizeIds((array) ($server->route_ids ?? []));
                if (!in_array($routeId, $current, true)) {
                    return;
                }

                $server->route_ids = array_values(array_filter($current, fn(int $id): bool => $id !== $routeId));
                $server->save();
                $affectedServerIds[] = (int) $server->id;
            });
    }

    private function serversContainingRoute(int $routeId): array
    {
        $ids = [];
        Server::query()
            ->orderBy('id')
            ->get(['id', 'route_ids'])
            ->each(function (Server $server) use ($routeId, &$ids): void {
                if (in_array($routeId, $this->normalizeIds((array) ($server->route_ids ?? [])), true)) {
                    $ids[] = (int) $server->id;
                }
            });

        return $this->normalizeIds($ids);
    }

    private function invalidateServers(array $serverIds, array $routeIds): void
    {
        if ($serverIds === [] && $routeIds === []) {
            return;
        }

        try {
            $publisher = app(NodeRealtimePublisher::class);
            if ($serverIds !== []) {
                $publisher->invalidateConfigForServers($serverIds, 'subscription_control.managed_source_ip_routes.synced', [
                    'route_ids' => $routeIds,
                ]);
                return;
            }

            $publisher->invalidateConfigForRoutes($routeIds, 'subscription_control.managed_source_ip_routes.synced');
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 托管节点路由实时通知失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function managedRemark(string $key, string $label): string
    {
        return self::ROUTE_REMARK_PREFIX . ' ' . $key . ' ' . $label;
    }

    private function routeKeyFromRemark(string $remarks): string
    {
        $text = trim(substr($remarks, strlen(self::ROUTE_REMARK_PREFIX)));
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $text, 2);
        return (string) ($parts[0] ?? '');
    }

    private function providerRouteKey(string $providerKey): string
    {
        return 'provider:' . $providerKey;
    }

    private function normalizeProviderKey(string $key): string
    {
        return strtolower(str_replace([' ', '.'], '_', trim($key)));
    }

    private function normalizeIds(array $ids): array
    {
        $ids = array_map('intval', array_filter($ids, 'is_numeric'));
        $ids = array_values(array_unique(array_filter($ids, fn(int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    private function toBool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }
}
