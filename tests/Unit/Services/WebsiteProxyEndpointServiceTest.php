<?php

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\WebsiteProxyHealth;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class WebsiteProxyEndpointServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
        (require base_path('database/migrations/2026_10_25_120000_create_website_proxy_health_table.php'))->up();
        $this->bindSettings([
            'website_proxy_enable' => true,
            'subscription_proxy_https_port' => 443,
            'app_url' => 'https://main.example.test',
        ]);
        $this->createSites();
    }

    public function test_returns_main_and_branch_urls_from_the_same_port_mapping_as_machine_config(): void
    {
        $this->createRunningMachine();
        $service = new WebsiteProxyEndpointService();

        $this->assertSame('https://2.56.116.39', $service->urlForSiteId(null));
        $this->assertSame('https://2.56.116.39:8444', $service->urlForSiteId(2));
        $this->assertSame('https://2.56.116.39:8445', $service->urlForSiteId(3));
    }

    public function test_returns_all_online_machine_urls_for_navigation_pages(): void
    {
        $this->createRunningMachine();
        $second = $this->createRunningMachine();
        $second->forceFill([
            'name' => 'edge-b',
            'token' => 'machine-token-b',
            'sort' => 20,
            'subproxy_cert_domain' => '2.56.116.40',
        ])->save();
        $this->approveMachine($second);

        $this->assertSame([
            'https://2.56.116.39:8444',
            'https://2.56.116.40:8444',
        ], (new WebsiteProxyEndpointService())->urlsForSiteId(2));
    }

    public function test_does_not_publish_a_stale_or_unreported_endpoint(): void
    {
        $machine = $this->createRunningMachine();
        $machine->forceFill(['last_seen_at' => time() - 301])->save();

        $this->assertNull((new WebsiteProxyEndpointService())->urlForSiteId(2));
    }

    public function test_does_not_publish_a_branch_port_missing_from_runtime_status(): void
    {
        $machine = $this->createRunningMachine();
        $loadStatus = $machine->load_status;
        data_set($loadStatus, 'agent.subscription_proxy.website_listens', ['0.0.0.0:8444']);
        $machine->forceFill(['load_status' => $loadStatus])->save();

        $service = new WebsiteProxyEndpointService();
        $this->assertSame('https://2.56.116.39:8444', $service->urlForSiteId(2));
        $this->assertNull($service->urlForSiteId(3));
    }

    public function test_requires_recent_matching_health_and_isolates_failed_sites(): void
    {
        $machine = $this->createRunningMachine();
        $service = new WebsiteProxyEndpointService();
        $health = WebsiteProxyHealth::query()->where('machine_id', $machine->id)->where('site_id', 2)->firstOrFail();
        foreach ([
            ['status' => 'error'], ['status' => 'recovering'],
            ['tls_verified' => false], ['http_status' => 502], ['context_http_status' => 404],
            ['last_checked_at' => time() - 181], ['last_success_at' => time() - 181],
            ['last_checked_at' => time() + 60], ['route_fingerprint' => str_repeat('0', 64)],
        ] as $changes) {
            $original = $health->getAttributes();
            $health->forceFill($changes)->save();
            $this->assertNull($service->urlForSiteId(2));
            $this->assertNotNull($service->urlForSiteId(3));
            $health->setRawAttributes($original)->save();
        }
        $health->delete();
        $this->assertNull($service->urlForSiteId(2));
    }

    public function test_endpoint_and_origin_changes_invalidate_old_health_without_waiting_for_cache(): void
    {
        $machine = $this->createRunningMachine();
        $service = new WebsiteProxyEndpointService();
        $this->assertNotNull($service->urlForSiteId(2));
        $machine->forceFill(['subproxy_cert_domain' => '2.56.116.40'])->save();
        $this->assertNull($service->urlForSiteId(2));
        $this->approveMachine($machine);
        $this->assertSame('https://2.56.116.40:8444', $service->urlForSiteId(2));
        SiteDomain::where('site_id', 2)->update(['domain' => 'changed.example.test']);
        $this->assertNull($service->urlForSiteId(2));
    }

    public function test_missing_schema_global_disable_and_future_heartbeat_fail_closed(): void
    {
        $machine = $this->createRunningMachine();
        $machine->forceFill(['last_seen_at' => time() + 60])->save();
        $service = new WebsiteProxyEndpointService();
        $this->assertNull($service->urlForSiteId(2));
        $machine->forceFill(['last_seen_at' => time()])->save();
        $this->bindSettings(['website_proxy_enable' => false]);
        $this->assertNull($service->urlForSiteId(2));
        Schema::drop('v2_website_proxy_health');
        $this->bindSettings(['website_proxy_enable' => true]);
        $this->assertNull($service->urlForSiteId(2));
    }

    public function test_admin_health_is_site_scoped_fresh_and_does_not_trigger_network_requests(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $machine = $this->createRunningMachine();
        $service = new WebsiteProxyEndpointService();
        $payload = $service->adminHealthPayloads([$machine->id]);
        $this->assertCount(3, $payload[$machine->id]);
        $this->assertTrue($payload[$machine->id][1]['published']);
        WebsiteProxyHealth::where('site_id', 2)->update(['status' => 'error', 'reason' => 'site_context_mismatch']);
        $payload = $service->adminHealthPayloads([$machine->id]);
        $this->assertSame('site_context_mismatch', $payload[$machine->id][1]['reason']);
        $this->assertFalse($payload[$machine->id][1]['eligible']);
        $this->assertTrue($payload[$machine->id][2]['eligible']);
        $this->assertNotNull($service->urlForSiteId(3));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $machine->forceFill(['subproxy_cert_domain' => '2.56.116.40'])->save();
        $payload = $service->adminHealthPayloads([$machine->id]);
        $this->assertSame('unverified', $payload[$machine->id][1]['status']);
        $this->assertNull($payload[$machine->id][1]['last_checked_at']);
    }

    private function approveMachine(ServerMachine $machine): void
    {
        foreach ((new WebsiteProxyEndpointService())->configuredTargets($machine->id) as $target) {
            WebsiteProxyHealth::query()->updateOrCreate([
                'machine_id' => $machine->id, 'site_id' => $target['site_id'],
            ], [
                'route_fingerprint' => $target['route_fingerprint'],
                'endpoint_url' => $target['url'], 'upstream_url' => $target['upstream_url'],
                'site_code' => $target['site_code'], 'status' => 'ok', 'reason' => 'ok',
                'tls_verified' => true, 'http_status' => 200, 'context_http_status' => 200,
                'last_checked_at' => time(), 'last_success_at' => time(),
            ]);
        }
    }

    private function createRunningMachine(): ServerMachine
    {
        $machine = ServerMachine::create([
            'name' => 'edge-a',
            'token' => 'machine-token',
            'is_active' => true,
            'sort' => 10,
        ]);
        $machine->forceFill([
            'webproxy_enabled' => true,
            'subproxy_cert_domain' => '2.56.116.39',
            'last_seen_at' => time(),
            'load_status' => [
                'agent' => [
                    'subscription_proxy' => [
                        'running' => true,
                        'website_profiles' => 3,
                        'mode' => 'https',
                        'https_listen' => '0.0.0.0:443',
                        'website_listens' => ['0.0.0.0:8444', '0.0.0.0:8445'],
                        'certificate_domain' => '2.56.116.39',
                    ],
                ],
            ],
        ])->save();

        $this->approveMachine($machine);

        return $machine->fresh();
    }

    private function createSites(): void
    {
        Site::create([
            'id' => 1,
            'code' => 'main',
            'name' => 'Main',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
        ]);
        foreach ([
            [2, 'sp', 'sp.huhu.icu'],
            [3, 'budget', '250.huhu.icu'],
        ] as [$id, $code, $domain]) {
            Site::create([
                'id' => $id,
                'code' => $code,
                'name' => $code,
                'status' => Site::STATUS_ACTIVE,
                'is_default' => false,
            ]);
            SiteDomain::create([
                'site_id' => $id,
                'domain' => $domain,
                'status' => SiteDomain::STATUS_ACTIVE,
                'is_primary' => true,
            ]);
        }
    }

    private function createTables(): void
    {
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('token');
            $table->boolean('is_active')->default(true);
            $table->boolean('webproxy_enabled')->default(false);
            $table->unsignedSmallInteger('subproxy_https_port')->nullable();
            $table->string('subproxy_cert_domain')->nullable();
            $table->integer('sort')->default(0);
            $table->unsignedInteger('last_seen_at')->nullable();
            $table->json('load_status')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_site', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->string('status');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
        Schema::create('v2_site_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->string('domain');
            $table->string('status');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    private function bindSettings(array $values): void
    {
        app()->instance(Setting::class, new class($values) extends Setting {
            public function __construct(private array $values)
            {
                $this->values = array_change_key_case($this->values, CASE_LOWER);
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[strtolower($key)] ?? $default;
            }
        });
    }
}
