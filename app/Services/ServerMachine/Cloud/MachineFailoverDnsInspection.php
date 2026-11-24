<?php

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverDnsSet;
use App\Models\MachineFailoverDnsSlot;
use App\Models\MachineFailoverMember;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineOwnedInstance;
use App\Models\ServerMachine;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetInspector;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Schema;

final class MachineFailoverDnsInspection
{
    public function __construct(private MachineFailoverDnsRegistry $dns, private MachineFailoverServiceRegistry $services,
        private DnsProviderClient $provider, private MachineDnsRecordSetInspector $inspector, private MachineAddressInventory $addresses) {}

    public function inspect(int $service, int $revision, int $setId, int $setRevision, int $admin): array
    {
        [$context, $credential] = $this->scope($service, $revision, $setId, $setRevision, $admin);
        $before = $this->expected($context['members']);
        try {
            $records = $this->provider->inspectRecordSet($credential, $context['set']);
        } catch (DnsException) {
            throw new CloudException('dns_inventory_unconfirmed');
        }
        [$current] = $this->scope($service, $revision, $setId, $setRevision, $admin);
        if ($current !== $context) throw new CloudException('config_changed');
        $after = $this->expected($current['members']); $addresses = [];
        // A changed or expired address observation cannot confirm a provider record.
        foreach ($after as $member => $evidence) {
            if (($before[$member]['identity'] ?? null) === $evidence['identity']) {
                $addresses[$member] = array_intersect_assoc($evidence['addresses'], $before[$member]['addresses']);
            }
        }
        $set = $context['set'];
        return ['schema' => 1, 'read_only' => true, 'dns_writes' => 0, 'cloud_writes' => 0, 'service_id' => $service, 'service_revision' => $revision,
            'set_id' => $setId, 'set_revision' => $setRevision, 'credential_id' => $set['credential_id'], 'credential_revision' => $set['credential_revision'],
            'hostname' => $set['hostname'], 'provider' => $set['provider'], 'checked_at' => time(),
            'rows' => $this->inspector->compare($set, $context['slots'], $context['members'], $addresses, $records)];
    }

    private function scope(int $service, int $revision, int $setId, int $setRevision, int $admin): array
    {
        $this->services->actor($admin);
        if (!$this->dns->available()) throw new CloudException('migration_required');
        $this->dns->service($service, $revision);
        $set = MachineFailoverDnsSet::query()->where('service_id', $service)->find($setId);
        if (!$set || $set->revision !== $setRevision) throw new CloudException('config_changed');
        $credential = MachineDnsCredential::query()->find($set->credential_id);
        if (!$credential || $credential->revision !== $set->credential_revision || $credential->provider !== $set->provider) {
            throw new CloudException('dns_credential_changed');
        }
        $slots = MachineFailoverDnsSlot::query()->where('set_id', $setId)->orderBy('id')->limit(121)->get();
        if ($slots->isEmpty() || $slots->count() > 120 || $slots->pluck('marker')->unique()->count() !== $slots->count()) throw new CloudException('invalid_dns_members');
        foreach ($slots as $slot) {
            if (!in_array($slot->type, ['A', 'AAAA'], true)
                || !in_array($slot->line, $set->provider === 'aliyun' ? MachineDnsRecordSetProvider::LINES : ['default'], true)
                || !preg_match('/^keli-set-[a-f0-9]{48}$/D', $slot->marker)) throw new CloudException('invalid_dns_members');
        }
        $ids = $slots->pluck('member_id')->unique()->all();
        $members = MachineFailoverMember::query()->where('service_id', $service)->whereIn('id', $ids)->orderBy('id')->get();
        if ($members->count() !== count($ids) || $members->count() > 10) throw new CloudException('invalid_dns_members');
        return [['set' => $set->only(['id', 'revision', 'provider', 'credential_id', 'credential_revision', 'zone', 'zone_name', 'hostname', 'ttl']),
            'members' => $members->keyBy('id')->map(fn ($m) => $m->only(['id', 'policy_id', 'policy_revision', 'machine_id', 'enabled']))->all(),
            'slots' => $slots->map(fn ($s) => $s->only(['id', 'member_id', 'type', 'line', 'marker']))->all()], $credential];
    }

    private function expected(array $members): array
    {
        $result = [];
        foreach ($members as $member) {
            if (!$member['enabled']) continue;
            $policy = MachineFailoverPolicy::query()->find($member['policy_id']);
            if (!$policy || $policy->revision !== $member['policy_revision'] || $policy->machine_id !== $member['machine_id'] || !is_string($policy->machine_authority)) continue;
            try {
                [$identity, $ips] = MachineFailoverExistingSource::isDraft($policy)
                    ? [$policy->slot, app(MachineFailoverExistingSource::class)->dnsInspectionAddresses($policy)]
                    : $this->ownedAddresses($policy);
            } catch (CloudException) {
                continue;
            }
            $families = [];
            foreach (['A', 'AAAA'] as $family) {
                $familyIps = array_values(array_unique(array_filter($ips, fn ($ip) => str_contains($ip, ':') === ($family === 'AAAA'))));
                if (count($familyIps) === 1) $families[$family] = $familyIps[0];
            }
            if ($families) $result[$member['id']] = ['identity' => $identity, 'addresses' => $families];
        }
        return $result;
    }

    private function ownedAddresses(MachineFailoverPolicy $policy): array
    {
        if (!Schema::hasTable('v2_machine_owned_instance')) return [null, []];
        $instances = MachineOwnedInstance::query()->where('active_policy_id', $policy->id)->limit(2)->get();
        if ($instances->count() !== 1) return [null, []];
        $instance = $instances->first();
        $pin = app(MachineNativeDnsAdmission::class)->sourcePin($policy, $instance);
        $machine = ServerMachine::query()->find($policy->machine_id);
        if (!$machine?->is_active || !hash_equals($policy->machine_authority, hash('sha256', (string) $machine->token))) return [null, []];
        $now = time();
        $reports = $this->addresses->snapshots([$machine])[$machine->id] ?? [];
        $fresh = array_filter($reports, fn ($r) => ($r['at'] ?? 0) >= $now - 180);
        $report = $fresh[$instance->installation_id] ?? null;
        if (count($fresh) !== 1 || !$report || $report['at'] > $now + 30 || ($report['installation_id'] ?? null) !== $instance->installation_id) return [null, []];
        $reported = [];
        foreach ($report['ips'] ?? [] as $row) {
            if (($row['observed'] ?? true) === false && ($ip = MachineEntryProbe::publicIp($row['ip'] ?? null))) $reported[] = $ip;
        }
        return [hash('sha256', json_encode($pin, JSON_THROW_ON_ERROR)), array_values(array_intersect($pin['addresses'], $reported))];
    }
}
