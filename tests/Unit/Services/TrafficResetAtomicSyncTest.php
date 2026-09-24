<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Plan;
use App\Models\User;
use App\Observers\UserObserver;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\TrafficResetService;
use App\Services\UserSyncService;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class TrafficResetAtomicSyncTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        config(['app.timezone' => 'UTC']);
        DB::connection()->setTransactionManager(new DatabaseTransactionsManager());
        Model::setEventDispatcher(app('events'));
        User::observe(app(UserObserver::class));
        app()->instance(NodeRealtimePublisher::class, new class extends NodeRealtimePublisher {
            public array $published = [];
            public function __construct() {}
            public function invalidateUsersForGroups(array $groupIds, string $reason = 'users.updated', array $payload = []): void
            {
                $this->published[] = ['groups' => $groupIds, 'revision' => $payload['revision'], 'level' => DB::transactionLevel()];
            }
        });
        Schema::create('v2_plan', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('reset_traffic_method');
        });
        Schema::create('v2_user', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('plan_id')->default(20);
            $table->integer('group_id')->default(10);
            $table->string('uuid')->default('fixture-user');
            $table->string('token')->default('fixture-token');
            $table->string('email')->default('fixture@example.invalid');
            $table->bigInteger('u')->default(190);
            $table->bigInteger('d')->default(160);
            $table->bigInteger('transfer_enable')->default(180);
            $table->boolean('banned')->default(false);
            $table->integer('expired_at')->nullable();
            $table->integer('next_reset_at')->nullable();
            $table->integer('last_reset_at')->nullable();
            $table->integer('reset_count')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
            $table->integer('speed_limit')->default(0);
            $table->integer('device_limit')->default(0);
        });
        require_once base_path('database/migrations/2025_06_21_000002_create_traffic_reset_logs_table.php');
        (new \CreateTrafficResetLogsTable())->up();
        (require base_path('database/migrations/2025_12_30_000001_create_user_sync_tables.php'))->up();
        DB::table('v2_plan')->insert(['id' => 20, 'reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]);
        DB::table('v2_user')->insert(['id' => 7, 'created_at' => time() - 86400 * 40, 'next_reset_at' => time() - 60]);
        DB::table('user_sync_states')->insert(app(UserSyncService::class)->computeSnapshot($this->user()));
    }

    protected function tearDown(): void
    {
        Model::unsetEventDispatcher();
        parent::tearDown();
    }

    private function user(): User
    {
        return User::query()->findOrFail(7);
    }

    private function failSync(): void
    {
        DB::unprepared("CREATE TRIGGER fail_reset_sync BEFORE UPDATE ON user_sync_states BEGIN SELECT RAISE(ABORT, 'reset sync failed'); END");
    }

    private function assertRolledBack(): void
    {
        $user = $this->user();
        $this->assertSame([190, 160, 0], [(int) $user->u, (int) $user->d, (int) $user->reset_count]);
        $this->assertNull($user->last_reset_at);
        $this->assertLessThan(time(), $user->next_reset_at);
        $this->assertSame(0, DB::table('v2_traffic_reset_logs')->count());
        $this->assertSame(0, DB::table('user_sync_events')->count());
        $this->assertFalse((bool) DB::table('user_sync_states')->value('available'));
        $this->assertSame([], app(NodeRealtimePublisher::class)->published);
    }

    public function test_sync_failure_cannot_commit_reset_despite_best_effort_observer(): void
    {
        $this->failSync();
        $service = new TrafficResetService();
        $this->assertFalse($service->manualReset($this->user()));
        $this->assertRolledBack();
        DB::unprepared('DROP TRIGGER fail_reset_sync');
        $this->assertTrue($service->checkAndReset($this->user(), 'cron'));
        $this->assertFalse($service->checkAndReset($this->user(), 'cron'));
        $this->assertSame(1, (int) $this->user()->reset_count);
        $this->assertSame(1, DB::table('v2_traffic_reset_logs')->count());
        $this->assertSame(1, DB::table('user_sync_events')->count());
        $this->assertTrue((bool) DB::table('user_sync_states')->value('available'));
        $this->assertCount(1, app(NodeRealtimePublisher::class)->published);
        $this->assertSame(0, app(NodeRealtimePublisher::class)->published[0]['level']);
    }

    public function test_missed_calendar_reset_also_rolls_back_failed_sync(): void
    {
        $this->failSync();
        try {
            (new TrafficResetService())->reconcileMissedCalendarReset($this->user());
            $this->fail('Expected reset synchronization failure.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('reset sync failed', $error->getMessage());
        }
        $this->assertRolledBack();
    }

    public function test_reset_does_not_duplicate_successful_observer_event(): void
    {
        $this->assertTrue((new TrafficResetService())->manualReset($this->user()));
        $this->assertSame([0, 0], [(int) $this->user()->u, (int) $this->user()->d]);
        $this->assertTrue((bool) DB::table('user_sync_states')->value('available'));
        $this->assertSame(1, DB::table('user_sync_events')->count());
        $this->assertCount(1, app(NodeRealtimePublisher::class)->published);
        $this->assertSame(0, app(NodeRealtimePublisher::class)->published[0]['level']);
    }

    public function test_legacy_schema_without_sync_tables_remains_supported(): void
    {
        Schema::drop('user_sync_events');
        Schema::drop('user_sync_states');
        $this->assertTrue((new TrafficResetService())->manualReset($this->user()));
        $this->assertSame([0, 0], [(int) $this->user()->u, (int) $this->user()->d]);
        $this->assertSame(1, DB::table('v2_traffic_reset_logs')->count());
    }
}
