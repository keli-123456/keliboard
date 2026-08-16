<?php

namespace App\Jobs;

use App\Services\SubscriptionControlAiAdvisorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSubscriptionControlAiReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $reviewId)
    {
        $this->onConnection('redis_ai');
        $this->onQueue('risk_ai');
    }

    public function handle(SubscriptionControlAiAdvisorService $service): void
    {
        $service->generate($this->reviewId);
    }

    public function failed(?Throwable $exception): void
    {
        $message = strtolower((string) ($exception?->getMessage() ?? ''));
        $code = str_contains($message, 'timed out') || str_contains($message, 'timeout')
            ? 'review_timeout'
            : 'job_failed';

        app(SubscriptionControlAiAdvisorService::class)->failPendingReview($this->reviewId, $code);
    }
}
