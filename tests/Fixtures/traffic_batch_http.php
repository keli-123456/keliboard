<?php

declare(strict_types=1);

// CLI-only acceptance fixture. Never load bootstrap/app.php, .env or live settings.
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
    throw new RuntimeException('CLI fixture only.');
}
$root = realpath((string) getenv('KELI_TRAFFIC_HTTP_FIXTURE'));
$temp = realpath(sys_get_temp_dir());
if ($root === false || $temp === false || dirname($root) !== $temp
    || !preg_match('/^keli-traffic-http-[a-f0-9]{32}$/D', basename($root))
    || is_link($root) || !is_file($root . '/fixture-marker')
    || file_get_contents($root . '/fixture-marker') !== 'isolated-traffic-http-v1') {
    throw new RuntimeException('Expected marked, direct-child temporary fixture directory.');
}
$database = $root . '/traffic.sqlite';
if (is_link($database)) {
    throw new RuntimeException('Database symlinks are not allowed.');
}
$action = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? '');
$postgres = (string) getenv('KELI_TRAFFIC_POSTGRES_CONFIG') !== '';
$mysql = (string) getenv('KELI_TRAFFIC_MYSQL_CONFIG') !== '';
if ($postgres && $mysql) { throw new RuntimeException('Choose exactly one isolated database driver.'); }
if (!$postgres && !$mysql && $action === 'init') {
    $handle = fopen($database, 'x');
    if ($handle === false) { throw new RuntimeException('Fixture must be new.'); }
    fclose($handle);
} elseif (!$postgres && !$mysql && !is_file($database)) {
    throw new RuntimeException('Fixture database missing.');
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\TrafficBatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;

$connection = $postgres
    ? require __DIR__ . '/traffic_batch_postgres.php'
    : ($mysql ? require __DIR__ . '/traffic_batch_mysql.php'
        : ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']);

$fixture = new class('traffic-http-fixture') extends \Tests\TestCase {
    use InteractsWithInMemoryDatabase;

    public ?object $resetFixture = null;
    public ?object $queueFixture = null;
    public ?object $redisFixture = null;
    public ?object $nodeFixture = null;

    public function bootFixture(array $connection): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestSettings();
        config([
            'app.env' => 'testing',
            'database.connections.default' => $connection,
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database', 'connection' => 'default', 'table' => 'jobs',
                'queue' => 'traffic_fetch', 'retry_after' => 90, 'after_commit' => false,
            ],
        ]);
        DB::purge('default');
        // Capsule initially binds SQLite objects; replacing only PDO leaves its SQL grammar cached.
        app()->instance('db.connection', DB::connection());
        app()->instance('db.schema', DB::connection()->getSchemaBuilder());
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db.schema');
        if ($connection['driver'] === 'sqlite') {
            DB::statement('PRAGMA busy_timeout = 5000');
            DB::statement('PRAGMA synchronous = FULL');
        } elseif ($connection['driver'] === 'mysql') {
            DB::statement('SET SESSION max_execution_time = 5000');
            DB::statement('SET SESSION innodb_lock_wait_timeout = 3');
            DB::statement('SET SESSION lock_wait_timeout = 3');
        } else {
            DB::statement("SET statement_timeout = '5s'");
            DB::statement("SET lock_timeout = '3s'");
        }
        app()->register(\Illuminate\Events\EventServiceProvider::class);
        app()->register(\Illuminate\Bus\BusServiceProvider::class);
        app()->register(\Illuminate\Queue\QueueServiceProvider::class);
        app()->instance('env', 'testing');
    }

    public function initialize(): void
    {
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('machine_id');
            $table->string('code');
            $table->boolean('enabled')->default(true);
            $table->string('name');
            $table->string('type');
            $table->decimal('rate', 8, 2);
            $table->boolean('rate_time_enable')->default(false);
            $table->text('rate_time_ranges')->nullable();
        });
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('token');
            $table->boolean('is_active');
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->integer('t')->nullable();
        });
        foreach (['v2_stat_user', 'v2_stat_server', 'v2_stat_user_node_day'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->bigIncrements('id');
                $key = [];
                if ($name !== 'v2_stat_server') {
                    $table->integer('user_id');
                    $key[] = 'user_id';
                }
                if ($name !== 'v2_stat_user') {
                    $table->integer('server_id');
                    $key[] = 'server_id';
                    $table->string('server_type');
                    if ($name === 'v2_stat_server') { $key[] = 'server_type'; }
                    else { $table->string('server_name'); }
                }
                if ($name !== 'v2_stat_server') {
                    $table->decimal('server_rate', 10, 2);
                    $key[] = 'server_rate';
                }
                $table->bigInteger('u');
                $table->bigInteger('d');
                $table->integer('record_at');
                $table->string('record_type');
                $table->integer('created_at');
                $table->integer('updated_at');
                $table->unique([...$key, 'record_at', 'record_type'], 'uniq_fixture_' . $name);
            });
        }
        Schema::create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        (require base_path('database/migrations/2026_09_20_000001_create_traffic_batch_receipts.php'))->up();
        DB::table('v2_server_machine')->insert([
            'id' => 12, 'token' => 'fixture-token-not-production', 'is_active' => true,
        ]);
        DB::table('v2_server')->insert([
            'id' => 1, 'machine_id' => 12, 'code' => '1', 'name' => 'fixture-node',
            'type' => 'socks', 'rate' => '1.50',
        ]);
        DB::table('v2_user')->insert(['id' => 7]);
    }

    public function snapshot(): array
    {
        $totals = [];
        foreach (['v2_user', 'v2_stat_user', 'v2_stat_user_node_day', 'v2_stat_server'] as $table) {
            $totals[$table] = [(int) DB::table($table)->sum('u'), (int) DB::table($table)->sum('d')];
        }
        $snapshot = [
            'runtime' => ['php' => PHP_VERSION, 'database' => DB::connection()->getDriverName()],
            'totals' => $totals, 'jobs' => DB::table('jobs')->count(),
            'receipts' => DB::table('v2_traffic_batch')->orderBy('id')->get([
                'id', 'report_id', 'content_hash', 'node_id', 'node_type',
                'processed_at', 'sync_finished_at', 'payload', 'retry_at',
            ])->all(),
        ];
        if ($this->resetFixture !== null) {
            $snapshot['quota_reset'] = $this->resetFixture->snapshot();
        }
        if ($this->queueFixture !== null) {
            $snapshot['queue_daemon'] = $this->queueFixture->snapshot();
        }
        if ($this->redisFixture !== null) {
            $snapshot['redis_queue'] = $this->redisFixture->snapshot();
        }
        return $snapshot;
    }

    public function fault(string $name, string $table, bool $enabled): void
    {
        $message = $table === 'jobs' ? 'fixture queue unavailable' : 'fixture late statistics failure';
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared($enabled
                ? "CREATE TRIGGER $name BEFORE INSERT ON $table BEGIN SELECT RAISE(ABORT, '$message'); END"
                : "DROP TRIGGER $name");
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::unprepared($enabled
                ? "CREATE TRIGGER $name BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'"
                : "DROP TRIGGER $name");
            return;
        }
        if ($enabled) {
            DB::unprepared("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION '$message'; END; \$\$");
            DB::unprepared("CREATE TRIGGER $name BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
        } else {
            DB::unprepared("DROP TRIGGER $name ON $table");
            DB::unprepared("DROP FUNCTION $name()");
        }
    }

    public function http(): void
    {
        // Use the shipped route registration and authentication, not test copies.
        $router = new \Illuminate\Routing\Router(app('events'), app());
        app()->instance('router', $router);
        $router->aliasMiddleware('server', \App\Http\Middleware\Server::class);
        $router->group(['prefix' => 'api/v2'], function ($router): void {
            (new \App\Http\Routes\V2\ServerRoute())->map($router);
        });
        if ($this->nodeFixture !== null) {
            $router->group(['prefix' => 'api/v1'], function ($router): void {
                (new \App\Http\Routes\V1\ServerRoute())->map($router);
            });
        }
        $request = Request::capture();
        app()->instance('request', $request);
        // Only the receipt endpoint is exposed by this fixture, including no static files.
        if ($request->path() !== 'api/v2/server/traffic/batch'
            && !($this->nodeFixture?->allows($request) ?? false)) {
            response()->json(['fixture_scope' => 'traffic_receipts_only'], 404)->send();
            return;
        }
        try {
            $response = $router->dispatch($request);
        } catch (\App\Exceptions\ApiException $error) {
            $response = response()->json(['error_class' => get_class($error)], $error->getCode());
        } catch (\Illuminate\Validation\ValidationException $error) {
            $response = response()->json(['error_class' => get_class($error)], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $response = response()->json(['error_class' => get_class($error)], $error->getStatusCode());
        } catch (\Throwable $error) {
            error_log((string) $error);
            $response = response()->json(['error_class' => get_class($error)], 500);
        }
        $this->nodeFixture?->record($request, $response);
        $response->header('Connection', 'close')->send();
    }
};

$fixture->bootFixture($connection);
if ($mysql && getenv('KELI_TRAFFIC_MYSQL_TRACE') === '1') {
    $trace = static function (array $entry) use ($root): void {
        file_put_contents($root . '/mysql-query-' . getmypid() . '.jsonl', json_encode(
            ['at' => microtime(true), ...$entry], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND);
    };
    $trace(['settings' => (array) DB::selectOne('SELECT CONNECTION_ID() AS connection_id, '
        . '@@transaction_isolation AS isolation_level, @@innodb_lock_wait_timeout AS lock_timeout')]);
    DB::connection()->beforeExecuting(static function ($sql, $bindings, $connection) use ($trace): void {
        $trace(['phase' => 'before', 'sql' => $sql, 'transaction_level' => $connection->transactionLevel()]);
    });
    DB::listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use ($trace): void {
        $trace(['phase' => 'after', 'sql' => $query->sql, 'duration_ms' => $query->time]);
    });
}
if (getenv('KELI_TRAFFIC_RESET_FIXTURE') === '1') {
    $fixture->resetFixture = require __DIR__ . '/traffic_batch_reset.php';
    $fixture->resetFixture->boot();
}
if (getenv('KELI_TRAFFIC_QUEUE_FIXTURE') === '1') {
    $fixture->queueFixture = require __DIR__ . '/traffic_batch_queue.php';
    $fixture->queueFixture->boot();
}
if (getenv('KELI_TRAFFIC_REDIS_FIXTURE') === '1') {
    $fixture->redisFixture = require __DIR__ . '/traffic_batch_redis.php';
}
if (getenv('KELI_TRAFFIC_NODE_FIXTURE') === '1') {
    $fixture->nodeFixture = require __DIR__ . '/traffic_batch_node.php';
    $fixture->nodeFixture->boot();
}
$barrier = (string) getenv('KELI_TRAFFIC_START_BARRIER');
if ($barrier !== '') {
    if (!in_array($action, ['accept', 'apply', 'dispatch', 'reset'], true)
        || dirname($barrier) !== $root || !preg_match('/^parallel-[a-f0-9]{32}$/D', basename($barrier))) {
        throw new RuntimeException('Invalid fixture start barrier.');
    }
    file_put_contents($barrier . '.ready-' . getmypid(), (string) getmypid());
    $deadline = microtime(true) + 10;
    while (!is_file($barrier)) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('Fixture start barrier timed out.'); }
        usleep(10000);
        clearstatcache(true, $barrier);
    }
}
switch ($action) {
    case 'init':
        $fixture->initialize();
        $fixture->resetFixture?->initialize();
        $fixture->queueFixture?->initialize();
        break;
    case 'http': $fixture->http(); return;
    case 'snapshot': break;
    case 'node-ech-save':
        require __DIR__ . '/traffic_batch_ech.php';
        exit(0);
    case 'node-configure':
    case 'node-restore':
        if ($fixture->nodeFixture === null) { throw new RuntimeException('Explicit node fixture required.'); }
        $fixture->nodeFixture->action($action, $argv[2] ?? '');
        break;
    case 'hy2-configure':
        if ($fixture->redisFixture === null || getenv('KELI_TRAFFIC_HY2_FIXTURE') !== '1'
            || DB::table('v2_traffic_batch')->exists()
            || DB::table('v2_server')->where('id', 1)->value('type') !== 'socks') {
            throw new RuntimeException('Explicit pristine isolated HY2 fixture required.');
        }
        DB::table('v2_server')->where('id', 1)->update(['type' => 'hysteria']);
        break;
    case 'queue-redis-outage':
        if ($fixture->redisFixture === null || getenv('KELI_TRAFFIC_REDIS_OUTAGE_FIXTURE') !== '1') {
            throw new RuntimeException('Explicit isolated Redis outage fixture required.');
        }
        require __DIR__ . '/traffic_batch_redis_outage.php';
        exit(0);
    case 'queue-horizon':
        if ($fixture->redisFixture === null) { throw new RuntimeException('Explicit Redis fixture required.'); }
        exit($fixture->redisFixture->horizon($horizonArguments));
    case 'queue-daemon':
    case 'queue-recover':
    case 'queue-corrupt':
    case 'queue-fill':
    case 'queue-users':
    case 'queue-restart':
        if ($fixture->queueFixture === null) { throw new RuntimeException('Explicit queue fixture required.'); }
        $code = $fixture->queueFixture->action($action, $argv[2] ?? '');
        if ($code !== 0) {
            echo json_encode($fixture->snapshot(), JSON_THROW_ON_ERROR) . PHP_EOL;
            exit($code);
        }
        break;
    case 'reset-seed':
    case 'reset':
    case 'reset-audit-off':
    case 'reset-audit-on':
    case 'quota-sync-off':
    case 'quota-sync-on':
        if ($fixture->resetFixture === null) { throw new RuntimeException('Explicit reset fixture required.'); }
        $fixture->resetFixture->action($action, $argv[2] ?? '');
        break;
    case 'queue-off':
        $fixture->fault('fixture_queue_off', 'jobs', true);
        break;
    case 'queue-on': $fixture->fault('fixture_queue_off', 'jobs', false); break;
    case 'late-write-off':
        $fixture->fault('fixture_late_write', 'v2_stat_server', true);
        break;
    case 'late-write-on': $fixture->fault('fixture_late_write', 'v2_stat_server', false); break;
    case 'accept':
        (new TrafficBatchService())->accept(\App\Models\Server::query()->findOrFail(1), $argv[2] ?? '',
            json_decode($argv[3] ?? '', true, 512, JSON_THROW_ON_ERROR));
        break;
    case 'apply':
    case 'dispatch':
        $reportId = \App\Services\TrafficBatchPayload::reportId($argv[2] ?? '');
        $receipt = DB::table('v2_traffic_batch')->where('node_id', 1)->where('report_id', $reportId)->first();
        if (!$receipt) { throw new RuntimeException('Fixture receipt missing.'); }
        if ($action === 'apply') { (new TrafficBatchService())->process((int) $receipt->id); }
        else { (new TrafficBatchService())->dispatch($receipt); }
        break;
    case 'recover-due':
        // Explicitly advance only fixture dispatch leases, not the machine clock.
        DB::table('v2_traffic_batch')->whereNull('sync_finished_at')->update(['retry_at' => 0]);
        echo json_encode(['scheduled' => (new TrafficBatchService())->recover(20)], JSON_THROW_ON_ERROR) . PHP_EOL;
        break;
    case 'worker-once':
        $job = app('queue')->connection()->pop('traffic_fetch');
        if ($job === null) { throw new RuntimeException('Expected a queued job.'); }
        try {
            // Real DatabaseJob -> CallQueuedHandler -> serialized production job -> service.
            $job->fire();
            if (!$job->isDeletedOrReleased()) { throw new RuntimeException('Queue job was not acknowledged.'); }
        } catch (\Throwable $error) {
            if (!$job->isDeletedOrReleased()) { $job->release(0); }
            echo json_encode(['job_error' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR) . PHP_EOL;
            exit(2);
        }
        break;
    default: throw new RuntimeException('Unknown fixture action.');
}
echo json_encode($fixture->snapshot(), JSON_THROW_ON_ERROR) . PHP_EOL;
