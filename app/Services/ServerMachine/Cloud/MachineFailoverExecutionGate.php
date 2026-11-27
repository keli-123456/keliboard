<?php
namespace App\Services\ServerMachine\Cloud;

class MachineFailoverExecutionGate
{
    public function assertAllowed(string $operation): void
    {
        if (MachineFailoverOperatorPermit::current()?->allows($operation) !== true) throw new CloudException('orchestration_disabled');
    }
}
