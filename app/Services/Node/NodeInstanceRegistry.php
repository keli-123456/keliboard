<?php

namespace App\Services\Node;

use App\Models\Server;
use App\Services\UserOnlineService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NodeInstanceRegistry
{
    public const LIMIT = 32;
    public const RETENTION = 86400;

    public function key(int $nodeId): string { return 'node_instances:' . $nodeId; }

    public function record(object $node, Request $request, array $fields = []): bool
    {
        $source = NodeReportSource::from($request);
        $key = $this->key((int) $node->id);
        try { return Cache::lock($key . ':lock', 5)->block(3, function () use ($key, $source, $request, $fields): bool {
            $rows = (array) Cache::get($key, []);
            $id = $source['key'];
            if (!isset($rows[$id]) && count($rows) >= self::LIMIT) {
                uasort($rows, fn ($a, $b) => ($a['last_seen_at'] ?? 0) <=> ($b['last_seen_at'] ?? 0));
                $oldest = array_key_first($rows);
                if (($rows[$oldest]['last_seen_at'] ?? 0) >= time() - UserOnlineService::nodeDataExpirySeconds()) return false;
                unset($rows[$oldest]);
            }
            $row = $rows[$id] ?? ['first_seen_at' => time()];
            $ip = $request->ip();
            $rows[$id] = array_replace($row, $source, ['last_seen_at' => time(),
                'reported_ip' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null],
                array_intersect_key($fields, array_flip(['online_users', 'cpu', 'memory_total', 'memory_used', 'last_online_at', 'last_traffic_at', 'last_status_at'])));
            Cache::put($key, $rows, self::RETENTION);
            return true;
        }); } catch (\Throwable $error) {
            \Illuminate\Support\Facades\Log::warning('Node instance observation unavailable', ['error_type' => get_class($error)]);
            return true;
        }
    }

    public function snapshot(int $nodeId): array
    {
        $now = time();
        $ttl = UserOnlineService::nodeDataExpirySeconds();
        return array_values(array_map(function ($row) use ($now, $ttl): array {
            $row['online'] = ($row['last_seen_at'] ?? 0) >= $now - $ttl;
            $row['status_fresh'] = ($row['last_status_at'] ?? 0) >= $now - $ttl;
            if (($row['last_online_at'] ?? 0) < $now - $ttl) $row['online_users'] = null;
            return $row;
        }, (array) Cache::get($this->key($nodeId), [])));
    }

    public function recordStatus(object $node, Request $request, array $status): void
    {
        $fields = ['last_status_at' => time()];
        foreach (['cpu' => 'cpu', 'mem.total' => 'memory_total', 'mem.used' => 'memory_used'] as $from => $to) {
            $value = data_get($status, $from);
            if (is_numeric($value) && is_finite((float) $value) && $value >= 0) $fields[$to] = $value + 0;
        }
        $this->record($node, $request, $fields);
    }

    public function refreshOnlineCount(string $nodeType, int $nodeId, ?string $currentSource, ?int $cacheNodeId = null): void
    {
        $keys = [UserOnlineService::nodeUsersCacheKey($nodeType, $nodeId, $currentSource)];
        foreach ($this->snapshot($nodeId) as $row) {
            if (($row['last_online_at'] ?? 0) >= time() - UserOnlineService::nodeDataExpirySeconds()) {
                $keys[] = UserOnlineService::nodeUsersCacheKey($nodeType, $nodeId, $row['key']);
            }
        }
        $users = [];
        foreach (Cache::many(array_unique($keys)) as $ids) foreach ((array) $ids as $id) $users[(int) $id] = true;
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . strtoupper($nodeType) . '_ONLINE_USER', $cacheNodeId ?? $nodeId), count($users), max(120, UserOnlineService::nodeDataExpirySeconds()));
    }

    public function summaries(iterable $nodes): array
    {
        $list = collect($nodes);
        $keys = $list->map(fn ($node) => $this->key((int) $node->id))->all();
        $all = $keys ? Cache::many($keys) : [];
        $replicas = NodeMachineBinding::available() ? \Illuminate\Support\Facades\DB::table('v2_server_machine_binding')
            ->whereIn('server_id', $list->pluck('id'))->get()->groupBy('server_id') : collect();
        $result = [];
        foreach ($list as $node) {
            $rows = (array) ($all[$this->key((int) $node->id)] ?? []);
            $identified = array_filter($rows, fn ($row) => $row['identified'] ?? false);
            $ids = array_merge([(int) $node->machine_id], $replicas->get($node->id, collect())->pluck('machine_id')->all());
            $result[(int) $node->id] = ['observed' => count($identified),
                'online' => count(array_filter($identified, fn ($row) => ($row['last_seen_at'] ?? 0) >= time() - UserOnlineService::nodeDataExpirySeconds())),
                'unidentified' => count($rows) > count($identified), 'bound_machines' => count(array_unique(array_filter($ids)))];
        }
        return $result;
    }
}
