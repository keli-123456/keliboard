<?php

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\Node\NodeMachineBinding;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineFailoverPolicyService
{
    public function __construct(private MachineAddressInventory $addresses, private NodeInstanceRegistry $nodes,
        private ProvisionBootstrap $bootstrap) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_failover_policy') && Schema::hasTable('v2_machine_cloud_account')
            && Schema::hasTable('v2_server_machine');
    }

    public function overview(): array
    {
        $result = ['available' => $this->available(), 'execution_enabled' => false, 'supported_providers' => ['linode'],
            'options' => ['regions' => config('machine_provision.supported_regions', []),
                'sizes' => config('machine_provision.supported_sizes', []), 'images' => config('machine_provision.supported_images', [])],
            'accounts' => [], 'machines' => [], 'dns_credentials' => [], 'policies' => []];
        if (!$result['available']) return $result;
        $pool = app(MachineFailoverCredentials::class);
        $result['provider_groups_available'] = $pool->available();
        $result['provisioning_providers'] = MachineProvisionProviders::SUPPORTED;
        $result['credential_groups'] = [];
        if ($pool->available()) {
            $result['supported_providers'] = ['linode', 'digitalocean', 'vultr'];
            $result['credential_groups'] = array_map(function ($group) {
                $counts = Account::query()->where('group_id', $group['id'])->selectRaw('provider, COUNT(*) AS total')->groupBy('provider')->pluck('total', 'provider');
                return ['id' => $group['id'], 'name' => $group['name'], 'provider_counts' => $counts];
            }, app(MachineCloudGroupService::class)->overview());
        }
        $result['accounts'] = Account::query()->orderBy('id')->get(array_merge(['id', 'name', 'provider', 'revision'], app(MachineCloudGroupService::class)->available() ? ['group_id'] : []));
        $owners = Schema::hasTable('v2_machine_owned_instance') ? Policy::query()->whereIn('id', \App\Models\MachineOwnedInstance::query()->select('policy_id'))->get()->groupBy('machine_id') : collect();
        $result['machines'] = ServerMachine::query()->orderBy('id')->limit(500)->get(['id', 'name', 'is_active', 'token'])->map(function ($machine) use ($owners) {
            $used = $this->machineInUse($machine);
            return ['id' => $machine->id, 'name' => $machine->name, 'is_active' => (bool) $machine->is_active,
                'node_count' => $this->machineNodes($machine->id)->count(), 'candidate_available' => !$used,
                'blocked_reason' => $used ? 'machine_in_use' : null,
                'owned_policy_ids' => $owners->get($machine->id, collect())->filter(fn ($policy) => app(MachineInstanceOwnership::class)->canUse($policy))->pluck('id')->values()];
        });
        if (Schema::hasTable('v2_machine_dns_credential')) {
            $result['dns_credentials'] = MachineDnsCredential::query()->orderBy('id')->get(['id', 'name', 'provider', 'revision']);
        }
        $result['policies'] = Policy::query()->orderByDesc('id')->limit(100)->get()->map(fn ($policy) => $this->present($policy));
        $result['replacements'] = app(MachineReplacementService::class)->overview();
        return $result;
    }

    public function save(array $input, int $admin): array
    {
        $this->actor($admin);
        $this->requireStorage();
        $values = $this->normalize($input);
        $locked = function () use ($input, $values, $admin) {
            $hostname = $values['dns_hostname'];
            $work = function () use ($input, $values, $admin) {
                $credentialWork = fn () => DB::transaction(function () use ($input, $values, $admin) {
                    $this->actor($admin);
                    $policy = isset($input['id']) ? Policy::query()->findOrFail($input['id']) : new Policy();
                    if ($policy->exists && $policy->revision !== $input['expected_revision']) throw new CloudException('config_changed');
                    if ($policy->exists && Schema::hasTable('v2_machine_owned_instance') && \App\Models\MachineOwnedInstance::query()->where('policy_id', $policy->id)->exists()) {
                        foreach (array_merge(['machine_id', 'account_id', 'region', 'size', 'image', 'dns_credential_id', 'dns_hostname'], isset($values['credential_group_id']) ? ['credential_group_id', 'provider'] : []) as $field) {
                            if ($policy->{$field} !== $values[$field]) throw new CloudException('owned_identity_locked');
                        }
                    }
                    if (!$policy->exists && Policy::query()->count() >= 100) throw new CloudException('limit_reached');
                    $grouped = isset($values['credential_group_id']);
                    $catalog = null;
                    if ($grouped) {
                        app(MachineFailoverCredentials::class)->pool($values['credential_group_id'], $values['provider']);
                        if (Policy::query()->where('machine_id', $values['machine_id'])->when($policy->exists, fn ($query) => $query->where('id', '!=', $policy->id))->exists()) throw new CloudException('machine_in_use');
                        $same = $policy->exists;
                        foreach (['provider', 'credential_group_id', 'region', 'size', 'image', 'catalog_fingerprint'] as $field) $same = $same && $policy->{$field} === $values[$field];
                        if (!$same) $catalog = app(MachineCloudCatalog::class)->validate($values);
                    }
                    $account = $grouped ? null : Account::query()->find($values['account_id'], ['id', 'name', 'provider', 'revision']);
                    $machine = ServerMachine::query()->find($values['machine_id']);
                    if (!$grouped && !$account) throw new CloudException('credentials_required');
                    if (!$grouped && $account->provider !== 'linode') throw new CloudException('unsupported_provider');
                    if (!$machine || !$machine->is_active) throw new CloudException('invalid_policy');
                    if ($this->machineInUse($machine, $policy->exists ? $policy : null)) throw new CloudException('machine_in_use');
                    if ($this->dnsConflict($values['dns_hostname'], $policy->exists ? $policy->id : null)) throw new CloudException('dns_record_conflict');
                    $credential = $values['dns_credential_id'] === null ? null : $this->credential($values['dns_credential_id']);
                    $policy->fill($values + ['machine_authority' => hash('sha256', (string) $machine->token),
                        'account_revision' => $account?->revision, 'dns_credential_revision' => $credential?->revision,
                        'dns_scope' => $values['dns_hostname'] === '' ? null : hash('sha256', $values['dns_hostname']),
                        'updated_by' => $admin, 'revision' => $policy->exists ? $policy->revision + 1 : 1]);
                    if ($catalog !== null) $policy->catalog_checked_at = $catalog['checked_at'];
                    if (!$policy->exists) $policy->slot = 'independent:' . Str::uuid();
                    $policy->save();
                    return $this->present($policy->fresh());
                });
                return $values['dns_credential_id'] === null ? $credentialWork()
                    : $this->lock('machine-dns:credential:' . $values['dns_credential_id'], $credentialWork);
            };
            return $hostname === '' ? $work() : $this->lock('machine-dns:hostname:' . hash('sha256', $hostname), $work);
        };
        return $this->lock('machine-failover:write', fn () => isset($values['credential_group_id'])
            ? app(MachineCloudGroupService::class)->withGroup($values['credential_group_id'], $locked)
            : $this->lock('machine-cloud:account:' . $values['account_id'], $locked));
    }

    public function drop(int $id, int $revision, int $admin): void
    {
        $this->actor($admin);
        $this->requireStorage();
        $this->lock('machine-failover:write', function () use ($id, $revision) {
            DB::transaction(function () use ($id, $revision) {
                $policy = Policy::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($policy->revision !== $revision) throw new CloudException('config_changed');
                if (Schema::hasTable('v2_machine_failover_member') && \App\Models\MachineFailoverMember::query()->where('policy_id', $id)->exists()) throw new CloudException('policy_in_use');
                if (Schema::hasTable('v2_machine_owned_instance') && \App\Models\MachineOwnedInstance::query()->where('policy_id', $policy->id)->exists()) throw new CloudException('policy_in_use');
                $policy->delete();
            });
        });
    }

    public function rehearse(int $id, int $revision, string $scenario): array
    {
        $this->requireStorage();
        if (!in_array($scenario, ['current', 'initial_setup', 'agent_stale', 'ipv4_unreachable'], true)) throw new CloudException('invalid_policy');
        // This read never calls a provider, changes telemetry, or dispatches jobs.
        return DB::transaction(function () use ($id, $revision, $scenario) {
            $policy = Policy::query()->findOrFail($id);
            if ($policy->revision !== $revision) throw new CloudException('config_changed');
            $now = now()->getTimestamp();
            $machine = ServerMachine::query()->find($policy->machine_id);
            $checks = $this->localChecks($policy);
            $nodes = $machine ? $this->machineNodes($machine->id) : collect();
            $rows = $machine ? ($this->addresses->snapshots([$machine])[$machine->id] ?? []) : [];
            $fresh = array_filter($rows, fn ($row) => ($row['at'] ?? 0) >= $now - $policy->stale_after_seconds && ($row['at'] ?? 0) <= $now + 30);
            $probes = $machine ? $this->addresses->probes($fresh, $now) : [];
            $states = array_column($probes, 'status');
            $probe = in_array('reachable', $states, true) ? 'reachable'
                : ($states && count($states) === count($fresh) && !array_diff($states, ['suspected_blocked']) ? 'unreachable' : 'unknown');
            $agent = !$rows ? 'missing' : ($fresh ? 'fresh' : 'stale');
            $trigger = $scenario !== 'current' ? 'simulated_match' : ($agent === 'fresh' && $probe === 'reachable' ? 'healthy'
                : ($rows ? 'insufficient_samples' : 'not_evaluated'));
            $observed = null;
            $observer = app(MachineFailoverObserver::class);
            if ($scenario === 'current' && $observer->available()) {
                $history = $observer->summary($policy, $now);
                $observed = $history['observed_failures'];
                if ($history['threshold_reached']) $trigger = 'threshold_reached';
                $codes = $history['plan']['blockers'] ?? [];
                if (!$history['collecting'] || !$history['evidence_current'] || $history['reason'] === 'healthy_baseline_required') $codes[] = $history['reason'];
                foreach (array_diff(array_unique($codes), array_column($checks, 'code')) as $code) $checks[] = ['code' => $code, 'status' => 'blocked'];
            }
            return ['policy_id' => $policy->id, 'revision' => $policy->revision, 'checked_at' => $now,
                'scenario' => $scenario, 'simulated' => $scenario !== 'current', 'executed' => false, 'execution_enabled' => false,
                'provider_checked' => false, 'pricing_checked' => false, 'external_ownership_verified' => false,
                'current' => ['agent' => $agent, 'node_count' => $nodes->count(), 'ipv4_probe' => $probe, 'active_instances' => count($fresh)],
                'trigger' => ['state' => $trigger, 'required_failures' => $policy->failure_threshold, 'observed_failures' => $observed],
                'checks' => $checks, 'steps' => array_map(fn ($action) => ['action' => $action, 'status' => 'planned'], [
                    'create_new_vm', 'install_kelinode_rs', 'wait_exact_instance_reports', 'verify_ipv4_targets', 'verify_tcp_udp',
                    'bind_new_instance', 'switch_dedicated_dns', 'keep_previous_vm'])];
        });
    }

    public function localChecks(Policy $policy, ?\App\Models\MachineProvisionTask $task = null): array
    {
        $credentials = app(MachineFailoverCredentials::class); $grouped = $credentials->grouped($policy);
        $account = Account::query()->find($policy->account_id, ['id', 'provider', 'revision']);
        $machine = ServerMachine::query()->find($policy->machine_id);
        $checks = [];
        $add = function (string $code, string $status) use (&$checks): void { $checks[] = compact('code', 'status'); };
        $add('execution_disabled', 'blocked');
        if (!$policy->isolation_confirmed) $add('isolation_confirmation_required', 'blocked');
        if (!$machine) $add('machine_missing', 'blocked');
        elseif (!$machine->is_active) $add('machine_inactive', 'blocked');
        elseif (!hash_equals($policy->machine_authority, hash('sha256', (string) $machine->token)) || $this->machineInUse($machine, $policy)) $add('machine_in_use', 'blocked');
        if (!$machine || $this->machineNodes($machine->id)->isEmpty()) $add('node_group_empty', 'blocked');
        if ($grouped) {
            try {
                $choice = $credentials->context($policy, $task); $account = Account::query()->find($choice['account_id']);
                $add('credential_group_ready', 'passed');
            } catch (CloudException $error) { $add($error->getMessage(), 'blocked'); }
        }
        if (!$account && !$grouped) $add('account_missing', 'blocked');
        elseif ($account && !in_array($account->provider, MachineProvisionProviders::SUPPORTED, true)) $add('provider_creation_unsupported', 'blocked');
        elseif ($account && !$grouped && $account->revision !== $policy->account_revision) $add('account_changed', 'blocked');
        try { $this->bootstrap->configuredOrigin(); } catch (\Throwable) { $add('panel_origin_invalid', 'blocked'); }
        $supported = MachineProvisionProviders::templateSupported($credentials->provider($policy) ?? '', $policy->region, $policy->size, $policy->image);
        $add($supported ? 'template_supported' : 'template_unsupported', $supported ? 'passed' : 'blocked');
        if ($grouped && ($task === null || $task->dispatched_at === null)) {
            try { app(MachineCloudCatalog::class)->validate($policy->toArray()); $add('provider_template_valid', 'passed'); }
            catch (CloudException $error) { $add($error->getMessage(), 'blocked'); }
        }
        $add($policy->monthly_limit_usd > 0 ? 'budget_configured' : 'budget_required', $policy->monthly_limit_usd > 0 ? 'passed' : 'blocked');
        if (!$policy->dns_credential_id || $policy->dns_hostname === '') $add('dns_not_configured', 'blocked');
        else {
            $credential = Schema::hasTable('v2_machine_dns_credential') ? MachineDnsCredential::query()->find($policy->dns_credential_id, ['id', 'revision', 'provider']) : null;
            if (!$credential) $add('dns_credential_missing', 'blocked');
            elseif ($credential->revision !== $policy->dns_credential_revision) $add('dns_credential_changed', 'blocked');
            elseif ($credential->provider !== 'cloudflare') $add('owned_dns_unsupported', 'blocked');
            if ($this->dnsConflict($policy->dns_hostname, $policy->id)) $add('dns_record_conflict', 'blocked');
        }
        $add('provider_not_checked', 'unverified');
        $add('external_ownership_unverified', 'unverified');
        $add('forwarding_not_verified', 'unverified');
        return $checks;
    }

    private function normalize(array $input): array
    {
        $allowed = ['id', 'expected_revision', 'name', 'machine_id', 'account_id', 'region', 'size', 'image',
            'failure_threshold', 'stale_after_seconds', 'cooldown_seconds', 'monthly_limit_usd', 'daily_limit',
            'dns_credential_id', 'dns_hostname', 'isolation_confirmed', 'provider', 'credential_group_id', 'catalog_fingerprint'];
        if (array_diff(array_keys($input), $allowed)) throw new CloudException('invalid_policy');
        foreach (['id', 'expected_revision'] as $key) {
            if (isset($input[$key]) && (!is_int($input[$key]) || $input[$key] < 1)) throw new CloudException('invalid_policy');
        }
        if (isset($input['id']) && !isset($input['expected_revision'])) throw new CloudException('config_changed');
        $name = $input['name'] ?? null;
        if (!is_string($name) || preg_match('/[\x00-\x1F\x7F]/', $name)) throw new CloudException('invalid_name');
        $name = preg_replace('/\s+/u', ' ', trim($name));
        if ($name === null || $name === '' || mb_strlen($name) > 80) throw new CloudException('invalid_name');
        $grouped = array_key_exists('credential_group_id', $input);
        if ($grouped && (!app(MachineFailoverCredentials::class)->available() || !is_int($input['credential_group_id']) || $input['credential_group_id'] < 1)) throw new CloudException('group_missing');
        if ($grouped && (array_key_exists('account_id', $input) || !in_array($input['provider'] ?? null, ['linode', 'digitalocean', 'vultr'], true))) throw new CloudException('invalid_policy');
        if ($grouped && (!is_string($input['catalog_fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $input['catalog_fingerprint']))) throw new CloudException('catalog_expired');
        if (!$grouped && (isset($input['provider']) || isset($input['catalog_fingerprint']))) throw new CloudException('invalid_policy');
        $numeric = ['machine_id' => [1, PHP_INT_MAX], 'failure_threshold' => [2, 10],
            'stale_after_seconds' => [30, 1800], 'cooldown_seconds' => [300, 86400], 'daily_limit' => [1, 2]];
        if (!$grouped) $numeric['account_id'] = [1, PHP_INT_MAX];
        foreach ($numeric as $key => [$min, $max]) {
            if (!is_int($input[$key] ?? null) || $input[$key] < $min || $input[$key] > $max) throw new CloudException('invalid_policy');
        }
        foreach (['region' => '/^[a-z0-9-]{1,60}$/D', 'size' => '/^[a-z0-9-]{1,60}$/D', 'image' => !$grouped || $input['provider'] === 'linode' ? '~^linode/[a-z0-9-]{1,60}$~D' : '/^[a-z0-9-]{1,80}$/D'] as $key => $pattern) {
            if (!is_string($input[$key] ?? null) || !preg_match($pattern, $input[$key])) throw new CloudException('invalid_policy');
        }
        $budget = $input['monthly_limit_usd'] ?? null;
        if (!is_numeric($budget) || !is_finite((float) $budget) || $budget < 0 || $budget > 1000
            || round((float) $budget, 2) !== (float) $budget) throw new CloudException('invalid_budget');
        if (!is_bool($input['isolation_confirmed'] ?? null)) throw new CloudException('invalid_policy');
        $credential = $input['dns_credential_id'] ?? null;
        if ($credential !== null && (!is_int($credential) || $credential < 1)) throw new CloudException('invalid_dns');
        $hostname = $input['dns_hostname'] ?? '';
        if (!is_string($hostname)) throw new CloudException('invalid_dns');
        $hostname = strtolower(trim($hostname));
        if (str_ends_with($hostname, '.')) $hostname = substr($hostname, 0, -1);
        if ($hostname !== '' && (strlen($hostname) > 253 || !str_contains($hostname, '.') || filter_var($hostname, FILTER_VALIDATE_IP)
            || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $hostname))) throw new CloudException('invalid_dns');
        $values = array_intersect_key($input, array_flip(array_diff($allowed, ['id', 'expected_revision'])));
        if ($grouped) $values['account_id'] = null;
        elseif (app(MachineFailoverCredentials::class)->available()) $values += ['provider' => null, 'credential_group_id' => null, 'catalog_fingerprint' => null, 'catalog_checked_at' => null];
        return array_replace($values, ['name' => $name, 'monthly_limit_usd' => (float) $budget,
            'dns_credential_id' => $credential, 'dns_hostname' => $hostname]);
    }

    private function machineNodes(int $id)
    {
        if (!Schema::hasTable('v2_server')) return collect();
        $bindings = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $id)->pluck('server_id') : [];
        return Server::query()->where('enabled', true)->where(fn ($query) => $query->where('machine_id', $id)->orWhereIn('id', $bindings))->get(['id']);
    }

    private function machineInUse(ServerMachine $machine, ?Policy $policy = null): bool
    {
        if ($policy && $policy->machine_id === $machine->id && app(MachineInstanceOwnership::class)->canUse($policy)) return false;
        if (Schema::hasTable('v2_machine_cloud_resource') && MachineCloudResource::query()->where('machine_id', $machine->id)->exists()) return true;
        if (Schema::hasTable('v2_machine_dns_binding') && DB::table('v2_machine_dns_binding')->where('machine_id', $machine->id)->exists()) return true;
        if (($this->addresses->snapshots([$machine])[$machine->id] ?? []) !== []) return true;
        foreach ($this->machineNodes($machine->id) as $node) if ($this->nodes->snapshot($node->id) !== []) return true;
        return false;
    }

    private function dnsConflict(string $hostname, ?int $except): bool
    {
        if ($hostname === '') return false;
        if (Schema::hasTable('v2_machine_failover_dns_set') && DB::table('v2_machine_failover_dns_set')->where('hostname', $hostname)->exists()) return true;
        if (Schema::hasTable('v2_machine_owned_dns') && DB::table('v2_machine_owned_dns')->where('hostname', $hostname)
            ->when($except, fn ($query) => $query->where('policy_id', '!=', $except))->exists()) return true;
        if (Policy::query()->where('dns_hostname', $hostname)->when($except, fn ($query) => $query->where('id', '!=', $except))->exists()) return true;
        return Schema::hasTable('v2_machine_dns_binding') && DB::table('v2_machine_dns_binding')->where('hostname', $hostname)->exists();
    }

    private function credential(int $id): MachineDnsCredential
    {
        $credential = Schema::hasTable('v2_machine_dns_credential') ? MachineDnsCredential::query()->find($id, ['id', 'revision', 'provider']) : null;
        if (!$credential || !in_array($credential->provider, ['aliyun', 'cloudflare'], true)) throw new CloudException('credentials_required');
        return $credential;
    }

    private function present(Policy $policy): array
    {
        $result = $policy->only(['id', 'name', 'revision', 'slot', 'machine_id', 'account_id', 'region', 'size', 'image',
            'failure_threshold', 'stale_after_seconds', 'cooldown_seconds', 'monthly_limit_usd', 'daily_limit',
            'dns_credential_id', 'dns_hostname', 'isolation_confirmed', 'created_at', 'updated_at']);
        $result['machine_name'] = ServerMachine::query()->whereKey($policy->machine_id)->value('name');
        $account = Account::query()->find($policy->account_id, ['name', 'provider']);
        if (app(MachineFailoverCredentials::class)->available()) {
            $result += $policy->only(['credential_group_id', 'catalog_fingerprint', 'catalog_checked_at']);
            $result['credential_group_name'] = $policy->credential_group_id ? \App\Models\MachineCloudGroup::query()->whereKey($policy->credential_group_id)->value('name') : null;
        }
        $result['identity_locked'] = Schema::hasTable('v2_machine_owned_instance') && \App\Models\MachineOwnedInstance::query()->where('policy_id', $policy->id)->exists();
        return $result + ['account_name' => $account?->name, 'provider' => $policy->credential_group_id ? $policy->provider : $account?->provider,
            'status' => 'draft', 'execution_enabled' => false];
    }

    private function actor(int $id): void
    {
        $admin = User::query()->find($id);
        if (!$admin || !$admin->is_admin || $admin->banned) throw new CloudException('permission_revoked');
    }
    private function requireStorage(): void { if (!$this->available()) throw new CloudException('migration_required'); }
    private function lock(string $key, callable $work): mixed { return Cache::lock($key, 60)->block(2, $work); }
}
