<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateAliveDataJob;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Services\UserOnlineService;
use Illuminate\Http\JsonResponse;
use Throwable;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly UserOnlineService $userOnlineService
    ) {
    }

    /**
     * 获取当前请求的节点信息
     */
    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    private function getServerApiCache()
    {
        $store = config('server_api_cache.store');
        try {
            return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
        } catch (Throwable) {
            return Cache::store();
        }
    }

    /**
     * 统一节点状态缓存的服务端 ID（子节点归属到父节点）
     */
    private function getNodeCacheServerId($node): int
    {
        return (int) ($node->parent_id ?: $node->id);
    }

    /**
     * 更新节点最后检查时间，用于面板在线状态判断
     */
    private function touchNodeLastCheckAt($node): void
    {
        $nodeType = (string) $node->type;
        $nodeId = $this->getNodeCacheServerId($node);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_CHECK_AT', $nodeId), time(), 3600);
    }

    // 后端获取用户
		    public function user(Request $request)
		    {
		        ini_set('memory_limit', -1);
		        $node = $this->getNodeInfo($request);
		        $this->touchNodeLastCheckAt($node);
	        $cacheTtl = (int) config('server_api_cache.user_ttl', 0);
	        $lockTtl = (int) config('server_api_cache.lock_ttl', 10);
	        $lockWait = (int) config('server_api_cache.lock_wait', 3);

		        if ($cacheTtl > 0) {
		            $cache = $this->getServerApiCache();
		            $cacheKey = "server_api:user:{$node->id}";
		            $cached = $cache->get($cacheKey);
		            if (is_array($cached) && isset($cached['etag'], $cached['body'])) {
		                return $this->respondCacheEntry($request, $cached);
		            }

		            try {
		                $lock = $cache->lock("lock:{$cacheKey}", $lockTtl);
	                $cached = $lock->block($lockWait, function () use ($cache, $cacheKey, $cacheTtl, $node) {
	                    $existing = $cache->get($cacheKey);
	                    if (is_array($existing) && isset($existing['etag'], $existing['body'])) {
	                        return $existing;
	                    }
	                    $entry = $this->buildUserCacheEntry($node);
	                    $cache->put($cacheKey, $entry, $cacheTtl);
	                    return $entry;
	                });
	            } catch (Throwable) {
	                $cached = $this->buildUserCacheEntry($node);
	                try {
	                    $cache->put($cacheKey, $cached, $cacheTtl);
	                } catch (Throwable) {
	                }
		            }

		            if (is_array($cached) && isset($cached['etag'], $cached['body'])) {
		                return $this->respondCacheEntry($request, $cached);
	            }
	        }

	        return $this->respondCacheEntry($request, $this->buildUserCacheEntry($node));
	    }

    // 后端提交数据
    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }
        $data = array_filter($res, function ($item) {
            return is_array($item)
                && count($item) === 2
                && is_numeric($item[0])
                && is_numeric($item[1]);
        });
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);
        if (empty($data)) {
            return $this->success(true);
        }
        $nodeType = $node->type;
        $nodeId = $this->getNodeCacheServerId($node);

        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId),
            count($data),
            3600
        );
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId),
            time(),
            3600
        );

        $userService = new UserService();
        $userService->trafficFetch($node, $nodeType, $data);
        return $this->success(true);
    }

    // 后端获取配置
		    public function config(Request $request)
		    {
		        $node = $this->getNodeInfo($request);
		        $this->touchNodeLastCheckAt($node);
		        $isV2Node = (bool) $request->attributes->get('is_v2node', false);

	        $cacheTtl = (int) config('server_api_cache.config_ttl', 0);
		        $lockTtl = (int) config('server_api_cache.lock_ttl', 10);
		        $lockWait = (int) config('server_api_cache.lock_wait', 3);
		        if ($cacheTtl > 0) {
		            $cache = $this->getServerApiCache();
		            $cacheKeySuffix = $isV2Node ? 'v2node' : 'default';
		            $cacheKey = "server_api:config:{$node->id}:{$cacheKeySuffix}";
		            $cached = $cache->get($cacheKey);
		            if (is_array($cached) && isset($cached['etag'], $cached['body'])) {
		                return $this->respondCacheEntry($request, $cached);
		            }

		            try {
		                $lock = $cache->lock("lock:{$cacheKey}", $lockTtl);
		                $cached = $lock->block($lockWait, function () use ($cache, $cacheKey, $cacheTtl, $node, $isV2Node) {
		                    $existing = $cache->get($cacheKey);
		                    if (is_array($existing) && isset($existing['etag'], $existing['body'])) {
		                        return $existing;
		                    }
		                    $entry = $this->buildConfigCacheEntry($node, $isV2Node);
		                    $cache->put($cacheKey, $entry, $cacheTtl);
		                    return $entry;
		                });
		            } catch (Throwable) {
		                $cached = $this->buildConfigCacheEntry($node, $isV2Node);
		                try {
		                    $cache->put($cacheKey, $cached, $cacheTtl);
		                } catch (Throwable) {
		                }
		            }

		            if (is_array($cached) && isset($cached['etag'], $cached['body'])) {
		                return $this->respondCacheEntry($request, $cached);
	            }
	        }

	        return $this->respondCacheEntry($request, $this->buildConfigCacheEntry($node, $isV2Node));
	    }

    // 获取在线用户数据（wyx2685
    public function alivelist(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);
        $deviceLimitUsers = ServerService::getAvailableUsers($node, true);
        $alive = $this->userOnlineService->getAliveList($deviceLimitUsers);
        return response()->json(['alive' => (object) $alive]);
    }

    // 后端提交在线数据
    public function alive(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);
        $data = json_decode(request()->getContent(), true);
        if ($data === null) {
            return response()->json([
                'error' => 'Invalid online data'
            ], 400);
        }
        UpdateAliveDataJob::dispatch($data, $node->type, $node->id);
        return response()->json(['data' => true]);
    }

    // 提交节点负载状态
    public function status(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);

        $data = $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'required|integer|min:0',
            'swap.used' => 'required|integer|min:0',
            'disk.total' => 'required|integer|min:0',
            'disk.used' => 'required|integer|min:0',
        ]);

        $nodeType = $node->type;
        $nodeId = $this->getNodeCacheServerId($node);

        $statusData = [
            'cpu' => (float) $data['cpu'],
            'mem' => [
                'total' => (int) $data['mem']['total'],
                'used' => (int) $data['mem']['used'],
            ],
            'swap' => [
                'total' => (int) $data['swap']['total'],
                'used' => (int) $data['swap']['used'],
            ],
            'disk' => [
                'total' => (int) $data['disk']['total'],
                'used' => (int) $data['disk']['used'],
            ],
            'updated_at' => now()->timestamp,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LOAD_STATUS', $nodeId) => $statusData,
            CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_LOAD_AT', $nodeId) => now()->timestamp,
        ], $cacheTime);

        return response()->json(['data' => true, "code" => 0, "message" => "success"]);
    }

    private function adaptConfigForV2Node(array $response, $node): array
    {
        $nodeType = (string) $node->type;
        $protocolSettings = $node->protocol_settings;

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
            $encryption = (string) data_get($protocolSettings, 'encryption', '');
            $response['encryption'] = $encryption;
            $response['encryption_settings'] = [
                'mode' => (string) data_get($protocolSettings, 'encryption_settings.mode', ''),
                'ticket' => (string) data_get($protocolSettings, 'encryption_settings.ticket', ''),
                'server_padding' => (string) data_get($protocolSettings, 'encryption_settings.server_padding', ''),
                'private_key' => (string) data_get($protocolSettings, 'encryption_settings.private_key', ''),
            ];
        }

        if (isset($response['base_config']) && is_array($response['base_config'])) {
            $nodeReportMinTraffic = max(0, (int) admin_setting('node_report_min_traffic', 0));
            $deviceOnlineMinTraffic = max(0, (int) admin_setting('device_online_min_traffic', 0));

            $response['base_config'] += [
                'node_report_min_traffic' => $nodeReportMinTraffic,
                'device_online_min_traffic' => $deviceOnlineMinTraffic,
            ];
        }

        return $response;
    }

    private function mapV2NodeProtocol(string $nodeType, array $protocolSettings): string
    {
        if ($nodeType !== 'hysteria') {
            return $nodeType;
        }

        $version = (int) data_get($protocolSettings, 'version', 2);
        return $version === 2 ? 'hysteria2' : 'hysteria';
    }

    private function getV2NodeTlsValue(string $nodeType, array $protocolSettings): ?int
    {
        return match ($nodeType) {
            'vmess', 'vless' => (int) data_get($protocolSettings, 'tls', 0),
            'trojan', 'hysteria', 'tuic', 'anytls' => 1,
            'shadowsocks' => 0,
            default => null,
        };
    }

    private function buildV2NodeTlsSettings($node, string $nodeType, array $protocolSettings, int $tls): array
    {
        if ($tls <= 0) {
            return [];
        }

        $baseTlsSettings = $this->getBaseTlsSettingsForV2Node($nodeType, $protocolSettings, $tls);
        $serverName = $this->resolveV2NodeServerName($node, $nodeType, $protocolSettings, $tls, $baseTlsSettings);

        $tlsSettings = $baseTlsSettings;
        $tlsSettings['server_name'] = $serverName;

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

    private function getBaseTlsSettingsForV2Node(string $nodeType, array $protocolSettings, int $tls): array
    {
        if ($nodeType === 'vless' && $tls === 2) {
            return (array) data_get($protocolSettings, 'reality_settings', []);
        }

        return (array) data_get($protocolSettings, 'tls_settings', []);
    }

	    private function resolveV2NodeServerName($node, string $nodeType, array $protocolSettings, int $tls, array $baseTlsSettings): string
	    {
        $serverName = match ($nodeType) {
            'trojan' => (string) data_get($protocolSettings, 'server_name', ''),
            'hysteria', 'tuic', 'anytls' => (string) data_get($protocolSettings, 'tls.server_name', ''),
            default => (string) data_get($baseTlsSettings, 'server_name', ''),
        };

        if ($nodeType === 'vless' && $tls === 2) {
            $serverName = (string) data_get($protocolSettings, 'reality_settings.server_name', $serverName);
        }

	        return $serverName ?: (string) $node->host;
	    }

	    private function respondCacheEntry(Request $request, array $entry)
	    {
	        $etag = (string) ($entry['etag'] ?? '');
	        if ($etag !== '' && strpos($request->header('If-None-Match', ''), $etag) !== false) {
	            return response(null, 304)->header('ETag', "\"{$etag}\"");
	        }

	        return response((string) ($entry['body'] ?? ''), 200, ['Content-Type' => 'application/json; charset=UTF-8'])
	            ->header('ETag', "\"{$etag}\"");
	    }

	    private function buildUserCacheEntry($node): array
	    {
	        $users = ServerService::getAvailableUsers($node);
        $eTagContext = hash_init('sha1');
        foreach ($users as $index => $user) {
            $userId = (int) data_get($user, 'id', 0);
            $uuid = (string) data_get($user, 'uuid', '');
            $speedLimit = (int) data_get($user, 'speed_limit', 0);
            $deviceLimit = (int) data_get($user, 'device_limit', 0);

            if (is_object($user)) {
                $user->id = $userId;
                $user->uuid = $uuid;
                $user->speed_limit = $speedLimit;
                $user->device_limit = $deviceLimit;
            } elseif (is_array($user)) {
                $user['id'] = $userId;
                $user['uuid'] = $uuid;
                $user['speed_limit'] = $speedLimit;
                $user['device_limit'] = $deviceLimit;
                $users[$index] = $user;
            }

            hash_update($eTagContext, "{$userId}:{$uuid}:{$speedLimit}:{$deviceLimit};");
        }

        $response = ['users' => $users];
        $eTag = hash_final($eTagContext);
        $body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

	        return [
	            'etag' => $eTag,
	            'body' => $body === false ? '{"users":[]}' : $body,
	        ];
	    }

	    private function buildConfigResponse($node, bool $isV2Node): array
	    {
	        $nodeType = $node->type;
	        $protocolSettings = $node->protocol_settings;

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
	                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
	                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
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
	                'tls_settings' =>
	                        match ((int) $protocolSettings['tls']) {
	                            2 => $protocolSettings['reality_settings'],
	                            default => $protocolSettings['tls_settings']
	                        }
	            ],
	            'hysteria' => [
	                ...$baseConfig,
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
	                'server_name' => $protocolSettings['tls']['server_name'],
	                'padding_scheme' => $protocolSettings['padding_scheme'],
	            ],
	            'socks' => [
	                ...$baseConfig,
	                'server_port' => (int) $serverPort,
	            ],
	            'naive' => [
	                ...$baseConfig,
	                'server_port' => (int) $serverPort,
	                'tls' => (int) $protocolSettings['tls'],
	                'tls_settings' => $protocolSettings['tls_settings']
	            ],
	            'http' => [
	                ...$baseConfig,
	                'server_port' => (int) $serverPort,
	                'tls' => (int) $protocolSettings['tls'],
	                'tls_settings' => $protocolSettings['tls_settings']
	            ],
	            'mieru' => [
	                ...$baseConfig,
	                'server_port' => (string) $serverPort,
	                'protocol' => (int) $protocolSettings['protocol'],
	            ],
	            default => []
	        };

	        $response['base_config'] = [
	            'push_interval' => (int) admin_setting('server_push_interval', 60),
	            'pull_interval' => (int) admin_setting('server_pull_interval', 60)
	        ];

	        if (!empty($node['route_ids'])) {
	            $response['routes'] = ServerService::getRoutes($node['route_ids']);
	        }

	        if ($isV2Node) {
	            $response = $this->adaptConfigForV2Node($response, $node);
	        }

	        return $response;
	    }

	    private function buildConfigCacheEntry($node, bool $isV2Node): array
	    {
	        $response = $this->buildConfigResponse($node, $isV2Node);
	        $body = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	        $eTag = sha1($body === false ? '' : $body);

	        return [
	            'etag' => $eTag,
	            'body' => $body === false ? '{}' : $body,
	        ];
	    }
}
