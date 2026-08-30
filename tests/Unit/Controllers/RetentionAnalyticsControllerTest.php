<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Http\Controllers\V2\Admin\RetentionAnalyticsController;
use App\Models\Order;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class RetentionAnalyticsControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createTables();
    }

    public function test_platform_scope_excludes_agent_users_and_summarizes_renewals(): void
    {
        $now = time();
        DB::table('v2_user')->insert([
            ['id' => 1, 'site_id' => 1, 'email' => 'platform@example.test', 'plan_id' => 1, 'expired_at' => $now + 3 * 86400, 'banned' => 0, 'is_admin' => 0, 'auto_renew_enable' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'site_id' => 1, 'email' => 'agent@example.test', 'plan_id' => 1, 'expired_at' => $now + 20 * 86400, 'banned' => 0, 'is_admin' => 0, 'auto_renew_enable' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('v2_agent_user')->insert(['agent_user_id' => 99, 'sub_user_id' => 2]);
        DB::table('v2_order')->insert([
            ['user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 1000, 'handling_amount' => 0, 'paid_at' => $now, 'updated_at' => $now],
            ['user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 800, 'handling_amount' => 0, 'paid_at' => $now, 'updated_at' => $now],
            ['user_id' => 2, 'site_id' => 1, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 5000, 'handling_amount' => 0, 'paid_at' => $now, 'updated_at' => $now],
        ]);

        $controller = app(RetentionAnalyticsController::class);
        $paidOrders = new \ReflectionMethod($controller, 'paidOrders');
        $orderSummary = new \ReflectionMethod($controller, 'orderSummary');
        $subscriberSummary = new \ReflectionMethod($controller, 'subscriberSummary');
        $filters = ['ownership' => 'platform'];
        $summary = $orderSummary->invoke($controller, $paidOrders->invoke($controller, $now - 86400, $filters));
        $subscribers = $subscriberSummary->invoke($controller, $filters);

        $this->assertSame(1, $summary['new_orders']);
        $this->assertSame(1, $summary['renewal_orders']);
        $this->assertSame(800, $summary['renewal_revenue']);
        $this->assertSame(50.0, $summary['renewal_order_share']);
        $this->assertSame(1, $subscribers['active_subscribers']);
        $this->assertSame(1, $subscribers['auto_renew_enabled']);
        $this->assertSame(1, $subscribers['expiring_7d']);
    }

    public function test_paid_order_window_preserves_legacy_timestamp_fallback_without_including_late_updates(): void
    {
        $now = time();
        $start = $now - 86400;
        DB::table('v2_user')->insert([
            'id' => 1,
            'site_id' => 1,
            'email' => 'platform@example.test',
            'plan_id' => 1,
            'expired_at' => $now + 86400,
            'banned' => 0,
            'is_admin' => 0,
            'auto_renew_enable' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('v2_order')->insert([
            ['id' => 1, 'user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_NEW_PURCHASE, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 100, 'handling_amount' => 0, 'paid_at' => $now, 'updated_at' => $start - 10],
            ['id' => 2, 'user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 100, 'handling_amount' => 0, 'paid_at' => 0, 'updated_at' => $now],
            ['id' => 3, 'user_id' => 1, 'site_id' => null, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 100, 'handling_amount' => 0, 'paid_at' => null, 'updated_at' => $now],
            ['id' => 4, 'user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 100, 'handling_amount' => 0, 'paid_at' => $start - 10, 'updated_at' => $now],
            ['id' => 5, 'user_id' => 1, 'site_id' => 1, 'type' => Order::TYPE_RENEWAL, 'status' => Order::STATUS_COMPLETED, 'total_amount' => 100, 'handling_amount' => 0, 'paid_at' => 0, 'updated_at' => $start - 10],
        ]);

        $controller = app(RetentionAnalyticsController::class);
        $paidOrders = new \ReflectionMethod($controller, 'paidOrders');
        $ids = $paidOrders->invoke($controller, $start, [
            'ownership' => 'platform',
            'site_id' => 1,
        ])->pluck('o.id')->sort()->values()->all();

        $this->assertSame([1, 2, 3], $ids);
    }

    private function createTables(): void
    {
        $this->database->schema()->create('v2_order', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('user_id'); $table->integer('site_id')->nullable();
            $table->integer('type'); $table->integer('status'); $table->integer('total_amount')->default(0);
            $table->integer('handling_amount')->default(0); $table->integer('paid_at')->nullable(); $table->integer('updated_at');
        });
        $this->database->schema()->create('v2_agent_user', function (Blueprint $table): void {
            $table->increments('id'); $table->integer('agent_user_id'); $table->integer('sub_user_id');
        });
    }
}
