<?php
declare(strict_types=1);

namespace Tests\Integration\ServerMachine;

use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

final class MemberHttpServicesTest extends TestCase
{
    private Fixture $fixture;
    private Task $a;
    private Task $b;
    private string $secret;
    private mixed $server = null;
    private array $pipes = [];
    private int $port;

    protected function setUp(): void
    {
        $this->fixture = new Fixture();
        $this->fixture->seed(2);
        $tasks = Task::query()->orderBy('machine_id')->get();
        $this->a = $tasks[0]; $this->b = $tasks[1];
        $this->secret = bin2hex(random_bytes(32));
        foreach ([$this->a, $this->b] as $task) $task->fill(['status' => 'bootstrapping', 'bootstrap_state' => null,
            'bootstrap_at' => null, 'callback_hash' => hash('sha256', $this->secret),
            'dispatched_at' => time(), 'deadline_at' => time() + 120])->save();
        $this->start();
    }

    private function start(bool $disabled = false): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $env = getenv(); $env['KELI_MEMBER_HTTP_FIXTURE'] = '1'; $env['KELI_MEMBER_OWNER'] = Fixture::$owner;
        $env['KELI_MEMBER_CALLBACK_DISABLED'] = $disabled ? '1' : '0';
        unset($env['PHP_CLI_SERVER_WORKERS']);
        $this->server = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-S', '127.0.0.1:' . $this->port, dirname(__DIR__, 2) . '/Fixtures/member_http_callback.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $this->pipes, dirname(__DIR__, 3), $env);
        $this->assertIsResource($this->server); fclose($this->pipes[0]);
        $until = microtime(true) + 10;
        do {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.2);
            if ($socket) { fclose($socket); return; }
            usleep(50000);
        } while (microtime(true) < $until && proc_get_status($this->server)['running']);
        $this->fail('Isolated loopback HTTP process did not start.');
    }

    private function stop(): void
    {
        if (!is_resource($this->server)) return;
        proc_terminate($this->server); $until = microtime(true) + 3;
        while (proc_get_status($this->server)['running'] && microtime(true) < $until) usleep(20000);
        if (proc_get_status($this->server)['running']) proc_terminate($this->server, 9);
        proc_close($this->server); $this->server = null;
    }

    protected function tearDown(): void
    {
        $this->stop();
        if (isset($this->fixture)) $this->fixture->cleanup();
    }

    private function request(array $changes = [], ?string $secret = null, ?string $raw = null): array
    {
        $data = array_replace(['task_id' => $this->a->id, 'installation_id' => $this->a->installation_id,
            'state' => 'succeeded'], $changes);
        $context = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 6, 'ignore_errors' => true,
            'follow_location' => 0, 'header' => "Content-Type: application/json\r\nAuthorization: Bearer " . ($secret ?? $this->secret),
            'content' => $raw ?? json_encode($data, JSON_THROW_ON_ERROR)]]);
        $body = file_get_contents('http://127.0.0.1:' . $this->port . '/api/v2/server/machine/provision/result', false, $context);
        $this->assertIsString($body);
        preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $match);
        $this->assertStringNotContainsString($this->secret, $body);
        return [(int) $match[1], json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
    }

    public function test_real_http_callback_persists_in_mysql_survives_restart_and_keeps_b_unchanged(): void
    {
        $b = $this->b->fresh()->getAttributes();
        $this->assertSame(200, $this->request()[0]);
        $this->assertSame('succeeded', $this->a->fresh()->bootstrap_state);
        $this->stop(); $this->start();
        $this->assertSame(200, $this->request()[0]);
        $this->assertSame(1, DB::table('v2_machine_provision_step')->where('task_id', $this->a->id)
            ->where('state', 'bootstrap_succeeded')->count());
        $this->assertSame($b, $this->b->fresh()->getAttributes());
        $this->assertSame(0, DB::table('native_fixture_event')->whereIn('kind', ['create', 'dns_write'])->count());
        $this->assertSame(0, (int) app('redis')->connection()->llen('queues:admin_operations'));
    }

    public function test_wrong_identity_token_oversize_and_expiry_are_rejected_over_http(): void
    {
        $this->assertSame(401, $this->request([], str_repeat('0', 64))[0]);
        $this->assertSame(401, $this->request(['installation_id' => $this->b->installation_id])[0]);
        $this->assertSame(401, $this->request(['unexpected' => true])[0]);
        $this->assertSame(401, $this->request([], null, str_repeat(' ', 2050))[0]);
        $this->a->fill(['deadline_at' => time() - 1])->save();
        $this->assertSame(401, $this->request()[0]);
        $this->assertNull($this->a->fresh()->bootstrap_state);
        $this->assertSame(0, DB::table('v2_machine_provision_step')->where('task_id', $this->a->id)->count());
    }

    public function test_real_redis_account_lock_blocks_callback_then_releases(): void
    {
        $lock = Cache::lock('machine-cloud:account:' . $this->a->account_id, 15);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame(503, $this->request()[0]);
            $this->assertNull($this->a->fresh()->bootstrap_state);
        } finally { $lock->release(); }
        $this->assertSame(200, $this->request()[0]);
    }

    public function test_conflicting_callback_and_disabled_gate_do_not_overwrite_success(): void
    {
        $this->assertSame(200, $this->request()[0]);
        $this->assertSame(409, $this->request(['state' => 'failed'])[0]);
        $this->stop(); $this->start(true);
        $this->assertSame(401, $this->request()[0]);
        $this->assertSame('succeeded', $this->a->fresh()->bootstrap_state);
    }

    public function test_mysql_transaction_rolls_back_when_receipt_step_cannot_be_written(): void
    {
        // Keep the table available but reject the insert after the task update.
        // A column rename needs no global privileges or changes to server security.
        DB::statement('ALTER TABLE v2_machine_provision_step RENAME COLUMN state TO fixture_saved_state');
        try {
            $this->assertSame(500, $this->request()[0]);
            $this->assertNull($this->a->fresh()->bootstrap_state);
        } finally { DB::statement('ALTER TABLE v2_machine_provision_step RENAME COLUMN fixture_saved_state TO state'); }
        $this->assertSame(200, $this->request()[0]);
    }
}
