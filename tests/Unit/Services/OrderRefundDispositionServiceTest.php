<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderRefundDispositionService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class OrderRefundDispositionServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createCommissionLogTable();
    }

    public function test_dispose_bans_user_and_reverses_paid_commission_once(): void
    {
        $buyer = $this->createUser('buyer@example.test');
        $inviter = $this->createUser('inviter@example.test', commissionBalance: 500);
        $order = $this->createCompletedOrder($buyer, $inviter, 'refund-order-1');

        CommissionLog::query()->create([
            'invite_user_id' => $inviter->id,
            'user_id' => $buyer->id,
            'trade_no' => $order->trade_no,
            'order_amount' => 5000,
            'get_amount' => 500,
            'credited_to' => OrderRefundDispositionService::CREDIT_COMMISSION_BALANCE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $service = new OrderRefundDispositionService();
        $first = $service->dispose($order, 99);
        $second = $service->dispose($order->fresh(), 99);

        $this->assertFalse($first['already_processed']);
        $this->assertTrue($second['already_processed']);
        $this->assertSame(500, $first['commission_reversed_amount']);

        $freshOrder = $order->fresh();
        $this->assertSame(Order::COMMISSION_STATUS_INVALID, (int) $freshOrder->commission_status);
        $this->assertSame(5000, (int) $freshOrder->refund_amount);
        $this->assertSame(500, (int) $freshOrder->commission_reversed_amount);
        $this->assertSame(99, (int) $freshOrder->refund_disposed_by);

        $this->assertTrue((bool) $buyer->fresh()->banned);
        $this->assertSame(0, (int) $inviter->fresh()->commission_balance);

        $log = CommissionLog::query()->where('trade_no', $order->trade_no)->firstOrFail();
        $this->assertNotNull($log->reversed_at);
        $this->assertSame(99, (int) $log->reversed_by_admin_id);
    }

    public function test_dispose_invalidates_pending_commission_without_deducting_balance(): void
    {
        $buyer = $this->createUser('pending-buyer@example.test');
        $inviter = $this->createUser('pending-inviter@example.test', commissionBalance: 200);
        $order = $this->createCompletedOrder(
            $buyer,
            $inviter,
            'refund-order-2',
            Order::COMMISSION_STATUS_PENDING
        );

        $result = (new OrderRefundDispositionService())->dispose($order, 100);

        $this->assertSame(0, $result['commission_reversed_amount']);
        $this->assertSame(200, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(Order::COMMISSION_STATUS_INVALID, (int) $order->fresh()->commission_status);
        $this->assertTrue((bool) $buyer->fresh()->banned);
    }

    private function createCommissionLogTable(): void
    {
        app('db')->connection()->getSchemaBuilder()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invite_user_id');
            $table->integer('user_id');
            $table->string('trade_no', 64);
            $table->integer('order_amount');
            $table->integer('get_amount');
            $table->string('credited_to', 32)->nullable();
            $table->integer('reversed_at')->nullable();
            $table->integer('reversed_by_admin_id')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function createUser(string $email, int $commissionBalance = 0): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => $commissionBalance,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createCompletedOrder(
        User $buyer,
        User $inviter,
        string $tradeNo,
        int $commissionStatus = Order::COMMISSION_STATUS_VALID
    ): Order {
        return Order::query()->create([
            'user_id' => $buyer->id,
            'invite_user_id' => $inviter->id,
            'plan_id' => 1,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => 5000,
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => $commissionStatus,
            'commission_balance' => 500,
            'actual_commission_balance' => $commissionStatus === Order::COMMISSION_STATUS_VALID ? 500 : 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
