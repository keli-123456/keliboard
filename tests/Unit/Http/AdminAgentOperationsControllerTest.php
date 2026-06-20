<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\AgentOperationsController;
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

final class AdminAgentOperationsControllerTest extends TestCase
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

    public function test_summary_and_agent_list_return_operations_rows(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domain = $this->createDomain($agent, 'shop.example.test');
        $payment = $this->createPayment($agent, $domain->id, true);
        $this->createAgentOrder($agent, [
            'trade_no' => 'paid-agent-order',
            'domain' => $domain,
            'payment' => $payment,
            'context_status' => AgentOrderContext::STATUS_PAID,
            'order_status' => Order::STATUS_COMPLETED,
            'hold_status' => AgentBalanceHold::STATUS_CAPTURED,
            'captured_at' => time(),
        ]);
        $controller = app(AgentOperationsController::class);
        $request = Request::create('/admin/agent-operations/agents', 'GET');

        $summary = $this->responsePayload($controller->summary($request))['data'];
        $agents = $this->responsePayload($controller->agents($request))['data'];
        $detail = $this->responsePayload($controller->agent($request, $agent->id))['data'];
        $orders = $this->responsePayload($controller->agentOrders($request, $agent->id))['data'];

        $this->assertSame(1, $summary['active_agent_count']);
        $this->assertSame(1, $agents['total']);
        $this->assertSame('agent@example.test', $agents['data'][0]['agent_email']);
        $this->assertSame(1, $detail['active_domain_count']);
        $this->assertSame(['paid-agent-order'], array_column($orders['data'], 'trade_no'));
    }

    public function test_admin_can_disable_and_enable_agent_payment_and_disable_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domain = $this->createDomain($agent, 'shop.example.test');
        $payment = $this->createPayment($agent, $domain->id, true);
        $controller = app(AgentOperationsController::class);

        $disabledPayment = $this->responsePayload($controller->disablePayment($payment->id))['data'];
        $enabledPayment = $this->responsePayload($controller->enablePayment($payment->id))['data'];
        $disabledDomain = $this->responsePayload($controller->disableDomain($domain->id))['data'];

        $this->assertFalse($disabledPayment['enable']);
        $this->assertTrue($enabledPayment['enable']);
        $this->assertSame(AgentDomain::STATUS_DISABLED, $disabledDomain['status']);
        $this->assertTrue((bool) Payment::query()->find($payment->id)->enable);
        $this->assertSame(AgentDomain::STATUS_DISABLED, AgentDomain::query()->find($domain->id)->status);
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

    private function createDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
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

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
