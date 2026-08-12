<?php

namespace App\Console\Commands;

use App\Services\AiDiagnosticService;
use Illuminate\Console\Command;

class ScanAiDiagnostics extends Command
{
    protected $signature = 'ai-diagnostics:scan';
    protected $description = 'Generate read-only local diagnostic reports when enabled';

    public function handle(AiDiagnosticService $service): int
    {
        $this->info(json_encode($service->runScheduled(), JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
