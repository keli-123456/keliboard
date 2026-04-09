<?php

namespace App\Console\Commands;

use App\Services\MarketingAutomationService;
use App\Services\MessageOpsSettings;
use Illuminate\Console\Command;

class ScanMarketingRules extends Command
{
    protected $signature = 'marketing:scan';
    protected $description = 'Scan enabled marketing rules and enqueue dispatch tasks';

    public function handle(MarketingAutomationService $service): int
    {
        if (!MessageOpsSettings::enabled()) {
            $this->info(json_encode([
                'enabled' => false,
                'matched' => 0,
                'queued' => 0,
                'skipped' => 0,
            ], JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $result = $service->scanEnabledRules();
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
