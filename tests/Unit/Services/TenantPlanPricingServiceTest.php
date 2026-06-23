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
use App\Services\TenantPlanPricingService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TenantPlanPricingServiceTest extends TestCase
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
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
        ]);
    }

    public function test_agent_bound_user_price_uses_agent_sale_amount_without_user_discount(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test', ['discount' => 20]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->bindAgentUser($agent, $buyer);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $price = app(TenantPlanPricingService::class)->resolveForUser($buyer, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame('agent', $price['source']);
        $this->assertSame(1300, $price['sale_amount']);
        $this->assertSame(2000, $price['platform_plan_price']);
        $this->assertSame($agent->id, (int) $price['agent_context']['agent_user_id']);
    }

    public function test_site_user_price_uses_site_sale_amount_with_user_discount(): void
    {
        $site = $this->createSite('cheap');
        $buyer = $this->createUser('buyer@example.test', [
            'site_id' => $site->id,
            'discount' => 10,
        ]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->setSitePrice($site, $plan, Plan::PERIOD_MONTHLY, 1300);

        $price = app(TenantPlanPricingService::class)->resolveForUser($buyer, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame('site', $price['source']);
        $this->assertSame(1170, $price['sale_amount']);
        $this->assertSame(2000, $price['platform_plan_price']);
        $this->assertSame($site->id, (int) $price['site_context']['site_id']);
        $this->assertSame(130, (int) $price['pricing_snapshot']['user_discount_amount']);
    }

    public function test_platform_user_price_uses_platform_amount_with_user_discount(): void
    {
        $buyer = $this->createUser('buyer@example.test', ['discount' => 10]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

        $price = app(TenantPlanPricingService::class)->resolveForUser($buyer, $plan, Plan::PERIOD_MONTHLY);

        $this->assertSame('platform', $price['source']);
        $this->assertSame(1800, $price['sale_amount']);
        $this->assertSame(2000, $price['platform_plan_price']);
        $this->assertSame(200, (int) $price['pricing_snapshot']['user_discount_amount']);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email, ['balance' => 5000]);

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

    private function bindAgentUser(User $agent, User $buyer): void
    {
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
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
}
