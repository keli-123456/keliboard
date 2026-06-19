<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\User\OrderController;
use App\Http\Requests\Passport\AuthRegister;
use App\Http\Requests\User\OrderSave;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceService;
use App\Services\Auth\RegisterService;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Http\Request as BaseRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentDomainOrderFlowTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindSynchronousBusDispatcher();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->bindTestUrlGenerator('https://panel.example.test');
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createTrafficResetLogTable();
        $this->bindTestHasher();
        HookManager::reset();
        $this->bindFakePaymentGateway();
        $this->bindTestSettings([
            'captcha_enable' => 0,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'email_verify' => 0,
            'register_limit_by_ip_enable' => 0,
            'try_out_plan_id' => 0,
            'default_remind_expire' => 1,
            'default_remind_traffic' => 1,
            'agent_center_discount_percent' => 50,
            'plan_change_enable' => 1,
        ]);
    }

    private function bindRequestValidateMacro(): void
    {
        if (BaseRequest::hasMacro('validate')) {
            return;
        }

        BaseRequest::macro('validate', function (array $rules = [], ...$parameters): array {
            return $this->all();
        });
    }

    public function test_order_save_through_agent_domain_creates_agent_order(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createUser('buyer@example.test');
        $request = OrderSave::create('/api/v1/user/order/save', 'POST', [
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);
        $request->setUserResolver(static fn (): User => $buyer);

        $response = app(OrderController::class)->save($request);
        $payload = $this->responsePayload($response);
        $order = Order::query()->where('trade_no', $payload['data'])->first();

        $this->assertSame('success', $payload['status']);
        $this->assertNotNull($order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame(1, AgentBalanceHold::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, AgentOrderContext::query()->where('order_id', $order->id)->count());
    }

    public function test_agent_checkout_rejects_platform_payment_method(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_PLATFORM, null);
        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame('fail', $payload['status']);
        $this->assertSame('This payment method is unavailable.', $payload['message']);
        $this->assertNull($order->fresh()->payment_id);
        $this->assertSame(10000, (int) $agent->fresh()->balance);
    }

    public function test_agent_checkout_accepts_owned_agent_payment_method(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame(0, $payload['type']);
        $this->assertSame($payment->id, (int) $order->fresh()->payment_id);
    }

    public function test_agent_checkout_does_not_double_count_current_hold(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $agent->balance = (int) $hold->amount;
        $agent->save();

        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame(0, $payload['type']);
        $this->assertSame($payment->id, (int) $order->fresh()->payment_id);
        $this->assertSame($payment->id, (int) AgentOrderContext::query()->where('order_id', $order->id)->first()->payment_id);
    }

    public function test_agent_checkout_rejects_when_hold_is_not_pending(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $hold->status = AgentBalanceHold::STATUS_RELEASED;
        $hold->save();

        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame('fail', $payload['status']);
        $this->assertSame('Agent balance hold is unavailable', $payload['message']);
        $this->assertNull($order->fresh()->payment_id);
    }

    public function test_agent_checkout_rejects_when_available_balance_no_longer_covers_hold(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $agent->balance = 100;
        $agent->save();

        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame('fail', $payload['status']);
        $this->assertSame(AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE, $payload['message']);
        $this->assertNull($order->fresh()->payment_id);
    }

    public function test_agent_domain_payment_methods_only_include_owned_agent_methods(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $otherAgent = $this->createActiveAgent('other-agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $ownedPayment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $this->createPayment(Payment::OWNER_AGENT, $otherAgent->id);
        $this->createPayment(Payment::OWNER_PLATFORM, null);
        $request = BaseRequest::create('/api/v1/user/order/getPaymentMethod', 'GET', [], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);

        $response = app(OrderController::class)->getPaymentMethod($request);
        $payload = $this->responsePayload($response);

        $this->assertSame([$ownedPayment->id], array_column($payload['data'], 'id'));
    }

    public function test_platform_order_trade_no_does_not_fall_back_to_agent_domain_payments(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createUser('buyer@example.test');
        Order::query()->create([
            'user_id' => $buyer->id,
            'plan_id' => 0,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'platform-order',
            'total_amount' => 1000,
            'status' => Order::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $platformPayment = $this->createPayment(Payment::OWNER_PLATFORM, null);
        $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $request = BaseRequest::create('/api/v1/user/order/getPaymentMethod', 'GET', [
            'trade_no' => 'platform-order',
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);
        $request->setUserResolver(static fn (): User => $buyer);

        $response = app(OrderController::class)->getPaymentMethod($request);
        $payload = $this->responsePayload($response);

        $this->assertSame([$platformPayment->id], array_column($payload['data'], 'id'));
    }

    public function test_agent_domain_payment_methods_exclude_payments_bound_to_another_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $currentDomain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'shop-a.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $otherDomain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'shop-b.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $globalPayment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $currentPayment = $this->createPayment(Payment::OWNER_AGENT, $agent->id, $currentDomain->id);
        $this->createPayment(Payment::OWNER_AGENT, $agent->id, $otherDomain->id);
        $request = BaseRequest::create('/api/v1/user/order/getPaymentMethod', 'GET', [], [], [], [
            'HTTP_HOST' => 'shop-a.example.test',
        ]);

        $response = app(OrderController::class)->getPaymentMethod($request);
        $payload = $this->responsePayload($response);

        $this->assertSame([$globalPayment->id, $currentPayment->id], array_column($payload['data'], 'id'));
    }

    public function test_checkout_rejects_agent_payment_bound_to_another_domain(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture('shop-a.example.test');
        $otherDomain = AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'shop-b.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id, $otherDomain->id);
        $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ], [], [], [
            'HTTP_HOST' => 'shop-a.example.test',
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app()->instance('request', $request);

        $response = app(OrderController::class)->checkout($request);
        $payload = $this->responsePayload($response);

        $this->assertSame('fail', $payload['status']);
        $this->assertSame('This payment method is unavailable.', $payload['message']);
        $this->assertNull($order->fresh()->payment_id);
    }

    public function test_payment_callback_captures_hold_and_deducts_agent_once(): void
    {
        [$agent, , $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $order->payment_id = $payment->id;
        $order->save();

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-1',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id));

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertTrue($handled);
        $this->assertSame(9500, (int) $agent->fresh()->balance);
        $this->assertSame(AgentBalanceHold::STATUS_CAPTURED, $hold->fresh()->status);
        $this->assertSame(AgentOrderContext::STATUS_PAID, $context->fresh()->status);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
    }

    public function test_duplicate_payment_callback_does_not_double_deduct_agent_balance(): void
    {
        [$agent, , $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $order->payment_id = $payment->id;
        $order->save();

        $this->assertTrue($this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-1',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id)));
        $this->assertTrue($this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-2',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id)));

        $this->assertSame(9500, (int) $agent->fresh()->balance);
        $this->assertSame(AgentBalanceHold::STATUS_CAPTURED, AgentBalanceHold::query()->where('order_id', $order->id)->first()->status);
        $this->assertSame('gateway-1', $order->fresh()->callback_no);
    }

    public function test_payment_callback_marks_agent_context_failed_when_agent_balance_is_insufficient(): void
    {
        [$agent, , $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $order->payment_id = $payment->id;
        $order->save();
        $agent->balance = 100;
        $agent->save();

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-low-balance',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id));

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertFalse($handled);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(100, (int) $agent->fresh()->balance);
        $this->assertSame(AgentBalanceHold::STATUS_FAILED, $hold->fresh()->status);
        $this->assertSame(AgentOrderContext::STATUS_FAILED, $context->fresh()->status);
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $hold->fresh()->metadata['failure_reason'] ?? null
        );
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $context->fresh()->payment_snapshot['failure_reason'] ?? null
        );

        $this->assertFalse($this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-low-balance-retry',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id)));
        $this->assertSame(AgentBalanceHold::STATUS_FAILED, $hold->fresh()->status);
        $this->assertSame(AgentOrderContext::STATUS_FAILED, $context->fresh()->status);
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $hold->fresh()->metadata['failure_reason'] ?? null
        );
        $this->assertSame(
            AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
            $context->fresh()->payment_snapshot['failure_reason'] ?? null
        );
    }

    public function test_cancel_agent_order_releases_pending_hold(): void
    {
        [$agent, $buyer, $order] = $this->createAgentOrderFixture();
        $request = BaseRequest::create('/api/v1/user/order/cancel', 'POST', [
            'trade_no' => $order->trade_no,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);

        $response = app(OrderController::class)->cancel($request);
        $payload = $this->responsePayload($response);

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertSame('success', $payload['status']);
        $this->assertTrue($payload['data']);
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertNotNull($hold->fresh()->released_at);
        $this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context->fresh()->status);
        $this->assertSame(10000, (int) $agent->fresh()->balance);
    }

    public function test_fail_for_order_does_not_mutate_cancelled_agent_context(): void
    {
        [, $buyer, $order] = $this->createAgentOrderFixture();
        $request = BaseRequest::create('/api/v1/user/order/cancel', 'POST', [
            'trade_no' => $order->trade_no,
        ]);
        $request->setUserResolver(static fn (): User => $buyer);
        app(OrderController::class)->cancel($request);

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        app(AgentCommerceService::class)->failForOrder($order, 'should not overwrite terminal state');

        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->fresh()->status);
        $this->assertNull($hold->fresh()->metadata['failure_reason'] ?? null);
        $this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context->fresh()->status);
        $this->assertNull($context->fresh()->payment_snapshot['failure_reason'] ?? null);
    }

    public function test_fail_for_order_is_idempotent_for_failed_agent_context(): void
    {
        [, , $order] = $this->createAgentOrderFixture();
        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        app(AgentCommerceService::class)->failForOrder($order, 'first failure');
        app(AgentCommerceService::class)->failForOrder($order, 'second failure');

        $this->assertSame(AgentBalanceHold::STATUS_FAILED, $hold->fresh()->status);
        $this->assertSame('first failure', $hold->fresh()->metadata['failure_reason'] ?? null);
        $this->assertSame(AgentOrderContext::STATUS_FAILED, $context->fresh()->status);
        $this->assertSame('first failure', $context->fresh()->payment_snapshot['failure_reason'] ?? null);
    }

    public function test_registration_through_agent_domain_binds_user_to_agent(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => 'agent.example.test',
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $request = AuthRegister::create('/api/v1/passport/auth/register', 'POST', [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
        ], [], [], [
            'HTTP_HOST' => 'agent.example.test',
        ]);

        [$success, $result] = app(RegisterService::class)->register($request);

        $this->assertTrue($success);
        $this->assertInstanceOf(User::class, $result);
        $registeredUser = $result->fresh();
        $this->assertSame($agent->id, (int) $registeredUser->invite_user_id);
        $this->assertSame(1, DB::table('v2_agent_user')
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $registeredUser->id)
            ->count());
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email, 10000);

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

    private function createTrafficResetLogTable(): void
    {
        DB::connection()->getSchemaBuilder()->create('v2_traffic_reset_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('reset_type');
            $table->timestamp('reset_time')->nullable();
            $table->bigInteger('old_upload')->default(0);
            $table->bigInteger('old_download')->default(0);
            $table->bigInteger('old_total')->default(0);
            $table->bigInteger('new_upload')->default(0);
            $table->bigInteger('new_download')->default(0);
            $table->bigInteger('new_total')->default(0);
            $table->string('trigger_source');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * @return array{0: User, 1: User, 2: Order}
     */
    private function createAgentOrderFixture(string $domain = 'agent.example.test'): array
    {
        $agent = $this->createActiveAgent('agent@example.test');
        AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            BaseRequest::create('/api/v1/user/order/save', 'POST', [], [], [], [
                'HTTP_HOST' => $domain,
            ])
        );

        return [$agent, $buyer, $order];
    }

    private function createPayment(string $ownerType, ?int $ownerId, ?int $ownerDomainId = null): Payment
    {
        return Payment::query()->create([
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'owner_domain_id' => $ownerDomainId,
            'uuid' => substr(md5($ownerType . ':' . (string) $ownerId . ':' . uniqid('', true)), 0, 32),
            'payment' => 'FAKEPAY',
            'name' => $ownerType === Payment::OWNER_AGENT ? 'Agent Pay' : 'Platform Pay',
            'config' => ['merchant_id' => $ownerType],
            'enable' => true,
            'sort' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    /**
     * @param array<string, mixed> $verify
     */
    private function invokePaymentHandle(array $verify, PaymentService $paymentService): bool
    {
        $controller = new PaymentController();
        $method = new \ReflectionMethod(PaymentController::class, 'handle');
        $method->setAccessible(true);

        return (bool) $method->invoke($controller, $verify, $paymentService);
    }

    private function paymentServiceWithId(?int $paymentId): PaymentService
    {
        return new class($paymentId) extends PaymentService {
            private ?int $paymentId;

            public function __construct(?int $paymentId)
            {
                $this->paymentId = $paymentId;
            }

            public function getPaymentId(): ?int
            {
                return $this->paymentId;
            }
        };
    }

    private function responsePayload($response): array
    {
        if (method_exists($response, 'getData')) {
            return $response->getData(true);
        }

        return json_decode((string) $response->getContent(), true) ?: [];
    }

    private function bindFakePaymentGateway(): void
    {
        HookManager::registerFilter('available_payment_methods', static function (array $methods): array {
            $methods['FAKEPAY'] = [
                'name' => 'Fake Pay',
                'icon' => 'fake',
                'plugin_code' => 'fakepay',
                'type' => 'plugin',
            ];

            return $methods;
        });

        app()->instance(PluginManager::class, new class {
            public function initializeEnabledPlugins(): void {}

            public function getEnabledPaymentPlugins(): array
            {
                return [new class {
                    private array $config = [];

                    public function getPluginCode(): string
                    {
                        return 'fakepay';
                    }

                    public function setConfig(array $config): void
                    {
                        $this->config = $config;
                    }

                    public function pay(array $order): array
                    {
                        return [
                            'type' => 0,
                            'data' => [
                                'trade_no' => $order['trade_no'],
                                'notify_uuid' => $this->config['uuid'] ?? null,
                            ],
                        ];
                    }

                    public function notify(array $params): array
                    {
                        return $params;
                    }

                    public function form(): array
                    {
                        return [];
                    }
                }];
            }
        });
    }
}
