<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\AgentCommerceController;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCenterService;
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
        $this->bindRequestValidateMacro();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createAgentCenterTables();
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
            'domain_snapshot' => [
                'source' => 'user_binding',
                'agent_domain_id' => null,
                'domain' => '',
                'is_primary' => false,
            ],
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
        $this->assertSame('user_binding', $orders[0]['source']);
    }

    public function test_admin_oversight_exposes_failed_order_and_hold_reasons(): void
    {
        $failureReason = 'The site balance is insufficient. Please contact site support.';
        $agent = $this->createUser('failed-agent@example.test', 0);
        $buyer = $this->createUser('failed-buyer@example.test', 0);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'failed-agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $payment = Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'agentpay000000000000000000000002',
            'payment' => 'FAKEPAY',
            'name' => 'Failed Agent Pay',
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
            'trade_no' => 'agent-order-failed-1',
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
            'status' => AgentBalanceHold::STATUS_FAILED,
            'metadata' => ['failure_reason' => $failureReason],
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
            'status' => AgentOrderContext::STATUS_FAILED,
            'pricing_snapshot' => ['period' => 'monthly'],
            'domain_snapshot' => [
                'source' => 'domain',
                'agent_domain_id' => $domain->id,
                'domain' => $domain->domain,
                'is_primary' => true,
            ],
            'payment_snapshot' => ['failure_reason' => $failureReason],
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $controller = app(AgentCommerceController::class);
        $request = Request::create('/admin/agent-commerce', 'GET');

        $orders = $this->responsePayload($controller->orders($request))['data'];
        $this->assertSame('failed', $orders[0]['status']);
        $this->assertSame($failureReason, $orders[0]['failure_reason']);
        $this->assertSame('domain', $orders[0]['source']);

        $holds = $this->responsePayload($controller->holds($request))['data'];
        $this->assertSame('failed', $holds[0]['status']);
        $this->assertSame($failureReason, $holds[0]['failure_reason']);
    }

    public function test_admin_domain_payload_exposes_verification_metadata_without_token(): void
    {
        $agent = $this->createUser('agent-domain@example.test', 0);
        $admin = $this->createUser('admin-domain@example.test', 0);

        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent-owned.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'remark' => 'agent domain',
            'verification_token' => 'secret-agent-token',
            'verification_type' => 'txt',
            'verified_at' => 1111,
            'last_checked_at' => 2222,
            'verification_error' => 'TXT record missing',
            'created_by_agent_id' => $agent->id,
            'created_at' => 1000,
            'updated_at' => 2000,
        ]);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'admin-owned.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'verification_token' => 'secret-admin-token',
            'created_by_admin_id' => $admin->id,
            'created_at' => 3000,
            'updated_at' => 4000,
        ]);
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'unknown-owner.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => false,
            'verification_token' => 'secret-unknown-token',
            'created_at' => 5000,
            'updated_at' => 6000,
        ]);

        $domains = $this->responsePayload(app(AgentCommerceController::class)->domains())['data'];
        $byDomain = [];
        foreach ($domains as $domain) {
            $byDomain[$domain['domain']] = $domain;
        }

        $agentPayload = $byDomain['agent-owned.example.test'];
        $this->assertSame('agent', $agentPayload['source']);
        $this->assertSame('txt', $agentPayload['verification_type']);
        $this->assertSame(1111, $agentPayload['verified_at']);
        $this->assertSame(2222, $agentPayload['last_checked_at']);
        $this->assertSame('TXT record missing', $agentPayload['verification_error']);
        $this->assertSame($agent->id, $agentPayload['created_by_agent_id']);
        $this->assertArrayNotHasKey('verification_token', $agentPayload);

        $adminPayload = $byDomain['admin-owned.example.test'];
        $this->assertSame('admin', $adminPayload['source']);
        $this->assertSame($admin->id, $adminPayload['created_by_admin_id']);
        $this->assertNull($adminPayload['created_by_agent_id']);
        $this->assertArrayNotHasKey('verification_token', $adminPayload);

        $unknownPayload = $byDomain['unknown-owner.example.test'];
        $this->assertSame('unknown', $unknownPayload['source']);
        $this->assertArrayNotHasKey('verification_token', $unknownPayload);
    }

    public function test_admin_save_domain_marks_new_domain_as_admin_and_keeps_existing_verification_state(): void
    {
        $agent = $this->createUser('save-agent@example.test', 0);
        $admin = $this->createUser('save-admin@example.test', 0);
        $this->createActiveAgentProfile($agent);

        $createRequest = Request::create('/admin/agent-commerce/domains', 'POST', [
            'agent_user_id' => $agent->id,
            'domain' => 'new-admin.example.test',
            'remark' => 'created by admin',
            'is_primary' => true,
        ]);
        $createRequest->setUserResolver(fn () => $admin);

        $created = $this->responsePayload(app(AgentCommerceController::class)->saveDomain($createRequest))['data'];
        $this->assertSame('admin', $created['source']);
        $this->assertSame(AgentDomain::STATUS_ACTIVE, $created['status']);
        $this->assertSame($admin->id, $created['created_by_admin_id']);
        $this->assertNull($created['created_by_agent_id']);
        $this->assertNull($created['verification_type']);
        $this->assertArrayNotHasKey('verification_token', $created);

        $existing = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'existing-agent.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'verification_token' => 'existing-secret-token',
            'verification_type' => 'txt',
            'verified_at' => 3333,
            'last_checked_at' => 4444,
            'verification_error' => 'Still pending',
            'created_by_agent_id' => $agent->id,
            'created_at' => 3000,
            'updated_at' => 3000,
        ]);
        $updateRequest = Request::create('/admin/agent-commerce/domains', 'POST', [
            'id' => $existing->id,
            'agent_user_id' => $agent->id,
            'domain' => 'existing-agent-updated.example.test',
            'remark' => 'updated by admin',
            'is_primary' => false,
        ]);
        $updateRequest->setUserResolver(fn () => $admin);

        $updated = $this->responsePayload(app(AgentCommerceController::class)->saveDomain($updateRequest))['data'];
        $this->assertSame('agent', $updated['source']);
        $this->assertSame(AgentDomain::STATUS_PENDING, $updated['status']);
        $this->assertSame('txt', $updated['verification_type']);
        $this->assertSame(3333, $updated['verified_at']);
        $this->assertSame(4444, $updated['last_checked_at']);
        $this->assertSame('Still pending', $updated['verification_error']);
        $this->assertSame($agent->id, $updated['created_by_agent_id']);
        $this->assertArrayNotHasKey('verification_token', $updated);
    }

    public function test_admin_domain_status_payload_preserves_verification_metadata(): void
    {
        $agent = $this->createUser('status-agent@example.test', 0);
        $domain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'status-agent.example.test',
            'status' => AgentDomain::STATUS_PENDING,
            'is_primary' => false,
            'verification_token' => 'status-secret-token',
            'verification_type' => 'txt',
            'verified_at' => 5555,
            'last_checked_at' => 6666,
            'verification_error' => 'retry later',
            'created_by_agent_id' => $agent->id,
            'created_at' => 5000,
            'updated_at' => 5000,
        ]);

        $payload = $this->responsePayload(app(AgentCommerceController::class)->enableDomain($domain->id))['data'];

        $this->assertSame(AgentDomain::STATUS_ACTIVE, $payload['status']);
        $this->assertSame('agent', $payload['source']);
        $this->assertSame('txt', $payload['verification_type']);
        $this->assertSame(5555, $payload['verified_at']);
        $this->assertSame(6666, $payload['last_checked_at']);
        $this->assertSame('retry later', $payload['verification_error']);
        $this->assertSame($agent->id, $payload['created_by_agent_id']);
        $this->assertArrayNotHasKey('verification_token', $payload);
    }

    public function test_admin_cannot_disable_domain_used_by_enabled_agent_payment(): void
    {
        $agent = $this->createUser('disable-agent@example.test', 0);
        $domain = $this->createAgentDomain($agent, 'disable-blocked.example.test');
        $this->createAgentPayment($agent, $domain, true);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain is used by an enabled payment method');

        app(AgentCommerceController::class)->disableDomain($domain->id);
    }

    public function test_admin_cannot_delete_domain_used_by_enabled_agent_payment(): void
    {
        $agent = $this->createUser('delete-agent@example.test', 0);
        $domain = $this->createAgentDomain($agent, 'delete-blocked.example.test');
        $this->createAgentPayment($agent, $domain, true);

        try {
            app(AgentCommerceController::class)->deleteDomain($domain->id);
            $this->fail('Expected domain deletion to be blocked.');
        } catch (ApiException $exception) {
            $this->assertSame('Domain is used by an enabled payment method', $exception->getMessage());
            $this->assertNotNull(AgentDomain::query()->find($domain->id));
        }
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

    private function bindRequestValidateMacro(): void
    {
        if (Request::hasMacro('validate')) {
            return;
        }

        Request::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    private function createActiveAgentProfile(User $agent): AgentProfile
    {
        return AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createAgentDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => false,
            'created_by_admin_id' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createAgentPayment(User $agent, AgentDomain $domain, bool $enabled): Payment
    {
        return Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $domain->id,
            'uuid' => 'agentpay' . str_pad((string) $domain->id, 24, '0', STR_PAD_LEFT),
            'payment' => 'FAKEPAY',
            'name' => 'Agent Pay ' . $domain->id,
            'config' => ['secret' => 'do-not-leak'],
            'enable' => $enabled,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}
