<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentOrderStatusResolver;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentOrderStatusResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
    }

    public function test_clean_pending_order_has_no_abnormal_flags(): void
    {
        $context = $this->createContext([
            'sale_amount' => 1300,
            'cost_amount' => 500,
        ]);
        $hold = $this->createHold($context, [
            'amount' => 500,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'expires_at' => time() + 3600,
        ]);
        $context->forceFill(['hold_id' => $hold->id])->save();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertSame([
            'hold_status' => AgentBalanceHold::STATUS_PENDING,
            'capture_status' => 'not_captured',
            'margin_amount' => 800,
            'abnormal_flags' => [],
        ], $result);
    }

    public function test_missing_hold_adds_hold_missing_flag(): void
    {
        $context = $this->createContext();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertSame('missing', $result['hold_status']);
        $this->assertContains('hold_missing', $result['abnormal_flags']);
    }

    public function test_expired_pending_hold_adds_hold_expired_flag(): void
    {
        $context = $this->createContext();
        $hold = $this->createHold($context, [
            'status' => AgentBalanceHold::STATUS_PENDING,
            'expires_at' => time() - 1,
        ]);
        $context->forceFill(['hold_id' => $hold->id])->save();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertSame(AgentBalanceHold::STATUS_EXPIRED, $result['hold_status']);
        $this->assertContains('hold_expired', $result['abnormal_flags']);
    }

    public function test_hold_amount_mismatch_adds_hold_amount_mismatch_flag(): void
    {
        $context = $this->createContext(['cost_amount' => 500]);
        $hold = $this->createHold($context, ['amount' => 499]);
        $context->forceFill(['hold_id' => $hold->id])->save();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertContains('hold_amount_mismatch', $result['abnormal_flags']);
    }

    public function test_disabled_payment_adds_payment_disabled_flag(): void
    {
        $payment = Payment::query()->create([
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => 1,
            'uuid' => 'agent-payment-disabled',
            'payment' => 'stripe',
            'name' => 'Agent Stripe',
            'enable' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $context = $this->createContext(['payment_id' => $payment->id]);
        $hold = $this->createHold($context);
        $context->forceFill(['hold_id' => $hold->id])->save();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertContains('payment_disabled', $result['abnormal_flags']);
    }

    public function test_captured_paid_order_is_captured_without_abnormal_flags(): void
    {
        $context = $this->createContext([
            'status' => AgentOrderContext::STATUS_PAID,
            'sale_amount' => 1300,
            'cost_amount' => 500,
            'order_status' => Order::STATUS_COMPLETED,
        ]);
        $hold = $this->createHold($context, [
            'amount' => 500,
            'status' => AgentBalanceHold::STATUS_CAPTURED,
            'captured_at' => time(),
        ]);
        $context->forceFill(['hold_id' => $hold->id])->save();

        $result = app(AgentOrderStatusResolver::class)->resolve($context->fresh());

        $this->assertSame(AgentBalanceHold::STATUS_CAPTURED, $result['hold_status']);
        $this->assertSame(AgentBalanceHold::STATUS_CAPTURED, $result['capture_status']);
        $this->assertSame(800, $result['margin_amount']);
        $this->assertSame([], $result['abnormal_flags']);
    }

    private function createContext(array $overrides = []): AgentOrderContext
    {
        $now = time();
        $user = User::query()->create([
            'email' => 'buyer-' . uniqid() . '@example.test',
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => uniqid('buyer-uuid-', true),
            'token' => uniqid('buyer-token-', true),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => 1,
            'period' => 'month_price',
            'trade_no' => uniqid('trade-', true),
            'total_amount' => $overrides['sale_amount'] ?? 1300,
            'status' => $overrides['order_status'] ?? Order::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        unset($overrides['order_status']);

        return AgentOrderContext::query()->create(array_merge([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => 1,
            'sale_amount' => 1300,
            'cost_amount' => 500,
            'status' => AgentOrderContext::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    private function createHold(AgentOrderContext $context, array $overrides = []): AgentBalanceHold
    {
        $now = time();

        return AgentBalanceHold::query()->create(array_merge([
            'agent_user_id' => $context->agent_user_id,
            'order_id' => $context->order_id,
            'trade_no' => $context->trade_no,
            'amount' => $context->cost_amount,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'expires_at' => $now + 3600,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }
}
