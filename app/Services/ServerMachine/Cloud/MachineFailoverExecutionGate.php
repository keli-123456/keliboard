<?php
namespace App\Services\ServerMachine\Cloud;

class MachineFailoverExecutionGate
{
    // No setting, environment variable, route or scheduler can arm this bridge.
    public function assertAllowed(string $operation): void { throw new CloudException('orchestration_disabled'); }
}
