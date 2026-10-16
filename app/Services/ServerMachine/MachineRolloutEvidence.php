<?php

namespace App\Services\ServerMachine;

class MachineRolloutEvidence
{
    public function sample(array $status): ?array
    {
        $observation = app(MachineRuntimeObservation::class)->normalize(data_get($status, 'runtime.observation'));
        $metrics = app(MachineInsightService::class)->processMetrics($status);
        $hash = data_get($status, 'runtime.binary_sha256');
        if (!$observation || !$observation['oom'] || data_get($status, 'runtime.core_mode') !== 'embedded'
            || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !is_int($status['updated_at'] ?? null) || !is_string($status['version'] ?? null)) {
            return null;
        }
        foreach (['rss_bytes', 'cpu_percent_x100', 'quic_connections', 'tcp_streams', 'udp_sessions', 'memory_trim_error_total', 'user_apply_error_total'] as $field) {
            if ($metrics[$field] === null) {
                return null;
            }
        }
        if ($metrics['rss_bytes'] <= 0) return null;
        return [
            'at' => $status['updated_at'], 'version' => $status['version'], 'binary_sha256' => $hash,
            'session' => $observation['session'], 'uptime_seconds' => $observation['uptime_seconds'],
            'oom' => $observation['oom'], 'metrics' => $metrics,
        ];
    }

    public function comparable(array $before, array $after): bool
    {
        foreach (['quic_connections', 'tcp_streams', 'udp_sessions'] as $key) {
            $a = $before['metrics'][$key];
            if (abs($after['metrics'][$key] - $a) > max(5, $a * 0.2)) {
                return false;
            }
        }
        return true;
    }

    public function regression(array $baseline, array $samples): bool
    {
        if (count($samples) < 3) {
            return false;
        }
        $before = $this->summary($baseline);
        $after = $this->summary(array_slice($samples, -3));
        return $after['rss_bytes'] > max($before['rss_bytes'] * 1.25, $before['rss_bytes'] + 64 * 1024 * 1024)
            || $after['cpu_percent_x100'] > max($before['cpu_percent_x100'] * 1.5, $before['cpu_percent_x100'] + 1000);
    }

    public function summary(array $samples): array
    {
        $result = [];
        foreach (['rss_bytes', 'cpu_percent_x100', 'quic_connections', 'tcp_streams', 'udp_sessions'] as $field) {
            $values = array_column(array_column($samples, 'metrics'), $field);
            sort($values, SORT_NUMERIC);
            $result[$field] = $values === [] ? null : $values[(int) floor((count($values) - 1) / 2)];
        }
        return $result;
    }
}
