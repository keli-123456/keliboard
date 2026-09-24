<?php

declare(strict_types=1);

if (!isset($root, $temp, $action, $fixture) || getenv('KELI_TRAFFIC_REDIS_FIXTURE') !== '1'
    || getenv('KELI_TRAFFIC_QUEUE_FIXTURE') !== '1' || getenv('KELI_TRAFFIC_RESET_FIXTURE') !== '1'
    || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() === 0
    || readlink('/proc/self/ns/net') === getenv('KELI_ACCEPTANCE_HOST_NETNS')) {
    throw new RuntimeException('Explicit isolated non-root Linux Redis fixture required.');
}
$configPath = realpath((string) getenv('KELI_TRAFFIC_REDIS_CONFIG'));
if ($configPath === false || dirname($configPath) !== dirname($temp) || basename($configPath) !== 'redis-fixture.json'
    || is_link($configPath) || fileowner($configPath) !== posix_geteuid()) {
    throw new RuntimeException('Owned private Redis manifest required.');
}
$redisConfig = json_decode(file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
if (($redisConfig['host'] ?? null) !== '127.0.0.1' || !is_int($redisConfig['port'] ?? null)
    || $redisConfig['port'] < 1024 || $redisConfig['port'] > 65535
    || !preg_match('/^[a-f0-9]{32}$/D', $redisConfig['password'] ?? '')
    || !preg_match('/^[a-f0-9]{40}$/D', $redisConfig['run_id'] ?? '')) {
    throw new RuntimeException('Invalid private Redis identity.');
}
$probe = new Redis();
$probe->connect('127.0.0.1', $redisConfig['port'], 3);
$probe->auth($redisConfig['password']);
$info = $probe->info('server');
if ($info['run_id'] !== $redisConfig['run_id'] || $info['redis_version'] !== '7.0.15') {
    throw new RuntimeException('Unexpected Redis server.');
}
$probe->close();
$id = substr(basename($root), strlen('keli-traffic-http-'));
$redisPrefix = 'keli-traffic-' . $id . ':';
config([
    'database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => $redisPrefix],
        'default' => ['host' => '127.0.0.1', 'port' => $redisConfig['port'], 'password' => $redisConfig['password'],
            'database' => 0, 'timeout' => 3, 'read_timeout' => 3]],
    'queue.default' => 'redis',
    'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'traffic_fetch',
        'retry_after' => 90, 'block_for' => null, 'after_commit' => false],
    'app.name' => 'keli-traffic-fixture',
]);
app()->register(\Illuminate\Redis\RedisServiceProvider::class);
config(['cache.default' => 'file', 'cache.prefix' => $redisPrefix,
    'cache.stores.file' => ['driver' => 'file', 'path' => $root . '/horizon-cache']]);
$cache = new \Illuminate\Cache\CacheManager(app());
app()->instance('cache', $cache);
app()->instance('cache.store', $cache->store());
\Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
$horizon = require base_path('vendor/laravel/horizon/config/horizon.php');
$horizon['use'] = 'default';
$horizon['prefix'] = $redisPrefix . 'horizon:';
$horizon['name'] = 'fixture-' . $id;
$horizon['middleware'] = [];
$horizon['defaults'] = [];
$horizon['environments'] = ['acceptance' => ['traffic' => [
    'connection' => 'redis', 'queue' => ['traffic_fetch'], 'balance' => 'simple',
    'minProcesses' => 2, 'maxProcesses' => 2, 'tries' => 1, 'timeout' => 60,
    'sleep' => 1, 'memory' => 128, 'maxTime' => 150,
]]];
config(['horizon' => $horizon]);
$provider = app()->register(\Laravel\Horizon\HorizonServiceProvider::class);
$provider->boot();
$entry = escapeshellarg((string) getenv('KELI_TRAFFIC_PHP')) . ' ' . escapeshellarg(__DIR__ . '/traffic_batch_horizon.php');
\Laravel\Horizon\WorkerCommandString::$command = 'exec ' . $entry . ' horizon:work';
\Laravel\Horizon\SupervisorCommandString::$command = 'exec ' . $entry . ' horizon:supervisor';
foreach ([\Laravel\Horizon\Events\JobPushed::class, \Laravel\Horizon\Events\JobReserved::class,
    \Laravel\Horizon\Events\JobDeleted::class, \Laravel\Horizon\Events\JobReleased::class] as $event) {
    app('events')->listen($event, static function ($event) use ($fixture): void {
        $fixture->queueFixture->record(['event' => get_class($event), 'queue' => $event->queue,
            'connection' => $event->connectionName, 'payload' => $event->payload ?? null]);
    });
}

if (getenv('KELI_TRAFFIC_REDIS_FAULT_FIXTURE') === '1') {
    require __DIR__ . '/traffic_batch_redis_fault.php';
}

return new class($fixture->queueFixture, $redisPrefix) {
    public function __construct(private object $queue, private string $prefix) {}

    public function snapshot(): array
    {
        $redis = app('redis')->connection();
        $keys = ['ready' => 'queues:traffic_fetch', 'delayed' => 'queues:traffic_fetch:delayed',
            'reserved' => 'queues:traffic_fetch:reserved'];
        $result = ['driver' => get_class(app('queue')->connection()), 'retry_after' => 90, 'prefix' => $this->prefix];
        foreach ($keys as $name => $key) {
            $result[$name] = $name === 'ready' ? $redis->lrange($key, 0, -1) : $redis->zrange($key, 0, -1, ['withscores' => true]);
        }
        $result['masters'] = app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class)->all();
        $result['supervisors'] = app(\Laravel\Horizon\Contracts\SupervisorRepository::class)->all();
        return $result;
    }

    public function horizon(array $arguments): int
    {
        $classes = ['horizon' => \Laravel\Horizon\Console\HorizonCommand::class,
            'horizon:supervisor' => \Laravel\Horizon\Console\SupervisorCommand::class,
            'horizon:work' => \Laravel\Horizon\Console\WorkCommand::class,
            'horizon:terminate' => \Laravel\Horizon\Console\TerminateCommand::class,
            'horizon:status' => \Laravel\Horizon\Console\StatusCommand::class];
        $name = array_shift($arguments);
        if (!isset($classes[$name])) { throw new RuntimeException('Unsupported Horizon fixture command.'); }
        $paths = [];
        foreach ([$classes[$name], \Laravel\Horizon\MasterSupervisor::class, \Laravel\Horizon\Supervisor::class,
            \Laravel\Horizon\RedisQueue::class, \Illuminate\Queue\Worker::class, \App\Jobs\TrafficBatchApplyJob::class] as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $paths[] = ['class' => $class, 'path' => $path, 'sha256' => hash_file('sha256', $path)];
        }
        $this->queue->record(['event' => 'horizon-runtime', 'command' => $name, 'classes' => $paths,
            'pcntl' => extension_loaded('pcntl'), 'php' => PHP_VERSION, 'redis_extension' => phpversion('redis')]);
        $command = app($classes[$name]);
        $command->setLaravel(app());
        return $command->run(new \Symfony\Component\Console\Input\ArgvInput([__FILE__, ...$arguments]),
            new \Symfony\Component\Console\Output\ConsoleOutput());
    }
};
