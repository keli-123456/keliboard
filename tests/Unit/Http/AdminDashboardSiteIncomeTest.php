<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V2\Admin\StatController;
use App\Models\AgentOrderContext;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Site;
use App\Models\StatUser;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminDashboardSiteIncomeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        app()->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->createSiteTenantTables();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createAgentCommerceTables();
        $this->createAgentCenterTables();
        $this->createStatUserTable();
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
        $agentOrder = $this->createOrder('agent-paid', $lion->id, 7777, Order::STATUS_COMPLETED, $todayStart + 50);
        $this->createAgentOrderContext($agentOrder, $todayStart + 50);

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

        $platformIncome = new \ReflectionMethod(StatController::class, 'platformIncome');
        $platformIncome->setAccessible(true);

        $this->assertSame(
            4255,
            $platformIncome->invoke(new StatController(new StatisticalService()), $todayStart, $now)
        );
    }

    public function test_new_user_breakdown_groups_daily_and_monthly_users_by_site_and_platform(): void
    {
        $monthStart = strtotime('2026-06-01 00:00:00');
        $todayStart = strtotime('2026-06-24 00:00:00');
        $now = $todayStart + 3600;

        $miaosu = Site::query()->create([
            'code' => 'miaosu',
            'name' => '秒速云',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);
        $lion = Site::query()->create([
            'code' => 'lion',
            'name' => 'LionCloud',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);
        $idle = Site::query()->create([
            'code' => 'idle',
            'name' => '零新增站',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);

        $this->createDashboardUser('platform-today@example.test', null, $todayStart + 10);
        $this->createDashboardUser('miaosu-today@example.test', $miaosu->id, $todayStart + 20);
        $this->createDashboardUser('miaosu-month@example.test', $miaosu->id, $monthStart + 30);
        $this->createDashboardUser('lion-month@example.test', $lion->id, $monthStart + 40);
        $this->createDashboardUser('ignored-last-month@example.test', $miaosu->id, $monthStart - 10);

        $method = new \ReflectionMethod(StatController::class, 'buildNewUsersBySite');
        $method->setAccessible(true);

        $breakdown = $method->invoke(new StatController(new StatisticalService()), $todayStart, $monthStart, $now);

        $this->assertSame([
            [
                'site_id' => $miaosu->id,
                'site_code' => 'miaosu',
                'site_name' => '秒速云',
                'today_count' => 1,
                'month_count' => 2,
            ],
            [
                'site_id' => null,
                'site_code' => 'platform',
                'site_name' => '主站',
                'today_count' => 1,
                'month_count' => 1,
            ],
            [
                'site_id' => $lion->id,
                'site_code' => 'lion',
                'site_name' => 'LionCloud',
                'today_count' => 0,
                'month_count' => 1,
            ],
            [
                'site_id' => $idle->id,
                'site_code' => 'idle',
                'site_name' => '零新增站',
                'today_count' => 0,
                'month_count' => 0,
            ],
        ], $breakdown);
    }

    public function test_traffic_breakdown_groups_daily_and_monthly_usage_by_site_and_excludes_agent_users(): void
    {
        $monthStart = strtotime('2026-06-01 00:00:00');
        $todayStart = strtotime('2026-06-24 00:00:00');
        $now = $todayStart + 3600;

        $miaosu = Site::query()->create([
            'code' => 'miaosu',
            'name' => '秒速云',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);
        $lion = Site::query()->create([
            'code' => 'lion',
            'name' => 'LionCloud',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);
        $idle = Site::query()->create([
            'code' => 'idle',
            'name' => '零流量站',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);

        $platformUser = $this->createDashboardUser('platform-traffic@example.test', null, $monthStart);
        $miaosuUser = $this->createDashboardUser('miaosu-traffic@example.test', $miaosu->id, $monthStart);
        $lionUser = $this->createDashboardUser('lion-traffic@example.test', $lion->id, $monthStart);
        $agentUser = $this->createDashboardUser('agent-traffic@example.test', $miaosu->id, $monthStart);

        AgentUser::query()->create([
            'agent_user_id' => 999,
            'sub_user_id' => $agentUser->id,
            'created_at' => $monthStart,
            'updated_at' => $monthStart,
        ]);

        $this->createTraffic($platformUser->id, $todayStart, 10, 20);
        $this->createTraffic($miaosuUser->id, $monthStart + 60, 100, 200);
        $this->createTraffic($miaosuUser->id, $todayStart, 30, 40);
        $this->createTraffic($lionUser->id, $todayStart, 50, 60);
        $this->createTraffic($agentUser->id, $todayStart, 700, 800);

        $method = new \ReflectionMethod(StatController::class, 'buildTrafficBySite');
        $method->setAccessible(true);
        $controller = new StatController(new StatisticalService());
        $breakdown = $method->invoke($controller, $todayStart, $monthStart, $now);

        $this->assertSame([
            [
                'site_id' => $miaosu->id,
                'site_code' => 'miaosu',
                'site_name' => '秒速云',
                'today_upload' => 30,
                'today_download' => 40,
                'today_total' => 70,
                'month_upload' => 130,
                'month_download' => 240,
                'month_total' => 370,
            ],
            [
                'site_id' => $lion->id,
                'site_code' => 'lion',
                'site_name' => 'LionCloud',
                'today_upload' => 50,
                'today_download' => 60,
                'today_total' => 110,
                'month_upload' => 50,
                'month_download' => 60,
                'month_total' => 110,
            ],
            [
                'site_id' => null,
                'site_code' => 'platform',
                'site_name' => '主站',
                'today_upload' => 10,
                'today_download' => 20,
                'today_total' => 30,
                'month_upload' => 10,
                'month_download' => 20,
                'month_total' => 30,
            ],
            [
                'site_id' => $idle->id,
                'site_code' => 'idle',
                'site_name' => '零流量站',
                'today_upload' => 0,
                'today_download' => 0,
                'today_total' => 0,
                'month_upload' => 0,
                'month_download' => 0,
                'month_total' => 0,
            ],
        ], $breakdown);

        $summarize = new \ReflectionMethod(StatController::class, 'summarizeTrafficBySite');
        $summarize->setAccessible(true);

        $this->assertSame(
            ['upload' => 90, 'download' => 120, 'total' => 210],
            $summarize->invoke($controller, $breakdown, 'today')
        );
        $this->assertSame(
            ['upload' => 190, 'download' => 320, 'total' => 510],
            $summarize->invoke($controller, $breakdown, 'month')
        );
    }

    private function createOrder(string $tradeNo, ?int $siteId, int $amount, int $status, int $createdAt): Order
    {
        return Order::query()->create([
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

    private function createDashboardUser(string $email, ?int $siteId, int $createdAt): User
    {
        return User::query()->create([
            'site_id' => $siteId,
            'email' => $email,
            'password' => 'secret',
            'token' => sha1($email),
            'uuid' => sha1('uuid-' . $email),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createAgentOrderContext(Order $order, int $createdAt): void
    {
        AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => 9,
            'agent_domain_id' => null,
            'payment_id' => null,
            'sale_amount' => $order->total_amount,
            'cost_amount' => 100,
            'hold_id' => null,
            'status' => AgentOrderContext::STATUS_PAID,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function createStatUserTable(): void
    {
        $this->database->schema()->create('v2_stat_user', function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('user_id')->index();
            $table->decimal('server_rate', 10, 2)->default(1);
            $table->bigInteger('u')->default(0);
            $table->bigInteger('d')->default(0);
            $table->char('record_type', 2)->default('d');
            $table->integer('record_at')->index();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    private function createTraffic(int $userId, int $recordAt, int $upload, int $download): void
    {
        StatUser::query()->create([
            'user_id' => $userId,
            'server_rate' => 1,
            'u' => $upload,
            'd' => $download,
            'record_type' => 'd',
            'record_at' => $recordAt,
            'created_at' => $recordAt,
            'updated_at' => $recordAt,
        ]);
    }
}
