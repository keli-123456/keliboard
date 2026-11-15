<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineInstanceOwnership
{
    public function register(Policy $policy, Instance $instance, MachineProvisionTask $task): MachineCloudResource
    {
        $provider = $task->payload['plan']['provider'] ?? 'linode';
        $resource = MachineCloudResource::query()->where('provider', $provider)->where('external_id', $task->external_id)->first();
        if ($resource && ($resource->account_id !== $task->account_id || $resource->provider_created_at !== $task->provider_created_at
            || ($resource->machine_id && ($resource->machine_id !== $policy->machine_id || $resource->installation_id !== $instance->installation_id))
            || $resource->auto_boot || $resource->armed || $resource->active_task_id)) throw new CloudException('resource_already_owned');
        $resource ??= new MachineCloudResource();
        $resource->fill(['account_id' => $task->account_id, 'provider' => $provider, 'external_id' => $task->external_id,
            'provider_created_at' => $task->provider_created_at, 'label' => MachineProvisionProviders::requestIdentity($provider, $task->payload['request'])['label'],
            'region' => $policy->region, 'size' => $policy->size, 'status' => 'running', 'public_ips' => $task->public_ips,
            'machine_id' => $policy->machine_id, 'installation_id' => $instance->installation_id, 'machine_authority' => $task->machine_authority,
            'observed_at' => time(), 'auto_boot' => false, 'armed' => false, 'manual_hold' => true, 'dns_hold' => true])->save();
        return $resource;
    }

    public function canUse(Policy $policy): bool
    {
        if (!Schema::hasTable('v2_machine_owned_instance') || !Instance::query()->where('policy_id', $policy->id)->exists()) return false;
        try { $this->assertExclusive($policy); return true; } catch (\Throwable) { return false; }
    }

    public function assertExclusive(Policy $policy, ?string $candidate = null): void
    {
        $owned = Instance::query()->where('policy_id', $policy->id)->get();
        $machine = ServerMachine::query()->findOrFail($policy->machine_id);
        foreach ($owned as $instance) {
            $task = MachineProvisionTask::query()->find($instance->task_id);
            if (!$task || $task->installation_id !== $instance->installation_id || $task->machine_id !== $policy->machine_id
                || !app(MachineFailoverCredentials::class)->owns($policy, $task) || $task->slot !== $policy->slot
                || !hash_equals($task->machine_authority, hash('sha256', (string) $machine->token))) throw new CloudException('ownership_mismatch');
            if ($instance->resource_id !== null) {
                $resource = MachineCloudResource::query()->find($instance->resource_id);
                if (!$resource || $resource->account_id !== $task->account_id || $resource->external_id !== $task->external_id
                    || $resource->machine_id !== $policy->machine_id || $resource->installation_id !== $instance->installation_id
                    || $resource->provider_created_at !== $task->provider_created_at) throw new CloudException('ownership_mismatch');
            }
        }
        $allowed = $owned->pluck('installation_id')->all(); if ($candidate !== null) $allowed[] = $candidate;
        if (MachineCloudResource::query()->where('machine_id', $policy->machine_id)->whereNotIn('id', $owned->pluck('resource_id')->filter())->exists()) throw new CloudException('machine_in_use');
        if (Schema::hasTable('v2_machine_dns_binding') && DB::table('v2_machine_dns_binding')->where('machine_id', $policy->machine_id)->exists()) throw new CloudException('machine_in_use');
        foreach ((app(MachineAddressInventory::class)->snapshots([$machine], true)[$machine->id] ?? []) as $id => $row) {
            if (($row['at'] ?? 0) >= time() - 300 && !in_array($id, $allowed, true)) throw new CloudException('machine_in_use');
        }
        $bindings = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $machine->id)->pluck('server_id') : [];
        $nodes = Server::query()->where('enabled', true)->where(fn ($query) => $query->where('machine_id', $machine->id)->orWhereIn('id', $bindings))->get(['id', 'machine_id']);
        foreach ($nodes as $node) {
            if ($node->machine_id !== $machine->id || (NodeMachineBinding::available()
                && DB::table('v2_server_machine_binding')->where('server_id', $node->id)->where('machine_id', '!=', $machine->id)->exists())) throw new CloudException('shared_node_group');
            foreach (app(NodeInstanceRegistry::class)->snapshot($node->id) as $report) {
                if (($report['last_seen_at'] ?? 0) >= time() - 300 && (($report['machine_id'] ?? null) !== $machine->id
                    || !in_array($report['installation_id'] ?? null, $allowed, true))) throw new CloudException('shared_node_group');
            }
        }
    }
}
