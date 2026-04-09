<?php

namespace App\Console\Commands;

use App\Services\MarketingAutomationService;
use Illuminate\Console\Command;

class ScanMarketingRules extends Command
{
    protected $signature = 'marketing:scan';
    protected $description = 'Scan enabled marketing rules and enqueue dispatch tasks';

    public function handle(MarketingAutomationService $service): int
    {
        $result = $service->scanEnabledRules();
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
