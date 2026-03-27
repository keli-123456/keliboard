<?php

namespace App\Services\NodeRealtime;

use App\Models\Server;
use Illuminate\Support\Facades\File;

class NodeRealtimeStatusService
{
    private NodeRealtimeSettings $settings;

    public function __construct(NodeRealtimeSettings $settings)
    {
        $this->settings = $settings;
    }

    public function getStatus(): array
    {
        $storagePath = storage_path('app/ws-server');
        $snapshot = $this->loadSnapshot($storagePath . '/connections.json');
        $pid = $this->readPid($storagePath . '/workerman.pid');
        $connections = $this->normalizeConnections((array) ($snapshot['connections'] ?? []));
        $realtimeEnabled = $this->settings->enabledSetting();
        $activityWindowSeconds = $this->resolveActivityWindowSeconds();
        $recentActiveNodes = $realtimeEnabled ? $this->resolveRecentActiveNodes($activityWindowSeconds) : [];
        $missingNodes = $realtimeEnabled ? $this->resolveMissingNodes($recentActiveNodes, $connections) : [];

        return [
            'enabled' => $realtimeEnabled,
            'running' => $pid !== null ? $this->isPidRunning($pid) : false,
            'pid' => $pid,
            'listen' => sprintf('%s:%d', $this->settings->listenHost(), $this->settings->listenPort()),
            'public_url' => $this->settings->resolvedPublicUrl(),
            'updated_at' => $snapshot['updated_at'] ?? null,
            'active_connections' => count($connections),
            'connections' => $connections,
            'recent_active_window_seconds' => $activityWindowSeconds,
            'recent_active_nodes_count' => count($recentActiveNodes),
            'missing_nodes_count' => count($missingNodes),
            'missing_nodes' => $missingNodes,
        ];
    }

    private function resolveActivityWindowSeconds(): int
    {
        $minutes = (int) admin_setting('node_realtime_alert_window_minutes', 10);
        if ($minutes < 5) {
            $minutes = 5;
        }
        if ($minutes > 120) {
            $minutes = 120;
        }

        return $minutes * 60;
    }

    private function loadSnapshot(string $path): array
    {
        if (!File::exists($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) File::get($path), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function readPid(string $path): ?int
    {
        if (!File::exists($path)) {
            return null;
        }

        $pid = (int) trim((string) File::get($path));
        return $pid > 0 ? $pid : null;
    }

    private function isPidRunning(int $pid): bool
    {
        if ($pid <= 0 || !function_exists('posix_kill')) {
            return false;
        }

        try {
            return @posix_kill($pid, 0);
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeConnections(array $connections): array
    {
        return array_values(array_filter(array_map(function ($row) {
            if (!is_array($row)) {
                return null;
            }

            return [
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'remote_ip' => $row['remote_ip'] ?? null,
                'node_id' => (string) ($row['node_id'] ?? ''),
                'server_id' => (int) ($row['server_id'] ?? 0),
                'node_type' => $row['node_type'] ?? null,
                'group_ids' => array_values(array_map('intval', array_filter((array) ($row['group_ids'] ?? []), 'is_numeric'))),
                'authenticated_at' => $row['authenticated_at'] ?? null,
            ];
        }, $connections)));
    }

    private function resolveRecentActiveNodes(int $activityWindowSeconds): array
    {
        $cutoff = time() - max(60, $activityWindowSeconds);
        $rows = [];

        foreach (Server::query()->orderBy('sort')->orderBy('id')->get(['id', 'code', 'name', 'type', 'parent_id']) as $server) {
            $lastCheckAt = (int) ($server->last_check_at ?? 0);
            if ($lastCheckAt <= 0 || $lastCheckAt < $cutoff) {
                continue;
            }

            $rows[] = [
                'server_id' => (int) $server->id,
                'cache_server_id' => (int) ($server->parent_id ?: $server->id),
                'node_id' => $this->resolveNodeId($server),
                'name' => (string) ($server->name ?? ''),
                'node_type' => (string) ($server->type ?? ''),
                'last_check_at' => $this->formatTimestamp($lastCheckAt),
            ];
        }

        return $rows;
    }

    private function resolveMissingNodes(array $recentActiveNodes, array $connections): array
    {
        if ($recentActiveNodes === []) {
            return [];
        }

        $serverIds = array_values(array_unique(array_map(
            fn (array $row) => (int) ($row['server_id'] ?? 0),
            $connections
        )));

        $serverCacheMap = [];
        if ($serverIds !== []) {
            $serverCacheMap = Server::query()
                ->whereIn('id', $serverIds)
                ->get(['id', 'parent_id'])
                ->mapWithKeys(fn (Server $server) => [
                    (int) $server->id => (int) ($server->parent_id ?: $server->id),
                ])
                ->all();
        }

        $connectedCacheServerIds = [];
        foreach ($connections as $connection) {
            $serverId = (int) ($connection['server_id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }
            $connectedCacheServerIds[] = $serverCacheMap[$serverId] ?? $serverId;
        }
        $connectedCacheServerIds = array_values(array_unique(array_filter($connectedCacheServerIds, fn (int $id) => $id > 0)));

        return array_values(array_filter($recentActiveNodes, function (array $row) use ($connectedCacheServerIds): bool {
            return !in_array((int) ($row['cache_server_id'] ?? 0), $connectedCacheServerIds, true);
        }));
    }

    private function resolveNodeId(Server $server): string
    {
        $code = trim((string) ($server->code ?? ''));
        return $code !== '' ? $code : (string) $server->id;
    }

    private function formatTimestamp(int $timestamp): ?string
    {
        if ($timestamp <= 0) {
            return null;
        }

        return date(DATE_ATOM, $timestamp);
    }
}
