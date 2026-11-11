<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineFailoverObservation as Observation;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;

// This adapter has no cloud, DNS, queue or notification transport.
class MachineFailoverReadOnlyAdapter
{
    public function __construct(private MachineFailoverObserver $observer, private MachineFailoverCredentials $credentials,
        private MachineCloudCredentialHealth $health, private MachineInstanceOwnership $ownership) {}

    public function execute(): never { throw new CloudException('shadow_execution_disabled'); }

    public function read(Policy $policy, int $now, ?array $dnsScope = null): array
    {
        $current = $this->observer->summary($policy, $now); $probe = $current['probe'] ?? [];
        $signal = $probe['state'] ?? 'unknown';
        $source = ['state' => $signal, 'reason' => $probe['reason'] ?? $current['reason'],
            'installation_id' => $probe['installation_id'] ?? null, 'ipv4' => $probe['ipv4'] ?? [],
            'checked_at' => $probe['checked_at'] ?? null, 'valid_until' => $probe['valid_until'] ?? null,
            'reachable_targets' => count(array_filter($probe['targets'] ?? [], fn ($row) => $row['status'] === 'reachable')),
            'total_targets' => count($probe['targets'] ?? []), 'collecting' => $current['collecting'],
            'evidence_reason' => $current['reason'],
            'evidence_current' => false, 'failures' => null, 'required_failures' => $policy->failure_threshold,
            'baseline_at' => null, 'threshold_reached' => false, 'ownership_verified' => false];
        $row = Observation::query()->where('policy_id', $policy->id)->where('policy_revision', $policy->revision)->latest('id')->first();
        $same = $current['evidence_current'] && $row && ($probe['context_key'] ?? null) === $row->context_key
            && $row->installation_id === $source['installation_id'] && $row->checked_at === $source['checked_at']
            && $row->state === $signal && $row->valid_until >= $now && $row->observed_at <= $now
            && $row->baseline_at !== null && $row->baseline_at <= $row->checked_at
            && ($signal === 'healthy' || ($row->first_failure_at !== null && $row->first_failure_at >= $row->baseline_at
                && $row->first_failure_at <= $row->checked_at && $row->first_failure_at - $row->baseline_at <= $policy->stale_after_seconds));
        if ($same) {
            $source['evidence_current'] = true; $source['failures'] = $row->consecutive_failures;
            $source['evidence_reason'] = 'collected';
            $source['baseline_at'] = $row->baseline_at;
            $source['threshold_reached'] = $signal === 'failed' && $row->threshold_reached && $row->consecutive_failures >= $policy->failure_threshold;
        } elseif ($current['evidence_current'] && $row?->baseline_at !== null) $source['evidence_reason'] = 'source_unverified';
        $blockers = [];
        $author = \App\Models\User::query()->find($policy->getRawOriginal('updated_by'), ['id', 'is_admin', 'banned']);
        if (!$author || !$author->is_admin || $author->banned) $blockers[] = 'permission_revoked';
        $dns = \App\Models\MachineDnsCredential::query()->find($policy->dns_credential_id, ['id', 'provider']);
        if ($dns && $dns->provider !== 'cloudflare' && empty($dnsScope['dns_sets'])) $blockers[] = 'dns_provider_unsupported';
        $checks = app(MachineFailoverPolicyService::class)->localChecks($policy);
        try { $checks = app(MachineNativeDnsAdmission::class)->policyChecks($policy, $checks, $dnsScope); }
        catch (CloudException $error) { $blockers[] = $error->getMessage(); }
        foreach ($checks as $check) {
            if ($check['status'] !== 'passed' && !in_array($check['code'], ['execution_disabled', 'provider_not_checked',
                'external_ownership_unverified', 'forwarding_not_verified'], true)) $blockers[] = $check['code'];
        }
        $active = MachineOwnedInstance::query()->where('active_policy_id', $policy->id)->first();
        $task = $active ? Task::query()->find($active->task_id) : null;
        if ($active && $task && $task->status === 'accepted' && $active->policy_id === $policy->id && $active->status === 'active' && $active->policy_revision === $policy->revision
            && in_array($active->ip, $source['ipv4'], true)
            && $active->installation_id === $source['installation_id'] && $task->installation_id === $active->installation_id
            && $task->machine_id === $policy->machine_id && $task->slot === $policy->slot && $this->credentials->owns($policy, $task)) {
            try { $this->ownership->assertExclusive($policy); $source['ownership_verified'] = true; }
            catch (CloudException $error) { $blockers[] = $error->getMessage(); }
        }
        if (!$source['ownership_verified']) $blockers[] = 'source_ownership_unverified';

        $account = null; $ids = []; $attempts = null; $cooldown = null; $health = null;
        try {
            $choice = $this->credentials->selection($policy); $account = Account::query()->find($choice['account_id']);
            if (!$account || $account->provider !== $this->credentials->provider($policy)
                || (!$this->credentials->grouped($policy) && $account->revision !== $policy->account_revision)) throw new CloudException('account_changed');
            $health = $this->health->status($account, $policy->only(['region', 'size', 'image']));
            if (!$health['available'] || !$health['eligible']) $blockers[] = 'credential_health_blocked';
            $ids = $this->health->sameAccountIds($account); $history = Task::query()->whereIn('account_id', $ids);
            $attempts = (clone $history)->where('created_at', '>=', $now - 86400)->count();
            $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
            if ($attempts >= $limit) $blockers[] = 'daily_budget';
            $last = (clone $history)->max('created_at');
            $cooldown = $last === null ? null : (int) $last + $policy->cooldown_seconds;
            if ($cooldown !== null && $cooldown > $now) $blockers[] = 'replacement_cooldown';
            if ((clone $history)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
                || MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->whereIn('account_id', $ids)->select('id'))
                    ->whereNotIn('status', MachineCloudTask::TERMINAL)->exists()) $blockers[] = 'task_active';
        } catch (CloudException $error) { $blockers[] = $error->getMessage(); }
        $plan = $current['plan'] ?? null;
        if ($source['threshold_reached'] && (!is_array($plan) || ($plan['status'] ?? null) !== 'blocked'
            || ($plan['policy_revision'] ?? null) !== $policy->revision)) $blockers[] = 'decision_snapshot_missing';
        if ($source['threshold_reached'] && is_array($plan)) {
            $episode = $plan['evidence']['episode_key'] ?? null;
            $expected = hash('sha256', json_encode([$policy->id, $policy->revision, $row->context_key, $row->baseline_at, $row->first_failure_at], JSON_THROW_ON_ERROR));
            if (!is_string($episode) || !hash_equals($expected, $episode)) $blockers[] = 'decision_snapshot_missing';
            elseif (\App\Models\MachineReplacementTask::query()->where('episode_key', $episode)->exists()) $blockers[] = 'replacement_episode_used';
        }
        return ['source' => $source, 'candidate' => $account ? ['account_id' => $account->id, 'account_revision' => $account->revision,
            'account_name' => $account->name, 'provider' => $account->provider, 'health' => $health['state'] ?? 'unknown',
            'identity' => $health['identity'] ?? 'unknown', 'region' => $policy->region, 'size' => $policy->size, 'image' => $policy->image] : null,
            'budget' => ['account_attempts_24h' => $attempts, 'cooldown_until' => $cooldown, 'monthly_reservation_usd' => 0],
            'blockers' => array_values(array_unique($blockers)), 'quota_ids' => $ids];
    }
}
