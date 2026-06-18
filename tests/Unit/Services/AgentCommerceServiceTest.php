<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceService;
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
        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => 1,
            'trade_no' => 'pending-hold',
            'amount' => 700,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => 2,
            'trade_no' => 'captured-hold',
            'amount' => 200,
            'status' => AgentBalanceHold::STATUS_CAPTURED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame(300, app(AgentCommerceService::class)->availableBalance($agent));
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
