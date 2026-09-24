<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\TrafficBatchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverTrafficBatches extends Command
{
    protected $signature = 'traffic-batches:recover {--limit=200}';
    protected $description = 'Re-enqueue durable traffic batches whose application or quota sync is unfinished';

    public function handle(TrafficBatchService $service): int
    {
        if (!Schema::hasTable('v2_traffic_batch')) {
            return self::SUCCESS;
        }
        $this->info('Traffic batches scheduled: ' . $service->recover((int) $this->option('limit')));
        return self::SUCCESS;
    }
}
