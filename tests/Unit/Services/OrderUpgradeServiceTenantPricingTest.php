<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteOrderContext;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\OrderUpgradeService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class OrderUpgradeServiceTenantPricingTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createOrderUpgradeQuoteTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'commission_first_time_enable' => 1,
            'invite_commission' => 10,
            'plan_change_enable' => 1,
            'upgrade_v2_enable' => true,
            'upgrade_credit_coeffs' => [
                Plan::PERIOD_MONTHLY => 1,
                Plan::PERIOD_QUARTERLY => 1,
                Plan::PERIOD_HALF_YEARLY => 1,
                Plan::PERIOD_YEARLY => 1,
                Plan::PERIOD_TWO_YEARLY => 1,
                Plan::PERIOD_THREE_YEARLY => 1,
            ],
            'upgrade_usage_penalty_rules' => [
                ['max_usage_percentage' => 100, 'coefficient' => 1],
            ],
            'upgrade_min_pay_amount' => 100,
            'upgrade_min_pay_ratio' => 0,
            'upgrade_max_credit_cap_ratio' => 1,
        ]);
    }

    public function test_agent_domain_discount_upgrade_uses_agent_price_and_creates_agent_hold(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $domain = $this->assignAgentDomain($agent, 'agent.example.test');
        [$buyer, $sourcePlan, $targetPlan, $sourceOrder] = $this->createUpgradeableSubscription();
        $this->setAgentPrice($agent, $targetPlan, Plan::PERIOD_MONTHLY, 1300);

        $preview = app(OrderUpgradeService::class)->previewUpgrade(
            $buyer,
            $targetPlan,
            Plan::PERIOD_MONTHLY,
            $this->requestForHost('agent.example.test')
        );

        $this->assertTrue($preview['allow_upgrade']);
        $this->assertSame(1300, (int) $preview['pricing_detail']['target_price']);
        $this->assertSame('agent', $preview['pricing_detail']['tenant_source']);

        $order = app(OrderUpgradeService::class)->confirmUpgrade($buyer, (string) $preview['quote_token']);
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();

        $this->assertSame(Order::TYPE_DISCOUNT_UPGRADE, (int) $order->type);
        $this->assertSame($targetPlan->id, (int) $order->plan_id);
        $this->assertSame($agent->id, (int) $order->invite_user_id);
        $this->assertSame(0, (int) $order->commission_balance);
        $this->assertLessThan(1300, (int) $order->total_amount);
        $this->assertSame($sourceOrder->id, (int) $order->upgrade_source_order_ids[0]);

        $this->assertNotNull($context);
        $this->assertNotNull($hold);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertSame($domain->id, (int) $context->agent_domain_id);
        $this->assertSame((int) $order->total_amount, (int) $context->sale_amount);
        $this->assertSame((int) $hold->amount, (int) $context->cost_amount);
        $this->assertGreaterThan(0, (int) $context->cost_amount);
        $this->assertLessThan(1000, (int) $context->cost_amount);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->status);
        $this->assertSame('discount_upgrade', $context->pricing_snapshot['order_type']);
        $this->assertSame('agent.example.test', $context->domain_snapshot['domain']);

        $this->assertSame(1, AgentUser::query()
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $buyer->id)
            ->count());
    }

    public function test_site_domain_discount_upgrade_uses_site_price_and_records_site_context(): void
    {
        $site = $this->createSite('cheap', 'Cheap Site');
        $domain = $this->assignSiteDomain($site, 'cheap.example.test');
        [$buyer, , $targetPlan] = $this->createUpgradeableSubscription();
        $this->setSitePrice($site, $targetPlan, Plan::PERIOD_MONTHLY, 1200);

        $preview = app(OrderUpgradeService::class)->previewUpgrade(
            $buyer,
            $targetPlan,
            Plan::PERIOD_MONTHLY,
            $this->requestForHost('cheap.example.test')
        );

        $this->assertTrue($preview['allow_upgrade']);
        $this->assertSame(1200, (int) $preview['pricing_detail']['target_price']);
        $this->assertSame('site', $preview['pricing_detail']['tenant_source']);

        $order = app(OrderUpgradeService::class)->confirmUpgrade($buyer, (string) $preview['quote_token']);
        $context = SiteOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertSame($site->id, (int) $order->site_id);
        $this->assertLessThan(1200, (int) $order->total_amount);
        $this->assertNull(AgentOrderContext::query()->where('order_id', $order->id)->first());

        $this->assertNotNull($context);
        $this->assertSame($site->id, (int) $context->site_id);
        $this->assertSame($domain->id, (int) $context->site_domain_id);
        $this->assertSame(1200, (int) $context->sale_amount);
        $this->assertSame(2000, (int) $context->platform_plan_price);
        $this->assertSame('discount_upgrade', $context->pricing_snapshot['order_type']);
        $this->assertSame('cheap.example.test', $context->domain_snapshot['domain']);
    }

    private function createUpgradeableSubscription(): array
    {
        $sourcePlan = $this->createPlan('Basic', [Plan::PERIOD_MONTHLY => 3.00]);
        $targetPlan = $this->createPlan('Plus', [Plan::PERIOD_MONTHLY => 20.00]);
        $sourcePlan->upgrade_to_plan_ids = [$targetPlan->id];
        $sourcePlan->save();

        $buyer = $this->createUser('buyer@example.test', [
            'plan_id' => $sourcePlan->id,
            'transfer_enable' => $sourcePlan->transfer_enable * 1073741824,
            'expired_at' => time() + 25 * 86400,
        ]);

        $sourceOrder = Order::query()->create([
            'user_id' => $buyer->id,
            'plan_id' => $sourcePlan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'source-' . $buyer->id,
            'total_amount' => 300,
            'balance_amount' => 0,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => time() - 3600,
            'updated_at' => time() - 3600,
        ]);

        return [$buyer, $sourcePlan, $targetPlan, $sourceOrder];
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, ['balance' => $balance]);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
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

    private function createSite(string $code, string $name): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignAgentDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignSiteDomain(Site $site, string $domain): SiteDomain
    {
        return SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function setAgentPrice(User $agent, Plan $plan, string $period, int $salePrice): void
    {
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function setSitePrice(Site $site, Plan $plan, string $period, int $salePrice): void
    {
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host): Request
    {
        return Request::create('/api/v1/user/order/upgrade/preview', 'POST', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
    }
}
