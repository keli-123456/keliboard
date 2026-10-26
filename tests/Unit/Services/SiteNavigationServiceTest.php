<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteNavigation;
use App\Models\SiteNavigationDomain;
use App\Models\SiteNavigationLink;
use App\Models\SiteSetting;
use App\Services\SiteNavigationService;
use App\Services\SubscriptionProxy\WebsiteProxyEndpointService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteNavigationServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createTables();
        $this->bindSettings([
            'app_name' => 'Keli Main',
            'app_url' => 'http://main.example.test',
            'logo' => 'https://static.example.test/logo.png',
        ]);
        app()->instance(WebsiteProxyEndpointService::class, new class extends WebsiteProxyEndpointService {
            public function urlsForSiteId(?int $siteId): array
            {
                return $siteId !== null
                    ? ['https://2.56.116.39:8449', 'https://2.56.116.40:8449']
                    : ['https://2.56.116.39'];
            }
        });
    }

    public function test_resolves_navigation_domain_and_aggregates_site_proxy_and_manual_urls(): void
    {
        $site = Site::query()->create([
            'code' => 'branch',
            'name' => 'Branch',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => '分站品牌',
            'logo_url' => 'https://static.example.test/branch.png',
            'enabled' => true,
        ]);
        foreach ([
            ['shop.example.test', true],
            ['backup.example.test', false],
        ] as [$domain, $primary]) {
            SiteDomain::query()->create([
                'site_id' => $site->id,
                'domain' => $domain,
                'status' => SiteDomain::STATUS_ACTIVE,
                'is_primary' => $primary,
            ]);
        }

        $navigation = SiteNavigation::query()->create([
            'scope_key' => 'site:' . $site->id,
            'site_id' => $site->id,
            'enabled' => true,
            'title' => null,
            'description' => '请选择入口',
            'announcement' => '域名失效时请使用备用访问',
        ]);
        SiteNavigationDomain::query()->create([
            'navigation_id' => $navigation->id,
            'domain' => 'nav.example.test',
            'status' => SiteNavigationDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'sort' => 0,
        ]);
        SiteNavigationLink::query()->create([
            'navigation_id' => $navigation->id,
            'label' => '客服入口',
            'url' => 'https://support.example.test',
            'enabled' => true,
            'sort' => 10,
        ]);

        $service = app(SiteNavigationService::class);
        $request = Request::create('https://nav.example.test/', 'GET');
        $storedDomain = SiteNavigationDomain::query()
            ->with('navigation.site')
            ->where('domain', 'nav.example.test')
            ->first();

        $this->assertSame('nav.example.test', $request->getHost());
        $this->assertNotNull($storedDomain);
        $this->assertNotNull($storedDomain->navigation);
        $this->assertSame(Site::STATUS_ACTIVE, $storedDomain->navigation->site?->status);
        $payload = $service->pageForRequest($request);
        $this->assertNotNull($payload);
        $this->assertSame('分站品牌', $payload['title']);
        $this->assertSame('https://nav.example.test', $service->urlForSiteId((int) $site->id));
        $this->assertSame([
            'https://shop.example.test',
            'https://backup.example.test',
            'https://2.56.116.39:8449',
            'https://2.56.116.40:8449',
            'https://support.example.test',
        ], array_column($payload['destinations'], 'url'));
        $this->assertTrue($payload['destinations'][0]['recommended']);
    }

    public function test_disabled_navigation_domain_does_not_replace_the_storefront(): void
    {
        $navigation = SiteNavigation::query()->create([
            'scope_key' => 'platform',
            'site_id' => null,
            'enabled' => true,
        ]);
        SiteNavigationDomain::query()->create([
            'navigation_id' => $navigation->id,
            'domain' => 'nav.example.test',
            'status' => SiteNavigationDomain::STATUS_DISABLED,
            'is_primary' => true,
            'sort' => 0,
        ]);

        $service = app(SiteNavigationService::class);

        $this->assertNull($service->pageForRequest(Request::create('https://nav.example.test/', 'GET')));
        $this->assertNull($service->urlForSiteId(null));
    }

    public function test_information_uses_real_agent_threshold_and_keeps_storefront_path_and_query(): void
    {
        $this->bindSettings([
            'app_url' => 'https://main.example.test/portal/?tenant=one#old',
            'agent_center_enable' => true, 'agent_center_unlock_balance' => 66600,
            'currency_symbol' => '$', 'customer_service_url' => 'https://support.example.test',
            'customer_service_name' => '在线客服', 'telegram_discuss_link' => 'https://t.me/example',
            'tos_url' => 'https://legal.example.test/terms',
        ]);

        $page = $this->navigationPage();
        $this->assertSame(['referral', 'agent'], array_column($page['opportunities'], 'kind'));
        $this->assertSame('https://main.example.test/portal/?tenant=one#/referral-center', $page['opportunities'][0]['url']);
        $this->assertSame('https://main.example.test/portal/?tenant=one#/agent-center', $page['opportunities'][1]['url']);
        $this->assertSame('账户余额达到 $666.00 可申请开通', $page['opportunities'][1]['detail']);
        $this->assertSame(['help', 'support', 'community', 'terms'], array_column($page['help_links'], 'kind'));
        $this->assertSame('https://main.example.test/portal/?tenant=one#/help-center', $page['help_links'][0]['url']);
    }

    public function test_disabled_opportunities_and_unsafe_contact_urls_are_not_published(): void
    {
        $this->bindSettings([
            'app_url' => 'https://main.example.test', 'invite_gen_limit' => 0, 'agent_center_enable' => false,
            'customer_service_url' => 'javascript:alert(1)', 'telegram_discuss_link' => 'http://community.example.test',
            'tos_url' => 'https://user:secret@legal.example.test',
        ]);
        $page = $this->navigationPage();
        $this->assertSame([], $page['opportunities']);
        $this->assertSame(['help'], array_column($page['help_links'], 'kind'));

        $this->bindSettings(['app_url' => 'https://main.example.test', 'invite_gen_limit' => 5, 'invite_commission' => 0]);
        $this->assertSame([], $this->navigationPage()['opportunities']);
    }

    public function test_manual_agent_mode_does_not_advertise_balance_unlock(): void
    {
        $this->bindSettings([
            'app_url' => 'https://main.example.test', 'agent_center_enable' => true,
            'agent_center_unlock_mode' => 'manual', 'agent_center_unlock_balance' => 66600,
        ]);
        $page = $this->navigationPage();
        $this->assertSame('提交代理申请，由平台审核开通', $page['opportunities'][1]['detail']);
    }

    public function test_branch_information_stays_on_its_own_storefront_and_contacts(): void
    {
        $this->bindSettings([
            'app_url' => 'https://platform.example.test', 'agent_center_enable' => true,
            'customer_service_url' => 'https://platform-support.example.test',
            'telegram_discuss_link' => 'https://t.me/platform_only', 'tos_url' => 'https://platform.example.test/terms',
        ]);
        $site = Site::query()->create(['code' => 'branch-info', 'name' => 'Branch', 'status' => Site::STATUS_ACTIVE]);
        $setting = SiteSetting::query()->create([
            'site_id' => $site->id, 'site_name' => 'Branch', 'support_name' => '分站客服',
            'support_url' => 'https://branch-support.example.test', 'enabled' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id, 'domain' => 'branch.example.test', 'status' => SiteDomain::STATUS_ACTIVE, 'is_primary' => true,
        ]);
        $page = $this->navigationPage($site);
        $this->assertSame('https://branch.example.test/#/referral-center', $page['opportunities'][0]['url']);
        $this->assertSame('https://branch.example.test/#/agent-center', $page['opportunities'][1]['url']);
        $this->assertSame(['help', 'support'], array_column($page['help_links'], 'kind'));
        $this->assertSame('分站客服', $page['help_links'][1]['label']);
        $this->assertSame('https://branch-support.example.test', $page['help_links'][1]['url']);
        $this->assertStringNotContainsString('platform', json_encode($page['help_links']));

        $setting->update(['support_url' => null]);
        $this->assertSame(['help'], array_column($this->navigationPage($site)['help_links'], 'kind'));
    }

    public function test_proxy_fallback_keeps_port_and_no_available_storefront_hides_internal_actions(): void
    {
        $this->bindSettings(['app_url' => '', 'agent_center_enable' => true]);
        $this->assertSame('https://2.56.116.39/#/agent-center', $this->navigationPage()['opportunities'][1]['url']);
        app()->instance(WebsiteProxyEndpointService::class, new class extends WebsiteProxyEndpointService {
            public function urlsForSiteId(?int $siteId): array
            {
                return ['https://203.0.113.10:8444'];
            }
        });
        $this->assertSame('https://203.0.113.10:8444/#/help-center', $this->navigationPage()['help_links'][0]['url']);
        app()->instance(WebsiteProxyEndpointService::class, new class extends WebsiteProxyEndpointService {
            public function urlsForSiteId(?int $siteId): array
            {
                return [];
            }
        });
        $page = $this->navigationPage();
        $this->assertSame([], $page['opportunities']);
        $this->assertSame([], $page['help_links']);
    }

    private function navigationPage(?Site $site = null): array
    {
        $navigation = SiteNavigation::query()->firstOrCreate(
            ['scope_key' => $site ? 'site:' . $site->id : 'platform'],
            ['site_id' => $site?->id, 'enabled' => true]
        );
        $domain = 'info-' . ($site?->id ?? 0) . '.example.test';
        SiteNavigationDomain::query()->firstOrCreate(
            ['domain' => $domain],
            ['navigation_id' => $navigation->id, 'status' => SiteNavigationDomain::STATUS_ACTIVE, 'is_primary' => true, 'sort' => 0]
        );
        $page = app(SiteNavigationService::class)->pageForRequest(Request::create('https://' . $domain . '/', 'GET'));
        $this->assertNotNull($page);
        return $page;
    }

    private function createTables(): void
    {
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
        Schema::create('v2_site_setting', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique();
            $table->string('site_name')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('support_name')->nullable();
            $table->string('support_url')->nullable();
            $table->string('telegram_discuss_link')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
        Schema::create('v2_site_navigation', function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key')->unique();
            $table->unsignedBigInteger('site_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->string('announcement')->nullable();
            $table->timestamps();
        });
        Schema::create('v2_site_navigation_domain', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('navigation_id');
            $table->string('domain')->unique();
            $table->string('status');
            $table->boolean('is_primary')->default(false);
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
        Schema::create('v2_site_navigation_link', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('navigation_id');
            $table->string('label');
            $table->string('url');
            $table->boolean('enabled')->default(true);
            $table->integer('sort')->default(0);
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
