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
