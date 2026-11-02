<?php

namespace App\Jobs;

use App\Services\SubscriptionAiAutomationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSubscriptionAiActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 1;
    public int $timeout = 60;
    public bool $failOnTimeout = true;
    public function __construct(public readonly int $actionId)
    {
        $this->onConnection('redis_ai'); $this->onQueue('risk_ai');
    }
    public function handle(SubscriptionAiAutomationService $service): void { $service->process($this->actionId); }
    public function failed(?\Throwable $error): void { app(SubscriptionAiAutomationService::class)->fail($this->actionId, 'worker_failed'); }
}
