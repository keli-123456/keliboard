<?php

namespace App\Services;

use App\Jobs\ProcessAdminOperationTaskJob;
use App\Models\AdminAuditLog;
use App\Models\AdminOperationTask;
use App\Models\AdminOperationTaskItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AdminOperationTaskService
{
    public function __construct(private readonly AdminOperationExecutor $executor)
    {
    }

    public function create(User $admin, array $attributes): AdminOperationTask
    {
        $operation = trim((string) ($attributes['operation'] ?? ''));
        $payload = $this->executor->validateTaskPayload($operation, (array) ($attributes['payload'] ?? []));
        $items = $this->normalizeItems((array) ($attributes['items'] ?? []));
        if ($items === []) {
            throw new RuntimeException('At least one task item is required.');
        }

        $clientToken = trim((string) ($attributes['client_token'] ?? ''));
        if ($clientToken !== '') {
            $existing = AdminOperationTask::query()
                ->where('admin_id', $admin->id)
                ->where('client_token', $clientToken)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        try {
            $task = DB::transaction(function () use ($admin, $attributes, $operation, $payload, $items, $clientToken) {
            $now = time();
            $task = AdminOperationTask::query()->create([
                'id' => (string) Str::uuid(),
                'admin_id' => (int) $admin->id,
                'operation' => $operation,
                'title' => mb_substr(trim((string) ($attributes['title'] ?? $operation)), 0, 191),
                'description' => $this->nullableText($attributes['description'] ?? null),
                'source_path' => $this->nullableText($attributes['source_path'] ?? null, 255),
                'status' => AdminOperationTask::STATUS_QUEUED,
                'total' => count($items),
                'payload' => $payload ?: null,
                'context' => [
                    'ip' => $attributes['ip'] ?? null,
                    'user_agent' => $this->nullableText($attributes['user_agent'] ?? null, 512),
                ],
                'client_token' => $clientToken !== '' ? $clientToken : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (array_chunk($items, 500) as $chunk) {
                AdminOperationTaskItem::query()->insert(array_map(fn (array $item) => [
                    'task_id' => $task->id,
                    'item_key' => $item['key'],
                    'label' => $item['label'],
                    'payload' => $item['payload'] === null ? null : json_encode(
                        $item['payload'],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                    'status' => AdminOperationTaskItem::STATUS_PENDING,
                    'attempt_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            return $task;
            });
        } catch (QueryException $exception) {
            $existing = $clientToken === '' ? null : AdminOperationTask::query()
                ->where('admin_id', $admin->id)
                ->where('client_token', $clientToken)
                ->first();
            if (!$existing) {
                throw $exception;
            }

            return $existing;
        }

        $this->dispatch($task);

        return $task->refresh();
    }

    public function tasksForAdmin(User $admin, ?int $limit = null): Collection
    {
        return AdminOperationTask::query()
            ->where('admin_id', $admin->id)
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit ?: config('admin_operations.history_limit', 50), 100)))
            ->get();
    }

    public function findForAdmin(User $admin, string $taskId): AdminOperationTask
    {
        return AdminOperationTask::query()
            ->where('admin_id', $admin->id)
            ->whereKey($taskId)
            ->firstOrFail();
    }

    public function cancel(AdminOperationTask $task): AdminOperationTask
    {
        if ($task->isTerminal()) {
            return $task;
        }

        $now = time();
        $task->update(['cancel_requested_at' => $now, 'updated_at' => $now]);
        if ($task->status === AdminOperationTask::STATUS_QUEUED) {
            AdminOperationTaskItem::query()
                ->where('task_id', $task->id)
                ->where('status', AdminOperationTaskItem::STATUS_PENDING)
                ->update([
                    'status' => AdminOperationTaskItem::STATUS_CANCELLED,
                    'finished_at' => $now,
                    'updated_at' => $now,
                ]);
            $this->finalize($task->refresh());
        }

        return $task->refresh();
    }

    public function retryFailed(AdminOperationTask $task): AdminOperationTask
    {
        if ($task->status === AdminOperationTask::STATUS_RUNNING || $task->status === AdminOperationTask::STATUS_QUEUED) {
            throw new RuntimeException('Task is still running.');
        }

        $now = time();
        $retried = AdminOperationTaskItem::query()
            ->where('task_id', $task->id)
            ->where('status', AdminOperationTaskItem::STATUS_FAILED)
            ->update([
                'status' => AdminOperationTaskItem::STATUS_PENDING,
                'result' => null,
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ]);
        if ($retried === 0) {
            throw new RuntimeException('Task has no failed items to retry.');
        }

        $task->update([
            'status' => AdminOperationTask::STATUS_QUEUED,
            'cancel_requested_at' => null,
            'finished_at' => null,
            'last_error' => null,
            'updated_at' => $now,
        ]);
        $this->refreshCounters($task);
        $this->dispatch($task);

        return $task->refresh();
    }

    public function dismiss(AdminOperationTask $task): void
    {
        if (!$task->isTerminal()) {
            throw new RuntimeException('Running tasks cannot be dismissed.');
        }
        $task->update(['dismissed_at' => time(), 'updated_at' => time()]);
    }

    public function process(string $taskId): void
    {
        $now = time();
        $claimed = AdminOperationTask::query()
            ->whereKey($taskId)
            ->where('status', AdminOperationTask::STATUS_QUEUED)
            ->update([
                'status' => AdminOperationTask::STATUS_RUNNING,
                'started_at' => DB::raw('COALESCE(started_at, ' . $now . ')'),
                'updated_at' => $now,
            ]);
        if ($claimed === 0) {
            return;
        }

        while (true) {
            $task = AdminOperationTask::query()->find($taskId);
            if (!$task || $task->status !== AdminOperationTask::STATUS_RUNNING) {
                return;
            }
            if ($task->cancel_requested_at !== null) {
                $this->cancelPendingItems($task);
                $this->finalize($task);
                return;
            }

            $item = AdminOperationTaskItem::query()
                ->where('task_id', $taskId)
                ->where('status', AdminOperationTaskItem::STATUS_PENDING)
                ->orderBy('id')
                ->first();
            if (!$item) {
                $this->finalize($task);
                return;
            }

            $itemId = (int) $item->id;
            $itemClaimed = AdminOperationTaskItem::query()
                ->whereKey($itemId)
                ->where('status', AdminOperationTaskItem::STATUS_PENDING)
                ->update([
                    'status' => AdminOperationTaskItem::STATUS_RUNNING,
                    'attempt_count' => DB::raw('attempt_count + 1'),
                    'started_at' => time(),
                    'updated_at' => time(),
                ]);
            if ($itemClaimed === 0) {
                continue;
            }

            try {
                DB::transaction(function () use ($taskId, $itemId): void {
                    $lockedTask = AdminOperationTask::query()->lockForUpdate()->findOrFail($taskId);
                    $lockedItem = AdminOperationTaskItem::query()->lockForUpdate()->findOrFail($itemId);
                    if ($lockedTask->cancel_requested_at !== null) {
                        $lockedItem->update([
                            'status' => AdminOperationTaskItem::STATUS_CANCELLED,
                            'finished_at' => time(),
                            'updated_at' => time(),
                        ]);
                        return;
                    }
                    if ($lockedItem->status !== AdminOperationTaskItem::STATUS_RUNNING) {
                        return;
                    }

                    $execution = $this->executor->execute($lockedTask, $lockedItem);
                    $lockedItem->update([
                        'status' => ($execution['skipped'] ?? false)
                            ? AdminOperationTaskItem::STATUS_SKIPPED
                            : AdminOperationTaskItem::STATUS_SUCCEEDED,
                        'result' => $execution['result'] ?? null,
                        'error_message' => null,
                        'finished_at' => time(),
                        'updated_at' => time(),
                    ]);
                });
            } catch (Throwable $e) {
                AdminOperationTaskItem::query()
                    ->whereKey($itemId)
                    ->where('status', AdminOperationTaskItem::STATUS_RUNNING)
                    ->update([
                        'status' => AdminOperationTaskItem::STATUS_FAILED,
                        'error_message' => mb_substr($e->getMessage(), 0, 4000),
                        'finished_at' => time(),
                        'updated_at' => time(),
                    ]);
            }

            $this->refreshCounters($task);
        }
    }

    public function markJobFailed(string $taskId, Throwable $error): void
    {
        $now = time();
        AdminOperationTaskItem::query()
            ->where('task_id', $taskId)
            ->where('status', AdminOperationTaskItem::STATUS_RUNNING)
            ->update([
                'status' => AdminOperationTaskItem::STATUS_FAILED,
                'error_message' => mb_substr($error->getMessage(), 0, 4000),
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        $task = AdminOperationTask::query()->find($taskId);
        if (!$task || $task->status !== AdminOperationTask::STATUS_RUNNING) {
            return;
        }
        $this->refreshCounters($task);
        $task->update([
            'status' => AdminOperationTask::STATUS_INTERRUPTED,
            'last_error' => mb_substr($error->getMessage(), 0, 4000),
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
        $this->recordCompletionAudit($task->refresh());
    }

    public function recoverStale(int $limit = 100): int
    {
        $cutoff = time() - (int) config('admin_operations.stale_after_seconds', 1800);
        $tasks = AdminOperationTask::query()
            ->where('status', AdminOperationTask::STATUS_RUNNING)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('updated_at')
            ->limit(max(1, min($limit, 500)))
            ->get();

        foreach ($tasks as $task) {
            $this->markJobFailed((string) $task->id, new RuntimeException('Operation worker stopped before completion.'));
        }

        return $tasks->count();
    }

    public function serialize(AdminOperationTask $task, int $failureLimit = 50): array
    {
        $failures = $task->items()
            ->where('status', AdminOperationTaskItem::STATUS_FAILED)
            ->orderBy('id')
            ->limit(max(0, $failureLimit))
            ->get(['item_key', 'label', 'error_message'])
            ->map(fn (AdminOperationTaskItem $item) => [
                'key' => $item->item_key,
                'label' => $item->label,
                'message' => $item->error_message ?: 'Operation failed.',
            ])->values()->all();

        return [
            'id' => (string) $task->id,
            'operation' => $task->operation,
            'title' => $task->title,
            'description' => $task->description,
            'source_path' => $task->source_path,
            'status' => $task->status,
            'total' => (int) $task->total,
            'completed' => (int) $task->completed,
            'succeeded' => (int) $task->succeeded,
            'failed' => (int) $task->failed,
            'skipped' => (int) $task->skipped,
            'cancelled' => (int) $task->cancelled,
            'failures' => $failures,
            'can_retry' => (int) $task->failed > 0 && $task->status !== AdminOperationTask::STATUS_RUNNING,
            'can_cancel' => in_array($task->status, [AdminOperationTask::STATUS_QUEUED, AdminOperationTask::STATUS_RUNNING], true),
            'last_error' => $task->last_error,
            'created_at' => (int) $task->getRawOriginal('created_at'),
            'updated_at' => (int) $task->getRawOriginal('updated_at'),
            'started_at' => $task->getRawOriginal('started_at') !== null ? (int) $task->getRawOriginal('started_at') : null,
            'finished_at' => $task->getRawOriginal('finished_at') !== null ? (int) $task->getRawOriginal('finished_at') : null,
        ];
    }

    private function dispatch(AdminOperationTask $task): void
    {
        ProcessAdminOperationTaskJob::dispatch((string) $task->id)
            ->onConnection((string) config('admin_operations.connection', 'redis'))
            ->onQueue((string) config('admin_operations.queue', 'admin_operations'));
    }

    private function normalizeItems(array $items): array
    {
        $maxItems = (int) config('admin_operations.max_items', 5000);
        if (count($items) > $maxItems) {
            throw new RuntimeException("Task item count exceeds {$maxItems}.");
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '' || mb_strlen($key) > 191) {
                continue;
            }
            $normalized[$key] = [
                'key' => $key,
                'label' => $this->nullableText($item['label'] ?? null, 255),
                'payload' => isset($item['payload']) && is_array($item['payload']) ? $item['payload'] : null,
            ];
        }

        return array_values($normalized);
    }

    private function cancelPendingItems(AdminOperationTask $task): void
    {
        $now = time();
        AdminOperationTaskItem::query()
            ->where('task_id', $task->id)
            ->where('status', AdminOperationTaskItem::STATUS_PENDING)
            ->update([
                'status' => AdminOperationTaskItem::STATUS_CANCELLED,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function refreshCounters(AdminOperationTask $task): void
    {
        $counts = AdminOperationTaskItem::query()
            ->where('task_id', $task->id)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $succeeded = (int) ($counts[AdminOperationTaskItem::STATUS_SUCCEEDED] ?? 0);
        $failed = (int) ($counts[AdminOperationTaskItem::STATUS_FAILED] ?? 0);
        $skipped = (int) ($counts[AdminOperationTaskItem::STATUS_SKIPPED] ?? 0);
        $cancelled = (int) ($counts[AdminOperationTaskItem::STATUS_CANCELLED] ?? 0);
        $task->update([
            'completed' => $succeeded + $failed + $skipped + $cancelled,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'skipped' => $skipped,
            'cancelled' => $cancelled,
            'updated_at' => time(),
        ]);
    }

    private function finalize(AdminOperationTask $task): void
    {
        $this->refreshCounters($task);
        $task->refresh();
        if ($task->cancel_requested_at !== null || $task->cancelled > 0) {
            $status = AdminOperationTask::STATUS_CANCELLED;
        } elseif ($task->failed === 0) {
            $status = AdminOperationTask::STATUS_SUCCEEDED;
        } elseif ($task->succeeded + $task->skipped > 0) {
            $status = AdminOperationTask::STATUS_PARTIAL;
        } else {
            $status = AdminOperationTask::STATUS_FAILED;
        }

        $task->update([
            'status' => $status,
            'finished_at' => time(),
            'updated_at' => time(),
        ]);
        $this->recordCompletionAudit($task->refresh());
    }

    private function recordCompletionAudit(AdminOperationTask $task): void
    {
        try {
            AdminAuditLog::query()->insert([
                'admin_id' => (int) $task->admin_id,
                'action' => 'operation_task.' . $task->status,
                'method' => 'QUEUE',
                'uri' => 'queue://admin-operation-task/' . $task->id,
                'request_data' => json_encode([
                    'task_id' => $task->id,
                    'operation' => $task->operation,
                    'total' => (int) $task->total,
                    'succeeded' => (int) $task->succeeded,
                    'failed' => (int) $task->failed,
                    'skipped' => (int) $task->skipped,
                    'cancelled' => (int) $task->cancelled,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => data_get($task->context, 'ip'),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (Throwable) {
            // Audit failures must not change an already completed operation result.
        }
    }

    private function nullableText(mixed $value, ?int $limit = null): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return $limit ? mb_substr($text, 0, $limit) : $text;
    }
}
