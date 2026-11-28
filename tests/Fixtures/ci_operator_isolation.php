<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Console\Commands\WorkMachineFailoverOperator;
use App\Console\Commands\AdvanceMachineFailoverOperator;
use App\Http\Controllers\V2\Server\MachineProvisionController;
use App\Jobs\MachineFailoverOperatorRunJob;
use App\Jobs\StatServerJob;
use App\Jobs\TrafficBatchApplyJob;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorControl;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue as Queue;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use Tests\Support\HandoverEvidenceFixture;
use Tests\Unit\Services\MachineFailoverNativeBridgeTest;

function requireFixture(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
function fixtureRedis(): Redis {
    $r = new Redis();
    requireFixture($r->connect('127.0.0.1', (int) getenv('KELI_NATIVE_REDIS_PORT'), 2), 'fixture_redis_unavailable');
    return $r;
}
function aclIdentity(): array {
    $password = getenv('KELI_OPERATOR_FIXTURE_PASS');
    requireFixture(is_string($password) && preg_match('/^[a-f0-9]{48}$/D',$password) === 1, 'fixture_acl_scope_missing');
    return ['username'=>'operator_'.Fixture::$owner, 'password'=>$password];
}
function aclAdmin(bool $create): void {
    $id = aclIdentity(); $redis = fixtureRedis(); $p = Fixture::prefix();
    if (!$create) { $redis->rawCommand('ACL','DELUSER',$id['username']); return; }
    $queue = 'queues:'.Queue::OPERATOR_NAME;
    $rw = [$queue,$queue.':delayed',$queue.':reserved',$queue.':notify','cache:'.Queue::OPERATOR_HEARTBEAT,
        'cache:machine-native:run:*','cache:machine-native:member:*','cache:machine-failover:write',
        'cache:machine-cloud:quota:linode','cache:machine-cloud:health:linode','cache:machine-cloud:account:*',
        'cache:machine-provision:slot:*','cache:machine-operation-notice:*','fixture-barrier:create_after','fixture-barrier:preflight'];
    $policy=\App\Models\MachineFailoverPolicy::query()->sole();
    if ($policy->credential_group_id) {
        $pool=app(\App\Services\ServerMachine\Cloud\MachineFailoverCredentials::class)->pool($policy->credential_group_id,'linode');
        $rw[]='cache:machine-cloud:catalog:'.$pool['fingerprint'];
    }
    $ro = ['cache:illuminate:queue:restart','cache:illuminate:queue:paused:'.Queue::CONNECTION.':'.Queue::OPERATOR_NAME,
        'fixture-control:fault','fixture-control:release','fixture-control:record_sets','cache:machine_addresses:*','cache:node_instances:*'];
    $reply = $redis->rawCommand('ACL','SETUSER',$id['username'],'reset','on','>'.$id['password'],
        ...[...array_map(fn ($k) => '~'.$p.$k,$rw),...array_map(fn ($k) => '%R~'.$p.$k,$ro),
            '+ping','+select','+get','+set','+setex','+del','+exists','+eval','+llen','+lpop','+rpush','+zadd','+zcard',
            '+zrem','+zrangebyscore','+zremrangebyrank','+mget','+incr']);
    requireFixture(in_array($reply,[true,'OK'],true),'fixture_acl_creation_failed');
}
function aclDiagnostics(): array {
    $r=fixtureRedis(); $identity=aclIdentity()['username']; $prefix=Fixture::prefix(); $codes=[];
    foreach ($r->rawCommand('ACL','LOG','64') ?: [] as $entry) {
        if (array_is_list($entry)) {
            $row=[];
            foreach (array_chunk($entry,2) as [$name,$value]) $row[$name]=$value;
        } else $row=$entry;
        if (($row['username'] ?? '')!==$identity) continue;
        $object=(string)($row['object'] ?? '');
        if (str_starts_with($object,$prefix)) $object=substr($object,strlen($prefix));
        if (preg_match('/^queues:(stat|traffic_fetch|admin_operations|machine_failover)(:|$)/D',$object)) continue;
        $object=preg_replace('/[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}|[a-f0-9]{8,}/i','<fixture-id>',$object);
        if (preg_match('/^[a-zA-Z0-9_:.<>-]{1,140}$/D',$object)) $codes[] = ($row['reason'] ?? 'unknown').':'.$object;
    }
    return array_values(array_unique($codes));
}
function useRestrictedRedis(): void {
    $id = aclIdentity(); $r = fixtureRedis();
    requireFixture($r->auth([$id['username'],$id['password']]) && $r->ping() !== false,'fixture_acl_identity_failed');
    foreach (['stat','traffic_fetch','admin_operations',Queue::NAME] as $name) {
        $key = Fixture::prefix().'queues:'.$name;
        foreach ([['LLEN',$key],['RPUSH',$key,'fixture_must_be_denied'],
            ['EVAL',\Illuminate\Queue\LuaScripts::size(),'3',$key,$key.':delayed',$key.':reserved']] as $command) {
            $r->clearLastError(); $error = '';
            try { $reply = $r->rawCommand(...$command); $error = (string) $r->getLastError(); }
            catch (RedisException $exception) { $reply = false; $error = $exception->getMessage(); }
            requireFixture($reply === false && str_contains($error,'NOPERM'),'legacy_queue_acl_too_broad');
        }
    }
    $r->close();
    config(['database.redis.default'=>array_replace(config('database.redis.default'),$id),
        'machine_failover.operator_failure_directory'=>getenv('KELI_OPERATOR_FIXTURE_JOURNAL'),
        'queue.failed'=>['driver'=>'database-uuids','database'=>'default','table'=>'failed_jobs']]);
    app()->forgetInstance('redis');
    (new \Illuminate\Redis\RedisServiceProvider(app()))->register();
    $cache = new \Illuminate\Cache\CacheManager(app());
    app()->instance('cache',$cache);
    app()->instance('cache.store',$cache->store());
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('cache');
    \Illuminate\Support\Facades\Facade::clearResolvedInstance('redis');
    requireFixture(app('redis')->connection()->ping() !== false,'restricted_redis_manager_unavailable');
    Fixture::event('restricted_acl', '12');
}
function operatorConfig(): void {
    config(['machine_failover.operator_enabled' => true, 'machine_failover.runner_enabled' => false,
        'machine_failover.workflow' => \App\Services\ServerMachine\Cloud\MachineFailoverWorkflow::READINESS,
        'machine_failover.allow_replacement' => false, 'machine_failover.allow_rotation' => false, 'machine_provision.allow_live' => false,
        'machine_acceptance.allow_readiness' => false, 'machine_acceptance.allow_verification' => false, 'machine_acceptance.allow_dns' => false]);
    app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
}
function prepareBusiness(): void {
    Schema::create('failed_jobs', function (Blueprint $t) {
        $t->id(); $t->string('uuid')->unique(); $t->text('connection'); $t->text('queue');
        $t->longText('payload'); $t->longText('exception'); $t->timestamp('failed_at')->useCurrent();
    });
    DB::table('failed_jobs')->insert(['uuid'=>'fixture_preexisting_failure','connection'=>'redis','queue'=>'stat',
        'payload'=>'existing_fixture','exception'=>'existing_fixture']);
    Schema::table('v2_user', fn (Blueprint $t) => $t->unsignedBigInteger('u')->default(0));
    Schema::table('v2_user', fn (Blueprint $t) => $t->unsignedBigInteger('d')->default(0));
    Schema::table('v2_user', fn (Blueprint $t) => $t->unsignedInteger('t')->default(0));
    DB::table('v2_user')->insert(['id' => 9000, 'is_admin' => false, 'banned' => false]);
    foreach (['v2_stat_server', 'v2_stat_user', 'v2_stat_user_node_day'] as $name) Schema::create($name, function (Blueprint $t) use ($name) {
        $key = [];
        if ($name !== 'v2_stat_server') { $t->unsignedBigInteger('user_id'); $key[] = 'user_id'; }
        if ($name !== 'v2_stat_user') { $t->unsignedBigInteger('server_id'); $t->string('server_type'); $key[] = 'server_id'; }
        if ($name === 'v2_stat_user_node_day') $t->string('server_name');
        if ($name !== 'v2_stat_server') { $t->decimal('server_rate', 8, 2); $key[] = 'server_rate'; }
        else $key[] = 'server_type';
        $t->unsignedInteger('record_at'); $t->char('record_type', 1);
        foreach (['u', 'd', 'created_at', 'updated_at'] as $column) $t->unsignedBigInteger($column);
        $t->unique([...$key, 'record_at', 'record_type'], 'fixture_stat_key');
    });
    Schema::create('v2_stat_job_receipt', function (Blueprint $t) { $t->uuid('delivery_id')->primary(); $t->char('content_hash', 64); $t->unsignedInteger('applied_at'); });
    (require base_path('database/migrations/2026_09_20_000001_create_traffic_batch_receipts.php'))->up();
}
function runConsole(string $mode): int {
    app()->instance('env', 'testing');
    app()->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, new class implements \Illuminate\Contracts\Foundation\MaintenanceMode {
        public function activate(array $payload): void { throw new LogicException('Fixture is not a maintenance controller'); }
        public function deactivate(): void { throw new LogicException('Fixture is not a maintenance controller'); }
        public function active(): bool { return false; }
        public function data(): array { return []; }
    });
    $events = app('events');
    $worker = new \Illuminate\Queue\Worker(app('queue'), $events, app(\Illuminate\Contracts\Debug\ExceptionHandler::class), fn () => false);
    $cli = new \Illuminate\Console\Application(app(), $events, 'fixture');
    $cli->setAutoExit(false); $cli->setCatchExceptions(false);
    $cli->add(new \Illuminate\Queue\Console\WorkCommand($worker, Cache::store()));
    $cli->add(new WorkMachineFailoverOperator());
    $cli->add(new AdvanceMachineFailoverOperator());
    return $cli->run(new \Symfony\Component\Console\Input\ArrayInput(match ($mode) {
        'worker' => ['command' => 'machines:work-operator-failover'],
        'timer' => ['command' => 'machines:advance-operator-failover'],
        'business' => ['command' => 'queue:work', 'connection' => 'redis', '--queue' => 'stat,traffic_fetch', '--sleep' => 1, '--max-time' => 300, '--tries' => 1],
    }),
        new \Symfony\Component\Console\Output\NullOutput());
}

function processProof(string $kind): void {
    $status = file_get_contents('/proc/self/status'); $group = trim(file_get_contents('/proc/self/cgroup'));
    requireFixture(preg_match('~^0::(/system.slice/keli-operator-ci-[a-f0-9-]+\.service)$~D', $group, $m) === 1
        && preg_match('/^NoNewPrivs:\s+1$/m', $status) === 1, 'fixture_systemd_isolation_required');
    $path = '/sys/fs/cgroup' . $m[1];
    Fixture::event($kind, json_encode(['uid' => posix_geteuid(), 'gid' => posix_getegid(), 'pid' => getmypid(),
        'cpu' => trim(file_get_contents($path . '/cpu.max')), 'memory' => trim(file_get_contents($path . '/memory.max')),
        'swap' => trim(file_get_contents($path . '/memory.swap.max')), 'tasks' => trim(file_get_contents($path . '/pids.max'))], JSON_THROW_ON_ERROR));
}

try {
    Fixture::configuration(getenv());
    [$self, $owner, $action, $case] = $argv;
    requireFixture(PHP_OS_FAMILY === 'Linux' && posix_geteuid() !== 0 && getenv('GITHUB_WORKFLOW') === 'Manual failover failure journal'
        && preg_match('/^[a-f0-9]{32}$/D', $owner) === 1 && in_array($case, ['timeout', 'recovery'], true), 'fixture_only');
    if ($action === 'seed') {
        Fixture::$owner = $owner; Fixture::$worker = 'seed';
        $seed = new class('test_operator_ticket_is_bound_to_one_run_while_the_legacy_switches_stay_closed') extends MachineFailoverNativeBridgeTest {
            protected function setUpInMemoryDatabase(): void { Fixture::connect(true); }
            public function bootFixture(): void { User::withoutTimestamps(fn () => parent::setUp()); }
        };
        $seed->bootFixture();
        $queue = config('queue.connections.' . Queue::CONNECTION);
        $service = (new ReflectionMethod(MachineFailoverNativeBridgeTest::class, 'operatorService'))->invoke($seed);
        aclAdmin(true);
        if ($case === 'timeout') Fixture::control('fault', 'preflight', true);
        if ($case === 'recovery') {
            $policy = (new ReflectionMethod(MachineFailoverNativeBridgeTest::class, 'operatorPolicy'))->invoke($seed, $service);
            $keys = (new ReflectionProperty(HandoverEvidenceFixture::class, 'keys'))->getValue();
            requireFixture(openssl_pkey_export($keys[MachineFailoverHandoverEvidence::CLOSE], $private), 'fixture_key_unavailable');
            // Disposable fixture key only, scoped to the random Redis namespace and removed with it.
            Fixture::control('fixture-closure-key', $private, true);
            Fixture::control('operator-control-policy', $policy, true);
            Fixture::control('fault', 'create_after', true);
        }
        config(['queue.connections.' . Queue::CONNECTION => $queue]);
        Cache::forget(Queue::OPERATOR_HEARTBEAT);
        prepareBusiness();
        $grant = \App\Models\MachineFailoverHandoverGrant::query()->sole();
        $source = Task::query()->sole();
        $legacyMachine = \App\Models\ServerMachine::query()->create([
            'name' => 'fixture legacy independent machine', 'token' => str_repeat('e', 32), 'is_active' => true,
        ]);
        $legacyAccount = \App\Models\MachineCloudAccount::query()->create([
            'name' => 'fixture legacy independent account', 'provider' => 'digitalocean',
            'credentials' => ['api_token' => 'synthetic-receipt-only'], 'revision' => 1,
        ]);
        $legacy = $source->replicate();
        $legacy->fill(['id' => (string) Str::uuid(), 'machine_id' => $legacyMachine->id, 'machine_authority' => hash('sha256', $legacyMachine->token),
            'account_id' => $legacyAccount->id, 'account_revision' => $legacyAccount->revision,
            'request_key' => hash('sha256', $owner), 'marker' => 'fixture-legacy-' . $owner,
            'installation_id' => str_repeat('2', 32), 'callback_hash' => hash('sha256', str_repeat('4', 64)),
            // Keep the existing legacy receipt admissible through the real 300-second killed-worker lock and final readback.
            'status' => 'waiting_initialization', 'dispatched_at' => time(), 'deadline_at' => time() + 900])->save();
        Fixture::control('grant', $grant->id, true);
        Fixture::control('legacy', $legacy->id, true);
        Fixture::control('service', $service['id'], true);
    } else {
        Fixture::attach($owner, $action); Fixture::assertOwner();
        if ($action === 'worker') {
            processProof('worker_process');
            operatorConfig();
            useRestrictedRedis();
            app('events')->listen(\Illuminate\Queue\Events\JobFailed::class, function ($e): void {
                Fixture::event('shared_horizon_failure_listener');
                app('queue.failer')->log($e->connectionName,$e->job->getQueue(),$e->job->getRawBody(),$e->exception);
            });
            exit(runConsole('worker'));
        }
        if ($action === 'timer') {
            processProof('timer_process');
            operatorConfig();
            if ($case === 'timeout') { sleep(100); exit(3); }
            exit(runConsole('timer'));
        }
        if ($action === 'business') exit(runConsole('business'));
        if ($action === 'launch') {
            operatorConfig(); MachineFailoverOperatorPermit::requireConsumer();
            $grant = \App\Models\MachineFailoverHandoverGrant::query()->sole();
            $plans = Plan::query()->latest('id')->limit(1)->pluck('id')->all();
            $scope = fn () => app(MachineFailoverNativeBridge::class)->start($grant->service_id, $grant->service_revision,
                $plans, $grant->release['id'], $owner, $grant->admin_id, handoverGrantId: $grant->id);
            MachineFailoverOperatorPermit::start($grant->id, $grant->service_id, $grant->service_revision, $grant->release['id'], $grant->admin_id, $plans, $scope);
        } elseif ($action === 'produce') {
            $date = strtotime(gmdate('Y-m-d'));
            StatServerJob::dispatch(['id' => 777], [[5, 7]], 'hysteria', recordAt: $date);
            $payload = json_encode(['version' => 1, 'node_id' => 778, 'node_type' => 'hysteria', 'node_name' => 'fixture',
                'rate_cents' => 100, 'record_at' => $date, 'traffic' => [9000 => [11, 13]]], JSON_THROW_ON_ERROR);
            $id = DB::table('v2_traffic_batch')->insertGetId(['node_id' => 778, 'node_type' => 'hysteria', 'report_id' => bin2hex(random_bytes(16)),
                'content_hash' => hash('sha256', $payload), 'prepared_hash' => hash('sha256', $payload), 'payload' => $payload, 'created_at' => time()]);
            TrafficBatchApplyJob::dispatch($id);
            $request = \Illuminate\Http\Request::create('/fixture/provision/result', 'POST', server: ['CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('4', 64)], content: json_encode(['task_id' => Fixture::control('legacy'),
                'installation_id' => str_repeat('2', 32), 'state' => 'succeeded'], JSON_THROW_ON_ERROR));
            $response = app(MachineProvisionController::class)->result($request, app(MachineProvisionService::class));
            requireFixture($response->getStatusCode() === 200 && $response->getData(true)['data']['accepted'] === true, 'legacy_callback_rejected');
        }
        elseif ($action === 'revoke' || $action === 'read-close') {
            requireFixture($case === 'recovery' && getenv('GITHUB_ACTIONS') === 'true', 'fixture_only');
            operatorConfig();
            config(['machine_failover.operator_enabled' => false]);
            $control = app(MachineFailoverOperatorControl::class);
            $policy = Fixture::control('operator-control-policy');
            $grant = ['grant_id' => Fixture::control('grant')];
            if ($action === 'revoke') {
                $receipt = $control->execute($policy, 'revoke', $grant);
                requireFixture($receipt['revoked'] && $receipt['settlement']['queued'] === 0, 'fixture_failed_to_revoke');
                Fixture::event('grant_revoked');
            } else {
                $attempt = Attempt::query()->sole();
                $task = Task::query()->findOrFail($attempt->task_id);
                requireFixture($task->external_id !== null && $task->status === 'needs_review', 'provider_ack_not_recovered');
                $before = $task->getRawOriginal();
                $observed = $control->execute($policy, 'read-cloud', $grant + ['attempt_id' => $attempt->id]);
                requireFixture($observed['result'] === 'matched_original', 'original_provider_identity_not_reconciled');
                $scope = $control->execute($policy, 'closure-scope', $grant)['scope'];
                requireFixture(array_column($scope['operations'], 'kind') === ['cloud_create'], 'unexpected_operation_journal');
                $private = openssl_pkey_get_private(Fixture::control('fixture-closure-key'));
                requireFixture($private !== false, 'fixture_key_unavailable');
                (new ReflectionProperty(HandoverEvidenceFixture::class, 'keys'))->setValue(null, [MachineFailoverHandoverEvidence::CLOSE => $private]);
                $outcome = HandoverEvidenceFixture::close($scope)['body']['outcomes'][0];
                $outcome['evidence_sha256'] = $observed['observation_sha256'];
                $closed = $control->execute($policy, 'close', $grant + ['proof' => HandoverEvidenceFixture::close($scope, ['outcomes' => [$outcome]])]);
                requireFixture($closed['released'] && $before === $task->fresh()->getRawOriginal(), 'closure_history_changed');
                Fixture::event('grant_closed');
            }
        }
        elseif ($action === 'stop') {
            operatorConfig();
            $grant = \App\Models\MachineFailoverHandoverGrant::query()->sole();
            app(\App\Services\ServerMachine\Cloud\MachineFailoverHandover::class)->revoke($grant->id, $grant->admin_id);
            app(MachineFailoverRunner::class)->advanceOperator();
            app(MachineFailoverRunner::class)->advance();
        } elseif ($action === 'cleanup') {
            aclAdmin(false);
            $fixture = new Fixture();
            (new ReflectionProperty(Fixture::class, 'owned'))->setValue($fixture, true);
            $fixture->cleanup();
        } elseif ($action !== 'status') throw new LogicException('Unexpected fixture action');
    }
    if ($action === 'status' || $action === 'stop') {
        $queue = app('queue')->connection('redis');
        $events = DB::table('native_fixture_event');
        $user = DB::table('v2_user')->find(9000);
        $attempt = Attempt::query()->first();
        $candidate = $attempt?->task_id ? Task::query()->find($attempt->task_id) : null;
        echo json_encode(['ready' => Cache::get(Queue::OPERATOR_HEARTBEAT) !== null,
            'preflight_barrier' => (clone $events)->where('kind','barrier')->where('reference','preflight')->count(),
            'acl_checks' => (int) (clone $events)->where('kind','restricted_acl')->latest('id')->value('reference'),
            'unexpected_acl' => aclDiagnostics(),
            'shared_listener' => (clone $events)->where('kind','shared_horizon_failure_listener')->count(),
            'shared_failed_unchanged' => DB::table('failed_jobs')->count() === 1 && DB::table('failed_jobs')->value('payload') === 'existing_fixture',
            'task_signature' => $candidate ? hash('sha256',$attempt->id.':'.$candidate->id.':'.$candidate->marker.':'.$candidate->dispatched_at) : null,
            'provider_known' => $candidate?->external_id !== null,'candidate_attempts' => Attempt::count(),
            'member_lock_ttl' => $attempt ? fixtureRedis()->ttl(Fixture::prefix().'cache:machine-native:member:'.$attempt->execution_member_id) : null,
            'recovered_events' => DB::table('v2_machine_failover_runtime_event')->where('kind','recovered')->count(),
            'creates_and_dns' => (clone $events)->whereIn('kind', ['create', 'dns_write'])->count(), 'run' => Execution::query()->value('status'),
            'stat' => (int) DB::table('v2_stat_server')->where('server_id', 777)->value('u'), 'stat_receipts' => DB::table('v2_stat_job_receipt')->count(),
            'traffic_u' => (int) $user->u, 'traffic_d' => (int) $user->d, 'traffic_receipts' => DB::table('v2_traffic_batch')->whereNotNull('sync_finished_at')->count(),
            'legacy' => Task::query()->findOrFail(Fixture::control('legacy'))->bootstrap_state,
            'legacy_steps' => DB::table('v2_machine_provision_step')->where('task_id', Fixture::control('legacy'))->where('state', 'bootstrap_succeeded')->count(),
            'queue_stat' => $queue->size('stat'), 'queue_traffic' => $queue->size('traffic_fetch'),
            'legacy_admin' => $queue->size('admin_operations'), 'legacy_failover' => $queue->size(Queue::NAME),
            'worker_process' => json_decode((clone $events)->where('kind', 'worker_process')->latest('id')->value('reference') ?? 'null', true),
            'timer_process' => json_decode((clone $events)->where('kind', 'timer_process')->latest('id')->value('reference') ?? 'null', true),
            'timer_ticks' => (clone $events)->where('kind', 'timer_process')->count(),
            'provider_barrier' => (clone $events)->where('kind', 'barrier')->where('reference', 'create_after')->count(),
            'stop_evidence' => (clone $events)->where('kind', 'grant_revoked')->count(),
            'closed' => (clone $events)->where('kind', 'grant_closed')->count(),
            'runtime_state' => Runtime::query()->value('state'), 'runtime_reason' => Runtime::query()->value('reason'),
            'member_phase' => \App\Models\MachineFailoverExecutionMember::query()->value('phase'),
            'member_reason' => \App\Models\MachineFailoverExecutionMember::query()->value('reason'),
            'worker_error' => (clone $events)->where('kind', 'worker_error')->latest('id')->value('reference'),
            'operator_generation' => Runtime::query()->value('generation')], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture failed: ' . Fixture::errorCode($error) . ' [' . (preg_match('/^[a-z_]{1,64}$/D', $error->getMessage()) ? $error->getMessage() : 'details withheld') . ']' . PHP_EOL);
    exit(2);
}
