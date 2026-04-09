<?php

namespace App\Console\Commands;

use App\Services\SpamRegistrationService;
use Illuminate\Console\Command;

class ScanSpamRegistrationCandidates extends Command
{
    protected $signature = 'spam-registration:scan';
    protected $description = 'Evaluate users and mark spam-registration cleanup candidates conservatively';

    public function handle(SpamRegistrationService $service): int
    {
        $result = $service->scanCandidates();
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE));
        return self::SUCCESS;
    }
}
