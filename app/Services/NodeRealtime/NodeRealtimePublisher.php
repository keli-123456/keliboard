<?php

namespace App\Services\NodeRealtime;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class NodeRealtimePublisher
{
    private const SERVER_RELATION_MAP_CACHE_SECONDS = 300;
    private const SERVER_RELATION_MAP_COLUMNS = ['group_ids', 'route_ids'];

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

    public function invalidateUsersForServers(array $serverIds, string $reason = 'users.updated', array $payload = []): void
    {
        $serverIds = $this->normalizeIntList($serverIds);
        $this->clearUserCache($serverIds);

        $targets = $this->buildTargets(serverIds: $serverIds);
        if ($targets === null) {
            return;
        }

        $this->publish('users', $reason, $payload, $targets);
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

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        if ($eventId === '') {
            $payload['event_id'] = (string) Str::uuid();
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
        return $this->resolveServerIdsByRelationIds($routeIds, 'route_ids');
    }

    private function resolveServerIdsByGroupIds(array $groupIds): array
    {
        return $this->resolveServerIdsByRelationIds($groupIds, 'group_ids');
    }

    private function resolveServerIdsByRelationIds(array $relationIds, string $column): array
    {
        $relationIds = $this->normalizeIntList($relationIds);
        if ($relationIds === [] || !in_array($column, self::SERVER_RELATION_MAP_COLUMNS, true)) {
            return [];
        }

        $relationMap = $this->getServerRelationMap($column);
        $serverIds = [];
        foreach ($relationIds as $relationId) {
            foreach ($relationMap[$relationId] ?? [] as $serverId) {
                $serverIds[] = $serverId;
            }
        }

        return $this->normalizeIntList($serverIds);
    }

    private function getServerRelationMap(string $column): array
    {
        $version = Server::query()
            ->selectRaw('COUNT(*) as server_count, COALESCE(MAX(updated_at), 0) as server_updated_at')
            ->first();
        $cacheKey = sprintf(
            'node_realtime:server_relation_map:%s:%s:%s',
            $column,
            (int) ($version->server_count ?? 0),
            (int) ($version->server_updated_at ?? 0)
        );

        return Cache::remember($cacheKey, now()->addSeconds(self::SERVER_RELATION_MAP_CACHE_SECONDS), function () use ($column): array {
            $map = [];
            Server::query()->orderBy('id')->get(['id', $column])->each(function (Server $server) use (&$map, $column): void {
                foreach ($this->normalizeIntList((array) ($server->{$column} ?? [])) as $relationId) {
                    $map[$relationId][] = (int) $server->id;
                }
            });

            foreach ($map as $relationId => $serverIds) {
                $map[$relationId] = $this->normalizeIntList($serverIds);
            }

            return $map;
        });
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
