<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Console\Commands\CheckCommission;
use App\Exceptions\ApiException;
use App\Models\AgentUser;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Services\Auth\RegisterService;
use App\Services\OrderService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentReferralBoundaryTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindTestSettings(['invite_force' => 0, 'invite_never_expire' => 0]);
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->database->schema()->create('v2_invite_code', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('code');
            $table->integer('status')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invite_user_id');
            $table->integer('user_id');
            $table->string('trade_no');
            $table->integer('order_amount');
            $table->integer('get_amount');
            $table->string('credited_to')->nullable();
            $table->integer('reversed_at')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    public function test_old_agent_subordinate_code_is_not_attributed_or_consumed(): void
    {
        $inviter = $this->user();
        $this->bindAgent($inviter);
        $code = InviteCode::forceCreate(['user_id' => $inviter->id, 'code' => 'old-code']);
        $this->assertNull(DB::transaction(fn () => app(RegisterService::class)->handleInviteCode('old-code')));
        $this->assertSame(0, (int) $code->fresh()->status);
    }

    public function test_forced_invitation_rejects_old_subordinate_code(): void
    {
        $this->bindTestSettings(['invite_force' => 1]);
        $inviter = $this->user();
        $this->bindAgent($inviter);
        InviteCode::forceCreate(['user_id' => $inviter->id, 'code' => 'old-code']);
        $this->expectException(ApiException::class);
        DB::transaction(fn () => app(RegisterService::class)->handleInviteCode('old-code'));
    }

    public function test_normal_invite_code_still_works(): void
    {
        $inviter = $this->user();
        $code = InviteCode::forceCreate(['user_id' => $inviter->id, 'code' => 'normal-code']);
        $this->assertSame((int) $inviter->id, DB::transaction(fn () => app(RegisterService::class)->handleInviteCode('normal-code')));
        $this->assertSame(1, (int) $code->fresh()->status);
    }

    public function test_order_does_not_accrue_to_a_subordinate_inviter(): void
    {
        $inviter = $this->user(['commission_rate' => 20, 'commission_type' => 2]);
        $this->bindAgent($inviter);
        $buyer = $this->user(['invite_user_id' => $inviter->id]);
        $order = new Order(['total_amount' => 10000, 'invite_user_id' => $inviter->id, 'commission_balance' => 999]);
        (new OrderService($order))->setInvite($buyer);
        $this->assertSame(0, (int) $order->commission_balance);
        $this->assertNull($order->invite_user_id);
        $this->assertSame($inviter->id, $buyer->fresh()->invite_user_id);
    }

    public function test_pending_order_is_not_auto_approved_after_inviter_becomes_subordinate(): void
    {
        [$inviter, , $order] = $this->fixture(['commission_status' => Order::COMMISSION_STATUS_PENDING]);
        DB::table('v2_order')->where('id', $order->id)->update(['updated_at' => time() - 4 * 86400]);
        $this->bindAgent($inviter);
        (new CheckCommission())->autoCheck();
        $this->assertSame(Order::COMMISSION_STATUS_PENDING, (int) $order->fresh()->commission_status);
    }

    public function test_processing_order_does_not_pay_a_newly_bound_inviter(): void
    {
        [$inviter, , $order] = $this->fixture();
        $this->bindAgent($inviter);
        (new CheckCommission())->autoPayCommission();
        $this->assertUnpaid($inviter, $order);
    }

    public function test_subordinate_higher_level_blocks_the_whole_distribution(): void
    {
        $this->distribution([70, 20, 10]);
        [$inviter, , $order] = $this->fixture();
        $parent = $this->user();
        $inviter->update(['invite_user_id' => $parent->id]);
        $this->bindAgent($parent);
        (new CheckCommission())->autoPayCommission();
        $this->assertUnpaid($inviter, $order);
        $this->assertSame(0, (int) $parent->fresh()->commission_balance);
    }

    public function test_over_budget_distribution_writes_nothing(): void
    {
        $this->distribution([100, 50, 0]);
        [$inviter, , $order] = $this->fixture();
        $inviter->update(['invite_user_id' => $this->user()->id]);
        (new CheckCommission())->autoPayCommission();
        $this->assertUnpaid($inviter, $order);
    }

    public function test_cyclic_invite_chain_writes_nothing(): void
    {
        $this->distribution([70, 20, 10]);
        [$inviter, $buyer, $order] = $this->fixture();
        $inviter->update(['invite_user_id' => $buyer->id]);
        (new CheckCommission())->autoPayCommission();
        $this->assertUnpaid($inviter, $order);
    }

    public function test_cancelled_or_refunded_orders_never_pay(): void
    {
        foreach ([['status' => Order::STATUS_CANCELLED], ['refund_amount' => 1000], ['refund_disposed_at' => time()]] as $state) {
            [$inviter, , $order] = $this->fixture($state);
            (new CheckCommission())->autoPayCommission();
            $this->assertUnpaid($inviter, $order);
        }
    }

    public function test_existing_ledger_is_not_paid_twice_when_status_was_reset(): void
    {
        [$inviter, $buyer, $order] = $this->fixture();
        CommissionLog::create(['invite_user_id' => $inviter->id, 'user_id' => $buyer->id,
            'trade_no' => $order->trade_no, 'order_amount' => 10000, 'get_amount' => 1000]);
        $inviter->update(['commission_balance' => 1000]);
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::count());
        $this->assertSame(Order::COMMISSION_STATUS_PROCESSING, (int) $order->fresh()->commission_status);
    }

    public function test_normal_distribution_is_integer_bounded_and_idempotent(): void
    {
        $this->distribution([70, 20, 10]);
        [$inviter, , $order] = $this->fixture(['commission_balance' => 101]);
        $second = $this->user();
        $third = $this->user();
        $inviter->update(['invite_user_id' => $second->id]);
        $second->update(['invite_user_id' => $third->id]);
        $command = new CheckCommission();
        $command->autoPayCommission();
        $command->autoPayCommission();
        $this->assertSame([70, 20, 10], CommissionLog::orderBy('id')->pluck('get_amount')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(100, (int) $order->fresh()->actual_commission_balance);
        $this->assertSame(Order::COMMISSION_STATUS_VALID, (int) $order->fresh()->commission_status);
    }

    public function test_direct_settlement_entry_cannot_bypass_agent_order_guard(): void
    {
        [$inviter, $buyer, $order] = $this->fixture();
        $this->bindAgent($buyer);
        try {
            (new CheckCommission())->payHandle($inviter->id, $order);
            $this->fail('Agent order must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Agent orders', $e->getMessage());
        }
        $this->assertUnpaid($inviter, $order);
    }

    public function test_ledger_failure_rolls_back_all_recipients(): void
    {
        $this->distribution([70, 30, 0]);
        [$inviter, , $order] = $this->fixture();
        $second = $this->user();
        $inviter->update(['invite_user_id' => $second->id]);
        DB::statement('CREATE TRIGGER fail_second_commission BEFORE INSERT ON v2_commission_log '
            . 'WHEN NEW.invite_user_id = ' . (int) $second->id . " BEGIN SELECT RAISE(ABORT, 'test ledger failure'); END");
        (new CheckCommission())->autoPayCommission();
        $this->assertUnpaid($inviter, $order);
        $this->assertSame(0, (int) $second->fresh()->commission_balance);
    }

    public function test_zero_first_share_does_not_shift_second_level_recipient(): void
    {
        $this->distribution([0, 100, 0]);
        [$inviter, , $order] = $this->fixture();
        $second = $this->user();
        $inviter->update(['invite_user_id' => $second->id]);
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(0, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1000, (int) $second->fresh()->commission_balance);
        $this->assertSame(1000, (int) $order->fresh()->actual_commission_balance);
    }

    public function test_normal_commission_can_still_credit_ordinary_balance(): void
    {
        $this->bindTestSettings(['withdraw_close_enable' => 1]);
        [$inviter, , $order] = $this->fixture();
        (new CheckCommission())->autoPayCommission();
        $this->assertSame(1000, (int) $inviter->fresh()->balance);
        $this->assertSame(0, (int) $inviter->fresh()->commission_balance);
        $this->assertSame('balance', CommissionLog::where('trade_no', $order->trade_no)->value('credited_to'));
    }

    public function test_admin_cannot_approve_subordinate_referral_but_can_reject_it(): void
    {
        $this->bindJsonResponseFactory();
        [$inviter, , $order] = $this->fixture(['commission_status' => Order::COMMISSION_STATUS_PENDING]);
        $this->bindAgent($inviter);
        $controller = app(\App\Http\Controllers\V2\Admin\OrderController::class);
        $request = \App\Http\Requests\Admin\OrderUpdate::create('/test', 'POST', [
            'trade_no' => $order->trade_no, 'commission_status' => Order::COMMISSION_STATUS_PROCESSING,
        ]);
        $this->assertSame('fail', $controller->update($request)->getData(true)['status']);
        $this->assertSame(Order::COMMISSION_STATUS_PENDING, (int) $order->fresh()->commission_status);
        $request->merge(['commission_status' => Order::COMMISSION_STATUS_INVALID]);
        $this->assertSame('success', $controller->update($request)->getData(true)['status']);
        $this->assertSame(Order::COMMISSION_STATUS_INVALID, (int) $order->fresh()->commission_status);
    }

    public function test_admin_can_still_approve_normal_paid_order(): void
    {
        $this->bindJsonResponseFactory();
        [, , $order] = $this->fixture(['commission_status' => Order::COMMISSION_STATUS_PENDING]);
        $request = \App\Http\Requests\Admin\OrderUpdate::create('/test', 'POST', [
            'trade_no' => $order->trade_no, 'commission_status' => Order::COMMISSION_STATUS_PROCESSING,
        ]);
        $response = app(\App\Http\Controllers\V2\Admin\OrderController::class)->update($request);
        $this->assertSame('success', $response->getData(true)['status']);
        $this->assertSame(Order::COMMISSION_STATUS_PROCESSING, (int) $order->fresh()->commission_status);
    }

    private function assertUnpaid(User $inviter, Order $order): void
    {
        $this->assertSame(0, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(0, CommissionLog::where('trade_no', $order->trade_no)->count());
        $this->assertSame(Order::COMMISSION_STATUS_PROCESSING, (int) $order->fresh()->commission_status);
    }

    private function fixture(array $overrides = []): array
    {
        $inviter = $this->user();
        $buyer = $this->user(['invite_user_id' => $inviter->id]);
        $order = Order::create(array_merge(['user_id' => $buyer->id, 'invite_user_id' => $inviter->id,
            'plan_id' => 1, 'period' => 'month_price', 'trade_no' => 'referral-' . $buyer->id,
            'total_amount' => 10000, 'commission_balance' => 1000, 'status' => Order::STATUS_COMPLETED,
            'paid_at' => time(), 'commission_status' => Order::COMMISSION_STATUS_PROCESSING], $overrides));
        return [$inviter, $buyer, $order];
    }

    private function user(array $overrides = []): User
    {
        return User::create(array_merge(['email' => 'user-' . (User::count() + 1) . '@example.test',
            'password' => 'unused', 'balance' => 0, 'commission_balance' => 0], $overrides));
    }

    private function bindAgent(User $user): void
    {
        AgentUser::create(['sub_user_id' => $user->id, 'agent_user_id' => $this->user()->id]);
    }

    private function distribution(array $shares): void
    {
        $this->bindTestSettings(['commission_distribution_enable' => 1,
            'commission_distribution_l1' => $shares[0], 'commission_distribution_l2' => $shares[1],
            'commission_distribution_l3' => $shares[2]]);
    }
}
