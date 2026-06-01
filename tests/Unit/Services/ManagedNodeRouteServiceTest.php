<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plugin;
use App\Models\Server;
use App\Models\ServerRoute;
use Illuminate\Database\Schema\Blueprint;
use Plugin\SubscriptionControl\Services\ManagedNodeRouteService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ManagedNodeRouteServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
    }

    public function test_sync_creates_source_ip_routes_and_binds_enabled_servers(): void
    {
        $manual = ServerRoute::create([
            'remarks' => 'manual keep',
            'match' => ['domain:example.com'],
            'action' => 'block',
            'action_value' => null,
        ]);
        $enabled = $this->createServer(['route_ids' => [$manual->id]]);
        $disabled = $this->createServer(['enabled' => false, 'route_ids' => []]);

        $this->database->table('v2_subscription_control_event')->insert([
            'event_id' => 'evt-tencent',
            'code' => 'subscription_source_ip_denylist',
            'reason' => 'cloud',
            'action' => 'block',
            'client_ip' => '203.0.113.10',
            'ip_prefix' => '203.0.113.0/24',
            'ip_asn' => 45090,
            'ip_org' => 'Tencent cloud computing',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $result = (new ManagedNodeRouteService())->sync([
            'enable_node_source_ip_managed_routes' => true,
            'enable_node_source_ip_route_learned_prefixes' => true,
            'source_ip_deny_cidrs' => "198.51.100.0/24\nbad-value",
            'node_source_ip_provider_policy' => "ucloud=allow\ntencent=block",
            'node_source_ip_provider_cidrs' => '',
            'node_source_ip_managed_max_prefixes_per_provider' => 100,
        ]);

        $this->assertCount(2, $result['active_route_ids']);

        $manualRoute = ServerRoute::query()
            ->where('remarks', ManagedNodeRouteService::ROUTE_REMARK_PREFIX . ' manual 手动来源 IP 黑名单')
            ->first();
        $this->assertNotNull($manualRoute);
        $this->assertSame(['source_ip:198.51.100.0/24'], $manualRoute->match);

        $providerRoute = ServerRoute::query()
            ->where('remarks', ManagedNodeRouteService::ROUTE_REMARK_PREFIX . ' provider:tencent 云厂商 腾讯云')
            ->first();
        $this->assertNotNull($providerRoute);
        $this->assertSame(['source_ip:203.0.113.0/24'], $providerRoute->match);

        $enabled->refresh();
        $this->assertSame(
            [$manual->id, $manualRoute->id, $providerRoute->id],
            array_values(array_map('intval', $enabled->route_ids))
        );

        $disabled->refresh();
        $this->assertSame([], $disabled->route_ids);
    }

    public function test_sync_removes_stale_managed_routes_when_policy_allows_provider(): void
    {
        $server = $this->createServer();

        $first = (new ManagedNodeRouteService())->sync([
            'enable_node_source_ip_managed_routes' => true,
            'source_ip_deny_cidrs' => '',
            'node_source_ip_provider_policy' => 'tencent=block',
            'node_source_ip_provider_cidrs' => "[tencent]\n203.0.113.0/24",
        ]);
        $this->assertCount(1, $first['active_route_ids']);

        $server->refresh();
        $this->assertSame($first['active_route_ids'], array_values(array_map('intval', $server->route_ids)));

        $second = (new ManagedNodeRouteService())->sync([
            'enable_node_source_ip_managed_routes' => true,
            'source_ip_deny_cidrs' => '',
            'node_source_ip_provider_policy' => 'tencent=allow',
            'node_source_ip_provider_cidrs' => "[tencent]\n203.0.113.0/24",
        ]);

        $this->assertSame($first['active_route_ids'], $second['deleted_route_ids']);
        $this->assertSame([], $second['active_route_ids']);
        $this->assertSame(0, ServerRoute::query()->where('remarks', 'like', ManagedNodeRouteService::ROUTE_REMARK_PREFIX . '%')->count());

        $server->refresh();
        $this->assertSame([], $server->route_ids);
    }

    public function test_save_settings_preserves_unrelated_plugin_config_and_syncs(): void
    {
        Plugin::create([
            'code' => ManagedNodeRouteService::PLUGIN_CODE,
            'name' => '订阅风控',
            'description' => '',
            'version' => '1.5.11',
            'author' => '',
            'url' => '',
            'email' => '',
            'license' => '',
            'requires' => '',
            'type' => Plugin::TYPE_FEATURE,
            'is_enabled' => true,
            'config' => json_encode([
                'enable_leak_guard' => true,
                'source_ip_deny_cidrs' => '198.51.100.0/24',
            ]),
        ]);
        $this->createServer();

        $result = (new ManagedNodeRouteService())->saveSettings([
            'enabled' => true,
            'providers' => [
                'ucloud' => 'allow',
                'tencent' => 'block',
            ],
            'provider_cidrs' => [
                'tencent' => ['203.0.113.0/24'],
            ],
        ]);

        $this->assertNotEmpty($result['active_route_ids']);

        $config = json_decode((string) Plugin::where('code', ManagedNodeRouteService::PLUGIN_CODE)->first()?->config, true);
        $this->assertTrue($config['enable_leak_guard']);
        $this->assertSame('198.51.100.0/24', $config['source_ip_deny_cidrs']);
        $this->assertStringContainsString('tencent=block', $config['node_source_ip_provider_policy']);
        $this->assertStringContainsString('[tencent]', $config['node_source_ip_provider_cidrs']);
    }

    private function createTables(): void
    {
        $this->database->schema()->create('v2_plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->default('');
            $table->text('description')->nullable();
            $table->string('version')->default('1.0.0');
            $table->string('author')->nullable();
            $table->string('url')->nullable();
            $table->string('email')->nullable();
            $table->string('license')->nullable();
            $table->string('requires')->nullable();
            $table->text('config')->nullable();
            $table->string('type')->default(Plugin::TYPE_FEATURE);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        $this->database->schema()->create('v2_server_route', function (Blueprint $table): void {
            $table->id();
            $table->string('remarks');
            $table->json('match')->nullable();
            $table->string('action');
            $table->text('action_value')->nullable();
            $table->timestamps();
        });

        $this->database->schema()->create('v2_server', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('runtime')->default(Server::RUNTIME_GENERIC);
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->json('group_ids')->nullable();
            $table->json('route_ids')->nullable();
            $table->string('name');
            $table->decimal('rate', 8, 2)->default(1);
            $table->json('tags')->nullable();
            $table->string('host');
            $table->string('port');
            $table->integer('server_port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->nullable();
            $table->timestamps();
        });

        $this->database->schema()->create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('code', 64)->index();
            $table->text('reason');
            $table->string('action', 32)->index();
            $table->string('client_ip', 64)->nullable()->index();
            $table->integer('ip_asn')->nullable();
            $table->string('ip_prefix', 128)->nullable();
            $table->string('ip_org', 191)->nullable();
            $table->integer('created_at')->index();
            $table->integer('updated_at');
        });
    }

    private function createServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'type' => Server::TYPE_VLESS,
            'runtime' => Server::RUNTIME_V2NODE,
            'machine_id' => null,
            'group_ids' => [],
            'route_ids' => [],
            'name' => 'node',
            'rate' => 1,
            'tags' => [],
            'host' => '127.0.0.1',
            'port' => '443',
            'server_port' => 443,
            'protocol_settings' => [],
            'show' => true,
            'enabled' => true,
            'sort' => 0,
        ], $overrides));
    }
}
