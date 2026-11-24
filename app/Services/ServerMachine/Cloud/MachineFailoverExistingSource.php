<?php

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\Node\NodeReportSource;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MachineFailoverExistingSource
{
    private const PREFIX = 'existing-source:';

    public function __construct(private MachineAddressInventory $addresses, private NodeInstanceRegistry $nodes) {}

    public static function isDraft(Policy $policy): bool
    {
        return self::isDraftSlot((string) $policy->slot);
    }

    public static function isDraftSlot(string $slot): bool
    {
        return str_starts_with($slot, self::PREFIX);
    }

    public static function slot(string $proof): string
    {
        // Use the existing immutable policy scope, not the ownership or binding tables.
        return self::PREFIX . $proof;
    }

    public function inspect(int $machineId, ?int $policyId = null, ?string $expectedIp = null): array
    {
        [$resource, $account, $ip, $proof, $ips] = $this->evidence($machineId, $policyId);
        if ($expectedIp !== null && !in_array($expectedIp, $ips, true)) throw new CloudException('source_identity_unverified');
        $ip = $expectedIp ?? $ip;
        $client = match ($account->provider) {
            'linode' => app(LinodeClient::class),
            'digitalocean' => app(DigitalOceanClient::class),
            'vultr' => app(VultrClient::class),
            default => throw new CloudException('source_identity_unverified'),
        };
        try {
            $instance = $client->instance($account, $resource->external_id);
        } catch (CloudException) {
            throw new CloudException('source_cloud_read_failed');
        }
        if (($instance['external_id'] ?? null) !== $resource->external_id
            || ($instance['provider_created_at'] ?? null) !== $resource->provider_created_at
            || ($instance['region'] ?? null) !== $resource->region || ($instance['size'] ?? null) !== $resource->size
            || ($expectedIp !== null && ($instance['label'] ?? null) !== $resource->label)
            || ($instance['status'] ?? null) !== 'running' || !in_array($ip, $instance['public_ips'] ?? [], true)) {
            throw new CloudException('source_identity_unverified');
        }
        $this->assertCurrent($machineId, $policyId, $proof);
        return ['machine_id' => $machineId, 'provider' => $account->provider, 'region' => $resource->region,
            'size' => $resource->size, 'proof' => $proof];
    }

    public function assertCurrent(int $machineId, ?int $policyId, string $proof, bool $lock = false): void
    {
        [, , , $current] = $this->evidence($machineId, $policyId, $lock);
        if (!hash_equals($current, $proof)) throw new CloudException('source_identity_changed');
    }

    public function assertDraftCurrent(Policy $policy): void
    {
        $this->assertCurrent($policy->machine_id, $policy->id, substr($policy->slot, strlen(self::PREFIX)), true);
    }

    /** Internal evidence only; do not expose the account or installation through the draft endpoint. */
    public function admissionEvidence(Policy $policy, bool $lock = false): array
    {
        [$resource, $account, , $proof, $ips, $runtime] = $this->evidence($policy->machine_id, $policy->id, $lock);
        return [$resource, $account, $proof, $ips, $runtime];
    }

    public function dnsInspectionAddresses(Policy $policy): array
    {
        [, , , , $ips] = $this->evidence($policy->machine_id, $policy->id);
        return $ips;
    }

    private function evidence(int $machineId, ?int $policyId, bool $lock = false): array
    {
        if (!Schema::hasColumn('v2_machine_cloud_resource', 'provider') || !Schema::hasTable('v2_machine_failover_policy')) {
            throw new CloudException('migration_required');
        }
        $machineQuery = ServerMachine::query();
        $resourceQuery = Resource::query()->where('machine_id', $machineId)->orderBy('id')->limit(2);
        $machine = ($lock ? $machineQuery->lockForUpdate() : $machineQuery)->find($machineId);
        $resources = ($lock ? $resourceQuery->lockForUpdate() : $resourceQuery)->get();
        if (!$machine?->is_active || $resources->count() !== 1) throw new CloudException('source_identity_unverified');
        $resource = $resources->first();
        $installation = NodeReportSource::installation($resource->installation_id);
        if (!$installation || !$resource->provider_created_at || !$machine->token
            || !hash_equals((string) $resource->machine_authority, hash('sha256', (string) $machine->token))) {
            throw new CloudException('source_identity_unverified');
        }
        $policy = $policyId ? Policy::query()->find($policyId) : null;
        if (($policyId && (!$policy || $policy->machine_id !== $machineId || !self::isDraft($policy)))
            || Policy::query()->where('machine_id', $machineId)->when($policyId, fn ($q) => $q->where('id', '!=', $policyId))->exists()
            || $resource->auto_boot || $resource->armed || $resource->active_task_id !== null
            || (Schema::hasTable('v2_machine_owned_instance') && DB::table('v2_machine_owned_instance')->where('resource_id', $resource->id)->exists())
            || (Schema::hasTable('v2_machine_dns_binding') && DB::table('v2_machine_dns_binding')->where('machine_id', $machineId)->exists())) {
            throw new CloudException('machine_in_use');
        }
        $account = Account::query()->find($resource->account_id);
        if (!$account || $account->provider !== $resource->provider) throw new CloudException('source_identity_unverified');
        $now = time();
        try {
            $reports = $this->addresses->snapshots([$machine], true)[$machineId] ?? [];
            $fresh = array_filter($reports, fn ($row) => ($row['at'] ?? 0) >= $now - 180);
            $report = $fresh[$installation] ?? [];
            if (count($fresh) !== 1 || ($report['installation_id'] ?? null) !== $installation || $report['at'] > $now + 30) {
                throw new CloudException('source_agent_unverified');
            }
            $this->assertNodeScope($machineId, $installation, $now);
        } catch (CloudException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new CloudException('source_agent_unverified');
        }
        $ips = [];
        foreach ($report['ips'] ?? [] as $address) {
            if (($address['observed'] ?? true) !== false) continue;
            $ip = MachineEntryProbe::publicIp($address['ip'] ?? null);
            if ($ip && in_array($ip, $resource->public_ips ?? [], true)) $ips[] = $ip;
        }
        sort($ips, SORT_STRING);
        if (!$ips) throw new CloudException('source_agent_unverified');
        $proof = hash_hmac('sha256', json_encode([$machineId, $resource->id, $account->id, $account->revision,
            $resource->provider, $resource->external_id, $resource->provider_created_at, $resource->region,
            $resource->size, $installation, $ips], JSON_THROW_ON_ERROR), (string) $machine->token);
        if ($policy && $policy->slot !== self::slot($proof)) throw new CloudException('source_identity_changed');
        return [$resource, $account, $ips[0], $proof, $ips, $report['runtime'] ?? []];
    }

    private function assertNodeScope(int $machineId, string $installation, int $now): void
    {
        if (!Schema::hasTable('v2_server')) throw new CloudException('source_identity_unverified');
        $bindings = NodeMachineBinding::available();
        $ids = $bindings ? DB::table('v2_server_machine_binding')->where('machine_id', $machineId)->pluck('server_id') : [];
        $nodes = Server::query()->where('enabled', true)->where(fn ($q) => $q->where('machine_id', $machineId)->orWhereIn('id', $ids))->get(['id', 'machine_id']);
        foreach ($nodes as $node) {
            if ((int) $node->machine_id !== $machineId || ($bindings && DB::table('v2_server_machine_binding')
                ->where('server_id', $node->id)->where('machine_id', '!=', $machineId)->exists())) {
                throw new CloudException('machine_in_use');
            }
            foreach ($this->nodes->snapshot($node->id) as $row) {
                if (($row['last_seen_at'] ?? 0) >= $now - 180
                    && (($row['machine_id'] ?? null) !== $machineId || ($row['installation_id'] ?? null) !== $installation)) {
                    throw new CloudException('machine_in_use');
                }
            }
        }
    }
}
