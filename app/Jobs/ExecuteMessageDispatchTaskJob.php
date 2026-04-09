<?php

namespace App\Jobs;

use App\Services\MessageDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteMessageDispatchTaskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;
    private int $taskId;

    public function __construct(int $taskId)
    {
        $this->onQueue('message_dispatch');
        $this->taskId = $taskId;
    }

    public function handle(MessageDispatchService $dispatchService): void
    {
        $dispatchService->processTask($this->taskId);
    }
}
