<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentStorefrontService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentStorefrontServiceTest extends TestCase
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
        $this->bindTestSettings(['agent_center_allowed_plan_ids' => '']);
    }

    public function test_agent_price_is_returned_for_enabled_agent_period(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->assignDomain($agent, 'agent.example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 20.00,
            Plan::PERIOD_YEARLY => 120.00,
        ]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1500,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(AgentStorefrontService::class)->plansForRequest(
            $this->requestForHost('agent.example.test'),
            collect([$plan])
        );
        $sale = app(AgentStorefrontService::class)->resolveSalePrice($agent->id, $plan->id, Plan::PERIOD_MONTHLY);

        $this->assertCount(1, $plans);
        $this->assertEquals(15.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
        $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
        $this->assertSame(1500, $plans[0]->agent_sale_periods[Plan::PERIOD_MONTHLY]);
        $this->assertSame(1500, $sale['sale_amount']);
        $this->assertSame($plan->id, $sale['plan_id']);
    }

    public function test_unpriced_agent_period_is_hidden(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->assignDomain($agent, 'agent.example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $plans = app(AgentStorefrontService::class)->plansForRequest(
            $this->requestForHost('agent.example.test'),
            collect([$plan])
        );

        $this->assertCount(0, $plans);
    }

    public function test_bound_user_on_platform_request_gets_agent_sale_prices(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 20.00,
            Plan::PERIOD_YEARLY => 120.00,
        ]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(AgentStorefrontService::class)->plansForRequest(
            $this->requestForHost('platform.example.test', $buyer),
            collect([$plan])
        );

        $this->assertCount(1, $plans);
        $this->assertSame($agent->id, $plans[0]->agent_context['agent_user_id']);
        $this->assertSame('user_binding', $plans[0]->agent_context['source']);
        $this->assertEquals(13.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
        $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
    }

    public function test_price_save_rejects_plan_not_allowed_for_agents(): void
    {
        $this->bindTestSettings(['agent_center_allowed_plan_ids' => '999']);
        $agent = $this->createActiveAgent('agent@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Plan is not allowed for agents');

        app(AgentStorefrontService::class)->savePrices($agent, [[
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1500,
            'enabled' => true,
        ]]);
    }

    public function test_price_save_rejects_period_missing_on_plan(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Period is not available');

        app(AgentStorefrontService::class)->savePrices($agent, [[
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_YEARLY,
            'sale_price' => 1500,
            'enabled' => true,
        ]]);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email, 10000);

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

    private function assignDomain(User $agent, string $domain): void
    {
        AgentDomain::query()->create([
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

    private function requestForHost(string $host, ?User $user = null): Request
    {
        $request = Request::create('/api/v1/guest/plan/fetch', 'GET', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}
