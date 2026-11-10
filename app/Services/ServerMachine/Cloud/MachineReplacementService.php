<?php

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\MachineReplacementTask as Replacement;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\MachineOperationNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineReplacementService
{
    public function __construct(private MachineFailoverObserver $observer, private MachineInstanceOwnership $ownership) {}

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_replacement_task') && Schema::hasColumn('v2_machine_provision_task', 'replacement_id');
    }

    public function overview(): array
    {
        return ['available' => $this->available(), 'execution_enabled' => false, 'dns_cutover' => false,
            'tasks' => $this->available() ? Replacement::query()->latest('id')->limit(100)->get() : []];
    }

    public function gate(int $policy): void
    {
        if (config('machine_failover.allow_replacement') !== true) throw new CloudException('replacement_disabled');
        if (!in_array($policy, config('machine_failover.allowed_replacement_policy_ids', []), true)) throw new CloudException('replacement_not_allowed');
        if (config('machine_provision.allow_live') !== true) throw new CloudException('provisioning_disabled');
        if (!$this->available()) throw new CloudException('migration_required');
    }

    // Internal entry only: no observation, browser request or scheduler calls this automatically.
    public function queue(int $planId, int $revision, string $requestId, int $admin): array
    {
        return Cache::lock('machine-failover:write', 120)->block(2, function () use ($planId, $revision, $requestId, $admin) {
            $plan = Plan::query()->findOrFail($planId);
            $this->gate($plan->policy_id);
            $policy = Policy::query()->findOrFail($plan->policy_id);
            if ($policy->revision !== $revision) throw new CloudException('config_changed');
            $input = $this->input($policy);
            return app(MachineProvisionService::class)->queueReplacement($input, $requestId, $admin, $planId);
        });
    }

    private function input(Policy $policy, ?Task $task = null): array
    {
        return app(MachineFailoverCredentials::class)->context($policy, $task) + ['machine_id' => $policy->machine_id, 'slot' => $policy->slot,
            'panel_origin' => app(ProvisionBootstrap::class)->configuredOrigin(), 'region' => $policy->region,
            'size' => $policy->size, 'image' => $policy->image, 'authorized_keys' => [],
            'monthly_limit_usd' => $policy->monthly_limit_usd];
    }

    // Called under the provisioning account lock, again immediately before the provider POST.
    public function validate(int $planId, array $input, ?Task $current = null, ?\App\Models\MachineFailoverAttempt $attempt = null, ?array $dnsScope = null): array
    {
        $input = ProvisionPlan::normalize($input);
        $plan = Plan::query()->findOrFail($planId);
        $this->gate($plan->policy_id);
        $policy = Policy::query()->findOrFail($plan->policy_id);
        if ($plan->policy_revision !== $policy->revision) throw new CloudException('config_changed');
        if ($attempt) app(MachineFailoverNativeBridge::class)->assertAttempt($attempt, $current);
        if (!MachineIntentJson::same(ProvisionPlan::normalize($attempt ? $attempt->payload['plan'] : $this->input($policy, $current)), $input)) throw new CloudException('source_changed');
        $checks = app(MachineFailoverPolicyService::class)->localChecks($policy, $current);
        if ($attempt) {
            $member = \App\Models\MachineFailoverExecutionMember::query()->findOrFail($attempt->execution_member_id);
            if (isset($member->payload['dns_source'])) $dnsScope = \App\Models\MachineFailoverExecution::query()->findOrFail($member->execution_id)->payload['snapshot'];
        }
        $checks = app(MachineNativeDnsAdmission::class)->policyChecks($policy, $checks, $dnsScope);
        foreach ($checks as $check) {
            if ($check['status'] !== 'passed' && !in_array($check['code'], ['execution_disabled', 'provider_not_checked',
                'external_ownership_unverified', 'forwarding_not_verified'], true)) throw new CloudException('replacement_guard_failed');
        }
        $active = MachineOwnedInstance::query()->where('active_policy_id', $policy->id)->first();
        $source = $plan->evidence['installation_id'] ?? null;
        $sourceTask = $active ? Task::query()->find($active->task_id) : null;
        if (!$active || !$sourceTask || $sourceTask->status !== 'accepted' || $active->installation_id !== $source
            || $active->policy_revision !== $policy->revision) throw new CloudException('ownership_mismatch');
        $this->ownership->assertExclusive($policy);
        $summary = $this->observer->summary($policy, now()->getTimestamp());
        $context = \App\Models\MachineFailoverObservation::query()->whereKey($plan->observation_id)->value('context_key');
        if (!is_string($context) || ($summary['probe']['context_key'] ?? null) !== $context) throw new CloudException('replacement_evidence_changed');
        if (($summary['probe']['state'] ?? null) === 'healthy' && ($summary['probe']['installation_id'] ?? null) === $source) {
            throw new CloudException('source_recovered');
        }
        $sameDecision = ($current || $attempt) ? ($summary['plan']['evidence']['episode_key'] ?? null) === ($plan->evidence['episode_key'] ?? null)
            && in_array($plan->status, ['blocked', 'superseded'], true) : ($summary['plan']['id'] ?? null) === $plan->id && $plan->status === 'blocked';
        if (!$summary['evidence_current'] || !$summary['threshold_reached'] || ($summary['probe']['state'] ?? null) !== 'failed' || !$sameDecision
            || !is_string($plan->evidence['episode_key'] ?? null)) throw new CloudException('replacement_evidence_changed');
        $episode = $plan->evidence['episode_key'];
        if (app(MachineFailoverNativeBridge::class)->claimsEpisode($episode, $attempt)) throw new CloudException('replacement_episode_used');
        if ($attempt) {
            $member = \App\Models\MachineFailoverExecutionMember::query()->findOrFail($attempt->execution_member_id);
            if ($member->plan_id !== $plan->id || $member->policy_id !== $policy->id || $member->episode_key !== $episode) throw new CloudException('native_lineage_changed');
        }
        if ($policy->credential_group_id && Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)
            ->when($current, fn ($query) => $query->where('id', '!=', $current->id))->where('created_at', '>=', time() - 86400)->count() >= $policy->daily_limit) throw new CloudException('credential_group_daily_budget');
        if (Replacement::query()->where('episode_key', $episode)->when($current, fn ($query) => $query->where('task_id', '!=', $current->id))->exists()) {
            throw new CloudException('replacement_episode_used');
        }
        $accountId = $input['account_id'];
        $quotaIds = app(MachineCloudCredentialHealth::class)->sameAccountIds(\App\Models\MachineCloudAccount::query()->findOrFail($accountId));
        $history = Task::query()->whereIn('account_id', $quotaIds);
        $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
        $attempts = (clone $history)->where('created_at', '>=', time() - 86400)->count();
        if ($attempts >= $limit + ($current ? 1 : 0)) throw new CloudException('daily_budget');
        if ((clone $history)->when($current, fn ($query) => $query->where('id', '!=', $current->id))
            ->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
            || \App\Models\MachineCloudTask::query()->whereIn('resource_id', \App\Models\MachineCloudResource::query()
                ->whereIn('account_id', $quotaIds)->select('id'))->whereNotIn('status', \App\Models\MachineCloudTask::TERMINAL)->exists()) {
            throw new CloudException('task_active');
        }
        $previous = (clone $history)->when($current, fn ($query) => $query->where('id', '!=', $current->id))->max('created_at');
        if ($previous !== null && $previous + $policy->cooldown_seconds > time()) throw new CloudException('replacement_cooldown');
        return ['policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'plan_id' => $plan->id,
            'episode_key' => $episode, 'account_id' => $accountId, 'machine_id' => $policy->machine_id,
            'source_installation_id' => $source, 'source_context_key' => $context, 'reserved_monthly_usd' => $policy->monthly_limit_usd];
    }

    public function assertDispatch(Task $task): void
    {
        if (!$task->replacement_id) return;
        $row = Replacement::query()->find($task->replacement_id);
        if (!$row || $row->task_id !== $task->id || $row->account_id !== $task->account_id || $row->machine_id !== $task->machine_id) {
            throw new CloudException('ownership_mismatch');
        }
        $this->gate($row->policy_id);
        $policy = Policy::query()->find($row->policy_id);
        if (!$policy || $policy->revision !== $row->policy_revision) throw new CloudException('config_changed');
        if (!$task->dispatched_at) {
            $validated = $this->validate($row->plan_id, $task->payload['plan'], $task);
            if ($row->policy_revision !== $validated['policy_revision'] || !hash_equals($row->episode_key, $validated['episode_key'])) {
                throw new CloudException('replacement_evidence_changed');
            }
        }
    }

    public function sync(Task $task): void
    {
        if (!$task->replacement_id || !$this->available()) return;
        try {
            DB::transaction(function () use ($task) {
                $row = Replacement::query()->whereKey($task->replacement_id)->lockForUpdate()->first();
                if (!$row || $row->task_id !== $task->id) return;
                $status = $this->stage($row, $task);
                $reason = $task->reason;
                if ($row->status !== $status || $row->reason !== $reason) {
                    $event = $row->status === $status ? $row->notice_event : (string) Str::uuid();
                    $row->fill(['status' => $status, 'reason' => $reason, 'notice_event' => $event,
                        'steps' => array_slice([...($row->steps ?? []), ['state' => $status, 'reason' => $reason, 'created_at' => time()]], -30)])->save();
                }
                app(MachineOperationNotifier::class)->replacement($row->id);
                $policy = Policy::query()->find($row->policy_id);
                if (!$row->source_recovered_at && $policy && $policy->revision === $row->policy_revision) {
                    $probe = $this->observer->summary($policy, now()->getTimestamp())['probe'];
                    if (($probe['state'] ?? null) === 'healthy' && ($probe['installation_id'] ?? null) === $row->source_installation_id
                        && ($probe['context_key'] ?? null) === $row->source_context_key) {
                        $row->fill(['source_recovered_at' => time(), 'recovery_event' => (string) Str::uuid()])->save();
                        app(MachineOperationNotifier::class)->replacement($row->id, true);
                    }
                }
            });
        } catch (\Throwable) { try { Log::warning('[MachineReplacement] Progress capture unavailable'); } catch (\Throwable) {} }
    }

    private function stage(Replacement $row, Task $task): string
    {
        if ($task->status === 'awaiting_acceptance') {
            $instance = MachineOwnedInstance::query()->where('task_id', $task->id)->first();
            if ($instance && $instance->verified_until > time() && $instance->policy_revision === $row->policy_revision
                && ($instance->verification['cleanup_complete'] ?? false) === true && !empty($instance->verification['receipt_sha256'])) return 'awaiting_dns';
        }
        return match ($task->status) {
            'dispatching', 'waiting_provider' => 'creating', 'waiting_initialization' => 'initializing',
            'waiting_agent', 'waiting_node_reports' => 'waiting_reports', 'waiting_ipv4_probe' => 'waiting_ipv4',
            'accepted' => $this->completed($row, $task) ? 'completed' : 'needs_review', default => $task->status,
        };
    }

    private function completed(Replacement $row, Task $task): bool
    {
        if (isset($task->evidence['native_dns_completion'])) {
            $attempt = \App\Models\MachineFailoverAttempt::query()->find($task->payload['coordinated_attempt'] ?? '');
            $member = $attempt ? \App\Models\MachineFailoverExecutionMember::query()->find($attempt->execution_member_id) : null;
            return $member && $member->policy_id === $row->policy_id && $member->policy_revision === $row->policy_revision
                && app(MachineNativeDnsAdmission::class)->completionCurrent($attempt, $task);
        }
        $instance = MachineOwnedInstance::query()->where('task_id', $task->id)->where('active_policy_id', $row->policy_id)->first();
        return $instance && ($task->evidence['forwarding_verified'] ?? false) === true && ($task->evidence['dns_changed'] ?? false) === true
            && MachineDnsTransition::query()->whereKey($task->evidence['dns_transition_id'] ?? '')->where('state', 'verified')
                ->where('to_instance_id', $instance->id)->exists();
    }

    public function noticeCurrent(Replacement $row, array $snapshot, bool $recovered): bool
    {
        $policy = Policy::query()->find($row->policy_id);
        $task = Task::query()->find($row->task_id);
        $account = $task ? MachineCloudAccount::query()->find($task->account_id) : null;
        $machine = $policy ? ServerMachine::query()->find($policy->machine_id) : null;
        $actor = $task ? User::query()->find($task->admin_id) : null;
        if (!$policy || !$task || !$account || !$machine || !$actor?->is_admin || $actor->banned
            || $policy->revision !== $row->policy_revision || $account->revision !== $task->account_revision
            || !$machine->is_active || !hash_equals($task->machine_authority, hash('sha256', (string) $machine->token))) return false;
        if (!$recovered) return ($snapshot['result'] ?? null) === $row->status && $this->stage($row, $task) === $row->status;
        $probe = $this->observer->summary($policy, now()->getTimestamp())['probe'];
        return $row->source_recovered_at !== null
            && ($probe['state'] ?? null) === 'healthy' && ($probe['installation_id'] ?? null) === $row->source_installation_id
            && ($probe['context_key'] ?? null) === $row->source_context_key;
    }

    public function refreshStatuses(): void
    {
        if (!$this->available()) return;
        $rows = Replacement::query()->where('created_at', '>=', time() - 7 * 86400)->where('status', '!=', 'completed')
            ->whereNull('source_recovered_at')->where('last_checked_at', '<=', time() - 30)->orderBy('last_checked_at')->limit(20)->get();
        foreach ($rows as $row) {
            $row->forceFill(['last_checked_at' => time()])->save();
            if ($task = Task::query()->find($row->task_id)) $this->sync($task);
        }
    }
}
