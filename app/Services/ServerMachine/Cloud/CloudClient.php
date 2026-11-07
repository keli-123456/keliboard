<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

interface CloudClient
{
    public function inventory(MachineCloudAccount $account, int $page): array;
    public function instance(MachineCloudAccount $account, string $id): array;
    public function power(MachineCloudAccount $account, string $id, string $action): void;
    public function safety(MachineCloudAccount $account, string $id, int $since): array;
}
