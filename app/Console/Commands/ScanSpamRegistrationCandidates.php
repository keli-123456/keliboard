<?php

namespace App\Console\Commands;

use App\Services\SpamRegistrationService;
use App\Services\MessageOpsSettings;
use Illuminate\Console\Command;

class ScanSpamRegistrationCandidates extends Command
{
    protected $signature = 'spam-registration:scan';
    protected $description = 'Evaluate users and mark spam-registration cleanup candidates conservatively';

    public function handle(SpamRegistrationService $service): int
    {
        if (!MessageOpsSettings::enabled()) {
            $this->info(json_encode([
                'enabled' => false,
                'scanned' => 0,
                'flagged' => 0,
                'preserved' => 0,
            ], JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $result = $service->scanCandidates();
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
