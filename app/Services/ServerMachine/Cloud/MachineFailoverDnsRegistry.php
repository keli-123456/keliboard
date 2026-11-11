<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverDnsRun as Run;
use App\Models\MachineFailoverDnsSet as DnsSet;
use App\Models\MachineFailoverDnsSlot as Slot;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverService as Service;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineFailoverDnsRegistry
{
    public const CLOSED = ['simulation_only' => true, 'execution_enabled' => false, 'automatic_rotation' => false,
        'dns_cutover' => false, 'cloud_writes' => 0, 'dns_writes' => 0, 'propagation_verified' => false];

    public function __construct(private MachineFailoverServiceRegistry $registry) {}
    public function available(): bool
    {
        foreach (['set', 'slot', 'run'] as $name) if (!Schema::hasTable('v2_machine_failover_dns_' . $name)) return false;
        foreach (['runtime', 'fixture'] as $name) if (!Schema::hasTable('v2_machine_dns_set_' . $name)) return false;
        return $this->registry->available() && Schema::hasTable('v2_machine_dns_credential');
    }

    public function overview(int $service, int $revision, int $admin): array
    {
        $this->registry->actor($admin);
        if (!$this->available()) return self::CLOSED + ['available' => false, 'service_id' => $service, 'service_revision' => $revision,
            'members' => [], 'credentials' => [], 'sets' => [], 'scenarios' => MachineFailoverDnsSimulation::SCENARIOS];
        $row = $this->service($service, $revision);
        return self::CLOSED + ['available' => true, 'service_id' => $row->id, 'service_revision' => $row->revision,
            'members' => Member::query()->where('service_id', $service)->orderBy('priority')->orderBy('id')->get()->toArray(),
            'credentials' => MachineDnsCredential::query()->whereIn('provider', ['cloudflare', 'aliyun'])->orderBy('id')
                ->limit(100)->get(['id', 'name', 'provider', 'revision'])->toArray(),
            'sets' => DnsSet::query()->where('service_id', $service)->orderBy('id')->get()->map(fn ($set) => $this->present($set))->all(),
            'propagation_available' => true, 'scenarios' => MachineFailoverDnsSimulation::SCENARIOS];
    }

    public function present(DnsSet $set): array
    {
        return $set->toArray() + ['slots' => Slot::query()->where('set_id', $set->id)->orderBy('member_id')->orderBy('line')->orderBy('type')->get()->toArray(),
            'latest_run' => Run::query()->where('set_id', $set->id)->latest('created_at')->latest('id')->first()?->only(['id', 'status', 'reason', 'scenario', 'created_at'])];
    }

    public function save(array $input, int $admin): array
    {
        $this->registry->actor($admin); $this->storage();
        if (array_diff(array_keys($input), ['id', 'expected_revision', 'service_id', 'service_revision', 'credential_id', 'credential_revision',
            'zone', 'zone_name', 'hostname', 'ttl', 'members'])) throw new CloudException('invalid_dns_set');
        foreach (['service_id', 'service_revision', 'credential_id', 'credential_revision'] as $key)
            if (!is_int($input[$key] ?? null) || $input[$key] < 1) throw new CloudException('invalid_dns_set');
        if (isset($input['id']) && (!is_int($input['id']) || $input['id'] < 1 || !is_int($input['expected_revision'] ?? null))) throw new CloudException('config_changed');
        if (!isset($input['id']) && isset($input['expected_revision'])) throw new CloudException('invalid_dns_set');
        $hostname = $this->domain($input['hostname'] ?? null); $zoneName = $this->domain($input['zone_name'] ?? null);
        if ($hostname !== $zoneName && !str_ends_with($hostname, '.' . $zoneName)) throw new CloudException('invalid_dns_set');
        return $this->registry->locked(fn () => Cache::lock('machine-dns:hostname:' . hash('sha256', $hostname), 120)->block(2,
            fn () => Cache::lock('machine-dns:credential:' . $input['credential_id'], 120)->block(2,
                fn () => DB::transaction(function () use ($input, $admin, $hostname, $zoneName) {
                    $this->registry->actor($admin); $service = $this->service($input['service_id'], $input['service_revision']); $this->idle($service->id);
                    $credential = MachineDnsCredential::query()->find($input['credential_id']);
                    if (!$credential || $credential->revision !== $input['credential_revision'] || !in_array($credential->provider, ['cloudflare', 'aliyun'], true))
                        throw new CloudException('dns_credential_changed');
                    $zone = $credential->provider === 'aliyun' ? $this->domain($input['zone'] ?? null) : ($input['zone'] ?? null);
                    if (!is_string($zone) || ($credential->provider === 'cloudflare' ? !preg_match('/^[a-f0-9]{32}$/D', $zone) : $zone !== $zoneName)
                        || !is_int($input['ttl'] ?? null) || $input['ttl'] < ($credential->provider === 'cloudflare' ? 60 : 600) || $input['ttl'] > 86400)
                        throw new CloudException('invalid_dns_set');
                    $set = isset($input['id']) ? DnsSet::query()->findOrFail($input['id']) : new DnsSet();
                    if ($set->exists && ($set->service_id !== $service->id || $set->revision !== $input['expected_revision'])) throw new CloudException('config_changed');
                    if (!$set->exists && DnsSet::query()->where('service_id', $service->id)->count() >= 5) throw new CloudException('dns_set_limit');
                    $values = ['credential_id' => $credential->id, 'credential_revision' => $credential->revision, 'provider' => $credential->provider,
                        'zone' => $zone, 'zone_name' => $zoneName, 'hostname' => $hostname, 'ttl' => $input['ttl']];
                    if ($set->exists && Run::query()->where('set_id', $set->id)->exists()) foreach (['credential_id', 'provider', 'zone', 'zone_name', 'hostname'] as $key)
                        if ($set->{$key} !== $values[$key]) throw new CloudException('dns_set_identity_locked');
                    $this->conflict($hostname, $set->exists ? $set->id : null);
                    $members = $input['members'] ?? null;
                    if (!is_array($members) || !array_is_list($members) || !$members || count($members) > 10) throw new CloudException('invalid_dns_members');
                    $seen = []; $slots = [];
                    foreach ($members as $row) {
                        if (!is_array($row) || array_diff(array_keys($row), ['member_id', 'types', 'lines']) || !is_int($row['member_id'] ?? null)
                            || isset($seen[$row['member_id']]) || !Member::query()->where('service_id', $service->id)->whereKey($row['member_id'])->exists()) throw new CloudException('invalid_dns_members');
                        $seen[$row['member_id']] = true;
                        foreach (['types' => ['A', 'AAAA'], 'lines' => $credential->provider === 'cloudflare' ? ['default'] : MachineDnsRecordSetProvider::LINES] as $key => $allowed) {
                            if (!is_array($row[$key] ?? null) || !array_is_list($row[$key]) || !$row[$key] || count(array_unique($row[$key], SORT_REGULAR)) !== count($row[$key]))
                                throw new CloudException('invalid_dns_members');
                            foreach ($row[$key] as $value) if (!is_string($value) || !in_array($value, $allowed, true)) throw new CloudException('invalid_dns_members');
                        }
                        foreach ($row['lines'] as $line) foreach ($row['types'] as $type) $slots[] = ['member_id' => $row['member_id'], 'line' => $line, 'type' => $type];
                    }
                    $set->fill($values + ['service_id' => $service->id, 'scope' => hash('sha256', $hostname), 'updated_by' => $admin,
                        'revision' => $set->exists ? $set->revision + 1 : 1])->save();
                    $keep = [];
                    foreach ($slots as $slot) {
                        $row = Slot::query()->firstOrCreate(['set_id' => $set->id] + $slot, ['marker' => 'keli-set-' . bin2hex(random_bytes(24))]); $keep[] = $row->id;
                    }
                    Slot::query()->where('set_id', $set->id)->whereNotIn('id', $keep)->delete();
                    $service->fill(['revision' => $service->revision + 1, 'updated_by' => $admin])->save();
                    return $this->overview($service->id, $service->revision, $admin);
                }))));
    }

    public function drop(int $id, int $revision, int $service, int $serviceRevision, int $admin): void
    {
        $this->registry->actor($admin); $this->storage();
        $this->registry->locked(fn () => DB::transaction(function () use ($id, $revision, $service, $serviceRevision, $admin) {
            $this->registry->actor($admin); $parent = $this->service($service, $serviceRevision); $this->idle($service);
            $set = DnsSet::query()->findOrFail($id);
            if ($set->service_id !== $service || $set->revision !== $revision) throw new CloudException('config_changed');
            if (Run::query()->where('set_id', $id)->exists()) throw new CloudException('dns_set_audit_retained');
            Slot::query()->where('set_id', $id)->delete(); $set->delete();
            $parent->fill(['revision' => $parent->revision + 1, 'updated_by' => $admin])->save();
        }));
    }

    public function snapshot(int $id, int $serviceRevision, int $revision): array
    {
        $this->storage(); $set = DnsSet::query()->findOrFail($id); $service = $this->service($set->service_id, $serviceRevision);
        if ($set->revision !== $revision) throw new CloudException('config_changed');
        $credential = MachineDnsCredential::query()->find($set->credential_id);
        if (!$credential || $credential->revision !== $set->credential_revision || $credential->provider !== $set->provider) throw new CloudException('dns_credential_changed');
        $members = Member::query()->where('service_id', $set->service_id)->orderBy('id')->get();
        foreach ($members as $member) if (Policy::query()->whereKey($member->policy_id)->value('revision') !== $member->policy_revision) throw new CloudException('member_policy_changed');
        $slots = Slot::query()->where('set_id', $id)->orderBy('id')->get();
        if ($slots->isEmpty() || $slots->count() > 120 || $slots->whereNotIn('member_id', $members->pluck('id'))->isNotEmpty()) throw new CloudException('invalid_dns_members');
        return ['service_context' => $this->registry->snapshot($service->id, $service->revision),
            'set' => $set->only(['id', 'revision', 'service_id', 'credential_id', 'credential_revision', 'provider', 'zone', 'zone_name', 'hostname', 'ttl']),
            'members' => $members->map(fn ($row) => $row->only(['id', 'policy_id', 'policy_revision', 'name', 'enabled']))->all(),
            'slots' => $slots->map(fn ($row) => $row->only(['id', 'member_id', 'type', 'line', 'marker']))->all()];
    }

    public function service(int $id, int $revision): Service
    {
        $row = Service::query()->findOrFail($id);
        if ($row->revision !== $revision) throw new CloudException('config_changed');
        return $row;
    }
    private function idle(int $service): void
    {
        foreach (['run', 'execution', 'dns_run'] as $table) if (Schema::hasTable('v2_machine_failover_' . $table)
            && DB::table('v2_machine_failover_' . $table)->where('service_id', $service)->where('status', 'running')->exists()) throw new CloudException('run_active');
    }
    private function conflict(string $hostname, ?int $except): void
    {
        if (DnsSet::query()->where('hostname', $hostname)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) throw new CloudException('dns_record_conflict');
        foreach (['v2_machine_dns_binding' => 'hostname', 'v2_machine_owned_dns' => 'hostname', 'v2_machine_failover_policy' => 'dns_hostname'] as $table => $field)
            if (Schema::hasTable($table) && DB::table($table)->where($field, $hostname)->exists()) throw new CloudException('dns_record_conflict');
    }
    private function domain(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 254) throw new CloudException('invalid_dns_set');
        $value = strtolower(rtrim(trim($value), '.'));
        if (!$value || !filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) throw new CloudException('invalid_dns_set');
        return $value;
    }
    private function storage(): void { if (!$this->available()) throw new CloudException('migration_required'); }
}
