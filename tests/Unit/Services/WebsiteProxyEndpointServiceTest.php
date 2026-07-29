<?php

namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Models\Site;
use App\Models\SiteDomain;
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
        $this->bindSettings([
            'website_proxy_enable' => true,
            'subscription_proxy_https_port' => 443,
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
                        'https_listen' => '0.0.0.0:443',
                        'website_listens' => ['0.0.0.0:8444', '0.0.0.0:8445'],
                        'certificate_domain' => '2.56.116.39',
                    ],
                ],
            ],
        ])->save();

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
