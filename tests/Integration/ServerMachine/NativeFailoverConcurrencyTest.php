<?php
declare(strict_types=1);

namespace Tests\Integration\ServerMachine;

use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

final class NativeFailoverConcurrencyTest extends TestCase
{
    private Fixture $fixture;
    private array $run;
    private array $workers = [];
    private int $killed = 0;

    protected function setUp(): void { $this->fixture = new Fixture(); }
    protected function tearDown(): void
    {
        if (!isset($this->run)) return;
        Fixture::control('exit', true, true); Fixture::control('release', true, true);
        foreach (array_keys($this->workers) as $id) $this->join($id, true);
        $summary = ['case' => $this->name(), 'isolation' => getenv('KELI_NATIVE_ISOLATION'), 'workers' => count($this->workers),
            'killed_workers' => $this->killed, 'mock_creates' => $this->eventCount('create'), 'mock_dns_writes' => $this->eventCount('dns_write'),
            'worker_errors' => $this->eventCount('worker_error'), 'member_reasons' => Member::query()->whereNotNull('reason')->pluck('reason')->all(),
            'runtime_reason' => Runtime::query()->find($this->run['id'])->reason,
            'cloud_mocked' => true, 'dns_mocked' => true, 'production_connections' => 0, 'cleanup' => false];
        try { $this->fixture->cleanup(); $summary['cleanup'] = true; }
        finally {
            $dir = dirname(__DIR__, 3) . '/artifacts'; if (!is_dir($dir)) mkdir($dir, 0700, true);
            file_put_contents($dir . '/native-failover-summary.jsonl', json_encode($summary, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    private function seed(int $members = 1): void { $this->run = $this->fixture->seed($members); }
    private function spawn(string $mode = 'once'): string
    {
        $id = bin2hex(random_bytes(6));
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Fixtures/native_failover_worker.php', Fixture::$owner, $id, $mode],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $this->assertIsResource($process); fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $this->workers[$id] = ['process' => $process, 'pipes' => $pipes, 'joined' => false, 'killed' => false]; return $id;
    }
    private function join(string $id, bool $cleanup = false): void
    {
        $worker = &$this->workers[$id]; if ($worker['joined']) return;
        $until = microtime(true) + ($cleanup ? 2 : 20); $status = proc_get_status($worker['process']);
        while ($status['running'] && microtime(true) < $until) { usleep(30000); $status = proc_get_status($worker['process']); }
        if ($status['running']) { proc_terminate($worker['process'], 9); $worker['killed'] = true; usleep(100000); }
        foreach ([1, 2] as $pipe) { stream_get_contents($worker['pipes'][$pipe]); fclose($worker['pipes'][$pipe]); }
        $exit = proc_close($worker['process']); $worker['joined'] = true;
        if (!$cleanup && !$worker['killed']) $this->assertTrue(($status['exitcode'] === 0 || $exit === 0), 'Child process failed; ' . $this->errors());
        if (!$cleanup) $this->assertFalse($status['running'], 'Child process exceeded its bounded fixture lifetime.');
    }
    private function kill(string $id): void
    {
        $this->assertTrue(proc_get_status($this->workers[$id]['process'])['running']);
        proc_terminate($this->workers[$id]['process'], 9); $this->workers[$id]['killed'] = true; $this->killed++; $this->join($id);
    }
    private function wait(callable $condition, string $label, int $seconds = 15): void
    {
        $until = microtime(true) + $seconds;
        do { if ($condition()) return; usleep(30000); } while (microtime(true) < $until);
        $this->fail('Timed out: ' . $label . '; ' . $this->errors());
    }
    private function barrier(string $phase, int $count = 1): void
    {
        $this->wait(fn () => (int) app('redis')->connection()->get('fixture-barrier:' . $phase) >= $count, $phase);
    }
    private function eventCount(string $kind): int { return DB::table('native_fixture_event')->where('kind', $kind)->count(); }
    private function errors(): string { return implode(', ', [...DB::table('native_fixture_event')->where('kind', 'worker_error')->pluck('reference')->all(), ...Member::query()->whereNotNull('reason')->pluck('reason')->all()]); }
    private function cleanWorkers(): void { $this->assertSame(0, $this->eventCount('worker_error'), $this->errors()); }
    private function workOnce(): void { $this->fixture->due($this->run['id']); $this->join($this->spawn()); $this->cleanWorkers(); }
    private function runStatus(): string { return (string) Execution::query()->findOrFail($this->run['id'])->status; }

    public function test_two_real_workers_complete_two_members_with_one_paid_intent_per_member(): void
    {
        $this->seed(2); $ids = [$this->spawn('loop'), $this->spawn('loop')];
        $until = microtime(true) + 25;
        while ($this->runStatus() === 'running' && microtime(true) < $until) {
            $this->fixture->initialized(); $this->fixture->due($this->run['id']); usleep(60000);
        }
        Fixture::control('exit', true, true); foreach ($ids as $id) $this->join($id);
        $this->cleanWorkers(); $this->assertSame('completed', $this->runStatus());
        $this->assertSame(2, $this->eventCount('create')); $this->assertSame(2, $this->eventCount('dns_write'));
        $this->assertSame(2, Attempt::query()->count()); $this->assertSame(2, Member::query()->where('phase', 'completed')->count());
    }
    public function test_duplicate_queue_payloads_are_fenced_across_three_workers(): void
    {
        $this->seed(); $redis = app('redis')->connection(); $payload = $redis->lindex('queues:admin_operations', 0) ?: ($redis->zrange('queues:admin_operations:delayed', 0, 0)[0] ?? null);
        $this->assertIsString($payload); $redis->rpush('queues:admin_operations', $payload, $payload);
        $ids = [$this->spawn(), $this->spawn(), $this->spawn()]; foreach ($ids as $id) $this->join($id);
        $this->cleanWorkers(); $this->assertSame(1, $this->eventCount('create')); $this->assertSame(1, Attempt::query()->count());
        $this->assertSame('waiting_initialization', Task::query()->findOrFail(Attempt::query()->first()->task_id)->status);
    }
    public function test_two_recovery_sweepers_replace_a_lost_ticket_only_once(): void
    {
        $this->seed(); app('redis')->connection()->del('queues:admin_operations');
        Runtime::query()->whereKey($this->run['id'])->update(['next_dispatch_at' => 0]);
        $ids = [$this->spawn('recover'), $this->spawn('recover')]; foreach ($ids as $id) $this->join($id);
        $this->assertSame(2, Runtime::query()->find($this->run['id'])->generation);
        $redis = app('redis')->connection(); $this->assertSame(1, $redis->llen('queues:admin_operations') + $redis->zcard('queues:admin_operations:delayed')); $this->workOnce();
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(1, Attempt::query()->count());
    }
    public function test_real_queue_connection_failure_preserves_the_ticket_until_recovery(): void
    {
        $this->seed(); app('redis')->connection()->del('queues:admin_operations');
        Runtime::query()->whereKey($this->run['id'])->update(['next_dispatch_at' => 0]);
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $message); $this->assertIsResource($socket);
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
        config(['database.redis.queue_fault' => ['host' => '127.0.0.1', 'port' => $port, 'timeout' => 1, 'read_timeout' => 1, 'database' => 0],
            'queue.connections.redis.connection' => 'queue_fault']);
        app()->instance('redis', new \Illuminate\Redis\RedisManager(app(), 'phpredis', config('database.redis'))); Fixture::queue();
        app(MachineFailoverRunner::class)->advance();
        $this->assertSame('queue_unavailable', Runtime::query()->find($this->run['id'])->reason); $this->assertSame(0, $this->eventCount('create'));
        config(['queue.connections.redis.connection' => 'default']); Fixture::queue();
        Runtime::query()->whereKey($this->run['id'])->update(['next_dispatch_at' => 0]); app(MachineFailoverRunner::class)->advance(); $this->workOnce();
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(1, Attempt::query()->count());
    }
    public function test_sigkill_after_create_reconciles_the_original_intent_without_another_post(): void
    {
        $this->seed(); Fixture::control('fault', 'create_after', true); $id = $this->spawn(); $this->barrier('create_after');
        $this->kill($id); $this->assertNull(Task::query()->findOrFail(Attempt::query()->first()->task_id)->external_id);
        $this->fixture->expire($this->run['id']); $this->join($this->spawn('recover')); Fixture::control('release', true, true); $this->workOnce();
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(1, Attempt::query()->count());
        $this->assertNotNull(Task::query()->findOrFail(Attempt::query()->first()->task_id)->external_id);
        $redis = app('redis')->connection(); foreach ($redis->zrange('queues:admin_operations:reserved', 0, -1) as $payload) $redis->zadd('queues:admin_operations:reserved', 0, $payload);
        $this->join($this->spawn()); $this->assertSame(1, $this->eventCount('create')); $this->cleanWorkers();
    }
    public function test_expired_live_worker_cannot_overwrite_a_new_workers_reconciliation(): void
    {
        $this->seed(); Fixture::control('fault', 'create_after', true); $old = $this->spawn(); $this->barrier('create_after');
        $this->fixture->expire($this->run['id']); $this->join($this->spawn('recover')); $this->workOnce();
        $task = Task::query()->findOrFail(Attempt::query()->first()->task_id); $this->assertNotNull($task->external_id); $state = $task->status;
        Fixture::control('release', true, true); $this->join($old); $this->cleanWorkers();
        $this->assertSame($state, $task->fresh()->status); $this->assertSame(1, $this->eventCount('create')); $this->assertSame(1, Attempt::query()->count());
    }
    public function test_stop_during_preflight_prevents_a_post_in_another_process(): void
    {
        $this->seed(); Fixture::control('fault', 'preflight', true); $id = $this->spawn(); $this->barrier('preflight');
        $this->assertSame('stopping', $this->fixture->stop($this->run['id'])['state']);
        Fixture::control('release', true, true); $this->join($id); $this->cleanWorkers();
        $this->assertSame('stopped', $this->runStatus()); $this->assertSame(0, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write'));
    }
    public function test_stop_and_sigkill_are_settled_by_the_disabled_execution_stop_sweeper(): void
    {
        $this->seed(); Fixture::control('fault', 'create_after', true); $id = $this->spawn(); $this->barrier('create_after');
        $this->fixture->stop($this->run['id']); $this->kill($id); $this->fixture->expire($this->run['id']); $this->join($this->spawn('stops'));
        $this->assertSame('stopped', $this->runStatus()); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write'));
        $this->assertSame(0, DB::table('v2_machine_failover_budget_release')->count()); $this->cleanWorkers();
    }
    public function test_concurrent_same_stop_intent_is_idempotent_under_both_isolation_levels(): void
    {
        $this->seed(); $view = app(MachineFailoverRunner::class)->view(Execution::query()->find($this->run['id']));
        Fixture::control('stop', ['id' => $this->run['id'], 'revision' => $view['revision'], 'request' => str_repeat('a', 32), 'admin' => Execution::query()->find($this->run['id'])->admin_id], true);
        DB::beginTransaction(); Runtime::query()->whereKey($this->run['id'])->lockForUpdate()->firstOrFail();
        try {
            $ids = [$this->spawn('stop'), $this->spawn('stop')];
            $this->wait(fn () => Fixture::control('stop-prior:' . $ids[0]) === true && Fixture::control('stop-prior:' . $ids[1]) === true, 'both stop snapshots before the row lock');
        } finally { DB::rollBack(); }
        foreach ($ids as $id) $this->join($id);
        $this->cleanWorkers(); $this->assertSame(2, $this->eventCount('stop_accepted'));
        $this->assertSame(1, DB::table('v2_machine_failover_runtime_event')->where('kind', 'stop_requested')->count()); $this->assertSame(0, $this->eventCount('create'));
    }
    public function test_stop_during_a_dns_read_prevents_the_patch(): void
    {
        $this->seed(); $this->workOnce(); $this->fixture->initialized(); $this->workOnce();
        $this->assertSame('awaiting_dns', Member::query()->first()->phase);
        Fixture::control('fault', 'dns_preflight', true); $this->fixture->due($this->run['id']); $id = $this->spawn(); $this->barrier('dns_preflight');
        $this->fixture->stop($this->run['id']); Fixture::control('release', true, true); $this->join($id); $this->cleanWorkers();
        $this->assertSame(0, $this->eventCount('dns_write')); $this->assertSame('cancelled', MachineDnsTransition::query()->first()->state);
    }
    public function test_stop_after_a_dns_patch_keeps_the_uncertain_result_without_reversing_it(): void
    {
        $this->seed(); $this->workOnce(); $this->fixture->initialized(); $this->workOnce();
        Fixture::control('fault', 'dns_after', true); $this->fixture->due($this->run['id']); $id = $this->spawn(); $this->barrier('dns_after');
        $this->fixture->stop($this->run['id']); Fixture::control('release', true, true); $this->join($id); $this->cleanWorkers();
        $this->assertSame('stopped', $this->runStatus()); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertSame(1, $this->eventCount('dns_write')); $this->assertSame(0, DB::table('v2_machine_failover_budget_release')->count());
    }
}
