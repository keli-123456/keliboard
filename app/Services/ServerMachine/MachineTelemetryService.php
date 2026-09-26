<?php

namespace App\Services\ServerMachine;

use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use Illuminate\Support\Facades\Cache;

class MachineTelemetryService
{
    private const TTL = 900;
    private const METRICS = ['cpu', 'mem', 'swap', 'net', 'uptime'];

    private function key(ServerMachine $machine): string
    {
        // Rotating credentials must also invalidate the previous reporter's data.
        return 'machine_telemetry:v1:' . $machine->id . ':' . hash('sha256', (string) $machine->token);
    }

    public function receive(ServerMachine $machine, array $input): bool
    {
        $key = $this->key($machine);
        $lock = Cache::lock($key . ':lock', 5);
        if (!$lock->get()) {
            return false;
        }
        try {
            $state = Cache::get($key, []);
            if (($state['session'] ?? null) === $input['session'] && ($state['sequence'] ?? 0) >= $input['sequence']) {
                return true;
            }
            $now = now()->timestamp;
            $status = array_intersect_key($input['status'], array_flip(self::METRICS));
            $aggregate = $state['aggregate'] ?? ['since' => $now, 'count' => 0, 'cpu_sum' => 0, 'cpu_peak' => 0, 'rx_peak' => 0, 'tx_peak' => 0];
            $aggregate['count']++;
            $aggregate['cpu_sum'] += $status['cpu'];
            $aggregate['cpu_peak'] = max($aggregate['cpu_peak'], $status['cpu'], data_get($input, 'peaks.cpu', 0));
            $aggregate['rx_peak'] = max($aggregate['rx_peak'], data_get($status, 'net.rx_rate', 0), data_get($input, 'peaks.rx_rate', 0));
            $aggregate['tx_peak'] = max($aggregate['tx_peak'], data_get($status, 'net.tx_rate', 0), data_get($input, 'peaks.tx_rate', 0));
            Cache::put($key, [
                'session' => $input['session'], 'sequence' => $input['sequence'],
                'received_at' => $now, 'status' => $status, 'aggregate' => $aggregate,
            ], self::TTL);
            return true;
        } finally {
            $lock->release();
        }
    }

    public function snapshot(ServerMachine $machine): array
    {
        try {
            $state = Cache::get($this->key($machine));
        } catch (\Throwable) {
            $state = null;
        }
        $legacy = is_array($machine->load_status) ? $machine->load_status : [];
        $fast = is_array($state);
        $at = $fast ? $state['received_at'] : ($legacy['updated_at'] ?? null);
        $age = $at ? max(0, now()->timestamp - (int) $at) : null;
        // Do not allow a stale fast sample to replace a newer full status report.
        $status = $fast && (int) $at >= (int) ($legacy['updated_at'] ?? 0)
            ? array_replace($legacy, $state['status'], ['updated_at' => $at]) : $legacy;
        return [
            'load_status' => $status,
            'telemetry' => [
                'mode' => $fast ? 'fast' : 'legacy', 'received_at' => $at,
                'age_seconds' => $age,
                'state' => $age === null ? 'never' : ($age <= ($fast ? 15 : 90) ? 'fresh' : ($age <= ($fast ? 60 : 300) ? 'delayed' : 'stale')),
            ],
        ];
    }

    public function recordHistory(ServerMachine $machine, array $status): void
    {
        $key = $this->key($machine);
        $lock = Cache::lock($key . ':lock', 5);
        if (!$lock->get()) {
            return;
        }
        try {
            $now = now()->timestamp;
            if ($now - (int) Cache::get($key . ':history_at', 0) < 60) {
                return;
            }
            $state = Cache::get($key);
            $aggregate = $state['aggregate'] ?? null;
            if ($aggregate && $aggregate['count'] > 0) {
                $status['telemetry_summary'] = array_merge($aggregate, [
                    'until' => $state['received_at'],
                    'cpu_average' => $aggregate['cpu_sum'] / $aggregate['count'],
                ]);
            }
            ServerMachineLoadHistory::create([
                'machine_id' => (int) $machine->id, 'cpu' => $status['cpu'],
                'mem_total' => $status['mem']['total'], 'mem_used' => $status['mem']['used'],
                'swap_total' => $status['swap']['total'], 'swap_used' => $status['swap']['used'],
                'disk_total' => $status['disk']['total'], 'disk_used' => $status['disk']['used'],
                'load_status' => $status,
            ]);
            Cache::put($key . ':history_at', $now, self::TTL);
            if ($state) {
                unset($state['aggregate']);
                Cache::put($key, $state, self::TTL);
            }
        } finally {
            $lock->release();
        }
        if (Cache::add($key . ':pruned', true, 3600)) {
            ServerMachineLoadHistory::where('machine_id', (int) $machine->id)
                ->where('created_at', '<', now()->subDays(7))->delete();
        }
    }
}
