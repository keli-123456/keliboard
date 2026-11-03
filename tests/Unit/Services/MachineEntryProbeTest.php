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
        $this->assertTrue($validator->make(['server_entry_probe_targets' => array_fill(0, 9, '8.8.8.8')], $rules)->fails());
        $this->assertTrue($validator->make(['server_entry_probe_interval' => 1], $rules)->fails());
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
