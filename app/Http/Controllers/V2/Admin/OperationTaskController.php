<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminOperationTaskItem;
use App\Services\AdminOperationTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationTaskController extends Controller
{
    public function __construct(private readonly AdminOperationTaskService $tasks)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['limit' => 'nullable|integer|min:1|max:100']);
        $items = $this->tasks->tasksForAdmin($request->user(), $request->integer('limit') ?: null)
            ->map(fn ($task) => $this->tasks->serialize($task))
            ->values()
            ->all();

        return $this->success($items);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = $request->validate([
            'operation' => 'required|string|max:64',
            'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'source_path' => 'nullable|string|max:255',
            'client_token' => 'nullable|string|max:64',
            'payload' => 'nullable|array',
            'items' => 'required|array|min:1|max:' . config('admin_operations.max_items', 5000),
            'items.*.key' => 'required|string|max:191',
            'items.*.label' => 'nullable|string|max:255',
            'items.*.payload' => 'nullable|array',
        ]);
        $attributes['ip'] = $request->getClientIp();
        $attributes['user_agent'] = $request->userAgent();

        try {
            $task = $this->tasks->create($request->user(), $attributes);
        } catch (RuntimeException $error) {
            return $this->fail([400, $error->getMessage()]);
        }

        return $this->success($this->tasks->serialize($task));
    }

    public function show(Request $request, string $taskId): JsonResponse
    {
        $task = $this->tasks->findForAdmin($request->user(), $taskId);

        return $this->success($this->tasks->serialize($task, 500));
    }

    public function cancel(Request $request, string $taskId): JsonResponse
    {
        $task = $this->tasks->findForAdmin($request->user(), $taskId);

        return $this->success($this->tasks->serialize($this->tasks->cancel($task)));
    }

    public function retry(Request $request, string $taskId): JsonResponse
    {
        $task = $this->tasks->findForAdmin($request->user(), $taskId);
        try {
            $task = $this->tasks->retryFailed($task);
        } catch (RuntimeException $error) {
            return $this->fail([400, $error->getMessage()]);
        }

        return $this->success($this->tasks->serialize($task));
    }

    public function dismiss(Request $request, string $taskId): JsonResponse
    {
        $task = $this->tasks->findForAdmin($request->user(), $taskId);
        try {
            $this->tasks->dismiss($task);
        } catch (RuntimeException $error) {
            return $this->fail([400, $error->getMessage()]);
        }

        return $this->success(true);
    }

    public function exportFailures(Request $request, string $taskId): StreamedResponse
    {
        $task = $this->tasks->findForAdmin($request->user(), $taskId);
        $filename = 'operation-task-' . $task->id . '-failures.csv';

        return response()->streamDownload(function () use ($task): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['key', 'label', 'attempts', 'error']);
            AdminOperationTaskItem::query()
                ->where('task_id', $task->id)
                ->where('status', AdminOperationTaskItem::STATUS_FAILED)
                ->orderBy('id')
                ->chunkById(500, function ($items) use ($output): void {
                    foreach ($items as $item) {
                        fputcsv($output, [
                            $item->item_key,
                            $item->label,
                            (int) $item->attempt_count,
                            $item->error_message,
                        ]);
                    }
                });
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
