<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimeSettings;
use App\Services\ServerMachine\MachineTerminalBridge;
use App\Services\ServerMachine\MachineTerminalService;
use App\Services\ServerMachine\SshTerminalException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class TerminalTestService extends MachineTerminalService
{
    public array $agent = ['terminal_protocol' => 1, 'terminal_enabled' => true];
    protected function capability(int $machineId): array { return $this->agent; }
}
class TerminalTestBridge extends MachineTerminalBridge
{
    public float $clock = 100;
    protected function ensureTimer(): void {}
    protected function now(): float { return $this->clock; }
}
#[\AllowDynamicProperties]
class TerminalTestPeer
{
    public array $messages = [];
    public bool $closed = false;
    public int $buffer = 0;
    public function __construct(public int $id) {}
    public function send(string $message, bool $raw = false): bool { $this->messages[] = $raw ? $message : json_decode($message, true); return true; }
    public function close(): void { $this->closed = true; }
    public function getSendBufferQueueSize(): int { return $this->buffer; }
}
class TerminalTestRequest
{
    public function __construct(private string $channel, private string $origin = 'https://panel.example.test') {}
    public function get(string $key): string { return $this->channel; }
    public function header(string $key, string $fallback): string { return $this->origin; }
}

final class MachineTerminalServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private TerminalTestService $service;
    private TerminalTestBridge $bridge;
    private User $admin;
    private PersonalAccessToken $token;
    private ServerMachine $machine;
    private TerminalTestPeer $control;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('log', new class { public function info(...$args): void {} public function warning(...$args): void {} });
        app()->instance('encrypter', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        config(['agent_terminal.enabled' => true, 'agent_terminal.cache_store' => 'array']);
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->string('email'); $t->boolean('is_admin')->default(false); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('personal_access_tokens', function (Blueprint $t) { $t->id(); $t->morphs('tokenable'); $t->string('name'); $t->string('token',64); $t->text('abilities')->nullable(); $t->timestamp('expires_at')->nullable(); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->boolean('terminal_enabled'); $t->timestamps(); });
        $this->admin = User::create(['email'=>'terminal@example.invalid','is_admin'=>true]);
        $this->token = $this->admin->tokens()->create(['name'=>'fixture','token'=>hash('sha256','synthetic-admin'),'abilities'=>['*'],'expires_at'=>now()->addHour()]);
        $this->admin->withAccessToken($this->token);
        $this->machine = ServerMachine::create(['name'=>'Synthetic machine','token'=>str_repeat('m',64),'is_active'=>true,'terminal_enabled'=>true]);
        $settings = $this->createMock(NodeRealtimeSettings::class); $settings->method('resolvedPublicUrl')->willReturn('wss://panel.example.test/ws/node');
        app()->instance(NodeRealtimeSettings::class,$settings);
        $this->service = new TerminalTestService(); $this->bridge = new TerminalTestBridge($this->service);
        $this->control = new TerminalTestPeer(900);
        $this->bridge->controls(fn ($id) => $id === (int) $this->machine->id ? $this->control : null);
    }

    protected function tearDown(): void { $this->bridge->shutdown(); parent::tearDown(); }
    private function packet(): array { return $this->service->issue($this->machine,$this->admin,$this->token,'https://panel.example.test'); }
    private function rejected(string $reason, callable $call): void
    {
        try { $call(); $this->fail('Expected rejection'); }
        catch (SshTerminalException $e) { $this->assertSame($reason,$e->getMessage()); }
    }
    private function browser(int $id = 1): TerminalTestPeer
    {
        $browser = new TerminalTestPeer($id); $this->bridge->connect($browser,new TerminalTestRequest('admin-terminal'));
        $this->bridge->message($browser,json_encode(['type'=>'connect','ticket'=>$this->packet()['ticket'],'cols'=>80,'rows'=>24]));
        $this->assertFalse($browser->closed); return $browser;
    }
    private function agent(int $id = 2, ?array $replace = null): TerminalTestPeer
    {
        $invite = $this->control->messages[array_key_last($this->control->messages)]['terminal'];
        $agent = new TerminalTestPeer($id); $this->bridge->connect($agent,new TerminalTestRequest('agent-terminal',''));
        $this->bridge->message($agent,json_encode(array_replace(['type'=>'agent_connect','session_id'=>$invite['session_id'],
            'capability'=>$invite['capability'],'machine_id'=>$this->machine->id,'token'=>$this->machine->token],$replace ?? [])));
        return $agent;
    }
    private function ready(): array
    {
        $browser=$this->browser(); $agent=$this->agent(); $this->bridge->message($agent,'{"type":"ready"}');
        return [$browser,$agent];
    }

    public function test_ticket_is_encrypted_origin_bound_and_one_use(): void
    {
        $p=$this->packet(); $key='terminal:ticket:'.hash('sha256',$p['ticket']);
        $this->assertStringNotContainsString($p['ticket'],$p['websocket_url']);
        $this->assertStringNotContainsString('token_hash',$this->service->cache()->get($key));
        $this->rejected('ticket_expired',fn ()=>$this->service->consume($p['ticket'],'https://wrong.invalid'));
        $record=$this->service->consume($p['ticket'],'https://panel.example.test');
        $this->assertSame((int)$this->admin->id,$record['admin_id']);
        $this->rejected('ticket_expired',fn ()=>$this->service->consume($p['ticket'],'https://panel.example.test'));
        $this->rejected('ticket_expired',fn ()=>$this->service->consume($this->machine->token,'https://panel.example.test'));
    }
    public function test_role_ban_expiration_and_abilities_cannot_issue_terminal_tickets(): void
    {
        foreach (['is_admin'=>false,'banned'=>true] as $field=>$value) {
            $this->admin->update([$field=>$value]);
            $this->rejected('session_revoked',fn ()=>$this->packet());
            $this->admin->update(['is_admin'=>true,'banned'=>false]);
        }
        $this->token->update(['abilities'=>['server:read']]); $this->rejected('session_revoked',fn ()=>$this->packet());
        $this->token->update(['abilities'=>['*'],'expires_at'=>now()->subSecond()]); $this->rejected('session_revoked',fn ()=>$this->packet());
    }
    public function test_disabled_panel_machine_or_local_agent_fail_closed(): void
    {
        config(['agent_terminal.enabled'=>false]); $this->rejected('session_revoked',fn ()=>$this->packet());
        config(['agent_terminal.enabled'=>true]); $this->machine->update(['terminal_enabled'=>false]); $this->rejected('session_revoked',fn ()=>$this->packet());
        $this->machine->update(['terminal_enabled'=>true,'is_active'=>false]); $this->rejected('session_revoked',fn ()=>$this->packet());
        $this->machine->update(['is_active'=>true]); $this->service->agent['terminal_enabled']=false;
        $this->assertSame('local_disabled',$this->service->profile($this->machine)['agent_state']);
        $this->rejected('unavailable',fn ()=>$this->packet());
        $this->service->agent=[]; $this->assertSame('offline',$this->service->profile($this->machine)['agent_state']);
        $this->service->agent=['terminal_protocol'=>0]; $this->assertSame('upgrade_required',$this->service->profile($this->machine)['agent_state']);
    }
    public function test_rotated_machine_token_revokes_existing_ticket(): void
    {
        $p=$this->packet(); $this->machine->update(['token'=>str_repeat('n',64)]);
        $this->rejected('session_revoked',fn ()=>$this->service->consume($p['ticket'],'https://panel.example.test'));
    }

    public function test_shared_machine_installations_block_agent_terminal_tickets(): void
    {
        $packet = $this->packet();
        $browser = $this->browser();
        $agent = $this->agent();
        $guard = new \App\Services\ServerMachine\MachineIdentityGuard();
        $guard->observeInstallation((int) $this->machine->id, str_repeat('a', 32));
        $guard->observeInstallation((int) $this->machine->id, str_repeat('b', 32));
        $profile = $this->service->profile($this->machine);
        $this->assertSame('identity_conflict', $profile['agent_state']);
        $this->assertFalse($profile['runtime_ready']);
        $this->rejected('unavailable', fn () => $this->packet());
        $this->rejected('unavailable', fn () => $this->service->consume($packet['ticket'], 'https://panel.example.test'));
        $this->bridge->clock += 5;
        $this->bridge->tick();
        $this->assertTrue($browser->closed);
        $this->assertTrue($agent->closed);
    }

    public function test_policy_requires_live_admin_token_and_explicit_confirmation(): void
    {
        $this->machine->update(['terminal_enabled'=>false]);
        $controller=new \App\Http\Controllers\V2\Admin\Server\MachineTerminalController($this->service);
        $request=\Illuminate\Http\Request::create('https://panel.example.test/terminal/policy','POST',[],[],[],
            ['CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'https://panel.example.test'],
            json_encode(['id'=>$this->machine->id,'enabled'=>true,'confirmed'=>true]));
        $request->setUserResolver(fn ()=>$this->admin);
        $this->token->update(['expires_at'=>now()->subSecond()]);
        $response=$controller->policy($request); $this->assertSame(403,$response->getStatusCode());
        $this->assertFalse($this->machine->fresh()->terminal_enabled);
        $this->token->update(['expires_at'=>now()->addHour()]);
        $response=$controller->policy($request); $this->assertSame(200,$response->getStatusCode());
        $this->assertTrue($this->machine->fresh()->terminal_enabled);
    }

    public function test_cross_origin_policy_requests_cannot_enable_machine(): void
    {
        $this->machine->update(['terminal_enabled'=>false]);
        $controller=new \App\Http\Controllers\V2\Admin\Server\MachineTerminalController($this->service);
        $request=\Illuminate\Http\Request::create('https://panel.example.test/terminal/policy','POST',[],[],[],
            ['CONTENT_TYPE'=>'application/json','HTTP_ORIGIN'=>'https://wrong.invalid'],
            json_encode(['id'=>$this->machine->id,'enabled'=>true,'confirmed'=>true]));
        $request->setUserResolver(fn ()=>$this->admin);
        try { $controller->policy($request); $this->fail('Cross-origin grant accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->assertFalse($this->machine->fresh()->terminal_enabled);
    }
    public function test_one_session_per_admin_and_machine_and_release_is_owner_bound(): void
    {
        $record=$this->service->consume($this->packet()['ticket'],'https://panel.example.test');
        $this->service->acquire($record,'owner');
        $this->service->release($record,'wrong-owner');
        $this->rejected('session_busy',fn ()=>$this->service->acquire($record,'next'));
        $this->service->release($record,'owner'); $this->service->acquire($record,'next');
        $this->assertSame('next',$this->service->cache()->get('terminal:machine:'.$this->machine->id));
    }
    public function test_invitation_has_capability_but_never_machine_credentials(): void
    {
        $this->browser(); $invite=$this->control->messages[0];
        $this->assertSame('terminal_open',$invite['type']); $this->assertSame(64,strlen($invite['terminal']['capability']));
        $this->assertStringNotContainsString($this->machine->token,json_encode($invite));
    }
    public function test_machine_token_without_capability_and_wrong_machine_are_rejected(): void
    {
        $browser=$this->browser();
        $bad=$this->agent(2,['capability'=>str_repeat('x',64)]); $this->assertTrue($bad->closed); $this->assertFalse($browser->closed);
        $bad=$this->agent(3,['machine_id'=>999]); $this->assertTrue($bad->closed); $this->assertFalse($browser->closed);
        $bad=$this->agent(4,['token'=>'wrong']); $this->assertTrue($bad->closed); $this->assertFalse($browser->closed);
        $good=$this->agent(5); $this->assertFalse($good->closed);
    }
    public function test_agent_capability_cannot_be_replayed(): void
    {
        $browser=$this->browser(); $first=$this->agent(); $second=$this->agent(3);
        $this->assertTrue($second->closed); $this->assertFalse($first->closed); $this->assertFalse($browser->closed);
    }
    public function test_relays_utf8_bytes_resize_and_interrupt_without_using_control_socket(): void
    {
        [$browser,$agent]=$this->ready();
        $this->assertSame('ready',$browser->messages[0]['type']);
        $this->bridge->message($browser,json_encode(['type'=>'input','data'=>base64_encode("echo \xE4\xBD\xA0\xE5\xA5\xBD\r\x03")]));
        $this->assertSame('input',$agent->messages[array_key_last($agent->messages)]['type']);
        $this->bridge->message($browser,'{"type":"resize","cols":100,"rows":30}');
        $this->assertSame(100,$agent->messages[array_key_last($agent->messages)]['cols']);
        $this->bridge->message($agent,json_encode(['type'=>'output','data'=>base64_encode('synthetic output')]));
        $this->assertSame('synthetic output',base64_decode($browser->messages[array_key_last($browser->messages)]['data']));
        $this->assertCount(1,$this->control->messages);
    }
    public function test_control_disconnect_closes_both_peers_and_releases_machine(): void
    {
        [$browser,$agent]=$this->ready(); $this->bridge->controlClosed($this->control);
        $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
        $this->assertFalse($this->service->cache()->has('terminal:machine:'.$this->machine->id));
    }
    public function test_browser_close_closes_agent_without_closing_proxy_control(): void
    {
        [$browser,$agent]=$this->ready(); $this->bridge->close($browser);
        $this->assertTrue($agent->closed); $this->assertFalse($this->control->closed);
    }
    public function test_revocation_stops_lease_renewal(): void
    {
        [$browser,$agent]=$this->ready(); $this->admin->update(['banned'=>true]); $this->bridge->clock+=6; $this->bridge->tick();
        $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
    }
    public function test_lost_browser_heartbeat_and_pending_agent_expire(): void
    {
        $browser=$this->browser(); $this->bridge->clock+=31; $this->bridge->tick(); $this->assertTrue($browser->closed);
        [$browser,$agent]=$this->ready(); $this->bridge->clock+=46; $this->bridge->tick();
        $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
    }
    public function test_output_backpressure_closes_session_instead_of_accumulating_memory(): void
    {
        [$browser,$agent]=$this->ready(); $browser->buffer=65537;
        $this->bridge->message($agent,json_encode(['type'=>'output','data'=>base64_encode('bounded')]));
        $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
    }
    public function test_empty_input_cannot_extend_idle_deadline(): void
    {
        [$browser,$agent]=$this->ready(); $this->bridge->clock+=601;
        $this->bridge->message($browser,'{"type":"input","data":""}');
        $this->bridge->tick(); $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
    }
    public function test_large_input_invalid_dimensions_and_flood_are_bounded(): void
    {
        [$browser,$agent]=$this->ready();
        $this->bridge->message($browser,json_encode(['type'=>'input','data'=>base64_encode(str_repeat('x',8193))]));
        $this->assertTrue($browser->closed); $this->assertTrue($agent->closed);
        [$browser,$agent]=$this->ready(); $this->bridge->message($browser,'{"type":"resize","cols":9999,"rows":30}');
        $this->assertTrue($agent->closed);
    }
    public function test_unknown_and_disabled_channels_cannot_start_sessions(): void
    {
        $peer=new TerminalTestPeer(99); $this->assertFalse($this->bridge->connect($peer,new TerminalTestRequest('unknown')));
        config(['agent_terminal.enabled'=>false]); $this->assertTrue($this->bridge->connect($peer,new TerminalTestRequest('admin-terminal')));
        $this->assertTrue($peer->closed); $this->assertStringContainsString('403 Forbidden',$peer->messages[0]);
        $this->assertCount(0,$this->control->messages);
    }
}
