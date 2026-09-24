<?php

declare(strict_types=1);

if (!isset($root, $action, $fixture) || getenv('KELI_TRAFFIC_REDIS_FIXTURE') !== '1'
    || getenv('KELI_TRAFFIC_REDIS_FAULT_FIXTURE') !== '1' || PHP_OS_FAMILY !== 'Linux'
    || posix_geteuid() === 0 || !is_string(getenv('KELI_ACCEPTANCE_HOST_NETNS'))
    || !str_starts_with((string) getenv('KELI_ACCEPTANCE_HOST_NETNS'), 'net:[')
    || readlink('/proc/self/ns/net') === getenv('KELI_ACCEPTANCE_HOST_NETNS')) {
    throw new RuntimeException('Explicit isolated Redis fault fixture required.');
}

function keliRedisFaultHold(string $phase, object $job): void
{
    global $root, $action, $fixture, $horizonArguments;
    if (getenv('KELI_TRAFFIC_REDIS_FAULT_PHASE') !== $phase) { return; }
    if ($action !== 'queue-horizon' || ($horizonArguments[0] ?? '') !== 'horizon:work'
        || realpath($root) !== $root || fileowner($root) !== posix_geteuid()) {
        throw new RuntimeException('Fault hold is restricted to marked Horizon worker.');
    }
    $base = $root . '/redis-fault-' . $phase;
    if (is_link($base . '.claimed')) { throw new RuntimeException('Unsafe hold claim.'); }
    $claim = @fopen($base . '.claimed', 'x');
    if ($claim === false) {
        if (is_file($base . '.claimed')) { return; }
        throw new RuntimeException('Cannot claim fault barrier.');
    }
    fclose($claim);
    $timeoutTest = getenv('KELI_TRAFFIC_REDIS_FAULT_TIMEOUT') === '1';
    if ($timeoutTest && (!in_array($phase, ['before-handle', 'before-ack'], true) || $job->timeout() !== 60)) {
        throw new RuntimeException('Timeout gate requires original 60-second job timeout.');
    }
    $entry = ['event' => 'redis-fault-hold', 'phase' => $phase, 'pid' => getmypid(),
        'timeout_test' => $timeoutTest, 'job_timeout' => $job->timeout(),
        'uuid' => $job->uuid(), 'attempts' => $job->attempts(),
        'transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel(),
        'monotonic_ns' => hrtime(true), 'at' => microtime(true)];
    $fixture->queueFixture->record($entry);
    $ready = fopen($base . '.ready', 'x');
    fwrite($ready, json_encode($entry, JSON_THROW_ON_ERROR));
    fflush($ready);
    fclose($ready);
    $deadline = hrtime(true) + ($timeoutTest ? 75000000000 : 12000000000);
    while (!is_file($base . '.release')) {
        if (hrtime(true) >= $deadline) { throw new RuntimeException('Redis fault barrier timed out.'); }
        usleep(10000);
        clearstatcache(true, $base . '.release');
    }
}

// Only the two observation points are overridden; ACK itself is the vendor method.
class KeliFixtureRedisFaultQueue extends \Laravel\Horizon\RedisQueue
{
    public function deleteReserved($queue, $job)
    {
        keliRedisFaultHold('before-ack', $job);
        parent::deleteReserved($queue, $job);
        keliRedisFaultHold('after-ack', $job);
    }
}

app('queue')->addConnector('redis', static function () {
    return new class(app('redis')) extends \Laravel\Horizon\Connectors\RedisConnector {
        public function connect(array $config)
        {
            if ($config['queue'] !== 'traffic_fetch' || $config['connection'] !== 'default'
                || $config['retry_after'] !== 90 || $config['block_for'] !== null || $config['after_commit'] !== false) {
                throw new RuntimeException('Fixed Redis queue configuration required.');
            }
            return new KeliFixtureRedisFaultQueue($this->redis, $config['queue'], $config['connection'],
                $config['retry_after'], $config['block_for'], $config['after_commit']);
        }
    };
});
app('events')->listen(\Illuminate\Queue\Events\JobProcessing::class,
    static function ($event): void { keliRedisFaultHold('before-handle', $event->job); });
if (getenv('KELI_TRAFFIC_REDIS_LIVE_OUTAGE') === '1') {
    foreach ([\Laravel\Horizon\Events\MasterSupervisorLooped::class, \Laravel\Horizon\Events\SupervisorLooped::class] as $event) {
        app('events')->listen($event, static function ($event) use ($fixture): void {
            $fixture->queueFixture->record(['event' => class_basename($event)]);
        });
    }
}
app('events')->listen(\Illuminate\Queue\Events\JobTimedOut::class,
    static function ($event) use ($fixture): void {
        $fixture->queueFixture->record(['event' => 'JobTimedOut', 'uuid' => $event->job->uuid(),
            'attempts' => $event->job->attempts(), 'job_timeout' => $event->job->timeout(),
            'transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel()]);
    });
app('events')->listen(\Laravel\Horizon\Events\WorkerProcessRestarting::class,
    static function ($event) use ($fixture): void {
        $process = $event->process->process;
        $fixture->queueFixture->record(['event' => 'horizon-worker-restarting',
            'exit_code' => $process->getExitCode(), 'signaled' => $process->hasBeenSignaled(),
            'term_signal' => $process->hasBeenSignaled() ? $process->getTermSignal() : null,
            'command' => $process->getCommandLine()]);
    });
