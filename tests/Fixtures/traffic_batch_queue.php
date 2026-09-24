<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\TrafficBatchService;
use Illuminate\Queue\Events;
use Illuminate\Support\Facades\DB;

if (!isset($root, $action) || getenv('KELI_TRAFFIC_QUEUE_FIXTURE') !== '1'
    || getenv('KELI_TRAFFIC_RESET_FIXTURE') !== '1') {
    throw new RuntimeException('Marked traffic fixture and real quota fixture required.');
}

return new class($root, $action) {
    private \Illuminate\Contracts\Cache\Repository $cache;

    public function __construct(private string $root, private string $currentAction) {}

    public function record(array $entry): void
    {
        file_put_contents($this->root . '/queue-events-' . getmypid() . '.jsonl', json_encode([
            'at' => microtime(true), 'monotonic_ns' => hrtime(true), 'pid' => getmypid(),
            'memory_bytes' => memory_get_usage(true), 'peak_bytes' => memory_get_peak_usage(true), ...$entry,
        ], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
    }

    public function boot(): void
    {
        config(['queue.failed' => ['driver' => 'database', 'database' => 'default', 'table' => 'failed_jobs']]);
        $this->cache = new \Illuminate\Cache\Repository(new \Illuminate\Cache\FileStore(
            new \Illuminate\Filesystem\Filesystem(), $this->root . '/queue-cache'));
        app()->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class,
            new class implements \Illuminate\Contracts\Foundation\MaintenanceMode {
                public function activate(array $payload): void { throw new RuntimeException('Fixture is never in maintenance.'); }
                public function deactivate(): void {}
                public function active(): bool { return false; }
                public function data(): array { return []; }
            });
        app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class,
            new class($this) implements \Illuminate\Contracts\Debug\ExceptionHandler {
                public function __construct(private object $fixture) {}
                public function report(\Throwable $e): void {
                    $this->fixture->record(['event' => 'reported', 'error' => get_class($e), 'message' => $e->getMessage()]);
                }
                public function shouldReport(\Throwable $e): bool { return true; }
                public function render($request, \Throwable $e) { throw $e; }
                public function renderForConsole($output, \Throwable $e): void { throw $e; }
            });
        foreach ([Events\WorkerStarting::class, Events\WorkerStopping::class, Events\JobProcessing::class,
            Events\JobProcessed::class, Events\JobReleasedAfterException::class, Events\JobFailed::class,
            Events\JobExceptionOccurred::class] as $event) {
            app('events')->listen($event, function ($event): void {
                $entry = ['event' => class_basename($event)];
                if (isset($event->job)) {
                    $job = $event->job;
                    $entry += ['uuid' => $job->uuid(), 'job_id' => $job->getJobId(), 'attempts' => $job->attempts(),
                        'deleted' => $job->isDeleted(), 'released' => $job->isReleased(), 'failed' => $job->hasFailed(),
                        'payload' => $job->payload()];
                }
                if ($event instanceof Events\JobReleasedAfterException) { $entry['backoff'] = $event->backoff; }
                if ($event instanceof Events\WorkerStopping) { $entry['status'] = $event->status; }
                $this->record($entry);
                if ($event instanceof Events\JobProcessing) { $this->hold('before-handle'); }
            });
        }
        if ($this->currentAction === 'queue-daemon') {
            DB::connection()->beforeExecuting(function ($sql): void {
                if (str_starts_with(str_replace('`', '"', $sql), 'delete from "jobs"')) {
                    $this->hold('before-ack');
                }
            });
        }
    }

    private function hold(string $phase): void
    {
        if (getenv('KELI_TRAFFIC_QUEUE_HOLD_PHASE') !== $phase) { return; }
        $barrier = (string) getenv('KELI_TRAFFIC_QUEUE_HOLD');
        if ($this->currentAction !== 'queue-daemon' || realpath(dirname($barrier)) !== $this->root
            || !preg_match('/^queue-hold-[a-f0-9]{32}$/D', basename($barrier)) || is_link($barrier)) {
            throw new RuntimeException('Invalid marked queue barrier.');
        }
        $barrier = $this->root . DIRECTORY_SEPARATOR . basename($barrier);
        file_put_contents($barrier . '.ready', json_encode([
            'phase' => $phase, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel(),
        ], JSON_THROW_ON_ERROR));
        $until = microtime(true) + 12;
        while (!is_file($barrier)) {
            if (microtime(true) >= $until) { throw new RuntimeException('Queue hold deadline exceeded.'); }
            usleep(10000);
            clearstatcache(true, $barrier);
        }
    }

    public function initialize(): void
    {
        require_once base_path('database/migrations/2019_08_19_000000_create_failed_jobs_table.php');
        (new \CreateFailedJobsTable())->up();
    }

    public function snapshot(): array
    {
        return [
            'retry_after' => config('queue.connections.database.retry_after'),
            'jobs' => DB::table('jobs')->orderBy('id')->get()->all(),
            'failed_jobs' => DB::table('failed_jobs')->orderBy('id')->get()->all(),
        ];
    }

    public function action(string $action, string $argument): int
    {
        switch ($action) {
            case 'queue-daemon':
                $options = json_decode($argument, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($options) || array_diff(array_keys($options), ['max_jobs', 'max_time', 'stop_when_empty'])
                    || !is_int($options['max_jobs'] ?? null) || $options['max_jobs'] < 0 || $options['max_jobs'] > 256
                    || !is_int($options['max_time'] ?? null) || $options['max_time'] < 1 || $options['max_time'] > 150
                    || !is_bool($options['stop_when_empty'] ?? null)) {
                    throw new RuntimeException('Bounded queue worker options required.');
                }
                $paths = [];
                foreach ([\Illuminate\Queue\Worker::class, \Illuminate\Queue\Console\WorkCommand::class,
                    \App\Jobs\TrafficBatchApplyJob::class, TrafficBatchService::class] as $class) {
                    $path = (new \ReflectionClass($class))->getFileName();
                    $paths[] = ['class' => $class, 'path' => $path, 'sha256' => hash_file('sha256', $path)];
                }
                $this->record(['event' => 'runtime', 'classes' => $paths, 'php' => PHP_VERSION,
                    'pcntl' => extension_loaded('pcntl'), 'options' => $options]);
                $command = new \Illuminate\Queue\Console\WorkCommand(app('queue.worker'), $this->cache);
                $command->setLaravel(app());
                $args = ['connection' => 'database', '--queue' => 'traffic_fetch', '--max-jobs' => $options['max_jobs'],
                    '--max-time' => $options['max_time'], '--sleep' => 1, '--memory' => 128, '--tries' => 1, '--json' => true];
                if ($options['stop_when_empty']) { $args['--stop-when-empty'] = true; }
                return $command->run(new \Symfony\Component\Console\Input\ArrayInput($args),
                    new \Symfony\Component\Console\Output\ConsoleOutput());
            case 'queue-recover':
                if (!ctype_digit($argument) || (int) $argument < 1 || (int) $argument > 1000) {
                    throw new RuntimeException('Bounded recovery required.');
                }
                $command = new \App\Console\Commands\RecoverTrafficBatches();
                $command->setLaravel(app());
                return $command->run(new \Symfony\Component\Console\Input\ArrayInput(['--limit' => $argument]),
                    new \Symfony\Component\Console\Output\ConsoleOutput());
            case 'queue-corrupt':
                $id = \App\Services\TrafficBatchPayload::reportId($argument);
                if (DB::table('v2_traffic_batch')->where('report_id', $id)->whereNull('processed_at')->update(['payload' => '{}']) !== 1) {
                    throw new RuntimeException('Expected exactly one unapplied fixture receipt.');
                }
                return 0;
            case 'queue-fill':
                if (!ctype_digit($argument) || (int) $argument < 1 || (int) $argument > 128) {
                    throw new RuntimeException('Bounded receipt count required.');
                }
                $server = Server::query()->findOrFail(1);
                for ($index = 0; $index < (int) $argument; $index++) {
                    (new TrafficBatchService())->accept($server, bin2hex(random_bytes(16)), [7 => [1, 3]]);
                }
                return 0;
            case 'queue-users':
                if ($argument !== '1000' || DB::table('v2_user')->count() !== 1 || DB::table('v2_traffic_batch')->exists()) {
                    throw new RuntimeException('Pristine 1000-user boundary fixture required.');
                }
                foreach (array_chunk(range(100, 1099), 100) as $ids) {
                    DB::table('v2_user')->insert(array_map(fn ($id) => [
                        'id' => $id, 'uuid' => sprintf('%08x-0000-4000-8000-%012x', $id, $id),
                        'email' => 'fixture-' . $id . '@example.invalid',
                    ], $ids));
                    $rows = \App\Models\User::query()->whereIn('id', $ids)->get()->map(fn ($user) => [
                        'user_id' => $user->id, ...app(\App\Services\UserSyncService::class)->computeSnapshot($user),
                    ])->all();
                    DB::table('user_sync_states')->insert($rows);
                }
                return 0;
            case 'queue-restart':
                $this->cache->forever('illuminate:queue:restart', bin2hex(random_bytes(16)));
                return 0;
        }
        throw new RuntimeException('Unknown queue fixture action.');
    }
};
