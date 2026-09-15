<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Services\FinancialReconciliationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class FinancialReconciliationServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
        $this->createAgentCenterTables();
        $this->createPlanTable();
        $this->createGiftCardTables();
        $this->createOrderTable();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
        $this->createCommissionTable();
        $this->now = time();
        $this->seedLedger();
    }

    public function test_overview_summarizes_each_financial_scope_and_surfaces_mismatches(): void
    {
        $overview = app(FinancialReconciliationService::class)->overview(['days' => 30]);

        $this->assertSame(6, $overview['summary']['order_count']);
        $this->assertSame(4, $overview['summary']['completed_order_count']);
        $this->assertSame(6850, $overview['summary']['settled_amount']);
        $this->assertSame(3000, $overview['summary']['agent_sales_amount']);
        $this->assertSame(1500, $overview['summary']['agent_cost_amount']);
        $this->assertSame(1, $overview['summary']['gift_card_usage_count']);

        $scopeTypes = collect($overview['scope_breakdown'])->pluck('scope_type')->all();
        $this->assertContains('platform', $scopeTypes);
        $this->assertContains('site', $scopeTypes);
        $this->assertContains('agent', $scopeTypes);

        $codes = collect($overview['issues']['data'])->pluck('code')->all();
        $this->assertContains('paid_at_status_conflict', $codes, json_encode($codes));
        $this->assertContains('refund_not_disposed', $codes);
        $this->assertContains('refund_commission_still_valid', $codes);
        $this->assertContains('cancelled_order_pending_hold', $codes);
        $this->assertContains('agent_ledger_balance_mismatch', $codes);
        $this->assertContains('gift_card_usage_count_mismatch', $codes);
        $this->assertGreaterThanOrEqual(6, $overview['summary']['issue_count']);
    }

    public function test_platform_scope_excludes_site_and_agent_finance(): void
    {
        $overview = app(FinancialReconciliationService::class)->overview([
            'days' => 30,
            'scope' => 'platform',
        ]);

        $this->assertSame(3, $overview['summary']['order_count']);
        $this->assertSame(0, $overview['summary']['agent_sales_amount']);
        $this->assertSame(0, $overview['summary']['agent_cost_amount']);
        $this->assertNotContains(
            'agent_ledger_balance_mismatch',
            collect($overview['issues']['data'])->pluck('code')->all()
        );
    }

    public function test_platform_collection_does_not_require_a_self_collection_hold(): void
    {
        DB::table('v2_agent_order_context')->where('order_id', 3)->update([
            'hold_id' => null, 'pricing_snapshot' => json_encode(['collection' => ['mode' => 'platform']]),
        ]);
        $codes = $this->codesFor('agent-good');
        $this->assertNotContains('agent_hold_missing', $codes);
        $this->assertNotContains('paid_agent_order_not_captured', $codes);
    }

    public function test_zero_cost_self_collection_does_not_require_a_hold(): void
    {
        DB::table('v2_agent_order_context')->where('order_id', 3)->update(['hold_id' => null, 'cost_amount' => 0]);
        $codes = $this->codesFor('agent-good');
        $this->assertNotContains('agent_hold_missing', $codes);
        $this->assertNotContains('paid_agent_order_not_captured', $codes);
    }

    public function test_agent_commission_is_flagged_without_changing_historical_money(): void
    {
        DB::table('v2_order')->where('id', 3)->update(['invite_user_id' => 3, 'commission_balance' => 300,
            'actual_commission_balance' => 300, 'commission_status' => Order::COMMISSION_STATUS_VALID]);
        DB::table('v2_commission_log')->insert(['invite_user_id' => 3, 'user_id' => 4,
            'trade_no' => 'agent-good', 'order_amount' => 3000, 'get_amount' => 300,
            'created_at' => $this->now, 'updated_at' => $this->now]);
        $before = DB::table('v2_order')->where('id', 3)->first();
        $this->assertContains('agent_regular_commission_conflict', $this->codesFor('agent-good'));
        $this->assertEquals($before, DB::table('v2_order')->where('id', 3)->first());
        $this->assertSame(300, (int) DB::table('v2_commission_log')->where('trade_no', 'agent-good')->sum('get_amount'));
    }

    public function test_main_order_with_subordinate_recipient_requires_review(): void
    {
        DB::table('v2_order')->where('id', 1)->update(['invite_user_id' => 4, 'commission_balance' => 100]);
        $this->assertContains('agent_regular_commission_conflict', $this->codesFor('main-good'));
    }

    public function test_commission_over_budget_is_reported(): void
    {
        DB::table('v2_order')->where('id', 1)->update(['commission_balance' => 100, 'actual_commission_balance' => 150]);
        $this->assertContains('commission_budget_exceeded', $this->codesFor('main-good'));
    }

    public function test_platform_profit_is_checked_for_missing_and_inconsistent_rows(): void
    {
        (require base_path('database/migrations/2026_09_14_190000_create_agent_profit_accounts.php'))->up();
        DB::table('v2_agent_order_context')->where('order_id', 3)->update([
            'hold_id' => null, 'pricing_snapshot' => json_encode(['collection' => ['mode' => 'platform']]),
        ]);
        $this->assertContains('agent_profit_missing', $this->codesFor('agent-good'));
        DB::table('v2_agent_profit')->insert(['agent_user_id' => 3, 'order_id' => 3, 'trade_no' => 'agent-good',
            'sale_amount' => 3000, 'cost_amount' => 1500, 'fee_amount' => 100, 'amount' => 1500,
            'status' => 'pending', 'available_at' => $this->now + 86400,
            'created_at' => $this->now, 'updated_at' => $this->now]);
        $this->assertContains('agent_profit_amount_mismatch', $this->codesFor('agent-good'));
        DB::table('v2_agent_profit')->where('order_id', 3)->update(['amount' => 1400]);
        $this->assertNotContains('agent_profit_amount_mismatch', $this->codesFor('agent-good'));
        DB::table('v2_order')->where('id', 3)->update(['refund_disposed_at' => $this->now]);
        $this->assertContains('agent_profit_source_invalid', $this->codesFor('agent-good'));
    }

    public function test_audit_command_reports_database_only_and_does_not_modify_orders(): void
    {
        (require base_path('database/migrations/2026_09_14_190000_create_agent_profit_accounts.php'))->up();
        $before = DB::table('v2_order')->orderBy('id')->get();
        $command = new \App\Console\Commands\AgentFinanceAudit();
        $command->setLaravel(app());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $this->assertSame(1, $tester->execute(['--json' => true]));
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['gateway_receipts_verified']);
        $this->assertFalse($report['mysql_concurrency_verified']);
        $this->assertArrayNotHasKey('user_email', $report['issues']['data'][0]);
        $this->assertEquals($before, DB::table('v2_order')->orderBy('id')->get());
    }

    public function test_audit_command_reports_incomplete_schema_as_invalid(): void
    {
        $command = new \App\Console\Commands\AgentFinanceAudit();
        $command->setLaravel(app());
        $tester = new \Symfony\Component\Console\Tester\CommandTester($command);
        $this->assertSame(2, $tester->execute(['--json' => true]));
        $this->assertFalse(json_decode($tester->getDisplay(), true)['ready']);
    }

    private function codesFor(string $tradeNo): array
    {
        return collect(app(FinancialReconciliationService::class)->overview(['keyword' => $tradeNo])['issues']['data'])
            ->where('trade_no', $tradeNo)->pluck('code')->all();
    }

    private function seedLedger(): void
    {
        DB::table('v2_site')->insert([
            'id' => 10,
            'code' => 'site-a',
            'name' => 'Site A',
            'status' => 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_plan')->insert([
            'id' => 1,
            'name' => 'Plan A',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_payment')->insert([
            'id' => 1,
            'uuid' => 'payment-1',
            'payment' => 'Balance',
            'name' => 'Balance',
            'enable' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_user')->insert([
            ['id' => 1, 'site_id' => null, 'email' => 'main@example.test', 'created_at' => $this->now, 'updated_at' => $this->now],
            ['id' => 2, 'site_id' => 10, 'email' => 'site@example.test', 'created_at' => $this->now, 'updated_at' => $this->now],
            ['id' => 3, 'site_id' => null, 'email' => 'agent@example.test', 'created_at' => $this->now, 'updated_at' => $this->now],
            ['id' => 4, 'site_id' => null, 'email' => 'agent-child@example.test', 'created_at' => $this->now, 'updated_at' => $this->now],
        ]);
        DB::table('v2_agent_profile')->insert([
            'user_id' => 3,
            'status' => 'active',
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_agent_user')->insert([
            'agent_user_id' => 3,
            'sub_user_id' => 4,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);

        DB::table('v2_order')->insert([
            $this->order(1, null, 1, 'main-good', Order::STATUS_COMPLETED, 1000, 50, $this->now),
            $this->order(2, 10, 2, 'site-good', Order::STATUS_COMPLETED, 2000, 0, $this->now),
            $this->order(3, null, 4, 'agent-good', Order::STATUS_COMPLETED, 3000, 0, $this->now),
            $this->order(4, null, 1, 'paid-pending', Order::STATUS_PENDING, 500, 0, $this->now),
            array_merge($this->order(5, null, 1, 'refund-open', Order::STATUS_COMPLETED, 800, 0, $this->now), [
                'refund_amount' => 800,
                'commission_status' => Order::COMMISSION_STATUS_VALID,
                'actual_commission_balance' => 100,
            ]),
            $this->order(6, null, 4, 'agent-cancelled', Order::STATUS_CANCELLED, 400, 0, null),
        ]);
        DB::table('v2_site_order_context')->insert([
            'order_id' => 2,
            'trade_no' => 'site-good',
            'site_id' => 10,
            'sale_amount' => 2000,
            'platform_plan_price' => 1800,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_agent_balance_hold')->insert([
            ['id' => 1, 'agent_user_id' => 3, 'order_id' => 3, 'trade_no' => 'agent-good', 'amount' => 1500, 'status' => AgentBalanceHold::STATUS_CAPTURED, 'expires_at' => $this->now + 600, 'captured_at' => $this->now, 'created_at' => $this->now, 'updated_at' => $this->now],
            ['id' => 2, 'agent_user_id' => 3, 'order_id' => 6, 'trade_no' => 'agent-cancelled', 'amount' => 200, 'status' => AgentBalanceHold::STATUS_PENDING, 'expires_at' => $this->now + 600, 'captured_at' => null, 'created_at' => $this->now, 'updated_at' => $this->now],
        ]);
        DB::table('v2_agent_order_context')->insert([
            ['order_id' => 3, 'trade_no' => 'agent-good', 'agent_user_id' => 3, 'sale_amount' => 3000, 'cost_amount' => 1500, 'hold_id' => 1, 'status' => AgentOrderContext::STATUS_PAID, 'created_at' => $this->now, 'updated_at' => $this->now],
            ['order_id' => 6, 'trade_no' => 'agent-cancelled', 'agent_user_id' => 3, 'sale_amount' => 400, 'cost_amount' => 200, 'hold_id' => 2, 'status' => AgentOrderContext::STATUS_CANCELLED, 'created_at' => $this->now, 'updated_at' => $this->now],
        ]);
        DB::table('v2_agent_ledger')->insert([
            'agent_user_id' => 3,
            'target_user_id' => 4,
            'type' => 'order_cost',
            'amount' => -200,
            'balance_before' => 1000,
            'balance_after' => 900,
            'created_at' => $this->now,
        ]);
        DB::table('v2_commission_log')->insert([
            'invite_user_id' => 2,
            'user_id' => 1,
            'trade_no' => 'refund-open',
            'order_amount' => 800,
            'get_amount' => 100,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_gift_card_template')->insert([
            'id' => 1,
            'name' => 'Gift',
            'type' => 1,
            'rewards' => '{}',
            'admin_id' => 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_gift_card_code')->insert([
            'id' => 1,
            'template_id' => 1,
            'code' => 'GIFT-1',
            'usage_count' => 3,
            'max_usage' => 10,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
        DB::table('v2_gift_card_usage')->insert([
            'code_id' => 1,
            'template_id' => 1,
            'user_id' => 1,
            'rewards_given' => '{}',
            'created_at' => $this->now,
        ]);
    }

    private function order(
        int $id,
        ?int $siteId,
        int $userId,
        string $tradeNo,
        int $status,
        int $amount,
        int $handling,
        ?int $paidAt
    ): array {
        return [
            'id' => $id,
            'site_id' => $siteId,
            'user_id' => $userId,
            'plan_id' => 1,
            'payment_id' => 1,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => 'month_price',
            'trade_no' => $tradeNo,
            'total_amount' => $amount,
            'handling_amount' => $handling,
            'refund_amount' => null,
            'status' => $status,
            'commission_status' => Order::COMMISSION_STATUS_PENDING,
            'actual_commission_balance' => null,
            'paid_at' => $paidAt,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ];
    }

    private function createCommissionTable(): void
    {
        $this->database->schema()->create('v2_commission_log', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('invite_user_id');
            $table->integer('user_id');
            $table->string('trade_no', 64);
            $table->integer('order_amount');
            $table->integer('get_amount');
            $table->string('credited_to')->nullable();
            $table->integer('reversed_at')->nullable();
            $table->integer('reversed_by_admin_id')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }
}
