<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Console\Commands\WorkMachineFailoverOperator;
use App\Http\Controllers\V2\Server\MachineProvisionController;
use App\Jobs\MachineFailoverOperatorRunJob;
use App\Jobs\StatServerJob;
use App\Jobs\TrafficBatchApplyJob;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
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
use Tests\Unit\Services\MachineFailoverNativeBridgeTest;

function requireFixture(bool $condition, string $reason): void {
    if (!$condition) throw new RuntimeException($reason);
}
function operatorConfig(): void {
    config(['machine_failover.operator_enabled' => true, 'machine_failover.runner_enabled' => false,
        'machine_failover.allow_replacement' => false, 'machine_failover.allow_rotation' => false, 'machine_provision.allow_live' => false,
        'machine_acceptance.allow_readiness' => false, 'machine_acceptance.allow_verification' => false, 'machine_acceptance.allow_dns' => false]);
    app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
}
function prepareBusiness(): void {
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
function runConsole(bool $operator): int {
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
    return $cli->run(new \Symfony\Component\Console\Input\ArrayInput($operator
        ? ['command' => 'machines:work-operator-failover']
        : ['command' => 'queue:work', 'connection' => 'redis', '--queue' => 'stat,traffic_fetch', '--sleep' => 1, '--max-time' => 180, '--tries' => 1]),
        new \Symfony\Component\Console\Output\NullOutput());
}

try {
    Fixture::configuration(getenv());
    [$self, $owner, $action, $case] = $argv;
    requireFixture(PHP_OS_FAMILY === 'Linux' && posix_geteuid() !== 0 && getenv('GITHUB_WORKFLOW') === 'Manual failover isolated process limits'
        && preg_match('/^[a-f0-9]{32}$/D', $owner) === 1 && in_array($case, ['kill', 'timeout', 'limits'], true), 'fixture_only');
    if ($action === 'seed') {
        Fixture::$owner = $owner; Fixture::$worker = 'seed';
        $seed = new class('test_operator_ticket_is_bound_to_one_run_while_the_legacy_switches_stay_closed') extends MachineFailoverNativeBridgeTest {
            protected function setUpInMemoryDatabase(): void { Fixture::connect(true); }
            public function bootFixture(): void { User::withoutTimestamps(fn () => parent::setUp()); }
        };
        $seed->bootFixture();
        $queue = config('queue.connections.' . Queue::CONNECTION);
        $service = (new ReflectionMethod(MachineFailoverNativeBridgeTest::class, 'operatorService'))->invoke($seed);
        config(['queue.connections.' . Queue::CONNECTION => $queue]);
        Cache::forget(Queue::OPERATOR_HEARTBEAT);
        prepareBusiness();
        $grant = \App\Models\MachineFailoverHandoverGrant::query()->sole();
        $source = Task::query()->sole();
        $legacy = $source->replicate();
        $legacy->fill(['id' => (string) Str::uuid(), 'request_key' => hash('sha256', $owner), 'marker' => 'fixture-legacy-' . $owner,
            'installation_id' => str_repeat('2', 32), 'callback_hash' => hash('sha256', str_repeat('4', 64)),
            'status' => 'waiting_initialization', 'dispatched_at' => time(), 'deadline_at' => time() + 240])->save();
        Fixture::control('grant', $grant->id, true);
        Fixture::control('legacy', $legacy->id, true);
        Fixture::control('service', $service['id'], true);
    } else {
        Fixture::attach($owner, $action); Fixture::assertOwner();
        if ($action === 'worker') {
            operatorConfig();
            app('events')->listen(\Illuminate\Queue\Events\JobTimedOut::class, fn () => Fixture::event('operator_timed_out'));
            app('events')->listen(\Illuminate\Queue\Events\JobProcessing::class, function ($event) use ($case) {
                requireFixture($event->connectionName === Queue::CONNECTION && $event->job->getQueue() === Queue::OPERATOR_NAME
                    && $event->job->resolveName() === MachineFailoverOperatorRunJob::class, 'manual_queue_crossed');
                Fixture::event('operator_enter', (string) getmypid());
                if ($case !== 'limits') { sleep(110); return; }
                $until = microtime(true) + 5;
                while (microtime(true) < $until) hash('sha256', str_repeat('c', 8192));
                Fixture::event('cpu_ceiling_exercised');
                while (Fixture::control('allocate') !== true && microtime(true) < $until + 35) usleep(100000);
                $allocated = [];
                for ($i = 0; $i < 40; $i++) $allocated[] = str_repeat(chr(65 + ($i % 26)), 8 * 1024 * 1024);
                throw new RuntimeException('Kernel memory ceiling failed to stop allocation');
            });
            exit(runConsole(true));
        }
        if ($action === 'business') exit(runConsole(false));
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
        } elseif ($action === 'allocate') Fixture::control('allocate', true, true);
        elseif ($action === 'stop') {
            operatorConfig();
            $grant = \App\Models\MachineFailoverHandoverGrant::query()->sole();
            app(\App\Services\ServerMachine\Cloud\MachineFailoverHandover::class)->revoke($grant->id, $grant->admin_id);
            app(MachineFailoverRunner::class)->advanceOperator();
            app(MachineFailoverRunner::class)->advance();
        } elseif ($action === 'cleanup') {
            $fixture = new Fixture();
            (new ReflectionProperty(Fixture::class, 'owned'))->setValue($fixture, true);
            $fixture->cleanup();
        } elseif ($action !== 'status') throw new LogicException('Unexpected fixture action');
    }
    if ($action === 'status' || $action === 'stop') {
        $queue = app('queue')->connection('redis');
        $events = DB::table('native_fixture_event');
        $user = DB::table('v2_user')->find(9000);
        echo json_encode(['ready' => Cache::get(Queue::OPERATOR_HEARTBEAT) !== null, 'entered' => (int) (clone $events)->where('kind', 'operator_enter')->value('reference'),
            'timeouts' => (clone $events)->where('kind', 'operator_timed_out')->count(), 'cpu' => (clone $events)->where('kind', 'cpu_ceiling_exercised')->count(),
            'creates_and_dns' => (clone $events)->whereIn('kind', ['create', 'dns_write'])->count(), 'run' => Execution::query()->value('status'),
            'stat' => (int) DB::table('v2_stat_server')->where('server_id', 777)->value('u'), 'stat_receipts' => DB::table('v2_stat_job_receipt')->count(),
            'traffic_u' => (int) $user->u, 'traffic_d' => (int) $user->d, 'traffic_receipts' => DB::table('v2_traffic_batch')->whereNotNull('sync_finished_at')->count(),
            'legacy' => Task::query()->findOrFail(Fixture::control('legacy'))->bootstrap_state,
            'legacy_steps' => DB::table('v2_machine_provision_step')->where('task_id', Fixture::control('legacy'))->where('state', 'bootstrap_succeeded')->count(),
            'queue_stat' => $queue->size('stat'), 'queue_traffic' => $queue->size('traffic_fetch'),
            'legacy_admin' => $queue->size('admin_operations'), 'legacy_failover' => $queue->size(Queue::NAME),
            'operator_generation' => Runtime::query()->value('generation')], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture failed: ' . Fixture::errorCode($error) . ' [' . (preg_match('/^[a-z_]{1,64}$/D', $error->getMessage()) ? $error->getMessage() : 'details withheld') . ']' . PHP_EOL);
    exit(2);
}
