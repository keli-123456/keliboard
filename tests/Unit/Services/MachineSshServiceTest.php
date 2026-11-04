<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Console\Commands\MachineSshSession;
use App\Http\Controllers\V2\Admin\Server\MachineSshController;
use App\Http\Middleware\RequestLog;
use App\Models\AdminAuditLog;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerMachine\InteractiveSshConnection;
use App\Services\ServerMachine\MachineSshBridge;
use App\Services\ServerMachine\MachineSshService;
use App\Services\ServerMachine\SshTarget;
use App\Services\ServerMachine\SshTerminalException;
use App\Services\ServerMachine\SshTransport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineSshServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineSshService $service;
    private User $admin;
    private PersonalAccessToken $access;
    private ServerMachine $machine;
    private array $target = ['host' => '8.8.8.8', 'port' => 22, 'username' => 'root'];

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
        config(['ssh_terminal.enabled' => true, 'ssh_terminal.cache_store' => 'array']);
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->string('email'); $t->boolean('is_admin')->default(false); $t->boolean('is_staff')->default(false); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('personal_access_tokens', function (Blueprint $t) { $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token', 64); $t->text('abilities')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamp('last_used_at')->nullable(); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->json('ssh_profile')->nullable(); $t->json('load_status')->nullable(); $t->timestamps(); });
        Schema::create('v2_admin_audit_log', function (Blueprint $t) { $t->id(); $t->integer('admin_id'); foreach (['action','method','uri','request_data','ip'] as $f) $t->text($f); $t->timestamps(); });
        $this->admin = User::create(['email' => 'admin@example.invalid', 'is_admin' => true]);
        $this->access = $this->admin->tokens()->create(['name' => 'test', 'token' => hash('sha256', 'synthetic-token'), 'abilities' => ['*'], 'expires_at' => now()->addHour()]);
        $this->admin->withAccessToken($this->access);
        $this->machine = ServerMachine::create(['name' => 'Test server', 'ssh_profile' => $this->target + ['fingerprint' => 'SHA256:known', 'key_type' => 'ssh-ed25519', 'verified_at' => time()]]);
        $transport = $this->createMock(SshTransport::class);
        $transport->method('probe')->willReturn(['fingerprint' => 'SHA256:known', 'key_type' => 'ssh-ed25519']);
        $this->service = new MachineSshService(new SshTarget(), $transport);
        $settings = $this->createMock(NodeRealtimeSettings::class);
        $settings->method('resolvedPublicUrl')->willReturn('wss://panel.example.test/ws/node');
        app()->instance(NodeRealtimeSettings::class, $settings);
    }

    private function issue(): array
    {
        return $this->service->issue($this->machine, $this->admin, $this->access, 'https://panel.example.test', ['auth_type' => 'password', 'password' => 'synthetic-ssh-password']);
    }

    private function assertReason(string $reason, callable $call): void
    {
        try { $call(); $this->fail('Expected rejection: ' . $reason); }
        catch (SshTerminalException $e) { $this->assertSame($reason, $e->getMessage()); }
    }

    public function test_reporting_address_never_populates_or_replaces_ssh_host(): void
    {
        $this->machine->load_status = ['ip' => ['public_ipv4' => '192.0.2.50']];
        $this->assertSame($this->target['host'], $this->service->profile($this->machine)['host']);
        $this->machine->ssh_profile = null;
        $this->assertSame('', $this->service->profile($this->machine)['host']);
        $this->assertReason('host_not_verified', fn () => $this->issue());
    }

    public function test_ticket_is_encrypted_origin_bound_one_use_and_not_in_url(): void
    {
        $packet = $this->issue();
        $this->assertStringNotContainsString($packet['ticket'], $packet['websocket_url']);
        $encrypted = $this->service->cache()->get('ssh:ticket:' . hash('sha256', $packet['ticket']));
        $this->assertIsString($encrypted); $this->assertStringNotContainsString('synthetic-ssh-password', $encrypted);
        $this->assertReason('ticket_expired', fn () => $this->service->inspect($packet['ticket'], 'https://evil.example'));
        $record = $this->service->inspect($packet['ticket'], 'https://panel.example.test', true);
        $this->assertSame('synthetic-ssh-password', $record['credentials']['password']);
        $this->assertReason('ticket_expired', fn () => $this->service->inspect($packet['ticket'], 'https://panel.example.test', true));
    }

    public function test_machine_token_cannot_authenticate_terminal(): void
    {
        $this->assertReason('ticket_expired', fn () => $this->service->inspect('machine-token', 'https://panel.example.test'));
    }

    public function test_ticket_cannot_admit_two_workers_before_child_consumption(): void
    {
        $packet = $this->issue();
        $this->service->admit($packet['ticket'], 'https://panel.example.test');
        $this->assertReason('ticket_expired', fn () => $this->service->admit($packet['ticket'], 'https://panel.example.test'));
        $this->assertSame((int) $this->admin->id, $this->service->inspect($packet['ticket'], 'https://panel.example.test', true)['admin_id']);
    }

    public function test_role_revocation_invalidates_even_an_unconsumed_ticket(): void
    {
        $packet = $this->issue(); $this->admin->update(['is_admin' => false]);
        $this->assertReason('session_revoked', fn () => $this->service->inspect($packet['ticket'], 'https://panel.example.test'));
    }

    public function test_logout_and_expired_or_scoped_sessions_are_rejected(): void
    {
        $packet = $this->issue(); $this->access->delete();
        $this->assertReason('session_revoked', fn () => $this->service->inspect($packet['ticket'], 'https://panel.example.test'));
    }

    public function test_expired_session_cannot_issue_a_ticket(): void
    {
        $this->access->update(['expires_at' => now()->subMinute()]);
        $this->assertReason('session_revoked', fn () => $this->issue());
    }

    public function test_disabling_terminal_revokes_an_already_admitted_session(): void
    {
        $packet = $this->issue(); $record = $this->service->inspect($packet['ticket'], 'https://panel.example.test', true);
        config(['ssh_terminal.enabled' => false]);
        $this->assertReason('session_revoked', fn () => $this->service->authorizeSession($record));
        $this->assertFalse($this->service->available());
    }

    public function test_restricted_token_and_banned_admin_cannot_issue_tickets(): void
    {
        $this->access->update(['abilities' => ['profile:read']]);
        $this->assertReason('session_revoked', fn () => $this->issue());
        $this->access->update(['abilities' => ['*']]); $this->admin->update(['banned' => true]);
        $this->assertReason('session_revoked', fn () => $this->issue());
    }

    public function test_profile_edits_invalidate_previously_issued_tickets(): void
    {
        $packet = $this->issue(); $p = $this->machine->ssh_profile; $p['port'] = 2222; $this->machine->update(['ssh_profile' => $p]);
        $this->assertReason('profile_changed', fn () => $this->service->inspect($packet['ticket'], 'https://panel.example.test'));
    }

    public function test_probe_confirmation_is_bound_to_admin_machine_and_exact_target(): void
    {
        $probe = $this->service->probe($this->machine, (int) $this->admin->id, $this->target);
        $this->assertArrayNotHasKey('password', $probe);
        $profile = $this->service->confirm($this->machine, (int) $this->admin->id, $this->target + ['probe_id' => $probe['probe_id'], 'confirmed' => true]);
        $this->assertSame('SHA256:known', $profile['fingerprint']);
        $this->assertArrayNotHasKey('ssh_profile', $this->machine->toArray());
        $this->assertReason('probe_expired', fn () => $this->service->confirm($this->machine, (int) $this->admin->id, $this->target + ['probe_id' => $probe['probe_id'], 'confirmed' => true]));
        $probe = $this->service->probe($this->machine, (int) $this->admin->id, $this->target);
        $this->assertReason('probe_expired', fn () => $this->service->confirm($this->machine, 999, $this->target + ['probe_id' => $probe['probe_id'], 'confirmed' => true]));
        $probe = $this->service->probe($this->machine, (int) $this->admin->id, $this->target);
        $this->assertReason('probe_expired', fn () => $this->service->confirm($this->machine, (int) $this->admin->id, array_replace($this->target, ['port' => 2222, 'probe_id' => $probe['probe_id'], 'confirmed' => true])));
    }

    public function test_unknown_host_requires_an_explicit_verified_profile(): void
    {
        $this->machine->update(['ssh_profile' => null]);
        $this->assertReason('host_not_verified', fn () => $this->issue());
    }

    public function test_unencrypted_websocket_is_not_permitted(): void
    {
        $settings = $this->createMock(NodeRealtimeSettings::class); $settings->method('resolvedPublicUrl')->willReturn('ws://panel.example.test/ws/node'); app()->instance(NodeRealtimeSettings::class, $settings);
        $this->assertReason('secure_websocket_required', fn () => $this->issue());
    }

    public function test_leases_are_exclusive_and_an_unrelated_close_cannot_release_them(): void
    {
        $packet = $this->issue(); $record = $this->service->inspect($packet['ticket'], 'https://panel.example.test', true);
        $this->service->acquire($record);
        $this->assertReason('session_busy', fn () => $this->service->acquire($record));
        $other = $record; $other['lease_owner'] = 'other'; $this->service->release($other);
        $this->assertReason('session_busy', fn () => $this->service->acquire($record));
        $this->service->release($record); $this->service->acquire($record); $this->service->release($record);
        $this->assertNull($this->service->cache()->get('ssh:lease:' . $this->admin->id));
    }

    public function test_target_policy_rejects_local_metadata_mapped_private_and_mixed_dns(): void
    {
        $policy = new SshTarget();
        foreach (['127.0.0.1','10.0.0.1','169.254.169.254','::1','::ffff:127.0.0.1','fd00::1','fe80::1','0.0.0.0','224.0.0.1'] as $ip) $this->assertFalse($policy->allowed($ip), $ip);
        $this->assertTrue($policy->allowed('8.8.8.8'));
        config(['ssh_terminal.allowed_private_hosts' => ['10.0.0.1']]);
        $this->assertTrue($policy->allowed('10.0.0.1')); $this->assertFalse($policy->allowed('10.0.0.2'));
        $dns = new class extends SshTarget { protected function lookup(string $host): array { return ['8.8.8.8', '127.0.0.1']; } };
        $this->assertReason('target_not_allowed', fn () => $dns->resolve('example.test'));
        foreach (['-oProxyCommand=bad','https://8.8.8.8','8.8.8.8;id'] as $host) $this->assertReason('invalid_target', fn () => $policy->normalize(['host' => $host]));
    }

    public function test_host_key_change_is_rejected_before_any_authentication(): void
    {
        $key = 'ssh-ed25519 ' . base64_encode(pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . str_repeat('x', 32));
        $connection = $this->getMockBuilder(InteractiveSshConnection::class)->disableOriginalConstructor()->onlyMethods(['getServerPublicHostKey','login','disconnect'])->getMock();
        $connection->method('getServerPublicHostKey')->willReturn($key);
        $connection->expects($this->never())->method('login'); $connection->expects($this->once())->method('disconnect');
        $transport = new class($connection) extends SshTransport {
            public function __construct(private InteractiveSshConnection $mock) {}
            protected function connection(array $target): InteractiveSshConnection { return $this->mock; }
        };
        $this->assertReason('host_key_changed', fn () => $transport->open($this->target + ['address' => '8.8.8.8','fingerprint' => 'SHA256:different'], ['auth_type' => 'password','password' => 'must-not-send'], 80, 24));
    }

    public function test_terminal_dimensions_have_a_strict_bound(): void
    {
        $this->assertSame([80,24], MachineSshSession::dimensions([]));
        foreach ([['cols' => 0],['rows' => 10000],['cols' => '80'],['rows' => []]] as $frame) $this->assertReason('invalid_frame', fn () => MachineSshSession::dimensions($frame));
    }

    public function test_rsa_sha2_negotiation_preserves_openssh_host_fingerprint(): void
    {
        $blob = pack('N', 7) . 'ssh-rsa' . pack('N', 3) . "\1\0\1" . pack('N', 32) . str_repeat('x', 32);
        $expected = SshTransport::identity('ssh-rsa ' . base64_encode($blob));
        $this->assertSame($expected, SshTransport::identity('rsa-sha2-512 ' . base64_encode($blob)));
        $this->assertSame('ssh-rsa', $expected['key_type']);
    }

    public function test_admin_ssh_request_audit_never_contains_credentials_or_query_secrets(): void
    {
        $request = Request::create('https://panel.example.test/api/v2/admin/server/machine/ssh/ticket?password=query-secret', 'POST', [
            'id' => $this->machine->id,'password' => 'ssh-password','private_key' => 'private-key-material','key_password' => 'key-password', 'ticket' => 'ticket-secret',
        ]); $request->setUserResolver(fn () => $this->admin);
        (new RequestLog())->handle($request, fn () => response()->json(['status' => 'success']));
        $log = AdminAuditLog::firstOrFail(); $serialized = json_encode($log->toArray());
        foreach (['query-secret','ssh-password','private-key-material','key-password','ticket-secret'] as $secret) $this->assertStringNotContainsString($secret, $serialized);
        $this->assertSame(['id' => $this->machine->id], json_decode($log->request_data, true));
    }

    public function test_browser_ssh_routes_refuse_staff_and_non_admin_users(): void
    {
        $controller = new MachineSshController($this->service);
        $this->admin->update(['is_admin' => false,'is_staff' => true]);
        $request = Request::create('https://panel.example.test/profile', 'GET', ['id' => $this->machine->id]); $request->setUserResolver(fn () => $this->admin);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $controller->profile($request);
    }

    public function test_terminal_channel_is_separate_and_bad_tickets_do_not_spawn(): void
    {
        $bridge = new MachineSshBridge($this->service);
        $connection = new class { public $id=1; public bool $machine_ssh=false; public int $maxPackageSize=0; public int $maxSendBufferSize=0; public bool $closed=false; public array $messages=[]; public function close(){ $this->closed=true; } public function send($x){ $this->messages[]=json_decode($x,true); } };
        $nodeRequest = new class { public function get($k){ return null; } };
        $this->assertFalse($bridge->connect($connection,$nodeRequest)); $this->assertFalse($connection->closed);
        $terminalRequest = new class { public function get($k){ return 'admin-ssh'; } public function header($k,$default=''){ return 'https://panel.example.test'; } };
        $this->assertTrue($bridge->connect($connection,$terminalRequest));
        $bridge->message($connection,json_encode(['type'=>'connect','ticket'=>'node-token','cols'=>80,'rows'=>24]));
        $this->assertTrue($connection->closed); $this->assertSame('ticket_expired',$connection->messages[0]['code']);
    }

    public function test_bridge_drains_real_child_pipes_and_releases_after_exit(): void
    {
        $service = $this->createMock(MachineSshService::class);
        $service->method('available')->willReturn(true);
        $service->method('admit')->willReturn(['admin_id' => 123, 'lease_owner' => 'fixture-owner']);
        $service->expects($this->once())->method('release')->with(['admin_id' => 123, 'lease_owner' => 'fixture-owner']);
        $bridge = new class($service) extends MachineSshBridge {
            protected function spawn(array &$pipes) {
                return proc_open([PHP_BINARY, base_path('tests/Fixtures/ssh_stdio_worker.php')], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
            }
        };
        $connection = new class {
            public $id=5; public bool $machine_ssh=false; public int $maxPackageSize=0; public int $maxSendBufferSize=0;
            public bool $closed=false; public array $messages=[];
            public function close(){ $this->closed=true; } public function send($x){ $this->messages[]=json_decode($x,true); }
            public function getSendBufferQueueSize(){ return 0; }
        };
        $request = new class { public function get($k){ return 'admin-ssh'; } public function header($k,$default=''){ return 'https://panel.example.test'; } };
        $bridge->connect($connection,$request);
        $bridge->message($connection,json_encode(['type'=>'connect','ticket'=>str_repeat('a',64),'cols'=>80,'rows'=>24]));
        $bridge->message($connection,json_encode(['type'=>'input','data'=>base64_encode('fixture-echo')]));
        $deadline=microtime(true)+4;
        while(!$connection->closed&&microtime(true)<$deadline){$bridge->tick();usleep(10000);}
        $bridge->close($connection);
        $this->assertTrue($connection->closed);
        $this->assertSame(['ready','output'],array_column($connection->messages,'type'));
        $this->assertSame('fixture-echo',base64_decode($connection->messages[1]['data']));
    }
}
