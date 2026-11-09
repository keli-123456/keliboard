<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Unit\Services\MachineFailoverNativeBridgeTest;

final class NativeFailoverConcurrentFixture
{
    public const DATABASE = 'keli_acceptance_native_failover';
    public const USER = 'keli_native_fixture';
    public static string $owner;
    public static string $worker = 'parent';
    private ?MachineFailoverNativeBridgeTest $seed = null;
    private bool $owned = false;

    public static function configuration(array $env): array
    {
        if (($env['GITHUB_ACTIONS'] ?? '') !== 'true' || ($env['KELI_NATIVE_ACCEPTANCE'] ?? '') !== '1'
            || ($env['KELI_NATIVE_HOST'] ?? '') !== '127.0.0.1' || ($env['KELI_NATIVE_DATABASE'] ?? '') !== self::DATABASE
            || ($env['KELI_NATIVE_USER'] ?? '') !== self::USER || !is_string($env['KELI_NATIVE_PASSWORD'] ?? null)
            || trim($env['KELI_NATIVE_PASSWORD']) === '' || !in_array($env['KELI_NATIVE_ISOLATION'] ?? '', ['REPEATABLE READ', 'READ COMMITTED'], true)) {
            throw new \RuntimeException('Only the explicitly enabled disposable CI fixture is allowed.');
        }
        foreach (['KELI_NATIVE_MYSQL_PORT', 'KELI_NATIVE_REDIS_PORT'] as $key) {
            if (!ctype_digit((string) ($env[$key] ?? '')) || (int) $env[$key] < 1024 || (int) $env[$key] > 65535
                || in_array((int) $env[$key], [3306, 6379], true)) throw new \RuntimeException('Random loopback fixture ports required.');
        }
        return ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => (int) $env['KELI_NATIVE_MYSQL_PORT'],
            'database' => self::DATABASE, 'username' => self::USER, 'password' => $env['KELI_NATIVE_PASSWORD'],
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
            'isolation_level' => $env['KELI_NATIVE_ISOLATION'], 'options' => [\PDO::ATTR_TIMEOUT => 5]];
    }

    public static function connect(bool $create): void
    {
        $mysql = self::configuration(getenv());
        if (PHP_OS_FAMILY !== 'Linux' || !extension_loaded('pdo_mysql') || !extension_loaded('redis')
            || !preg_match('/^[a-f0-9]{32}$/D', self::$owner)) throw new \RuntimeException('Linux fixture runtime required.');
        app()->instance('db.transactions', new \Illuminate\Database\DatabaseTransactionsManager());
        Model::reguard(); Model::unsetEventDispatcher();
        (new \ReflectionProperty(Model::class, 'guardableColumns'))->setValue(null, []);
        $capsule = new Capsule(app()); $capsule->addConnection($mysql); $capsule->setAsGlobal(); $capsule->bootEloquent();
        app()->instance('db', $capsule->getDatabaseManager()); app()->instance('db.connection', $capsule->getConnection());
        app()->instance('db.schema', $capsule->getConnection()->getSchemaBuilder()); Model::setConnectionResolver($capsule->getDatabaseManager());
        $identity = DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user, @@version AS version, @@transaction_isolation AS isolation_level');
        if ($identity->db_name !== self::DATABASE || !str_starts_with($identity->db_user, self::USER . '@')
            || !str_starts_with($identity->version, '8.4.') || str_replace('-', ' ', $identity->isolation_level) !== $mysql['isolation_level'])
            throw new \RuntimeException('Unexpected disposable MySQL identity.');
        foreach (DB::select('SHOW GRANTS') as $grant) {
            $text = (string) array_values((array) $grant)[0];
            if (str_contains($text, ' ON *.* ') && !str_starts_with($text, 'GRANT USAGE ON *.* ')) throw new \RuntimeException('Global database privileges refused.');
        }
        if ($create && DB::select('SHOW TABLES') !== []) throw new \RuntimeException('An empty disposable database is required.');
        if (!$create) self::assertOwner();
        $redis = self::rawRedis(); $info = $redis->info('server');
        if (!str_starts_with($info['redis_version'] ?? '', '7.') || !preg_match('/^[a-f0-9]{40}$/D', $info['run_id'] ?? '')) throw new \RuntimeException('Redis 7 fixture required.');
        if (!$create && DB::table('native_fixture_owner')->value('redis_id') !== $info['run_id']) throw new \RuntimeException('Redis identity changed.');
        config(['database.redis' => ['client' => 'phpredis', 'options' => ['prefix' => self::prefix()],
            'default' => ['host' => '127.0.0.1', 'port' => (int) getenv('KELI_NATIVE_REDIS_PORT'), 'database' => 0, 'timeout' => 2, 'read_timeout' => 2]],
            'cache.default' => 'redis', 'cache.prefix' => 'cache:', 'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'default'],
            'queue.default' => 'redis', 'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'admin_operations',
                'retry_after' => 90, 'block_for' => null, 'after_commit' => false],
            'queue.connections.' . MachineFailoverQueue::CONNECTION => ['driver' => 'redis', 'connection' => 'default',
                'queue' => MachineFailoverQueue::NAME, 'retry_after' => MachineFailoverQueue::RETRY_AFTER,
                'block_for' => null, 'after_commit' => false], 'queue.failed.driver' => null]);
        (new \Illuminate\Redis\RedisServiceProvider(app()))->register();
        $cache = new \Illuminate\Cache\CacheManager(app()); app()->instance('cache', $cache); app()->instance('cache.store', $cache->store());
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
        if ($create) {
            Schema::create('native_fixture_owner', function (Blueprint $t) { $t->string('owner', 32)->primary(); $t->string('redis_id', 40); });
            DB::table('native_fixture_owner')->insert(['owner' => self::$owner, 'redis_id' => $info['run_id']]);
            foreach (['cloud', 'dns'] as $name) Schema::create('native_fixture_' . $name, function (Blueprint $t) { $t->string('id')->primary(); $t->json('value'); });
            Schema::create('native_fixture_event', function (Blueprint $t) { $t->id(); $t->string('kind'); $t->string('worker'); $t->string('reference')->nullable(); $t->unsignedInteger('at'); });
            $redis->set(self::prefix() . 'owner', self::$owner);
        } elseif ($redis->get(self::prefix() . 'owner') !== self::$owner) throw new \RuntimeException('Redis fixture ownership marker missing.');
        $redis->close();
    }

    public function seed(int $members = 1, ?callable $beforeStart = null): array
    {
        self::$owner = bin2hex(random_bytes(16)); self::$worker = 'parent';
        $this->seed = new class('test_runner_defaults_off_and_configuration_alone_cannot_open_the_hard_execution_gate') extends MachineFailoverNativeBridgeTest {
            protected function setUpInMemoryDatabase(): void { NativeFailoverConcurrentFixture::connect(true); }
            public function bootFixture(): void { User::withoutTimestamps(fn () => parent::setUp()); }
        };
        try {
            $this->seed->bootFixture(); $this->owned = true;
            $this->invoke('runtimeSchema');
            if ($members > 1) { $fixtures = $this->property('fixtures'); $fixtures[] = $this->invoke('fixture', [2]); $this->property('fixtures', $fixtures, true); }
            foreach ($this->property('records') as $id => $row) DB::table('native_fixture_dns')->insert(['id' => $id, 'value' => json_encode($row, JSON_THROW_ON_ERROR)]);
            self::transport(); self::queue(); config(['machine_failover.runner_enabled' => true]);
            if ($beforeStart) $beforeStart();
            $run = $this->invoke('start', [400, $members]);
            foreach (DB::select('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $row)
                if ($row->engine !== 'InnoDB') throw new \RuntimeException('InnoDB tables required.');
            return $run;
        } catch (\Throwable $error) {
            if (isset(self::$owner) && app()->bound('db') && Schema::hasTable('native_fixture_owner')) { $this->owned = true; $this->cleanup(); }
            throw new \RuntimeException('Fixture seed failed: ' . self::errorCode($error));
        }
    }

    public static function attach(string $owner, string $worker): void
    {
        self::$owner = $owner; self::$worker = $worker;
        $bootstrap = new class('bootFixture') extends TestCase {
            use InteractsWithInMemoryDatabase;
            public function bootFixture(): void {
                parent::setUp(); NativeFailoverConcurrentFixture::connect(false);
                $this->bindJsonResponseFactory();
                $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
                    'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
            }
        };
        $bootstrap->bootFixture();
        app()->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('n', 32), 'AES-256-CBC'));
        foreach (['machine_failover', 'machine_provision', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        $budget = \Mockery::mock(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class); $budget->shouldReceive('assertCapacity')->andReturnNull();
        app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class, $budget);
        $policies = \App\Models\MachineFailoverPolicy::query()->pluck('id')->all();
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        config(['machine_failover.observe_enabled' => true, 'machine_failover.runner_enabled' => true, 'machine_failover.allow_replacement' => true,
            'machine_failover.allow_rotation' => true, 'machine_failover.allowed_rotation_policy_ids' => \App\Models\MachineFailoverPolicy::query()->pluck('id')->all(),
            'machine_failover.allowed_replacement_policy_ids' => $policies, 'machine_provision.allow_live' => true,
            'machine_provision.allowed_test_machine_ids' => \App\Models\ServerMachine::query()->pluck('id')->all(),
            'machine_acceptance.allow_verification' => true, 'machine_acceptance.allow_dns' => true, 'machine_acceptance.allowed_policy_ids' => $policies,
            'machine_acceptance.verifier_url' => 'https://verifier.example.test/v1/verify', 'machine_acceptance.verifier_key' => str_repeat('k', 32),
            'machine_acceptance.verifier_profiles' => array_fill_keys($policies, 'fixture-profile')]);
        self::queue(); self::transport();
    }

    public static function queue(): void
    {
        $events = new \Illuminate\Events\Dispatcher(app()); app()->instance('events', $events); app()->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events);
        DB::connection()->setEventDispatcher($events);
        (new \Illuminate\Queue\QueueServiceProvider(app()))->register();
        $bus = new \Illuminate\Bus\Dispatcher(app(), fn ($connection) => app('queue')->connection($connection));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus); app()->instance(\Illuminate\Contracts\Bus\QueueingDispatcher::class, $bus); Bus::swap($bus);
        $exceptions = new class implements \Illuminate\Contracts\Debug\ExceptionHandler {
            public function report(\Throwable $e) {
                NativeFailoverConcurrentFixture::event($e instanceof \Illuminate\Queue\MaxAttemptsExceededException ? 'queue_exhausted' : 'worker_error', NativeFailoverConcurrentFixture::errorCode($e));
            }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw new \RuntimeException('HTTP rendering is unavailable in a fixture worker.'); }
            public function renderForConsole($output, \Throwable $e) { $output->writeln('Fixture worker failed.'); }
        };
        app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $exceptions);
        $events->listen(\Illuminate\Queue\Events\JobProcessed::class, fn () => self::event('job_processed'));
        $events->listen(\Illuminate\Queue\Events\JobFailed::class, fn () => self::event('job_failed'));
    }

    public static function work(string $mode): int
    {
        self::assertOwner(); self::event('worker_started');
        if ($mode === 'recover') { app(MachineFailoverRunner::class)->advance(); return 0; }
        if ($mode === 'stops') { config(['machine_failover.runner_enabled' => false]); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate()); app(MachineFailoverRunner::class)->advance(true); return 0; }
        $worker = new \Illuminate\Queue\Worker(app('queue'), app('events'), app(\Illuminate\Contracts\Debug\ExceptionHandler::class), fn () => false);
        $options = new \Illuminate\Queue\WorkerOptions(sleep: 0, maxTries: 1, timeout: 75);
        if ($mode === 'legacy') { $worker->runNextJob('redis', 'admin_operations', $options); return 0; }
        if ($mode === 'once') { $worker->runNextJob(MachineFailoverQueue::CONNECTION, MachineFailoverQueue::NAME, $options); return 0; }
        $until = microtime(true) + 45;
        while (microtime(true) < $until && self::control('exit') !== true) { $worker->runNextJob(MachineFailoverQueue::CONNECTION, MachineFailoverQueue::NAME, $options); usleep(50000); }
        return 0;
    }

    public static function transport(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.linode.com/')) {
                if ($request->method() === 'POST') {
                    $id = 1000 + self::event('create', hash('sha256', $request['label']));
                    $row = ['id' => $id, 'label' => $request['label'], 'tags' => $request['tags'], 'region' => $request['region'],
                        'type' => $request['type'], 'image' => $request['image'], 'created' => gmdate('Y-m-d\TH:i:s'), 'status' => 'running',
                        'ipv4' => ['8.8.4.' . (10 + $id % 200)], 'ipv6' => '', 'backups' => ['enabled' => false]];
                    DB::table('native_fixture_cloud')->insert(['id' => (string) $id, 'value' => json_encode($row, JSON_THROW_ON_ERROR)]);
                    self::barrier('create_after'); return Http::response($row);
                }
                if (str_ends_with($url, '/v4/account')) return Http::response(['euuid' => 'fixture-account-' . substr(hash('sha256', $request->header('Authorization')[0]), 0, 16)]);
                if (str_ends_with($url, '/profile/grants')) return Http::response('', 204);
                if (str_contains($url, '/volumes?')) return Http::response(['page' => 1, 'pages' => 1, 'results' => 0, 'data' => []]);
                if (str_contains($url, '/regions/')) return Http::response(['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']]);
                if (str_contains($url, '/images/')) return Http::response(['id' => 'linode/debian12', 'status' => 'available', 'deprecated' => false, 'capabilities' => ['cloud-init']]);
                if (str_contains($url, '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => 'standard', 'price' => ['monthly' => 48], 'region_prices' => []]);
                if (str_contains($url, '/account/settings')) { self::barrier('preflight'); return Http::response(['backups_enabled' => false]); }
                if (preg_match('~/linode/instances/(\d+)$~', $url, $match)) {
                    self::event('provider_readback');
                    self::barrier('readback'); return Http::response(json_decode(DB::table('native_fixture_cloud')->where('id', $match[1])->value('value'), true, 512, JSON_THROW_ON_ERROR));
                }
                if (str_contains($url, '/linode/instances?')) {
                    $filter = json_decode($request->header('X-Filter')[0] ?? '{}', true);
                    $rows = DB::table('native_fixture_cloud')->pluck('value')->map(fn ($raw) => json_decode($raw, true, 512, JSON_THROW_ON_ERROR))
                        ->filter(fn ($row) => !isset($filter['label']) || $filter['label'] === $row['label'])->values()->all();
                    return Http::response(['data' => $rows, 'pages' => 1, 'page' => 1, 'results' => count($rows)]);
                }
            }
            if ($url === 'https://verifier.example.test/v1/verify') {
                self::event('verify'); $challenge = json_decode($request->body(), true, 512, JSON_THROW_ON_ERROR);
                $echo = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'bytes' => 96, 'sha256' => $challenge['echo_sha256']];
                $reply = ['schema' => 1, 'challenge_id' => $challenge['challenge_id'], 'request_sha256' => hash('sha256', $request->body()),
                    'finished_at' => time(), 'cleanup_complete' => true, 'results' => array_map(fn ($node) => ['node_id' => $node['id'],
                        'endpoint_authenticated' => true, 'tcp' => $echo, 'udp' => $echo], $challenge['nodes'])];
                $body = json_encode($reply, JSON_THROW_ON_ERROR);
                return Http::response($body, 200, ['X-Keli-Signature' => hash_hmac('sha256', "response\n" . $body, config('machine_acceptance.verifier_key'))]);
            }
            if (str_starts_with($url, 'https://api.cloudflare.com/')) {
                if ($request->method() === 'GET') {
                    self::barrier('dns_preflight'); parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                    $hostname = $query['name_exact'] ?? $query['name'] ?? null;
                    $rows = DB::table('native_fixture_dns')->pluck('value')->map(fn ($raw) => json_decode($raw, true, 512, JSON_THROW_ON_ERROR))
                        ->filter(fn ($row) => $hostname === null || $row['name'] === $hostname)->values()->all();
                    return Http::response(['success' => true, 'result' => $rows,
                        'result_info' => ['total_pages' => 1, 'total_count' => count($rows), 'page' => 1, 'per_page' => 50]]);
                }
                if ($request->method() !== 'PATCH') throw new \RuntimeException('Unexpected mock DNS operation.');
                self::event('dns_write'); $id = basename(parse_url($url, PHP_URL_PATH));
                $row = json_decode(DB::table('native_fixture_dns')->where('id', $id)->value('value'), true, 512, JSON_THROW_ON_ERROR);
                $row['content'] = $request['content']; DB::table('native_fixture_dns')->where('id', $id)->update(['value' => json_encode($row, JSON_THROW_ON_ERROR)]);
                self::barrier('dns_after'); return Http::response(['success' => true, 'result' => $row]);
            }
            throw new \RuntimeException('Unexpected outbound HTTP blocked by fixture.');
        });
    }

    public function initialized(): void
    {
        foreach (Attempt::query()->whereNotNull('task_id')->get() as $attempt) {
            $task = Task::query()->findOrFail($attempt->task_id);
            if ($task->status !== 'waiting_initialization') continue;
            $secret = $this->invoke('callbackSecret', [$task]);
            app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $secret, 'succeeded');
            $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
            foreach ($this->property('fixtures') as $fixture) if ($fixture['machine']->id === $task->machine_id)
                $this->invoke('report', [$fixture, $task, $task->public_ips[0], true, time()]);
        }
    }
    public function heartbeat(bool $initialize = true): void
    {
        foreach ($this->property('fixtures') as $fixture) {
            $checkedAt = \App\Models\MachineFailoverObservation::query()->where('policy_id', $fixture['policy']->id)->latest('id')->value('checked_at');
            $interval = app(\App\Services\ServerMachine\MachineEntryProbe::class)->configuration()['interval_seconds'];
            if ($checkedAt === null || time() - $checkedAt >= $interval) {
                $this->invoke('report', [$fixture, $fixture['source'], $fixture['sourceIp'], false, time()]);
                app(MachineFailoverObserver::class)->observe($fixture['policy']->id);
            }
            foreach (Attempt::query()->whereNotNull('task_id')->get() as $attempt) {
                $task = Task::query()->findOrFail($attempt->task_id);
                if ($task->machine_id !== $fixture['machine']->id || !$task->external_id || !$initialize) continue;
                if ($task->status === 'waiting_initialization' && $task->bootstrap_state === null)
                    app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->invoke('callbackSecret', [$task]), 'succeeded');
                if ($task->fresh()->bootstrap_state === 'succeeded')
                    $this->invoke('report', [$fixture, $task, $task->public_ips[0], true, time()]);
            }
        }
    }
    public function due(string $id): void
    {
        Runtime::query()->whereKey($id)->update(['next_check_at' => 0]); Task::query()->whereNotIn('status', Task::TERMINAL)->update(['next_check_at' => 0]);
        $redis = app('redis')->connection(); $key = 'queues:' . MachineFailoverQueue::NAME . ':delayed';
        foreach ($redis->zrange($key, 0, -1) as $payload) $redis->zadd($key, 0, $payload);
    }
    public function stop(string $id): array
    {
        $runner = app(MachineFailoverRunner::class); $view = $runner->view(Execution::query()->findOrFail($id));
        return $runner->stop($id, $view['revision'], bin2hex(random_bytes(16)), (int) Execution::query()->findOrFail($id)->admin_id);
    }
    public function expire(string $id): void
    {
        Runtime::query()->whereKey($id)->update(['lease_until' => time() - 1, 'next_dispatch_at' => 0, 'next_check_at' => 0]);
        $raw = self::rawRedis();
        foreach (['machine-native:run:' . $id, ...Member::query()->where('execution_id', $id)->pluck('id')->map(fn ($n) => 'machine-native:member:' . $n)->all(),
            'machine-cloud:quota:linode', ...Task::query()->pluck('account_id')->unique()->map(fn ($n) => 'machine-cloud:account:' . $n)->all()] as $key)
            $raw->pexpire(self::prefix() . 'cache:' . $key, 1);
        $raw->close(); usleep(10000);
    }
    public function cleanup(): void
    {
        if (!$this->owned) return;
        self::assertOwner(); $redis = self::rawRedis();
        if ($redis->get(self::prefix() . 'owner') !== self::$owner) throw new \RuntimeException('Cleanup ownership mismatch.');
        $keys = $redis->keys(self::prefix() . '*'); if ($keys) $redis->del($keys);
        if ($redis->keys(self::prefix() . '*') !== []) throw new \RuntimeException('Redis fixture cleanup incomplete.');
        $redis->close();
        foreach (DB::select('SHOW TABLES') as $row) { $table = (string) array_values((array) $row)[0]; if ($table !== 'native_fixture_owner') Schema::drop($table); }
        Schema::drop('native_fixture_owner'); $this->owned = false;
    }
    public static function assertOwner(): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', self::$owner) || DB::table('native_fixture_owner')->value('owner') !== self::$owner)
            throw new \RuntimeException('Disposable fixture ownership mismatch.');
    }
    public static function control(string $key, mixed $value = null, bool $write = false): mixed
    {
        $redis = app('redis')->connection();
        if ($write) { $redis->set('fixture-control:' . $key, json_encode($value, JSON_THROW_ON_ERROR)); return $value; }
        $raw = $redis->get('fixture-control:' . $key); return !$raw ? null : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    public static function event(string $kind, ?string $reference = null): int
    {
        return DB::table('native_fixture_event')->insertGetId(['kind' => $kind, 'worker' => self::$worker, 'reference' => $reference, 'at' => time()]);
    }
    public static function barrier(string $phase): void
    {
        if (self::control('fault') !== $phase) return;
        self::event('barrier', $phase); app('redis')->connection()->incr('fixture-barrier:' . $phase);
        $until = microtime(true) + (getenv('KELI_NATIVE_HORIZON_ACCEPTANCE') === '1' ? 660 : 25);
        while (microtime(true) < $until) { if (self::control('release') === true) return; usleep(30000); }
        throw new \RuntimeException('Fixture barrier expired.');
    }
    public static function errorCode(\Throwable $error): string
    {
        $code = substr(str_replace('\\', '_', get_class($error)), -80) . ':' . basename($error->getFile()) . ':' . $error->getLine();
        if ($error instanceof \Illuminate\Database\QueryException) $code .= ':' . ($error->errorInfo[1] ?? 'sql');
        return substr($code, 0, 240);
    }
    public static function prefix(): string { return 'native-fixture:' . self::$owner . ':'; }
    private static function rawRedis(): \Redis
    {
        $redis = new \Redis(); $redis->connect('127.0.0.1', (int) getenv('KELI_NATIVE_REDIS_PORT'), 2); return $redis;
    }
    private function invoke(string $method, array $args = []): mixed { return (new \ReflectionMethod(MachineFailoverNativeBridgeTest::class, $method))->invokeArgs($this->seed, $args); }
    private function property(string $name, mixed $value = null, bool $write = false): mixed
    {
        $property = new \ReflectionProperty(MachineFailoverNativeBridgeTest::class, $name);
        if ($write) { $property->setValue($this->seed, $value); return null; } return $property->getValue($this->seed);
    }
}
