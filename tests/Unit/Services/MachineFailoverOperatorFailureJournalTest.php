<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Console\Commands\WorkMachineFailoverOperator;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorFailureJournal as Journal;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue as Queue;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class MachineFailoverOperatorFailureJournalTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'operator-journal-test-' . bin2hex(random_bytes(8));
        mkdir($this->path, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '/*') ?: [] as $file) unlink($file);
        rmdir($this->path);
        parent::tearDown();
    }

    private function state(): array
    {
        return json_decode(file_get_contents($this->path . '/failure-state.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function delivery(string $uuid): SyncJob
    {
        return new SyncJob(app(), json_encode(['uuid' => $uuid, 'data' => ['ticket' => 'SECRET_PAYLOAD']], JSON_THROW_ON_ERROR),
            Queue::CONNECTION, Queue::OPERATOR_NAME);
    }

    public function test_failure_and_timeout_are_sanitized_and_first_failure_is_retained(): void
    {
        $journal = new Journal($this->path);
        $job = $this->delivery('006c112d-7cbe-407b-b456-17ed3f2455c1');
        $journal->begin($job);
        $journal->failed($job, new RuntimeException('TOKEN_AND_SQL_TEXT'));
        $initial = $this->state();
        $journal->failed($job, new TimeoutExceededException('later timeout'));
        self::assertSame($initial, $this->state());
        self::assertSame('job_failed', array_values($initial['failures'])[0]['reason']);
        $other = $this->delivery('0418e42b-9c04-4e57-833e-7fa83c534391');
        $journal->begin($other);
        $journal->failed($other, new TimeoutExceededException('PRIVATE_MATERIAL'));
        $journal->workerError(new RuntimeException('PRIVATE_CONNECTION_STRING'));
        $journal->close();
        $state = $this->state();
        self::assertSame(['job_failed','job_timeout'], array_column($state['failures'], 'reason'));
        self::assertNull($state['active']);
        self::assertSame('worker_error', $state['worker_error']['reason']);
        $raw = json_encode($state, JSON_THROW_ON_ERROR);
        foreach (['006c112d-7cbe-407b-b456-17ed3f2455c1','TOKEN_AND_SQL_TEXT','SECRET_PAYLOAD','PRIVATE_MATERIAL','PRIVATE_CONNECTION_STRING',base_path()] as $value)
            self::assertStringNotContainsString($value, $raw);
    }

    public function test_worker_lock_excludes_a_second_writer_and_success_is_not_archived(): void
    {
        $journal = new Journal($this->path);
        try {
            new Journal($this->path);
            self::fail('Concurrent journal writer was admitted.');
        } catch (RuntimeException $error) { self::assertSame('operator_journal_lock_unavailable', $error->getMessage()); }
        $job = $this->delivery('0acc2d84-023a-485b-b6f6-1fa44bd14186');
        $journal->begin($job);
        $journal->completed($job);
        $journal->close();
        (new Journal($this->path))->close();
        self::assertSame([], $this->state()['failures']);
        self::assertNull($this->state()['active']);
    }

    public function test_fresh_process_recovers_the_interrupted_delivery_without_replaying_it(): void
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $stdout = $this->path . '/child-stdout.txt'; $stderr = $this->path . '/child-stderr.txt';
        $fixture = dirname(__DIR__, 2) . '/Fixtures/operator_failure_journal_child.php';
        $child = proc_open([PHP_BINARY, '-n', $fixture, $this->path],
            [['file',$null,'r'],['file',$stdout,'w'],['file',$stderr,'w']], $pipes, base_path(), null, ['bypass_shell'=>true]);
        self::assertIsResource($child);
        try {
            $deadline = microtime(true) + 8;
            $active = null;
            do {
                $raw = @file_get_contents($this->path . '/failure-state.json');
                if (is_string($raw) && ($active = json_decode($raw, true)['active'] ?? null)) break;
                usleep(25000);
            } while (proc_get_status($child)['running'] && microtime(true) < $deadline);
            self::assertNotNull($active, trim(file_get_contents($stdout) . file_get_contents($stderr)));
            self::assertTrue(proc_terminate($child, 9));
        } finally {
            if (proc_get_status($child)['running']) proc_terminate($child, 9);
            proc_close($child);
        }

        $journal = new Journal($this->path);
        $journal->close();
        $state = $this->state();
        self::assertNull($state['active']);
        self::assertSame('worker_interrupted', $state['failures'][$active['delivery']]['reason']);
        (new Journal($this->path))->close();
        self::assertSame($state, $this->state());
    }

    public function test_full_state_stops_new_work_and_does_not_discard_existing_records(): void
    {
        (new Journal($this->path))->close();
        $state = $this->state();
        for ($i = 0; $i < 128; $i++) {
            $key = hash('sha256', 'fixture:' . $i);
            $state['failures'][$key] = ['delivery'=>$key, 'started_at'=>1, 'at'=>2, 'reason'=>'job_failed'];
        }
        $file = $this->path . '/failure-state.json';
        $proof = json_encode($state, JSON_THROW_ON_ERROR);
        file_put_contents($file, $proof);
        try {
            new Journal($this->path);
            self::fail('Worker can start when its failure journal is full.');
        } catch (RuntimeException $error) { self::assertSame('operator_journal_full', $error->getMessage()); }
        self::assertSame($proof, file_get_contents($file));
    }

    // The real worker counts all memory already allocated in the PHP process.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_real_worker_does_not_call_legacy_failure_listeners_or_queue_failer(): void
    {
        $app = app();
        $original = new Dispatcher($app);
        $app->instance('events', $original);
        $app->instance(\Illuminate\Contracts\Events\Dispatcher::class, $original);
        $legacy = 0;
        $original->listen(JobFailed::class, function () use (&$legacy): void { $legacy++; });
        $app->bind('queue.failer', static fn () => throw new RuntimeException('Legacy failer must not be resolved.'));
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode {
            public function activate(array $payload): void {}
            public function deactivate(): void {}
            public function active(): bool { return false; }
            public function data(): array { return []; }
        });
        $db = new Manager($app); $app->instance('db', $db->getDatabaseManager());

        $payload = '{"uuid":"ef7c0a8a-0498-4d86-b699-74a1a3d8ab5a","data":{},"attempts":0}';
        $redisQueue = $this->createMock(RedisQueue::class);
        $redisQueue->expects(self::once())->method('deleteReserved')->with(Queue::OPERATOR_NAME, self::isInstanceOf(RedisJob::class));
        $job = new class($app, $redisQueue, $payload, $payload, Queue::CONNECTION, Queue::OPERATOR_NAME) extends RedisJob {
            public function fire(): void
            {
                Cache::put('illuminate:queue:restart', 123, 15);
                throw new RuntimeException('SENSITIVE_FAKE_PROVIDER_PASSWORD');
            }
            protected function failed($e): void {}
        };
        $queue = $this->createMock(\Illuminate\Contracts\Queue\Queue::class);
        $queue->method('getConnectionName')->willReturn(Queue::CONNECTION);
        $queue->expects(self::once())->method('pop')->with(Queue::OPERATOR_NAME, 0)->willReturn($job);
        $factory = $this->getMockBuilder(QueueManager::class)->setConstructorArgs([$app])->onlyMethods(['connection','isPaused'])->getMock();
        $factory->expects(self::once())->method('connection')->with(Queue::CONNECTION)->willReturn($queue);
        $factory->method('isPaused')->with(Queue::CONNECTION, Queue::OPERATOR_NAME)->willReturn(false);
        $app->instance('queue', $factory);

        $journal = new Journal($this->path);
        $command = new WorkMachineFailoverOperator(); $command->setLaravel($app);
        try { self::assertSame(0, (new ReflectionMethod($command, 'runWorker'))->invoke($command, $journal)); }
        finally { $journal->close(); }
        self::assertSame(0, $legacy);
        self::assertSame($original, $app['events']);
        self::assertSame($original, $app->make(\Illuminate\Contracts\Events\Dispatcher::class));
        self::assertNull(Cache::get(Queue::OPERATOR_HEARTBEAT));
        self::assertSame(['job_failed'], array_column($this->state()['failures'], 'reason'));
        self::assertStringNotContainsString('SENSITIVE_FAKE_PROVIDER_PASSWORD', json_encode($this->state(), JSON_THROW_ON_ERROR));
        $original->dispatch(new JobFailed('redis', $job, new RuntimeException('legacy_fixture')));
        self::assertSame(1, $legacy);
    }
}
