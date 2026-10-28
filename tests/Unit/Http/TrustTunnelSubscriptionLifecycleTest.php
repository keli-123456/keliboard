<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\User;
use App\Services\Node\NodeConfigService;
use App\Services\Node\ServerConfigHistoryService;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerService;
use App\Services\SiteNavigationService;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Services\UserService;
use App\Support\ProtocolCapabilityService;
use App\Support\ProtocolManager;
use App\Support\UserClientCompatibilityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TrustTunnelSubscriptionLifecycleTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private array $notifications = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('f', 32)),
            'protocol_capabilities' => require base_path('config/protocol_capabilities.php'),
            'protocol_capabilities.experimental.trusttunnel' => true,
            'user_sync.use_state_table_for_server_users' => false]);
        app()->instance('encrypter', new Encrypter(str_repeat('f', 32), 'AES-256-CBC'));
        Schema::create('v2_server', function (Blueprint $table): void {
            $table->id();
            foreach (['type', 'runtime', 'code', 'name', 'host', 'port'] as $field) $table->string($field)->nullable();
            foreach (['group_ids', 'route_ids', 'tags', 'protocol_settings', 'rate_time_ranges'] as $field) $table->json($field)->nullable();
            foreach (['server_port', 'parent_id', 'machine_id'] as $field) $table->integer($field)->nullable();
            $table->float('rate')->default(1);
            $table->integer('sort')->default(0);
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->boolean('rate_time_enable')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->id(); $table->string('uuid'); $table->integer('group_id'); $table->integer('plan_id')->nullable();
            $table->boolean('banned')->default(false); $table->bigInteger('u')->default(0); $table->bigInteger('d')->default(0);
            $table->bigInteger('transfer_enable'); $table->integer('expired_at')->nullable();
            $table->integer('speed_limit')->default(0); $table->integer('device_limit')->default(0);
            $table->timestamps();
        });
        (require database_path('migrations/2026_10_27_000001_create_server_config_revisions.php'))->up();
        $publisher = $this->createMock(NodeRealtimePublisher::class);
        $publisher->method('invalidateConfigForServers')->willReturnCallback(function ($ids, $reason, $payload): void {
            $this->notifications[] = [$ids, $reason, $payload];
        });
        app()->instance(NodeRealtimePublisher::class, $publisher);
        app()->instance('protocols.manager', new ProtocolManager(app()));
        app()->instance('protocols.capabilities', new ProtocolCapabilityService(config('protocol_capabilities')));
        app()->instance(SiteNavigationService::class, new class extends SiteNavigationService {
            public function urlForSubscription(mixed $user, Request $request): ?string { return null; }
        });
        app()->instance(WebsiteProxyEndpointService::class, new class extends WebsiteProxyEndpointService {
            public function urlForSubscription(mixed $user, Request $request): ?string { return null; }
        });
        $this->fixtureSettings();
    }

    private function fixtureSettings(int $socksPort = 28743): void
    {
        $this->bindTestSettings(['app_name' => 'Chain', 'risk_center_enable' => false,
            'server_push_interval' => 1, 'server_pull_interval' => 1,
            'subscribe_template_clashmeta' => Yaml::dump([
                'socks-port' => $socksPort, 'allow-lan' => false, 'bind-address' => '127.0.0.1',
                'mode' => 'rule', 'log-level' => 'error', 'dns' => ['enable' => false],
                'proxies' => [], 'proxy-groups' => [['name' => 'Chain', 'type' => 'select', 'proxies' => []]],
                'rules' => ['MATCH,Chain'],
            ], 5)]);
    }

    private function user(): User
    {
        return User::create(['uuid' => '123e4567-e89b-12d3-a456-426614174000', 'group_id' => 1,
            'plan_id' => 1, 'transfer_enable' => 1073741824, 'expired_at' => time() + 3600]);
    }

    private function saveNode(string $name, array $alpn, int $port = 28443): Server
    {
        return (new ServerConfigHistoryService())->persist(null, ServerSave::validateSnapshot([
            'type' => 'trusttunnel', 'runtime' => 'v2node', 'name' => $name,
            'host' => '127.0.0.1', 'port' => $port, 'server_port' => $port,
            'group_ids' => ['1'], 'show' => true, 'enabled' => true, 'rate' => 1,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 1, 'tls_settings' => [
                'server_name' => 'tt.example.test', 'alpn' => $alpn, 'cert_mode' => 'file',
                'cert_file' => '/FIXTURE/fixture.cer', 'key_file' => '/FIXTURE/fixture.key', 'allow_insecure' => false,
            ]],
        ]), 7)->fresh();
    }

    private function subscribe(User $user, string $flag, ?string $userAgent = null): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create('https://panel.example.test/s/synthetic', 'GET', $flag === '' ? [] : ['flag' => $flag], [], [],
            $userAgent === null ? [] : ['HTTP_USER_AGENT' => $userAgent]);
        app()->instance('request', $request);
        return (new ClientController())->doSubscribe($request, $user);
    }

    private function proxy(User $user): array
    {
        $response = $this->subscribe($user, 'mihomo/1.19.33');
        $this->assertSame(200, $response->getStatusCode());
        return Yaml::parse($response->getContent());
    }

    public function test_saved_node_payload_and_subscription_preserve_user_identity_and_transport(): void
    {
        $user = $this->user();
        foreach ([['h2'], ['h3'], ['h2', 'h3']] as $alpn) {
            Server::query()->update(['show' => false]);
            $server = $this->saveNode('TT-' . implode('-', $alpn), $alpn);
            $node = (new NodeConfigService())->buildResponse($server, true, true);
            $config = $this->proxy($user);
            $this->assertCount(1, $config['proxies']);
            $export = $config['proxies'][0];
            $this->assertSame($user->uuid, $export['username']);
            $this->assertSame($user->uuid, $export['password']);
            $this->assertSame('tt.example.test', $export['sni']);
            $this->assertSame($alpn === ['h3'], $export['quic']);
            $this->assertFalse($export['skip-cert-verify']);
            $this->assertSame($node['server_port'], $export['port']);
            $this->assertSame($alpn, $node['tls_settings']['alpn']);
            $users = ServerService::getAvailableUsers($server)->toArray();
            $this->assertCount(1, $users);
            $this->assertSame($export['username'], $users[0]->uuid);
            $this->assertStringNotContainsString('/FIXTURE', json_encode($export));
        }
        $this->assertCount(3, $this->notifications);
    }

    public function test_actual_subscription_filters_unverified_clients_without_removing_legacy_nodes(): void
    {
        $user = $this->user();
        $this->saveNode('TT-synthetic', ['h2']);
        Server::create(['type' => 'trojan', 'name' => 'Legacy-synthetic', 'host' => '127.0.0.1', 'port' => 28440,
            'group_ids' => ['1'], 'show' => true, 'enabled' => true, 'protocol_settings' => [
                'network' => 'tcp', 'server_name' => 'tt.example.test', 'allow_insecure' => false,
            ]]);
        foreach (['mihomo/1.19.32', 'mihomo/1.20.0-alpha', 'ClashVerge/99.0.0', 'Unknown/99.0.0',
            'Shadowrocket/2698', 'sing-box/1.14.0'] as $flag) {
            $body = $this->subscribe($user, $flag)->getContent();
            $decoded = base64_decode($body, true);
            $body = is_string($decoded) ? $decoded : $body;
            $this->assertStringNotContainsString('TT-synthetic', $body, $flag);
            $this->assertStringNotContainsString('trusttunnel', $body, $flag);
            $this->assertTrue(str_contains($body, 'Legacy-synthetic') || str_contains($body, rawurlencode('Legacy-synthetic')), $flag);
        }
        $this->assertContains('TT-synthetic', array_column($this->proxy($user)['proxies'], 'name'));
    }

    public function test_visibility_group_feature_gate_and_ban_are_applied_to_the_saved_node(): void
    {
        $user = $this->user();
        $server = $this->saveNode('TT-scope', ['h3']);
        $this->assertCount(1, ServerService::getAvailableServers($user));
        foreach (['show' => false, 'enabled' => false, 'group_ids' => ['9']] as $field => $value) {
            $original = $server->getAttribute($field);
            $server->update([$field => $value]);
            $this->assertSame([], ServerService::getAvailableServers($user), $field);
            $server->update([$field => $original]);
        }
        config(['protocol_capabilities.experimental.trusttunnel' => false]);
        $this->assertSame([], ServerService::getAvailableServers($user));
        config(['protocol_capabilities.experimental.trusttunnel' => true]);
        $user->update(['banned' => true]);
        $this->assertFalse((new UserService())->isAvailable($user));
        $this->assertCount(0, ServerService::getAvailableUsers($server));
        $denied = Request::create('https://panel.example.test/s/synthetic', 'GET', ['flag' => 'mihomo/1.19.33']);
        $denied->setUserResolver(fn (): User => $user);
        $this->assertSame(403, (new ClientController())->subscribe($denied)->getStatusCode());
        $user->update(['banned' => false, 'speed_limit' => 1, 'device_limit' => 2]);
        $available = ServerService::getAvailableUsers($server)->first();
        $this->assertSame(1, $available->speed_limit);
        $this->assertSame(2, $available->device_limit);
        $labels = app(UserClientCompatibilityService::class)->summarize([$server->toArray()]);
        $this->assertSame(['TrustTunnel (H3)'], $labels['protocols']);
    }

    public function test_format_only_import_flags_use_a_canonical_core_header_without_trusting_wrappers(): void
    {
        $user = $this->user();
        $this->saveNode('TT-header-core', ['h2']);
        foreach (['meta', 'clashmeta', 'ClashVerge/99.0.0'] as $flag) {
            foreach (['Mihomo/1.19.33' => true, 'Mihomo/v1.19.33' => true, 'Mihomo/1.19.32' => false,
                'clash.meta/v1.19.33' => true, 'clash.meta/v1.19.32' => false, 'clash.meta/v1.20.0-alpha' => false,
                'Mihomo/1.20.0-alpha' => false, 'ClashVerge/99.0.0' => false, 'Wrapper/99.0.0 Mihomo/1.19.33' => false] as $agent => $allowed) {
                $body = $this->subscribe($user, $flag, $agent)->getContent();
                $this->assertSame($allowed, str_contains($body, 'TT-header-core'), "$flag / $agent");
            }
        }
        $this->assertStringContainsString('TT-header-core', $this->subscribe($user, '', 'clash.meta/v1.19.33')->getContent());
        $body = $this->subscribe($user, 'Shadowrocket/2698', 'Mihomo/1.19.33')->getContent();
        $this->assertStringNotContainsString('TT-header-core', base64_decode($body, true) ?: $body);
    }

    public function test_transport_change_has_new_config_etag_and_keeps_subscription_credentials(): void
    {
        $user = $this->user();
        $server = $this->saveNode('TT-update', ['h2']);
        $builder = new NodeConfigService();
        $before = $builder->buildCacheEntry($server, true);
        $old = $this->proxy($user)['proxies'][0];
        $settings = $server->protocol_settings;
        $settings['tls_settings']['alpn'] = ['h3'];
        $server = (new ServerConfigHistoryService())->persist($server->id, ['protocol_settings' => $settings], 7)->fresh();
        $this->assertNotSame($before['etag'], $builder->buildCacheEntry($server, true)['etag']);
        $next = $this->proxy($user)['proxies'][0];
        $this->assertFalse($old['quic']);
        $this->assertTrue($next['quic']);
        $this->assertSame($old['password'], $next['password']);
        $this->assertCount(2, $this->notifications);
    }

    public function exportInteropFixtures(string $destination): void
    {
        $this->setUp();
        try {
            $user = $this->user();
            $fixtures = [];
            foreach (['h2' => ['h2'], 'h3' => ['h3'], 'dual' => ['h2', 'h3']] as $name => $alpn) {
                $index = count($fixtures);
                Server::query()->update(['show' => false]);
                $server = $this->saveNode('TT-' . $name, $alpn, 28443 + $index);
                $this->fixtureSettings(28743 + $index);
                $users = ServerService::getAvailableUsers($server)->toArray();
                $user->update(['speed_limit' => 1]);
                $limited = ServerService::getAvailableUsers($server)->toArray();
                $user->update(['banned' => true]);
                $revoked = ServerService::getAvailableUsers($server)->toArray();
                $user->update(['banned' => false, 'speed_limit' => 0]);
                $fixtures[] = ['name' => $name, 'node_id' => $server->id,
                    'node' => (new NodeConfigService())->buildResponse($server, true, true),
                    'client' => $this->proxy($user), 'users' => $users, 'limited_users' => $limited,
                    'revoked_users' => $revoked, 'restored_users' => ServerService::getAvailableUsers($server)->toArray()];
            }
            file_put_contents($destination, json_encode(['synthetic_only' => true, 'fixtures' => $fixtures], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } finally {
            $this->tearDown();
        }
    }
}
