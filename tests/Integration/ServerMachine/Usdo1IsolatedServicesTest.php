<?php

declare(strict_types=1);

namespace Tests\Integration\ServerMachine;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineProvisionTask as Task;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;
use Tests\Unit\Services\MachineFailoverNativeBridgeTest;

final class Usdo1IsolatedServicesTest extends MachineFailoverNativeBridgeTest
{
    private array $children = [];
    private bool $ownsFixture = false;

    protected function setUp(): void
    {
        if (getenv('KELI_USDO1_ACCEPTANCE') !== '1') $this->markTestSkipped('Requires disposable service 123 CI.');
        Fixture::$owner = bin2hex(random_bytes(16));
        Fixture::$worker = 'usdo1-parent';
        putenv('KELI_USDO1_FIXTURE_OWNER=' . Fixture::$owner);
        User::withoutTimestamps(fn () => parent::setUp());
    }

    protected function setUpInMemoryDatabase(): void
    {
        Fixture::connect(true);
        $this->ownsFixture = true;
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_keys($this->children) as $pid) $this->finish($pid, true);
            if ($this->ownsFixture) {
                Fixture::cleanupOwned();
                self::assertSame([], DB::select('SHOW TABLES'));
            }
        } finally {
            putenv('KELI_USDO1_FIXTURE_OWNER');
            parent::tearDown();
        }
    }

    public function test_usdo1_isolated_process_crash_recovers_original_create_and_fences_http_callback(): void
    {
        $plan = $this->prepareUsdo1Package('success');
        $id = $plan['run']['execution_id'];
        $scope = array_intersect_key($plan, array_flip(['admin','release','source','baseline','grant']));
        // Transport bootstrap accepts just these fields, in a stable order.
        $scope = array_replace(array_fill_keys(['admin','release','source','baseline','grant'], null), $scope);
        Fixture::control('usdo1-test-scope', $scope, true);
        Fixture::control('fault', 'create_after', true);
        $first = $this->spawn();
        $this->waitFor(fn () => (int) app('redis')->connection()->get('fixture-barrier:create_after') === 1, 'create acknowledgement barrier');
        $contender = $this->spawn();
        $this->finish($contender);
        self::assertSame(1, $this->events('usdo1_lock_busy'));
        self::assertSame(1, $this->events('create'));
        $task = Task::query()->findOrFail(Attempt::query()->sole()->task_id);
        self::assertNotNull($task->dispatched_at);
        self::assertNull($task->external_id);
        self::assertSame('processing', Runtime::query()->findOrFail($id)->state);
        $this->finish($first, true);
        self::assertSame(1, $this->events('create'));

        Fixture::control('fault', null, true);
        // Force only this fixture's dead lease to expire; no wall-clock/production lease is changed.
        (new Fixture())->expire($id);
        app('redis')->connection()->pexpire('cache:usdo1-isolated-writer:9123', 1);
        $this->waitFor(fn () => !app('redis')->connection()->exists('cache:usdo1-isolated-writer:9123'), 'expired writer lock');
        $this->finish($this->spawn());
        self::assertSame(1, $this->events('create'));
        self::assertGreaterThan(0, $this->events('provider_readback'));
        self::assertSame(0, $this->events('usdo1_worker_error'));
        self::assertSame(2, Runtime::query()->findOrFail($id)->generation);
        $task->refresh();
        self::assertSame('waiting_initialization', $task->status);
        self::assertNotNull($task->external_id);
        self::assertSame($task->id, Attempt::query()->sole()->task_id);

        [$server,$port] = $this->serve();
        $secret = $this->usdo1FixtureSecret($task);
        $valid = ['task_id'=>$task->id, 'installation_id'=>$task->installation_id, 'state'=>'succeeded'];
        self::assertSame(401, $this->sendFixtureCallback($port, $valid, str_repeat('0', 64)));
        self::assertSame(401, $this->sendFixtureCallback($port, array_replace($valid, ['installation_id' => str_repeat('f', 32)]), $secret));
        self::assertSame(401, $this->sendFixtureCallback($port, array_replace($valid, ['task_id' => '00000000-0000-4000-8000-000000000001']), $secret));
        self::assertNull($task->fresh()->bootstrap_state);
        self::assertSame(200, $this->sendFixtureCallback($port, $valid, $secret));
        self::assertSame('succeeded', $task->fresh()->bootstrap_state);
        $this->finish($server, true);
        [$server,$port] = $this->serve();
        self::assertSame(200, $this->sendFixtureCallback($port, $valid, $secret));
        self::assertSame(1, DB::table('v2_machine_provision_step')->where('task_id', $task->id)->where('state', 'bootstrap_succeeded')->count());
        self::assertSame('stopped', $plan['package']->stop($plan['grant'])['status']);
        self::assertSame(401, $this->sendFixtureCallback($port, $valid, $secret));
        $this->finish($server, true);
        self::assertSame(0, $this->events('dns_write'));
        self::assertSame(1, $this->events('create'));
        $redis = app('redis')->connection();
        foreach (['admin_operations',MachineFailoverQueue::NAME] as $name) {
            self::assertSame(0, (int) $redis->llen('queues:' . $name));
            self::assertSame(0, (int) $redis->zcard('queues:' . $name . ':delayed'));
            self::assertSame(0, (int) $redis->zcard('queues:' . $name . ':reserved'));
        }
    }

    private function events(string $kind): int { return DB::table('native_fixture_event')->where('kind', $kind)->count(); }

    public function test_usdo1_real_linux_launch_assembly(): void
    {
        ['package'=>$package,'grant'=>$grant,'admin'=>$admin] = $this->prepareUsdo1Package('success');
        $launch = new \App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope($grant, $admin, 'https://receipt.example.test');
        $package->pulse($grant, $launch);
        $task = Task::query()->findOrFail(Attempt::query()->sole()->task_id);
        self::assertSame($launch->origin, $task->payload['usdo1_callback_origin']);
        $scope = ['grant'=>$grant,'admin'=>$admin,'owner'=>Fixture::$owner,'task_id'=>$task->id,
            'installation_id'=>$task->installation_id,'secret'=>$this->usdo1FixtureSecret($task),'state'=>'succeeded'];
        $call = static function (string $phase) use ($scope): array {
            $process = proc_open(['python3','.github/ci/usdo1-launch-stack.py',$phase],
                [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__, 3));
            self::assertIsResource($process);
            fwrite($pipes[0], json_encode($scope, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
            $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), preg_match('/\Afixture_error:[a-zA-Z0-9_: .-]{0,240}\s*\z/D', $error)
                ? trim($error) : 'native_launch_fixture_failed');
            return json_decode($out, true, 8, JSON_THROW_ON_ERROR);
        };
        self::assertNull($task->fresh()->bootstrap_state);
        $proof = $call('start');
        foreach (['https_accepted','tls_hostname_and_ca_checked','fpm_unix_only','wrong_id_or_secret_rejected',
            'non_root_proxy_and_fpm','unrelated_uid_denied'] as $field) self::assertTrue($proof[$field]);
        self::assertSame('succeeded', $task->fresh()->bootstrap_state);
        self::assertSame(1, DB::table('v2_machine_provision_step')->where('task_id',$task->id)->where('state','bootstrap_succeeded')->count());
        self::assertSame('stopped', $package->stop($grant)['status']);
        $proof += $call('revoked');
        self::assertTrue($proof['revoked_rejected']);
        self::assertCount(1, $this->posts);
        foreach (['admin_operations',MachineFailoverQueue::NAME] as $name) {
            self::assertSame(0, (int) app('redis')->connection()->llen('queues:' . $name));
        }
        file_put_contents(base_path('artifacts/usdo1-launch-summary.json'), json_encode($proof, JSON_THROW_ON_ERROR));
    }

    private function spawn(?int $port = null): int
    {
        $argv = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0'];
        if ($port !== null) array_push($argv, '-S', '127.0.0.1:' . $port);
        $argv[] = dirname(__DIR__, 2) . '/Fixtures/usdo1_isolated_worker.php';
        $env = getenv(); unset($env['PHP_CLI_SERVER_WORKERS']);
        $process = proc_open($argv, [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes, dirname(__DIR__, 3), $env);
        self::assertIsResource($process); fclose($pipes[0]);
        $pid = proc_get_status($process)['pid']; $this->children[$pid] = $process;
        return $pid;
    }

    private function finish(int $pid, bool $kill = false): void
    {
        $process = $this->children[$pid]; $status = proc_get_status($process);
        if ($kill && $status['running']) proc_terminate($process, 9);
        $until = microtime(true) + ($kill ? 4 : 18);
        while ($status['running'] && microtime(true) < $until) {
            usleep(20000); $status = proc_get_status($process);
        }
        if ($status['running']) proc_terminate($process, 9);
        $exitCode = proc_close($process); unset($this->children[$pid]);
        self::assertFalse($status['running'], 'Fixture child exceeded its bounded lifetime.');
        if (!$kill) self::assertTrue($status['exitcode'] === 0 || $exitCode === 0,
            'Worker failed: ' . implode(',', DB::table('native_fixture_event')->where('kind', 'usdo1_worker_error')->pluck('reference')->all()));
    }

    private function waitFor(callable $condition, string $label): void
    {
        $until = microtime(true) + 12;
        do { if ($condition()) return; usleep(25000); } while (microtime(true) < $until);
        self::fail('Timed out: ' . $label . '; ' . implode(',', DB::table('native_fixture_event')->where('kind', 'usdo1_worker_error')->pluck('reference')->all()));
    }

    private function serve(): array
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $message);
        self::assertIsResource($reservation);
        $port = (int) substr(strrchr(stream_socket_get_name($reservation, false), ':'), 1);
        fclose($reservation);
        $pid = $this->spawn($port);
        $this->waitFor(static function () use ($port): bool {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $message, 0.2);
            if (!$socket) return false;
            fclose($socket); return true;
        }, 'HTTP fixture');
        return [$pid,$port];
    }

    private function sendFixtureCallback(int $port, array $data, string $secret): int
    {
        $context = stream_context_create(['http'=>['method'=>'POST','timeout'=>6,'ignore_errors'=>true,'follow_location'=>0,
            'header'=>"Content-Type: application/json\r\nAuthorization: Bearer $secret\r\n", 'content'=>json_encode($data, JSON_THROW_ON_ERROR)]]);
        $body = file_get_contents('http://127.0.0.1:' . $port . '/fixture/usdo1/callback', false, $context);
        self::assertIsString($body);
        self::assertStringNotContainsString($secret, $body);
        self::assertSame(1, preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $match));
        return (int) $match[1];
    }
}
