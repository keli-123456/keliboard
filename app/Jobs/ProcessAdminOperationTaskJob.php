<?php

namespace App\Jobs;

use App\Services\AdminOperationTaskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessAdminOperationTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(private readonly string $taskId)
    {
    }

    public function handle(AdminOperationTaskService $tasks): void
    {
        $tasks->process($this->taskId);
    }

    public function failed(Throwable $error): void
    {
        app(AdminOperationTaskService::class)->markJobFailed($this->taskId, $error);
    }
}
