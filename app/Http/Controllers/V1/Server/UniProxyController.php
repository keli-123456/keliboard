<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateAliveDataJob;
use App\Services\Node\NodeConfigService;
use App\Services\Node\NodeUserService;
use App\Services\ServerService;
use App\Services\UserOnlineService;
use App\Services\UserService;
use App\Support\ServerApiRuntime;
use App\Utils\CacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly UserOnlineService $userOnlineService,
        private readonly NodeConfigService $nodeConfigService,
        private readonly NodeUserService $nodeUserService
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
        ServerApiRuntime::applyMemoryLimit();
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);

        $cacheTtl = (int) admin_setting('server_api_user_cache_ttl', config('server_api_cache.user_ttl', 0));
        $lockTtl = (int) admin_setting('server_api_cache_lock_ttl', config('server_api_cache.lock_ttl', 10));
        $lockWait = (int) admin_setting('server_api_cache_lock_wait', config('server_api_cache.lock_wait', 3));
        if ($cacheTtl < 0) {
            $cacheTtl = 0;
        }
        if ($lockTtl <= 0) {
            $lockTtl = (int) config('server_api_cache.lock_ttl', 10);
        }
        if ($lockWait < 0) {
            $lockWait = 0;
        }

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
                    $entry = $this->nodeUserService->buildUserCacheEntry($node);
                    $cache->put($cacheKey, $entry, $cacheTtl);
                    return $entry;
                });
            } catch (Throwable) {
                $cached = $this->nodeUserService->buildUserCacheEntry($node);
                try {
                    $cache->put($cacheKey, $cached, $cacheTtl);
                } catch (Throwable) {
                }
            }

            if (is_array($cached) && isset($cached['etag'], $cached['body'])) {
                return $this->respondCacheEntry($request, $cached);
            }
        }

        return $this->respondCacheEntry($request, $this->nodeUserService->buildUserCacheEntry($node));
    }

    // 后端增量获取用户 (users_revision)
    public function userDelta(Request $request)
    {
        ServerApiRuntime::applyMemoryLimit();
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);

        $entry = $this->nodeUserService->buildDeltaResponseEntry(
            $node,
            (int) $request->query('since', 0),
            $request->query('limit')
        );

        if ((bool) ($entry['raw'] ?? false)) {
            return response((string) ($entry['body'] ?? ''), 200, ['Content-Type' => 'application/json; charset=UTF-8']);
        }

        return response()->json($entry['data'] ?? []);
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

        $cacheTtl = (int) admin_setting('server_api_config_cache_ttl', config('server_api_cache.config_ttl', 0));
        $lockTtl = (int) admin_setting('server_api_cache_lock_ttl', config('server_api_cache.lock_ttl', 10));
        $lockWait = (int) admin_setting('server_api_cache_lock_wait', config('server_api_cache.lock_wait', 3));
        if ($cacheTtl < 0) {
            $cacheTtl = 0;
        }
        if ($lockTtl <= 0) {
            $lockTtl = (int) config('server_api_cache.lock_ttl', 10);
        }
        if ($lockWait < 0) {
            $lockWait = 0;
        }

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
        $onlyExplicitDeviceLimited = max(0, (int) admin_setting('device_limit_fallback', 0)) <= 0;
        $deviceLimitUserIds = ServerService::getAvailableUserIds($node, $onlyExplicitDeviceLimited);
        $aliveSnapshot = $this->userOnlineService->getAliveSnapshot($deviceLimitUserIds);
        return response()->json([
            'alive' => (object) ($aliveSnapshot['alive'] ?? []),
            'alive_ips' => (object) ($aliveSnapshot['alive_ips'] ?? []),
            'mode' => (int) ($aliveSnapshot['mode'] ?? 0),
        ]);
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

    private function respondCacheEntry(Request $request, array $entry)
    {
        $etag = (string) ($entry['etag'] ?? '');
        if ($etag !== '' && strpos($request->header('If-None-Match', ''), $etag) !== false) {
            return response(null, 304)->header('ETag', "\"{$etag}\"");
        }

        return response((string) ($entry['body'] ?? ''), 200, ['Content-Type' => 'application/json; charset=UTF-8'])
            ->header('ETag', "\"{$etag}\"");
    }

    private function buildConfigCacheEntry($node, bool $isV2Node): array
    {
        return $this->nodeConfigService->buildCacheEntry($node, $isV2Node);
    }
}
