<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\MachineInsightService;
use App\Services\ServerMachine\MachineIpHistory;
use Tests\TestCase;

final class MachineIpHistoryTest extends TestCase
{
    public function test_first_report_establishes_a_baseline_without_an_alert(): void
    {
        $state = (new MachineIpHistory())->observe([], ['public_ipv4' => '192.0.2.1'], 100);
        $this->assertSame([], $state['changes']);
        $this->assertSame(100, $state['tracking_since']);
        $this->assertSame(['public_ipv4' => '192.0.2.1'], $state['current']);
    }

    public function test_changes_keep_source_and_do_not_modify_the_reported_address(): void
    {
        $previous = ['ip' => ['public_ipv4' => '192.0.2.1', 'panel_seen' => '198.51.100.1']];
        $state = (new MachineIpHistory())->observe($previous, ['public_ipv4' => '192.0.2.2', 'panel_seen' => '198.51.100.2'], 200);
        $this->assertSame([
            ['source' => 'public_ipv4', 'from' => '192.0.2.1', 'to' => '192.0.2.2', 'at' => 200],
            ['source' => 'panel_seen', 'from' => '198.51.100.1', 'to' => '198.51.100.2', 'at' => 200],
        ], $state['changes']);
        $this->assertSame('192.0.2.1', $previous['ip']['public_ipv4']);
    }

    public function test_gaps_invalid_values_and_equivalent_ipv6_do_not_create_changes(): void
    {
        $service = new MachineIpHistory();
        $state = $service->observe([], ['public_ipv4' => '192.0.2.1', 'public_ipv6' => '2001:0db8:0000:0000:0000:0000:0000:0001'], 100);
        $state = $service->observe(['ip_observation' => $state], ['public_ipv4' => ['secret'], 'public_ipv6' => '2001:db8::1', 'panel_seen' => 'not-ip'], 200);
        $this->assertSame([], $state['changes']);
        $this->assertSame('192.0.2.1', $state['current']['public_ipv4']);
        $state = $service->observe(['ip_observation' => $state], [], 300);
        $state = $service->observe(['ip_observation' => $state], ['public_ipv4' => '192.0.2.2'], 400);
        $this->assertCount(1, $state['changes']);
        $this->assertSame('192.0.2.1', $state['changes'][0]['from']);
        $this->assertSame(100, $state['tracking_since']);
    }

    public function test_history_is_bounded_and_unchanged_reports_are_not_duplicated(): void
    {
        $service = new MachineIpHistory();
        $state = [];
        for ($i = 1; $i <= 50; $i++) {
            $state = $service->observe(['ip_observation' => $state], ['public_ipv4' => "192.0.2.$i"], $i);
        }
        $this->assertCount(20, $state['changes']);
        $this->assertSame(31, $state['changes'][0]['at']);
        $this->assertSame($state, $service->observe(['ip_observation' => $state], ['public_ipv4' => '192.0.2.50'], 51));
    }

    public function test_process_projection_preserves_zero_and_never_defaults_missing_metrics_to_healthy(): void
    {
        $result = (new MachineInsightService())->processMetrics(['metrics' => ['keli_core_rs' => [
            'keli_core_process_rss_bytes' => 123,
            'keli_core_process_memory_trim_error_total' => 0,
            'keli_core_process_open_fds' => -1,
            'keli_core_process_threads' => '12',
            'keli_core_hy2_udp_active_sessions' => 0,
            'keli_core_quic_resource' => ['active_connections' => 20],
            'secret_token' => 'must-not-be-returned',
        ]]]);
        $this->assertSame(123, $result['rss_bytes']);
        $this->assertSame(0, $result['memory_trim_error_total']);
        $this->assertSame(0, $result['udp_sessions']);
        $this->assertSame(20, $result['quic_connections']);
        $this->assertNull($result['open_fds']);
        $this->assertNull($result['threads']);
        $this->assertNull($result['user_apply_error_total']);
        $this->assertStringNotContainsString('must-not-be-returned', json_encode($result));
    }
}
