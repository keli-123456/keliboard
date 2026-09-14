<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\AgentPlanOverride;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceService;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->createOrderTable();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
        ]);
    }

    public function test_platform_collection_fulfills_and_accrues_without_debiting_agent_balance(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $commerce = app(AgentCommerceService::class);
        $order = $commerce->createOrderFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY, null, $this->requestForHost('profit.example.test'));
        $this->assertSame(0, AgentBalanceHold::count());
        $this->assertSame(0, (int) $agent->fresh()->balance);
        $settings = \App\Models\AgentCollection::find($agent->id);
        $settings->mode = 'self';
        $settings->fee_bps = 9000;
        $settings->save();
        $order = $commerce->assignPaymentForCheckout($order, $payment, null);
        DB::transaction(function () use ($commerce, $order) {
            $commerce->captureForPaidOrder($order);
            $order->status = Order::STATUS_PROCESSING;
            $order->paid_at = time();
            $order->save();
        });
        (new OrderService($order))->open();
        (new OrderService($order->fresh()))->open();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertGreaterThan((int) $buyer->expired_at, (int) $buyer->fresh()->expired_at);
        $this->assertEquals(774, app(\App\Services\AgentProfitService::class)->summary($agent->id)['pending']);
        $this->assertSame(774, app(\App\Services\AgentOperationsService::class)->agentSummary($agent)['month_margin_total']);
        $this->assertSame(1, DB::table('v2_agent_profit')->count());
        $this->assertSame(0, (int) $agent->fresh()->balance);
        $resolver = app(\App\Services\AgentOrderStatusResolver::class);
        $this->assertSame([], $resolver->resolve(AgentOrderContext::first())['abnormal_flags']);
        $this->assertSame(0, $resolver->filterAbnormal(AgentOrderContext::query())->count());
    }

    public function test_platform_collection_does_not_accrue_when_fulfillment_fails(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $commerce = app(AgentCommerceService::class);
        $order = $commerce->createOrderFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY, null, $this->requestForHost('profit.example.test'));
        $order = $commerce->assignPaymentForCheckout($order, $payment, null);
        $commerce->captureForPaidOrder($order);
        $order->status = Order::STATUS_PROCESSING;
        $order->paid_at = time();
        $order->save();
        $plan->delete();
        try {
            (new OrderService($order))->open();
            $this->fail('Missing plan must prevent fulfillment');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('套餐不存在', $e->getMessage());
        }
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_platform_collection_rejects_unverified_balance_but_accepts_recharge_without_bonus(): void
    {
        [$agent, $buyer, $plan] = $this->platformFixture();
        AgentUser::create(['agent_user_id' => $agent->id, 'sub_user_id' => $buyer->id]);
        $buyer->invite_user_id = $agent->id;
        $buyer->balance = 99999;
        $buyer->save();
        try {
            app(AgentCommerceService::class)->createAutoRenewOrder($buyer, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('Unverified balance must not fund platform-collected orders');
        } catch (ApiException $e) {
            $this->assertSame('Insufficient balance', $e->getMessage());
        }
        $order = app(AgentCommerceService::class)->createRechargeOrderFromRequest($buyer, 1000, 100, $this->requestForHost('profit.example.test'));
        $this->assertSame(0, (int) $order->bonus_amount);
        $this->assertSame(1, Order::count());
        $this->assertSame(99999, (int) $buyer->fresh()->balance);
    }

    private function platformFixture(bool $prepaidSchema = true): array
    {
        $this->createPaymentTable();
        (require base_path('database/migrations/2026_09_14_190000_create_agent_profit_accounts.php'))->up();
        if ($prepaidSchema) {
            (require base_path('database/migrations/2026_09_14_200000_create_agent_prepaid_funds.php'))->up();
        }
        $agent = $this->createActiveAgent('profit-agent@example.test', 0);
        $this->assignDomain($agent, 'profit.example.test');
        $buyer = $this->createUser('profit-buyer@example.test');
        $plan = $this->createPlan('Profit Plan', [Plan::PERIOD_MONTHLY => 10.00]);
        $buyer->plan_id = $plan->id;
        $buyer->expired_at = time() + 86400;
        $buyer->save();
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);
        $payment = \App\Models\Payment::create(['name' => 'Official', 'uuid' => 'test-official', 'payment' => 'EPay', 'owner_type' => 'platform', 'enable' => true]);
        \App\Models\AgentCollection::create([
            'agent_user_id' => $agent->id, 'mode' => 'platform', 'platform_enabled' => true,
            'payment_ids' => [$payment->id], 'fee_bps' => 200, 'fee_fixed' => 0,
            'settlement_days' => 7, 'minimum_withdrawal' => 1000, 'updated_at' => time(),
        ]);
        return [$agent, $buyer, $plan, $payment];
    }

    private function payPlatform(Order $order, \App\Models\Payment $payment): Order
    {
        $this->bindSynchronousBusDispatcher();
        if ((int) $order->total_amount > 0 && (int) $order->status === Order::STATUS_PENDING) {
            $order = app(AgentCommerceService::class)->assignPaymentForCheckout($order, $payment, null);
        }
        $this->assertTrue((new OrderService($order))->paid('test-' . $order->trade_no));
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        return $order->fresh();
    }

    private function prepaidRecharge(User $buyer, \App\Models\Payment $payment, int $amount = 2600): Order
    {
        $order = app(AgentCommerceService::class)->createRechargeOrderFromRequest($buyer, $amount, 100,
            $this->requestForHost('profit.example.test', $buyer));
        return $this->payPlatform($order, $payment);
    }

    public static function processingRefundKinds(): array
    {
        return ['recharge' => [true], 'subscription' => [false]];
    }

    public function test_platform_checkout_rejects_negative_handling_fee(): void
    {
        [, $buyer, , $payment] = $this->platformFixture();
        $commerce = app(AgentCommerceService::class);
        $order = $commerce->createRechargeOrderFromRequest($buyer, 2600, 0, $this->requestForHost('profit.example.test', $buyer));
        try {
            $commerce->assignPaymentForCheckout($order, $payment, -100);
            $this->fail('A collection payment must not collect less than the credited principal.');
        } catch (ApiException $exception) {
            $this->assertNull($order->fresh()->payment_id);
            $this->assertNull($order->fresh()->handling_amount);
        }
    }

    public function test_legacy_negative_handling_fee_cannot_credit_platform_balance(): void
    {
        [, $buyer, , $payment] = $this->platformFixture();
        $this->bindSynchronousBusDispatcher();
        $commerce = app(AgentCommerceService::class);
        $order = $commerce->createRechargeOrderFromRequest($buyer, 2600, 0, $this->requestForHost('profit.example.test', $buyer));
        $order = $commerce->assignPaymentForCheckout($order, $payment, null);
        $order->update(['handling_amount' => -100]);

        $this->assertFalse((new OrderService($order))->paid('short-receipt'));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(0, DB::table('v2_agent_prepaid_fund')->count());
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('processingRefundKinds')]
    public function test_refund_before_fulfillment_cannot_credit_or_deliver_on_retry(bool $recharge): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        DB::getSchemaBuilder()->create('v2_commission_log', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->id();
            $table->string('trade_no');
            $table->integer('reversed_at')->nullable();
        });
        $commerce = app(AgentCommerceService::class);
        $request = $this->requestForHost('profit.example.test', $buyer);
        $order = $recharge ? $commerce->createRechargeOrderFromRequest($buyer, 2600, 0, $request)
            : $commerce->createOrderFromRequest($buyer, $plan, Plan::PERIOD_MONTHLY, null, $request);
        $order = $commerce->assignPaymentForCheckout($order, $payment, null);
        DB::transaction(function () use ($commerce, $order): void {
            $commerce->captureForPaidOrder($order);
            $order->status = Order::STATUS_PROCESSING;
            $order->paid_at = time();
            $order->save();
        });
        $expiry = $buyer->fresh()->expired_at;
        app(\App\Services\OrderRefundDispositionService::class)->dispose($order, 99);

        (new OrderService($order))->open();
        (new OrderService($order->fresh()))->open();

        $this->assertSame(0, DB::table('v2_agent_prepaid_fund')->count());
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
        $this->assertSame(0, (int) $buyer->fresh()->balance);
        $this->assertSame($expiry, $buyer->fresh()->expired_at);
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
        $this->assertTrue((bool) $buyer->fresh()->banned);
    }

    public function test_platform_recharge_credits_only_principal_once_and_never_accrues_profit(): void
    {
        [$agent, $buyer, , $payment] = $this->platformFixture();
        $order = $this->prepaidRecharge($buyer, $payment);
        $this->payPlatform($order, $payment);
        $fund = DB::table('v2_agent_prepaid_fund')->first();
        $this->assertSame(2600, (int) $fund->remaining_amount);
        $this->assertSame(52, (int) $fund->remaining_fee);
        $this->assertSame(0, (int) $buyer->fresh()->balance);
        $this->assertSame(0, (int) $agent->fresh()->balance);
        $this->assertSame(1, DB::table('v2_agent_prepaid_fund')->count());
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
        $this->assertSame(0, app(\App\Services\AgentOperationsService::class)->agentSummary($agent)['month_margin_total']);
        $this->assertSame(0, app(\App\Services\AgentOperationsService::class)->agentSummary($agent)['month_sales_total']);
    }

    public function test_auto_renew_uses_prepaid_principal_with_original_fee_not_current_fee(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment);
        \App\Models\AgentCollection::whereKey($agent->id)->update(['fee_bps' => 2000, 'fee_fixed' => 100, 'platform_enabled' => false]);
        $order = app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(1300, (int) $order->balance_amount);
        $this->assertSame(0, (int) $order->total_amount);
        $order = $this->payPlatform($order, $payment);
        $this->assertSame(1300, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame(26, (int) DB::table('v2_agent_profit')->value('fee_amount'));
        $this->assertSame(774, (int) DB::table('v2_agent_profit')->value('amount'));
        $this->assertSame(1300, app(\App\Services\AgentOperationsService::class)->agentSummary($agent)['month_sales_total']);
        $this->assertSame(0, (int) $agent->fresh()->balance);
        $this->assertSame(774, app(\App\Services\AgentOrderStatusResolver::class)->resolve(AgentOrderContext::where('order_id', $order->id)->first())['margin_amount']);
    }

    public function test_scheduled_auto_renew_completes_with_zero_ordinary_balance(): void
    {
        [$agent, $buyer, , $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment);
        $buyer->refresh();
        $buyer->auto_renew_enable = true;
        $buyer->auto_renew_period = Plan::PERIOD_MONTHLY;
        $buyer->save();
        (new \App\Console\Commands\AutoRenewOrders())->handle();
        (new \App\Console\Commands\AutoRenewOrders())->handle();
        $this->assertSame(2, Order::count());
        $this->assertGreaterThan(time() + 86400, (int) $buyer->fresh()->expired_at);
        $this->assertSame(1300, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
    }

    public function test_mixed_payment_apportions_recharge_fee_and_only_charges_external_remainder(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        \App\Models\AgentCollection::whereKey($agent->id)->update(['fee_fixed' => 100]);
        $this->prepaidRecharge($buyer, $payment, 1000);
        $order = app(AgentCommerceService::class)->createOrderFromRequest($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY, null,
            $this->requestForHost('profit.example.test', $buyer));
        $this->assertSame(300, (int) $order->total_amount);
        $this->assertSame(1000, (int) $order->balance_amount);
        $this->payPlatform($order, $payment);
        $this->assertSame(226, (int) DB::table('v2_agent_profit')->value('fee_amount'));
        $this->assertSame(574, (int) DB::table('v2_agent_profit')->value('amount'));
        $this->assertSame(0, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
    }

    public function test_cancel_restores_the_same_prepaid_fund_and_fee_once(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment);
        $order = app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertFalse((new OrderService($order))->cancel());
        $this->assertSame(2600, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame(52, (int) DB::table('v2_agent_prepaid_fund')->value('remaining_fee'));
        $this->assertSame(0, (int) $buyer->fresh()->balance);
        $this->assertSame('released', DB::table('v2_agent_prepaid_allocation')->value('status'));
    }

    public function test_mode_switch_cannot_strand_prepaid_funds(): void
    {
        [$agent, $buyer, , $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment);
        $this->expectException(ApiException::class);
        app(\App\Services\AgentCollectionService::class)->setMode($agent->id, 'self');
    }

    public function test_recharge_refund_revokes_unspent_funds_and_related_profit(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $recharge = $this->prepaidRecharge($buyer, $payment);
        $order = app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
        $this->payPlatform($order, $payment);
        DB::transaction(fn () => app(\App\Services\AgentProfitService::class)->reverseAffectedOrders($recharge, 99));
        DB::transaction(fn () => app(\App\Services\AgentProfitService::class)->reverseAffectedOrders($recharge, 99));
        $this->assertSame(0, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame('reversed', DB::table('v2_agent_profit')->value('status'));
        $this->assertEquals(0, app(\App\Services\AgentProfitService::class)->summary($agent->id)['pending']);
    }

    public function test_refunded_recharge_cannot_fund_a_reserved_order_or_reappear_on_cancel(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $recharge = $this->prepaidRecharge($buyer, $payment);
        $order = app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
        DB::transaction(fn () => app(\App\Services\AgentProfitService::class)->reverseAffectedOrders($recharge, 99));
        $this->assertFalse((new OrderService($order))->paid('invalid-funding'));
        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(0, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame(0, DB::table('v2_agent_profit')->count());
    }

    public function test_prepaid_principal_cannot_be_spent_by_another_agent(): void
    {
        [, $buyer, , $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment);
        $order = new Order(['user_id' => $buyer->id]);
        $this->expectException(ApiException::class);
        DB::transaction(fn () => app(\App\Services\AgentPrepaidService::class)->reserve($order, 99999, 1000));
    }

    public static function upgradeFundingModes(): array
    {
        return ['external' => [false, false], 'prepaid' => [true, false], 'cancel_prepaid' => [true, true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('upgradeFundingModes')]
    public function test_platform_discount_upgrade_uses_verified_cost_and_accrues_only_the_difference(bool $usePrepaid, bool $cancel): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $source = $this->payPlatform(app(AgentCommerceService::class)->createOrderFromRequest($buyer, $plan,
            Plan::PERIOD_MONTHLY, null, $this->requestForHost('profit.example.test')), $payment);
        if ($usePrepaid) {
            $this->prepaidRecharge($buyer->fresh(), $payment, 5200);
        }
        $target = $this->createPlan('Upgrade', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->setAgentPrice($agent, $target, Plan::PERIOD_MONTHLY, 2600);
        $plan->upgrade_to_plan_ids = [$target->id];
        $plan->save();
        $this->createOrderUpgradeQuoteTable();
        $this->bindTestSettings(['upgrade_v2_enable' => true, 'plan_change_enable' => true, 'agent_center_discount_percent' => 50]);
        $buyer->refresh();
        $preview = app(\App\Services\OrderUpgradeService::class)->previewUpgrade($buyer, $target, Plan::PERIOD_MONTHLY);
        $this->assertTrue($preview['allow_upgrade'], (string) $preview['reason']);
        $order = app(\App\Services\OrderUpgradeService::class)->confirmUpgrade($buyer, $preview['quote_token']);
        $context = AgentOrderContext::where('order_id', $order->id)->first();
        $this->assertNull($context->hold_id);
        $this->assertLessThan(2600, (int) $context->sale_amount);
        $this->assertLessThan(1000, (int) $context->cost_amount);
        $this->assertSame($plan->id, (int) $buyer->fresh()->plan_id);
        if ($usePrepaid) {
            $this->assertSame(0, (int) $order->total_amount);
            $this->assertSame((int) $context->sale_amount, (int) $order->balance_amount);
        }
        if ($cancel) {
            $beforeExpiry = $buyer->fresh()->expired_at;
            $this->assertTrue((new OrderService($order))->cancel());
            $this->assertSame($plan->id, (int) $buyer->fresh()->plan_id);
            $this->assertSame($beforeExpiry, $buyer->fresh()->expired_at);
            $this->assertSame(5200, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
            $this->assertSame(1, DB::table('v2_agent_profit')->count());
            return;
        }
        $this->payPlatform($order, $payment);
        $this->assertSame($target->id, (int) $buyer->fresh()->plan_id);
        $this->assertSame(0, (int) $agent->fresh()->balance);
        $this->assertSame(2, DB::table('v2_agent_profit')->count());
        DB::transaction(fn () => app(\App\Services\AgentProfitService::class)->reverseAffectedOrders($source, 99));
        $this->assertSame(2, DB::table('v2_agent_profit')->where('status', 'reversed')->count());
    }

    public function test_margin_failure_rolls_back_prepaid_reservation(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        \App\Models\AgentCollection::whereKey($agent->id)->update(['fee_bps' => 8000]);
        $this->prepaidRecharge($buyer, $payment, 1300);
        try {
            app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
            $this->fail('Insufficient margin must not create an unfunded profit');
        } catch (ApiException $e) {
            $this->assertStringContainsString('成本', $e->getMessage());
        }
        $this->assertSame(1300, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame(0, DB::table('v2_agent_prepaid_allocation')->count());
        $this->assertSame(1, Order::count());
    }

    public function test_recharge_cannot_start_before_prepaid_schema_is_ready(): void
    {
        [, $buyer] = $this->platformFixture(false);
        try {
            app(AgentCommerceService::class)->createRechargeOrderFromRequest($buyer, 1000, 0, $this->requestForHost('profit.example.test', $buyer));
            $this->fail('Do not collect money before the prepaid ledger is ready');
        } catch (ApiException $e) {
            $this->assertStringContainsString('数据库升级', $e->getMessage());
        }
        $this->assertSame(0, Order::count());
    }

    public function test_multiple_recharge_lots_preserve_exact_total_fees(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $this->prepaidRecharge($buyer, $payment, 1301);
        $this->prepaidRecharge($buyer->fresh(), $payment, 1299);
        for ($i = 0; $i < 2; $i++) {
            $this->payPlatform(app(AgentCommerceService::class)->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY), $payment);
        }
        $this->assertSame(0, app(\App\Services\AgentPrepaidService::class)->balance($buyer->id, $agent->id));
        $this->assertSame(53, (int) DB::table('v2_agent_profit')->sum('fee_amount'));
        $this->assertSame(53, (int) DB::table('v2_agent_prepaid_fund')->sum('fee_amount'));
        $this->assertSame(0, (int) DB::table('v2_agent_prepaid_fund')->sum('remaining_fee'));
    }

    public function test_repeated_recharge_spend_cancel_and_retry_conserve_every_cent(): void
    {
        [$agent, $buyer, $plan, $payment] = $this->platformFixture();
        $prepaid = app(\App\Services\AgentPrepaidService::class);
        $commerce = app(AgentCommerceService::class);
        for ($i = 0; $i < 30; $i++) {
            if ($prepaid->balance($buyer->id, $agent->id) < 1300) {
                \App\Models\AgentCollection::whereKey($agent->id)->update(['fee_bps' => 200 + ($i % 4) * 17, 'fee_fixed' => $i % 3]);
                $this->prepaidRecharge($buyer->fresh(), $payment, 2601 + $i * 13);
            }
            $order = $commerce->createAutoRenewOrder($buyer->fresh(), $plan, Plan::PERIOD_MONTHLY);
            $this->assertPrepaidConservation();
            if ($i % 3 === 0) {
                $this->assertTrue((new OrderService($order))->cancel());
                $this->assertFalse((new OrderService($order))->cancel());
            } else {
                $this->payPlatform($order, $payment);
                $this->payPlatform($order->fresh(), $payment);
            }
            $this->assertPrepaidConservation();
        }
        $profits = DB::table('v2_agent_profit')->get();
        $captured = DB::table('v2_agent_prepaid_allocation')->where('status', 'captured')->get();
        $this->assertCount(20, $profits);
        $this->assertSame((int) $captured->sum('amount'), (int) $profits->sum('sale_amount'));
        $this->assertSame((int) $captured->sum('fee_amount'), (int) $profits->sum('fee_amount'));
        $this->assertSame((int) $profits->sum('sale_amount') - (int) $profits->sum('cost_amount') - (int) $profits->sum('fee_amount'),
            (int) $profits->sum('amount'));
        $this->assertSame((int) $profits->sum('amount'), app(\App\Services\AgentProfitService::class)->summary($agent->id)['pending']);
        $this->assertSame(0, (int) $buyer->fresh()->balance);
        $this->assertSame(0, (int) $agent->fresh()->balance);
    }

    private function assertPrepaidConservation(): void
    {
        foreach (DB::table('v2_agent_prepaid_fund')->get() as $fund) {
            $allocated = DB::table('v2_agent_prepaid_allocation')->where('fund_id', $fund->id)
                ->whereIn('status', ['reserved', 'captured'])->get();
            $this->assertSame((int) $fund->amount, (int) $fund->remaining_amount + (int) $allocated->sum('amount'));
            $this->assertSame((int) $fund->fee_amount, (int) $fund->remaining_fee + (int) $allocated->sum('fee_amount'));
            $this->assertGreaterThanOrEqual(0, (int) $fund->remaining_amount);
            $this->assertGreaterThanOrEqual(0, (int) $fund->remaining_fee);
        }
    }

    public function test_agent_order_creation_fails_when_available_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 499);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        try {
            app(AgentCommerceService::class)->createOrderFromRequest(
                $buyer,
                $plan,
                Plan::PERIOD_MONTHLY,
                null,
                $this->requestForHost('agent.example.test')
            );
            $this->fail('Expected insufficient site balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame(
                'The site balance is insufficient. Please contact site support.',
                $exception->getMessage()
            );
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, AgentBalanceHold::query()->count());
        $this->assertSame(0, AgentOrderContext::query()->count());
    }

    public function test_agent_order_creation_creates_order_hold_and_context(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $domain = $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);
        AgentPlanOverride::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'display_name' => '代理畅享版',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame($agent->id, (int) $order->invite_user_id);
        $this->assertSame(Order::TYPE_NEW_PURCHASE, (int) $order->type);

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($hold);
        $this->assertSame($agent->id, (int) $hold->agent_user_id);
        $this->assertSame(500, (int) $hold->amount);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->status);

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertSame($domain->id, (int) $context->agent_domain_id);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(500, (int) $context->cost_amount);
        $this->assertSame($hold->id, (int) $context->hold_id);
        $this->assertSame($price->id, (int) $context->pricing_snapshot['agent_plan_price_id']);
        $this->assertSame('代理畅享版', $context->pricing_snapshot['display_name']);
        $this->assertSame('Starter', $context->pricing_snapshot['platform_plan_name']);
        $this->assertSame('agent.example.test', $context->domain_snapshot['domain']);

        $this->assertSame(1, DB::table('v2_agent_user')
            ->where('agent_user_id', $agent->id)
            ->where('sub_user_id', $buyer->id)
            ->count());
        $this->assertSame($agent->id, (int) $buyer->fresh()->invite_user_id);
    }

    public function test_pending_holds_reduce_available_agent_balance(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 1000);
        $buyer = $this->createUser('buyer@example.test');
        $pendingOrder = $this->createOrder($buyer, 'pending-hold', Order::STATUS_PENDING);
        $capturedOrder = $this->createOrder($buyer, 'captured-hold', Order::STATUS_COMPLETED);

        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $pendingOrder->id,
            'trade_no' => 'pending-hold',
            'amount' => 700,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $capturedOrder->id,
            'trade_no' => 'captured-hold',
            'amount' => 200,
            'status' => AgentBalanceHold::STATUS_CAPTURED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame(300, app(AgentCommerceService::class)->availableBalance($agent));
    }

    public function test_cancelling_agent_order_releases_balance_hold(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame(0, app(AgentCommerceService::class)->availableBalance($agent));

        $this->assertTrue((new OrderService($order))->cancel());

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertSame(Order::STATUS_CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->status);
        $this->assertNotNull($hold->released_at);
        $this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context->status);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));
    }

    public function test_cancelling_agent_order_releases_hold_when_context_hold_id_is_missing(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );
        $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
        $context->hold_id = null;
        $context->save();

        $this->assertTrue((new OrderService($order))->cancel());

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->firstOrFail();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->status);
        $this->assertSame($hold->id, (int) $context->hold_id);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));
    }

    public function test_cancelled_orders_do_not_reduce_available_balance_when_hold_is_still_pending(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 500);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );
        $order->status = Order::STATUS_CANCELLED;
        $order->save();

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->status);
        $this->assertSame(500, app(AgentCommerceService::class)->availableBalance($agent->fresh()));

        $this->assertSame(1, app(AgentCommerceService::class)->releaseCancelledPendingHolds($agent->id));
        $this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->fresh()->status);
    }

    public function test_non_agent_request_returns_null(): void
    {
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('platform.example.test')
        );

        $this->assertNull($order);
    }

    public function test_agent_order_creation_rejects_unconfigured_sale_period(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent price is not available');

        app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_YEARLY,
            null,
            $this->requestForHost('agent.example.test')
        );
    }

    public function test_existing_owned_user_is_not_reassigned_by_another_agent_domain(): void
    {
        $firstAgent = $this->createActiveAgent('first@example.test', 5000);
        $secondAgent = $this->createActiveAgent('second@example.test', 5000);
        $this->assignDomain($secondAgent, 'second.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $buyer->invite_user_id = $firstAgent->id;
        $buyer->save();
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($firstAgent, $plan, Plan::PERIOD_MONTHLY, 1200);
        $this->setAgentPrice($secondAgent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('second.example.test', $buyer)
        );

        $this->assertSame($firstAgent->id, (int) $buyer->fresh()->invite_user_id);
        $this->assertSame(1200, (int) $order->total_amount);
        $this->assertSame(1, AgentUser::query()->where('sub_user_id', $buyer->id)->count());
        $this->assertSame(1, AgentUser::query()
            ->where('agent_user_id', $firstAgent->id)
            ->where('sub_user_id', $buyer->id)
            ->count());
    }

    public function test_bound_user_on_main_domain_creates_agent_order_from_user_binding(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer->invite_user_id = $agent->id;
        $buyer->save();

        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('platform.example.test', $buyer)
        );

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame($agent->id, (int) $order->invite_user_id);

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame($agent->id, (int) $context->agent_user_id);
        $this->assertNull($context->agent_domain_id);
        $this->assertSame('user_binding', $context->domain_snapshot['source']);
        $this->assertSame('', $context->domain_snapshot['domain']);
        $this->assertSame($price->id, (int) $context->pricing_snapshot['agent_plan_price_id']);
    }

    public function test_bound_user_on_main_domain_uses_agent_for_payment_methods(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $agentUserId = app(AgentCommerceService::class)->agentUserIdForPaymentMethods(
            $this->requestForHost('platform.example.test', $buyer)
        );

        $this->assertSame($agent->id, $agentUserId);
    }

    public function test_bound_user_on_another_agent_domain_keeps_original_agent(): void
    {
        $firstAgent = $this->createActiveAgent('first@example.test', 5000);
        $secondAgent = $this->createActiveAgent('second@example.test', 5000);
        $this->assignDomain($secondAgent, 'second.example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $buyer->invite_user_id = $firstAgent->id;
        $buyer->save();

        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($firstAgent, $plan, Plan::PERIOD_MONTHLY, 1200);
        $this->setAgentPrice($secondAgent, $plan, Plan::PERIOD_MONTHLY, 1800);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('second.example.test', $buyer)
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertSame($firstAgent->id, (int) $context->agent_user_id);
        $this->assertSame(1200, (int) $order->total_amount);
        $this->assertSame(1, AgentUser::query()->where('sub_user_id', $buyer->id)->count());
    }

    public function test_agent_order_pricing_snapshot_contains_sale_and_platform_cost_contract(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $snapshot = $context->pricing_snapshot;

        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(500, (int) $context->cost_amount);
        $this->assertSame($price->id, (int) $snapshot['agent_plan_price_id']);
        $this->assertSame($plan->id, (int) $snapshot['plan_id']);
        $this->assertSame(Plan::PERIOD_MONTHLY, $snapshot['period']);
        $this->assertSame(1300, (int) $snapshot['sale_price']);
        $this->assertSame(1000, (int) $snapshot['platform_base_amount']);
        $this->assertSame(1000, (int) $snapshot['cost_base_amount']);
        $this->assertSame(500, (int) $snapshot['cost_amount']);
        $this->assertSame(50.0, (float) $snapshot['discount_percent']);
        $this->assertNull($snapshot['cost_site_id']);
        $this->assertSame('platform', $snapshot['cost_source']);
    }

    public function test_agent_order_cost_uses_agent_cost_site_price(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1300,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(2500, (int) $context->sale_amount);
        $this->assertSame(650, (int) $context->cost_amount);
        $this->assertSame($site->id, (int) $context->pricing_snapshot['cost_site_id']);
        $this->assertSame('site', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['platform_base_amount']);
        $this->assertSame(1300, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_agent_order_cost_falls_back_to_platform_price_when_cost_site_period_is_missing(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(2500, (int) $context->sale_amount);
        $this->assertSame(1000, (int) $context->cost_amount);
        $this->assertNull($context->pricing_snapshot['cost_site_id']);
        $this->assertSame('platform', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_agent_order_cost_falls_back_to_platform_price_when_cost_site_price_is_negative(): void
    {
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $site = $this->createSite('agent-cost');
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        AgentProfile::query()
            ->where('user_id', $agent->id)
            ->update(['cost_site_id' => $site->id]);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
        SitePlanPrice::query()->create([
            'site_id' => $site->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => -100,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $this->assertSame(1000, (int) $context->cost_amount);
        $this->assertNull($context->pricing_snapshot['cost_site_id']);
        $this->assertSame('platform', $context->pricing_snapshot['cost_source']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['platform_base_amount']);
        $this->assertSame(2000, (int) $context->pricing_snapshot['cost_base_amount']);
    }

    public function test_zero_discount_agent_order_creates_zero_amount_hold_without_requiring_balance(): void
    {
        $this->bindTestSettings([
            'agent_center_discount_percent' => 0,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
        ]);

        $agent = $this->createActiveAgent('agent@example.test', 0);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertNotNull($hold);
        $this->assertNotNull($context);
        $this->assertSame(0, (int) $hold->amount);
        $this->assertSame(0, (int) $context->cost_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(0, (int) $context->pricing_snapshot['cost_amount']);
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

    private function assignDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
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

    private function createOrder(User $user, string $tradeNo, int $status): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => 0,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => $tradeNo,
            'total_amount' => 0,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createSite(string $code): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => ucfirst($code),
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function setAgentPrice(User $agent, Plan $plan, string $period, int $salePrice): AgentPlanPrice
    {
        return AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host, ?User $user = null): Request
    {
        $request = Request::create('/api/v1/user/order/save', 'POST', [], [], [], [
            'HTTP_HOST' => $host,
        ]);
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}
