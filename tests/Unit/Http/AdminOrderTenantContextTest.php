<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\OrderController;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteOrderContext;
use App\Models\User;
use App\Services\AgentCommerceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminOrderTenantContextTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createCommissionLogTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
    }

    public function test_admin_order_fetch_exposes_site_and_agent_tenant_contexts(): void
    {
        $plan = $this->createPlan();
        $siteOrder = $this->createSiteOrder($plan);
        $agentOrder = $this->createAgentOrder($plan);

        $payload = $this->responsePayload(app(OrderController::class)->fetch(Request::create(
            '/api/v2/admin/order/fetch',
            'GET',
            ['pageSize' => 10, 'current' => 1]
        )));

        $items = $payload['data']['items'];
        $byTradeNo = [];
        foreach ($items as $item) {
            $byTradeNo[$item['trade_no']] = $item;
        }

        $this->assertSame('site', $byTradeNo[$siteOrder->trade_no]['tenant_context']['source']);
        $this->assertSame('光喵', $byTradeNo[$siteOrder->trade_no]['tenant_context']['site_name']);
        $this->assertSame('gm.example.test', $byTradeNo[$siteOrder->trade_no]['tenant_context']['domain']);
        $this->assertSame(1300, $byTradeNo[$siteOrder->trade_no]['tenant_context']['sale_amount']);
        $this->assertSame(2000, $byTradeNo[$siteOrder->trade_no]['tenant_context']['platform_plan_price']);

        $this->assertSame('agent', $byTradeNo[$agentOrder->trade_no]['tenant_context']['source']);
        $this->assertSame('agent@example.test', $byTradeNo[$agentOrder->trade_no]['tenant_context']['agent_email']);
        $this->assertSame('agent.example.test', $byTradeNo[$agentOrder->trade_no]['tenant_context']['agent_domain']);
        $this->assertSame(1500, $byTradeNo[$agentOrder->trade_no]['tenant_context']['sale_amount']);
        $this->assertSame(800, $byTradeNo[$agentOrder->trade_no]['tenant_context']['cost_amount']);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $byTradeNo[$agentOrder->trade_no]['tenant_context']['hold_status']);
    }

    public function test_admin_order_detail_exposes_agent_failure_reason(): void
    {
        $plan = $this->createPlan();
        $order = $this->createAgentOrder($plan, 'The site balance is insufficient.');

        $payload = $this->responsePayload(app(OrderController::class)->detail(Request::create(
            '/api/v2/admin/order/detail',
            'POST',
            ['id' => $order->id]
        )));

        $this->assertSame('agent', $payload['data']['tenant_context']['source']);
        $this->assertSame('The site balance is insufficient.', $payload['data']['tenant_context']['failure_reason']);
        $this->assertSame(AgentOrderContext::STATUS_FAILED, $payload['data']['tenant_context']['status']);
        $this->assertSame(AgentBalanceHold::STATUS_FAILED, $payload['data']['tenant_context']['hold_status']);
    }

    public function test_admin_manual_paid_marks_agent_order_failed_when_balance_is_insufficient(): void
    {
        $plan = $this->createPlan();
        $order = $this->createAgentOrder($plan, null, 'manual-paid-low-balance');
        $context = $order->agentOrderContext;
        $context->agent->update(['balance' => 100]);

        $payload = $this->responsePayload(app(OrderController::class)->paid(Request::create(
            '/api/v2/admin/order/paid',
            'POST',
            ['trade_no' => $order->trade_no]
        )));

        $this->assertSame('fail', $payload['status']);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(100, (int) $context->agent->fresh()->balance);
        $this->assertSame(AgentBalanceHold::STATUS_FAILED, $context->hold->fresh()->status);
        $this->assertSame(AgentOrderContext::STATUS_FAILED, $context->fresh()->status);
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $context->hold->fresh()->metadata['failure_reason'] ?? null
        );
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $context->fresh()->payment_snapshot['failure_reason'] ?? null
        );
    }

    public function test_admin_order_detail_exposes_agent_diagnostics_for_stuck_pending_hold(): void
    {
        $plan = $this->createPlan();
        $order = $this->createAgentOrder($plan, null, 'diagnostics');
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $payload = $this->responsePayload(app(OrderController::class)->detail(Request::create(
            '/api/v2/admin/order/detail',
            'POST',
            ['id' => $order->id]
        )));

        $context = $payload['data']['tenant_context'];
        $this->assertSame('agent', $context['source']);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $context['hold_status']);
        $this->assertSame('not_captured', $context['capture_status']);
        $this->assertContains('cancelled_with_pending_hold', $context['abnormal_flags']);
        $this->assertTrue($context['can_release_hold']);
        $this->assertSame('release_agent_hold', $context['recommended_action']);
    }

    public function test_admin_can_release_cancelled_agent_order_pending_hold(): void
    {
        $plan = $this->createPlan();
        $order = $this->createAgentOrder($plan, null, 'release');
        $order->update(['status' => Order::STATUS_CANCELLED]);

        $payload = $this->responsePayload(app(OrderController::class)->releaseAgentHold(Request::create(
            '/api/v2/admin/order/release-agent-hold',
            'POST',
            ['trade_no' => $order->trade_no]
        )));

        $this->assertSame('success', $payload['status']);
        $context = $payload['data']['tenant_context'];
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $context['hold_status']);
        $this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context['status']);
        $this->assertFalse($context['can_release_hold']);
        $this->assertSame('', $context['recommended_action']);
        $this->assertSame(
            AgentBalanceHold::STATUS_RELEASED,
            $order->agentOrderContext->hold->fresh()->status
        );
    }

    public function test_admin_cannot_release_active_agent_order_pending_hold(): void
    {
        $plan = $this->createPlan();
        $order = $this->createAgentOrder($plan, null, 'active-release');

        $payload = $this->responsePayload(app(OrderController::class)->releaseAgentHold(Request::create(
            '/api/v2/admin/order/release-agent-hold',
            'POST',
            ['trade_no' => $order->trade_no]
        )));

        $this->assertSame('fail', $payload['status']);
        $this->assertStringContainsString('只能释放已取消订单', $payload['message']);
        $this->assertSame(
            AgentBalanceHold::STATUS_PENDING,
            $order->agentOrderContext->hold->fresh()->status
        );
    }

    public function test_admin_order_fetch_filters_by_tenant_source(): void
    {
        $plan = $this->createPlan();
        $siteOrder = $this->createSiteOrder($plan);
        $agentOrder = $this->createAgentOrder($plan);
        $platformOrder = $this->createOrder(
            $this->createUser('platform-buyer@example.test'),
            $plan,
            'platform-order-trade',
            2000
        );

        $this->assertSame([$agentOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'tenant_source', 'value' => 'agent'],
        ]));
        $this->assertSame([$siteOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'tenant_source', 'value' => 'site'],
        ]));
        $this->assertSame([$platformOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'tenant_source', 'value' => 'platform'],
        ]));
    }

    public function test_admin_order_fetch_filters_agent_order_issues(): void
    {
        $plan = $this->createPlan();
        $pendingOrder = $this->createAgentOrder($plan, null, 'pending');
        $failedOrder = $this->createAgentOrder($plan, 'The site balance is insufficient.', 'failed');
        $cancelledOrder = $this->createAgentOrder($plan, null, 'cancelled');
        $cancelledOrder->update(['status' => Order::STATUS_CANCELLED]);
        $paidWithoutCompletedOrder = $this->createAgentOrder($plan, null, 'paid-without-completed');
        $paidWithoutCompletedOrder->update(['status' => Order::STATUS_PROCESSING]);
        $paidWithoutCompletedOrder->agentOrderContext()->update([
            'status' => AgentOrderContext::STATUS_PAID,
        ]);
        $paidWithoutCompletedOrder->agentOrderContext->hold()->update([
            'status' => AgentBalanceHold::STATUS_CAPTURED,
        ]);

        $this->assertSame([$failedOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'agent_order_issue', 'value' => 'failed'],
        ]));
        $this->assertSameCanonicalizing([$cancelledOrder->trade_no, $pendingOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'agent_order_issue', 'value' => 'pending_hold'],
        ]));
        $this->assertSame([$cancelledOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'agent_order_issue', 'value' => 'cancelled_with_hold'],
        ]));
        $this->assertSame([$paidWithoutCompletedOrder->trade_no], $this->fetchTradeNos([
            ['id' => 'agent_order_issue', 'value' => 'paid_without_completed'],
        ]));
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'name' => 'Starter',
            'prices' => [Plan::PERIOD_MONTHLY => 20.00],
            'transfer_enable' => 100,
            'group_id' => 1,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createCommissionLogTable(): void
    {
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->nullable();
            $table->integer('invite_user_id')->nullable();
            $table->string('trade_no', 64)->nullable()->index();
            $table->integer('get_amount')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createSiteOrder(Plan $plan): Order
    {
        $site = Site::query()->create([
            'code' => 'gm',
            'name' => '光喵',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $domain = SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'gm.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->createUser('site-buyer@example.test', ['site_id' => $site->id]);
        $order = $this->createOrder($user, $plan, 'site-order-trade', 1300, ['site_id' => $site->id]);

        SiteOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'site_id' => $site->id,
            'site_domain_id' => $domain->id,
            'sale_amount' => 1300,
            'platform_plan_price' => 2000,
            'pricing_snapshot' => ['period' => Plan::PERIOD_MONTHLY],
            'domain_snapshot' => ['source' => 'domain', 'domain' => $domain->domain],
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $order;
    }

    private function createAgentOrder(Plan $plan, ?string $failureReason = null, string $suffix = ''): Order
    {
        $suffixPart = $suffix === '' ? '' : "-{$suffix}";
        $agent = $this->createUser("agent{$suffixPart}@example.test", ['balance' => 5000]);
        $buyer = $this->createUser("agent-buyer{$suffixPart}@example.test", ['invite_user_id' => $agent->id]);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => "agent{$suffixPart}.example.test",
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = $this->createOrder($buyer, $plan, $failureReason ? "agent-failed-order-trade{$suffixPart}" : "agent-order-trade{$suffixPart}", 1500, [
            'invite_user_id' => $agent->id,
        ]);
        $hold = AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'amount' => 800,
            'status' => $failureReason ? AgentBalanceHold::STATUS_FAILED : AgentBalanceHold::STATUS_PENDING,
            'metadata' => $failureReason ? ['failure_reason' => $failureReason] : null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'sale_amount' => 1500,
            'cost_amount' => 800,
            'hold_id' => $hold->id,
            'status' => $failureReason ? AgentOrderContext::STATUS_FAILED : AgentOrderContext::STATUS_PENDING,
            'pricing_snapshot' => ['period' => Plan::PERIOD_MONTHLY],
            'domain_snapshot' => ['source' => 'domain', 'domain' => $domain->domain],
            'payment_snapshot' => $failureReason ? ['failure_reason' => $failureReason] : null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $order;
    }

    /**
     * @param array<int, array{id: string, value: mixed}> $filters
     * @return array<int, string>
     */
    private function fetchTradeNos(array $filters): array
    {
        $payload = $this->responsePayload(app(OrderController::class)->fetch(Request::create(
            '/api/v2/admin/order/fetch',
            'GET',
            ['pageSize' => 20, 'current' => 1, 'filter' => $filters]
        )));

        return array_values(array_map(
            fn (array $item): string => (string) $item['trade_no'],
            $payload['data']['items']
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createUser(string $email, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_DEFAULT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createOrder(User $user, Plan $plan, string $tradeNo, int $amount, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'type' => Order::TYPE_NEW_PURCHASE,
            'total_amount' => $amount,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
