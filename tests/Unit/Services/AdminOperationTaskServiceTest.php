<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AdminOperationTask;
use App\Models\AdminOperationTaskItem;
use App\Models\User;
use App\Services\AdminOperationExecutor;
use App\Services\AdminOperationTaskService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminOperationTaskServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();

        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_08_11_000001_create_admin_operation_task_tables.php';
        $migration->up();
        Schema::table('v2_admin_operation_task', function (Blueprint $table): void {
            $table->unsignedInteger('dismissed_at')->nullable();
        });
        Schema::create('v2_admin_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action', 64);
            $table->string('method', 16);
            $table->string('uri', 255);
            $table->text('request_data')->nullable();
            $table->string('ip', 64)->nullable();
            $table->unsignedInteger('created_at')->nullable();
            $table->unsignedInteger('updated_at')->nullable();
        });

        app()->instance(Dispatcher::class, new class implements Dispatcher {
            public function dispatch($command) { return $command; }
            public function dispatchSync($command, $handler = null) { return $command; }
            public function dispatchNow($command, $handler = null) { return $command; }
            public function hasCommandHandler($command) { return false; }
            public function getCommandHandler($command) { return null; }
            public function pipeThrough(array $pipes) { return $this; }
            public function map(array $map) { return $this; }
        });
    }

    public function test_process_persists_partial_progress_and_failure_details(): void
    {
        $task = $this->createTask(['1', '2']);
        $executor = $this->createMock(AdminOperationExecutor::class);
        $executor->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function ($task, AdminOperationTaskItem $item): array {
                if ($item->item_key === '2') {
                    throw new RuntimeException('second item failed');
                }

                return ['skipped' => false, 'result' => ['ok' => true]];
            });

        $service = new AdminOperationTaskService($executor);
        $service->process((string) $task->id);

        $task->refresh();
        $this->assertSame(AdminOperationTask::STATUS_PARTIAL, $task->status);
        $this->assertSame(2, $task->completed);
        $this->assertSame(1, $task->succeeded);
        $this->assertSame(1, $task->failed);
        $this->assertSame('second item failed', $service->serialize($task)['failures'][0]['message']);
        $this->assertSame('operation_task.partial', DB::table('v2_admin_audit_log')->value('action'));
    }

    public function test_cancel_queued_task_cancels_every_pending_item(): void
    {
        $task = $this->createTask(['11', '12', '13']);
        $service = new AdminOperationTaskService($this->createMock(AdminOperationExecutor::class));

        $service->cancel($task);

        $task->refresh();
        $this->assertSame(AdminOperationTask::STATUS_CANCELLED, $task->status);
        $this->assertSame(3, $task->completed);
        $this->assertSame(3, $task->cancelled);
        $this->assertSame(3, $task->items()->where('status', AdminOperationTaskItem::STATUS_CANCELLED)->count());
    }

    public function test_retry_only_requeues_failed_items(): void
    {
        $task = $this->createTask(['21', '22']);
        $task->update([
            'status' => AdminOperationTask::STATUS_PARTIAL,
            'completed' => 2,
            'succeeded' => 1,
            'failed' => 1,
            'finished_at' => time(),
        ]);
        $task->items()->where('item_key', '21')->update([
            'status' => AdminOperationTaskItem::STATUS_SUCCEEDED,
            'attempt_count' => 1,
        ]);
        $task->items()->where('item_key', '22')->update([
            'status' => AdminOperationTaskItem::STATUS_FAILED,
            'error_message' => 'temporary error',
        ]);

        $executor = $this->createMock(AdminOperationExecutor::class);
        $executor->expects($this->once())
            ->method('execute')
            ->willReturn(['skipped' => false, 'result' => ['ok' => true]]);
        $service = new AdminOperationTaskService($executor);

        $service->retryFailed($task);
        $service->process((string) $task->id);

        $task->refresh();
        $this->assertSame(AdminOperationTask::STATUS_SUCCEEDED, $task->status);
        $this->assertSame(2, $task->succeeded);
        $this->assertSame(0, $task->failed);
        $this->assertSame(1, (int) $task->items()->where('item_key', '21')->value('attempt_count'));
        $this->assertSame(1, (int) $task->items()->where('item_key', '22')->value('attempt_count'));
    }

    public function test_create_is_idempotent_for_the_same_admin_and_client_token(): void
    {
        $executor = $this->createMock(AdminOperationExecutor::class);
        $executor->expects($this->exactly(2))
            ->method('validateTaskPayload')
            ->willReturn(['balance' => 10]);
        $service = new AdminOperationTaskService($executor);
        $admin = new User();
        $admin->forceFill(['id' => 9]);
        $attributes = [
            'operation' => AdminOperationExecutor::USER_SET_BALANCE,
            'title' => 'Idempotent task',
            'client_token' => 'client-token-1',
            'payload' => ['balance' => 10],
            'items' => [
                ['key' => '31', 'label' => '#31'],
                ['key' => '32', 'label' => '#32'],
            ],
        ];

        $first = $service->create($admin, $attributes);
        $second = $service->create($admin, $attributes);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AdminOperationTask::query()->count());
        $this->assertSame(2, AdminOperationTaskItem::query()->count());
    }
    public function test_single_item_task_exposes_its_result_for_compatible_batch_feedback(): void
    {
        $task = $this->createTask(['batch']);
        $executor = $this->createMock(AdminOperationExecutor::class);
        $executor->method('execute')->willReturn([
            'skipped' => false,
            'result' => ['summary' => ['machines' => 2, 'bound' => 5, 'skipped' => 1]],
        ]);
        $executor->method('riskLevel')->willReturn('warning');
        $service = new AdminOperationTaskService($executor);

        $service->process((string) $task->id);
        $serialized = $service->serialize($task->fresh());

        $this->assertSame(2, $serialized['result']['summary']['machines']);
        $this->assertSame(5, $serialized['result']['summary']['bound']);
        $this->assertSame('warning', $serialized['risk_level']);
        $this->assertNotNull($serialized['retention_until']);
    }
    public function test_health_summary_reports_stale_and_recent_failed_tasks(): void
    {
        config()->set('admin_operations.stale_after_seconds', 1800);
        $now = time();
        $staleTask = $this->createTask(['41']);
        $failedTask = $this->createTask(['42']);

        DB::table('v2_admin_operation_task')->where('id', $staleTask->id)->update([
            'status' => AdminOperationTask::STATUS_RUNNING,
            'created_at' => $now - 5000,
            'updated_at' => $now - 4000,
        ]);
        DB::table('v2_admin_operation_task')->where('id', $failedTask->id)->update([
            'status' => AdminOperationTask::STATUS_FAILED,
            'completed' => 1,
            'failed' => 1,
            'finished_at' => $now - 60,
            'updated_at' => $now - 60,
        ]);

        $summary = (new AdminOperationTaskService($this->createMock(AdminOperationExecutor::class)))->healthSummary();

        $this->assertTrue($summary['available']);
        $this->assertSame('critical', $summary['status']);
        $this->assertSame(1, $summary['running']);
        $this->assertSame(1, $summary['stale']);
        $this->assertSame(1, $summary['failed_recent']);
        $this->assertGreaterThanOrEqual(5000, $summary['oldest_active_seconds']);
    }

    public function test_cleanup_expired_uses_longer_retention_for_failed_tasks(): void
    {
        config()->set('admin_operations.history_retention_days', 30);
        config()->set('admin_operations.failure_retention_days', 90);
        $now = time();

        $expiredSuccess = $this->createTask(['51']);
        $expiredFailure = $this->createTask(['52']);
        $recentSuccess = $this->createTask(['53']);
        $retainedFailure = $this->createTask(['54']);

        foreach ([
            [$expiredSuccess, AdminOperationTask::STATUS_SUCCEEDED, $now - (31 * 86400)],
            [$expiredFailure, AdminOperationTask::STATUS_FAILED, $now - (91 * 86400)],
            [$recentSuccess, AdminOperationTask::STATUS_SUCCEEDED, $now - (29 * 86400)],
            [$retainedFailure, AdminOperationTask::STATUS_FAILED, $now - (31 * 86400)],
        ] as [$task, $status, $updatedAt]) {
            DB::table('v2_admin_operation_task')->where('id', $task->id)->update([
                'status' => $status,
                'completed' => 1,
                'succeeded' => $status === AdminOperationTask::STATUS_SUCCEEDED ? 1 : 0,
                'failed' => $status === AdminOperationTask::STATUS_FAILED ? 1 : 0,
                'finished_at' => $updatedAt,
                'updated_at' => $updatedAt,
            ]);
        }

        $deleted = (new AdminOperationTaskService($this->createMock(AdminOperationExecutor::class)))->cleanupExpired();

        $this->assertSame(2, $deleted);
        $this->assertFalse(AdminOperationTask::query()->whereKey($expiredSuccess->id)->exists());
        $this->assertFalse(AdminOperationTask::query()->whereKey($expiredFailure->id)->exists());
        $this->assertTrue(AdminOperationTask::query()->whereKey($recentSuccess->id)->exists());
        $this->assertTrue(AdminOperationTask::query()->whereKey($retainedFailure->id)->exists());
        $this->assertSame(2, AdminOperationTaskItem::query()->count());
    }
    private function createTask(array $keys): AdminOperationTask
    {
        $now = time();
        $task = AdminOperationTask::query()->create([
            'id' => '11111111-1111-4111-8111-' . str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT),
            'admin_id' => 9,
            'operation' => AdminOperationExecutor::USER_SET_BALANCE,
            'title' => 'Unit test task',
            'status' => AdminOperationTask::STATUS_QUEUED,
            'total' => count($keys),
            'payload' => ['balance' => 10],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($keys as $key) {
            $task->items()->create([
                'item_key' => $key,
                'label' => '#' . $key,
                'status' => AdminOperationTaskItem::STATUS_PENDING,
                'attempt_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $task;
    }
}
