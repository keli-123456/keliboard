<?php

namespace App\Jobs;

use App\Services\SubscriptionControlAiAdvisorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateSubscriptionControlAiReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 150;

    public function __construct(public readonly int $reviewId)
    {
        $this->onQueue('ticket_ai');
    }

    public function handle(SubscriptionControlAiAdvisorService $service): void
    {
        $service->generate($this->reviewId);
    }
}
