<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAiAutomationService;
use Illuminate\Console\Command;

class AdvanceSubscriptionAi extends Command
{
    protected $signature = 'subscription-ai:advance';
    protected $description = 'Expire scoped protections and queue bounded asynchronous subscription AI decisions';
    public function handle(SubscriptionAiAutomationService $service): int
    {
        $this->line(json_encode($service->tick(), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
