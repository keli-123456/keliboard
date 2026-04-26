<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentOrderRegressionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindSynchronousBusDispatcher();
        $this->createUserTable();
        $this->createOrderTable();
    }

    public function test_payment_notify_amount_mismatch_does_not_complete_order(): void
    {
        $user = $this->createUser(balance: 0);
        $order = $this->createRechargeOrder($user, [
            'payment_id' => 9,
            'total_amount' => 1000,
            'handling_amount' => 50,
        ]);

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-1',
            'paid_amount' => 1000,
        ], $this->paymentServiceWithId(9));

        $this->assertFalse($handled);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->balance);
    }

    public function test_payment_notify_payment_method_mismatch_does_not_complete_order(): void
    {
        $user = $this->createUser(balance: 0);
        $order = $this->createRechargeOrder($user, [
            'payment_id' => 9,
            'total_amount' => 1000,
            'handling_amount' => 50,
        ]);

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-1',
            'paid_amount' => 1050,
        ], $this->paymentServiceWithId(10));

        $this->assertFalse($handled);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->balance);
    }

    public function test_valid_payment_notify_completes_recharge_order_once(): void
    {
        $user = $this->createUser(balance: 100);
        $order = $this->createRechargeOrder($user, [
            'payment_id' => 9,
            'total_amount' => 1000,
            'handling_amount' => 50,
            'bonus_amount' => 200,
        ]);

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-1',
            'paid_amount' => 1050,
        ], $this->paymentServiceWithId(9));

        $this->assertTrue($handled);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1300, (int) $user->fresh()->balance);
        $this->assertSame('gateway-1', $order->fresh()->callback_no);
    }

    public function test_duplicate_paid_callback_does_not_apply_recharge_twice(): void
    {
        $user = $this->createUser(balance: 100);
        $order = $this->createRechargeOrder($user, [
            'total_amount' => 1000,
            'bonus_amount' => 200,
        ]);

        $this->assertTrue((new OrderService($order))->paid('gateway-1'));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1300, (int) $user->fresh()->balance);

        $this->assertTrue((new OrderService($order->fresh()))->paid('gateway-2'));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertSame(1300, (int) $user->fresh()->balance);
        $this->assertSame('gateway-1', $order->fresh()->callback_no);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createRechargeOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => 0,
            'payment_id' => null,
            'type' => Order::TYPE_RECHARGE,
            'period' => 'recharge',
            'trade_no' => uniqid('trade-', true),
            'total_amount' => 1000,
            'handling_amount' => null,
            'bonus_amount' => 0,
            'status' => Order::STATUS_PENDING,
        ], $overrides));
    }

    private function createUser(int $balance): User
    {
        return User::create([
            'email' => uniqid('user-', true) . '@example.com',
            'password' => 'secret',
            'token' => uniqid('token-', true),
            'uuid' => uniqid('uuid-', true),
            'balance' => $balance,
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
}
