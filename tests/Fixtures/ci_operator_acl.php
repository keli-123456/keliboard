<?php

declare(strict_types=1);

use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as Configuration;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Facade;

function check(bool $condition, string $diagnostic): void
{
    if (!$condition) throw new LogicException($diagnostic);
}

function sqlDenied(PDO $pdo, string $sql): void
{
    try { $pdo->exec($sql); }
    catch (PDOException $e) {
        check(in_array($e->errorInfo[1] ?? null, [1142, 1143], true), 'unexpected_mysql_rejection');
        return;
    }
    throw new LogicException('mysql_permission_too_broad');
}

function redisDenied(Redis $redis, string ...$command): void
{
    $redis->clearLastError();
    try { $reply = $redis->rawCommand(...$command); }
    catch (RedisException $e) {
        check(str_contains($e->getMessage(), 'NOPERM'), 'unexpected_redis_rejection');
        return;
    }
    check($reply === false && str_contains((string) $redis->getLastError(), 'NOPERM'), 'redis_permission_too_broad');
}

$phase = 'fixture_guard';
$redisAdmin = null; $prefix = ''; $redisUser = ''; $status = 0;
try {
    $mode = $argv[1] ?? '';
    $local = $mode === 'mysql-local' && PHP_OS_FAMILY === 'Windows';
    $ci = $mode === 'ci' && getenv('GITHUB_ACTIONS') === 'true' && getenv('GITHUB_WORKFLOW') === 'Manual failover lane ACL';
    check($local || $ci, 'wrong_fixture_environment');
    $in = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    $schema = $in['schema'] ?? '';
    $port = (int) ($in['mysql_port'] ?? 0);
    $redisPort = (int) ($in['redis_port'] ?? 0);
    check(($ci ? $schema === 'keli_operator_acl_ci' : preg_match('/^keli_operator_acl_[0-9a-f]{12}$/D', $schema) === 1)
        && $port >= 1024 && $port <= 65535 && (!$ci || ($redisPort >= 1024 && $redisPort <= 65535)), 'invalid_fixture_endpoint');
    $dsn = 'mysql:host=127.0.0.1;port='.$port.';dbname='.$schema;
    $admin = new PDO($dsn, 'root', $in['mysql_root_password'], [PDO::ATTR_TIMEOUT => 3]);
    check($admin->query('SELECT @@version')->fetchColumn() === '5.7.44'
        && (int) $admin->query('SELECT @@port')->fetchColumn() === ($ci ? 3306 : $port)
        && (!$local || $admin->query('SELECT @@server_uuid')->fetchColumn() === $in['local_uuid'])
        && count($admin->query('SHOW TABLES')->fetchAll()) === 0, 'mysql_fixture_identity_changed');

    $phase = 'mysql_prepare';
    $id = bin2hex(random_bytes(8));
    $user = 'acl_fixture_'.$id;
    $password = bin2hex(random_bytes(24));
    $peer = "'$user'@'".($ci ? '%' : 'localhost')."'";
    $root = dirname(__DIR__, 2);
    $loader = require ($local ? $in['autoload'] : "$root/vendor/autoload.php");
    $loader->setPsr4('App\\', ["$root/app"]);
    $app = new Application($root);
    Facade::setFacadeApplication($app);
    $app->instance('config', new Configuration());
    $app->instance('encrypter', $crypt = new Encrypter(random_bytes(32), 'AES-256-CBC'));
    foreach ([
        'v2_user' => 'id INT PRIMARY KEY, is_admin INT, banned INT, password VARCHAR(64)',
        'v2_stat_user' => 'id INT PRIMARY KEY, u INT',
        'v2_traffic_batch_receipt' => 'id INT PRIMARY KEY, fingerprint VARCHAR(64)',
        'failed_jobs' => 'id INT PRIMARY KEY, payload TEXT',
        'v2_machine_provision_task' => 'id VARCHAR(36) PRIMARY KEY, payload TEXT, status VARCHAR(40), dispatched_at INT, external_id VARCHAR(32)',
        'v2_machine_provision_step' => 'task_id VARCHAR(36), state VARCHAR(40), reason VARCHAR(50), created_at INT, UNIQUE(task_id,state,reason)',
        'v2_machine_failover_attempt' => 'id VARCHAR(36) PRIMARY KEY, task_id VARCHAR(36), execution_member_id VARCHAR(36)',
    ] as $table => $columns) $admin->exec("CREATE TABLE $table ($columns) ENGINE=InnoDB");
    $admin->exec("INSERT INTO v2_user VALUES(1,1,0,'synthetic_only'),(2,0,0,'synthetic_only')");
    $admin->exec('INSERT INTO v2_stat_user VALUES(1,12)');
    $admin->exec("INSERT INTO v2_traffic_batch_receipt VALUES(1,'old_receipt_fixture')");
    $insert = $admin->prepare("INSERT INTO v2_machine_provision_task VALUES(?, ?, 'waiting_agent', 200, 'fixture_external_id')");
    $insert->execute(['fixture_task', $crypt->encrypt(json_encode(['coordinated_attempt' => 'missing_parent']), false)]);
    $admin->exec("CREATE USER $peer IDENTIFIED BY '$password'");
    foreach (['v2_machine_provision_task', 'v2_machine_failover_attempt'] as $table)
        $admin->exec("GRANT SELECT ON $schema.$table TO $peer");
    $admin->exec("GRANT SELECT, INSERT ON $schema.v2_machine_provision_step TO $peer");
    $admin->exec("GRANT SELECT(id,is_admin,banned) ON $schema.v2_user TO $peer");
    $capsule = new Manager($app);
    $capsule->addConnection(['driver'=>'mysql','host'=>'127.0.0.1','port'=>$port,'database'=>$schema,
        'username'=>$user,'password'=>$password,'prefix'=>'','options'=>[PDO::ATTR_TIMEOUT=>3]]);
    $capsule->bootEloquent();
    $app->instance('db', $capsule->getDatabaseManager());
    $db = $capsule->getConnection();
    $app->instance('db.schema', $db->getSchemaBuilder());
    $worker = $db->getPdo();

    $phase = 'mysql_allowed_and_denied';
    $class = new ReflectionClass(MachineProvisionService::class);
    $service = $class->newInstanceWithoutConstructor();
    $actor = $class->getMethod('actor');
    $actor->invoke($service, 1);
    $refused = false;
    try { $actor->invoke($service, 2); }
    catch (CloudException $e) { $refused = $e->getMessage() === 'permission_revoked'; }
    check($refused, 'ordinary_user_not_rejected');
    $task = MachineProvisionTask::query()->findOrFail('fixture_task');
    $runner = new MachineFailoverRunner();
    check($runner->wakeTask($task) && !$runner->workerOwns($task), 'broken_route_not_fenced');
    check($worker->query("SELECT reason FROM v2_machine_provision_step WHERE state='routing_blocked'")->fetchAll(PDO::FETCH_COLUMN)
        === ['native_lineage_changed'], 'audit_append_or_dedup_failed');
    foreach (['SELECT password FROM v2_user WHERE id=1', 'UPDATE v2_user SET banned=1 WHERE id=2',
        'INSERT INTO v2_stat_user VALUES(2,99)', 'DELETE FROM v2_traffic_batch_receipt WHERE id=1',
        "INSERT INTO failed_jobs VALUES(1,'unexpected')", 'DELETE FROM v2_machine_provision_step',
        'CREATE TABLE unrelated_fixture (id INT)'] as $statement) sqlDenied($worker, $statement);
    check($admin->query("SELECT status,dispatched_at,external_id FROM v2_machine_provision_task WHERE id='fixture_task'")
        ->fetch(PDO::FETCH_NUM) == ['waiting_agent', 200, 'fixture_external_id'], 'dispatch_evidence_changed');
    check((int)$admin->query('SELECT SUM(u) FROM v2_stat_user')->fetchColumn() === 12
        && (int)$admin->query('SELECT SUM(banned) FROM v2_user')->fetchColumn() === 0
        && $admin->query('SELECT fingerprint FROM v2_traffic_batch_receipt')->fetchColumn() === 'old_receipt_fixture', 'legacy_rows_changed');
    $proof = ['mysql_version'=>'5.7.44', 'scoped_admin_read'=>true, 'blocked_audit_once'=>true, 'mysql_denials'=>7,
        'original_dispatch_preserved'=>true, 'legacy_rows_preserved'=>true];

    if ($ci) {
        $phase = 'redis_prepare';
        $prefix = "acl:$id:";
        $redisUser = 'operator_'.$id;
        $redisPass = bin2hex(random_bytes(24));
        $redisAdmin = new Redis();
        check($redisAdmin->connect('127.0.0.1', $redisPort, 2.0) && $redisAdmin->ping() !== false, 'redis_fixture_unavailable');
        $manual = MachineFailoverQueue::OPERATOR_NAME;
        $heartbeat = MachineFailoverQueue::OPERATOR_HEARTBEAT;
        $key = "$prefix".'queues:'.$manual;
        $lock = 'machine-native:run:fixture';
        $restart = 'illuminate:queue:restart';
        $businessQueues = ['stat','traffic_fetch','admin_operations'];
        check(in_array($redisAdmin->rawCommand('ACL','SETUSER',$redisUser,'reset','on','>'.$redisPass,
            "~$key", "~$key:delayed", "~$key:reserved", "~$key:notify", "~$prefix$heartbeat", "~$prefix$lock", "%R~$prefix$restart",
            '+eval','+get','+set','+setex','+del','+llen','+lpop','+rpush','+zadd','+zcard','+zrem','+zrangebyscore','+zremrangebyrank'), [true,'OK'], true),
            'redis_acl_setup_failed');
        $config = ['host'=>'127.0.0.1','port'=>$redisPort,'timeout'=>2,'read_timeout'=>2];
        $manager = new RedisManager($app, 'phpredis', ['options'=>['prefix'=>$prefix],
            'operator'=>$config+['username'=>$redisUser,'password'=>$redisPass], 'legacy'=>$config]);
        $store = new RedisStore($manager, '', 'operator');
        $trustedStore = new RedisStore($manager, '', 'legacy');
        $app->instance('cache', new Repository($trustedStore));
        check($store->put($heartbeat, str_repeat('a', 32), 15), 'heartbeat_write_failed');
        MachineFailoverOperatorPermit::requireConsumer();
        $ttl = (int) $redisAdmin->ttl($prefix.$heartbeat);
        check($ttl > 0 && $ttl <= 15, 'heartbeat_ttl_wrong');
        check($store->lock($lock, 20)->get(fn () => $trustedStore->lock($lock, 20)->get() === false), 'lock_not_shared');
        $after = $trustedStore->lock($lock, 20);
        check($after->get() && $after->release(), 'shared_lock_not_released');
        check($trustedStore->put($restart, 123, 30) && (string)$store->get($restart) === '123', 'worker_restart_read_failed');

        $phase = 'redis_queue_and_denials';
        $operatorQueue = new RedisQueue($manager, $manual, 'operator', 90, null);
        $trustedQueue = new RedisQueue($manager, 'stat', 'legacy', 90, null);
        foreach ([$operatorQueue,$trustedQueue] as $q) $q->setContainer($app);
        $payload = fn (string $name) => json_encode(['id'=>$name,'job'=>'fixture','attempts'=>0], JSON_THROW_ON_ERROR);
        foreach ($businessQueues as $name) $trustedQueue->pushRaw($payload($name), $name);
        $operatorQueue->pushRaw($payload('manual'), $manual);
        $job = $operatorQueue->pop($manual);
        check($job !== null && $job->attempts() === 1, 'manual_pop_failed');
        $job->release(0);
        $job = $operatorQueue->pop($manual);
        check($job !== null && $job->attempts() === 2, 'manual_reservation_recovery_failed');
        $job->delete();
        check($operatorQueue->size($manual) === 0, 'manual_job_not_acknowledged');
        $probe = new Redis(); $probe->connect('127.0.0.1', $redisPort, 2.0); $probe->auth([$redisUser,$redisPass]);
        foreach ($businessQueues as $name) {
            redisDenied($probe, 'LLEN', $prefix.'queues:'.$name);
            redisDenied($probe, 'RPUSH', $prefix.'queues:'.$name, 'blocked_fixture_write');
            $otherJob = $trustedQueue->pop($name);
            check($otherJob !== null && json_decode($otherJob->getRawBody(),true,flags:JSON_THROW_ON_ERROR)['id'] === $name,
                'legacy_queue_marker_changed');
            $otherJob->delete();
        }
        redisDenied($probe, 'SET', $prefix.$restart, 'blocked_fixture_write');
        redisDenied($probe, 'GET', $prefix.'unknown');
        check($store->forget($heartbeat), 'heartbeat_cleanup_failed');
        $proof += ['redis_denials'=>8,'heartbeat_and_lock_shared'=>true,'manual_queue_reservation_recovery'=>true,'legacy_queues_processed'=>3];
    }
    echo json_encode($proof, JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    $diagnostic = $error instanceof LogicException && preg_match('/^[a-z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : get_class($error);
    fwrite(STDERR, json_encode(['phase'=>$phase,'diagnostic'=>$diagnostic,'line'=>$error->getLine()]), "\n");
    $status = 1;
} finally {
    if ($redisAdmin && $redisUser !== '') $redisAdmin->rawCommand('ACL','DELUSER',$redisUser);
}
exit($status);
