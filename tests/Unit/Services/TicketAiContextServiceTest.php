<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentOrderContext;
use App\Models\AgentDomain;
use App\Models\AgentSiteSetting;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketAiContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TicketAiContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindTestSettings([
            'app_name' => 'Platform Cloud',
            'app_url' => 'https://platform.example.test',
        ]);
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
        $this->createTicketTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createRiskEventTable();
    }

    public function test_platform_context_contains_operational_facts_without_private_identifiers_or_raw_ip(): void
    {
        $plan = Plan::query()->create(['name' => '标准套餐']);
        $user = $this->createUser((int) $plan->id);
        $ticket = $this->createTicket($user, ['subject' => '订阅无法使用']);
        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => '我的邮箱 private@example.test，token=abcdef0123456789abcdef0123456789',
            'created_at' => 100,
            'updated_at' => 100,
        ]);
        TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => 999,
            'message' => '请重新导入订阅。',
            'created_at' => 101,
            'updated_at' => 101,
        ]);
        Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'total_amount' => 1300,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'paid_at' => 90,
            'created_at' => 80,
            'updated_at' => 90,
        ]);
        DB::table('v2_subscription_control_event')->insert([
            'event_id' => 'risk-1',
            'user_id' => $user->id,
            'email' => $user->email,
            'code' => 'multi_ip',
            'action' => 'reset_token_uuid',
            'client_ip' => '203.0.113.9',
            'risk_score' => 72,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = (new TicketAiContextService())->build($ticket, 12, '联系 private@example.test');
        $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('platform', $context['scope']['type']);
        $this->assertSame('Platform Cloud', $context['scope']['brand_name']);
        $this->assertSame('user-' . $user->id, $context['user']['user_ref']);
        $this->assertSame('标准套餐', $context['subscription']['plan_name']);
        $this->assertSame(1300, $context['orders'][0]['total_amount']);
        $this->assertSame('high', $context['risk']['risk_level']);
        $this->assertSame(1, $context['risk']['event_count']);
        $this->assertSame(1, $context['risk']['reset_count']);
        $this->assertStringNotContainsString($user->email, $serialized);
        $this->assertStringNotContainsString($user->token, $serialized);
        $this->assertStringNotContainsString($user->uuid, $serialized);
        $this->assertStringNotContainsString('203.0.113.9', $serialized);
        $this->assertStringContainsString('[EMAIL]', $serialized);
        $this->assertSame('user', $context['conversation'][0]['role']);
        $this->assertSame('assistant', $context['conversation'][1]['role']);
    }

    public function test_site_context_uses_site_brand_and_primary_domain(): void
    {
        $site = Site::query()->create([
            'code' => 'miaosu',
            'name' => '秒速云原名',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => '秒速云',
            'enabled' => true,
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'dash.miaosu.example',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $user = $this->createUser(null, (int) $site->id);
        $ticket = $this->createTicket($user, ['site_id' => $site->id]);

        $context = (new TicketAiContextService())->build($ticket, 12, null);

        $this->assertSame('site', $context['scope']['type']);
        $this->assertSame((int) $site->id, $context['scope']['site_id']);
        $this->assertSame('秒速云', $context['scope']['brand_name']);
        $this->assertSame('dash.miaosu.example', $context['scope']['domain']);
    }

    public function test_missing_site_record_keeps_site_scope_and_excludes_platform_orders(): void
    {
        $missingSiteId = 999999;
        $plan = Plan::query()->create(['name' => '主站套餐']);
        $user = $this->createUser(null, $missingSiteId);
        Order::query()->create([
            'site_id' => null,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'total_amount' => 1300,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => 100,
            'updated_at' => 100,
        ]);

        $context = (new TicketAiContextService())->build(
            $this->createTicket($user, ['site_id' => $missingSiteId]),
            12,
            null
        );

        $this->assertSame('site', $context['scope']['type']);
        $this->assertSame($missingSiteId, $context['scope']['site_id']);
        $this->assertSame('', $context['scope']['brand_name']);
        $this->assertSame('', $context['scope']['domain']);
        $this->assertSame([], $context['orders']);
    }

    public function test_agent_domain_context_takes_precedence_and_never_leaks_platform_brand(): void
    {
        $site = Site::query()->create([
            'code' => 'source',
            'name' => '来源分站',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $agent = $this->createUser();
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'site_name' => '樱花代理站',
            'enabled' => true,
        ]);
        $user = $this->createUser(null, (int) $site->id);
        $ticket = $this->createTicket($user, [
            'site_id' => $site->id,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
        ]);

        $context = (new TicketAiContextService())->build($ticket, 12, null);
        $serialized = json_encode($context, JSON_UNESCAPED_UNICODE);

        $this->assertSame('agent', $context['scope']['type']);
        $this->assertSame((int) $agent->id, $context['scope']['agent_user_id']);
        $this->assertSame((int) $domain->id, $context['scope']['agent_domain_id']);
        $this->assertSame('樱花代理站', $context['scope']['brand_name']);
        $this->assertSame('agent.example.test', $context['scope']['domain']);
        $this->assertStringNotContainsString('Platform Cloud', $serialized);
    }

    public function test_agent_domain_owned_by_another_agent_is_not_used_for_branding(): void
    {
        $agentA = $this->createUser();
        $agentB = $this->createUser();
        $foreignDomain = AgentDomain::query()->create([
            'agent_user_id' => $agentB->id,
            'domain' => 'foreign-agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agentB->id,
            'agent_domain_id' => $foreignDomain->id,
            'site_name' => '其他代理品牌',
            'enabled' => true,
        ]);
        $user = $this->createUser();

        $context = (new TicketAiContextService())->build($this->createTicket($user, [
            'agent_user_id' => $agentA->id,
            'agent_domain_id' => $foreignDomain->id,
        ]), 12, null);

        $this->assertSame('agent', $context['scope']['type']);
        $this->assertSame((int) $agentA->id, $context['scope']['agent_user_id']);
        $this->assertSame((int) $foreignDomain->id, $context['scope']['agent_domain_id']);
        $this->assertSame('代理站点', $context['scope']['brand_name']);
        $this->assertSame('', $context['scope']['domain']);
    }

    public function test_recent_orders_are_restricted_to_current_site_scope(): void
    {
        $siteA = Site::query()->create([
            'code' => 'site-a',
            'name' => 'Site A',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $siteB = Site::query()->create([
            'code' => 'site-b',
            'name' => 'Site B',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $planA = Plan::query()->create(['name' => 'Site A Plan']);
        $planB = Plan::query()->create(['name' => 'Site B Plan']);
        $user = $this->createUser(null, (int) $siteA->id);
        Order::query()->create([
            'site_id' => $siteA->id,
            'user_id' => $user->id,
            'plan_id' => $planA->id,
            'period' => 'month_price',
            'total_amount' => 1100,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => 100,
            'updated_at' => 100,
        ]);
        Order::query()->create([
            'site_id' => $siteB->id,
            'user_id' => $user->id,
            'plan_id' => $planB->id,
            'period' => 'year_price',
            'total_amount' => 9900,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED,
            'created_at' => 200,
            'updated_at' => 200,
        ]);

        $context = (new TicketAiContextService())->build(
            $this->createTicket($user, ['site_id' => $siteA->id]),
            12,
            null
        );

        $this->assertCount(1, $context['orders']);
        $this->assertSame('Site A Plan', $context['orders'][0]['plan_name']);
        $this->assertSame(1100, $context['orders'][0]['total_amount']);
    }

    public function test_recent_orders_are_restricted_to_current_agent_scope(): void
    {
        $site = Site::query()->create([
            'code' => 'agent-source',
            'name' => 'Agent Source',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
        ]);
        $agentA = $this->createUser();
        $agentB = $this->createUser();
        $domainA = AgentDomain::query()->create([
            'agent_user_id' => $agentA->id,
            'domain' => 'agent-a.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $domainB = AgentDomain::query()->create([
            'agent_user_id' => $agentB->id,
            'domain' => 'agent-b.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
        ]);
        $planA = Plan::query()->create(['name' => 'Agent A Plan']);
        $planB = Plan::query()->create(['name' => 'Agent B Plan']);
        $user = $this->createUser(null, (int) $site->id);
        $orderA = Order::query()->create([
            'site_id' => $site->id, 'user_id' => $user->id, 'plan_id' => $planA->id,
            'period' => 'month_price', 'total_amount' => 1200, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'created_at' => 100, 'updated_at' => 100,
        ]);
        $orderB = Order::query()->create([
            'site_id' => $site->id, 'user_id' => $user->id, 'plan_id' => $planB->id,
            'period' => 'year_price', 'total_amount' => 8800, 'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_COMPLETED, 'created_at' => 200, 'updated_at' => 200,
        ]);
        AgentOrderContext::query()->create([
            'order_id' => $orderA->id,
            'trade_no' => 'agent-a-order',
            'agent_user_id' => $agentA->id,
            'agent_domain_id' => $domainA->id,
            'status' => AgentOrderContext::STATUS_PAID,
        ]);
        AgentOrderContext::query()->create([
            'order_id' => $orderB->id,
            'trade_no' => 'agent-b-order',
            'agent_user_id' => $agentB->id,
            'agent_domain_id' => $domainB->id,
            'status' => AgentOrderContext::STATUS_PAID,
        ]);

        $context = (new TicketAiContextService())->build($this->createTicket($user, [
            'site_id' => $site->id,
            'agent_user_id' => $agentA->id,
            'agent_domain_id' => $domainA->id,
        ]), 12, null);

        $this->assertCount(1, $context['orders']);
        $this->assertSame('Agent A Plan', $context['orders'][0]['plan_name']);
        $this->assertSame(1200, $context['orders'][0]['total_amount']);
    }

    private function createUser(?int $planId = null, ?int $siteId = null): User
    {
        return User::query()->create([
            'site_id' => $siteId,
            'email' => 'private' . uniqid() . '@example.test',
            'password' => 'password',
            'token' => bin2hex(random_bytes(16)),
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
            'plan_id' => $planId,
            'transfer_enable' => 1000,
            'u' => 100,
            'd' => 200,
            'expired_at' => 2000000000,
            'banned' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createTicket(User $user, array $attributes = []): Ticket
    {
        return Ticket::query()->create(array_merge([
            'user_id' => $user->id,
            'subject' => '测试工单',
            'level' => 1,
            'status' => Ticket::STATUS_OPENING,
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }

    private function createPlanTable(): void
    {
        Schema::create('v2_plan', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createOrderTable(): void
    {
        Schema::create('v2_order', function (Blueprint $table): void {
            $table->id();
            $table->integer('site_id')->nullable();
            $table->integer('user_id');
            $table->integer('plan_id')->nullable();
            $table->string('period')->nullable();
            $table->integer('total_amount')->default(0);
            $table->integer('type')->default(0);
            $table->integer('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createRiskEventTable(): void
    {
        Schema::create('v2_subscription_control_event', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id');
            $table->integer('user_id')->nullable();
            $table->string('email')->nullable();
            $table->string('code')->nullable();
            $table->string('action')->nullable();
            $table->string('client_ip')->nullable();
            $table->integer('risk_score')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }
}
