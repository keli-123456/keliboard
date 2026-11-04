<?php

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Services\NodeRealtime\NodeRealtimeStatusService;

class MachineInsightService
{
    public function snapshot(ServerMachine $machine): array
    {
        $now = now()->timestamp;
        $raw = is_array($machine->load_status) ? $machine->load_status : [];
        $snapshot = app(MachineTelemetryService::class)->snapshot($machine);
        $status = $snapshot['load_status'];
        $entryProtection = app(MachineEntryProtection::class)->snapshot($raw, $now);
        $entryProtection['reachability'] = $snapshot['entry_probe'];
        $nodes = \App\Services\Node\NodeMachineBinding::query((int) $machine->id)->orderBy('id')->get(['id', 'name', 'type', 'updated_at', 'machine_id']);
        $receipts = app(NodeRealtimeStatusService::class)->receiptsForServers($nodes->modelKeys());
        $conflicted = app(MachineIdentityGuard::class)->conflicted((int) $machine->id);
        $sourcesByNode = [];
        // A receipt from another replica must never make this machine look synchronized.
        foreach ($nodes as $node) {
            $sources = array_values(array_filter(app(\App\Services\Node\NodeInstanceRegistry::class)->snapshot((int) $node->id), fn ($row) => (int) ($row['machine_id'] ?? 0) === (int) $machine->id));
            if ($sources) $sourcesByNode[$node->id] = array_map(fn ($row) => $row + ['node_name' => $node->name], $sources);
            if (count($sources) === 1 && !$conflicted) {
                foreach (['config', 'users'] as $topic) $receipts[$node->id][$topic] = \Illuminate\Support\Facades\Cache::get('node_instance_receipt:' . $node->id . ':' . $sources[0]['key'] . ':' . $topic);
            } elseif ($conflicted || count($sources) > 1 || count(\App\Services\Node\NodeMachineBinding::machineIds($node)) > 1) {
                $receipts[$node->id] = [];
            }
        }

        $ip = app(MachineIpHistory::class)->observe($raw, [], $now);
        $ip['tracking_since'] = data_get($raw, 'ip_observation.tracking_since');
        $instances = app(MachineReportingInstances::class)->details($sourcesByNode);
        $inventory = app(MachineAddressInventory::class);
        return [
            'machine_id' => (int) $machine->id,
            'identity_conflict' => $conflicted,
            'reporting_instances' => $instances,
            'address_groups' => $inventory->groups($inventory->snapshots([$machine])[$machine->id] ?? [], $instances, $status, $conflicted, $now),
            'observed_at' => $now,
            'last_seen_at' => $machine->last_seen_at,
            'report_at' => $raw['updated_at'] ?? null,
            'telemetry' => $snapshot['telemetry'],
            'host' => array_intersect_key($status, array_flip(['cpu', 'mem', 'swap', 'disk', 'uptime'])),
            'process' => $this->processMetrics($raw),
            'entry_protection' => $entryProtection,
            'runtime_observation' => app(MachineRuntimeObservation::class)->normalize(data_get($raw, 'runtime.observation')),
            'runtime_tracking' => array_intersect_key((array) ($raw['runtime_observation_state'] ?? []), array_flip(['tracking_since', 'observed_restarts', 'last_change_at'])),
            'ip' => $ip,
            'nodes' => $nodes->map(fn ($node) => [
                'id' => (int) $node->id,
                'name' => $node->name,
                'type' => $node->type,
                'config_updated_at' => $node->updated_at,
                'config' => $this->receipt($receipts[$node->id]['config'] ?? null),
                'users' => $this->receipt($receipts[$node->id]['users'] ?? null),
            ])->values()->all(),
        ];
    }

    public function processMetrics(array $status): array
    {
        $metrics = data_get($status, 'metrics.keli_core_rs', []);
        $result = [];
        foreach ([
            'rss_bytes', 'peak_rss_bytes', 'anonymous_rss_bytes', 'file_rss_bytes',
            'swap_bytes', 'open_fds', 'threads', 'cpu_percent_x100',
            'memory_trim_total', 'memory_trim_error_total',
        ] as $field) {
            $result[$field] = $this->number(data_get($metrics, 'keli_core_process_' . $field));
        }
        $result['quic_connections'] = $this->number(data_get($metrics, 'keli_core_quic_resource.active_connections'));
        $result['tcp_streams'] = $this->number(data_get($metrics, 'keli_core_hy2_tcp_active_streams'));
        $result['udp_sessions'] = $this->number(data_get($metrics, 'keli_core_hy2_udp_active_sessions'));
        $result['user_apply_error_total'] = $this->number(data_get($metrics, 'keli_core_user_delta_apply_error_total'));
        return $result;
    }

    private function number(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 ? $value : null;
    }

    private function receipt(?array $row): ?array
    {
        if (!$row) {
            return null;
        }
        $status = in_array($row['status'] ?? '', ['applied', 'received', 'queued', 'scheduled', 'restart_required', 'ignored', 'error', 'failed'], true)
            ? $row['status'] : 'unknown';
        return ['status' => $status, 'event_id' => substr((string) ($row['event_id'] ?? ''), 0, 128), 'updated_at' => $row['updated_at'] ?? null];
    }
}
