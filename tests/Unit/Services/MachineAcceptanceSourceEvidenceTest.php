<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/source_fixture.php';

class MachineAcceptanceSourceEvidenceTest extends TestCase
{
    private function facts(): array
    {
        $now = 2000000000;
        $expected = ['run_id' => 'keli-accept-20990101-offline', 'machine_id' => 17, 'account_id' => 1,
            'node_id' => 1, 'ip' => '45.79.10.20', 'version' => 'v0.1.999',
            'binary_sha256' => str_repeat('b', 64), 'health_or_forwarding_claimed' => false];
        $task = ['installation_id' => str_repeat('a', 32), 'label' => $expected['run_id'] . '-source',
            'status' => 'waiting_ipv4_probe', 'bootstrap_state' => 'succeeded', 'machine_id' => 17, 'account_id' => 1,
            'dispatched_at' => $now - 120, 'external_id' => '123', 'provider_created_at' => '2033-05-18T03:31:20Z',
            'public_ips' => [$expected['ip']]];
        $cloud = array_intersect_key($task, array_flip(['external_id', 'provider_created_at', 'public_ips'])) + ['status' => 'running'];
        $row = ['at' => $now - 1, 'runtime' => ['binary_sha256' => $expected['binary_sha256'], 'version' => 'v0.1.999',
            'observation_session' => str_repeat('c', 32), 'configured_nodes' => [['id' => 1, 'protocol' => 'hysteria2', 'port' => 24443]]]];
        $summary = ['evidence_current' => true, 'threshold_reached' => true, 'observed_failures' => 3,
            'probe' => ['state' => 'failed', 'installation_id' => $task['installation_id'], 'ipv4' => [$expected['ip']],
                'checked_at' => $now - 1, 'valid_until' => $now + 30]];
        return compact('task', 'cloud', 'row', 'summary', 'expected', 'now');
    }

    private function check(array $facts): array
    {
        $checker = require base_path('tools/machine-acceptance/source_fixture_evidence.php');
        return $checker(...array_values($facts));
    }

    public static function rejectedFields(): array
    {
        return [
            'installation' => ['task.installation_id', 'bad', 'installation_id'],
            'label' => ['task.label', 'other-source', 'task_label'],
            'status' => ['task.status', 'failed', 'task_status'],
            'bootstrap' => ['task.bootstrap_state', 'failed', 'bootstrap_state'],
            'machine' => ['task.machine_id', 18, 'machine_id'],
            'account' => ['task.account_id', 2, 'account_id'],
            'dispatch-type' => ['task.dispatched_at', '1999999880', 'dispatched_at'],
            'dispatch-future' => ['task.dispatched_at', 2000000000, 'dispatched_at'],
            'external' => ['cloud.external_id', '456', 'external_id'],
            'created' => ['cloud.provider_created_at', 'other', 'provider_created_at'],
            'cloud' => ['cloud.status', 'offline', 'cloud_status'],
            'task-ip' => ['task.public_ips', [], 'task_ipv4'],
            'cloud-ip' => ['cloud.public_ips', [], 'cloud_ipv4'],
            'ip-shape' => ['cloud.public_ips', 'private-sentinel-value', 'cloud_ipv4'],
            'binary' => ['row.runtime.binary_sha256', str_repeat('0', 64), 'runtime_binary'],
            'version' => ['row.runtime.version', 'v0.1.998', 'runtime_version'],
            'version-shape' => ['row.runtime.version', ['private-sentinel-value'], 'runtime_version'],
            'session' => ['row.runtime.observation_session', 'bad', 'runtime_session'],
            'nodes' => ['row.runtime.configured_nodes', [], 'runtime_nodes'],
            'nodes-id-type' => ['row.runtime.configured_nodes', [['id' => '1', 'protocol' => 'hysteria2', 'port' => 24443]], 'runtime_nodes'],
            'nodes-extra' => ['row.runtime.configured_nodes', [['id' => 1, 'protocol' => 'hysteria2', 'port' => 24443, 'extra' => true]], 'runtime_nodes'],
            'row-type' => ['row.at', '1999999999', 'agent_at_type'],
            'row-future' => ['row.at', 2000000001, 'agent_at_future'],
            'row-stale' => ['row.at', 1999999969, 'agent_at_fresh'],
            'row-before-dispatch' => ['row.at', 1999999880, 'agent_after_dispatch'],
            'current' => ['summary.evidence_current', false, 'evidence_current'],
            'current-type' => ['summary.evidence_current', 1, 'evidence_current'],
            'threshold' => ['summary.threshold_reached', false, 'threshold_reached'],
            'failures' => ['summary.observed_failures', 2, 'observed_failures'],
            'failures-type' => ['summary.observed_failures', '3', 'observed_failures'],
            'state' => ['summary.probe.state', 'unknown', 'probe_state'],
            'healthy' => ['summary.probe.state', 'healthy', 'probe_state'],
            'foreign' => ['summary.probe.installation_id', str_repeat('d', 32), 'probe_installation'],
            'probe-ip' => ['summary.probe.ipv4', ['45.79.10.21'], 'probe_ipv4'],
            'probe-time-type' => ['summary.probe.checked_at', '1999999999', 'probe_at_type'],
            'probe-future' => ['summary.probe.checked_at', 2000000001, 'probe_at_future'],
            'probe-stale' => ['summary.probe.checked_at', 1999999969, 'probe_at_fresh'],
            'probe-expired' => ['summary.probe.valid_until', 2000000000, 'probe_valid_until'],
            'probe-validity-type' => ['summary.probe.valid_until', '2000000030', 'probe_valid_until'],
            'scope-run' => ['expected.run_id', 'private-sentinel-value', 'expected_run_id'],
            'scope-machine' => ['expected.machine_id', '17', 'expected_machine_id'],
            'scope-account' => ['expected.account_id', 0, 'expected_account_id'],
            'scope-node' => ['expected.node_id', 0, 'expected_node_id'],
            'scope-ip' => ['expected.ip', '127.0.0.1', 'expected_ipv4'],
            'scope-binary' => ['expected.binary_sha256', 'private-sentinel-value', 'expected_binary'],
            'scope-version' => ['expected.version', '', 'expected_version'],
            'scope-claim' => ['expected.health_or_forwarding_claimed', true, 'no_health_claim'],
        ];
    }

    #[DataProvider('rejectedFields')]
    public function test_each_failed_guard_names_its_field_without_exporting_values(string $path, mixed $value, string $field): void
    {
        $facts = $this->facts();
        data_set($facts, $path, $value);
        $report = $this->check($facts);
        $this->assertFalse($report['ok']);
        $this->assertContains($field, $report['failed_fields']);
        $this->assertSame(['schema', 'ok', 'reason', 'failed_fields', 'checks'], array_keys($report));
        foreach ($report['checks'] as $code => $passed) {
            $this->assertMatchesRegularExpression('/^[a-z_][a-z_0-9]{0,39}$/D', $code);
            $this->assertIsBool($passed);
        }
        $encoded = json_encode($report, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-sentinel-value', $encoded);
        $this->assertStringNotContainsString('45.79.10.20', $encoded);
        $this->assertStringNotContainsString(str_repeat('b', 64), $encoded);
    }

    public function test_complete_cold_failure_and_exact_freshness_boundary_are_accepted_without_a_health_receipt(): void
    {
        $facts = $this->facts();
        $facts['row']['at'] = $facts['summary']['probe']['checked_at'] = $facts['now'] - 30;
        $facts['summary']['probe']['valid_until'] = $facts['now'] + 1;
        $facts['row']['runtime']['version'] = '0.1.999';
        $this->assertTrue($this->check($facts)['ok']);
        $save = 0;
        \keli_acceptance_source_facts(...[...array_values($facts), static function () use (&$save): void { $save++; }]);
        $this->assertSame(0, $save);
    }

    public function test_field_error_survives_diagnostic_storage_failure_and_all_failures_are_retained(): void
    {
        $facts = $this->facts();
        $facts['summary']['evidence_current'] = false;
        $facts['summary']['threshold_reached'] = false;
        $facts['summary']['observed_failures'] = null;
        $attempts = 0;
        try {
            \keli_acceptance_source_facts(...[...array_values($facts), static function (array $report) use (&$attempts): void {
                $attempts++;
                throw new \RuntimeException('synthetic_storage_error');
            }]);
            $this->fail('Expected rejection');
        } catch (\KeliSourceFixtureEvidenceRejected $error) {
            $this->assertSame('source_fixture_evidence_invalid_evidence_current', $error->getMessage());
            $this->assertSame(['evidence_current', 'threshold_reached', 'observed_failures'], $error->diagnostic['failed_fields']);
            $this->assertFalse($error->diagnosticRecorded);
            $this->assertSame(1, $attempts);
        }
    }

    public static function registrationModes(): array
    {
        return [['valid'], ['runtime-mismatch'], ['storage-failure'], ['callback-race']];
    }

    public function test_existing_first_diagnostic_is_not_overwritten_by_a_later_failure(): void
    {
        $stored = null;
        $remember = static function (array $report) use (&$stored): void {
            if ($stored !== null) throw new \RuntimeException('exclusive_first_error_required');
            $stored = $report;
        };
        $facts = $this->facts();
        $facts['row']['runtime']['binary_sha256'] = str_repeat('0', 64);
        try { \keli_acceptance_source_facts(...[...array_values($facts), $remember]); $this->fail('Expected rejection'); }
        catch (\KeliSourceFixtureEvidenceRejected $e) { $this->assertTrue($e->diagnosticRecorded); }
        $original = $stored;
        $facts = $this->facts(); $facts['summary']['evidence_current'] = false;
        try { \keli_acceptance_source_facts(...[...array_values($facts), $remember]); $this->fail('Expected rejection'); }
        catch (\KeliSourceFixtureEvidenceRejected $e) {
            $this->assertSame('source_fixture_evidence_invalid_evidence_current', $e->getMessage());
            $this->assertFalse($e->diagnosticRecorded);
        }
        $this->assertSame($original, $stored);
        $this->assertSame(['runtime_binary'], $stored['failed_fields']);
    }

    #[DataProvider('registrationModes')]
    public function test_real_inventory_observer_and_registration_use_the_field_checker_offline(string $mode): void
    {
        $script = base_path('tests/Fixtures/MachineAcceptance/source_fixture_registration.php');
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', $script, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
        $this->assertIsResource($process);
        fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
        $this->assertSame(0, $code, substr($output . $errors, 0, 1500));
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['network_calls']);
        $this->assertFalse($result['forwarding_receipt_created']);
    }
}
