<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\User\AgentOperationsController;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserAgentOperationsControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createPlanTable();
    }

    public function test_summary_requires_active_agent_and_returns_finance_summary(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createAgentOrder($agent, [
            'trade_no' => 'agent-paid-order',
            'context_status' => AgentOrderContext::STATUS_PAID,
            'order_status' => Order::STATUS_COMPLETED,
            'hold_status' => AgentBalanceHold::STATUS_CAPTURED,
            'captured_at' => time(),
        ]);
        $request = $this->userRequest($agent, '/api/v1/user/agent/operations/summary');

        $payload = $this->responsePayload(app(AgentOperationsController::class)->summary($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame(10000, $payload['data']['balance']);
        $this->assertSame(1300, $payload['data']['month_sales_total']);
        $this->assertSame(800, $payload['data']['month_cost_total']);
        $this->assertSame(500, $payload['data']['month_margin_total']);
    }

    public function test_summary_rejects_inactive_agent(): void
    {
        $agent = $this->createUser('agent@example.test', 10000);
        $request = $this->userRequest($agent, '/api/v1/user/agent/operations/summary');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent permission is not active');

        app(AgentOperationsController::class)->summary($request);
    }

    public function test_orders_endpoint_scopes_to_current_agent_and_returns_detail(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $otherAgent = $this->createActiveAgent('other-agent@example.test', 10000);
        $this->createAgentOrder($agent, ['trade_no' => 'own-agent-order']);
        $this->createAgentOrder($otherAgent, ['trade_no' => 'other-agent-order']);
        $request = $this->userRequest($agent, '/api/v1/user/agent/operations/orders', 'GET', [
            'page' => 1,
            'page_size' => 20,
        ]);

        $orders = $this->responsePayload(app(AgentOperationsController::class)->orders($request))['data'];
        $detail = $this->responsePayload(
            app(AgentOperationsController::class)->order($request, 'own-agent-order')
        )['data'];

        $this->assertSame(1, $orders['total']);
        $this->assertSame(['own-agent-order'], array_column($orders['data'], 'trade_no'));
        $this->assertSame('own-agent-order', $detail['trade_no']);
    }

    private function userRequest(User $user, string $uri, string $method = 'GET', array $parameters = []): Request
    {
        $request = Request::create($uri, $method, $parameters);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
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

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
