<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Order;
use App\Models\Site;
use App\Services\StatisticalService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminDashboardSiteIncomeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createSiteTenantTables();
        $this->createOrderTable();
    }

    public function test_today_income_breakdown_groups_paid_orders_by_site_and_platform(): void
    {
        $todayStart = strtotime('today');
        $now = $todayStart + 3600;

        $miaosu = Site::query()->create([
            'code' => 'miaosu',
            'name' => '秒速云',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $todayStart,
            'updated_at' => $todayStart,
        ]);
        $lion = Site::query()->create([
            'code' => 'lion',
            'name' => 'LionCloud',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $todayStart,
            'updated_at' => $todayStart,
        ]);
        $idle = Site::query()->create([
            'code' => 'idle',
            'name' => '零收入站',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $todayStart,
            'updated_at' => $todayStart,
        ]);

        $this->createOrder('platform-paid', null, 455, Order::STATUS_COMPLETED, $todayStart + 10);
        $this->createOrder('miaosu-paid', $miaosu->id, 1300, Order::STATUS_COMPLETED, $todayStart + 20);
        $this->createOrder('lion-processing', $lion->id, 2500, Order::STATUS_PROCESSING, $todayStart + 30);
        $this->createOrder('miaosu-cancelled', $miaosu->id, 9999, Order::STATUS_CANCELLED, $todayStart + 40);
        $this->createOrder('lion-yesterday', $lion->id, 8888, Order::STATUS_COMPLETED, $todayStart - 60);

        $method = new \ReflectionMethod(StatController::class, 'buildTodayIncomeBySite');
        $method->setAccessible(true);

        $breakdown = $method->invoke(new StatController(new StatisticalService()), $todayStart, $now);

        $this->assertSame([
            [
                'site_id' => $lion->id,
                'site_code' => 'lion',
                'site_name' => 'LionCloud',
                'income' => 2500,
                'order_count' => 1,
            ],
            [
                'site_id' => $miaosu->id,
                'site_code' => 'miaosu',
                'site_name' => '秒速云',
                'income' => 1300,
                'order_count' => 1,
            ],
            [
                'site_id' => null,
                'site_code' => 'platform',
                'site_name' => '主站',
                'income' => 455,
                'order_count' => 1,
            ],
            [
                'site_id' => $idle->id,
                'site_code' => 'idle',
                'site_name' => '零收入站',
                'income' => 0,
                'order_count' => 0,
            ],
        ], $breakdown);
    }

    private function createOrder(string $tradeNo, ?int $siteId, int $amount, int $status, int $createdAt): void
    {
        Order::query()->create([
            'site_id' => $siteId,
            'invite_user_id' => null,
            'user_id' => 1,
            'plan_id' => 1,
            'payment_id' => null,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => 'month_price',
            'trade_no' => $tradeNo,
            'total_amount' => $amount,
            'status' => $status,
            'commission_status' => Order::COMMISSION_STATUS_PENDING,
            'commission_balance' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
