<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Server\HysteriaEchController;
use App\Models\Server;
use App\Models\User;
use App\Services\Node\HysteriaEchProvisioningService;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;
use Tests\Unit\Support\Hysteria2EchTest;

final class HysteriaEchProvisioningTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('type'); $table->string('runtime'); $table->string('port')->default('443');
            $table->json('protocol_settings'); $table->json('group_ids');
            $table->boolean('enabled')->default(true); $table->boolean('show')->default(true);
            $table->integer('sort')->default(0); $table->float('rate')->default(1);
            $table->integer('created_at')->nullable(); $table->integer('updated_at')->nullable();
        });
        app()->instance(NodeRealtimePublisher::class, $this->createMock(NodeRealtimePublisher::class));
    }

    private function node(): Server
    {
        return Server::create(['type' => 'hysteria', 'group_ids' => ['1'], 'protocol_settings' =>
            HysteriaEchProvisioningService::normalize(['version' => 2,
                'tls' => ['server_name' => 'public.example.com', 'allow_insecure' => false],
                'ech' => ['enabled' => true, 'mode' => 'automatic']])]);
    }

    private function request(?Server $node, array $body): Request
    {
        $request = Request::create('/api/v2/server/ech', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
        $request->attributes->set('node_info', $node);
        $request->merge(['node_type' => 'hysteria']);
        return $request;
    }

    public function test_callback_requires_authorized_node_and_rejects_private_or_cross_node_fields(): void
    {
        $controller = new HysteriaEchController(); $service = new HysteriaEchProvisioningService();
        $this->assertSame(403, $controller->report($this->request(null, []), $service)->getStatusCode());
        $node = $this->node();
        foreach (['private_key', 'node_id', 'token', 'private_pem'] as $field) {
            $response = $controller->report($this->request($node, [$field => 'untrusted']), $service);
            $this->assertSame(422, $response->getStatusCode());
        }
        $this->assertSame('pending', $node->fresh()->protocol_settings['ech']['state']);
    }

    public function test_only_ready_nodes_enter_subscriptions_and_the_callback_is_scoped_and_idempotent(): void
    {
        $node = $this->node(); $other = $this->node();
        $user = (new User())->forceFill(['group_id' => 1, 'uuid' => 'test-uuid']);
        $this->assertSame([], ServerService::getAvailableServers($user));
        $body = ['request_id' => $node->protocol_settings['ech']['request_id'], 'phase' => 'prepared',
            'config' => Hysteria2EchTest::publicConfig(), 'key_file' => '/etc/kelinode/ech/owned/key.pem'];
        $controller = new HysteriaEchController(); $service = new HysteriaEchProvisioningService();
        $premature = $body; $premature['phase'] = 'ready';
        $this->assertSame(409, $controller->report($this->request($node, $premature), $service)->getStatusCode());
        $this->assertSame(200, $controller->report($this->request($node, $body), $service)->getStatusCode());
        $this->assertSame([], ServerService::getAvailableServers($user));
        $this->assertSame(409, $controller->report($this->request($other, $body), $service)->getStatusCode());
        $body['phase'] = 'ready';
        $this->assertSame(200, $controller->report($this->request($node, $body), $service)->getStatusCode());
        $this->assertSame(200, $controller->report($this->request($node, $body), $service)->getStatusCode());
        $this->assertSame('pending', $other->fresh()->protocol_settings['ech']['state']);
        $available = ServerService::getAvailableServers($user);
        $this->assertTrue(array_is_list($available));
        $this->assertSame([$node->id], array_column($available, 'id'));
        $node->forceFill(['enabled' => false])->save();
        $this->assertSame(409, $controller->report($this->request($node, $body), $service)->getStatusCode());
    }

    public function test_trusttunnel_uses_its_own_tls_domain_and_exports_only_ready_public_ech(): void
    {
        $settings = HysteriaEchProvisioningService::normalize([
            'network' => 'tcp', 'tls' => 1,
            'tls_settings' => ['server_name' => 'public.example.com', 'alpn' => ['h3'], 'allow_insecure' => false],
            'ech' => ['enabled' => true, 'mode' => 'automatic'],
        ], null, 'tls_settings');
        $node = Server::create(['type' => 'trusttunnel', 'group_ids' => ['1'], 'protocol_settings' => $settings]);
        $user = (new User())->forceFill(['group_id' => 1, 'uuid' => 'test-uuid']);
        $this->assertSame([], ServerService::getAvailableServers($user));
        $body = ['request_id' => $settings['ech']['request_id'], 'phase' => 'prepared',
            'config' => Hysteria2EchTest::publicConfig(), 'key_file' => '/etc/kelinode/ech/owned/key.pem'];
        $controller = new HysteriaEchController(); $service = new HysteriaEchProvisioningService();
        $this->assertSame(200, $controller->report($this->request($node, $body), $service)->getStatusCode());
        $this->assertSame([], ServerService::getAvailableServers($user));
        $body['phase'] = 'ready';
        $this->assertSame(200, $controller->report($this->request($node, $body), $service)->getStatusCode());
        $available = ServerService::getAvailableServers($user);
        $this->assertSame([$node->id], array_column($available, 'id'));
        $export = \App\Protocols\ClashMeta::buildTrustTunnel('test-uuid', ['name' => 'TT fixture', 'host' => 'public.example.com', ...$node->fresh()->toArray()]);
        $this->assertTrue($export['quic']);
        $this->assertSame(['enable' => true, 'config' => $body['config']], $export['ech-opts']);
        $this->assertArrayNotHasKey('key_file', $export);
    }
}
