<?php

namespace App\Services\NodeRealtime;

use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeRealtimePublisher
{
    public function invalidateConfig(string $reason = 'config.updated', array $payload = []): void
    {
        $this->publish('config', $reason, $payload);
    }

    public function invalidateConfigForServers(array $serverIds, string $reason = 'config.updated', array $payload = []): void
    {
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
        $this->publish('users', $reason, $payload);
    }

    public function invalidateUsersForGroups(array $groupIds, string $reason = 'users.updated', array $payload = []): void
    {
        $targets = $this->buildTargets(groupIds: $groupIds);
        if ($targets === null) {
            return;
        }

        $this->publish('users', $reason, $payload, $targets);
    }

    public function publish(string $topic, string $reason, array $payload = [], array $targets = []): void
    {
        if (!config('node_realtime.enabled', false)) {
            return;
        }

        $queue = (string) config('node_realtime.redis.queue', 'xboard:node_realtime:events');
        $connection = (string) config('node_realtime.redis.connection', 'default');
        $maxLength = max(100, (int) config('node_realtime.redis.max_length', 10000));
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
