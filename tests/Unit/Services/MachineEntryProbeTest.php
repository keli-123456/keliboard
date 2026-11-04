<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Requests\Admin\ConfigSave;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Arr;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineEntryProbeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineEntryProbe $probe;

    protected function setUp(): void
    {
        parent::setUp(); $this->probe = new MachineEntryProbe(); $this->settings();
    }

    private function settings(array $changes = []): void
    {
        $this->bindTestSettings($changes + ['server_entry_probe_enable' => true,
            'server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29', '180.76.76.76'], 'server_entry_probe_interval' => 120]);
    }

    private function report(array $states, array $changes = []): array
    {
        $config = $this->probe->configuration();
        return $changes + ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => 1000,
            'targets' => array_map(fn ($target, $state) => ['id' => $target['id'], 'status' => $state], array_slice($config['targets'], 0, count($states)), $states)];
    }

    private function assess(array $states, array $changes = [], int $now = 1000): array
    {
        return $this->probe->snapshot(['updated_at' => 1000, 'entry_probe' => $this->report($states, $changes)], $now);
    }

    public function test_any_one_success_clears_the_project_block_criterion_even_when_others_fail_or_are_missing(): void
    {
        foreach ([['reachable', 'unreachable', 'unreachable'], ['reachable', 'error', 'error'], ['reachable']] as $states) {
            $result = $this->assess($states);
            $this->assertSame('reachable', $result['status']); $this->assertFalse($result['blocked_by_policy']);
            $this->assertSame(1, $result['reachable_targets']);
            $this->assertSame('agent_outbound_icmp', $result['source']);
        }
    }

    public function test_all_targets_must_be_observed_unreachable_to_trigger_the_criterion(): void
    {
        $result = $this->assess(['unreachable', 'unreachable', 'unreachable']);
        $this->assertSame('suspected_blocked', $result['status']); $this->assertTrue($result['blocked_by_policy']);
        $this->assertSame(3, $result['unreachable_targets']); $this->assertSame(0, $result['unknown_targets']);
        foreach ([['unreachable'], ['unreachable', 'unreachable', 'error'], ['error', 'error', 'error'], []] as $states) {
            $result = $this->assess($states); $this->assertSame('unknown', $result['status']); $this->assertNull($result['blocked_by_policy']);
        }
    }

    public function test_new_agents_require_two_complete_failure_rounds_but_never_count_errors_or_skips_as_failures(): void
    {
        $this->settings(['server_entry_probe_interval' => 5]);
        $this->assertSame(5, $this->probe->configuration()['interval_seconds']);
        foreach ([0, 1] as $count) {
            $result = $this->assess(['unreachable', 'unreachable', 'unreachable'], ['consecutive_failures' => $count]);
            $this->assertSame('unknown', $result['status']); $this->assertNull($result['blocked_by_policy']);
        }
        $this->assertSame('suspected_blocked', $this->assess(['unreachable', 'unreachable', 'unreachable'], ['consecutive_failures' => 2])['status']);
        foreach ([['unreachable'], ['unreachable', 'error', 'unreachable']] as $states) {
            $this->assertSame('unknown', $this->assess($states, ['consecutive_failures' => 2])['status']);
        }
        $this->assertSame('reachable', $this->assess(['reachable'], ['consecutive_failures' => 0])['status']);
        foreach ([-1, 3, '2', null] as $bad) {
            $this->assertNull($this->probe->normalizeReport($this->report(['reachable'], ['consecutive_failures' => $bad])));
        }
        $status = ['updated_at' => 1030, 'entry_probe_updated_at' => 600, 'entry_probe' => $this->report(['reachable'])];
        $this->assertSame('unknown', $this->probe->snapshot($status, 1030)['status']);
    }

    public function test_expired_future_pending_wrong_revision_and_foreign_targets_are_unknown(): void
    {
        foreach ([['revision' => str_repeat('a', 64)], ['checked_at' => 500], ['checked_at' => 2000],
            ['state' => 'pending'], ['state' => 'expired'], ['targets' => [['id' => str_repeat('b', 16), 'status' => 'reachable']]]] as $changes) {
            $this->assertSame('unknown', $this->assess(['reachable'], $changes)['status']);
        }
        $this->assertSame('unknown', $this->assess(['reachable'], [], 1400)['status']);
        $old = $this->report(['reachable']);
        $this->settings(['server_entry_probe_targets' => ['8.8.8.8']]);
        $this->assertSame('unknown', $this->probe->snapshot(['updated_at' => 1000, 'entry_probe' => $old], 1000)['status']);
    }

    public function test_address_result_requires_ipv4_source_and_fresh_installation_evidence(): void
    {
        $v4 = '1.0.0.1'; $v6 = '2606:4700:4700::1001';
        $this->settings(['server_entry_probe_targets' => ['223.5.5.5', '119.29.29.29']]);
        $report = $this->report(['unreachable', 'unreachable'], ['source_failures' => [$v4 => 2], 'consecutive_failures' => 2]);
        $report['targets'][0]['source_ip'] = $v4;
        $report['targets'][1]['source_ip'] = $v4;
        $installation = str_repeat('a', 32);
        $status = ['updated_at' => 1000, 'entry_probe' => $report, 'entry_probe_installation_id' => $installation];
        $snapshot = $this->probe->snapshot($status, 1000);
        $this->assertSame('suspected_blocked', $snapshot['status']);
        $this->assertSame($installation, $snapshot['address_installation_id']);
        $this->assertSame('suspected_blocked', $snapshot['addresses'][$v4]['status']);
        $this->assertArrayNotHasKey('addresses', $this->probe->snapshot($status, 1500));
        unset($status['entry_probe_installation_id']);
        $this->assertArrayNotHasKey('addresses', $this->probe->snapshot($status, 1000));
        $status['entry_probe_installation_id'] = $installation;
        $status['entry_probe']['targets'][1]['status'] = 'error';
        $this->assertSame('unknown', $this->probe->snapshot($status, 1000)['addresses'][$v4]['status']);
        $status['entry_probe']['targets'][1]['source_ip'] = $v6;
        $status['entry_probe']['targets'][1]['status'] = 'reachable';
        $this->assertNull($this->probe->normalizeReport($status['entry_probe']));
        $this->assertSame('unknown', $this->probe->snapshot($status, 1000)['status']);
    }

    public function test_legacy_ipv6_targets_are_not_distributed_or_counted_as_probe_evidence(): void
    {
        $v6 = '2606:4700:4700::1111';
        $this->settings(['server_entry_probe_targets' => [$v6, '223.5.5.5']]);
        $config = $this->probe->configuration();
        $this->assertTrue($config['enabled']);
        $this->assertSame(['223.5.5.5'], array_column($config['targets'], 'ip'));
        $this->assertSame('reachable', $this->assess(['reachable'])['status']);
        $legacy = $config;
        unset($legacy['revision']);
        array_unshift($legacy['targets'], ['id' => substr(hash('sha256', $v6), 0, 16), 'ip' => $v6]);
        $report = $this->report(['reachable'], ['revision' => hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR))]);
        $this->assertSame('unknown', $this->probe->snapshot(['updated_at' => 1000, 'entry_probe' => $report], 1000)['status']);
        $this->settings(['server_entry_probe_targets' => [$v6]]);
        $this->assertSame('unconfigured', $this->probe->snapshot([], 1000)['status']);
    }

    public function test_off_unconfigured_and_invalid_storage_never_probe_or_reuse_old_success(): void
    {
        $this->settings(['server_entry_probe_enable' => false]);
        $this->assertSame('disabled', $this->assess(['reachable'])['status']);
        $this->assertFalse($this->probe->configuration(false)['enabled']);
        $this->settings(['server_entry_probe_targets' => []]);
        $this->assertSame('unconfigured', $this->probe->snapshot([], 1000)['status']);
        foreach (['invalid', ['127.0.0.1'], array_fill(0, 9, '8.8.8.8')] as $targets) {
            $this->settings(['server_entry_probe_targets' => $targets]);
            $this->assertFalse($this->probe->configuration()['enabled']);
        }
    }

    public function test_public_literal_validation_and_admin_save_bounds_reject_internal_targets_and_commands(): void
    {
        $rules = Arr::only((new ConfigSave())->rules(), ['server_entry_probe_enable', 'server_entry_probe_targets', 'server_entry_probe_targets.*', 'server_entry_probe_interval']);
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $this->assertTrue($validator->make(['server_entry_probe_enable' => true, 'server_entry_probe_targets' => ['223.5.5.5'], 'server_entry_probe_interval' => 120], $rules)->passes());
        foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.169.254', '192.0.2.1', '198.18.0.1', '224.0.0.1', '::1', '::ffff:8.8.8.8', '2001:db8::1', '2002:7f00:1::', 'fc00::1', 'fe80::1', 'example.com', '8.8.8.8;echo bad'] as $ip) {
            $this->assertNull(MachineEntryProbe::publicIp($ip), $ip);
            $this->assertTrue($validator->make(['server_entry_probe_targets' => [$ip]], $rules)->fails(), $ip);
        }
        $this->assertNotNull(MachineEntryProbe::publicIp('2606:4700:4700::1111'));
        $this->assertTrue($validator->make(['server_entry_probe_targets' => ['2606:4700:4700::1111']], $rules)->fails());
        $this->assertTrue($validator->make(['server_entry_probe_targets' => array_fill(0, 9, '8.8.8.8')], $rules)->fails());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 1], $rules)->fails());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 4], $rules)->fails());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 5], $rules)->passes());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 600], $rules)->passes());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 601], $rules)->fails());
    }

    public function test_report_projection_strips_untrusted_fields_and_rejects_duplicate_results(): void
    {
        $report = $this->report(['reachable']); $report['secret'] = 'private-test-secret';
        $report['targets'][0]['ip'] = '127.0.0.1';
        $clean = $this->probe->normalizeReport($report);
        $this->assertStringNotContainsString('private-test-secret', json_encode($clean));
        $this->assertArrayNotHasKey('ip', $clean['targets'][0]);
        $report['targets'][] = $report['targets'][0];
        $this->assertNull($this->probe->normalizeReport($report));
    }
}
