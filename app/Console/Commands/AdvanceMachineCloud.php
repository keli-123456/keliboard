<?php
namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineCloudService;
use Illuminate\Console\Command;

class AdvanceMachineCloud extends Command
{
    protected $signature = 'machines:advance-cloud';
    protected $description = 'Reconcile existing power tasks and authorized recovery policies';
    public function handle(MachineCloudService $service): int { $this->line(json_encode($service->schedule())); return self::SUCCESS; }
}
