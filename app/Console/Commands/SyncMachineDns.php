<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\Dns\MachineDnsService;
use Illuminate\Console\Command;

class SyncMachineDns extends Command
{
    protected $signature = 'machines:dns-sync';
    protected $description = 'Queue enabled instance DNS bindings using existing telemetry';
    public function handle(MachineDnsService $service): int
    {
        $this->line((string) $service->schedule());
        return self::SUCCESS;
    }
}
