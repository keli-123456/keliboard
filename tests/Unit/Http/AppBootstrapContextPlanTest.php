<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\App\BootstrapController;
use App\Http\Controllers\V1\User\UserController;
use App\Models\AgentPlanOverride;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePlanOverride;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AppBootstrapContextPlanTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindTestUrlGenerator('https://main.example.test');
        $this->bindTestSettings([
            'app_name' => 'Main Cloud',
            'app_url' => 'https://main.example.test',
            'logo' => 'https://cdn.example.test/main-logo.png',
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
            'subscription_proxy_enable' => false,
        ]);
        $this->createUserTable();
        $this->createPlanTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
    }

    public function test_bootstrap_subscribe_plan_uses_site_display_name(): void
    {
        [$site] = $this->siteWithDomain('gm', '光喵', 'gm.example.test');
        $plan = $this->createPlan('Starter');
        SitePlanOverride::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'display_name' => '光喵标准套餐',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->createUser('buyer@example.test', $plan, $site);

        $payload = $this->bootstrapPayload($this->requestForHost('gm.example.test', $user));

        $this->assertSame('光喵', $payload['data']['app']['name']);
        $this->assertSame('', $payload['data']['app']['logo']);
        $this->assertSame($site->id, $payload['data']['app']['site_context']['site_id']);
        $this->assertSame('光喵标准套餐', $payload['data']['subscribe']['plan']['name']);
        $this->assertSame('光喵标准套餐', $payload['data']['subscribe']['plan']['site_display_name']);
        $this->assertSame('Starter', $payload['data']['subscribe']['plan']['platform_name']);
        $this->assertSame($site->id, $payload['data']['subscribe']['plan']['site_context']['site_id']);

        $subscribePayload = $this->subscribePayload($this->requestForHost('gm.example.test', $user));
        $this->assertSame('光喵标准套餐', $subscribePayload['data']['plan']['name']);
        $this->assertSame('光喵标准套餐', $subscribePayload['data']['plan']['site_display_name']);
        $this->assertSame('Starter', $subscribePayload['data']['plan']['platform_name']);
    }

    public function test_bootstrap_subscribe_plan_uses_agent_display_name_for_bound_user(): void
    {
        [$site] = $this->siteWithDomain('gm', '光喵', 'gm.example.test');
        $plan = $this->createPlan('Starter');
        SitePlanOverride::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'display_name' => '光喵标准套餐',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $agent = $this->createAgent('agent@example.test');
        $user = $this->createUser('buyer@example.test', $plan, $site);
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentPlanOverride::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'display_name' => '代理专属套餐',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $payload = $this->bootstrapPayload($this->requestForHost('main.example.test', $user));

        $this->assertSame('光喵', $payload['data']['app']['name']);
        $this->assertSame($site->id, $payload['data']['app']['site_context']['site_id']);
        $this->assertSame('代理专属套餐', $payload['data']['subscribe']['plan']['name']);
        $this->assertSame('代理专属套餐', $payload['data']['subscribe']['plan']['agent_display_name']);
        $this->assertSame('光喵标准套餐', $payload['data']['subscribe']['plan']['site_display_name']);
        $this->assertSame('Starter', $payload['data']['subscribe']['plan']['platform_name']);
        $this->assertSame($agent->id, $payload['data']['subscribe']['plan']['agent_context']['agent_user_id']);

        $subscribePayload = $this->subscribePayload($this->requestForHost('main.example.test', $user));
        $this->assertSame('代理专属套餐', $subscribePayload['data']['plan']['name']);
        $this->assertSame('代理专属套餐', $subscribePayload['data']['plan']['agent_display_name']);
        $this->assertSame('光喵标准套餐', $subscribePayload['data']['plan']['site_display_name']);
    }

    private function bootstrapPayload(Request $request): array
    {
        return app(BootstrapController::class)->bootstrap($request)->getData(true);
    }

    private function subscribePayload(Request $request): array
    {
        return app(UserController::class)->getSubscribe($request)->getData(true);
    }

    private function siteWithDomain(string $code, string $name, string $host): array
    {
        $site = Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $host,
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return [$site, $domain];
    }

    private function createPlan(string $name): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => [Plan::PERIOD_MONTHLY => 20.00],
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

    private function createUser(string $email, Plan $plan, Site $site): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'site_id' => $site->id,
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'plan_id' => $plan->id,
            'transfer_enable' => 0,
            'expired_at' => null,
            'u' => 0,
            'd' => 0,
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createAgent(string $email): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 10000,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

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

    private function requestForHost(string $host, User $user): Request
    {
        $request = Request::create('/api/v1/app/bootstrap', 'GET', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
        $request->setUserResolver(fn (): User => $user);

        return $request;
    }
}
