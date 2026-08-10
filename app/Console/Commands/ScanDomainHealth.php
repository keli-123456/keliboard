<?php

namespace App\Console\Commands;

use App\Services\DomainHealthMonitorService;
use Illuminate\Console\Command;

class ScanDomainHealth extends Command
{
    protected $signature = 'domain-health:scan {--force : Run even when automatic monitoring is disabled} {--no-notify : Do not send alert or recovery notifications}';

    protected $description = 'Discover and check platform, site, agent, and navigation domains';

    public function handle(DomainHealthMonitorService $monitor): int
    {
        $result = $monitor->scanAll(
            !$this->option('no-notify'),
            (bool) $this->option('force'),
        );

        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
