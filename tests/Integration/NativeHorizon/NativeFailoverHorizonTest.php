<?php
declare(strict_types=1);

namespace Tests\Integration\NativeHorizon;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use Tests\Support\NativeFailoverHorizonFixture as HorizonFixture;

final class NativeFailoverHorizonTest extends TestCase
{
    private HorizonFixture $fixture;
    private array $run;
    private array $measurements = [];
    private int $started;

    protected function setUp(): void { $this->fixture = new HorizonFixture(); $this->started = hrtime(true); }
    protected function tearDown(): void
    {
        if (!isset($this->run)) return;
        Fixture::control('release', true, true);
        $summary = ['case' => $this->name(), 'isolation' => getenv('KELI_NATIVE_ISOLATION'), 'elapsed_seconds' => $this->elapsed(),
            'horizon_version' => \Composer\InstalledVersions::getPrettyVersion('laravel/horizon'), 'runtime_hashes' => HorizonFixture::runtimeHashes(),
            'job_timeout_seconds' => HorizonFixture::JOB_TIMEOUT, 'retry_after_seconds' => 90,
            'run_lease_seconds' => HorizonFixture::RUN_LEASE, 'member_lock_seconds' => HorizonFixture::MEMBER_LEASE,
            'timers_accelerated' => false, 'queue_driver' => get_class(app('queue')->connection('redis')),
            'mock_creates' => $this->eventTotal('create'), 'mock_dns_writes' => $this->eventTotal('dns_write'),
            'job_timeouts' => $this->eventTotal('job_timed_out'), 'failed_queue_jobs' => $this->eventTotal('job_failed'),
            'exhausted_jobs' => $this->eventTotal('queue_exhausted'), 'worker_errors' => $this->eventTotal('worker_error'),
            'horizon_errors' => $this->eventTotal('horizon_error'), 'measurements' => $this->measurements,
            'cloud_mocked' => true, 'dns_mocked' => true, 'production_connections' => 0, 'cleanup' => false];
        try { $summary['cleanup_evidence'] = $this->fixture->cleanup(); $summary['cleanup'] = true; }
        finally {
            $dir = dirname(__DIR__, 3) . '/artifacts'; if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($dir . '/native-horizon-summary.jsonl', json_encode($summary, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    private function seedRun(): void { $this->run = $this->fixture->seed(); }
    private function elapsed(): float { return round((hrtime(true) - $this->started) / 1e9, 3); }
    private function eventTotal(string $kind): int { return DB::table('native_fixture_event')->where('kind', $kind)->count(); }
    private function runState(): string { return Execution::query()->findOrFail($this->run['id'])->status; }
    private function diagnostics(): string
    {
        return implode(', ', [...DB::table('native_fixture_event')->whereIn('kind', ['worker_error', 'horizon_error'])->pluck('reference')->all(),
            ...Member::query()->whereNotNull('reason')->pluck('reason')->all()]);
    }
    private function awaitCondition(callable $condition, string $label, int $seconds = 20, bool $initialize = true, bool $stopsOnly = false): void
    {
        $until = hrtime(true) + $seconds * 1000000000;
        do {
            if ($condition()) return;
            $this->fixture->pulse($initialize, $stopsOnly); usleep(100000);
        } while (hrtime(true) < $until);
        $this->fail('Timed out: ' . $label . '; ' . $this->diagnostics());
    }
    private function daemonReady(string $master): void
    {
        $this->awaitCondition(fn () => $this->eventTotal('horizon_worker_boot') >= 2 && count($this->fixture->liveProcesses($master)) >= 4,
            'isolated master, supervisor and two workers', 25, false);
        $rows = $this->fixture->liveProcesses($master); $pid = $this->fixture->masterPid($master);
        $this->assertSame($pid, $rows[$pid]['session']); $this->assertSame($pid, $rows[$pid]['group']);
        $this->assertInstanceOf(\Laravel\Horizon\RedisQueue::class, app('queue')->connection('redis'));
        $this->assertTrue($this->fixture->boundaryIntact());
    }
    private function assertNoUnexpectedErrors(): void
    {
        $this->assertSame(0, $this->eventTotal('worker_error'), $this->diagnostics());
        $this->assertSame(0, $this->eventTotal('horizon_error'), $this->diagnostics());
    }
    private function claimTime(): int
    {
        return (int) DB::table('v2_machine_failover_runtime_event')->where('execution_id', $this->run['id'])->where('kind', 'claimed')->orderBy('id')->value('created_at');
    }
    private function blockedWorker(): int
    {
        $worker = DB::table('native_fixture_event')->where('kind', 'barrier')->where('reference', 'create_after')->orderBy('id')->value('worker');
        $this->assertMatchesRegularExpression('/^horizon-\d+$/D', (string) $worker); return (int) substr($worker, 8);
    }

    public function test_horizon_console_is_isolated_and_never_consumes_neighbor_work(): void
    {
        $this->seedRun(); Fixture::control('fault', 'preflight', true); $master = $this->fixture->startMaster(); $this->daemonReady($master);
        $this->awaitCondition(fn () => (int) app('redis')->connection()->get('fixture-barrier:preflight') > 0, 'mock preflight', 20, false);
        $this->fixture->stopRun(); Fixture::control('release', true, true);
        $this->awaitCondition(fn () => $this->runState() === 'stopped', 'local preflight stop', 20, false);
        $this->assertSame(0, $this->eventTotal('create')); $this->assertSame(0, $this->eventTotal('dns_write'));
        $this->assertSame(0, $this->eventTotal('job_failed')); $this->assertNoUnexpectedErrors();
        $this->assertTrue($this->fixture->boundaryIntact());
    }
    public function test_actual_job_timeout_and_full_leases_recover_the_original_intent(): void
    {
        $this->seedRun(); Fixture::control('fault', 'create_after', true); $master = $this->fixture->startMaster(); $this->daemonReady($master);
        $this->awaitCondition(fn () => $this->eventTotal('create') === 1, 'initial mock create', 20, false);
        $this->blockedWorker(); $claimedAt = $this->claimTime(); $runtime = Runtime::query()->findOrFail($this->run['id']);
        $member = Member::query()->firstOrFail();
        $runTtl = $this->fixture->ttl('machine-native:run:' . $this->run['id']); $memberTtl = $this->fixture->ttl('machine-native:member:' . $member->id);
        $this->assertGreaterThan(110000, $runTtl); $this->assertLessThanOrEqual(120000, $runTtl);
        $this->assertGreaterThan(290000, $memberTtl); $this->assertLessThanOrEqual(300000, $memberTtl);
        $this->assertSame(120, $runtime->lease_until - $claimedAt);
        $this->measurements += ['initial_run_lock_ms' => $runTtl, 'initial_member_lock_ms' => $memberTtl];
        $this->awaitCondition(fn () => $this->eventTotal('job_timed_out') === 1, 'real 75 second job timeout', 95, false);
        $timedOutAt = (int) DB::table('native_fixture_event')->where('kind', 'job_timed_out')->value('at');
        $this->assertGreaterThanOrEqual(74, $timedOutAt - $claimedAt); $this->assertLessThan(90, $timedOutAt - $claimedAt);
        $this->awaitCondition(fn () => $this->eventTotal('horizon_worker_boot') >= 3, 'Horizon worker replacement', 15, false);
        $this->awaitCondition(fn () => DB::table('v2_machine_failover_runtime_event')->where('execution_id', $this->run['id'])->where('kind', 'recovered')->exists(),
            'real 120 second runtime lease recovery', 65, false);
        $recoveredAt = (int) DB::table('v2_machine_failover_runtime_event')->where('execution_id', $this->run['id'])->where('kind', 'recovered')->orderBy('id')->value('created_at');
        $this->assertGreaterThanOrEqual(120, $recoveredAt - $claimedAt);
        $this->assertSame(1, $this->eventTotal('create')); $this->assertSame(0, $this->eventTotal('provider_readback'));
        $this->assertGreaterThan(0, $this->fixture->ttl('machine-native:member:' . $member->id));
        $this->awaitCondition(fn () => $this->runState() === 'completed', 'natural member lock expiry and original-intent completion', 300);
        $readbackAt = (int) DB::table('native_fixture_event')->where('kind', 'provider_readback')->orderBy('id')->value('at');
        $this->assertGreaterThanOrEqual(299, $readbackAt - $claimedAt);
        $this->assertTrue(DB::table('v2_machine_failover_runtime_event')->where('reason', 'worker_busy')->exists());
        $this->assertSame(1, Attempt::query()->count()); $this->assertSame(1, $this->eventTotal('create'));
        $this->assertSame(1, $this->eventTotal('dns_write')); $this->assertSame(1, $this->eventTotal('job_failed'));
        $this->assertSame(0, $this->eventTotal('queue_exhausted')); $this->assertNoUnexpectedErrors();
        $this->measurements += ['timeout_observed_seconds' => $timedOutAt - $claimedAt, 'runtime_recovered_seconds' => $recoveredAt - $claimedAt,
            'member_progress_seconds' => $readbackAt - $claimedAt, 'worker_replaced' => true];
    }
    public function test_master_crash_restart_continues_without_a_new_paid_intent(): void
    {
        $this->seedRun(); $first = $this->fixture->startMaster(); $this->daemonReady($first);
        $this->awaitCondition(fn () => Attempt::query()->whereNotNull('task_id')->exists()
            && Task::query()->find(Attempt::query()->first()->task_id)?->status === 'waiting_initialization', 'initial candidate', 30, false);
        $taskId = Attempt::query()->first()->task_id; $this->fixture->crashMaster($first);
        $this->awaitCondition(fn () => $this->fixture->liveProcesses($first) === [], 'orphan supervisor and worker shutdown', 25, false);
        $second = $this->fixture->startMaster();
        $this->awaitCondition(fn () => $this->eventTotal('horizon_worker_boot') >= 4 && count($this->fixture->liveProcesses($second)) >= 4,
            'restarted Horizon process tree', 25);
        $this->awaitCondition(fn () => $this->runState() === 'completed', 'resume queued original intent', 100);
        $this->assertSame($taskId, Attempt::query()->first()->task_id); $this->assertSame(1, Attempt::query()->count());
        $this->assertNotSame($this->fixture->masterPid($first), $this->fixture->masterPid($second));
        $this->assertSame(1, $this->eventTotal('create')); $this->assertSame(1, $this->eventTotal('dns_write'));
        $this->assertSame(0, $this->eventTotal('job_failed')); $this->assertNoUnexpectedErrors();
        $this->measurements += ['master_restarted' => true, 'orphan_processes_remaining' => 0, 'original_task_retained' => true];
    }
    public function test_pending_stop_survives_worker_death_and_real_runtime_lease_expiry(): void
    {
        $this->seedRun(); Fixture::control('fault', 'create_after', true); $master = $this->fixture->startMaster(); $this->daemonReady($master);
        $this->awaitCondition(fn () => $this->eventTotal('create') === 1, 'mock create before stop', 20, false);
        $claimedAt = $this->claimTime(); $worker = $this->blockedWorker();
        $this->assertSame('stopping', $this->fixture->stopRun()['state']); $this->fixture->killWorker($worker);
        config(['machine_failover.runner_enabled' => false]); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $this->awaitCondition(fn () => $this->runState() === 'stopped', 'real lease expiry with execution closed', 155, false, true);
        $stoppedAt = (int) DB::table('v2_machine_failover_runtime_event')->where('execution_id', $this->run['id'])->where('kind', 'stopped')->value('created_at');
        $this->assertGreaterThanOrEqual(120, $stoppedAt - $claimedAt); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertSame(1, $this->eventTotal('create')); $this->assertSame(0, $this->eventTotal('dns_write'));
        $this->assertSame(1, $this->eventTotal('queue_exhausted')); $this->assertSame(1, $this->eventTotal('job_failed'));
        $this->assertSame(0, $this->eventTotal('job_timed_out')); $this->assertSame(0, DB::table('v2_machine_failover_budget_release')->count());
        $this->assertGreaterThanOrEqual(3, $this->eventTotal('horizon_worker_boot')); $this->assertNoUnexpectedErrors();
        $this->measurements += ['stop_settled_seconds' => $stoppedAt - $claimedAt, 'execution_closed_during_stop_recovery' => true,
            'budget_released' => false, 'worker_replaced' => true];
    }
}
