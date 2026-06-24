<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\TenantPlanCatalogService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TenantPlanCatalogServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->bindTestSettings(['agent_center_allowed_plan_ids' => '']);
    }

    public function test_agent_bound_user_catalog_uses_agent_prices_before_site_filtering(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
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

        $plans = app(TenantPlanCatalogService::class)->plansForRequest(
            $this->requestForUser($buyer),
            collect([$plan]),
            $buyer
        );

        $this->assertCount(1, $plans);
        $this->assertSame($agent->id, (int) $plans[0]->agent_context['agent_user_id']);
        $this->assertEquals(13.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
    }

    public function test_agent_bound_user_catalog_ignores_site_visible_periods(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
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
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1100,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_YEARLY,
            'sale_price' => 9000,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $plans = app(TenantPlanCatalogService::class)->plansForRequest(
            $this->requestForUser($buyer),
            collect([$plan]),
            $buyer
        );

        $this->assertCount(1, $plans);
        $this->assertSame([Plan::PERIOD_MONTHLY, Plan::PERIOD_YEARLY], array_keys($plans[0]->prices));
        $this->assertSame(1100, $plans[0]->agent_sale_periods[Plan::PERIOD_MONTHLY]);
        $this->assertSame(9000, $plans[0]->agent_sale_periods[Plan::PERIOD_YEARLY]);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);
        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
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

    private function requestForUser(User $user): Request
    {
        $request = Request::create('/api/v1/user/plan/fetch', 'GET', [], [], [], [
            'HTTP_HOST' => 'platform.example.test',
        ]);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
