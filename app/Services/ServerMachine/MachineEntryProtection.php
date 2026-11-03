<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

/** Process-wide observations, never evidence identifying a malicious subscriber. */
class MachineEntryProtection
{
    public const COUNTERS = ['tls_handshake_failure_total', 'tls_handshake_backoff_reject_total', 'tcp_auth_failure_total', 'tcp_auth_backoff_reject_total'];

    public function normalizeReport(mixed $value): array
    {
        if (!is_array($value) || ($value['schema'] ?? null) !== 1) return ['schema' => 0];
        return ['schema' => 1, 'scope' => 'machine_shared_not_user_attributed',
            'counters' => $this->counters(['entry_security' => $value])];
    }

    public function counters(array $status): array
    {
        $summary = $status['entry_security'] ?? null;
        $current = is_array($summary) && ($summary['schema'] ?? null) === 1 && is_array($summary['counters'] ?? null)
            ? ($summary['counters'] ?? []) : [];
        $legacy = !array_key_exists('entry_security', $status);
        $result = [];
        foreach (self::COUNTERS as $key) {
            $value = $legacy ? data_get($status, 'metrics.keli_core_rs.keli_core_' . $key) : ($current[$key] ?? null);
            if (data_get($status, 'metrics.keli_core_rs_error') !== null) $value = null;
            $result[$key] = is_int($value) && $value >= 0 && $value <= 9007199254740991 ? $value : null;
        }
        return $result;
    }

    public function observe(array $previous, array $current, int $now): array
    {
        $totals = $this->counters($current);
        $old = $this->counters($previous);
        $epoch = $this->epoch($current);
        $at = $this->timestamp($previous['updated_at'] ?? null);
        $interval = $at === null ? null : $now - $at;
        $comparable = $epoch !== null && $epoch === $this->epoch($previous)
            && $interval !== null && $interval > 0 && $interval <= 300;
        $reset = false;
        foreach (self::COUNTERS as $key) {
            if ($comparable && $old[$key] !== null && $totals[$key] !== null && $totals[$key] < $old[$key]) $reset = true;
        }
        $deltas = [];
        foreach (self::COUNTERS as $key) {
            $deltas[$key] = $comparable && !$reset && $old[$key] !== null && $totals[$key] !== null
                ? $totals[$key] - $old[$key] : null;
        }
        return ['schema' => 1, 'observed_at' => $now, 'window_start_at' => $comparable && !$reset ? $at : null,
            'interval_seconds' => $comparable && !$reset ? $interval : null, 'counter_reset' => $reset,
            'totals' => $totals, 'deltas' => $deltas];
    }

    public function snapshot(array $status, int $now): array
    {
        $at = $this->timestamp($status['updated_at'] ?? null);
        $state = is_array($status['entry_protection_state'] ?? null) ? $status['entry_protection_state'] : [];
        $totals = $this->counters($status);
        $deltas = array_fill_keys(self::COUNTERS, null);
        $interval = $state['interval_seconds'] ?? null;
        $valid = is_array($state) && ($state['schema'] ?? null) === 1 && ($state['observed_at'] ?? null) === $at
            && is_int($interval) && $interval > 0 && $interval <= 300
            && $this->timestamp($state['window_start_at'] ?? null) === $at - $interval;
        if ($valid) foreach (self::COUNTERS as $key) {
            $value = $state['deltas'][$key] ?? null;
            if (is_int($value) && $value >= 0 && $totals[$key] !== null && $value <= $totals[$key]) $deltas[$key] = $value;
        }
        $fresh = $at !== null && $at <= $now + 30 && $now - $at <= 300;
        return ['mode' => 'observe_only', 'scope' => 'machine_shared_not_user_attributed',
            'state' => array_filter($totals, fn ($v) => $v !== null) === [] ? 'unavailable'
                : (!$fresh ? 'stale' : (array_filter($deltas, fn ($v) => $v !== null) === [] ? 'baseline' : 'fresh')),
            'observed_at' => $at, 'interval_seconds' => $valid ? $interval : null,
            'totals' => $totals, 'deltas' => $deltas,
            'reachability' => app(MachineEntryProbe::class)->snapshot($status, $now),
            'attribution' => 'No subscriber identity, attack attribution or censorship conclusion is available from these counters.'];
    }

    private function epoch(array $status): ?string
    {
        // External core processes can restart independently of the agent's session.
        if (data_get($status, 'runtime.core_mode') !== 'embedded') return null;
        $session = data_get($status, 'runtime.observation.session');
        return is_string($session) && preg_match('/^[a-f0-9]{32}$/D', $session) ? $session : null;
    }

    private function timestamp(mixed $value): ?int
    {
        return is_int($value) && $value > 0 ? $value : null;
    }
}
