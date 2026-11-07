<?php
namespace App\Services\ServerMachine\Cloud;

// Only a claimed worker binds this in the current container; HTTP inputs never supply it.
final class MachineFailoverLease
{
    public function __construct(public readonly string $execution, public readonly int $generation, public readonly string $ticket) {}
}
