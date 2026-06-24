<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Site;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentOperationsService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentOperationsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createPlanTable();
    }

    public function test_agent_summary_reports_balance_holds_sales_cost_and_margin(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createAgentOrder($agent, [
            'trade_no' => 'paid-order',
            'sale_amount' => 1300,
            'cost_amount' => 800,
            'context_status' => AgentOrderContext::STATUS_PAID,
            'order_status' => Order::STATUS_COMPLETED,
            'hold_status' => AgentBalanceHold::STATUS_CAPTURED,
            'captured_at' => time(),
        ]);
        $this->createAgentOrder($agent, [
            'trade_no' => 'pending-order',
            'sale_amount' => 1500,
            'cost_amount' => 900,
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'order_status' => Order::STATUS_PENDING,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
        ]);

        $summary = app(AgentOperationsService::class)->agentSummary($agent);

        $this->assertSame(10000, $summary['balance']);
        $this->assertSame(9100, $summary['available_balance']);
        $this->assertSame(900, $summary['pending_hold_total']);
        $this->assertSame(1300, $summary['month_sales_total']);
        $this->assertSame(800, $summary['month_cost_total']);
        $this->assertSame(500, $summary['month_margin_total']);
        $this->assertSame(1, $summary['pending_order_count']);
        $this->assertSame(0, $summary['abnormal_order_count']);
    }

    public function test_agent_summary_ignores_cancelled_orders_with_stale_pending_holds(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createAgentOrder($agent, [
            'trade_no' => 'cancelled-stale-hold',
            'sale_amount' => 1500,
            'cost_amount' => 900,
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'order_status' => Order::STATUS_CANCELLED,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
        ]);

        $summary = app(AgentOperationsService::class)->agentSummary($agent);

        $this->assertSame(10000, $summary['available_balance']);
        $this->assertSame(0, $summary['pending_hold_total']);
        $this->assertSame(0, $summary['pending_order_count']);
        $this->assertSame(1, $summary['abnormal_order_count']);
    }

    public function test_agent_orders_are_scoped_to_current_agent(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $this->createAgentOrder($agent, ['trade_no' => 'own-trade']);
        $this->createAgentOrder($otherAgent, ['trade_no' => 'other-trade']);

        $orders = app(AgentOperationsService::class)->agentOrders($agent, [
            'page' => 1,
            'page_size' => 20,
        ]);

        $this->assertSame(1, $orders['total']);
        $this->assertSame(['own-trade'], array_column($orders['data'], 'trade_no'));
    }

    public function test_order_filters_by_status_abnormal_keyword_domain_and_payment(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domain = $this->createDomain($agent, 'shop.example.test');
        $payment = $this->createPayment($agent, $domain->id, true);
        $this->createAgentOrder($agent, [
            'trade_no' => 'target-trade',
            'buyer_email' => 'target-buyer@example.test',
            'domain' => $domain,
            'payment' => $payment,
            'period' => Plan::PERIOD_MONTHLY,
            'pricing_period' => Plan::PERIOD_YEARLY,
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
            'expires_at' => time() - 10,
        ]);
        $this->createAgentOrder($agent, [
            'trade_no' => 'noise-trade',
            'buyer_email' => 'noise@example.test',
        ]);

        $orders = app(AgentOperationsService::class)->agentOrders($agent, [
            'status' => AgentOrderContext::STATUS_PENDING,
            'abnormal' => true,
            'domain_id' => $domain->id,
            'payment_id' => $payment->id,
            'keyword' => 'target-buyer',
            'page' => 1,
            'page_size' => 20,
        ]);

        $this->assertSame(1, $orders['total']);
        $this->assertSame('target-trade', $orders['data'][0]['trade_no']);
        $this->assertSame(Plan::PERIOD_YEARLY, $orders['data'][0]['period']);
        $this->assertContains('hold_expired', $orders['data'][0]['abnormal_flags']);
        $this->assertSame($domain->id, $orders['data'][0]['agent_domain_id']);
        $this->assertSame($payment->id, $orders['data'][0]['payment_id']);
    }

    public function test_agent_order_detail_rejects_other_agent_order(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $this->createAgentOrder($otherAgent, ['trade_no' => 'other-trade']);

        $this->expectException(ApiException::class);

        app(AgentOperationsService::class)->agentOrderDetail($agent, 'other-trade');
    }

    public function test_order_row_prefers_pricing_snapshot_period(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createAgentOrder($agent, [
            'trade_no' => 'snapshot-period-trade',
            'period' => 'month_price',
            'pricing_period' => 'monthly',
        ]);

        $detail = app(AgentOperationsService::class)->agentOrderDetail($agent, 'snapshot-period-trade');

        $this->assertSame('monthly', $detail['period']);
    }

    public function test_admin_agents_include_finance_and_readiness_counts(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $costSite = $this->createSite('gm', '光喵');
        AgentProfile::query()->where('user_id', $agent->id)->update([
            'cost_site_id' => $costSite->id,
        ]);
        $domain = $this->createDomain($agent, 'shop.example.test');
        $this->createDomain($agent, 'disabled.example.test', AgentDomain::STATUS_DISABLED);
        $this->createPayment($agent, null, true);
        $this->createPayment($agent, $domain->id, true);
        $this->createPayment($agent, null, false);
        $this->createAgentOrder($agent, [
            'trade_no' => 'paid-order',
            'sale_amount' => 1300,
            'cost_amount' => 800,
            'context_status' => AgentOrderContext::STATUS_PAID,
            'order_status' => Order::STATUS_COMPLETED,
            'hold_status' => AgentBalanceHold::STATUS_CAPTURED,
        ]);
        $this->createAgentOrder($agent, [
            'trade_no' => 'pending-order',
            'sale_amount' => 1500,
            'cost_amount' => 900,
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
        ]);

        $agents = app(AgentOperationsService::class)->adminAgents([
            'page' => 1,
            'page_size' => 20,
        ]);

        $this->assertSame(1, $agents['total']);
        $this->assertSame($agent->id, $agents['data'][0]['agent_user_id']);
        $this->assertSame('agent@example.test', $agents['data'][0]['agent_email']);
        $this->assertSame(9100, $agents['data'][0]['available_balance']);
        $this->assertSame(2, $agents['data'][0]['enabled_payment_count']);
        $this->assertSame(1, $agents['data'][0]['active_domain_count']);
        $this->assertSame([
            'site_id' => $costSite->id,
            'code' => 'gm',
            'name' => '光喵',
            'is_platform' => false,
        ], $agents['data'][0]['cost_site']);
    }

    public function test_admin_agent_detail_reports_platform_cost_site_by_default(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);

        $detail = app(AgentOperationsService::class)->adminAgentDetail($agent->id);

        $this->assertSame([
            'site_id' => null,
            'code' => null,
            'name' => '主站',
            'is_platform' => true,
        ], $detail['cost_site']);
    }

    public function test_update_agent_cost_site_switches_between_site_and_platform(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $site = $this->createSite('nnm', '囡囡喵');

        $siteResult = app(AgentOperationsService::class)->updateAgentCostSite($agent->id, $site->id);

        $this->assertSame($site->id, AgentProfile::query()->where('user_id', $agent->id)->value('cost_site_id'));
        $this->assertSame([
            'site_id' => $site->id,
            'code' => 'nnm',
            'name' => '囡囡喵',
            'is_platform' => false,
        ], $siteResult['cost_site']);

        $platformResult = app(AgentOperationsService::class)->updateAgentCostSite($agent->id, null);

        $this->assertNull(AgentProfile::query()->where('user_id', $agent->id)->value('cost_site_id'));
        $this->assertTrue($platformResult['cost_site']['is_platform']);
        $this->assertNull($platformResult['cost_site']['site_id']);
    }

    public function test_update_agent_cost_site_rejects_default_and_disabled_sites(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $defaultSite = $this->createSite('default', '默认站点', Site::STATUS_ACTIVE, true);
        $disabledSite = $this->createSite('disabled', '停用站点', Site::STATUS_DISABLED);

        foreach ([$defaultSite->id, $disabledSite->id, 0, 999] as $siteId) {
            try {
                app(AgentOperationsService::class)->updateAgentCostSite($agent->id, $siteId);
                $this->fail('Expected unavailable cost site to be rejected.');
            } catch (ApiException $exception) {
                $this->assertSame('Cost site is not available', $exception->getMessage());
            }
        }

        $this->assertNull(AgentProfile::query()->where('user_id', $agent->id)->value('cost_site_id'));
    }

    public function test_admin_summary_reports_operations_health_counts(): void
    {
        $agentA = $this->createActiveAgent('agent-a@example.test', 500);
        $agentB = $this->createActiveAgent('agent-b@example.test', 10000);
        $this->createPayment($agentB, null, false);
        $this->createAgentOrder($agentA, [
            'trade_no' => 'agent-a-pending',
            'sale_amount' => 1500,
            'cost_amount' => 500,
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
        ]);
        $this->createAgentOrder($agentB, [
            'trade_no' => 'agent-b-abnormal',
            'context_status' => AgentOrderContext::STATUS_PENDING,
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
            'expires_at' => time() - 10,
        ]);

        $summary = app(AgentOperationsService::class)->adminSummary();

        $this->assertSame([
            'active_agent_count' => 2,
            'pending_hold_total' => 1300,
            'abnormal_order_count' => 1,
            'insufficient_balance_agent_count' => 1,
            'no_active_payment_agent_count' => 2,
        ], $summary);
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

    private function createSite(
        string $code,
        string $name,
        string $status = Site::STATUS_ACTIVE,
        bool $isDefault = false
    ): Site {
        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => $status,
            'is_default' => $isDefault,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
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

    private function createDomain(User $agent, string $domain, string $status = AgentDomain::STATUS_ACTIVE): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPayment(User $agent, ?int $ownerDomainId = null, bool $enable = true): Payment
    {
        return Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $ownerDomainId,
            'uuid' => substr(md5($agent->email . ':' . (string) $ownerDomainId . ':' . uniqid('', true)), 0, 32),
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay',
            'config' => ['merchant_id' => $agent->email],
            'enable' => $enable,
            'sort' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createAgentOrder(User $agent, array $overrides = []): AgentOrderContext
    {
        $now = $overrides['created_at'] ?? time();
        $buyer = $this->createUser($overrides['buyer_email'] ?? uniqid('buyer-', true) . '@example.test');
        $plan = Plan::query()->create([
            'name' => $overrides['plan_name'] ?? 'Starter',
            'prices' => [Plan::PERIOD_MONTHLY => 1000],
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $domain = $overrides['domain'] ?? null;
        $payment = $overrides['payment'] ?? null;
        $tradeNo = $overrides['trade_no'] ?? uniqid('trade-', true);
        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'plan_id' => $plan->id,
            'payment_id' => $payment?->id,
            'period' => $overrides['period'] ?? Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => $overrides['sale_amount'] ?? 1300,
            'status' => $overrides['order_status'] ?? Order::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $context = AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'payment_id' => $payment?->id,
            'sale_amount' => $overrides['sale_amount'] ?? 1300,
            'cost_amount' => $overrides['cost_amount'] ?? 800,
            'status' => $overrides['context_status'] ?? AgentOrderContext::STATUS_PENDING,
            'pricing_snapshot' => [
                'plan_name' => $plan->name,
                'period' => $overrides['pricing_period'] ?? ($overrides['period'] ?? Plan::PERIOD_MONTHLY),
            ],
            'domain_snapshot' => ['domain' => $domain?->domain],
            'payment_snapshot' => ['name' => $payment?->name, 'payment' => $payment?->payment],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $hold = AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'amount' => $overrides['cost_amount'] ?? 800,
            'status' => $overrides['hold_status'] ?? AgentBalanceHold::STATUS_PENDING,
            'expires_at' => $overrides['expires_at'] ?? $now + 3600,
            'captured_at' => $overrides['captured_at'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $context->forceFill(['hold_id' => $hold->id])->save();

        return $context->fresh();
    }
}
