<?php
namespace App\Console\Commands;

use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Console\Command;

class AdvanceMachineProvisioning extends Command
{
    protected $signature = 'machines:advance-provisioning';
    protected $description = 'Advance explicitly authorized isolated provisioning tasks; no automatic failover';
    public function handle(MachineProvisionService $service): int
    {
        $this->line(json_encode($service->advance())); return self::SUCCESS;
    }
}
