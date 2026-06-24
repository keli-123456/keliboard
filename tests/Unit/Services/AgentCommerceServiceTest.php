<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanOverride;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
        ]);
    }

    public function test_agent_order_creation_fails_when_available_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 499);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        try {
            app(AgentCommerceService::class)->createOrderFromRequest(
                $buyer,
                $plan,
                Plan::PERIOD_MONTHLY,
                null,
                $this->requestForHost('agent.example.test')
            );
            $this->fail('Expected insufficient site balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame(
                'The site balance is insufficient. Please contact site support.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, AgentBalanceHold::query()->count());
        $this->assertSame(0, AgentOrderContext::query()->count());
    }

    public function test_agent_order_creation_creates_order_hold_and_context(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $domain = $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);
        AgentPlanOverride::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'display_name' => '代理畅享版',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame($agent->id, (int) $order->invite_user_id);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($hold);
        $this->assertSame($agent->id, (int) $hold->agent_user_id);
        $this->assertSame(500, (int) $hold->amount);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->status);

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertSame($domain->id, (int) $context->agent_domain_id);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(500, (int) $context->cost_amount);
        $this->assertSame($hold->id, (int) $context->hold_id);
        $this->assertSame($price->id, (int) $context->pricing_snapshot['agent_plan_price_id']);
        $this->assertSame('代理畅享版', $context->pricing_snapshot['display_name']);
        $this->assertSame('Starter', $context->pricing_snapshot['platform_plan_name']);
        $this->assertSame('agent.example.test', $context->domain_snapshot['domain']);

        $this->assertSame(1, DB::table('v2_agent_user')
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $buyer->id)
            ->count());
        $this->assertSame($agent->id, (int) $buyer->fresh()->invite_user_id);
    }

    public function test_pending_holds_reduce_available_agent_balance(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 1000);
        $buyer = $this->createUser('buyer@example.test');
        $pendingOrder = $this->createOrder($buyer, 'pending-hold', Order::STATUS_PENDING);
        $capturedOrder = $this->createOrder($buyer, 'captured-hold', Order::STATUS_COMPLETED);

        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $pendingOrder->id,
            'trade_no' => 'pending-hold',
            'amount' => 700,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $capturedOrder->id,
            'trade_no' => 'captured-hold',
            'amount' => 200,
            'status' => AgentBalanceHold::STATUS_CAPTURED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame(300, app(AgentCommerceService::class)->availableBalance($agent));
    }

    public function test_cancelling_agent_order_releases_balance_hold(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame(0, app(AgentCommerceService::class)->availableBalance($agent));

        $this->assertTrue((new OrderService($order))->cancel());

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->status);
        $this->assertNotNull($hold->released_at);
        $this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context->status);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));
    }

    public function test_cancelling_agent_order_releases_hold_when_context_hold_id_is_missing(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );
        $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
        $context->hold_id = null;
        $context->save();

        $this->assertTrue((new OrderService($order))->cancel());

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->firstOrFail();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->status);
        $this->assertSame($hold->id, (int) $context->hold_id);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));
    }

    public function test_cancelled_orders_do_not_reduce_available_balance_when_hold_is_still_pending(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );
        $order->status = Order::STATUS_CANCELLED;
        $order->save();

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->status);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));

        $this->assertSame(1, app(AgentCommerceService::class)->releaseCancelledPendingHolds($agent->id));
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->fresh()->status);
    }

    public function test_non_agent_request_returns_null(): void
    {
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('platform.example.test')
        );

        $this->assertNull($order);
    }

    public function test_agent_order_creation_rejects_unconfigured_sale_period(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent price is not available');

        app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_YEARLY,
            null,
            $this->requestForHost('agent.example.test')
        );
    }

    public function test_existing_owned_user_is_not_reassigned_by_another_agent_domain(): void
    {
        $firstAgent = $this->createActiveAgent('first@example.test', 5000);
        $secondAgent = $this->createActiveAgent('second@example.test', 5000);
        $this->assignDomain($secondAgent, 'second.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $buyer->invite_user_id = $firstAgent->id;
        $buyer->save();
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($firstAgent, $plan, Plan::PERIOD_MONTHLY, 1200);
        $this->setAgentPrice($secondAgent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('second.example.test', $buyer)
        );

        $this->assertSame($firstAgent->id, (int) $buyer->fresh()->invite_user_id);
        $this->assertSame(1200, (int) $order->total_amount);
        $this->assertSame(1, AgentUser::query()->where('sub_user_id', $buyer->id)->count());
        $this->assertSame(1, AgentUser::query()
            ->where('agent_user_id', $firstAgent->id)
            ->where('sub_user_id', $buyer->id)
            ->count());
    }

    public function test_bound_user_on_main_domain_creates_agent_order_from_user_binding(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer->invite_user_id = $agent->id;
        $buyer->save();

        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('platform.example.test', $buyer)
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame($agent->id, (int) $order->invite_user_id);

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertNull($context->agent_domain_id);
        $this->assertSame('user_binding', $context->domain_snapshot['source']);
        $this->assertSame('', $context->domain_snapshot['domain']);
        $this->assertSame($price->id, (int) $context->pricing_snapshot['agent_plan_price_id']);
    }

    public function test_bound_user_on_main_domain_uses_agent_for_payment_methods(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $agentUserId = app(AgentCommerceService::class)->agentUserIdForPaymentMethods(
            $this->requestForHost('platform.example.test', $buyer)
        );

        $this->assertSame($agent->id, $agentUserId);
    }

    public function test_bound_user_on_another_agent_domain_keeps_original_agent(): void
    {
        $firstAgent = $this->createActiveAgent('first@example.test', 5000);
        $secondAgent = $this->createActiveAgent('second@example.test', 5000);
        $this->assignDomain($secondAgent, 'second.example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer->invite_user_id = $firstAgent->id;
        $buyer->save();

        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($firstAgent, $plan, Plan::PERIOD_MONTHLY, 1200);
        $this->setAgentPrice($secondAgent, $plan, Plan::PERIOD_MONTHLY, 1800);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('second.example.test', $buyer)
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertSame($firstAgent->id, (int) $context->agent_user_id);
        $this->assertSame(1200, (int) $order->total_amount);
        $this->assertSame(1, AgentUser::query()->where('sub_user_id', $buyer->id)->count());
    }

    public function test_agent_order_pricing_snapshot_contains_sale_and_platform_cost_contract(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $snapshot = $context->pricing_snapshot;

        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(500, (int) $context->cost_amount);
        $this->assertSame($price->id, (int) $snapshot['agent_plan_price_id']);
        $this->assertSame($plan->id, (int) $snapshot['plan_id']);
        $this->assertSame(Plan::PERIOD_MONTHLY, $snapshot['period']);
        $this->assertSame(1300, (int) $snapshot['sale_price']);
        $this->assertSame(1000, (int) $snapshot['platform_base_amount']);
        $this->assertSame(1000, (int) $snapshot['cost_base_amount']);
        $this->assertSame(500, (int) $snapshot['cost_amount']);
        $this->assertSame(50.0, (float) $snapshot['discount_percent']);
        $this->assertNull($snapshot['cost_site_id']);
        $this->assertSame('platform', $snapshot['cost_source']);
    }

    public function test_agent_order_cost_uses_agent_cost_site_price(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
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
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(2500, (int) $context->sale_amount);
        $this->assertSame(650, (int) $context->cost_amount);
        $this->assertSame($site->id, (int) $context->pricing_snapshot['cost_site_id']);
        $this->assertSame('site', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['platform_base_amount']);
        $this->assertSame(1300, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_agent_order_cost_falls_back_to_platform_price_when_cost_site_period_is_missing(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(2500, (int) $context->sale_amount);
        $this->assertSame(1000, (int) $context->cost_amount);
        $this->assertNull($context->pricing_snapshot['cost_site_id']);
        $this->assertSame('platform', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_agent_order_cost_falls_back_to_platform_price_when_cost_site_price_is_negative(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => -100,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(1000, (int) $context->cost_amount);
        $this->assertNull($context->pricing_snapshot['cost_site_id']);
        $this->assertSame('platform', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['platform_base_amount']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_zero_discount_agent_order_creates_zero_amount_hold_without_requiring_balance(): void
    {
        $this->bindTestSettings([
            'agent_center_discount_percent' => 0,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
        ]);

        $agent = $this->createActiveAgent('agent@example.test', 0);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertNotNull($hold);
        $this->assertNotNull($context);
        $this->assertSame(0, (int) $hold->amount);
        $this->assertSame(0, (int) $context->cost_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(0, (int) $context->pricing_snapshot['cost_amount']);
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = $this->createUser($email, $balance);

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

    private function createUser(string $email, int $balance = 0): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function assignDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
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

    private function createOrder(User $user, string $tradeNo, int $status): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => 0,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => 0,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createSite(string $code): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function setAgentPrice(User $agent, Plan $plan, string $period, int $salePrice): AgentPlanPrice
    {
        return AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host, ?User $user = null): Request
    {
        $request = Request::create('/api/v1/user/order/save', 'POST', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}
