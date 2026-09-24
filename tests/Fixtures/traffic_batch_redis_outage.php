<?php

declare(strict_types=1);

// Run only after the normal fixture has verified the live private Redis identity.
if (!isset($root, $action, $fixture, $redisConfig) || $action !== 'queue-redis-outage'
    || PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() === 0
    || getenv('KELI_TRAFFIC_REDIS_OUTAGE_FIXTURE') !== '1'
    || getenv('KELI_TRAFFIC_REDIS_FIXTURE') !== '1' || $fixture->redisFixture === null
    || realpath($root) !== $root || fileowner($root) !== posix_geteuid()
    || !str_starts_with((string) getenv('KELI_ACCEPTANCE_HOST_NETNS'), 'net:[')
    || readlink('/proc/self/ns/net') === getenv('KELI_ACCEPTANCE_HOST_NETNS')) {
    throw new RuntimeException('Explicit guarded private outage action required.');
}
$reportId = \App\Services\TrafficBatchPayload::reportId($argv[2] ?? '');
$info = app('redis')->connection()->info('server');
if ($info['run_id'] !== $redisConfig['run_id'] || $info['redis_version'] !== '7.0.15'
    || \Illuminate\Support\Facades\DB::table('v2_traffic_batch')->exists()) {
    throw new RuntimeException('Live verified Redis and empty receipt fixture required.');
}
$write = static function (string $name, array $row) use ($root): void {
    $file = fopen($root . '/redis-outage-' . $name . '.json', 'x');
    if ($file === false) { throw new RuntimeException('Outage record already exists.'); }
    fwrite($file, json_encode($row, JSON_THROW_ON_ERROR));
    fflush($file);
    fclose($file);
};
$logger = new class($fixture) {
    public array $warnings = [];
    public function __construct(private object $fixture) {}
    public function warning(string $message, array $context = []): void {
        $row = ['event' => 'redis-outage-warning', 'message' => $message, 'context' => $context];
        $this->warnings[] = $row;
        $this->fixture->queueFixture->record($row);
    }
    public function error(string $message, array $context = []): void {
        throw new RuntimeException('Unexpected outage error log: ' . $message);
    }
};
app()->instance('log', $logger);
\Illuminate\Support\Facades\Facade::clearResolvedInstance('log');
$ready = ['pid' => getmypid(), 'run_id' => $info['run_id'], 'report_id' => $reportId,
    'at' => microtime(true), 'monotonic_ns' => hrtime(true), 'redis_verified_live' => true];
$write('ready', $ready);
$release = $root . '/redis-outage.release';
$deadline = hrtime(true) + 20000000000;
while (!is_file($release)) {
    if (hrtime(true) >= $deadline) { throw new RuntimeException('Outage release deadline exceeded.'); }
    usleep(10000);
    clearstatcache(true, $release);
}
if (is_link($release) || file_get_contents($release) !== 'redis-process-exited') {
    throw new RuntimeException('Invalid outage release marker.');
}
$service = new \App\Services\TrafficBatchService();
$acceptedAt = microtime(true);
$receipt = $service->accept(\App\Models\Server::query()->findOrFail(1), $reportId, [7 => [1, 3]]);
$dispatchAt = microtime(true);
$dispatched = $service->dispatch($receipt);
$finishedAt = microtime(true);
$pending = (array) \Illuminate\Support\Facades\DB::table('v2_traffic_batch')->where('id', $receipt->id)->first();
if ($dispatched || count($logger->warnings) !== 1
    || $logger->warnings[0]['message'] !== 'Traffic batch dispatch pending recovery'
    || !is_a($logger->warnings[0]['context']['error_class'] ?? '', \RedisException::class, true)
    || $pending['processed_at'] !== null || $pending['sync_finished_at'] !== null || $pending['payload'] === null) {
    throw new RuntimeException('Expected durable receipt and real Redis dispatch failure.');
}
$result = ['accepted_at' => $acceptedAt, 'dispatch_at' => $dispatchAt, 'finished_at' => $finishedAt,
    'monotonic_ns' => hrtime(true), 'pid' => getmypid(), 'dispatched' => $dispatched,
    'receipt' => $pending, 'warnings' => $logger->warnings,
    'transaction_level' => \Illuminate\Support\Facades\DB::transactionLevel()];
$write('finished', $result);
echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
