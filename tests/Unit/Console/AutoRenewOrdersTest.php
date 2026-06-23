<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\AutoRenewOrders;
use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteOrderContext;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\AgentCenterService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AutoRenewOrdersTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->bindSynchronousBusDispatcher();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
            'try_out_plan_id' => 0,
        ]);
    }

    public function test_agent_bound_user_auto_renew_uses_agent_sale_price_and_records_context(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 2000);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createRenewingUser('buyer@example.test', $plan, 1300);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        app(AutoRenewOrders::class)->handle();

        $order = Order::query()->where('user_id', $buyer->id)->first();
        $this->assertNotNull($order);
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(1300, (int) $order->balance_amount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(1000, (int) $context->cost_amount);
        $this->assertSame(1000, (int) $agent->fresh()->balance);
    }

    public function test_agent_bound_user_auto_renew_skips_when_agent_balance_cannot_cover_cost(): void
    {
        $agent = $this->createActiveAgent('low-balance-agent@example.test', 900);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createRenewingUser('low-balance-buyer@example.test', $plan, 1300);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        app(AutoRenewOrders::class)->handle();

        $this->assertSame(0, Order::query()->where('user_id', $buyer->id)->count());
        $this->assertSame(0, AgentBalanceHold::query()->count());
        $this->assertSame(0, AgentOrderContext::query()->count());
        $this->assertSame(1300, (int) $buyer->fresh()->balance);
        $this->assertSame(900, (int) $agent->fresh()->balance);
    }

    public function test_site_user_auto_renew_uses_site_sale_price_and_records_context(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
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
        $buyer = $this->createRenewingUser('site-buyer@example.test', $plan, 1300, $site->id);

        app(AutoRenewOrders::class)->handle();

        $order = Order::query()->where('user_id', $buyer->id)->first();
        $this->assertNotNull($order);
        $this->assertSame($site->id, (int) $order->site_id);
        $this->assertSame(0, (int) $order->total_amount);
        $this->assertSame(1300, (int) $order->balance_amount);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);

        $context = SiteOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($site->id, (int) $context->site_id);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(2000, (int) $context->platform_plan_price);
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'uuid' => $email,
            'token' => md5($email),
            'balance' => $balance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createRenewingUser(string $email, Plan $plan, int $balance, ?int $siteId = null): User
    {
        return User::query()->create([
            'email' => $email,
            'uuid' => $email,
            'token' => md5($email),
            'site_id' => $siteId,
            'plan_id' => $plan->id,
            'expired_at' => time() + 3600,
            'auto_renew_enable' => true,
            'auto_renew_period' => Plan::PERIOD_MONTHLY,
            'balance' => $balance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(string $name, array $prices): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'group_id' => 1,
            'transfer_enable' => 100,
            'renew' => true,
            'sell' => true,
            'show' => true,
            'prices' => $prices,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
