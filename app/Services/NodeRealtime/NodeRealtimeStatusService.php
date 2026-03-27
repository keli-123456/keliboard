<?php

namespace App\Services\NodeRealtime;

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

        return [
            'enabled' => $this->settings->enabledSetting(),
            'running' => $pid !== null ? $this->isPidRunning($pid) : false,
            'pid' => $pid,
            'listen' => sprintf('%s:%d', $this->settings->listenHost(), $this->settings->listenPort()),
            'public_url' => $this->settings->resolvedPublicUrl(),
            'updated_at' => $snapshot['updated_at'] ?? null,
            'active_connections' => (int) ($snapshot['active_connections'] ?? 0),
            'connections' => $this->normalizeConnections((array) ($snapshot['connections'] ?? [])),
        ];
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
}
