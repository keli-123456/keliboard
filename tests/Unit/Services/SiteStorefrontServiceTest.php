<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePlanOverride;
use App\Models\SitePlanPrice;
use App\Services\SiteStorefrontService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteStorefrontServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPlanTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_non_default_site_uses_enabled_site_price_and_hides_unpriced_periods(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 20.00,
            Plan::PERIOD_YEARLY => 120.00,
        ]);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(SiteStorefrontService::class)->plansForRequest(
            $this->requestForHost('cheap.example.test'),
            collect([$plan])
        );

        $this->assertCount(1, $plans);
        $this->assertEquals(13.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
        $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
        $this->assertSame(1300, $plans[0]->site_sale_periods[Plan::PERIOD_MONTHLY]);
        $this->assertSame($site->id, $plans[0]->site_context['site_id']);

        $resource = PlanResource::make($plans[0])->toArray($this->requestForHost('cheap.example.test'));
        $this->assertSame(1300, $resource['prices'][Plan::PERIOD_MONTHLY]);
        $this->assertSame(1300, $resource['month_price']);
        $this->assertSame($site->id, $resource['site_context']['site_id']);
        $this->assertSame(1300, $resource['site_sale_periods'][Plan::PERIOD_MONTHLY]);
    }

    public function test_platform_host_uses_platform_prices_without_site_context(): void
    {
        $this->siteWithDomain('default', 'Default Site', 'main.example.test', true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $plans = app(SiteStorefrontService::class)->plansForRequest(
            $this->requestForHost('platform.example.test'),
            collect([$plan])
        );

        $this->assertCount(1, $plans);
        $this->assertEquals(20.00, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
        $this->assertNull($plans[0]->getAttribute('site_context'));
        $this->assertNull($plans[0]->getAttribute('site_sale_periods'));
    }

    public function test_site_display_name_overrides_platform_plan_name(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SitePlanOverride::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'display_name' => '光喵入门版',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(SiteStorefrontService::class)->plansForRequest(
            $this->requestForHost('cheap.example.test'),
            collect([$plan])
        );
        $resource = PlanResource::make($plans[0])->toArray($this->requestForHost('cheap.example.test'));

        $this->assertSame('光喵入门版', $resource['name']);
        $this->assertSame('光喵入门版', $resource['display_name']);
        $this->assertSame('光喵入门版', $resource['site_display_name']);
        $this->assertSame('Starter', $resource['platform_name']);
    }

    public function test_non_default_site_can_sell_hidden_plan_with_enabled_site_price(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $visiblePlan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $hiddenPlan = $this->createPlan('Site Only 500G', [Plan::PERIOD_YEARLY => 50.00], false);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $hiddenPlan->id,
            'period' => Plan::PERIOD_YEARLY,
            'sale_price' => 5000,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SitePlanOverride::query()->create([
            'site_id' => $site->id,
            'plan_id' => $hiddenPlan->id,
            'display_name' => '标准套餐',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(SiteStorefrontService::class)->plansForRequest(
            $this->requestForHost('cheap.example.test'),
            collect([$visiblePlan])
        );

        $this->assertCount(1, $plans);
        $this->assertSame($hiddenPlan->id, $plans[0]->id);
        $this->assertSame('标准套餐', $plans[0]->site_display_name);
        $this->assertEquals(50.0, $plans[0]->prices[Plan::PERIOD_YEARLY]);
        $this->assertSame(5000, $plans[0]->site_sale_periods[Plan::PERIOD_YEARLY]);
    }

    public function test_site_display_name_can_be_applied_to_current_plan_without_enabled_sale_price(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        SitePlanOverride::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'display_name' => '光喵当前套餐',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $decorated = app(SiteStorefrontService::class)->applyDisplayNameForRequest(
            $this->requestForHost('cheap.example.test'),
            $plan
        );
        $resource = PlanResource::make($decorated)->toArray($this->requestForHost('cheap.example.test'));

        $this->assertSame('光喵当前套餐', $resource['name']);
        $this->assertSame('光喵当前套餐', $resource['display_name']);
        $this->assertSame('光喵当前套餐', $resource['site_display_name']);
        $this->assertSame('Starter', $resource['platform_name']);
        $this->assertEquals(2000, $resource['month_price']);
        $this->assertSame($site->id, $resource['site_context']['site_id']);
    }

    public function test_non_default_site_rejects_missing_price_for_checkout(): void
    {
        [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Site price is not available');

        app(SiteStorefrontService::class)->resolveSalePrice($site->id, $plan->id, Plan::PERIOD_MONTHLY);
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

    private function createPlan(string $name, array $prices, bool $show = true): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => $show,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host): Request
    {
        return Request::create('/api/v1/guest/plan/fetch', 'GET', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
    }
}
