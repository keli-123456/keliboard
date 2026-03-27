<?php

namespace App\Services\NodeRealtime;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeRealtimePublisher
{
    private NodeRealtimeSettings $settings;

    public function __construct(NodeRealtimeSettings $settings)
    {
        $this->settings = $settings;
    }

    public function invalidateConfig(string $reason = 'config.updated', array $payload = []): void
    {
        $this->clearConfigCache();
        $this->publish('config', $reason, $payload);
    }

    public function invalidateConfigForServers(array $serverIds, string $reason = 'config.updated', array $payload = []): void
    {
        $serverIds = $this->normalizeIntList($serverIds);
        $this->clearConfigCache($serverIds);

        $targets = $this->buildTargets(serverIds: $serverIds);
        if ($targets === null) {
            return;
        }

        $this->publish('config', $reason, $payload, $targets);
    }

    public function invalidateConfigForRoutes(array $routeIds, string $reason = 'config.updated', array $payload = []): void
    {
        $serverIds = $this->resolveServerIdsByRouteIds($routeIds);
        if ($serverIds === []) {
            return;
        }

        $this->invalidateConfigForServers($serverIds, $reason, $payload);
    }

    public function invalidateUsers(string $reason = 'users.updated', array $payload = []): void
    {
        $this->clearUserCache();
        $this->publish('users', $reason, $payload);
    }

    public function invalidateUsersForGroups(array $groupIds, string $reason = 'users.updated', array $payload = []): void
    {
        $groupIds = $this->normalizeIntList($groupIds);
        $this->clearUserCache($this->resolveServerIdsByGroupIds($groupIds));

        $targets = $this->buildTargets(groupIds: $groupIds);
        if ($targets === null) {
            return;
        }

        $this->publish('users', $reason, $payload, $targets);
    }

    public function publish(string $topic, string $reason, array $payload = [], array $targets = []): void
    {
        if (!$this->settings->enabled()) {
            return;
        }

        $queue = $this->settings->redisQueue();
        $connection = $this->settings->redisConnection();
        $maxLength = $this->settings->redisMaxLength();
        $message = json_encode([
            'type' => 'invalidate',
            'topic' => $topic,
            'reason' => $reason,
            'ts' => time(),
            ...($targets !== [] ? ['targets' => $targets] : []),
            ...$payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($message === false) {
            return;
        }

        try {
            $redis = Redis::connection($connection);
            $redis->rpush($queue, $message);
            $redis->ltrim($queue, -$maxLength, -1);
        } catch (\Throwable $e) {
            Log::warning('Node realtime publish failed', [
                'topic' => $topic,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildTargets(array $serverIds = [], array $groupIds = []): ?array
    {
        $targets = array_filter([
            'server_ids' => $this->normalizeIntList($serverIds),
            'group_ids' => $this->normalizeIntList($groupIds),
        ]);

        return $targets === [] ? null : $targets;
    }

    private function resolveServerIdsByRouteIds(array $routeIds): array
    {
        $routeIds = $this->normalizeIntList($routeIds);
        if ($routeIds === []) {
            return [];
        }

        return Server::query()
            ->get(['id', 'route_ids'])
            ->filter(function (Server $server) use ($routeIds): bool {
                return array_intersect(
                    $this->normalizeIntList((array) ($server->route_ids ?? [])),
                    $routeIds
                ) !== [];
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveServerIdsByGroupIds(array $groupIds): array
    {
        $groupIds = $this->normalizeIntList($groupIds);
        if ($groupIds === []) {
            return [];
        }

        return Server::query()
            ->get(['id', 'group_ids'])
            ->filter(function (Server $server) use ($groupIds): bool {
                return array_intersect(
                    $this->normalizeIntList((array) ($server->group_ids ?? [])),
                    $groupIds
                ) !== [];
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function clearConfigCache(?array $serverIds = null): void
    {
        $this->clearServerApiCacheEntries('config', ['default', 'v2node'], $serverIds);
    }

    private function clearUserCache(?array $serverIds = null): void
    {
        $this->clearServerApiCacheEntries('user', [''], $serverIds);
    }

    private function clearServerApiCacheEntries(string $prefix, array $suffixes, ?array $serverIds = null): void
    {
        $ids = $serverIds === null
            ? Server::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $this->normalizeIntList($serverIds);

        if ($ids === []) {
            return;
        }

        $cache = $this->getServerApiCache();
        foreach ($ids as $serverId) {
            foreach ($suffixes as $suffix) {
                $key = $prefix === 'config'
                    ? "server_api:config:{$serverId}:{$suffix}"
                    : "server_api:user:{$serverId}";
                try {
                    $cache->forget($key);
                } catch (\Throwable $e) {
                    Log::warning('Node realtime cache clear failed', [
                        'key' => $key,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function getServerApiCache()
    {
        $store = config('server_api_cache.store');
        try {
            return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
        } catch (\Throwable) {
            return Cache::store();
        }
    }

    private function normalizeIntList(array $values): array
    {
        $normalized = array_map(
            fn ($value) => (int) $value,
            array_filter($values, fn ($value) => is_numeric($value))
        );

        $normalized = array_values(array_unique(array_filter($normalized, fn (int $value) => $value > 0)));
        sort($normalized);

        return $normalized;
    }
}
