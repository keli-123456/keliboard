<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

interface ProvisionClient
{
    public function buildRequest(array $plan, string $id, string $marker, string $bootstrap): array;
    public function preflight(MachineCloudAccount $account, array $plan): void;
    public function create(MachineCloudAccount $account, array $request, int $since): array;
    public function reconcile(MachineCloudAccount $account, array $request, int $since): ?array;
    public function instance(MachineCloudAccount $account, string $id): array;
}
