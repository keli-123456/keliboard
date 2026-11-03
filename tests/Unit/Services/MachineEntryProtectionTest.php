<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\MachineEntryProtection as Protection;
use Tests\TestCase;

final class MachineEntryProtectionTest extends TestCase
{
    private function report(int $at = 100, int $failures = 4, string $session = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        return ['updated_at' => $at, 'runtime' => ['core_mode' => 'embedded', 'observation' => ['session' => $session]],
            'metrics' => ['keli_core_rs' => ['keli_core_tcp_auth_failure_total' => $failures,
                'keli_core_tls_handshake_failure_total' => 0, 'private' => 'private-node-secret']]];
    }

    public function test_first_sample_retains_totals_but_does_not_invent_a_rate_or_public_verdict(): void
    {
        $service = new Protection(); $status = $this->report();
        $status['entry_protection_state'] = $service->observe([], $status, 100);
        $view = $service->snapshot($status, 100);
        $this->assertSame('baseline', $view['state']);
        $this->assertSame(4, $view['totals']['tcp_auth_failure_total']);
        $this->assertSame(0, $view['totals']['tls_handshake_failure_total']);
        $this->assertNull($view['deltas']['tcp_auth_failure_total']);
        $this->assertSame('unconfigured', $view['reachability']['status']);
        $this->assertStringNotContainsString('private-node-secret', json_encode($view));
    }

    public function test_adjacent_same_process_reports_compute_observed_deltas_without_changing_counters(): void
    {
        $service = new Protection(); $old = $this->report(); $current = $this->report(160, 9);
        $current['entry_protection_state'] = $service->observe($old, $current, 160);
        $view = $service->snapshot($current, 160);
        $this->assertSame('fresh', $view['state']); $this->assertSame(60, $view['interval_seconds']);
        $this->assertSame(5, $view['deltas']['tcp_auth_failure_total']);
        $this->assertSame(0, $view['deltas']['tls_handshake_failure_total']);
        $this->assertSame(4, $service->counters($old)['tcp_auth_failure_total']);
        $this->assertSame('stale', $service->snapshot($current, 1000)['state']);
    }

    public function test_restart_counter_reset_external_core_long_gap_and_clock_rollback_never_become_spikes(): void
    {
        $service = new Protection(); $old = $this->report();
        $external = $this->report(160, 9); $external['runtime']['core_mode'] = 'external';
        foreach ([$this->report(160, 9, str_repeat('b', 32)), $this->report(160, 1), $this->report(1000, 999), $this->report(90, 9), $external] as $current) {
            $state = $service->observe($old, $current, $current['updated_at']);
            $this->assertNull($state['deltas']['tcp_auth_failure_total']);
            $this->assertNull($state['interval_seconds']);
        }
    }

    public function test_missing_malformed_and_oversized_values_are_unknown_not_zero_and_new_schema_fails_closed(): void
    {
        $service = new Protection();
        foreach ([[], ['entry_security' => 'invalid'], ['entry_security' => ['schema' => 1, 'counters' => 'invalid']],
            ['metrics' => ['keli_core_rs' => ['keli_core_tcp_auth_failure_total' => '5', 'keli_core_tls_handshake_failure_total' => -1]]]] as $status) {
            $this->assertSame('unavailable', $service->snapshot($status, 100)['state']);
            $this->assertSame([null, null, null, null], array_values($service->counters($status)));
        }
        $status = $this->report(); $status['entry_security'] = ['schema' => 9];
        $this->assertNull($service->counters($status)['tcp_auth_failure_total']);
        $status['entry_security'] = ['schema' => 1, 'counters' => ['tcp_auth_failure_total' => PHP_INT_MAX]];
        $this->assertNull($service->counters($status)['tcp_auth_failure_total']);
    }

    public function test_new_report_is_allowlisted_before_storage(): void
    {
        $service = new Protection();
        $report = $service->normalizeReport(['schema' => 1, 'client_ip' => '192.0.2.1', 'private' => 'secret',
            'counters' => ['tcp_auth_failure_total' => 3, 'user_id' => 10]]);
        $this->assertSame(3, $report['counters']['tcp_auth_failure_total']);
        $this->assertArrayNotHasKey('user_id', $report['counters']);
        $this->assertStringNotContainsString('192.0.2.1', json_encode($report));
        $this->assertStringNotContainsString('secret', json_encode($report));
        $this->assertSame(['schema' => 0], $service->normalizeReport('invalid'));
    }
}
