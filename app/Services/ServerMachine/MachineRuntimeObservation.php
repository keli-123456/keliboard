<?php

namespace App\Services\ServerMachine;

class MachineRuntimeObservation
{
    public function normalize(mixed $value): ?array
    {
        if (!is_array($value) || ($value['schema'] ?? null) !== 1
            || !is_string($value['session'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $value['session'])
            || !$this->integer($value['pid'] ?? null, 1)
            || !$this->integer($value['started_at'] ?? null, 1)
            || !$this->integer($value['uptime_seconds'] ?? null)) {
            return null;
        }
        $oom = $value['oom'] ?? null;
        $validOom = is_array($oom) && is_string($oom['scope'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $oom['scope'])
            && $this->integer($oom['oom'] ?? null) && $this->integer($oom['oom_kill'] ?? null);
        return [
            'schema' => 1, 'session' => $value['session'], 'pid' => $value['pid'],
            'started_at' => $value['started_at'], 'uptime_seconds' => $value['uptime_seconds'],
            'oom' => $validOom ? array_intersect_key($oom, array_flip(['scope', 'oom', 'oom_kill'])) : null,
        ];
    }

    public function track(array $previous, ?array $observation, int $now): array
    {
        $old = is_array($previous['runtime_observation_state'] ?? null) ? $previous['runtime_observation_state'] : [];
        if (!$observation) {
            return $old;
        }
        $changed = isset($old['session']) && $old['session'] !== $observation['session'];
        return [
            'session' => $observation['session'],
            'tracking_since' => $old['tracking_since'] ?? $now,
            'observed_restarts' => max(0, (int) ($old['observed_restarts'] ?? 0)) + ($changed ? 1 : 0),
            'last_change_at' => $changed ? $now : ($old['last_change_at'] ?? null),
        ];
    }

    private function integer(mixed $value, int $min = 0): bool
    {
        return is_int($value) && $value >= $min;
    }
}
