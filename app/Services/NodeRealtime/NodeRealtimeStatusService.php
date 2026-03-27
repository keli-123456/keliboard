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
        $receiptSnapshot = $this->loadSnapshot($storagePath . '/receipts.json');
        $pid = $this->readPid($storagePath . '/workerman.pid');
        $connections = $this->normalizeConnections((array) ($snapshot['connections'] ?? []));
        $receiptRows = $this->normalizeReceipts((array) ($receiptSnapshot['receipts'] ?? []));
        $realtimeEnabled = $this->settings->enabledSetting();
        $activityWindowSeconds = $this->resolveActivityWindowSeconds();
        $recentActiveNodes = $realtimeEnabled ? $this->resolveRecentActiveNodes($activityWindowSeconds) : [];
        $serverMeta = $this->loadServerMeta(array_values(array_unique(array_map(
            fn (array $row) => (int) ($row['server_id'] ?? 0),
            array_merge($connections, $receiptRows)
        ))));
        $connectedCacheServerIds = $this->resolveConnectedCacheServerIds($connections, $serverMeta);
        $receiptMap = $this->resolveReceiptMap($receiptRows, $serverMeta);
        $missingNodes = $realtimeEnabled ? $this->resolveMissingNodes($recentActiveNodes, $connectedCacheServerIds) : [];
        $nodeStatuses = $realtimeEnabled ? $this->resolveNodeStatuses($recentActiveNodes, $connections, $serverMeta, $receiptMap) : [];

        return [
            'enabled' => $realtimeEnabled,
            'running' => $pid !== null ? $this->isPidRunning($pid) : false,
            'pid' => $pid,
            'listen' => sprintf('%s:%d', $this->settings->listenHost(), $this->settings->listenPort()),
            'public_url' => $this->settings->resolvedPublicUrl(),
            'updated_at' => $snapshot['updated_at'] ?? null,
            'receipt_updated_at' => $receiptSnapshot['updated_at'] ?? null,
            'active_connections' => count($connections),
            'connections' => $connections,
            'recent_active_window_seconds' => $activityWindowSeconds,
            'recent_active_nodes_count' => count($recentActiveNodes),
            'healthy_nodes_count' => max(0, count($recentActiveNodes) - count($missingNodes)),
            'missing_nodes_count' => count($missingNodes),
            'missing_nodes' => $missingNodes,
            'node_statuses' => $nodeStatuses,
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

    private function normalizeReceipts(array $receipts): array
    {
        return array_values(array_filter(array_map(function ($row) {
            if (!is_array($row)) {
                return null;
            }

            $serverId = (int) ($row['server_id'] ?? 0);
            $topic = trim((string) ($row['topic'] ?? ''));
            if ($serverId <= 0 || !in_array($topic, ['config', 'users'], true)) {
                return null;
            }

            return [
                'server_id' => $serverId,
                'node_id' => (string) ($row['node_id'] ?? ''),
                'node_type' => $row['node_type'] ?? null,
                'topic' => $topic,
                'event_id' => (string) ($row['event_id'] ?? ''),
                'reason' => (string) ($row['reason'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'message' => (string) ($row['message'] ?? ''),
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $receipts)));
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

    private function loadServerMeta(array $serverIds): array
    {
        $serverIds = array_values(array_unique(array_filter(array_map('intval', $serverIds), fn (int $id) => $id > 0)));
        if ($serverIds === []) {
            return [];
        }

        return Server::query()
            ->whereIn('id', $serverIds)
            ->get(['id', 'code', 'name', 'type', 'parent_id'])
            ->mapWithKeys(fn (Server $server) => [
                (int) $server->id => [
                    'server_id' => (int) $server->id,
                    'cache_server_id' => (int) ($server->parent_id ?: $server->id),
                    'node_id' => $this->resolveNodeId($server),
                    'name' => (string) ($server->name ?? ''),
                    'node_type' => (string) ($server->type ?? ''),
                ],
            ])
            ->all();
    }

    private function resolveConnectedCacheServerIds(array $connections, array $serverMeta): array
    {
        $connectedCacheServerIds = [];
        foreach ($connections as $connection) {
            $serverId = (int) ($connection['server_id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }
            $connectedCacheServerIds[] = (int) ($serverMeta[$serverId]['cache_server_id'] ?? $serverId);
        }

        return array_values(array_unique(array_filter($connectedCacheServerIds, fn (int $id) => $id > 0)));
    }

    private function resolveMissingNodes(array $recentActiveNodes, array $connectedCacheServerIds): array
    {
        if ($recentActiveNodes === []) {
            return [];
        }

        return array_values(array_filter($recentActiveNodes, function (array $row) use ($connectedCacheServerIds): bool {
            return !in_array((int) ($row['cache_server_id'] ?? 0), $connectedCacheServerIds, true);
        }));
    }

    private function resolveReceiptMap(array $receiptRows, array $serverMeta): array
    {
        $receiptMap = [];

        foreach ($receiptRows as $receipt) {
            $serverId = (int) ($receipt['server_id'] ?? 0);
            if ($serverId <= 0) {
                continue;
            }

            $cacheServerId = (int) ($serverMeta[$serverId]['cache_server_id'] ?? $serverId);
            if ($cacheServerId <= 0) {
                continue;
            }

            $topic = (string) ($receipt['topic'] ?? '');
            if ($topic === '') {
                continue;
            }

            $current = $receiptMap[$cacheServerId][$topic] ?? null;
            if ($current === null || strcmp((string) ($receipt['updated_at'] ?? ''), (string) ($current['updated_at'] ?? '')) >= 0) {
                $receiptMap[$cacheServerId][$topic] = $receipt;
            }
        }

        return $receiptMap;
    }

    private function resolveNodeStatuses(array $recentActiveNodes, array $connections, array $serverMeta, array $receiptMap): array
    {
        $statuses = [];
        $connectionMap = [];

        foreach ($connections as $connection) {
            $serverId = (int) ($connection['server_id'] ?? 0);
            $meta = $serverMeta[$serverId] ?? [
                'server_id' => $serverId,
                'cache_server_id' => $serverId,
                'node_id' => (string) ($connection['node_id'] ?? ''),
                'name' => '',
                'node_type' => (string) ($connection['node_type'] ?? ''),
            ];
            $cacheServerId = (int) ($meta['cache_server_id'] ?? $serverId);
            if ($cacheServerId <= 0) {
                continue;
            }

            $authenticatedAt = (string) ($connection['authenticated_at'] ?? '');
            $current = $connectionMap[$cacheServerId] ?? null;
            if ($current === null || strcmp($authenticatedAt, (string) ($current['authenticated_at'] ?? '')) >= 0) {
                $connectionMap[$cacheServerId] = [
                    'cache_server_id' => $cacheServerId,
                    'server_id' => $serverId,
                    'node_id' => (string) ($meta['node_id'] ?: ($connection['node_id'] ?? '')),
                    'name' => (string) ($meta['name'] ?? ''),
                    'node_type' => (string) (($connection['node_type'] ?? null) ?: ($meta['node_type'] ?? '')),
                    'authenticated_at' => $connection['authenticated_at'] ?? null,
                    'remote_ip' => $connection['remote_ip'] ?? null,
                    'group_ids' => array_values(array_map('intval', (array) ($connection['group_ids'] ?? []))),
                    'connection_count' => (int) (($current['connection_count'] ?? 0) + 1),
                ];
            } elseif ($current !== null) {
                $current['connection_count'] = (int) ($current['connection_count'] ?? 0) + 1;
                $connectionMap[$cacheServerId] = $current;
            }
        }

        foreach ($recentActiveNodes as $row) {
            $cacheServerId = (int) ($row['cache_server_id'] ?? 0);
            $matchedConnection = $connectionMap[$cacheServerId] ?? null;

            $statuses[] = [
                'cache_server_id' => $cacheServerId,
                'server_id' => (int) ($row['server_id'] ?? 0),
                'node_id' => (string) ($row['node_id'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'node_type' => (string) ($row['node_type'] ?? ''),
                'recent_active' => true,
                'authenticated' => $matchedConnection !== null,
                'status' => $matchedConnection !== null ? 'healthy' : 'missing',
                'last_check_at' => $row['last_check_at'] ?? null,
                'authenticated_at' => $matchedConnection['authenticated_at'] ?? null,
                'remote_ip' => $matchedConnection['remote_ip'] ?? null,
                'group_ids' => array_values(array_map('intval', (array) ($matchedConnection['group_ids'] ?? []))),
                'connection_count' => (int) ($matchedConnection['connection_count'] ?? 0),
                'last_config_receipt' => $receiptMap[$cacheServerId]['config'] ?? null,
                'last_users_receipt' => $receiptMap[$cacheServerId]['users'] ?? null,
            ];

            unset($connectionMap[$cacheServerId]);
        }

        foreach ($connectionMap as $cacheServerId => $connection) {
            $statuses[] = [
                'cache_server_id' => (int) $cacheServerId,
                'server_id' => (int) ($connection['server_id'] ?? 0),
                'node_id' => (string) ($connection['node_id'] ?? ''),
                'name' => (string) ($connection['name'] ?? ''),
                'node_type' => (string) ($connection['node_type'] ?? ''),
                'recent_active' => false,
                'authenticated' => true,
                'status' => 'idle',
                'last_check_at' => null,
                'authenticated_at' => $connection['authenticated_at'] ?? null,
                'remote_ip' => $connection['remote_ip'] ?? null,
                'group_ids' => array_values(array_map('intval', (array) ($connection['group_ids'] ?? []))),
                'connection_count' => (int) ($connection['connection_count'] ?? 0),
                'last_config_receipt' => $receiptMap[(int) $cacheServerId]['config'] ?? null,
                'last_users_receipt' => $receiptMap[(int) $cacheServerId]['users'] ?? null,
            ];
        }

        usort($statuses, function (array $left, array $right): int {
            $priority = ['missing' => 0, 'healthy' => 1, 'idle' => 2];
            $leftPriority = $priority[$left['status'] ?? 'idle'] ?? 9;
            $rightPriority = $priority[$right['status'] ?? 'idle'] ?? 9;

            return [$leftPriority, (int) ($left['server_id'] ?? 0), (string) ($left['node_id'] ?? '')]
                <=> [$rightPriority, (int) ($right['server_id'] ?? 0), (string) ($right['node_id'] ?? '')];
        });

        return $statuses;
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
