<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TrafficResetReconciliationTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createTrafficResetLogTable();
        $this->bindTestSettings([
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
        config()->set('app.timezone', 'Asia/Shanghai');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reconciliation_resets_missed_user_once_and_records_evidence(): void
    {
        $now = Carbon::parse('2026-09-04 13:00:00', 'Asia/Shanghai');
        Carbon::setTestNow($now);

        $plan = new Plan();
        $plan->timestamps = false;
        $plan->name = 'Monthly calendar plan';
        $plan->reset_traffic_method = Plan::RESET_TRAFFIC_FIRST_DAY_MONTH;
        $plan->save();

        $user = new User();
        $user->timestamps = false;
        $user->email = 'missed@example.test';
        $user->token = 'reset-token';
        $user->plan_id = $plan->id;
        $user->banned = 0;
        $user->expired_at = null;
        $user->u = 123;
        $user->d = 456;
        $user->reset_count = 0;
        $user->created_at = Carbon::parse('2026-08-15 10:00:00', 'Asia/Shanghai')->timestamp;
        $user->last_reset_at = Carbon::parse('2026-08-01 00:00:00', 'Asia/Shanghai')->timestamp;
        $user->next_reset_at = Carbon::parse('2026-10-01 00:00:00', 'Asia/Shanghai')->timestamp;
        $user->save();
        $user->setRelation('plan', $plan);

        $service = new TrafficResetService();

        $this->assertTrue($service->reconcileMissedCalendarReset($user, $now));

        $user->refresh();
        $this->assertSame(0, (int) $user->u);
        $this->assertSame(0, (int) $user->d);
        $this->assertSame(1, (int) $user->reset_count);
        $this->assertSame($now->timestamp, $this->timestamp($user->last_reset_at));
        $this->assertSame(
            Carbon::parse('2026-10-01 00:00:00', 'Asia/Shanghai')->timestamp,
            $this->timestamp($user->next_reset_at)
        );

        $log = TrafficResetLog::query()->sole();
        $this->assertSame(TrafficResetLog::TYPE_FIRST_DAY_MONTH, $log->reset_type);
        $this->assertSame(TrafficResetLog::SOURCE_CRON, $log->trigger_source);
        $this->assertSame('missed_calendar_reset', $log->metadata['reason']);
        $this->assertSame(579, (int) $log->old_total);

        $this->assertFalse($service->reconcileMissedCalendarReset($user, $now));
        $this->assertSame(1, TrafficResetLog::query()->count());
    }

    private function createTrafficResetLogTable(): void
    {
        Schema::create('v2_traffic_reset_logs', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('reset_type');
            $table->dateTime('reset_time');
            $table->bigInteger('old_upload')->default(0);
            $table->bigInteger('old_download')->default(0);
            $table->bigInteger('old_total')->default(0);
            $table->bigInteger('new_upload')->default(0);
            $table->bigInteger('new_download')->default(0);
            $table->bigInteger('new_total')->default(0);
            $table->string('trigger_source');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    private function timestamp(mixed $value): int
    {
        return $value instanceof \DateTimeInterface ? $value->getTimestamp() : (int) $value;
    }
}
