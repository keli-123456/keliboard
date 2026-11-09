<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

interface FailoverCostClient
{
    public function authority(MachineCloudAccount $account): bool;
    public function completeInventory(MachineCloudAccount $account): array;
    public function quote(MachineCloudAccount $account, array $row): int;
}
