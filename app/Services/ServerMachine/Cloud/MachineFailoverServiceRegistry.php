<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverRun as Run;
use App\Models\MachineFailoverService as Service;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineFailoverServiceRegistry
{
    public function available(): bool
    {
        foreach (['service', 'member', 'run', 'run_member', 'action'] as $table) if (!Schema::hasTable('v2_machine_failover_' . $table)) return false;
        return app(MachineFailoverPolicyService::class)->available();
    }
    public function actor(int $admin): void
    {
        $row = User::query()->find($admin, ['id', 'is_admin', 'banned']);
        if (!$row || !$row->is_admin || $row->banned) throw new CloudException('permission_revoked');
    }
    public function locked(callable $work): mixed { return Cache::lock('machine-failover:write', 120)->block(2, $work); }
    public function overview(): array
    {
        $result = ['available' => $this->available(), 'simulation_only' => true, 'execution_enabled' => false, 'dns_cutover' => false,
            'services' => [], 'policies' => [], 'scenarios' => MachineFailoverSimulationDriver::SCENARIOS];
        $result['shadow_available'] = app(MachineFailoverShadow::class)->available();
        $result['budget_available'] = app(MachineFailoverBudget::class)->available();
        $result['dns_sets_available'] = app(MachineFailoverDnsRegistry::class)->available();
        if (!$result['available']) return $result;
        $members = Member::query()->get()->keyBy('policy_id');
        $result['policies'] = Policy::query()->orderBy('id')->limit(100)->get()->map(fn ($policy) => $policy->only(['id', 'name', 'revision', 'machine_id',
            'region', 'size', 'image', 'dns_hostname', 'monthly_limit_usd', 'isolation_confirmed']) + ['provider' => app(MachineFailoverCredentials::class)->provider($policy),
                'used_service_id' => $members->get($policy->id)?->service_id])->all();
        $result['services'] = Service::query()->orderBy('id')->limit(50)->get()->map(fn ($service) => $this->present($service))->all();
        return $result;
    }
    public function present(Service $service): array
    {
        $members = Member::query()->where('service_id', $service->id)->orderBy('priority')->orderBy('id')->get();
        $policies = Policy::query()->whereIn('id', $members->pluck('policy_id'))->get()->keyBy('id');
        $run = Run::query()->where('service_id', $service->id)->latest('created_at')->latest('id')->first();
        return $service->toArray() + ['simulation_only' => true, 'execution_enabled' => false, 'dns_cutover' => false,
            'dns_sets_count' => Schema::hasTable('v2_machine_failover_dns_set') ? \App\Models\MachineFailoverDnsSet::query()->where('service_id', $service->id)->count() : 0,
            'members' => $members->map(fn ($member) => $member->toArray() + ['policy_name' => $policies->get($member->policy_id)?->name,
                'policy_current' => $policies->get($member->policy_id)?->revision === $member->policy_revision])->all(),
            'latest_run' => $run?->only(['id', 'status', 'reason', 'service_revision', 'created_at', 'finished_at'])];
    }
    public function save(array $input, int $admin): array
    {
        $this->actor($admin);
        if (!$this->available()) throw new CloudException('migration_required');
        if (array_diff(array_keys($input), ['id', 'expected_revision', 'name', 'max_parallel', 'attempt_limit', 'monthly_cap_usd', 'members'])) throw new CloudException('invalid_service');
        $name = $this->name($input['name'] ?? null);
        foreach (['max_parallel' => [1, 2], 'attempt_limit' => [1, 20]] as $key => [$min, $max]) {
            if (!is_int($input[$key] ?? null) || $input[$key] < $min || $input[$key] > $max) throw new CloudException('invalid_service');
        }
        $cap = $input['monthly_cap_usd'] ?? 0;
        if (!is_numeric($cap) || !is_finite((float) $cap) || $cap < 0 || $cap > 10000 || round((float) $cap, 2) !== (float) $cap) throw new CloudException('invalid_budget');
        if (!is_array($input['members'] ?? null) || !array_is_list($input['members']) || count($input['members']) < 1 || count($input['members']) > 10) throw new CloudException('invalid_members');
        if (isset($input['id']) && (!is_int($input['id']) || $input['id'] < 1 || !is_int($input['expected_revision'] ?? null))) throw new CloudException('config_changed');
        return $this->locked(fn () => DB::transaction(function () use ($input, $admin, $name, $cap) {
            $this->actor($admin); $service = isset($input['id']) ? Service::query()->findOrFail($input['id']) : new Service();
            if ($service->exists && $service->revision !== $input['expected_revision']) throw new CloudException('config_changed');
            if (!$service->exists && Service::query()->count() >= 50) throw new CloudException('limit_reached');
            if ($service->exists && Run::query()->where('service_id', $service->id)->where('status', 'running')->exists()) throw new CloudException('run_active');
            if ($service->exists && $this->nativeHistory($service->id, true)) throw new CloudException('run_active');
            $seen = []; $machines = []; $values = [];
            foreach ($input['members'] as $row) {
                if (!is_array($row) || array_diff(array_keys($row), ['policy_id', 'policy_revision', 'name', 'priority', 'enabled'])
                    || !is_int($row['policy_id'] ?? null) || !is_int($row['policy_revision'] ?? null) || !is_bool($row['enabled'] ?? null)
                    || !is_int($row['priority'] ?? null) || $row['priority'] < 1 || $row['priority'] > 100) throw new CloudException('invalid_members');
                $policy = Policy::query()->find($row['policy_id']);
                if (!$policy || $policy->revision !== $row['policy_revision']) throw new CloudException('member_policy_changed');
                if (isset($seen[$policy->id]) || isset($machines[$policy->machine_id])) throw new CloudException('duplicate_member');
                if (Member::query()->where(fn ($query) => $query->where('policy_id', $policy->id)->orWhere('machine_id', $policy->machine_id))
                    ->when($service->exists, fn ($query) => $query->where('service_id', '!=', $service->id))->exists()) throw new CloudException('member_in_use');
                $seen[$policy->id] = true; $machines[$policy->machine_id] = true;
                $values[] = $row + ['machine_id' => $policy->machine_id];
                $values[array_key_last($values)]['name'] = $this->name($row['name'] ?? null);
            }
            $removed = $service->exists ? Member::query()->where('service_id', $service->id)->whereNotIn('policy_id', array_keys($seen)) : null;
            if ($removed && Schema::hasTable('v2_machine_failover_dns_slot') && \App\Models\MachineFailoverDnsSlot::query()
                ->whereIn('member_id', (clone $removed)->select('id'))->exists()) throw new CloudException('dns_member_in_use');
            if ($removed && (clone $removed)->exists() && (Run::query()->where('service_id', $service->id)->exists() || $this->nativeHistory($service->id))) throw new CloudException('member_audit_retained');
            $removed?->delete();
            $service->fill(['name' => $name, 'max_parallel' => $input['max_parallel'], 'attempt_limit' => $input['attempt_limit'],
                'monthly_cap_usd' => array_key_exists('monthly_cap_usd', $input) ? (float) $cap : ($service->monthly_cap_usd ?? 0),
                'revision' => $service->exists ? $service->revision + 1 : 1, 'updated_by' => $admin])->save();
            foreach ($values as $row) Member::query()->updateOrCreate(['service_id' => $service->id, 'policy_id' => $row['policy_id']], $row);
            return $this->present($service->fresh());
        }));
    }
    public function drop(int $id, int $revision, int $admin): void
    {
        $this->actor($admin); if (!$this->available()) throw new CloudException('migration_required');
        $this->locked(fn () => DB::transaction(function () use ($id, $revision, $admin) {
            $this->actor($admin); $service = Service::query()->findOrFail($id);
            if ($service->revision !== $revision) throw new CloudException('config_changed');
            if (Schema::hasTable('v2_machine_failover_dns_set') && \App\Models\MachineFailoverDnsSet::query()->where('service_id', $id)->exists()) throw new CloudException('dns_set_in_use');
            if (Run::query()->where('service_id', $id)->exists() || $this->nativeHistory($id)) throw new CloudException('service_in_use');
            Member::query()->where('service_id', $id)->delete(); $service->delete();
        }));
    }

    public function snapshot(int $id, int $revision): array
    {
        $service = Service::query()->findOrFail($id);
        if ($service->revision !== $revision) throw new CloudException('config_changed');
        $members = Member::query()->where('service_id', $id)->where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        if ($members->isEmpty()) throw new CloudException('enabled_member_required');
        $result = ['service' => $service->only(['id', 'revision', 'max_parallel', 'attempt_limit', 'monthly_cap_usd']), 'members' => []];
        foreach ($members as $member) {
            $policy = Policy::query()->find($member->policy_id); $machine = $policy ? ServerMachine::query()->find($policy->machine_id) : null;
            if (!$policy || $policy->revision !== $member->policy_revision || $policy->machine_id !== $member->machine_id) throw new CloudException('member_policy_changed');
            if (!$machine || !$machine->is_active || !hash_equals($policy->machine_authority, hash('sha256', (string) $machine->token))) throw new CloudException('source_changed');
            $pool = app(MachineFailoverCredentials::class); $provider = $pool->provider($policy);
            if ($pool->grouped($policy)) $credential = $pool->pool($policy->credential_group_id, $provider)['fingerprint'];
            else {
                $account = MachineCloudAccount::query()->find($policy->account_id);
                if (!$account || $account->revision !== $policy->account_revision) throw new CloudException('account_changed');
                $credential = hash('sha256', $account->id . ':' . $account->revision);
            }
            $dns = $policy->dns_credential_id ? MachineDnsCredential::query()->find($policy->dns_credential_id) : null;
            if ($dns && $dns->revision !== $policy->dns_credential_revision) throw new CloudException('dns_credential_changed');
            $projection = $member->only(['id', 'name', 'policy_id', 'policy_revision', 'machine_id', 'priority']);
            $projection['policy'] = $policy->only(['region', 'size', 'image', 'slot', 'monthly_limit_usd', 'daily_limit', 'failure_threshold', 'stale_after_seconds',
                'isolation_confirmed', 'dns_hostname', 'dns_credential_id', 'dns_credential_revision']);
            $projection += ['provider' => $provider, 'credential_fingerprint' => $credential, 'dns_exists' => $dns !== null,
                'dns_fingerprint' => $dns ? hash('sha256', $dns->id . ':' . $dns->revision . ':' . $dns->provider) : null];
            $result['members'][] = $projection;
        }
        if (Schema::hasTable('v2_machine_failover_dns_set') && \App\Models\MachineFailoverDnsSet::query()->where('service_id', $id)->exists()) {
            if (!Schema::hasTable('v2_machine_failover_dns_slot') || !Schema::hasTable('v2_machine_failover_dns_run')) throw new CloudException('migration_required');
            $sets = \App\Models\MachineFailoverDnsSet::query()->where('service_id', $id)->orderBy('id')->get();
            $result['dns_sets'] = $sets->map(fn ($set) => $set->only(['id', 'revision', 'credential_id', 'credential_revision', 'provider', 'zone', 'zone_name', 'hostname', 'ttl'])
                + ['slots' => \App\Models\MachineFailoverDnsSlot::query()->where('set_id', $set->id)->orderBy('id')->get()
                    ->map(fn ($slot) => $slot->only(['id', 'member_id', 'type', 'line', 'marker']))->all()])->all();
        }
        return $result;
    }
    public function fingerprint(array $snapshot): string { return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)); }
    private function nativeHistory(int $service, bool $active = false): bool
    {
        if ($active && Schema::hasTable('v2_machine_failover_dns_run') && \App\Models\MachineFailoverDnsRun::query()
            ->where('service_id', $service)->where('status', 'running')->exists()) return true;
        return Schema::hasTable('v2_machine_failover_execution') && \App\Models\MachineFailoverExecution::query()->where('service_id', $service)
            ->when($active, fn ($query) => $query->where('status', 'running'))->exists();
    }
    private function name(mixed $name): string
    {
        if (!is_string($name) || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new CloudException('invalid_name');
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if (!$name || mb_strlen($name) > 80) throw new CloudException('invalid_name');
        return $name;
    }
}
