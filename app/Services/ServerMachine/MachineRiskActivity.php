<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/** Bounded existing online-report samples, never exact connection-start events. */
class MachineRiskActivity
{
    public const USERS = 64;
    public const SAMPLES = 60;
    public const RETENTION = 1860;

    public function record(string $nodeType, int $nodeId, array $sample, float $reportedAt, ?int $sourceMachineId = null, bool $identified = false): void
    {
        if (!app(MachineRiskSettings::class)->enabled() || $nodeId <= 0) return;
        $now = time(); $at = (int) $reportedAt;
        if ($at > $now + 5 || $at < $now - 300) return;
        $node = Server::query()->whereKey($nodeId)->where('type', $nodeType)->first(['id', 'machine_id']);
        if (!$node) return;
        $owners = \App\Services\Node\NodeMachineBinding::machineIds($node);
        $machineId = $sourceMachineId ?? (!$identified && count($owners) === 1 ? $owners[0] : null);
        if (!$machineId || !in_array($machineId, $owners, true)) return;
        $users = [];
        foreach (array_slice((array) ($sample['users'] ?? []), 0, self::USERS, true) as $id => $count) {
            if ((int) $id > 0 && is_numeric($count) && (int) $count > 0) $users[(int) $id] = min(1000000, (int) $count);
        }
        $key = $this->key($machineId, $nodeId);
        Cache::lock($key . ':lock', 3)->get(function () use ($key, $users, $sample, $at, $now): void {
            $state = json_decode((string) Cache::get($key, '{}'), true) ?: [];
            if ($at <= (int) ($state['latest_at'] ?? 0)) return;
            $rows = array_values(array_filter((array) ($state['samples'] ?? []), fn ($row) => ($row['at'] ?? 0) >= $now - 1800));
            $rows[] = ['at' => $at, 'received_at' => $now, 'users' => $users,
                'total_users' => max(count($users), (int) ($sample['total_users'] ?? 0)),
                'sampling' => ($sample['sampling'] ?? null) === 'rotating_offset_v1' ? 'rotating_offset_v1' : 'bounded_report_order',
                'capped' => (bool) ($sample['capped'] ?? false) || (int) ($sample['total_users'] ?? 0) > count($users)];
            $truncated = count($rows) > self::SAMPLES;
            $rows = array_slice($rows, -self::SAMPLES);
            $value = ['latest_at' => $at, 'samples' => $rows, 'truncated' => $truncated || ($state['truncated'] ?? false)];
            while (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 131072 && count($value['samples']) > 1) {
                array_shift($value['samples']); $value['truncated'] = true;
            }
            Cache::put($key, json_encode($value, JSON_THROW_ON_ERROR), self::RETENTION);
        });
    }

    public function key(int $machineId, int $nodeId): string
    {
        return 'machine_risk:activity:' . $machineId . ':' . $nodeId;
    }

    public function forMachine(int $machineId, int $detectedAt, int $windowSeconds = 1800): array
    {
        $windowSeconds = max(1, min(1800, $windowSeconds));
        $nodes = \App\Services\Node\NodeMachineBinding::query($machineId)->where('enabled', true)->limit(33)->get(['id', 'group_ids']);
        $users = []; $times = []; $methods = []; $capped = $nodes->count() > 32; $samples = 0;
        foreach ($nodes->take(32) as $node) {
            $state = json_decode((string) Cache::get($this->key($machineId, (int) $node->id), '{}'), true) ?: [];
            $capped = $capped || (bool) ($state['truncated'] ?? false);
            foreach (array_reverse((array) ($state['samples'] ?? [])) as $row) {
                $at = (int) ($row['at'] ?? 0);
                if ($at < $detectedAt - $windowSeconds || $at > $detectedAt) continue;
                $samples++; $times[] = $at; $capped = $capped || (bool) ($row['capped'] ?? false);
                $methods[$row['sampling'] ?? 'bounded_report_order'] = true;
                foreach ((array) ($row['users'] ?? []) as $id => $count) {
                    if (!isset($users[$id]) && count($users) >= 128) { $capped = true; continue; }
                    $old = $users[$id] ?? ['first_at' => $at, 'last_at' => $at, 'samples' => 0, 'network_endpoint_peak' => 0, 'node_ids' => []];
                    $old['first_at'] = min($old['first_at'], $at); $old['last_at'] = max($old['last_at'], $at);
                    $old['samples']++; $old['network_endpoint_peak'] = max($old['network_endpoint_peak'], (int) $count);
                    $old['node_ids'][] = (int) $node->id; $old['node_ids'] = array_values(array_unique($old['node_ids']));
                    $users[$id] = $old;
                }
            }
        }
        return ['source' => 'existing_online_report_samples', 'exact_connection_times' => false,
            'requested_from' => $detectedAt - $windowSeconds, 'requested_until' => $detectedAt,
            'first_sample_at' => $times === [] ? null : min($times), 'last_sample_at' => $times === [] ? null : max($times),
            'sample_count' => $samples, 'capped' => $capped, 'sampling_methods' => array_keys($methods), 'representative_sample' => false, 'users' => $users,
            'nodes' => $nodes->take(32)->mapWithKeys(fn ($node) => [(int) $node->id => array_map('strval', (array) $node->group_ids)])->all()];
    }
}
