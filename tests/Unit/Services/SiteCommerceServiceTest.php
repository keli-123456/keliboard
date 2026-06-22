<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteOrderContext;
use App\Models\SitePayment;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\SiteCommerceService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteCommerceServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindTestHasher();
        $this->bindTestSettings([
            'plan_change_enable' => 1,
            'try_out_plan_id' => 0,
        ]);
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_site_order_uses_site_price_and_records_context(): void
    {
        $site = $this->siteWithDomain('cheap', 'cheap.example.test', false);
        $user = $this->createUser('buyer@example.test', $site);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->sitePrice($site, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(SiteCommerceService::class)->createOrderFromRequest(
            $user,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForUser($user, 'cheap.example.test')
        );

        $context = SiteOrderContext::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($site->id, $order->site_id);
        $this->assertSame(1300, $order->total_amount);
        $this->assertSame($site->id, $context->site_id);
        $this->assertSame(1300, $context->sale_amount);
        $this->assertSame(2000, $context->platform_plan_price);
        $this->assertSame('cheap.example.test', $context->domain_snapshot['domain']);
    }

    public function test_site_payment_methods_inherit_enabled_platform_methods(): void
    {
        $site = $this->siteWithDomain('cheap', 'cheap.example.test', false);
        $user = $this->createUser('buyer@example.test', $site);
        $first = $this->createPayment('First Pay');
        $second = $this->createPayment('Second Pay');
        SitePayment::query()->create([
            'site_id' => $site->id,
            'payment_id' => $first->id,
            'enabled' => true,
            'sort' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SitePayment::query()->create([
            'site_id' => $site->id,
            'payment_id' => $second->id,
            'enabled' => false,
            'sort' => 2,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $methods = app(SiteCommerceService::class)->availablePaymentMethodsForRequest(
            $this->requestForUser($user, 'cheap.example.test')
        );

        $this->assertSame([$first->id, $second->id], $methods->pluck('id')->all());
    }

    public function test_site_order_allows_enabled_platform_payment_even_when_site_mapping_disables_it(): void
    {
        $site = $this->siteWithDomain('cheap', 'cheap.example.test', false);
        $user = $this->createUser('buyer@example.test', $site);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->sitePrice($site, $plan, Plan::PERIOD_MONTHLY, 1300);
        $payment = $this->createPayment('Platform Pay');
        SitePayment::query()->create([
            'site_id' => $site->id,
            'payment_id' => $payment->id,
            'enabled' => false,
            'sort' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = app(SiteCommerceService::class)->createOrderFromRequest(
            $user,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForUser($user, 'cheap.example.test')
        );

        app(SiteCommerceService::class)->assertPaymentAvailableForOrder($order, $payment);
        $this->assertTrue(true);
    }

    private function siteWithDomain(string $code, string $host, bool $default): Site
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $site;
    }

    private function createUser(string $email, Site $site): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'site_id' => $site->id,
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'expired_at' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(string $name, array $prices): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function sitePrice(Site $site, Plan $plan, string $period, int $salePrice): SitePlanPrice
    {
        return SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPayment(string $name): Payment
    {
        return Payment::query()->create([
            'uuid' => strtolower(str_replace(' ', '-', $name)) . '-uuid',
            'payment' => 'dummy',
            'name' => $name,
            'icon' => '',
            'config' => [],
            'notify_domain' => null,
            'handling_fee_fixed' => null,
            'handling_fee_percent' => null,
            'enable' => true,
            'owner_type' => Payment::OWNER_PLATFORM,
            'owner_id' => null,
            'owner_domain_id' => null,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForUser(User $user, string $host): Request
    {
        $request = Request::create('https://' . $host . '/api/v1/user/order/save', 'POST', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
