<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverBudgetRelease as Release;
use App\Models\MachineFailoverCostSnapshot as Snapshot;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverMember;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverService as Service;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineFailoverBudget
{
    public function __construct(private MachineFailoverServiceRegistry $registry, private LinodeFailoverCostClient $cloud) {}

    public function available(): bool
    {
        foreach (['cost_snapshot', 'budget_release', 'attempt', 'execution', 'execution_member'] as $name) if (!Schema::hasTable('v2_machine_failover_' . $name)) return false;
        return $this->registry->available() && app(MachineCloudCredentialHealth::class)->available();
    }

    private function scope(int $service, int $revision, int $admin): Service
    {
        $this->registry->actor($admin);
        if (!$this->available()) throw new CloudException('migration_required');
        $row = Service::query()->findOrFail($service);
        if ($row->revision !== $revision) throw new CloudException('config_changed');
        return $row;
    }

    public function workspace(int $id, int $revision, int $admin): array
    {
        $service = $this->scope($id, $revision, $admin); $graph = $this->graph($service);
        $cost = $this->balance($service, $graph);
        $runs = Execution::query()->where('service_id', $id)->latest('created_at')->orderByDesc('id')->limit(50)->get();
        return ['service_id' => $id, 'service_revision' => $revision, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false,
            'cloud_writes' => 0, 'budget_only' => true, 'cost' => $cost, 'runs' => $runs->map(function ($run) {
                $members = ExecutionMember::query()->where('execution_id', $run->id)->get();
                $attempts = Attempt::query()->whereIn('execution_member_id', $members->pluck('id'))->get();
                return $run->only(['id', 'status', 'service_revision', 'created_at', 'deadline_at']) + ['members' => $members->count(),
                    'needs_review' => $members->where('phase', 'needs_review')->count(), 'attempts' => $attempts->count(),
                    'reserved_cents' => $attempts->sum('reserved_cents'), 'released_cents' => (int) Release::query()->whereIn('attempt_id', $attempts->pluck('id'))->sum('amount_cents')];
            })->all()];
    }

    public function report(string $id, int $admin): array
    {
        $this->registry->actor($admin); if (!$this->available()) throw new CloudException('migration_required');
        $run = Execution::query()->findOrFail($id);
        $members = ExecutionMember::query()->where('execution_id', $id)->get();
        return ['id' => $id, 'service_id' => $run->service_id, 'service_revision' => $run->service_revision, 'status' => $run->status,
            'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false, 'cloud_writes' => 0,
            'members' => $members->map(function ($member) {
                $name = MachineFailoverMember::query()->whereKey($member->member_id)->value('name');
                return $member->only(['id', 'member_id', 'policy_id', 'policy_revision', 'phase', 'reason']) + ['name' => $name,
                    'attempts' => Attempt::query()->where('execution_member_id', $member->id)->orderBy('ordinal')->get()->map(function ($attempt) {
                        $task = $attempt->task_id ? Task::query()->find($attempt->task_id) : null;
                        $release = Release::query()->where('attempt_id', $attempt->id)->first();
                        return $attempt->only(['id', 'ordinal', 'task_id', 'status', 'reserved_cents', 'created_at']) + [
                            'account_name' => Account::query()->whereKey($attempt->account_id)->value('name'), 'task_status' => $task?->status,
                            'task_reason' => $task?->reason, 'installation_prefix' => $task ? substr($task->installation_id, 0, 8) : null,
                            'external_id' => $task?->external_id, 'initialization' => $task?->bootstrap_state,
                            'forwarding_verified' => isset($attempt->payload['forwarding_receipt']), 'dns_verified' => isset($attempt->payload['dns_receipt']),
                            'released_cents' => $release?->amount_cents ?? 0, 'release_kind' => $release?->proof_kind];
                    })->all()];
            })->all()];
    }

    public function capture(int $id, int $revision, int $admin): array
    {
        $snapshot = $this->registry->locked(fn () => DB::transaction(function () use ($id, $revision, $admin) {
            $service = $this->scope($id, $revision, $admin); $graph = $this->graph($service);
            return Snapshot::query()->create(['id' => (string) Str::uuid(), 'service_id' => $id, 'service_revision' => $revision, 'admin_id' => $admin,
                'context_hash' => $graph['context'], 'payload' => ['state' => 'reading', 'items' => []], 'checked_at' => time(), 'valid_until' => 0]);
        }));
        $service = $this->scope($id, $revision, $admin); $graph = $this->graph($service); $items = []; $groups = [];
        $deadline = microtime(true) + 30;
        foreach ($graph['entries'] as $entry) {
            $task = $entry['task'];
            $items[$task->id] = ['state' => 'unknown', 'monthly_cents' => null, 'reason' => 'cost_identity_unverified', 'absence_verified' => false,
                'identity' => $entry['identity']];
            if ($this->notCreated($task, $entry['owned'])) {
                $items[$task->id] = ['state' => 'not_created', 'monthly_cents' => 0, 'reason' => null, 'absence_verified' => false, 'identity' => $entry['identity']]; continue;
            }
            $account = Account::query()->find($task->account_id);
            if (!$account || $account->provider !== 'linode' || $account->revision !== $task->account_revision || !$task->external_id || !$task->provider_created_at
                || !isset($task->payload['request']['label'], $task->payload['request']['tags']) || ($task->payload['plan']['source_ref'] ?? null) !== []) continue;
            $groups[$account->id][] = $entry;
        }
        foreach ($groups as $accountId => $entries) {
            try {
                if (microtime(true) > $deadline) throw new CloudException('cost_read_timeout');
                $result = Cache::lock('machine-cloud:account:' . $accountId, 120)->block(2, function () use ($accountId, $entries, $admin, $deadline) {
                    $this->registry->actor($admin); $account = Account::query()->findOrFail($accountId);
                    foreach ($entries as $entry) if ($entry['task']->account_revision !== $account->revision) throw new CloudException('account_changed');
                    $unrestricted = $this->cloud->authority($account); $inventory = $this->cloud->completeInventory($account); $result = [];
                    foreach ($entries as $entry) {
                        if (microtime(true) > $deadline) throw new CloudException('cost_read_timeout');
                        $task = $entry['task']; $row = $inventory[$task->external_id] ?? null;
                        if (!$row) {
                            $result[$task->id] = ['state' => $unrestricted ? 'absent' : 'unknown', 'monthly_cents' => $unrestricted ? 0 : null,
                                'reason' => $unrestricted ? null : 'cost_scope_unknown', 'absence_verified' => $unrestricted, 'identity' => $entry['identity']]; continue;
                        }
                        try {
                            $request = $task->payload['request'];
                            if ($row['provider_created_at'] !== $task->provider_created_at || $row['label'] !== $request['label']
                                || !in_array($task->marker, $row['tags'], true) || $row['region'] !== ($request['region'] ?? null)
                                || $row['size'] !== ($request['type'] ?? null)) throw new CloudException('cost_identity_unverified');
                            $result[$task->id] = ['state' => 'present', 'monthly_cents' => $this->cloud->quote($account, $row),
                                'reason' => null, 'absence_verified' => false, 'identity' => $entry['identity']];
                        } catch (CloudException $error) { $result[$task->id] = ['state' => 'unknown', 'monthly_cents' => null,
                            'reason' => $this->reason($error), 'absence_verified' => false, 'identity' => $entry['identity']]; }
                    }
                    if ($this->cloud->authority($account) !== $unrestricted || $account->fresh()?->revision !== $account->revision) throw new CloudException('cost_scope_unknown');
                    $this->registry->actor($admin); return $result;
                });
                $items = array_replace($items, $result);
            } catch (\Throwable $error) {
                foreach ($entries as $entry) $items[$entry['task']->id]['reason'] = $this->reason($error);
            }
        }
        $resourceStates = [];
        foreach ($graph['entries'] as $entry) if (isset($items[$entry['task']->id])) $resourceStates[$entry['resource_key']][] = $items[$entry['task']->id]['state'];
        foreach ($graph['entries'] as $entry) {
            $states = $resourceStates[$entry['resource_key']] ?? [];
            if (in_array('present', $states, true) && in_array('absent', $states, true)) $items[$entry['task']->id] = [
                'state' => 'unknown', 'monthly_cents' => null, 'reason' => 'cost_inventory_inconsistent', 'absence_verified' => false, 'identity' => $entry['identity']];
        }
        return $this->registry->locked(fn () => DB::transaction(function () use ($id, $revision, $admin, $graph, $snapshot, $items, $deadline) {
            $service = $this->scope($id, $revision, $admin);
            if (microtime(true) > $deadline) throw new CloudException('cost_read_timeout');
            if ($this->latest($id)?->id !== $snapshot->id) throw new CloudException('cost_read_superseded');
            if (!hash_equals($snapshot->context_hash, $this->graph($service)['context']) || !hash_equals($graph['context'], $snapshot->context_hash)) throw new CloudException('cost_context_changed');
            $snapshot->fill(['payload' => ['state' => 'complete', 'items' => $items], 'checked_at' => time(), 'valid_until' => time() + 300])->save();
            return $this->workspace($id, $revision, $admin);
        }));
    }

    public function assertCapacity(int $id, int $revision, int $additionalCents): void
    {
        if (!$this->available()) throw new CloudException('cost_baseline_required');
        $service = Service::query()->findOrFail($id);
        if ($service->revision !== $revision) throw new CloudException('config_changed');
        $balance = $this->balance($service, $this->graph($service));
        if ($balance['state'] !== 'verified') throw new CloudException('cost_baseline_required');
        if ($additionalCents < 1 || $balance['allocated_cents'] + $additionalCents > $balance['cap_cents']) throw new CloudException('shared_monthly_budget');
    }

    public function previewRelease(string $id, int $admin): array
    {
        $this->registry->actor($admin); if (!$this->available()) throw new CloudException('migration_required');
        $attempt = Attempt::query()->findOrFail($id); $facts = $this->releaseFacts($attempt); $until = min(time() + 120, $facts['valid_until'] ?? PHP_INT_MAX);
        return ['attempt_id' => $id, 'eligible' => !$facts['reasons'] && !$facts['released'], 'already_released' => $facts['released'],
            'amount_cents' => $attempt->reserved_cents, 'proof_kind' => $facts['kind'], 'reasons' => $facts['reasons'],
            'valid_until' => !$facts['reasons'] && !$facts['released'] ? $until : null,
            'proof_token' => !$facts['reasons'] && !$facts['released'] ? app('encrypter')->encryptString(json_encode([
                'attempt_id' => $id, 'admin_id' => $admin, 'facts' => $this->hash($facts), 'valid_until' => $until], JSON_THROW_ON_ERROR)) : null,
            'cloud_writes' => 0, 'budget_only' => true, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false];
    }

    public function release(string $id, string $proof, string $request, int $admin): array
    {
        $this->registry->actor($admin); if (!$this->available()) throw new CloudException('migration_required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $request)) throw new CloudException('invalid_request_id');
        $initial = Attempt::query()->findOrFail($id);
        return $this->registry->locked(fn () => Cache::lock('machine-cloud:quota:linode', 120)->block(2,
            fn () => Cache::lock('machine-cloud:account:' . $initial->account_id, 120)->block(2, fn () => DB::transaction(function () use ($id, $proof, $request, $admin) {
                $this->registry->actor($admin); $attempt = Attempt::query()->whereKey($id)->lockForUpdate()->firstOrFail(); $key = hash('sha256', $admin . ':' . $request);
                $prior = Release::query()->where('request_key', $key)->first();
                if ($prior && $prior->attempt_id !== $id) throw new CloudException('request_conflict');
                $prior ??= Release::query()->where('attempt_id', $id)->first();
                if ($prior) return ['released' => true, 'release_id' => $prior->id, 'attempt_id' => $id, 'amount_cents' => $prior->amount_cents, 'cloud_writes' => 0, 'budget_only' => true];
                try { $token = json_decode(app('encrypter')->decryptString($proof), true, 8, JSON_THROW_ON_ERROR); }
                catch (\Throwable) { throw new CloudException('budget_proof_invalid'); }
                $facts = $this->releaseFacts($attempt);
                if (!is_array($token) || ($token['attempt_id'] ?? null) !== $id || ($token['admin_id'] ?? null) !== $admin
                    || !is_int($token['valid_until'] ?? null) || $token['valid_until'] <= time() || $token['valid_until'] > time() + 120
                    || !is_string($token['facts'] ?? null) || !hash_equals($this->hash($facts), $token['facts']) || $facts['reasons'] || $facts['released']) throw new CloudException('budget_proof_invalid');
                $row = Release::query()->create(['id' => (string) Str::uuid(), 'attempt_id' => $id, 'service_id' => $facts['service_id'], 'admin_id' => $admin,
                    'request_key' => $key, 'amount_cents' => $attempt->reserved_cents, 'proof_kind' => $facts['kind'], 'proof_hash' => $this->hash($facts), 'payload' => $facts]);
                $attempt->fill(['status' => 'released'])->save();
                $task = $attempt->task_id ? Task::query()->find($attempt->task_id) : null;
                if ($task && !in_array($task->status, ['accepted', 'failed', 'cancelled'], true)) {
                    $task->fill(['status' => 'cancelled', 'reason' => 'budget_released', 'finished_at' => time()])->save();
                    DB::table('v2_machine_provision_step')->insertOrIgnore(['task_id' => $task->id, 'state' => 'cancelled', 'reason' => 'budget_released', 'created_at' => time()]);
                }
                return ['released' => true, 'release_id' => $row->id, 'attempt_id' => $id, 'amount_cents' => $row->amount_cents, 'cloud_writes' => 0, 'budget_only' => true];
            }))));
    }

    private function releaseFacts(Attempt $attempt): array
    {
        $member = ExecutionMember::query()->findOrFail($attempt->execution_member_id); $run = Execution::query()->findOrFail($member->execution_id);
        $task = $attempt->task_id ? Task::query()->find($attempt->task_id) : null; $reasons = []; $kind = null; $snapshot = null;
        if ($member->attempt_id === $attempt->id && !in_array($member->phase, ['needs_review', 'retained', 'cancelled', 'completed'], true)) $reasons[] = 'budget_task_running';
        if ($task && !app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task)) $reasons[] = 'native_lineage_changed';
        if ($task && $this->resourceInUse($task)) $reasons[] = 'budget_resource_in_use';
        if (!$task && $attempt->task_id) $reasons[] = 'native_lineage_changed';
        if (!$task || (!$task->dispatched_at && !$task->external_id && $task->bootstrap_state === null)) $kind = 'not_dispatched';
        elseif (app(MachineFailoverNativeBridge::class)->definitelyRejected($task)) $kind = 'definite_rejection';
        elseif (!$task->external_id || !$task->provider_created_at) $reasons[] = 'original_intent_only';
        else {
            $service = Service::query()->findOrFail($run->service_id); $graph = $this->graph($service); $snapshot = $this->latest($service->id);
            $item = $snapshot?->payload['items'][$task->id] ?? null;
            if (!$snapshot || $snapshot->valid_until <= time() || !hash_equals($snapshot->context_hash, $graph['context'])
                || !$item || $item['state'] !== 'absent' || $item['absence_verified'] !== true || !hash_equals($this->entryIdentity($task), $item['identity'])) $reasons[] = 'budget_absence_unverified';
            else $kind = 'confirmed_absent';
        }
        return ['service_id' => $run->service_id, 'attempt_id' => $attempt->id, 'member_id' => $member->id, 'member_phase' => $member->phase,
            'current_attempt' => $member->attempt_id, 'amount_cents' => $attempt->reserved_cents, 'task_identity' => $task ? $this->entryIdentity($task) : null,
            'task_state' => $task?->only(['status', 'dispatched_at', 'external_id', 'bootstrap_state', 'evidence']),
            'kind' => $kind, 'snapshot_id' => $snapshot?->id, 'valid_until' => $snapshot?->valid_until,
            'reasons' => array_values(array_unique($reasons)), 'released' => Release::query()->where('attempt_id', $attempt->id)->exists()];
    }

    private function resourceInUse(Task $task): bool
    {
        $instances = MachineOwnedInstance::query()->where('task_id', $task->id)->get();
        if ($instances->whereNotNull('active_policy_id')->isNotEmpty() || MachineOwnedDns::query()->whereIn('active_instance_id', $instances->pluck('id'))->exists()
            || MachineDnsTransition::query()->whereIn('state', ['prepared', 'dispatching', 'needs_review'])->where(fn ($q) => $q
                ->whereIn('from_instance_id', $instances->pluck('id'))->orWhereIn('to_instance_id', $instances->pluck('id')))->exists()) return true;
        if (MachineCloudResource::query()->where('provider', 'linode')->where('external_id', $task->external_id ?? '')
            ->where(fn ($q) => $q->where('auto_boot', true)->orWhere('armed', true)->orWhereNotNull('active_task_id'))->exists()) return true;
        $machine = ServerMachine::query()->find($task->machine_id);
        $rows = $machine ? app(\App\Services\ServerMachine\MachineAddressInventory::class)->snapshots([$machine], true)[$machine->id] ?? [] : [];
        return ($rows[$task->installation_id]['at'] ?? 0) >= time() - 180;
    }

    private function graph(Service $service): array
    {
        $members = MachineFailoverMember::query()->where('service_id', $service->id)->orderBy('id')->get(); $entries = []; $context = []; $blockers = [];
        $health = app(MachineCloudCredentialHealth::class);
        foreach ($members as $member) {
            $policy = Policy::query()->find($member->policy_id); $machine = ServerMachine::query()->find($member->machine_id);
            $context[] = [$member->only(['id', 'policy_id', 'machine_id', 'policy_revision']), $policy?->revision,
                $machine ? hash('sha256', (string) $machine->token) : null];
            if (!$policy || !$machine) { $blockers[] = 'cost_context_changed'; continue; }
            try { $ids = app(MachineFailoverCredentials::class)->ids($policy); }
            catch (CloudException) { $ids = []; $blockers[] = 'cost_context_changed'; }
            foreach ($ids as $id) { $account = Account::query()->find($id); $context[] = $this->accountContext($id, $account, $health); }
            $owned = MachineOwnedInstance::query()->where('policy_id', $policy->id)->get()->keyBy('task_id');
            $tasks = Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)->orderBy('id')->limit(101)->get();
            if ($tasks->count() > 100) $blockers[] = 'cost_footprint_limit';
            foreach ($tasks->take(100) as $task) {
                $attempt = Attempt::query()->where('task_id', $task->id)->first(); $account = Account::query()->find($task->account_id);
                $instance = $owned->get($task->id); $identity = $this->entryIdentity($task);
                $entries[$task->id] = ['task' => $task, 'attempt' => $attempt, 'owned' => $instance !== null,
                    'kind' => $instance ? ($instance->active_policy_id !== null ? 'source' : 'retained') : 'task', 'identity' => $identity,
                    'name' => $member->name, 'account_name' => $account?->name,
                    'resource_key' => $task->external_id && $task->provider_created_at ? $this->hash([$account?->provider,
                        $account ? ($health->identityKey($account) ?? 'unknown-' . $account->id) : 'missing-' . $task->account_id,
                        $task->external_id, $task->provider_created_at]) : $task->id];
                // New journaled tasks have their own reservation; ordinary source/retained costs form the baseline.
                if (!$attempt) $context[] = [$identity, $instance?->id, $task->dispatched_at !== null];
                $context[] = $this->accountContext($task->account_id, $account, $health);
            }
            $known = $owned->pluck('resource_id')->filter();
            if (MachineCloudResource::query()->where('machine_id', $machine->id)->whereNotIn('id', $known)->exists()) $blockers[] = 'cost_untracked_resources';
        }
        ksort($entries);
        if (count($entries) > 100) { $blockers[] = 'cost_footprint_limit'; $entries = array_slice($entries, 0, 100, true); }
        // Deduplicate account rows so adding a journaled attempt cannot silently invalidate the baseline.
        $context = array_values(array_unique(array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $context))); sort($context);
        return ['entries' => $entries, 'blockers' => array_values(array_unique($blockers)),
            'context' => $this->hash([$service->only(['id', 'revision', 'monthly_cap_usd']), $context, $blockers])];
    }

    private function balance(Service $service, array $graph): array
    {
        $snapshot = $this->latest($service->id); $fresh = $snapshot && $snapshot->valid_until > time() && hash_equals($snapshot->context_hash, $graph['context'])
            && ($snapshot->payload['state'] ?? null) === 'complete';
        $attempts = Attempt::query()->whereIn('execution_member_id', ExecutionMember::query()->whereIn('execution_id', Execution::query()->where('service_id', $service->id)->select('id'))->select('id'))->get();
        $releases = Release::query()->where('service_id', $service->id)->get()->keyBy('attempt_id');
        $reserved = (int) $attempts->sum('reserved_cents'); $released = (int) $releases->sum('amount_cents'); $allocated = $reserved - $released;
        $baseline = 0; $source = 0; $retained = 0; $sourceKnown = true; $retainedKnown = true;
        $unknown = count($graph['blockers']); $items = []; $groups = []; $pendingQuotes = 0;
        foreach ($graph['entries'] as $entry) {
            $task = $entry['task']; $item = $snapshot?->payload['items'][$task->id] ?? null;
            $valid = $fresh && $item && hash_equals($entry['identity'], $item['identity']);
            $price = $valid ? $item['monthly_cents'] : null; $attempt = $entry['attempt'];
            $groups[$entry['resource_key']][] = ['kind' => $entry['kind'], 'price' => $price, 'attempt' => $attempt];
            if ($attempt && $price === null) $pendingQuotes++;
            if ($attempt && $valid && $item['state'] === 'unknown') $unknown++;
            $items[] = ['task_id' => $task->id, 'attempt_id' => $attempt?->id, 'member_name' => $entry['name'], 'account_name' => $entry['account_name'],
                'external_id' => $task->external_id, 'kind' => $entry['kind'], 'state' => $valid ? $item['state'] : 'unknown', 'monthly_cents' => $price,
                'reason' => $valid ? $item['reason'] : 'cost_baseline_required', 'reserved_cents' => $attempt?->reserved_cents ?? 0,
                'released_cents' => $attempt ? ($releases->get($attempt->id)?->amount_cents ?? 0) : 0];
        }
        foreach ($groups as $group) {
            $prices = array_values(array_filter(array_column($group, 'price'), fn ($value) => $value !== null));
            $groupAttempts = collect($group)->pluck('attempt')->filter()->unique('id');
            $price = $prices ? max($prices) : null;
            if ($groupAttempts->isEmpty()) { if ($price === null) $unknown++; else { $baseline += $price; $allocated += $price; } }
            elseif ($price !== null) $allocated += max(0, $price - $groupAttempts->sum(fn ($row) => $row->reserved_cents - ($releases->get($row->id)?->amount_cents ?? 0)));
            if (in_array('source', array_column($group, 'kind'), true)) {
                if ($price === null) $sourceKnown = false; else $source += $price;
            } elseif (in_array('retained', array_column($group, 'kind'), true)) {
                if ($price === null) $retainedKnown = false; else $retained += $price;
            }
        }
        $state = !$snapshot ? 'unread' : (!$fresh ? (($snapshot->payload['state'] ?? '') === 'reading' && $snapshot->checked_at > time() - 35 ? 'reading' : 'expired') : ($unknown ? 'incomplete' : 'verified'));
        $cap = (int) round($service->monthly_cap_usd * 100);
        return ['snapshot_id' => $snapshot?->id, 'state' => $state, 'checked_at' => $snapshot?->checked_at, 'valid_until' => $snapshot?->valid_until,
            'cap_cents' => $cap, 'baseline_cents' => $fresh && !$unknown ? $baseline : null, 'source_cents' => $fresh && $sourceKnown ? $source : null,
            'retained_cents' => $fresh && $retainedKnown ? $retained : null, 'reserved_cents' => $reserved, 'released_cents' => $released,
            'allocated_cents' => $allocated, 'available_cents' => $state === 'verified' ? max(0, $cap - $allocated) : null,
            'unknown_count' => $unknown, 'pending_quote_count' => $pendingQuotes, 'blockers' => $graph['blockers'], 'items' => $items,
            'scope' => 'compute_instance_monthly_estimate', 'actual_bill' => false];
    }

    private function notCreated(Task $task, bool $owned): bool
    {
        return !$owned && (!$task->dispatched_at && !$task->external_id && $task->bootstrap_state === null || app(MachineFailoverNativeBridge::class)->definitelyRejected($task));
    }
    private function entryIdentity(Task $task): string
    {
        return $this->hash([$task->id, $task->account_id, $task->account_revision, $task->machine_id, $task->machine_authority, $task->installation_id,
            $task->external_id, $task->provider_created_at, $task->marker, $task->slot, $task->plan_hash, $task->payload['request'] ?? null]);
    }
    private function accountContext(int $id, ?Account $account, MachineCloudCredentialHealth $health): array
    {
        return ['account', $id, $account?->revision, $account ? $health->credentialKey($account) : null, $account ? $health->identityKey($account) : null];
    }
    private function latest(int $service): ?Snapshot { return Snapshot::query()->where('service_id', $service)->orderByDesc('sequence')->first(); }
    private function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function reason(\Throwable $error): string { return $error instanceof CloudException && preg_match('/^[a-z_]{1,60}$/D', $error->getMessage()) ? $error->getMessage() : 'cost_read_failed'; }
}
