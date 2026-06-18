<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\AgentCommerceController;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminAgentCommerceControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
    }

    public function test_admin_oversight_lists_agent_payments_holds_and_orders(): void
    {
        $agent = $this->createUser('agent@example.test', 9000);
        $buyer = $this->createUser('buyer@example.test', 0);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $payment = Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'agentpay000000000000000000000001',
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay',
            'config' => ['secret' => 'do-not-leak'],
            'enable' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'plan_id' => 1,
            'payment_id' => $payment->id,
            'period' => 'monthly',
            'trade_no' => 'agent-order-1',
            'total_amount' => 1300,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $hold = AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'amount' => 500,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain->id,
            'payment_id' => $payment->id,
            'sale_amount' => 1300,
            'cost_amount' => 500,
            'hold_id' => $hold->id,
            'status' => AgentOrderContext::STATUS_PENDING,
            'pricing_snapshot' => ['period' => 'monthly'],
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller = app(AgentCommerceController::class);
        $request = Request::create('/admin/agent-commerce', 'GET');

        $payments = $this->responsePayload($controller->payments($request))['data'];
        $this->assertSame('agent@example.test', $payments[0]['agent_email']);
        $this->assertSame('agent.example.test', $payments[0]['owner_domain']);
        $this->assertArrayNotHasKey('config', $payments[0]);

        $holds = $this->responsePayload($controller->holds($request))['data'];
        $this->assertSame('buyer@example.test', $holds[0]['buyer_email']);
        $this->assertSame(500, $holds[0]['amount']);
        $this->assertSame(Order::STATUS_PENDING, $holds[0]['order_status']);

        $orders = $this->responsePayload($controller->orders($request))['data'];
        $this->assertSame('agent-order-1', $orders[0]['trade_no']);
        $this->assertSame('Agent Pay', $orders[0]['payment_name']);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $orders[0]['hold_status']);
    }

    private function createUser(string $email, int $balance): User
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

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
