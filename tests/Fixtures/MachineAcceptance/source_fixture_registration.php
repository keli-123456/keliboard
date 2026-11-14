<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/tools/machine-acceptance/source_fixture.php';

final class KeliLiveScope
{
    public const RUN = 'keli-accept-20990101-source-offline';
    public const BINARY_SHA256 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public static array $journals = [];
    public static bool $failStorage = false;
    public static function assertSource(string $command, bool $newStage): void
    {
        if ($command !== 'adopt-source' || !$newStage || app('db')->connection()->getDriverName() !== 'sqlite'
            || app('db')->connection()->getDatabaseName() !== ':memory:') throw new RuntimeException('synthetic_scope_required');
    }
    public static function journal(string $name, array $data): void
    {
        if (self::$failStorage || isset(self::$journals[$name])) throw new RuntimeException('synthetic_storage_error');
        self::$journals[$name] = $data;
    }
}

final class SourceFixtureDatabaseHarness extends Tests\Unit\Services\MachineFailoverNativeBridgeTest
{
    public function exercise(string $mode): array
    {
        parent::setUp();
        try {
            Illuminate\Support\Facades\Http::preventStrayRequests();
            $reflection = new ReflectionClass(Tests\Unit\Services\MachineFailoverNativeBridgeTest::class);
            $fixture = $reflection->getProperty('fixtures')->getValue($this)[0];
            $release = $reflection->getProperty('release')->getValue($this);
            $task = $fixture['source']; $machine = $fixture['machine']; $policy = $fixture['policy']; $now = time();
            Illuminate\Support\Facades\DB::table('v2_server_machine')->where('id', $machine->id)->update(['id' => 17]);
            $machine->setAttribute('id', 17);
            $payload = $task->payload; $payload['request']['label'] = KeliLiveScope::RUN . '-source';
            $task->fill(['machine_id' => 17, 'status' => 'waiting_ipv4_probe', 'bootstrap_state' => 'succeeded',
                'external_id' => '123', 'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 120),
                'public_ips' => [$fixture['sourceIp']], 'dispatched_at' => $now - 120, 'payload' => $payload])->save();
            $policy->fill(['machine_id' => 17, 'failure_threshold' => 3])->save();
            Illuminate\Support\Facades\DB::table('v2_server')->update(['machine_id' => 17, 'port' => 24443, 'server_port' => 24443]);
            App\Models\MachineOwnedInstance::query()->delete();
            App\Models\MachineFailoverObservation::query()->delete(); App\Models\MachineFailoverPlan::query()->delete();
            $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
                'server_entry_probe_interval' => 5,
                'server_entry_probe_targets' => ['39.156.70.37', '111.33.167.71', '123.150.76.218', '157.255.219.143']]);
            $observer = app(App\Services\ServerMachine\Cloud\MachineFailoverObserver::class);
            $inventory = app(App\Services\ServerMachine\MachineAddressInventory::class);
            $config = app(App\Services\ServerMachine\MachineEntryProbe::class)->configuration();
            $runtime = ['version' => $release->version, 'binary_sha256' => $release->binary_sha256,
                'observation_session' => str_repeat('e', 32),
                'configured_nodes' => [['id' => 1, 'protocol' => 'hysteria2', 'port' => 24443]]];
            if (in_array($mode, ['runtime-mismatch', 'storage-failure'], true)) $runtime['configured_nodes'] = [];
            $record = function (int $at) use ($inventory, $machine, $task, $config, $runtime, $fixture): void {
                $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => $at,
                    'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => 'unreachable',
                        'source_ip' => $fixture['sourceIp']], $config['targets'])];
                $inventory->record($machine, $task->installation_id, ['public_ipv4' => $fixture['sourceIp']], $at, $runtime, $probe);
                Illuminate\Support\Facades\Cache::put('node_instances:1', [$task->installation_id => ['machine_id' => 17,
                    'installation_id' => $task->installation_id, 'last_seen_at' => $at, 'last_status_at' => $at]], 300);
            };
            foreach ([-47, -36, -24, -13, -2] as $delta) {
                $at = $now + $delta;
                Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($at));
                $record($at); $observer->observe($policy->id);
            }
            Illuminate\Support\Carbon::setTestNow();
            $this->assertSame(5, $observer->summary($policy, time())['observed_failures']);
            $cloud = ['external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at,
                'public_ips' => $task->public_ips, 'status' => 'running'];
            $before = Illuminate\Support\Facades\Http::recorded()->count();
            if ($mode === 'callback-race') {
                // A newer report exists before summary(), but was not the report observe() collected.
                $record($now);
                $summary = $observer->summary($policy, $now);
                $this->assertFalse($summary['evidence_current']);
                $this->assertSame('sample_not_collected', $summary['reason']);
                $expected = ['run_id' => KeliLiveScope::RUN, 'machine_id' => 17, 'account_id' => 1,
                    'ip' => $fixture['sourceIp'], 'binary_sha256' => $release->binary_sha256, 'version' => $release->version,
                    'node_id' => 1, 'health_or_forwarding_claimed' => false];
                $facts = $task->only(['installation_id', 'status', 'bootstrap_state', 'machine_id', 'account_id',
                    'dispatched_at', 'external_id', 'provider_created_at', 'public_ips']) + ['label' => $payload['request']['label']];
                try {
                    keli_acceptance_source_facts($facts, $cloud, $inventory->snapshots([$machine], true)[17][$task->installation_id],
                        $summary, $expected, $now);
                    throw new LogicException('racing_snapshot_accepted');
                } catch (KeliSourceFixtureEvidenceRejected $error) {
                    $this->assertSame('source_fixture_evidence_invalid_evidence_current', $error->getMessage());
                    $this->assertContains('evidence_current', $error->diagnostic['failed_fields']);
                }
            } else {
                $client = Mockery::mock(App\Services\ServerMachine\Cloud\LinodeProvisionClient::class);
                $client->shouldReceive('reconcile')->once()->andReturn($cloud);
                $providers = Mockery::mock(App\Services\ServerMachine\Cloud\MachineProvisionProviders::class);
                $providers->shouldReceive('client')->once()->andReturn($client);
                app()->instance(App\Services\ServerMachine\Cloud\MachineProvisionProviders::class, $providers);
                KeliLiveScope::$failStorage = $mode === 'storage-failure';
                $GLOBALS['argv'] = [__FILE__, 'adopt-source', $task->id];
                $ids = ['source_task_id' => $task->id, 'policy_id' => $policy->id, 'release_id' => $release->id, 'node_id' => 1];
                try {
                    $result = keli_acceptance_adopt_source($ids, []);
                    $this->assertSame('valid', $mode);
                    $this->assertTrue($result['source_fixture_registered']);
                    $this->assertFalse($result['health_or_forwarding_success_claimed']);
                    $instance = App\Models\MachineOwnedInstance::query()->firstOrFail();
                    $this->assertNull($instance->verification); $this->assertNull($instance->verified_until);
                    $this->assertSame('accepted', $task->fresh()->status);
                } catch (KeliSourceFixtureEvidenceRejected $error) {
                    $this->assertNotSame('valid', $mode);
                    $this->assertSame('source_fixture_evidence_invalid_runtime_nodes', $error->getMessage());
                    $this->assertSame(['runtime_nodes'], $error->diagnostic['failed_fields']);
                    $this->assertSame($mode !== 'storage-failure', $error->diagnosticRecorded);
                }
            }
            if ($mode !== 'valid') {
                $this->assertSame(0, App\Models\MachineOwnedInstance::count());
                $this->assertSame(0, App\Models\MachineCloudResource::count());
                $this->assertArrayNotHasKey('source-adoption-intent.json', KeliLiveScope::$journals);
                $this->assertSame('waiting_ipv4_probe', $task->fresh()->status);
            }
            $this->assertSame(0, App\Models\MachineAcceptanceCheck::count());
            $this->assertSame($before, Illuminate\Support\Facades\Http::recorded()->count());
            return ['ok' => true, 'mode' => $mode, 'sqlite' => true, 'network_calls' => 0,
                'forwarding_receipt_created' => false, 'candidate_created' => false, 'dns_written' => false];
        } finally {
            parent::tearDown();
        }
    }
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['valid', 'runtime-mismatch', 'storage-failure', 'callback-race'], true)) throw new RuntimeException('synthetic_mode_required');
    $test = new SourceFixtureDatabaseHarness('test_initially_unreachable_source_completes_only_after_candidate_forwarding_and_owned_dns');
    echo json_encode($test->exercise($mode), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $reason = preg_match('/^[a-z_][a-z_0-9]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'offline_assertion_failed';
    echo json_encode(['ok' => false, 'reason' => $reason, 'error_class' => get_class($error),
        'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR);
    exit(1);
}
