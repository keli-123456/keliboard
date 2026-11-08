<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Console\Commands\PreviewMachineFailover;
use App\Console\Kernel;
use App\Models\MachineFailoverCostSnapshot;
use App\Models\MachineFailoverService;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Plugin\PluginManager;
use App\Services\ServerMachine\Cloud\MachineFailoverBudget;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use Illuminate\Console\Application as Console;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Unit\Services\MachineFailoverShadowTest;

final class TriggerPreviewFixture
{
    public const WORKFLOW = 'Failover Trigger Preview Isolated Acceptance';
    private ?MachineFailoverShadowTest $seed = null;
    private string $neighbor;
    public array $scope;

    public static function configuration(array $env, string $owner): array
    {
        $configuration = NativeFailoverConcurrentFixture::configuration($env);
        if (($env['KELI_TRIGGER_PREVIEW_ACCEPTANCE'] ?? '') !== '1' || ($env['GITHUB_WORKFLOW'] ?? '') !== self::WORKFLOW
            || !preg_match('/^[a-f0-9]{32}$/D', $owner)) throw new \RuntimeException('Dedicated disposable trigger preview workflow required.');
        return $configuration;
    }

    public function seed(): void
    {
        $owner = bin2hex(random_bytes(16)); self::configuration(getenv(), $owner);
        NativeFailoverConcurrentFixture::$owner = $owner; NativeFailoverConcurrentFixture::$worker = 'parent';
        $this->seed = new class('test_reading_fresh_reports_does_not_collect_samples_or_enable_execution') extends MachineFailoverShadowTest {
            protected function setUpInMemoryDatabase(): void { NativeFailoverConcurrentFixture::connect(true); }
            public function bootFixture(): array { User::withoutTimestamps(fn () => parent::setUp()); return ['id' => $this->service['id'], 'admin' => $this->admin->id]; }
            public function reportAt(int $time, bool $healthy): void {
                $this->clock = $time; Carbon::setTestNow(Carbon::createFromTimestampUTC($time));
                try { foreach ([0, 1] as $i) $this->sample($i, $healthy); } finally { Carbon::setTestNow(); }
            }
        };
        try {
            $this->scope = $this->seed->bootFixture(); Carbon::setTestNow(); self::guards();
            foreach (['2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_07_190000_create_machine_failover_finance.php',
                '2026_11_08_000000_create_machine_failover_trigger_previews.php'] as $file) (require base_path('database/migrations/' . $file))->up();
            Schema::create('trigger_fixture_result', function (Blueprint $t) { $t->id(); $t->string('worker'); $t->string('kind'); $t->json('payload'); $t->unsignedInteger('at'); });
            NativeFailoverConcurrentFixture::control('scope', $this->scope, true);
            DB::table('v2_machine_failover_policy')->update(['created_at' => time() - 60]);
            config(['machine_failover.observe_enabled' => true]); $this->baseline();
            $now = time(); foreach ([$now - 16 => true, $now - 11 => false, $now - 6 => false] as $at => $healthy) $this->seed->reportAt($at, $healthy);
            $this->neighbor = 'trigger-neighbor:' . $owner . ':';
            $redis = self::raw(); $redis->rpush($this->neighbor . 'queues:admin_operations', 'one', 'two');
            $redis->set($this->neighbor . 'horizon:master', 'unchanged'); $redis->close();
        } catch (\Throwable $error) {
            if (app()->bound('db') && Schema::hasTable('native_fixture_owner')) $this->cleanup();
            throw new \RuntimeException('Trigger fixture seed failed: ' . NativeFailoverConcurrentFixture::errorCode($error));
        }
    }

    public static function attach(string $owner, string $worker): void
    {
        self::configuration(getenv(), $owner); NativeFailoverConcurrentFixture::$owner = $owner; NativeFailoverConcurrentFixture::$worker = $worker;
        $bootstrap = new class('bootFixture') extends \Tests\TestCase {
            use InteractsWithInMemoryDatabase;
            public function bootFixture(): void {
                parent::setUp(); NativeFailoverConcurrentFixture::connect(false); $this->bindJsonResponseFactory();
                $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
                    'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
            }
        };
        $bootstrap->bootFixture(); Carbon::setTestNow();
        app()->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('h', 32), 'AES-256-CBC'));
        foreach (['machine_failover', 'machine_provision', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        $bus = new \Illuminate\Bus\Dispatcher(app()); app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $bus); Bus::fake();
        self::guards();
        $events = new \Illuminate\Events\Dispatcher(app()); app()->instance('events', $events);
        app()->instance(\Illuminate\Contracts\Events\Dispatcher::class, $events); DB::connection()->setEventDispatcher($events);
        DB::listen(function ($query) {
            $sql = strtolower(ltrim($query->sql));
            if (preg_match('/^(insert|update|delete|replace|alter|create|drop)\b/', $sql)
                && !preg_match('/^(?:insert into|update|delete from) [`"]?(?:v2_machine_failover_trigger_(?:preview|event)|native_fixture_event|trigger_fixture_result)[`" ]/', $sql)) {
                NativeFailoverConcurrentFixture::event('forbidden_sql'); throw new \RuntimeException('Non-audit SQL write forbidden.');
            }
            if (str_starts_with($sql, 'insert into `v2_machine_failover_trigger_event`')) NativeFailoverConcurrentFixture::barrier('audit_inserted');
        });
    }

    private static function guards(): void
    {
        foreach (['machine_failover.runner_enabled', 'machine_failover.allow_replacement', 'machine_provision.allow_live',
            'machine_acceptance.allow_verification', 'machine_acceptance.allow_dns'] as $key)
            if (config($key) !== false) throw new \RuntimeException('Real execution flags must remain closed.');
        if (get_class(app(MachineFailoverExecutionGate::class)) !== MachineFailoverExecutionGate::class) throw new \RuntimeException('Real execution gate required.');
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function () { NativeFailoverConcurrentFixture::event('forbidden_http'); throw new \RuntimeException('Cloud, DNS and verifier transport disabled.'); });
        app()->instance('env', 'trigger-preview-acceptance'); config(['app.env' => 'trigger-preview-acceptance']);
        app()->instance('log', new \Psr\Log\NullLogger());
    }

    public function baseline(int $monthlyCents = 2000): void
    {
        $service = MachineFailoverService::query()->findOrFail($this->scope['id']); $budget = app(MachineFailoverBudget::class);
        $graph = (new \ReflectionMethod(MachineFailoverBudget::class, 'graph'))->invoke($budget, $service); $items = [];
        foreach ($graph['entries'] as $entry) $items[$entry['task']->id] = ['state' => 'present', 'monthly_cents' => $monthlyCents,
            'identity' => $entry['identity'], 'reason' => null, 'absence_verified' => false];
        MachineFailoverCostSnapshot::query()->create(['id' => (string) \Illuminate\Support\Str::uuid(), 'service_id' => $service->id,
            'service_revision' => $service->revision, 'admin_id' => $this->scope['admin'], 'context_hash' => $graph['context'],
            'payload' => ['state' => 'complete', 'items' => $items], 'checked_at' => time(), 'valid_until' => time() + 600]);
    }
    public function sample(bool $healthy): void { $this->seed->reportAt(time(), $healthy); }

    public static function schedule(): Schedule
    {
        app()->instance(EventMutex::class, new CacheEventMutex(app('cache')));
        app()->instance(SchedulingMutex::class, new CacheSchedulingMutex(app('cache')));
        $backup = \Mockery::mock(BackupService::class); $backup->shouldReceive('settings')->andReturn(['enabled' => false]); app()->instance(BackupService::class, $backup);
        $plugins = \Mockery::mock(PluginManager::class); $plugins->shouldReceive('registerPluginSchedules')->andReturnNull(); app()->instance(PluginManager::class, $plugins);
        $events = app('events');
        $schedule = new Schedule(); $kernel = new Kernel(app(), $events);
        (new \ReflectionMethod(Kernel::class, 'schedule'))->invoke($kernel, $schedule);
        $owned = array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'machines:preview-failover')));
        if (count($owned) !== 1 || $owned[0]->expression !== '* * * * *' || !$owned[0]->onOneServer || !$owned[0]->withoutOverlapping || !$owned[0]->runInBackground)
            throw new \RuntimeException('Unexpected production preview schedule.');
        // Only this real event is runnable; all other production schedules are excluded before execution.
        (new \ReflectionProperty(Schedule::class, 'events'))->setValue($schedule, $owned);
        return $schedule;
    }

    public static function run(string $command, array $parameters = []): int
    {
        NativeFailoverConcurrentFixture::assertOwner(); config(['machine_failover.preview_enabled' => true, 'machine_failover.observe_enabled' => true]);
        $schedule = self::schedule(); app()->instance(Schedule::class, $schedule);
        app()->instance(\Illuminate\Contracts\Cache\Repository::class, app('cache.store'));
        app()->instance(\Illuminate\Contracts\Foundation\MaintenanceMode::class, new class implements \Illuminate\Contracts\Foundation\MaintenanceMode {
            public function activate(array $payload): void { throw new \RuntimeException('Maintenance mutation forbidden.'); }
            public function deactivate(): void { throw new \RuntimeException('Maintenance mutation forbidden.'); }
            public function active(): bool { return false; }
            public function data(): array { return []; }
        });
        app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class implements \Illuminate\Contracts\Debug\ExceptionHandler {
            public function report(\Throwable $e) { NativeFailoverConcurrentFixture::event('scheduler_error', NativeFailoverConcurrentFixture::errorCode($e)); }
            public function shouldReport(\Throwable $e) { return true; }
            public function render($request, \Throwable $e) { throw $e; }
            public function renderForConsole($output, \Throwable $e) { $this->report($e); }
        });
        $console = new Console(app(), app(\Illuminate\Contracts\Events\Dispatcher::class), 'trigger-fixture'); $console->setAutoExit(false);
        foreach ([new PreviewMachineFailover(), new ScheduleRunCommand(), new ScheduleFinishCommand()] as $handler) $console->add($handler);
        $output = new BufferedOutput(); $exit = $console->run(new ArrayInput(['command' => $command] + $parameters), $output);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
        if ($command === 'machines:preview-failover') {
            $result = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);
            DB::table('trigger_fixture_result')->insert(['worker' => NativeFailoverConcurrentFixture::$worker, 'kind' => 'collect', 'payload' => json_encode($result), 'at' => time()]);
        } else NativeFailoverConcurrentFixture::event($command === 'schedule:finish' ? 'schedule_finished' : 'scheduler_tick');
        return $exit;
    }

    public function cleanup(): array
    {
        NativeFailoverConcurrentFixture::assertOwner(); $redis = self::raw(); $prefix = NativeFailoverConcurrentFixture::prefix();
        if ($redis->get($prefix . 'owner') !== NativeFailoverConcurrentFixture::$owner) throw new \RuntimeException('Redis ownership mismatch.');
        $neighbor = isset($this->neighbor) && $redis->lrange($this->neighbor . 'queues:admin_operations', 0, -1) === ['one', 'two']
            && $redis->get($this->neighbor . 'horizon:master') === 'unchanged';
        if (isset($this->neighbor) && !$neighbor) throw new \RuntimeException('Neighbor queue/master sentinel changed.');
        foreach ($redis->keys($prefix . '*') as $key) $redis->del($key);
        if (isset($this->neighbor)) foreach ($redis->keys($this->neighbor . '*') as $key) $redis->del($key);
        $keysGone = $redis->keys($prefix . '*') === []; $redis->close();
        foreach (DB::select('SHOW TABLES') as $row) { $table = (string) array_values((array) $row)[0]; if ($table !== 'native_fixture_owner') Schema::drop($table); }
        Schema::drop('native_fixture_owner'); Carbon::setTestNow();
        return ['tables_removed' => DB::select('SHOW TABLES') === [], 'keys_removed' => $keysGone, 'neighbor_unchanged' => $neighbor];
    }
    private static function raw(): \Redis { $redis = new \Redis(); $redis->connect('127.0.0.1', (int) getenv('KELI_NATIVE_REDIS_PORT'), 2); return $redis; }
}
