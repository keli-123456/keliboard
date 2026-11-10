<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use Illuminate\Support\Facades\DB;
use Laravel\Horizon\HorizonServiceProvider;

final class NativeFailoverHorizonFixture
{
    public const WORKFLOW = 'Native Failover Horizon Acceptance';
    public const ENVIRONMENT = 'native-horizon-acceptance';
    public const JOB_TIMEOUT = 75;
    public const RUN_LEASE = 120;
    public const MEMBER_LEASE = 300;
    private NativeFailoverConcurrentFixture $inner;
    private array $masters = [];
    private float $heartbeat = 0;
    private float $sweep = 0;
    private string $neighbor;
    private int $peakFailoverWorkers = 0;
    public array $run;

    public static function configuration(array $environment, string $owner): array
    {
        NativeFailoverConcurrentFixture::configuration($environment);
        if (($environment['KELI_NATIVE_HORIZON_ACCEPTANCE'] ?? '') !== '1'
            || ($environment['GITHUB_WORKFLOW'] ?? '') !== self::WORKFLOW || !preg_match('/^[a-f0-9]{32}$/D', $owner))
            throw new \RuntimeException('Explicit dedicated Horizon CI workflow required.');
        return ['use' => 'default', 'prefix' => 'native-fixture:' . $owner . ':horizon:', 'name' => 'native-horizon-' . substr($owner, 0, 12),
            'env' => self::ENVIRONMENT, 'middleware' => [], 'defaults' => [], 'fast_termination' => false,
            'waits' => [MachineFailoverQueue::CONNECTION . ':' . MachineFailoverQueue::NAME => 120], 'memory_limit' => 192,
            'environments' => [self::ENVIRONMENT => ['native' => MachineFailoverQueue::supervisor(),
                'business' => ['connection' => 'redis', 'queue' => ['admin_operations'], 'balance' => false,
                    'minProcesses' => 1, 'maxProcesses' => 1, 'tries' => 1, 'timeout' => 30, 'sleep' => 1,
                    'memory' => 128, 'maxTime' => 900, 'maxJobs' => 1000]]]];
    }
    public static function configure(): void
    {
        $horizon = self::configuration(getenv(), NativeFailoverConcurrentFixture::$owner);
        if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pcntl') || !extension_loaded('posix')
            || \Composer\InstalledVersions::getPrettyVersion('laravel/horizon') !== 'v5.45.6') throw new \RuntimeException('Pinned Linux Horizon runtime required.');
        app()->instance('env', self::ENVIRONMENT);
        config(['app.name' => $horizon['name'], 'app.env' => self::ENVIRONMENT, 'horizon' => $horizon]);
        app()->instance('log', new \Psr\Log\NullLogger());
        app()->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, new class implements \Illuminate\Contracts\Foundation\MaintenanceMode {
            public function activate(array $payload): void { throw new \RuntimeException('Maintenance changes forbidden in fixture.'); }
            public function deactivate(): void { throw new \RuntimeException('Maintenance changes forbidden in fixture.'); }
            public function active(): bool { return false; }
            public function data(): array { return []; }
        });
        // Register the package's console services/events without a dashboard or production application bootstrap.
        $provider = new class(app()) extends HorizonServiceProvider {
            public function boot() { $this->normalizeConfig(); $this->registerEvents(); $this->registerCommands(); }
        };
        $provider->register();
        app()->instance('redis', new \Illuminate\Redis\RedisManager(app(), 'phpredis', config('database.redis')));
        $provider->boot();
        app('queue')->addConnector('redis', fn () => new \Laravel\Horizon\Connectors\RedisConnector(app('redis')));
        foreach (['redis', MachineFailoverQueue::CONNECTION] as $connection)
            if (!app('queue')->connection($connection) instanceof \Laravel\Horizon\RedisQueue) throw new \RuntimeException('Horizon queue connector required.');
        if (app('redis')->connection('horizon')->client()->getOption(\Redis::OPT_PREFIX) !== $horizon['prefix']
            || app('redis')->connection()->client()->getOption(\Redis::OPT_PREFIX) !== NativeFailoverConcurrentFixture::prefix())
            throw new \RuntimeException('Horizon/default Redis prefixes are not isolated.');
        \Laravel\Horizon\MasterSupervisor::determineNameUsing(fn () => $horizon['name']);
        $entry = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/native_failover_horizon.php')
            . ' ' . escapeshellarg(NativeFailoverConcurrentFixture::$owner);
        \Laravel\Horizon\SupervisorCommandString::$command = 'exec ' . $entry . ' horizon:supervisor';
        \Laravel\Horizon\WorkerCommandString::$command = 'exec ' . $entry . ' horizon:work';
        app('events')->listen(\Illuminate\Queue\Events\JobTimedOut::class, fn () => NativeFailoverConcurrentFixture::event('job_timed_out'));
    }
    public function seed(bool $recordSets = false): array
    {
        self::configuration(getenv(), str_repeat('a', 32));
        $this->inner = new NativeFailoverConcurrentFixture();
        $this->run = $this->inner->seed(1, self::configure(...), $recordSets);
        $this->neighbor = 'native-neighbor:' . NativeFailoverConcurrentFixture::$owner . ':';
        $redis = self::raw(); $redis->rpush($this->neighbor . 'queues:business', 'guard-one', 'guard-two');
        $redis->set($this->neighbor . 'horizon:masters', 'guarded-other-master'); $redis->close();
        return $this->run;
    }
    public function startMaster(): string
    {
        NativeFailoverConcurrentFixture::assertOwner(); $id = 'm' . bin2hex(random_bytes(6));
        $path = sys_get_temp_dir() . '/native-horizon-' . NativeFailoverConcurrentFixture::$owner . '-' . $id . '.log';
        $old = umask(0077);
        try {
            $process = proc_open(['setsid', PHP_BINARY, dirname(__DIR__) . '/Fixtures/native_failover_horizon.php',
                NativeFailoverConcurrentFixture::$owner, 'horizon', '--environment=' . self::ENVIRONMENT],
                [0 => ['pipe', 'r'], 1 => ['file', $path, 'a'], 2 => ['file', $path, 'a']], $pipes, dirname(__DIR__, 2));
        } finally { umask($old); }
        if (!is_resource($process)) throw new \RuntimeException('Cannot start isolated Horizon master.');
        fclose($pipes[0]); $pid = proc_get_status($process)['pid'];
        $this->masters[$id] = ['process' => $process, 'pid' => $pid, 'sid' => $pid, 'log' => $path]; return $id;
    }
    public function masterPid(string $id): int { return $this->masters[$id]['pid']; }
    public function crashMaster(string $id): void
    {
        $pid = $this->masterPid($id); $this->assertOwnedPid($pid); posix_kill($pid, SIGKILL);
    }
    public function killWorker(int $pid): void
    {
        $this->assertOwnedPid($pid); posix_kill($pid, SIGKILL);
    }
    private function assertOwnedPid(int $pid): void
    {
        $rows = $this->liveProcesses();
        if (!isset($rows[$pid])) throw new \RuntimeException('Refusing to signal an unowned process.');
        $args = (string) @file_get_contents('/proc/' . $pid . '/cmdline');
        $status = (string) @file_get_contents('/proc/' . $pid . '/status');
        if (!str_contains($args, 'native_failover_horizon.php') || !str_contains($args, NativeFailoverConcurrentFixture::$owner)
            || !preg_match('/^Uid:\s+(\d+)/m', $status, $match) || (int) $match[1] !== posix_geteuid())
            throw new \RuntimeException('Refusing to signal a mismatched fixture process.');
    }
    public function liveProcesses(?string $master = null): array
    {
        $sessions = $master ? [$this->masters[$master]['sid']] : array_column($this->masters, 'sid'); $rows = [];
        foreach (glob('/proc/[0-9]*/stat') as $path) {
            $raw = @file_get_contents($path); if (!$raw || ($end = strrpos($raw, ')')) === false) continue;
            $fields = explode(' ', trim(substr($raw, $end + 1))); $pid = (int) basename(dirname($path));
            if (count($fields) < 4 || $fields[0] === 'Z' || !in_array((int) $fields[3], $sessions, true)) continue;
            $args = explode("\0", (string) @file_get_contents(dirname($path) . '/cmdline'));
            $command = array_search('horizon:work', $args, true); $options = [];
            if ($command !== false) foreach ($args as $arg) {
                if (preg_match('/^--(memory|timeout|max-time|max-jobs|queue)=(.*)$/D', $arg, $match)) $options[$match[1]] = $match[2];
            }
            $rows[$pid] = ['pid' => $pid, 'parent' => (int) $fields[1], 'group' => (int) $fields[2], 'session' => (int) $fields[3],
                'connection' => $command !== false ? ($args[$command + 1] ?? null) : null, 'worker_options' => $options,
                'nice' => isset($fields[16]) ? (int) $fields[16] : null];
        }
        $this->peakFailoverWorkers = max($this->peakFailoverWorkers, count(array_filter($rows, fn ($row) => $row['connection'] === MachineFailoverQueue::CONNECTION)));
        return $rows;
    }
    public function workerEvidence(): array
    {
        $this->liveProcesses();
        return ['profile' => MachineFailoverQueue::supervisor(), 'peak_failover_workers' => $this->peakFailoverWorkers];
    }
    public function stopMaster(string $id): void
    {
        $pid = $this->masterPid($id);
        if (isset($this->liveProcesses($id)[$pid])) { $this->assertOwnedPid($pid); posix_kill($pid, SIGTERM); }
    }
    public function pulse(bool $initialize = true, bool $stopsOnly = false): void
    {
        $now = microtime(true);
        if (count(array_filter($this->liveProcesses(), fn ($row) => $row['connection'] === MachineFailoverQueue::CONNECTION)) > 1)
            throw new \RuntimeException('Failover worker cap exceeded.');
        $runtime = \App\Models\MachineFailoverRuntime::query()->findOrFail($this->run['id']);
        $fault = NativeFailoverConcurrentFixture::control('fault');
        $blocked = in_array($fault, ['preflight', 'create_after'], true)
            && (int) app('redis')->connection()->get('fixture-barrier:' . $fault) > 0
            && time() - ((int) $runtime->lease_until - self::RUN_LEASE) >= 5;
        // Publish controlled telemetry between steps, or while the mock transport is deliberately blocked.
        // Mixing a new Redis probe with an older SQL decision snapshot is a separate evidence-change scenario.
        if ($now - $this->heartbeat >= 3 && ($runtime->state !== 'processing' || $blocked)) {
            $this->inner->heartbeat($initialize); $this->heartbeat = $now;
        }
        if ($now - $this->sweep >= 1) { app(MachineFailoverRunner::class)->advance($stopsOnly); $this->sweep = $now; }
    }
    public function stopRun(): array { return $this->inner->stop($this->run['id']); }
    public function ttl(string $lock): int
    {
        $redis = self::raw(); try { return $redis->pttl(NativeFailoverConcurrentFixture::prefix() . 'cache:' . $lock); } finally { $redis->close(); }
    }
    public function boundaryIntact(): bool
    {
        $redis = self::raw();
        try {
            if ($redis->lrange($this->neighbor . 'queues:business', 0, -1) !== ['guard-one', 'guard-two']
                || $redis->get($this->neighbor . 'horizon:masters') !== 'guarded-other-master') return false;
            foreach ($redis->keys('*') as $key) if (!str_starts_with($key, NativeFailoverConcurrentFixture::prefix()) && !str_starts_with($key, $this->neighbor)) return false;
            return true;
        } finally { $redis->close(); }
    }
    public function cleanup(): array
    {
        foreach ($this->masters as $id => $master) $this->stopMaster($id);
        $until = microtime(true) + 10;
        while ($this->liveProcesses() && microtime(true) < $until) { NativeFailoverConcurrentFixture::control('release', true, true); usleep(100000); }
        foreach (array_keys($this->liveProcesses()) as $pid) { $this->assertOwnedPid($pid); posix_kill($pid, SIGKILL); }
        $until = microtime(true) + 5;
        while ($this->liveProcesses() && microtime(true) < $until) usleep(100000);
        if ($this->liveProcesses()) throw new \RuntimeException('Owned Horizon processes survived cleanup.');
        foreach ($this->masters as $master) {
            proc_close($master['process']);
            if (!str_starts_with($master['log'], sys_get_temp_dir() . '/native-horizon-' . NativeFailoverConcurrentFixture::$owner . '-'))
                throw new \RuntimeException('Private console file is not owned.');
            if (is_file($master['log']) && !unlink($master['log'])) throw new \RuntimeException('Private console cleanup failed.');
            if (file_exists($master['log'])) throw new \RuntimeException('Private console file survived cleanup.');
        }
        if (!$this->boundaryIntact()) throw new \RuntimeException('Neighbor Redis namespace changed.');
        $this->inner->cleanup();
        $redis = self::raw();
        try {
            $unchanged = $redis->lrange($this->neighbor . 'queues:business', 0, -1) === ['guard-one', 'guard-two']
                && $redis->get($this->neighbor . 'horizon:masters') === 'guarded-other-master';
            if (!$unchanged) throw new \RuntimeException('Neighbor Redis namespace changed during cleanup.');
            $redis->del($this->neighbor . 'queues:business', $this->neighbor . 'horizon:masters');
            if ($redis->dbSize() !== 0 || DB::select('SHOW TABLES') !== []) throw new \RuntimeException('Disposable service cleanup incomplete.');
        } finally { $redis->close(); }
        return ['live_processes' => 0, 'neighbor_unchanged' => true, 'redis_empty' => true, 'mysql_empty' => true, 'private_logs_removed' => true];
    }
    public static function runtimeHashes(): array
    {
        $result = [];
        foreach ([\Laravel\Horizon\MasterSupervisor::class, \Laravel\Horizon\Supervisor::class,
            \Illuminate\Queue\Worker::class, \App\Jobs\MachineFailoverRunJob::class, MachineFailoverQueue::class] as $class)
            $result[$class] = hash_file('sha256', (new \ReflectionClass($class))->getFileName());
        return $result;
    }
    private static function raw(): \Redis
    {
        $redis = new \Redis(); $redis->connect('127.0.0.1', (int) getenv('KELI_NATIVE_REDIS_PORT'), 2); return $redis;
    }
}
