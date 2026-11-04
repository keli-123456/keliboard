<?php

namespace App\Services\ServerMachine;

use App\Models\Server;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\UserOnlineService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineReportingInstances
{
    public function __construct(private NodeInstanceRegistry $registry) {}

    public function summaries(array $machineIds): array
    {
        if (!$machineIds) return [];
        $replicas = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->whereIn('machine_id', $machineIds)
            ->get(['server_id', 'machine_id'])->groupBy('server_id') : collect();
        $nodes = Server::query()->whereIn('machine_id', $machineIds)->orWhereIn('id', $replicas->keys())->get(['id', 'machine_id']);
        $reporters = [];
        foreach ($nodes->chunk(200) as $chunk) {
            $snapshots = Cache::many($chunk->map(fn ($node) => $this->registry->key($node->id))->all());
            foreach ($chunk as $node) {
                $allowed = $replicas->get($node->id, collect())->pluck('machine_id')->push($node->machine_id)->map(fn ($id) => (int) $id)->all();
                foreach ($this->registry->snapshotRows((array) ($snapshots[$this->registry->key($node->id)] ?? [])) as $row) {
                    if ($row['machine_id'] && in_array($row['machine_id'], $allowed, true)) {
                        $previous = $reporters[$row['machine_id']][$row['key']] ?? [];
                        $latest = $row['last_seen_at'] >= ($previous['last_seen_at'] ?? 0) ? $row : $previous;
                        $latest['node_ids'] = [...($previous['node_ids'] ?? []), $node->id];
                        $reporters[$row['machine_id']][$row['key']] = $latest;
                    }
                }
            }
        }
        return array_map('array_values', $reporters);
    }

    public function listSources(array $groupsByMachine, array $summaries): array
    {
        $sources = $selected = [];
        $ttl = UserOnlineService::nodeDataExpirySeconds();
        foreach ($groupsByMachine as $machineId => $groups) {
            $reporters = array_column($summaries[$machineId] ?? [], null, 'installation_id');
            foreach (array_slice($groups, 0, 2) as $group) {
                $id = $group['installation_id'];
                $reporter = $reporters[$id ?? ''] ?? null;
                $source = ['installation_id' => $id, 'ips' => $group['ips'],
                    'seen_until' => ($seen = max($reporter['last_seen_at'] ?? 0, $group['last_seen_at'] ?? 0)) ? $seen + $ttl : null];
                if ($id !== null && $reporter) {
                    $selected[$reporter['key']] = $reporter;
                    if ($reporter['status_fresh']) $source['runtime'] = [
                        'cpu' => $reporter['cpu'] ?? null, 'memory_used' => $reporter['memory_used'] ?? null,
                        'valid_until' => $reporter['last_status_at'] + $ttl,
                    ];
                }
                if (isset($group['runtime']) && $group['runtime']['valid_until'] >= ($source['runtime']['valid_until'] ?? 0)) {
                    $source['runtime'] = $group['runtime'];
                }
                $ips = array_column($group['ips'], 'ip');
                foreach (array_reverse($group['changes']) as $change) {
                    if (!in_array($change['to'], $ips, true)) continue;
                    $source['change'] = array_intersect_key($change, array_flip(['from', 'to', 'at']));
                    break;
                }
                $sources[$machineId][$reporter['key'] ?? ($id ?? '')] = $source;
            }
        }
        $bills = $this->billedToday($selected);
        foreach ($sources as &$rows) {
            foreach ($rows as $key => &$row) $row['billing'] = $bills[$key] ?? null;
            unset($row);
            $rows = array_values($rows);
        }
        unset($rows);
        return $sources;
    }

    public function billedToday(array $selected): array
    {
        $selected = array_filter($selected, fn ($row) => !empty($row['installation_id']));
        $sourcesByNode = [];
        foreach ($selected as $row) foreach ($row['node_ids'] as $nodeId) {
            $sourcesByNode[$nodeId][] = ['key' => $row['key']];
        }
        $day = strtotime('today');
        $ledger = $this->traffic($sourcesByNode, $day);
        $totals = [];
        $until = strtotime('+1 day', $day);
        foreach ($selected as $key => $row) foreach ($row['node_ids'] as $nodeId) {
            if ($stat = $ledger[$nodeId][$row['key']] ?? null) {
                $totals[$key] ??= ['upload' => 0, 'download' => 0, 'valid_until' => $until];
                $totals[$key]['upload'] += (int) $stat->upload;
                $totals[$key]['download'] += (int) $stat->download;
            }
        }
        return $totals;
    }

    private function traffic(array $sourcesByNode, ?int $day = null): array
    {
        $traffic = [];
        if ($sourcesByNode && Schema::hasTable('v2_server_instance_traffic')) {
            $day ??= strtotime('today');
            foreach (array_chunk($sourcesByNode, 100, true) as $nodes) {
                $keys = array_unique(array_column(array_merge(...array_values($nodes)), 'key'));
                $rows = DB::table('v2_server_instance_traffic')->whereIn('server_id', array_keys($nodes))->where('record_at', $day)
                    ->whereIn('reporter_key', $keys)
                    ->get(['server_id', 'reporter_key', 'upload', 'download']);
                foreach ($rows as $row) $traffic[$row->server_id][$row->reporter_key] = $row;
            }
        }
        return $traffic;
    }

    public function details(array $sourcesByNode): array
    {
        $traffic = $this->traffic($sourcesByNode);
        $instances = [];
        foreach ($sourcesByNode as $nodeId => $sources) foreach ($sources as $row) {
            $key = $row['key'];
            $instance = $instances[$key] ?? ['key' => $key, 'installation_id' => $row['installation_id'], 'last_seen_at' => 0, 'nodes' => []];
            if ($row['last_seen_at'] >= $instance['last_seen_at']) {
                $instance = array_replace($instance, array_intersect_key($row, array_flip(['reported_ip', 'last_seen_at', 'online'])));
            }
            $stat = $traffic[$nodeId][$key] ?? null;
            $instance['nodes'][] = [
                'id' => $nodeId, 'name' => $row['node_name'] ?? null, 'reported_ip' => $row['reported_ip'],
                'online_users' => $row['online_users'] ?? null,
                'cpu' => $row['status_fresh'] && $row['installation_id'] ? ($row['cpu'] ?? null) : null,
                'memory_used' => $row['status_fresh'] && $row['installation_id'] ? ($row['memory_used'] ?? null) : null,
                'ip_changes' => $row['ip_changes'] ?? [],
                'today_upload' => $stat ? (int) $stat->upload : null, 'today_download' => $stat ? (int) $stat->download : null,
            ];
            $instances[$key] = $instance;
        }
        uasort($instances, fn ($a, $b) => $b['last_seen_at'] <=> $a['last_seen_at'] ?: strcmp($a['key'], $b['key']));
        return array_values($instances);
    }
}
