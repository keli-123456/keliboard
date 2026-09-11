<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentCollectionPolicyService;
use App\Services\SiteCommerceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class PaymentCollectionPolicyServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createPaymentTable();
        $this->createOrderTable();
        config(['app.timezone' => 'Asia/Shanghai']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 20:00:00', 'Asia/Shanghai'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_legacy_methods_keep_manual_order_without_receipt_query_or_private_fields(): void
    {
        $a = $this->payment(['sort' => 2, 'config' => ['secret' => 'private']]);
        $b = $this->payment(['sort' => 1]);
        $this->database->connection()->enableQueryLog();
        $rows = $this->service()->publicMethods(collect([$a, $b]));
        $this->assertSame([$b->id, $a->id], $rows->pluck('id')->all());
        $this->assertCount(0, $this->database->connection()->getQueryLog());
        foreach (['config', 'collection_policy', 'collection_state', 'uuid', 'sort', 'enable'] as $field) {
            $this->assertArrayNotHasKey($field, $rows->last()->toArray());
        }
    }

    #[DataProvider('orderingCases')]
    public function test_ordering_at_targets_and_window_boundaries(string $time, int $aTotal, int $bTotal, string $action, array $expected): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 ' . $time, 'Asia/Shanghai'));
        $a = $this->payment(['sort' => 1, 'collection_policy' => ['daily_target' => 300000, 'reached_action' => 'demote', 'windows' => []]]);
        $b = $this->payment(['sort' => 2, 'collection_policy' => ['daily_target' => 500000, 'reached_action' => $action, 'windows' => [['start' => '18:00', 'end' => '23:00']]]]);
        $this->receipt($a, $aTotal);
        $this->receipt($b, $bTotal);
        $ids = [$a->id, $b->id];
        $this->assertSame(array_map(fn ($i) => $ids[$i], $expected), $this->service()->publicMethods(collect([$a, $b]))->pluck('id')->all());
    }

    public static function orderingCases(): array
    {
        return [
            'before window' => ['17:59:59', 100, 100, 'demote', [0, 1]],
            'opening inclusive' => ['18:00:00', 100, 100, 'demote', [1, 0]],
            'closing exclusive' => ['23:00:00', 100, 100, 'demote', [0, 1]],
            'scheduled reached' => ['20:00:00', 100, 500000, 'demote', [0, 1]],
            'both reached' => ['20:00:00', 300000, 500000, 'demote', [0, 1]],
            'legacy pause is demoted' => ['20:00:00', 100, 500000, 'pause', [0, 1]],
            'outside window and both reached' => ['23:00:00', 300000, 500000, 'pause', [0, 1]],
            'one cent below' => ['20:00:00', 100, 499999, 'pause', [1, 0]],
        ];
    }

    public function test_totals_use_confirmation_day_cny_fees_and_payment_id_across_sites(): void
    {
        $a = $this->payment();
        $other = $this->payment(['owner_type' => 'agent', 'owner_id' => 99]);
        $start = $this->service()->now()->startOfDay()->timestamp;
        $this->receipt($a, 1000, ['paid_at' => $start, 'site_id' => 1, 'handling_amount' => 50, 'created_at' => $start - 86400]);
        $this->receipt($a, 2000, ['site_id' => 2, 'status' => Order::STATUS_DISCOUNTED, 'refund_amount' => 2000, 'balance_amount' => 900]);
        $this->receipt($a, 8000, ['paid_at' => $start - 1]);
        $this->receipt($a, 9000, ['paid_at' => $start + 86400]);
        $this->receipt($a, 9000, ['paid_at' => null]);
        $this->receipt($a, 0, ['handling_amount' => 100, 'balance_amount' => 1000]);
        $this->receipt($other, 7777);
        $this->assertSame([$a->id => 3050, $other->id => 7777], $this->service()->dailyTotals(collect([$a, $other]), $this->service()->now()));
    }

    public function test_three_priority_groups_keep_manual_order_and_id_ties_without_hiding_fallbacks(): void
    {
        $active = $this->payment(['sort' => 99, 'collection_policy' => ['windows' => [['start' => '18:00', 'end' => '23:00']]]]);
        $allDay = $this->payment(['sort' => 50]);
        $outside = $this->payment(['sort' => 1, 'collection_policy' => ['windows' => [['start' => '09:00', 'end' => '12:00']]]]);
        $reached = $this->payment(['sort' => 1, 'collection_policy' => ['daily_target' => 100, 'reached_action' => 'pause']]);
        $disabled = $this->payment(['sort' => 0, 'enable' => false]);
        $this->receipt($reached, 100);
        $this->assertSame([$active->id, $allDay->id, $outside->id, $reached->id],
            $this->service()->publicMethods(collect([$reached, $disabled, $outside, $allDay, $active]))->pluck('id')->all());
        $this->assertSame([$outside->id, $reached->id],
            $this->service()->publicMethods(collect([$reached, $outside]))->pluck('id')->all());
        $this->assertSame('pause', $reached->fresh()->collection_policy['reached_action']);
        $this->assertSame(1, $reached->fresh()->sort);
        $this->assertFalse($disabled->fresh()->enable);
    }

    public function test_daily_reset_and_overnight_window_do_not_need_a_cron(): void
    {
        $p = $this->payment(['collection_policy' => ['daily_target' => 100, 'reached_action' => 'pause', 'windows' => [['start' => '22:00', 'end' => '06:00']]]]);
        $this->receipt($p, 100);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 23:00', 'Asia/Shanghai'));
        $state = $this->service()->checkoutState($p);
        $this->assertSame('demoted', $state['status']);
        $this->assertTrue($state['available']);
        $midnight = CarbonImmutable::parse('2026-09-12 00:00', 'Asia/Shanghai');
        $this->assertSame($midnight->timestamp, $state['next_priority_at']);
        $this->assertNull($state['next_available_at']);
        CarbonImmutable::setTestNow($midnight);
        $this->assertTrue($this->service()->checkoutState($p)['available']);
        $this->assertFalse($this->service()->checkoutState($p)['reached']);
        CarbonImmutable::setTestNow($midnight->setTime(6, 0));
        $this->assertSame('outside_window', $this->service()->checkoutState($p)['status']);
        $this->assertTrue($this->service()->checkoutState($p)['available']);
        $this->assertTrue($p->fresh()->enable);
    }

    public function test_priority_resumes_at_next_window_or_after_daily_target_resets(): void
    {
        $p = $this->payment(['collection_policy' => ['daily_target' => 100, 'reached_action' => 'pause', 'windows' => [['start' => '09:00', 'end' => '12:00'], ['start' => '21:00', 'end' => '23:00']]]]);
        $state = $this->service()->state($p, 0);
        $this->assertSame($this->service()->now()->setTime(21, 0)->timestamp, $state['next_priority_at']);
        $this->assertTrue($state['available']);
        $state = $this->service()->state($p, 100);
        $this->assertSame($this->service()->now()->addDay()->setTime(9, 0)->timestamp, $state['next_priority_at']);
        $this->assertTrue($state['available']);
    }

    public function test_batch_totals_take_one_query_and_checkout_reads_new_receipts(): void
    {
        $payments = collect(range(1, 15))->map(fn () => $this->payment(['collection_policy' => ['daily_target' => 100, 'reached_action' => 'pause']]));
        $this->database->connection()->enableQueryLog();
        $this->assertCount(15, $this->service()->publicMethods($payments));
        $this->assertCount(1, $this->database->connection()->getQueryLog());
        $this->receipt($payments->first(), 100);
        $this->assertTrue($this->service()->checkoutState($payments->first())['available']);
        $this->assertTrue($this->service()->checkoutState($payments->first())['reached']);
    }

    public function test_site_method_filter_keeps_agent_and_disabled_payments_private(): void
    {
        $platform = $this->payment();
        $this->payment(['owner_type' => 'agent', 'owner_id' => 9]);
        $this->payment(['enable' => false]);
        $rows = app(SiteCommerceService::class)->availablePaymentMethodsForRequest(Request::create('/'));
        $this->assertSame([$platform->id], $rows->pluck('id')->all());
    }

    public function test_manual_disable_does_not_auto_resume(): void
    {
        $state = $this->service()->state($this->payment(['enable' => false]), 0);
        $this->assertSame('disabled', $state['status']);
        $this->assertFalse($state['available']);
        $this->assertNull($state['next_available_at']);
    }

    public function test_defaults_and_valid_overnight_policy(): void
    {
        $this->assertSame(['daily_target' => 0, 'reached_action' => 'demote', 'windows' => []], $this->service()->validate(null));
        $policy = ['daily_target' => 12001, 'reached_action' => 'pause', 'windows' => [['start' => '22:00', 'end' => '06:00']]];
        $this->assertSame(array_replace($policy, ['reached_action' => 'demote']), $this->service()->validate($policy));
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_policy_is_rejected(array $policy): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->validate($policy);
    }

    public static function invalidPolicies(): array
    {
        return [
            [['daily_target' => -1]], [['daily_target' => 1.23]], [['daily_target' => 1000000000001]],
            [['reached_action' => 'disable']], [['secret' => 'not allowed']],
            [['windows' => [['start' => '24:00', 'end' => '06:00']]]],
            [['windows' => [['start' => '9:00', 'end' => '06:00']]]],
            [['windows' => [['start' => '22:00', 'end' => '22:00']]]],
            [['windows' => [['start' => '22:00']]]],
            [['windows' => array_fill(0, 13, ['start' => '09:00', 'end' => '12:00'])]],
        ];
    }

    public function test_migration_is_repeatable_and_legacy_null_policy_stays_available(): void
    {
        $p = $this->payment();
        $migration = require base_path('database/migrations/2026_09_11_000001_add_payment_collection_policy.php');
        $migration->up();
        $migration->up();
        $this->assertNull($p->fresh()->collection_policy);
        $this->assertTrue($this->service()->checkoutState($p->fresh())['available']);
        $migration->down();
        $this->assertTrue($this->service()->checkoutState($p->fresh())['available']);
        $migration->up();
    }

    private function service(): PaymentCollectionPolicyService
    {
        return app(PaymentCollectionPolicyService::class);
    }

    private function payment(array $attributes = []): Payment
    {
        return Payment::create(array_replace(['uuid' => uniqid(), 'name' => 'Test pay', 'payment' => 'TEST', 'enable' => true, 'owner_type' => 'platform', 'sort' => 0], $attributes));
    }

    private function receipt(Payment $payment, int $amount, array $attributes = []): Order
    {
        return Order::create(array_replace(['payment_id' => $payment->id, 'user_id' => 1, 'period' => 'recharge', 'trade_no' => uniqid(), 'total_amount' => $amount, 'status' => 3, 'paid_at' => $this->service()->now()->timestamp], $attributes));
    }
}
