<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TrafficBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrafficBatchApplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public int $receiptId)
    {
        $this->onQueue('traffic_fetch');
    }

    public function backoff(): array
    {
        return [1, 5, 30];
    }

    public function handle(): void
    {
        app(TrafficBatchService::class)->process($this->receiptId);
    }
}
