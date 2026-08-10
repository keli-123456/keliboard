<?php

namespace App\Jobs;

use App\Services\DomainHealthMonitorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScanDomainHealthJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;
    public int $uniqueFor = 600;

    public function handle(DomainHealthMonitorService $monitor): void
    {
        $monitor->scanAll(true, true);
    }

    public function uniqueId(): string
    {
        return 'domain-health-full-scan';
    }
}
