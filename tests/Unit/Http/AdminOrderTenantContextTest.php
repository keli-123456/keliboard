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

    private function createAgentOrder(Plan $plan, ?string $failureReason = null): Order
    {
        $agent = $this->createUser('agent@example.test', ['balance' => 5000]);
        $buyer = $this->createUser('agent-buyer@example.test', ['invite_user_id' => $agent->id]);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = $this->createOrder($buyer, $plan, $failureReason ? 'agent-failed-order-trade' : 'agent-order-trade', 1500, [
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
