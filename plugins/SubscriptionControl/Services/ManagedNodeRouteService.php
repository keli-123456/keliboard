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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class ManagedNodeRouteService
{
    public const PLUGIN_CODE = 'subscription_control';
    public const ROUTE_REMARK_PREFIX = '[订阅风控托管]';

    private const POLICY_BLOCK = 'block';
    private const POLICY_ALLOW = 'allow';
    private const DEFAULT_MAX_PREFIXES_PER_PROVIDER = 300;
    private const BGP_CACHE_KEY = 'subscription_control:managed_node_route:bgp_provider_cidrs:v2';

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
            'asns' => [45090, 132203, 132591, 134103, 133478, 139341, 58835],
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
            'asns' => [55967, 38365, 38627, 133746, 63288],
            'keywords' => ['baidu cloud', 'baidu netcom'],
        ],
        'volcengine' => [
            'label' => '火山引擎',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [396986, 138699],
            'keywords' => ['volcengine', 'bytedance', 'byteplus'],
        ],
        'tianyi' => [
            'label' => '天翼云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [58519],
            'keywords' => ['tianyi cloud', 'ctyun'],
        ],
        'mobile_cloud' => [
            'label' => '移动云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [138407, 24547],
            'keywords' => ['china mobile cloud', 'ecloud'],
        ],
        'jdcloud' => [
            'label' => '京东云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [137753],
            'keywords' => ['jd cloud', 'jcloud', 'jingdong'],
        ],
        'kingsoft' => [
            'label' => '金山云',
            'default_policy' => self::POLICY_BLOCK,
            'asns' => [59019],
            'keywords' => ['kingsoft cloud'],
        ],
    ];

    private const BUILTIN_PROVIDER_CIDRS = [
        'ucloud' => [
            '165.154.0.0/17', '113.31.192.0/18', '152.32.128.0/18', '165.154.128.0/18',
            '101.36.96.0/19', '152.32.224.0/19', '107.150.96.0/20', '118.193.32.0/20',
            '118.193.64.0/20', '123.58.208.0/20', '152.32.208.0/20', '106.75.192.0/21',
            '107.150.120.0/21', '118.193.56.0/21', '118.194.232.0/21', '118.194.248.0/21',
            '118.26.104.0/21', '123.58.192.0/21', '128.14.224.0/21', '134.168.248.0/21',
        ],
        'aliyun' => [
            '47.96.0.0/12', '39.96.0.0/13', '47.112.0.0/13', '8.136.0.0/13',
            '8.152.0.0/13', '8.216.0.0/13', '112.124.0.0/14', '120.24.0.0/14',
            '120.76.0.0/14', '121.196.0.0/14', '121.40.0.0/14', '223.4.0.0/14',
            '39.104.0.0/14', '43.104.0.0/14', '47.120.0.0/14', '47.236.0.0/14',
            '47.240.0.0/14', '47.80.0.0/14', '47.92.0.0/14', '8.132.0.0/14',
        ],
        'tencent' => [
            '43.136.0.0/13', '1.12.0.0/14', '106.52.0.0/14', '124.220.0.0/14',
            '43.176.0.0/14', '49.232.0.0/14', '81.68.0.0/14', '1.116.0.0/15',
            '101.34.0.0/15', '101.42.0.0/15', '111.230.0.0/15', '118.24.0.0/15',
            '121.4.0.0/15', '123.206.0.0/15', '42.192.0.0/15', '43.144.0.0/15',
            '43.154.0.0/15', '43.156.0.0/15', '43.162.0.0/15', '43.166.0.0/15',
        ],
        'huawei' => [
            '1.94.0.0/15', '121.36.0.0/15', '1.92.0.0/16', '101.44.0.0/16',
            '110.41.0.0/16', '113.44.0.0/16', '113.46.0.0/16', '116.205.0.0/16',
            '119.3.0.0/16', '120.46.0.0/16', '123.60.0.0/16', '124.70.0.0/16',
            '124.81.0.0/16', '60.204.0.0/16', '101.245.0.0/17', '101.46.128.0/17',
            '111.91.0.0/17', '113.45.128.0/17', '115.120.0.0/17', '115.175.128.0/17',
        ],
        'baidu' => [
            '120.48.0.0/16', '106.12.0.0/17', '106.13.0.0/17', '120.49.0.0/17',
            '180.76.0.0/17', '106.12.128.0/18', '106.13.128.0/18', '120.49.192.0/18',
            '180.76.128.0/18', '182.61.0.0/18', '106.12.192.0/19', '106.13.192.0/19',
            '180.76.224.0/19', '182.61.160.0/19', '182.61.224.0/19', '106.12.224.0/20',
            '106.13.224.0/20', '119.75.208.0/20', '154.85.48.0/20', '180.76.208.0/20',
        ],
        'volcengine' => [
            '71.18.128.0/20', '71.18.96.0/20', '139.177.240.0/21', '202.52.240.0/21',
            '71.18.120.0/21', '71.18.152.0/21', '71.18.160.0/21', '71.18.200.0/21',
            '71.18.208.0/21', '71.18.32.0/21', '71.18.48.0/21', '71.18.64.0/21',
            '71.18.88.0/21', '101.45.192.0/22', '101.45.248.0/22', '71.18.116.0/22',
            '71.18.144.0/22', '71.18.16.0/22', '71.18.168.0/22', '71.18.176.0/22',
        ],
        'tianyi' => [
            '113.125.0.0/16', '140.246.0.0/16', '150.223.0.0/16', '182.42.0.0/16',
            '36.114.0.0/16', '182.43.128.0/17', '116.63.128.0/18', '182.43.64.0/18',
            '182.44.0.0/18', '36.111.128.0/18', '42.123.64.0/18', '122.9.128.0/19',
            '140.210.192.0/19', '182.43.32.0/19', '203.189.192.0/19', '203.193.224.0/19',
            '203.195.64.0/19', '122.9.160.0/20', '139.9.144.0/20', '139.9.224.0/20',
        ],
        'mobile_cloud' => [
            '183.196.0.0/14', '111.62.0.0/15', '111.61.0.0/16', '120.211.0.0/16',
            '36.143.0.0/16', '111.11.0.0/17', '36.144.0.0/17', '211.143.64.0/18',
            '218.207.64.0/19', '112.53.192.0/20', '117.132.144.0/20', '117.132.160.0/20',
            '117.187.128.0/20', '211.138.0.0/20', '211.143.128.0/20', '211.143.48.0/20',
            '112.54.104.0/21', '117.187.112.0/21', '117.187.184.0/21', '117.187.208.0/21',
        ],
        'jdcloud' => [
            '1.118.64.0/19', '1.118.48.0/21', '1.118.32.0/22', '1.118.2.0/24', '1.118.36.0/24',
        ],
        'kingsoft' => [
            '110.43.0.0/16', '120.92.0.0/17', '120.92.128.0/18', '120.131.0.0/20',
            '120.92.224.0/20', '103.26.64.0/22', '120.92.216.0/22', '120.92.192.0/23',
            '120.92.209.0/24', '120.92.211.0/24',
        ],
    ];

    public function overview(): array
    {
        $config = $this->configValues();
        $policies = $this->providerPolicies($config);
        $providerCidrs = $this->providerCidrs($config);
        $builtinCidrs = $this->builtinProviderCidrs($config);
        $bgpCidrs = $this->cachedBgpProviderCidrs($config);
        $eventPrefixes = $this->eventPrefixesByProvider($config, $policies);
        $routes = $this->managedRoutes();

        $providers = array_map(function (array $provider) use ($policies, $providerCidrs, $builtinCidrs, $bgpCidrs, $eventPrefixes): array {
            $key = $provider['key'];
            $manualCidrs = $providerCidrs[$key] ?? [];
            $staticCidrs = $builtinCidrs[$key] ?? [];
            $remoteCidrs = $bgpCidrs[$key] ?? [];
            $learnedCidrs = $eventPrefixes[$key] ?? [];

            return [
                ...$provider,
                'policy' => $policies[$key] ?? self::POLICY_ALLOW,
                'manual_cidr_count' => count($manualCidrs),
                'builtin_cidr_count' => count($staticCidrs),
                'bgp_cidr_count' => count($remoteCidrs),
                'learned_cidr_count' => count($learnedCidrs),
                'cidr_count' => count(array_values(array_unique([
                    ...$manualCidrs,
                    ...$staticCidrs,
                    ...$remoteCidrs,
                    ...$learnedCidrs,
                ]))),
            ];
        }, $this->providerDefinitions());

        return [
            'plugin_installed' => PluginModel::query()->where('code', self::PLUGIN_CODE)->exists(),
            'enabled' => $this->toBool($config['enable_node_source_ip_managed_routes'] ?? true, true),
            'apply_scope' => 'all_enabled_nodes',
            'max_prefixes_per_provider' => $this->maxPrefixesPerProvider($config),
            'providers' => $providers,
            'bgp_refresh_enabled' => $this->toBool($config['enable_node_source_ip_bgp_prefix_refresh'] ?? true, true),
            'bgp_cache_updated_at' => (int) ($this->bgpCachePayload($config)['updated_at'] ?? 0),
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

        if (array_key_exists('bgp_refresh_enabled', $payload)) {
            $values['enable_node_source_ip_bgp_prefix_refresh'] = (bool) $payload['bgp_refresh_enabled'];
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
        $builtinCidrs = $this->builtinProviderCidrs($config);
        $bgpCidrs = $this->refreshBgpProviderCidrsIfNeeded($config, $policies);
        $eventPrefixes = $this->eventPrefixesByProvider($config, $policies);

        $desired = [];
        if ($enabled) {
            foreach ($this->providerDefinitions() as $provider) {
                $key = $provider['key'];
                if (($policies[$key] ?? self::POLICY_ALLOW) !== self::POLICY_BLOCK) {
                    continue;
                }

                $cidrs = array_values(array_unique([
                    ...($providerCidrs[$key] ?? []),
                    ...($builtinCidrs[$key] ?? []),
                    ...($bgpCidrs[$key] ?? []),
                    ...($eventPrefixes[$key] ?? []),
                ]));

                if ($cidrs === []) {
                    continue;
                }

                $cidrs = $this->sortCidrs($cidrs);
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
            'enable_node_source_ip_builtin_provider_cidrs' => true,
            'enable_node_source_ip_bgp_prefix_refresh' => true,
            'node_source_ip_provider_policy' => $this->formatProviderPolicies([]),
            'node_source_ip_provider_cidrs' => '',
            'node_source_ip_managed_max_prefixes_per_provider' => self::DEFAULT_MAX_PREFIXES_PER_PROVIDER,
            'node_source_ip_bgp_prefix_cache_hours' => 24,
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

    private function builtinProviderCidrs(array $config): array
    {
        $result = [];
        foreach (self::PROVIDERS as $key => $_) {
            $result[$key] = [];
        }

        if (!$this->toBool($config['enable_node_source_ip_builtin_provider_cidrs'] ?? true, true)) {
            return $result;
        }

        foreach (self::BUILTIN_PROVIDER_CIDRS as $key => $cidrs) {
            if (!isset($result[$key])) {
                continue;
            }
            $result[$key] = $this->validCidrs($cidrs);
        }

        return $result;
    }

    private function cachedBgpProviderCidrs(array $config): array
    {
        $empty = [];
        foreach (self::PROVIDERS as $key => $_) {
            $empty[$key] = [];
        }

        $payload = $this->bgpCachePayload($config);
        if (!is_array($payload['providers'] ?? null)) {
            return $empty;
        }

        foreach ($payload['providers'] as $key => $cidrs) {
            $key = $this->normalizeProviderKey((string) $key);
            if (!isset($empty[$key])) {
                continue;
            }
            $empty[$key] = $this->validCidrs(is_array($cidrs) ? $cidrs : []);
        }

        return $empty;
    }

    private function refreshBgpProviderCidrsIfNeeded(array $config, array $policies): array
    {
        if (!$this->toBool($config['enable_node_source_ip_bgp_prefix_refresh'] ?? true, true)) {
            return $this->cachedBgpProviderCidrs($config);
        }

        $payload = $this->bgpCachePayload($config);
        $ttlSeconds = max(1, (int) ($config['node_source_ip_bgp_prefix_cache_hours'] ?? 24)) * 3600;
        $updatedAt = (int) ($payload['updated_at'] ?? 0);
        if ($updatedAt > 0 && (time() - $updatedAt) < $ttlSeconds) {
            return $this->cachedBgpProviderCidrs($config);
        }

        $next = [
            'updated_at' => time(),
            'providers' => (array) ($payload['providers'] ?? []),
            'errors' => [],
        ];
        $max = $this->maxPrefixesPerProvider($config);

        foreach (self::PROVIDERS as $key => $provider) {
            if (($policies[$key] ?? self::POLICY_ALLOW) !== self::POLICY_BLOCK) {
                continue;
            }

            $fetched = $this->fetchBgpCidrsForAsns((array) $provider['asns'], $max);
            if ($fetched['cidrs'] !== []) {
                $next['providers'][$key] = $fetched['cidrs'];
            }
            if ($fetched['errors'] !== []) {
                $next['errors'][$key] = $fetched['errors'];
            }
        }

        Cache::put(self::BGP_CACHE_KEY, $next, $ttlSeconds + 3600);
        if ($next['errors'] !== []) {
            Log::warning('[SubscriptionControl] 云厂商 BGP CIDR 刷新存在失败项', [
                'errors' => $next['errors'],
            ]);
        }

        return $this->cachedBgpProviderCidrs($config);
    }

    private function fetchBgpCidrsForAsns(array $asns, int $max): array
    {
        $cidrs = [];
        $errors = [];

        foreach ($asns as $asn) {
            $asn = is_numeric($asn) ? (int) $asn : 0;
            if ($asn <= 0) {
                continue;
            }

            try {
                $response = Http::timeout(8)
                    ->acceptJson()
                    ->get("https://api.routeviews.org/asn/{$asn}");
                if (!$response->ok()) {
                    $errors[] = "AS{$asn}:http_" . $response->status();
                    continue;
                }

                $data = $response->json();
                $values = is_array($data) && array_is_list($data)
                    ? $data
                    : (is_array($data) ? (array) ($data['value'] ?? []) : []);
                foreach ($values as $value) {
                    $cidr = $this->normalizeCidr((string) $value);
                    if ($cidr !== null) {
                        $cidrs[] = $cidr;
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = "AS{$asn}:" . $e->getMessage();
            }
        }

        return [
            'cidrs' => array_slice($this->sortCidrs(array_values(array_unique($cidrs))), 0, $max),
            'errors' => $errors,
        ];
    }

    private function bgpCachePayload(array $config): array
    {
        $payload = Cache::get(self::BGP_CACHE_KEY);
        if (is_array($payload)) {
            return $payload;
        }

        $raw = $config['node_source_ip_bgp_cached_provider_cidrs'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return ['updated_at' => 0, 'providers' => [], 'errors' => []];
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

    private function sortCidrs(array $cidrs): array
    {
        $cidrs = $this->validCidrs($cidrs);
        usort($cidrs, function (string $a, string $b): int {
            $prefixA = $this->cidrPrefixLength($a);
            $prefixB = $this->cidrPrefixLength($b);
            if ($prefixA !== $prefixB) {
                return $prefixA <=> $prefixB;
            }

            $familyA = str_contains($a, ':') ? 6 : 4;
            $familyB = str_contains($b, ':') ? 6 : 4;
            if ($familyA !== $familyB) {
                return $familyA <=> $familyB;
            }

            return strnatcmp($a, $b);
        });

        return $cidrs;
    }

    private function cidrPrefixLength(string $cidr): int
    {
        if (str_contains($cidr, '/')) {
            $prefix = substr(strrchr($cidr, '/'), 1);
            return ctype_digit($prefix) ? (int) $prefix : 129;
        }

        return str_contains($cidr, ':') ? 128 : 32;
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
