<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class OrderCancelCommissionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
    }

    public function test_cancel_pending_order_marks_pending_commission_invalid(): void
    {
        $buyer = $this->createUser('buyer@example.test');
        $inviter = $this->createUser('inviter@example.test');
        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'invite_user_id' => $inviter->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'cancel-commission-pending',
            'total_amount' => 2000,
            'status' => Order::STATUS_PENDING,
            'commission_status' => Order::COMMISSION_STATUS_PENDING,
            'commission_balance' => 200,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue((new OrderService($order))->cancel());

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $fresh->status);
        $this->assertSame(Order::COMMISSION_STATUS_INVALID, (int) $fresh->commission_status);
        $this->assertSame(200, (int) $fresh->commission_balance);
    }

    public function test_cancel_pending_order_marks_processing_commission_invalid(): void
    {
        $buyer = $this->createUser('buyer-processing@example.test');
        $inviter = $this->createUser('inviter-processing@example.test');
        $order = Order::query()->create([
            'user_id' => $buyer->id,
            'invite_user_id' => $inviter->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'cancel-commission-processing',
            'total_amount' => 3000,
            'status' => Order::STATUS_PENDING,
            'commission_status' => Order::COMMISSION_STATUS_PROCESSING,
            'commission_balance' => 300,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertTrue((new OrderService($order))->cancel());

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_CANCELLED, (int) $fresh->status);
        $this->assertSame(Order::COMMISSION_STATUS_INVALID, (int) $fresh->commission_status);
        $this->assertSame(300, (int) $fresh->commission_balance);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
