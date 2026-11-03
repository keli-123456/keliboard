<?php

namespace App\Jobs;

use App\Services\ServerMachine\SubscriptionRenderEvidence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RecordSubscriptionRenderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 20;
    public function __construct(public readonly array $receipt)
    {
        $this->onConnection('redis_ai'); $this->onQueue('risk_ai');
    }
    public function handle(SubscriptionRenderEvidence $service): void { $service->persist($this->receipt); }
}
