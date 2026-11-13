<?php

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineFailoverObservation as Observation;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeReportSource;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineFailoverObserver
{
    public function __construct(private MachineAddressInventory $addresses, private MachineEntryProbe $probe) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_failover_policy') && Schema::hasTable('v2_machine_failover_observation')
            && Schema::hasTable('v2_machine_failover_plan') && Schema::hasTable('v2_machine_cloud_account')
            && Schema::hasTable('v2_server_machine');
    }

    public function collect(): array
    {
        $result = ['enabled' => config('machine_failover.observe_enabled') === true, 'policies_checked' => 0, 'errors' => 0, 'executed' => false];
        if (!$result['enabled'] || !$this->available()) return $result;
        foreach (Policy::query()->orderBy('id')->limit(100)->pluck('id') as $id) {
            try { $this->observe($id); $result['policies_checked']++; }
            catch (\Throwable) { $result['errors']++; }
        }
        foreach ([Observation::class => 'observed_at', Plan::class => 'created_at'] as $model => $time) {
            $expired = $model::query()->whereNotIn('policy_id', Policy::query()->select('id'))
                ->where($time, '<', now()->getTimestamp() - max(1, min(30, (int) config('machine_failover.history_days', 7))) * 86400)->limit(500)->pluck('id');
            $model::query()->whereIn('id', $expired)->delete();
        }
        return $result;
    }

    public function observe(int $id): array
    {
        if (config('machine_failover.observe_enabled') !== true) throw new CloudException('observation_disabled');
        if (!$this->available()) throw new CloudException('migration_required');
        return Cache::lock('machine-failover:write', 60)->block(2, function () use ($id) {
            $policy = Policy::query()->findOrFail($id);
            $lock = $policy->credential_group_id ? 'machine-cloud:credential-groups' : 'machine-cloud:account:' . $policy->account_id;
            return Cache::lock($lock, 60)->block(2, fn () => DB::transaction(function () use ($id) {
                $policy = Policy::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $now = now()->getTimestamp();
                $sample = $this->capture($policy, $now);
                $previous = Observation::query()->where('policy_id', $id)->where('policy_revision', $policy->revision)->latest('id')->first();
                $valid = in_array($sample['state'], ['healthy', 'failed'], true);
                $same = $previous && hash_equals($previous->context_key, $sample['context_key']);
                $key = $this->hash([$id, $policy->revision, $sample['context_key'], $valid ? $sample['checked_at']
                    : [$sample['state'], $sample['reason'], $previous?->id]]);
                if ($valid) {
                    $lastAt = Observation::query()->where('policy_id', $id)->where('policy_revision', $policy->revision)
                        ->where('context_key', $sample['context_key'])->whereIn('state', ['healthy', 'failed'])->max('checked_at');
                    if ($lastAt !== null && $sample['checked_at'] < $lastAt + $sample['interval']) {
                        $this->prune($id, $now); return $this->present($previous);
                    }
                } elseif ($same && $previous->state === $sample['state'] && $previous->reason === $sample['reason']) {
                    $this->prune($id, $now); return $this->present($previous);
                }
                $baseline = $same ? $previous->baseline_at : null;
                $count = 0; $firstFailure = null;
                if ($sample['state'] === 'healthy') $baseline = $sample['checked_at'];
                if ($sample['state'] === 'failed') {
                    $continuous = $same && $previous->state === 'failed' && $previous->checked_at < $sample['checked_at']
                        && $sample['checked_at'] - $previous->checked_at <= $sample['interval'] * 2 + 10;
                    $count = $continuous ? min(1000000, $previous->consecutive_failures + 1) : 1;
                    $firstFailure = $continuous ? $previous->first_failure_at : $sample['checked_at'];
                }
                $reached = $count >= $policy->failure_threshold;
                $row = Observation::query()->create(array_intersect_key($sample, array_flip(['context_key', 'installation_id', 'state', 'reason', 'checked_at', 'valid_until']))
                    + ['policy_id' => $id, 'policy_revision' => $policy->revision, 'sample_key' => $key, 'observed_at' => $now,
                        'baseline_at' => $baseline, 'first_failure_at' => $firstFailure, 'consecutive_failures' => $count, 'threshold_reached' => $reached]);
                // A plan is an immutable decision snapshot, never a provisioning request or budget reservation.
                $obsolete = Plan::query()->where('policy_id', $id)->where('status', 'blocked');
                if (!$reached || !$same) $obsolete->update(['status' => 'superseded', 'closed_at' => $now,
                    'closed_reason' => $sample['state'] === 'healthy' ? 'recovered' : 'evidence_changed']);
                else $obsolete->where('policy_revision', '!=', $policy->revision)->update(['status' => 'superseded', 'closed_at' => $now, 'closed_reason' => 'policy_changed']);
                if ($reached) $this->plan($policy, $row, $now);
                $this->prune($id, $now);
                return $this->present($row);
            }));
        });
    }

    public function history(int $id, int $revision): array
    {
        if (!$this->available()) return ['available' => false, 'execution_enabled' => false, 'observations' => [], 'plans' => []];
        $policy = Policy::query()->findOrFail($id);
        if ($policy->revision !== $revision) throw new CloudException('config_changed');
        return ['available' => true, 'execution_enabled' => false, 'current' => $this->summary($policy, now()->getTimestamp()),
            'observations' => Observation::query()->where('policy_id', $id)->latest('id')->limit(50)->get()->map(fn ($row) => $this->present($row)),
            'plans' => Plan::query()->where('policy_id', $id)->latest('id')->limit(20)->get()->map(fn ($row) => $this->presentPlan($row))];
    }

    public function summary(Policy $policy, int $now): array
    {
        $result = ['available' => $this->available(), 'collecting' => config('machine_failover.observe_enabled') === true,
            'evidence_current' => false, 'observed_failures' => null, 'threshold_reached' => false, 'reason' => 'sample_not_collected', 'plan' => null, 'probe' => null];
        if (!$result['available']) return $result;
        $sample = $this->capture($policy, $now);
        $result['probe'] = array_intersect_key($sample, array_flip(['context_key', 'state', 'reason', 'installation_id', 'checked_at', 'valid_until', 'ipv4', 'targets']));
        $row = Observation::query()->where('policy_id', $policy->id)->where('policy_revision', $policy->revision)->latest('id')->first();
        $matches = $result['collecting'] && $row && in_array($sample['state'], ['healthy', 'failed'], true)
            && hash_equals($row->context_key, $sample['context_key']) && $row->checked_at === $sample['checked_at'] && $row->state === $sample['state'];
        $result['reason'] = !$result['collecting'] ? 'observation_disabled' : ($matches ? $row->reason
            : (in_array($sample['state'], ['healthy', 'failed'], true) ? 'sample_not_collected' : $sample['reason']));
        if (!$matches) return $result;
        $result['evidence_current'] = true;
        $result['observed_failures'] = $row->consecutive_failures;
        $result['threshold_reached'] = $row->threshold_reached;
        if ($row->threshold_reached) {
            $plan = Plan::query()->where('policy_id', $policy->id)->where('policy_revision', $policy->revision)->where('status', 'blocked')->latest('id')->first();
            if ($plan) $result['plan'] = $this->presentPlan($plan);
        }
        return $result;
    }

    private function capture(Policy $policy, int $now): array
    {
        $config = $this->probe->configuration();
        $result = ['context_key' => $this->hash([$policy->revision, $config['revision'], 'missing']), 'installation_id' => null,
            'state' => 'unknown', 'reason' => 'initialization_required', 'checked_at' => null, 'valid_until' => null,
            'interval' => $config['interval_seconds'], 'ipv4' => [], 'targets' => []];
        $machine = ServerMachine::query()->find($policy->machine_id);
        if (!$machine || !$machine->is_active || !hash_equals($policy->machine_authority, hash('sha256', (string) $machine->token))) {
            return array_replace($result, ['reason' => 'machine_identity_changed']);
        }
        $rows = $this->addresses->snapshots([$machine], true)[$machine->id] ?? [];
        if (Schema::hasTable('v2_machine_owned_instance')) {
            $active = \App\Models\MachineOwnedInstance::query()->where('active_policy_id', $policy->id)->value('installation_id');
            if ($active !== null) $rows = isset($rows[$active]) ? [$active => $rows[$active]] : [];
        }
        $fresh = array_filter($rows, fn ($row) => is_int($row['at'] ?? null) && $row['at'] >= max((int) $policy->created_at, $now - $policy->stale_after_seconds) && $row['at'] <= $now);
        if (!$fresh) return array_replace($result, ['reason' => $rows ? 'agent_stale' : 'initialization_required']);
        if (count($fresh) !== 1) return array_replace($result, ['reason' => 'ambiguous_instances']);
        $id = array_key_first($fresh); $row = $fresh[$id];
        if (NodeReportSource::installation($id) === null || ($row['installation_id'] ?? null) !== $id) return array_replace($result, ['reason' => 'source_unverified']);
        $ips = [];
        foreach ((array) ($row['ips'] ?? []) as $item) {
            $ip = MachineEntryProbe::publicIp($item['ip'] ?? null);
            if ($ip && !str_contains($ip, ':')) $ips[$ip] = true;
        }
        ksort($ips);
        $result['ipv4'] = array_keys($ips);
        $result['installation_id'] = $id;
        $result['context_key'] = $this->hash([$policy->revision, $config['revision'], $id, array_keys($ips)]);
        if (!$ips) return array_replace($result, ['reason' => 'ipv4_missing']);
        if (!$config['enabled']) return array_replace($result, ['reason' => 'probe_disabled']);
        $raw = $this->probe->normalizeReport($row['entry_probe'] ?? null);
        if (!$raw || $raw['revision'] !== $config['revision'] || $raw['state'] !== 'ready') return array_replace($result, ['reason' => 'probe_unknown']);
        if ($raw['checked_at'] > $now) return array_replace($result, ['reason' => 'probe_clock_skew']);
        $snapshot = $this->probe->snapshot($row + ['entry_probe_installation_id' => $id], $now);
        if ($snapshot['checked_at'] === null || $snapshot['valid_until'] < $now) return array_replace($result, ['reason' => 'probe_stale']);
        $result['checked_at'] = $snapshot['checked_at']; $result['valid_until'] = $snapshot['valid_until'];
        $grouped = []; $reachable = false;
        foreach ($raw['targets'] as $target) {
            $source = $target['source_ip'] ?? '';
            if (!isset($ips[$source])) continue;
            if ($target['status'] === 'reachable') $reachable = true;
            $grouped[$source][$target['id']] = $target['status'];
        }
        foreach ($ips as $ip => $_) foreach ($config['targets'] as $target) {
            $result['targets'][] = ['source_ip' => $ip, 'target_ip' => $target['ip'], 'status' => $grouped[$ip][$target['id']] ?? 'unknown'];
        }
        if ($reachable) return array_replace($result, ['state' => 'healthy', 'reason' => 'ipv4_reachable']);
        // All known public IPv4 sources need complete failed target sets; IPv6 and unattributed reports cannot establish failure.
        foreach ($ips as $ip => $_) {
            $targets = $grouped[$ip] ?? [];
            if (count($targets) !== count($config['targets']) || array_diff($targets, ['unreachable'])) return array_replace($result, ['reason' => 'source_unverified']);
        }
        return array_replace($result, ['state' => 'failed', 'reason' => 'ipv4_all_failed']);
    }

    private function plan(Policy $policy, Observation $row, int $now): void
    {
        $episode = $this->hash([$policy->id, $policy->revision, $row->context_key, $row->baseline_at, $row->first_failure_at]);
        $checks = app(MachineFailoverPolicyService::class)->localChecks($policy);
        $blockers = array_column(array_filter($checks, fn ($check) => $check['status'] !== 'passed'), 'code');
        $actor = User::query()->find($policy->getRawOriginal('updated_by'), ['id', 'is_admin', 'banned']);
        if (!$actor || !$actor->is_admin || $actor->banned) $blockers[] = 'permission_revoked';
        $attempts = null; $lastDispatch = null;
        $selection = null;
        try { $selection = app(MachineFailoverCredentials::class)->selection($policy); }
        catch (CloudException $error) { $blockers[] = $error->getMessage(); }
        $accountId = $selection['account_id'] ?? null;
        if (!Schema::hasTable('v2_machine_provision_task')) $blockers[] = 'budget_history_unavailable';
        elseif ($accountId !== null) {
            $tasks = MachineProvisionTask::query()->where('account_id', $accountId);
            $attempts = (clone $tasks)->where('created_at', '>=', $now - 86400)->count();
            $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
            if ($attempts >= $limit) $blockers[] = 'daily_budget_reached';
            if ((clone $tasks)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()) $blockers[] = 'active_creation_task';
            $lastDispatch = (clone $tasks)->max('dispatched_at');
            if ($lastDispatch !== null && $lastDispatch + $policy->cooldown_seconds > $now) $blockers[] = 'cooldown_active';
        }
        if (!Schema::hasTable('v2_machine_cloud_task') || !Schema::hasTable('v2_machine_cloud_resource')) $blockers[] = 'cloud_history_unavailable';
        elseif (MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->where('account_id', $accountId)->select('id'))
            ->whereNotIn('status', MachineCloudTask::TERMINAL)->exists()) $blockers[] = 'active_cloud_task';
        $blockers[] = 'pricing_unverified';
        $blockers = array_values(array_unique($blockers)); sort($blockers);
        $guard = $this->hash([$blockers, $attempts, $lastDispatch, $selection]);
        $previous = Plan::query()->where('policy_id', $policy->id)->latest('id')->first();
        if ($previous && $previous->status === 'blocked' && ($previous->evidence['episode_key'] ?? null) === $episode
            && ($previous->evidence['guard_key'] ?? null) === $guard) return;
        Plan::query()->where('policy_id', $policy->id)->where('status', 'blocked')
            ->update(['status' => 'superseded', 'closed_at' => $now, 'closed_reason' => 'guards_changed']);
        $key = $this->hash([$episode, $guard, $previous?->id]);
        Plan::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'observation_id' => $row->id,
            'decision_key' => $key, 'status' => 'blocked', 'created_at' => $now, 'blockers' => $blockers,
            'evidence' => ['episode_key' => $episode, 'guard_key' => $guard,
                'installation_id' => $row->installation_id, 'baseline_at' => $row->baseline_at, 'first_failure_at' => $row->first_failure_at,
                'sample_at' => $row->checked_at, 'observed_failures' => $row->consecutive_failures, 'required_failures' => $policy->failure_threshold,
                'account_id' => $accountId, 'credential_group_id' => $policy->credential_group_id, 'machine_id' => $policy->machine_id, 'slot' => $policy->slot,
                'region' => $policy->region, 'size' => $policy->size, 'image' => $policy->image,
                'monthly_limit_usd' => $policy->monthly_limit_usd, 'daily_limit' => $policy->daily_limit, 'account_attempts_24h' => $attempts,
                'last_dispatched_at' => $lastDispatch === null ? null : (int) $lastDispatch,
                'cooldown_until' => $lastDispatch === null ? null : (int) $lastDispatch + $policy->cooldown_seconds,
                'pricing_checked' => false, 'external_ownership_verified' => false, 'forwarding_verified' => false]]);
    }

    private function prune(int $id, int $now): void
    {
        foreach ([[Observation::class, 'observed_at', 'sample_history_limit', 500], [Plan::class, 'created_at', 'plan_history_limit', 100]] as [$model, $time, $setting, $default]) {
            $limit = max(50, min(2000, (int) config('machine_failover.' . $setting, $default)));
            $old = $model::query()->where('policy_id', $id)->latest('id')->skip($limit)->take(100)->pluck('id');
            $expired = $model::query()->where('policy_id', $id)->where($time, '<', $now - max(1, min(30, (int) config('machine_failover.history_days', 7))) * 86400)
                ->where('id', '!=', $model::query()->where('policy_id', $id)->max('id'))->limit(100)->pluck('id');
            $model::query()->whereIn('id', $old->merge($expired))->delete();
        }
    }

    private function hash(array $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function present(?Observation $row): array { return $row ? $row->toArray() + ['execution_enabled' => false] : []; }
    private function presentPlan(Plan $row): array { return $row->toArray() + ['executed' => false, 'execution_enabled' => false, 'requires_new_authorization' => true]; }
}
