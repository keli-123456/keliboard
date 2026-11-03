<?php

namespace App\Console\Commands;

use App\Services\ServerMachine\MachineRiskEventService;
use Illuminate\Console\Command;

class AdvanceMachineRisk extends Command
{
    protected $signature = 'machine-risk:advance';
    protected $description = 'Reconcile read-only node risk observations and expire bounded history';
    public function handle(MachineRiskEventService $service): int
    {
        $this->line(json_encode($service->advance(), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
