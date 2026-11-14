<?php
declare(strict_types=1);
require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/tools/machine-acceptance/source_fixture.php';

final class KeliLiveScope
{
    public const RUN = 'keli-accept-20990101-source-offline';
    public const BINARY_SHA256 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    public const COMMIT = 'cccccccccccccccccccccccccccccccccccccccc';
    public static array $journals = [];
    public static bool $failStorage = false;
    public static bool $stopped = false;
    public static array $windowDiagnostic = [];
    public static function assertSource(string $command, bool $newStage): void
    {
        if (self::$stopped) throw new RuntimeException('synthetic_stop_requested');
        if ($command !== 'adopt-source' || !$newStage || app('db')->connection()->getDriverName() !== 'sqlite'
            || app('db')->connection()->getDatabaseName() !== ':memory:') throw new RuntimeException('synthetic_scope_required');
    }
    public static function journal(string $name, array $data): void
    {
        if (self::$failStorage || isset(self::$journals[$name])) throw new RuntimeException('synthetic_storage_error');
        self::$journals[$name] = ['schema' => 1, 'run_id' => self::RUN, 'application_commit' => self::COMMIT] + $data;
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
            $record = function (int $at, string $state = 'unreachable') use ($inventory, $machine, $task, $config, $runtime, $fixture): void {
                $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => $at,
                    'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => $state,
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
            $windowResult = [];
            if (str_starts_with($mode, 'window-')) {
                // Reproduce a provider read outliving the 20-second observation continuity boundary.
                $virtual = $now + 31; $mono = 0.0; $first = $virtual; $lastReport = $virtual;
                $threshold = $mode === 'window-higher-threshold' ? 4 : 3;
                $policy->forceFill(['failure_threshold' => $threshold])->save();
                Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($virtual));
                $record($virtual);
                $clock = function () use (&$mono): float { return $mono; };
                $wall = function () use (&$virtual): int { return $virtual; };
                $pause = function (float $seconds) use (&$virtual, &$mono, &$lastReport, $first, $mode, $record): void {
                    $mono += $seconds; $virtual += (int) $seconds;
                    if ($mode === 'window-stop') KeliLiveScope::$stopped = true;
                    if ($mode === 'window-wall-expired') $virtual += 60;
                    if ($mode === 'window-clock-backwards') $mono = -1;
                    Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($virtual));
                    $interval = $mode === 'window-timeout' ? 22 : 11;
                    $at = $first + intdiv($virtual - $first, $interval) * $interval;
                    if ($mode === 'window-recovered' || $mode === 'window-unknown') $at = $virtual;
                    if ($at > $lastReport && $mode !== 'window-duplicate') {
                        $record($at, match ($mode) {'window-recovered' => 'reachable', 'window-unknown' => 'error', default => 'unreachable'});
                        $lastReport = $at;
                    }
                };
                if ($mode === 'window-legacy') {
                    for ($i = 0; $i < 5; $i++) {
                        $observer->observe($policy->id); $summary = $observer->summary($policy, $virtual);
                        if ($i < 4) $pause(5);
                    }
                    $this->assertTrue($summary['evidence_current']);
                    $this->assertFalse($summary['threshold_reached']); $this->assertSame(2, $summary['observed_failures']);
                    $window = null;
                } else {
                    if ($mode === 'window-late-read') {
                        foreach ([0, 5, 10] as $delta) {
                            $virtual = $first + $delta;
                            Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($virtual));
                            $record($virtual); $observer->observe($policy->id);
                        }
                        $this->assertSame(3, $observer->summary($policy, $virtual)['observed_failures']);
                        $real = $observer;
                        $observer = Mockery::mock(App\Services\ServerMachine\Cloud\MachineFailoverObserver::class);
                        $observer->shouldReceive('observe')->once()->andReturnUsing(function ($id) use ($real, &$mono) {
                            $value = $real->observe($id); $mono = 61; return $value;
                        });
                        $observer->shouldReceive('summary')->once()->andReturnUsing(fn ($p, $at) => $real->summary($p, $at));
                    }
                    try {
                        $observed = keli_acceptance_source_observation_window($observer, $policy, $clock, $pause, $wall);
                    } catch (RuntimeException $error) {
                        $this->assertSame('window-stop', $mode); $this->assertSame('synthetic_stop_requested', $error->getMessage());
                        $this->assertSame(1, App\Models\MachineFailoverObservation::query()->where('checked_at', '>', $now)->count());
                        return ['ok' => true, 'mode' => $mode, 'sqlite' => true, 'network_calls' => 0,
                            'forwarding_receipt_created' => false, 'candidate_created' => false, 'dns_written' => false];
                    }
                    $summary = $observed['summary']; $window = $observed['diagnostic'];
                    KeliLiveScope::$windowDiagnostic = $window;
                    $this->assertNotNull(keli_acceptance_source_window_diagnostic($window));
                    $this->assertLessThanOrEqual(12, count($window['attempts']));
                    if (in_array($mode, ['window-recollected', 'window-higher-threshold', 'window-register-race'], true)) {
                        $this->assertSame('threshold_reached', $window['stop_reason']);
                        $this->assertSame($threshold, $summary['observed_failures']);
                        $this->assertGreaterThan(5, count($window['attempts']));
                        $this->assertSame(1, $window['attempts'][0]['observed_failures']);
                    } else {
                        $this->assertNotSame('threshold_reached', $window['stop_reason']);
                    }
                    $windowResult['observation_window'] = $window;
                    if ($mode === 'window-register-race') {
                        $virtual++;
                        Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::createFromTimestampUTC($virtual));
                        $record($virtual, 'reachable');
                        $summary = $observer->summary($policy, $virtual);
                        $this->assertFalse($summary['evidence_current']);
                        $this->assertSame('healthy', $summary['probe']['state']);
                    }
                }
                $expected = ['run_id' => KeliLiveScope::RUN, 'machine_id' => 17, 'account_id' => 1,
                    'ip' => $fixture['sourceIp'], 'binary_sha256' => $release->binary_sha256, 'version' => $release->version,
                    'node_id' => 1, 'health_or_forwarding_claimed' => false];
                $facts = $task->only(['installation_id', 'status', 'bootstrap_state', 'machine_id', 'account_id',
                    'dispatched_at', 'external_id', 'provider_created_at', 'public_ips']) + ['label' => $payload['request']['label']];
                try {
                    keli_acceptance_cli_guard(function () use ($facts, $cloud, $inventory, $machine, $task, $summary, $expected, $virtual, $window): void {
                        keli_acceptance_source_facts($facts, $cloud, $inventory->snapshots([$machine], true)[17][$task->installation_id],
                            $summary, $expected, $virtual,
                            static fn ($d) => KeliLiveScope::journal('source-first.json', ['diagnostic' => $d]), $window);
                    }, static fn ($d) => KeliLiveScope::journal('cli-first.json', $d));
                    $this->assertContains($mode, ['window-recollected', 'window-higher-threshold']);
                } catch (KeliSourceFixtureEvidenceRejected $error) {
                    $this->assertNotContains($mode, ['window-recollected', 'window-higher-threshold']);
                    if ($mode === 'window-legacy') $this->assertSame(['threshold_reached', 'observed_failures'], $error->diagnostic['failed_fields']);
                    elseif ($mode === 'window-late-read') $this->assertSame(['observation_window_complete'], $error->diagnostic['failed_fields']);
                    else $this->assertContains('observation_window_complete', $error->diagnostic['failed_fields']);
                    $windowResult['captured_failure'] = keli_acceptance_cli_failure('adopt-source', $error);
                    $this->assertSame($error->diagnostic, KeliLiveScope::$journals['source-first.json']['diagnostic']);
                    $windowResult['controller_source_journal'] = KeliLiveScope::$journals['source-first.json'];
                }
            } elseif ($mode === 'callback-race') {
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
                    if ($mode !== 'storage-failure') {
                        $stored = KeliLiveScope::$journals['source-fixture-evidence-first-error.json'];
                        $this->assertSame(1, $stored['schema']);
                        $this->assertSame(2, $stored['diagnostic']['schema']);
                        $this->assertSame($error->diagnostic, $stored['diagnostic']);
                    }
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
            return $windowResult + ['ok' => true, 'mode' => $mode, 'sqlite' => true, 'network_calls' => 0,
                'forwarding_receipt_created' => false, 'candidate_created' => false, 'dns_written' => false];
        } finally {
            parent::tearDown();
        }
    }
}

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['valid', 'runtime-mismatch', 'storage-failure', 'callback-race', 'window-legacy',
        'window-recollected', 'window-timeout', 'window-recovered', 'window-unknown', 'window-stop',
        'window-duplicate', 'window-higher-threshold', 'window-late-read', 'window-wall-expired', 'window-clock-backwards',
        'window-register-race'], true)) throw new RuntimeException('synthetic_mode_required');
    $test = new SourceFixtureDatabaseHarness('test_initially_unreachable_source_completes_only_after_candidate_forwarding_and_owned_dns');
    echo json_encode($test->exercise($mode), JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    $reason = preg_match('/^[a-z_][a-z_0-9]{0,79}$/D', $error->getMessage()) ? $error->getMessage() : 'offline_assertion_failed';
    $caller = array_values(array_filter($error->getTrace(), static fn ($frame) => ($frame['file'] ?? null) === __FILE__))[0] ?? [];
    echo json_encode(['ok' => false, 'reason' => $reason, 'error_class' => get_class($error),
        'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'fixture_assertion_line' => $caller['line'] ?? null,
        'observation_window' => keli_acceptance_source_window_diagnostic(KeliLiveScope::$windowDiagnostic)], JSON_THROW_ON_ERROR);
    exit(1);
}
