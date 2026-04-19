<?php

namespace App\Http\Controllers\V2\Server;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateAliveDataJob;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\UserService;
use App\Utils\CacheKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ServerController extends Controller
{
    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    private function getNodeCacheServerId($node): int
    {
        return (int) ($node->parent_id ?: $node->id);
    }

    private function touchNodeLastCheckAt($node): void
    {
        $nodeType = (string) $node->type;
        $nodeId = $this->getNodeCacheServerId($node);
        Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_CHECK_AT', $nodeId), time(), 3600);
    }

    public function handshake(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        if ($node) {
            $this->touchNodeLastCheckAt($node);
        }

        $settings = app(NodeRealtimeSettings::class);
        $wsURL = $settings->resolvedPublicUrl();
        $enabled = $settings->enabled() && $wsURL !== '';

        return response()->json([
            'websocket' => [
                'enabled' => $enabled,
                'ws_url' => $enabled ? $wsURL : '',
            ],
            'settings' => [
                'push_interval' => (int) admin_setting('server_push_interval', 60),
                'pull_interval' => (int) admin_setting('server_pull_interval', 60),
            ],
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $this->touchNodeLastCheckAt($node);

        $nodeType = (string) $node->type;
        $nodeId = $this->getNodeCacheServerId($node);
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $trafficRaw = $request->input('traffic');
        $traffic = [];
        if (is_array($trafficRaw)) {
            $traffic = array_filter($trafficRaw, function ($item) {
                return is_array($item)
                    && count($item) === 2
                    && is_numeric($item[0] ?? null)
                    && is_numeric($item[1] ?? null);
            });
        }
        if (!empty($traffic)) {
            $userService = new UserService();
            $userService->trafficFetch($node, $nodeType, $traffic);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId), count($traffic), 3600);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId), time(), 3600);
        }

        $alive = $request->input('alive');
        if (is_array($alive) && !empty($alive)) {
            UpdateAliveDataJob::dispatch($alive, $nodeType, $node->id);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId), time(), 3600);
        }

        $online = $request->input('online');
        if (is_array($online) && !empty($online)) {
            $onlineUserCount = 0;
            foreach ($online as $count) {
                if ((int) $count > 0) {
                    $onlineUserCount++;
                }
            }
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $nodeId), $onlineUserCount, 3600);
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_PUSH_AT', $nodeId), time(), 3600);
        }

        $status = $request->input('status');
        if (is_array($status)
            && is_numeric($status['cpu'] ?? null)
            && is_numeric(data_get($status, 'mem.total'))
            && is_numeric(data_get($status, 'mem.used'))
            && is_numeric(data_get($status, 'swap.total', 0))
            && is_numeric(data_get($status, 'swap.used', 0))
            && is_numeric(data_get($status, 'disk.total', 0))
            && is_numeric(data_get($status, 'disk.used', 0))
        ) {
            $statusData = [
                'cpu' => (float) $status['cpu'],
                'mem' => [
                    'total' => (int) data_get($status, 'mem.total', 0),
                    'used' => (int) data_get($status, 'mem.used', 0),
                ],
                'swap' => [
                    'total' => (int) data_get($status, 'swap.total', 0),
                    'used' => (int) data_get($status, 'swap.used', 0),
                ],
                'disk' => [
                    'total' => (int) data_get($status, 'disk.total', 0),
                    'used' => (int) data_get($status, 'disk.used', 0),
                ],
                'updated_at' => now()->timestamp,
            ];
            cache([
                CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LOAD_STATUS', $nodeId) => $statusData,
                CacheKey::get('SERVER_' . strtoupper($nodeType) . '_LAST_LOAD_AT', $nodeId) => now()->timestamp,
            ], $cacheTime);
        }

        $metrics = $request->input('metrics');
        if (is_array($metrics) && !empty($metrics)) {
            Cache::put(CacheKey::get('SERVER_' . strtoupper($nodeType) . '_METRICS', $nodeId), $metrics, $cacheTime);
        }

        return response()->json(['data' => true]);
    }
}

