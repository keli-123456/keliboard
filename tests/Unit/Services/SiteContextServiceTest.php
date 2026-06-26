<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\SiteContextService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_site_setting_belongs_to_site(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $setting = SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Cheap Cloud',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'landing_theme' => 'sakura',
            'accent_color' => '#f43f5e',
            'support_name' => 'Cheap Support',
            'support_url' => 'https://t.me/support',
            'announcement' => 'Welcome',
            'seo_title' => 'Cheap Cloud',
            'seo_description' => 'Fast access',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame($site->id, (int) $setting->site->id);
        $this->assertSame('Cheap Cloud', $site->fresh(['setting'])->setting->site_name);
    }

    public function test_guest_context_uses_request_host_settings(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Cheap Cloud',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'landing_theme' => 'sakura',
            'accent_color' => '#f43f5e',
            'support_name' => 'Cheap Support',
            'support_url' => 'https://t.me/cheap',
            'customer_service_type' => 'chatra',
            'customer_service_id' => 'cheap-chatra-id',
            'telegram_discuss_link' => 'https://t.me/cheap_group',
            'announcement' => 'Cheap announcement',
            'seo_title' => 'Cheap SEO',
            'seo_description' => 'Cheap description',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(SiteContextService::class)->resolve(
            Request::create('/api/v1/guest/site-context', 'GET', [], [], [], ['HTTP_HOST' => 'cheap.example.test'])
        );

        $this->assertSame($site->id, $context['id']);
        $this->assertSame('cheap', $context['site_code']);
        $this->assertSame('Cheap Cloud', $context['site_name']);
        $this->assertSame('sakura', $context['landing_theme']);
        $this->assertSame('cheap.example.test', $context['domain']);
        $this->assertSame('chatra', $context['customer_service_type']);
        $this->assertSame('cheap-chatra-id', $context['customer_service_id']);
        $this->assertSame('https://t.me/cheap_group', $context['telegram_discuss_link']);
    }

    public function test_site_setting_overrides_telegram_group_link_in_comm_config(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Cheap Cloud',
            'customer_service_type' => 'crisp',
            'customer_service_id' => 'cheap-crisp-id',
            'telegram_discuss_link' => 'https://t.me/cheap_group',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v1/guest/comm/config', 'GET', [], [], [], ['HTTP_HOST' => 'cheap.example.test']);
        $config = app(SiteContextService::class)->applyToConfig([
            'app_name' => 'Platform Cloud',
            'theme_config' => [
                'customer_service_type' => 'chatra',
                'customer_service_id' => 'platform-chatra-id',
            ],
            'customer_service_type' => 'chatra',
            'customer_service_id' => 'platform-chatra-id',
            'telegram_discuss_link' => 'https://t.me/platform_group',
        ], $request);

        $this->assertSame('crisp', $config['customer_service_type']);
        $this->assertSame('cheap-crisp-id', $config['customer_service_id']);
        $this->assertSame('crisp', $config['theme_config']['customer_service_type']);
        $this->assertSame('cheap-crisp-id', $config['theme_config']['customer_service_id']);
        $this->assertSame('https://t.me/cheap_group', $config['telegram_discuss_link']);
        $this->assertSame('crisp', $config['site_context']['customer_service_type']);
        $this->assertSame('cheap-crisp-id', $config['site_context']['customer_service_id']);
        $this->assertSame('https://t.me/cheap_group', $config['site_context']['telegram_discuss_link']);
    }

    public function test_authenticated_context_prefers_user_site_over_request_host(): void
    {
        [$cheap] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $this->siteWithDomain('default', 'Default Site', 'main.example.test', true);
        $user = User::query()->create([
            'site_id' => $cheap->id,
            'email' => 'buyer@example.test',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v1/user/site-context', 'GET', [], [], [], ['HTTP_HOST' => 'main.example.test']);
        $request->setUserResolver(fn () => $user);

        $context = app(SiteContextService::class)->resolve($request, $user);

        $this->assertSame($cheap->id, $context['id']);
        $this->assertSame('user', $context['source']);
        $this->assertSame('cheap', $context['site_code']);
    }

    public function test_unmatched_host_uses_platform_context_instead_of_default_site(): void
    {
        [$defaultSite] = $this->siteWithDomain('default', 'Default Site', 'main.example.test', true);
        SiteSetting::query()->create([
            'site_id' => $defaultSite->id,
            'site_name' => 'Default Branch',
            'logo_url' => 'https://cdn.example.test/default.png',
            'landing_theme' => 'sakura',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $request = Request::create('/api/v1/guest/comm/config', 'GET', [], [], [], ['HTTP_HOST' => 'platform.example.test']);
        $context = app(SiteContextService::class)->resolve($request);
        $config = app(SiteContextService::class)->applyToConfig([
            'app_name' => 'Platform Cloud',
            'website_name' => 'Platform Cloud',
            'logo' => 'https://cdn.example.test/platform.png',
        ], $request);

        $this->assertNull($context['site_id']);
        $this->assertSame('platform', $context['site_code']);
        $this->assertSame('platform', $context['source']);
        $this->assertSame('Platform Cloud', $config['app_name']);
        $this->assertSame('Platform Cloud', $config['website_name']);
        $this->assertSame('https://cdn.example.test/platform.png', $config['logo']);
        $this->assertNull($config['site_context']['site_id']);
    }

    private function siteWithDomain(string $code, string $name, string $domain, bool $default = false): array
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $domainRow = SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return [$site, $domainRow];
    }
}
