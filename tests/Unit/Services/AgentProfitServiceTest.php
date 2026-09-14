<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentCollection;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\AgentCollectionService;
use App\Services\AgentCommerceService;
use App\Services\AgentProfitService;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentProfitServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private AgentProfitService $service;
    private User $agent;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createPaymentTable();
        (require base_path('database/migrations/2026_09_14_190000_create_agent_profit_accounts.php'))->up();
        app('config')->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        app()->instance('encrypter', new \Illuminate\Encryption\Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        $this->agent = User::create(['email' => 'agent@test.local', 'password' => 'test', 'uuid' => 'agent', 'token' => 'agent', 'balance' => 0]);
        AgentProfile::create(['user_id' => $this->agent->id, 'status' => 'active', 'level' => 'default']);
        $this->order = Order::create([
            'user_id' => $this->agent->id, 'plan_id' => 1, 'period' => 'month_price', 'trade_no' => 'profit-order-1',
            'total_amount' => 10000, 'balance_amount' => 0, 'status' => Order::STATUS_COMPLETED, 'paid_at' => time(), 'payment_id' => 1,
        ]);
        AgentOrderContext::create([
            'agent_user_id' => $this->agent->id, 'order_id' => $this->order->id, 'trade_no' => $this->order->trade_no,
            'sale_amount' => 10000, 'cost_amount' => 6000, 'status' => 'paid', 'payment_id' => 1,
            'payment_snapshot' => ['owner_type' => 'platform'],
            'pricing_snapshot' => ['collection' => ['mode' => 'platform', 'fee_bps' => 200, 'fee_fixed' => 0, 'settlement_days' => 7, 'payment_ids' => [1]]],
        ]);
        $this->service = app(AgentProfitService::class);
    }

    private function accrue(): void
    {
        DB::transaction(fn () => $this->service->accrue($this->order));
    }

    private function settled(): void
    {
        $this->accrue();
        DB::table('v2_agent_profit')->update(['available_at' => time() - 1]);
        $this->assertTrue($this->service->settle($this->order->id));
    }

    private function withdraw(int $amount = 2000, string $key = 'test_request_key_0001'): array
    {
        return $this->service->requestWithdrawal($this->agent->id, ['amount' => $amount, 'method' => 'alipay', 'account' => 'test@example.test', 'request_key' => $key]);
    }

    public function test_profit_is_pending_once_and_does_not_modify_operating_balance(): void
    {
        $this->accrue();
        $this->accrue();
        $this->assertEquals(['pending' => 3800, 'available' => 0, 'frozen' => 0, 'debt' => 0], $this->service->summary($this->agent->id));
        $this->assertSame(1, DB::table('v2_agent_profit')->count());
        $this->assertFalse($this->service->settle($this->order->id));
        $this->assertSame(0, (int) $this->agent->fresh()->balance);
    }

    public function test_unfulfilled_and_self_collected_orders_do_not_accrue(): void
    {
        $this->order->status = Order::STATUS_PROCESSING;
        $this->accrue();
        $this->order->status = Order::STATUS_COMPLETED;
        AgentOrderContext::query()->update(['pricing_snapshot' => json_encode([])]);
        $this->accrue();
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
    }

    public function test_balance_funded_order_cannot_create_withdrawable_profit(): void
    {
        $this->order->balance_amount = 1;
        $this->expectException(ApiException::class);
        $this->accrue();
    }

    public function test_settlement_is_idempotent(): void
    {
        $this->settled();
        $this->assertFalse($this->service->settle($this->order->id));
        $this->assertEquals(3800, $this->service->summary($this->agent->id)['available']);
    }

    public function test_request_freezes_once_and_encrypts_account(): void
    {
        $this->settled();
        $a = $this->withdraw();
        $b = $this->withdraw();
        $this->assertSame($a['id'], $b['id']);
        $this->assertEquals(2000, $this->service->summary($this->agent->id)['frozen']);
        $this->assertEquals(1800, $this->service->summary($this->agent->id)['available']);
        $this->assertNotSame($a['account'], DB::table('v2_agent_profit_withdrawal')->value('account'));
    }

    public function test_conflicting_request_key_is_rejected(): void
    {
        $this->settled();
        $this->withdraw();
        $this->expectException(ApiException::class);
        $this->withdraw(1000);
    }

    public function test_pending_profit_cannot_be_withdrawn(): void
    {
        $this->accrue();
        $this->expectException(ApiException::class);
        $this->withdraw();
    }

    public function test_paid_requires_approval_and_reference(): void
    {
        $this->settled();
        $record = $this->withdraw();
        $this->expectException(ApiException::class);
        $this->service->review($record['id'], 'paid', 99, 'bank-123', '');
    }

    public function test_manual_paid_and_retry_does_not_double_debit(): void
    {
        $this->settled();
        $record = $this->withdraw();
        $this->service->review($record['id'], 'approve', 99, '', 'checked');
        $this->service->review($record['id'], 'paid', 99, 'bank-123', '');
        $this->service->review($record['id'], 'paid', 99, 'bank-123', '');
        $this->assertEquals(0, $this->service->summary($this->agent->id)['frozen']);
        $this->assertEquals(1800, $this->service->summary($this->agent->id)['available']);
    }

    public function test_refund_blocks_payout_and_rejection_offsets_debt(): void
    {
        $this->settled();
        $record = $this->withdraw();
        $this->service->review($record['id'], 'approve', 99, '', '');
        DB::transaction(fn () => $this->service->reverse($this->order, 99));
        DB::transaction(fn () => $this->service->reverse($this->order, 99));
        $this->assertEquals(2000, $this->service->summary($this->agent->id)['debt']);
        try {
            $this->service->review($record['id'], 'paid', 99, 'bank-123', '');
            $this->fail('Refund debt must block payout');
        } catch (ApiException $e) {
            $this->assertStringContainsString('退款', $e->getMessage());
        }
        $this->service->review($record['id'], 'reject', 99, '', 'order refunded');
        $this->assertEquals(['pending' => 0, 'available' => 0, 'frozen' => 0, 'debt' => 0], $this->service->summary($this->agent->id));
    }

    public function test_refund_pending_profit_prevents_settlement(): void
    {
        $this->accrue();
        DB::transaction(fn () => $this->service->reverse($this->order));
        $this->assertFalse($this->service->settle($this->order->id));
        $this->assertEquals(0, $this->service->summary($this->agent->id)['pending']);
    }

    public function test_platform_capture_does_not_debit_agent_and_uses_order_snapshot(): void
    {
        $payment = Payment::create(['uuid' => 'official-test', 'name' => 'Official', 'payment' => 'EPay', 'owner_type' => 'platform', 'enable' => 1]);
        $this->order->payment_id = $payment->id;
        AgentOrderContext::query()->update(['payment_id' => $payment->id]);
        app(AgentCommerceService::class)->captureForPaidOrder($this->order);
        $this->assertSame(0, (int) $this->agent->fresh()->balance);
        $this->assertSame(0, DB::table('v2_agent_balance_hold')->count());
        $this->assertSame('platform', app(AgentCollectionService::class)->forOrder(AgentOrderContext::first())['mode']);
    }

    public function test_refund_after_paid_withdrawal_is_offset_by_future_earnings(): void
    {
        $this->settled();
        $record = $this->withdraw();
        $this->service->review($record['id'], 'approve', 99, '', '');
        $this->service->review($record['id'], 'paid', 99, 'test-real-reference', '');
        DB::transaction(fn () => $this->service->reverse($this->order, 99));
        $this->assertEquals(2000, $this->service->summary($this->agent->id)['debt']);
        $next = $this->order->replicate();
        $next->trade_no = 'profit-order-2';
        $next->save();
        $context = AgentOrderContext::first()->replicate();
        $context->order_id = $next->id;
        $context->trade_no = $next->trade_no;
        $context->save();
        DB::transaction(fn () => $this->service->accrue($next));
        DB::table('v2_agent_profit')->where('order_id', $next->id)->update(['available_at' => time() - 1]);
        $this->service->settle($next->id);
        $this->assertEquals(['pending' => 0, 'available' => 1800, 'frozen' => 0, 'debt' => 0], $this->service->summary($this->agent->id));
    }

    public function test_overdraw_is_rejected_without_new_withdrawal_record(): void
    {
        $this->settled();
        $this->withdraw();
        try {
            $this->withdraw(2000, 'test_request_key_0002');
            $this->fail('Cannot freeze more than available earnings');
        } catch (ApiException $e) {
            $this->assertSame(1, DB::table('v2_agent_profit_withdrawal')->count());
            $this->assertEquals(1800, $this->service->summary($this->agent->id)['available']);
        }
    }

    public function test_banned_agent_cannot_request_withdrawal(): void
    {
        $this->settled();
        $this->agent->banned = true;
        $this->agent->save();
        $this->expectException(ApiException::class);
        $this->withdraw();
    }

    public function test_non_admin_cannot_read_financial_configuration(): void
    {
        $request = \Illuminate\Http\Request::create('/admin/agent-operations/profit');
        $request->setUserResolver(fn () => $this->agent);
        try {
            (new \App\Http\Controllers\V2\Admin\AgentProfitController())->show($request, $this->agent->id);
            $this->fail('Only admins may access financial configuration');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_collection_history_protects_financial_documents_from_user_deletion(): void
    {
        $this->assertTrue($this->service->hasCollectionHistory($this->agent->id));
        $this->assertFalse($this->service->hasCollectionHistory(999));
    }

    public function test_configuration_cannot_authorize_an_agent_owned_channel(): void
    {
        $payment = Payment::create(['uuid' => 'private-test', 'name' => 'Private', 'payment' => 'EPay', 'owner_type' => 'agent', 'owner_id' => $this->agent->id]);
        $this->expectException(ApiException::class);
        app(AgentCollectionService::class)->configure($this->agent->id, [
            'platform_enabled' => true, 'payment_ids' => [$payment->id], 'fee_bps' => 0, 'fee_fixed' => 0,
            'settlement_days' => 7, 'minimum_withdrawal' => 1000,
        ], 99);
    }
}
