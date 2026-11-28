<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorFailureJournal;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue as Queue;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WorkMachineFailoverOperator extends Command
{
    protected $signature = 'machines:work-operator-failover';
    protected $description = 'Run the standalone manual failover queue worker';

    public function handle(): int
    {
        MachineFailoverOperatorPermit::requireEnabled();
        try {
            foreach (['pcntl_async_signals', 'pcntl_alarm', 'pcntl_signal', 'posix_kill'] as $function)
                if (!function_exists($function)) throw new RuntimeException('operator_worker_signals_unavailable');
            $path = config('machine_failover.operator_failure_directory');
            if (!is_string($path) || $path === '' || realpath($path) !== $path) throw new RuntimeException('operator_journal_path_unavailable');
            $journal = new MachineFailoverOperatorFailureJournal($path);
        } catch (Throwable) {
            $this->error('Operator worker preflight failed.');
            return self::FAILURE;
        }

        try { return $this->runWorker($journal); }
        catch (Throwable $error) {
            try { $journal->workerError($error); } catch (Throwable) { /* Leave the prior durable state intact. */ }
            $this->error('Operator worker stopped. Check the dedicated failure journal.');
            return self::FAILURE;
        } finally { $journal->close(); }
    }

    private function runWorker(MachineFailoverOperatorFailureJournal $journal): int
    {
        $app = $this->laravel;
        $original = $app['events'];
        $directBinding = $app->isAlias(DispatcherContract::class) ? null : $app->make(DispatcherContract::class);
        // RedisJob::fail also resolves the dispatcher from the container, not from Worker.
        // Keep this dedicated CLI out of WorkCommand's failed_jobs and Horizon listeners.
        $events = new Dispatcher($app);
        $exceptions = new class($journal) implements ExceptionHandler {
            public function __construct(private MachineFailoverOperatorFailureJournal $journal) {}
            public function report(Throwable $e): void { $this->journal->workerError($e); }
            public function shouldReport(Throwable $e): bool { return true; }
            public function render($request, Throwable $e): Response { return new Response('Operator worker failed.', 500); }
            public function renderForConsole($output, Throwable $e): void { $output->writeln('Operator worker failed.'); }
        };
        $worker = new Worker($app->make(Factory::class), $events, $exceptions, fn () => $app->isDownForMaintenance(), static function () use ($app): void {
            foreach ($app['db']->getConnections() as $connection) {
                $connection->resetTotalQueryDuration();
                $connection->allowQueryDurationHandlersToRunAgain();
            }
            $app->forgetScopedInstances();
            Facade::clearResolvedInstances();
            memory_reset_peak_usage();
        });
        $apply = static function ($event, callable $record) use ($worker): void {
            if ($event->connectionName !== Queue::CONNECTION || $event->job->getQueue() !== Queue::OPERATOR_NAME) return;
            try { $record($event); }
            catch (Throwable $error) { $worker->shouldQuit = true; throw $error; }
        };
        $events->listen(JobProcessing::class, fn ($event) => $apply($event, fn ($e) => $journal->begin($e->job)));
        $events->listen(JobExceptionOccurred::class, fn ($event) => $apply($event, fn ($e) => $journal->failed($e->job, $e->exception)));
        $events->listen(JobFailed::class, fn ($event) => $apply($event, fn ($e) => $journal->failed($e->job, $e->exception)));
        $events->listen(JobTimedOut::class, fn ($event) => $apply($event, fn ($e) => $journal->failed($e->job, timedOut: true)));
        $events->listen(JobAttempted::class, fn ($event) => $apply($event, fn ($e) => $e->successful()
            ? $journal->completed($e->job) : $journal->failed($e->job)));

        $owner = bin2hex(random_bytes(16)); $lastHeartbeat = 0;
        $events->listen(Looping::class, function (Looping $event) use ($journal, $owner, &$lastHeartbeat) {
            if ($event->connectionName !== Queue::CONNECTION || $event->queue !== Queue::OPERATOR_NAME) return;
            $journal->requireCapacity();
            if ($lastHeartbeat + 3 > time()) return;
            if (!Cache::put(Queue::OPERATOR_HEARTBEAT, $owner, Queue::OPERATOR_HEARTBEAT_SECONDS))
                throw new RuntimeException('operator_heartbeat_unavailable');
            $lastHeartbeat = time();
        });
        $app->instance('events', $events);
        if ($directBinding !== null) $app->instance(DispatcherContract::class, $events);
        Facade::clearResolvedInstance('events');
        try {
            return $worker->setCache(Cache::store())->daemon(Queue::CONNECTION, Queue::OPERATOR_NAME,
                new WorkerOptions(memory: 128, timeout: Queue::JOB_TIMEOUT, sleep: 1, maxTries: 1, maxJobs: 100, maxTime: 900));
        } catch (Throwable $error) {
            try { $journal->workerError($error); } catch (Throwable) { /* Leave the prior durable state intact. */ }
            throw $error;
        } finally {
            try {
                if (Cache::get(Queue::OPERATOR_HEARTBEAT) === $owner) Cache::forget(Queue::OPERATOR_HEARTBEAT);
            } finally {
                $app->instance('events', $original);
                if ($directBinding !== null) $app->instance(DispatcherContract::class, $directBinding);
                Facade::clearResolvedInstance('events');
            }
        }
    }
}
