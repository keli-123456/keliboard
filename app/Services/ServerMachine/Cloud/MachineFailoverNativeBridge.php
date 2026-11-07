<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Dns\DnsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Internal, deliberately unarmed adapter. Simulation facts are never accepted as native receipts.
class MachineFailoverNativeBridge
{
    private const FINISHED = ['completed', 'cancelled', 'retained', 'needs_review'];

    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverExecutionGate $gate) {}

    public function available(): bool
    {
        foreach (['execution', 'execution_member', 'attempt'] as $name) if (!Schema::hasTable('v2_machine_failover_' . $name)) return false;
        return $this->registry->available() && app(MachineCloudCredentialHealth::class)->available()
            && app(MachineInstanceAcceptance::class)->available() && app(MachineReplacementService::class)->available();
    }

    public function start(int $service, int $revision, array $planIds, int $releaseId, string $request, int $admin): array
    {
        $this->gate->assertAllowed('start'); $this->registry->actor($admin);
        if (!$this->available()) throw new CloudException('migration_required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $request) || !array_is_list($planIds) || !$planIds || count($planIds) > 10
            || count(array_unique($planIds, SORT_REGULAR)) !== count($planIds)) throw new CloudException('invalid_native_intent');
        foreach ($planIds as $id) if (!is_int($id) || $id < 1) throw new CloudException('invalid_native_intent');
        sort($planIds);
        $result = $this->registry->locked(fn () => DB::transaction(function () use ($service, $revision, $planIds, $releaseId, $request, $admin) {
            $this->gate->assertAllowed('start'); $this->registry->actor($admin);
            $snapshot = $this->registry->snapshot($service, $revision); $release = $this->release($releaseId);
            $intent = $this->hash([$service, $revision, $planIds, $release]); $key = $this->hash([$admin, $request]);
            if ($old = Execution::query()->where('request_key', $key)->first()) {
                if (!hash_equals($old->intent_hash, $intent)) throw new CloudException('request_conflict');
                return $this->report($old->id, $admin);
            }
            if (Execution::query()->where('service_id', $service)->where('status', 'running')->exists()) throw new CloudException('run_active');
            $rows = []; $seen = [];
            foreach ($planIds as $id) {
                $plan = Plan::query()->findOrFail($id);
                $match = array_values(array_filter($snapshot['members'], fn ($row) => $row['policy_id'] === $plan->policy_id));
                if (count($match) !== 1 || $match[0]['provider'] !== 'linode') throw new CloudException('native_provider_unsupported');
                if (isset($seen[$match[0]['id']])) throw new CloudException('duplicate_member');
                $seen[$match[0]['id']] = true;
                $policy = Policy::query()->findOrFail($plan->policy_id);
                $input = $this->input($policy, app(MachineFailoverCredentials::class)->context($policy));
                $source = app(MachineReplacementService::class)->validate($id, $input);
                $active = MachineOwnedInstance::query()->where('active_policy_id', $policy->id)->firstOrFail();
                $dns = MachineOwnedDns::query()->where('policy_id', $policy->id)->first();
                if (!$dns || $dns->status !== 'live' || !$dns->record_id || $dns->active_instance_id !== $active->id || $dns->ip !== $active->ip
                    || $dns->credential_id !== $policy->dns_credential_id || $dns->credential_revision !== $policy->dns_credential_revision
                    || $dns->hostname !== $policy->dns_hostname) throw new CloudException('dns_ownership_changed');
                if (Member::query()->where('episode_key', $source['episode_key'])->exists()) throw new CloudException('replacement_episode_used');
                $rows[] = ['member_id' => $match[0]['id'], 'policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'plan_id' => $id,
                    'episode_key' => $source['episode_key'], 'payload' => ['source' => $source, 'priority' => $match[0]['priority'],
                        'source_instance_id' => $active->id, 'dns' => $dns->only(['id', 'revision', 'zone', 'record_id', 'marker', 'ip', 'active_instance_id'])]];
            }
            $run = Execution::query()->create(['id' => (string) Str::uuid(), 'service_id' => $service, 'service_revision' => $revision,
                'admin_id' => $admin, 'request_key' => $key, 'intent_hash' => $intent, 'context_hash' => $this->registry->fingerprint($snapshot),
                'payload' => ['snapshot' => $snapshot, 'release' => $release], 'deadline_at' => time() + 1200]);
            foreach ($rows as $row) Member::query()->create($row + ['execution_id' => $run->id]);
            return $this->report($run->id, $admin);
        }));
        app(\App\Services\ServerMachine\MachineOperationNotifier::class)->failoverRun($result['id']);
        app(MachineFailoverRunner::class)->enroll($result['id']);
        return $result;
    }

    public function advance(string $id, int $admin, bool $singleMember = false): array
    {
        $this->gate->assertAllowed('advance'); $this->registry->actor($admin);
        $run = Execution::query()->findOrFail($id);
        if ($run->admin_id !== $admin) throw new CloudException('permission_revoked');
        if ($run->status !== 'running') return $this->report($id, $admin);
        $runner = app(MachineFailoverRunner::class); $runner->guard($id);
        $members = Member::query()->where('execution_id', $id)->get();
        $members = $singleMember ? $runner->order($id, $members) : $members->sortBy(fn ($row) => [$row->payload['priority'], $row->id]);
        foreach ($members as $row) {
            if (in_array($row->phase, self::FINISHED, true)) continue;
            Cache::lock('machine-native:member:' . $row->id, 300)->block(2, function () use ($row, $run) {
                $current = $row->fresh();
                if (in_array($current->phase, self::FINISHED, true)) return;
                try { $this->step($current, $run->fresh()); }
                catch (CloudException|DnsException $error) {
                    $reason = $error->getMessage();
                    if (in_array($reason, ['native_lease_lost', 'native_stop_requested', 'runner_disabled'], true)) throw $error;
                    if (in_array($reason, ['parallel_budget', 'account_reserved'], true)) return;
                    $attempt = $row->fresh()->attempt_id ? Attempt::query()->find($row->fresh()->attempt_id) : null;
                    $task = $attempt?->task_id ? Task::query()->find($attempt->task_id) : null;
                    if ($reason === 'source_recovered') {
                        if ($task && !$task->dispatched_at) { app(MachineProvisionService::class)->cancel($task->id, $run->admin_id); $task->refresh(); }
                        if ($attempt) $this->attemptStatus($attempt, $task?->dispatched_at ? 'retained' : 'cancelled');
                        $this->phase($row, $task?->dispatched_at ? 'retained' : 'cancelled', $reason);
                    } else $this->phase($row, 'needs_review', preg_match('/^[a-z_]{1,60}$/D', $reason) ? $reason : 'internal_failure');
                } catch (\Throwable) { $this->phase($row, 'needs_review', 'internal_failure'); }
            });
            if ($singleMember) { $runner->cursor($id, $row->id); break; }
        }
        DB::transaction(function () use ($runner, $id, $run) {
            $runner->guard($id);
            $phases = Member::query()->where('execution_id', $id)->pluck('phase')->all();
            if (!array_diff($phases, self::FINISHED)) $run->fill(['status' => in_array('needs_review', $phases, true) ? 'needs_review'
                : (count(array_unique($phases)) === 1 ? $phases[0] : 'stopped')])->save();
        });
        app(\App\Services\ServerMachine\MachineOperationNotifier::class)->failoverRun($id);
        return $this->report($id, $admin);
    }

    private function step(Member $member, Execution $run): void
    {
        $this->context($run); $policy = Policy::query()->findOrFail($member->policy_id);
        $attempt = $member->attempt_id ? Attempt::query()->findOrFail($member->attempt_id) : $this->reserve($member, $run, $policy);
        if ($attempt->status === 'rejected') {
            $attempt = $this->reserve($member->fresh(), $run, $policy, $attempt);
        }
        $this->assertAttempt($attempt);
        $provision = app(MachineProvisionService::class);
        if (!$attempt->task_id) $provision->queueCoordinated($attempt->payload['plan'], $attempt->request_id, $run->admin_id, $attempt->id);
        $attempt->refresh(); $task = Task::query()->findOrFail($attempt->task_id);
        $this->assertTask($task);
        if (!in_array($task->status, Task::TERMINAL, true)) $provision->process($task->id);
        $task->refresh();
        app(MachineFailoverRunner::class)->guard($run->id);
        if ($task->status === 'failed' && $this->definitelyRejected($task)) {
            $this->attemptStatus($attempt, 'rejected'); $this->phase($member, 'retry_ready', $task->reason); return;
        }
        if (in_array($task->status, ['failed', 'cancelled', 'needs_review'], true)) {
            $this->attemptStatus($attempt, $task->status);
            $this->phase($member, $task->status === 'cancelled' ? 'cancelled' : 'needs_review', $task->reason); return;
        }
        if (!in_array($task->status, ['awaiting_acceptance', 'accepted'], true)) {
            $this->attemptStatus($attempt, $task->dispatched_at ? 'dispatched' : 'bound');
            $this->phase($member, $task->status === 'outcome_unknown' ? 'reconciling_create' : 'creating', $task->reason); return;
        }
        $payload = $attempt->payload;
        $transition = isset($payload['transition_id']) ? MachineDnsTransition::query()->findOrFail($payload['transition_id']) : null;
        if (!$transition?->dispatched_at) $this->source($member, $policy);
        $acceptance = app(MachineInstanceAcceptance::class);
        $instance = MachineOwnedInstance::query()->where('task_id', $task->id)->first();
        if (!$instance) {
            $ip = $this->candidateIp($task);
            $row = $acceptance->enroll($policy->id, $policy->revision, $task->id, $run->payload['release']['id'], $ip, $run->admin_id);
            $instance = MachineOwnedInstance::query()->findOrFail($row['id']);
        }
        $this->identity($attempt, $task, $instance);
        if ($transition?->state === 'verified') {
            $this->receipt($attempt, 'dns', $task->fresh(), $instance->fresh(), $transition);
            $this->attemptStatus($attempt, 'completed'); $this->phase($member, 'completed'); return;
        }
        if (!$instance->verification || $instance->verified_until <= time()) {
            if (isset($payload['verification_started_at'])) throw new CloudException('verification_requires_review');
            $this->checkpoint($attempt, 'verification_started_at', time());
            $acceptance->verify($instance->id, $policy->revision, $instance->revision, $run->admin_id);
            $instance->refresh();
            if (!$instance->verification || $instance->verified_until <= time()) throw new CloudException('forwarding_not_verified');
        }
        $acceptance->requireVerified($instance, $policy, $run->admin_id);
        $this->receipt($attempt, 'forwarding', $task, $instance);
        $this->assertDns($task, $transition?->dispatched_at !== null);
        if (!$transition) {
            $dns = $member->payload['dns'];
            $result = app(MachineOwnedDnsService::class)->prepare($instance->id, $policy->revision, $instance->revision, $dns['zone'],
                substr($this->hash([$attempt->id, 'dns']), 0, 32), null, $run->admin_id);
            $this->checkpoint($attempt, 'transition_id', $result['id']);
            $this->phase($member, 'awaiting_dns'); return;
        }
        app(MachineOwnedDnsService::class)->execute($transition->id, $run->admin_id);
        $transition->refresh();
        if ($transition->state === 'verified') {
            $this->receipt($attempt, 'dns', $task->fresh(), $instance->fresh(), $transition);
            $this->attemptStatus($attempt, 'completed'); $this->phase($member, 'completed');
        } else $this->phase($member, 'reconciling_dns', 'original_intent_only');
    }

    private function reserve(Member $member, Execution $run, Policy $policy, ?Attempt $previous = null): Attempt
    {
        return $this->registry->locked(fn () => DB::transaction(function () use ($member, $run, $policy, $previous) {
            $this->context($run->fresh()); $member = $member->fresh();
            if ($member->attempt_id !== $previous?->id) {
                if ($previous === null && $member->attempt_id) return Attempt::query()->findOrFail($member->attempt_id);
                throw new CloudException('native_lineage_changed');
            }
            $this->source($member, $policy);
            $credentials = app(MachineFailoverCredentials::class);
            if ($previous) {
                $this->gate->assertAllowed('rotate'); $task = Task::query()->findOrFail($previous->task_id);
                if (!$this->definitelyRejected($task)) throw new CloudException('original_intent_only');
                $decision = $credentials->rotationPlan($policy, $task, $task->evidence['credential_failure']);
                if ($decision['action'] !== 'rotate' || !$decision['next_account_id']) throw new CloudException('credential_group_exhausted');
                $choice = $credentials->context($policy, $task);
                $choice['account_id'] = $decision['next_account_id']; $choice['credential_pool']['account_revision'] = $decision['next_account_revision'];
            } else $choice = $credentials->context($policy);
            $plan = $this->input($policy, $choice); $account = $credentials->assertPlan($plan);
            app(MachineCloudCredentialHealth::class)->assertEligible($account, $plan);
            $service = $run->payload['snapshot']['service'];
            $members = Member::query()->whereIn('execution_id', Execution::query()->where('service_id', $run->service_id)->select('id'))->pluck('id');
            $history = Attempt::query()->whereIn('execution_member_id', $members);
            $currentMembers = Member::query()->where('execution_id', $run->id)->pluck('id');
            if (Attempt::query()->whereIn('execution_member_id', $currentMembers)->count() >= $service['attempt_limit']) throw new CloudException('attempt_budget');
            $cost = (int) round($policy->monthly_limit_usd * 100);
            $released = \Illuminate\Support\Facades\Schema::hasTable('v2_machine_failover_budget_release')
                ? (int) \App\Models\MachineFailoverBudgetRelease::query()->where('service_id', $run->service_id)->sum('amount_cents') : 0;
            if ($cost <= 0 || (clone $history)->sum('reserved_cents') - $released + $cost > (int) round($service['monthly_cap_usd'] * 100)) throw new CloudException('shared_monthly_budget');
            app(MachineFailoverBudget::class)->assertCapacity($run->service_id, $run->service_revision, $cost);
            $active = Attempt::query()->whereNotIn('status', ['rejected', 'failed', 'cancelled', 'completed', 'retained', 'released'])->get();
            if ($active->whereIn('execution_member_id', $members->all())->count() >= $service['max_parallel']) throw new CloudException('parallel_budget');
            $ids = app(MachineCloudCredentialHealth::class)->sameAccountIds($account);
            if ($active->whereIn('account_id', $ids)->isNotEmpty()) throw new CloudException('account_reserved');
            $attempt = Attempt::query()->create(['id' => (string) Str::uuid(), 'execution_member_id' => $member->id,
                'ordinal' => Attempt::query()->where('execution_member_id', $member->id)->count() + 1, 'request_id' => bin2hex(random_bytes(16)),
                'account_id' => $account->id, 'account_revision' => $account->revision, 'reserved_cents' => $cost,
                'payload' => ['plan' => $plan, 'previous_attempt_id' => $previous?->id]]);
            $member->fill(['attempt_id' => $attempt->id, 'phase' => 'creating', 'reason' => null])->save();
            return $attempt;
        }));
    }

    public function assertAttempt(Attempt $attempt, ?Task $task = null, bool $requireLease = true): void
    {
        $this->gate->assertAllowed('native_task');
        if (\Illuminate\Support\Facades\Schema::hasTable('v2_machine_failover_budget_release')
            && \App\Models\MachineFailoverBudgetRelease::query()->where('attempt_id', $attempt->id)->exists()) throw new CloudException('budget_released');
        if (app(MachineFailoverReview::class)->available() && \App\Models\MachineFailoverReviewAction::query()
            ->where('attempt_id', $attempt->id)->whereIn('operation', ['claim', 'close'])->exists()) throw new CloudException('review_hold');
        $current = Attempt::query()->find($attempt->id);
        if (!$current || $current->only(['task_id', 'account_id', 'account_revision', 'payload']) !== $attempt->only(['task_id', 'account_id', 'account_revision', 'payload'])) throw new CloudException('native_lineage_changed');
        if ($task === null && $attempt->task_id) $task = Task::query()->findOrFail($attempt->task_id);
        $member = Member::query()->findOrFail($attempt->execution_member_id); $run = Execution::query()->findOrFail($member->execution_id);
        $this->context($run, $requireLease);
        if ($member->attempt_id !== $attempt->id || in_array($member->phase, self::FINISHED, true)) throw new CloudException('native_lineage_changed');
        $policy = Policy::query()->findOrFail($member->policy_id); $plan = ProvisionPlan::normalize($attempt->payload['plan']);
        $credential = app(MachineFailoverCredentials::class); $account = $credential->assertPlan($plan);
        if ($account->id !== $attempt->account_id || $account->revision !== $attempt->account_revision || $account->provider !== 'linode'
            || $plan !== $this->input($policy, array_intersect_key($plan, array_flip(['account_id', 'provider', 'credential_pool'])))) throw new CloudException('native_lineage_changed');
        if ($task && ($task->admin_id !== $run->admin_id || !$this->bindingCurrent($attempt, $task))) throw new CloudException('native_lineage_changed');
        if (!$task?->dispatched_at) $this->source($member, $policy);
    }

    public function bind(Attempt $attempt, Task $task): void
    {
        $row = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
        if ($row->task_id && $row->task_id !== $task->id) throw new CloudException('native_lineage_changed');
        $payload = $row->payload; $identity = $this->taskIdentity($task);
        if (isset($payload['task_identity']) && !hash_equals($payload['task_identity'], $identity)) throw new CloudException('native_lineage_changed');
        $payload['task_identity'] = $identity;
        $row->task_id = $task->id; $row->status = 'bound'; $row->payload = $payload; $row->save();
        $this->assertAttempt($row, $task);
    }

    public function assertTask(Task $task, bool $requireLease = true): void
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if ($id === null) return;
        if (!is_string($id)) throw new CloudException('native_lineage_changed');
        $attempt = Attempt::query()->findOrFail($id); $this->assertAttempt($attempt, $task, $requireLease);
        if (!$task->dispatched_at) {
            $member = Member::query()->findOrFail($attempt->execution_member_id);
            app(MachineReplacementService::class)->validate($member->plan_id, $task->payload['plan'], $task, $attempt);
        }
    }

    public function assertDns(Task $task, bool $reconcile): void
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if ($id === null) return;
        $this->gate->assertAllowed($reconcile ? 'dns_readback' : 'dns_write');
        $attempt = Attempt::query()->findOrFail($id); $this->assertAttempt($attempt, $task);
        $member = Member::query()->findOrFail($attempt->execution_member_id); $policy = Policy::query()->findOrFail($member->policy_id);
        if (!$reconcile) {
            $this->source($member, $policy); $expected = $member->payload['dns'];
            $dns = MachineOwnedDns::query()->find($expected['id']);
            if (!$dns || $dns->only(array_keys($expected)) !== $expected) throw new CloudException('dns_ownership_changed');
        }
    }

    public function assertDnsIntent(Task $task, string $zone, string $request, ?string $recovery): void
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if ($id === null) return;
        $this->assertDns($task, false); $attempt = Attempt::query()->findOrFail($id);
        $member = Member::query()->findOrFail($attempt->execution_member_id);
        if ($recovery !== null || $zone !== $member->payload['dns']['zone'] || $request !== substr($this->hash([$id, 'dns']), 0, 32)) throw new CloudException('native_receipt_mismatch');
    }

    public function assertDnsTransition(Task $task, MachineDnsTransition $transition): void
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if ($id === null) return;
        if (!$this->transitionCurrent(Attempt::query()->findOrFail($id), $task, $transition)) throw new CloudException('native_receipt_mismatch');
        $this->assertDns($task, $transition->dispatched_at !== null);
    }

    public function transitionCurrent(Attempt $attempt, Task $task, MachineDnsTransition $transition): bool
    {
        try {
            $member = Member::query()->findOrFail($attempt->execution_member_id); $run = Execution::query()->findOrFail($member->execution_id);
            $expected = $member->payload['dns']; $instance = MachineOwnedInstance::query()->where('task_id', $task->id)->firstOrFail();
            return $this->bindingCurrent($attempt, $task)
                && $transition->request_key === hash('sha256', $run->admin_id . ':' . substr($this->hash([$attempt->id, 'dns']), 0, 32))
                && $transition->admin_id === $run->admin_id && $transition->recovery_of === null && $transition->dns_id === $expected['id']
                && $transition->dns_revision === $expected['revision'] && $transition->from_instance_id === $member->payload['source_instance_id']
                && $transition->from_ip === $expected['ip'] && $transition->to_instance_id === $instance->id && $transition->to_ip === $instance->ip
                && $transition->policy_revision === $member->policy_revision
                && (!isset($attempt->payload['transition_id']) || $attempt->payload['transition_id'] === $transition->id);
        } catch (\Throwable) { return false; }
    }

    public function definitelyRejected(Task $task): bool
    {
        $failure = $task->evidence['credential_failure'] ?? [];
        return $task->status === 'failed' && !$task->external_id && $task->bootstrap_state === null
            && ($task->payload['coordinated_attempt'] ?? null) !== null
            && in_array($task->evidence['credential_failure_operation'] ?? null, ['create', 'preflight'], true)
            && ($failure['outcome'] ?? null) === 'rejected' && ($failure['rotation_candidate'] ?? false) === true
            && ($failure['requires_review'] ?? true) === false;
    }

    public function bindingCurrent(Attempt $attempt, Task $task): bool
    {
        try {
            return $attempt->task_id === $task->id && $task->account_id === $attempt->account_id && $task->account_revision === $attempt->account_revision
                && ($task->payload['coordinated_attempt'] ?? null) === $attempt->id
                && ProvisionPlan::normalize($task->payload['plan']) === ProvisionPlan::normalize($attempt->payload['plan'])
                && hash_equals($attempt->payload['task_identity'] ?? '', $this->taskIdentity($task));
        } catch (\Throwable) { return false; }
    }

    public function mayFinishRejected(Task $task, CloudException $error): bool
    {
        if (!$error instanceof CloudRequestFailure || !isset($task->payload['coordinated_attempt']) || $task->external_id || $task->bootstrap_state !== null) return false;
        try { $this->assertAttempt(Attempt::query()->findOrFail($task->payload['coordinated_attempt']), $task); }
        catch (\Throwable) { return false; }
        return $error->decision['outcome'] === 'rejected' && $error->decision['rotation_candidate'] && !$error->decision['requires_review'];
    }

    public function claimsEpisode(string $episode, ?Attempt $attempt = null): bool
    {
        if (!Schema::hasTable('v2_machine_failover_execution_member')) return false;
        return Member::query()->where('episode_key', $episode)->when($attempt, fn ($query) => $query->where('id', '!=', $attempt->execution_member_id))->exists();
    }

    private function context(Execution $run, bool $requireLease = true): void
    {
        $this->gate->assertAllowed('context'); app(MachineFailoverRunner::class)->guard($run->id, $requireLease); $this->registry->actor($run->admin_id);
        if ($run->status !== 'running' || $run->deadline_at <= time()) throw new CloudException('native_intent_expired');
        if (!hash_equals($run->context_hash, $this->registry->fingerprint($this->registry->snapshot($run->service_id, $run->service_revision)))) throw new CloudException('native_context_changed');
        if ($this->release($run->payload['release']['id']) !== $run->payload['release']) throw new CloudException('release_changed');
    }

    private function source(Member $member, Policy $policy): void
    {
        $summary = app(MachineFailoverObserver::class)->summary($policy, time()); $probe = $summary['probe']; $source = $member->payload['source'];
        if (($probe['installation_id'] ?? null) !== $source['source_installation_id'] || ($probe['context_key'] ?? null) !== $source['source_context_key']) throw new CloudException('replacement_evidence_changed');
        if (($probe['state'] ?? null) === 'healthy') throw new CloudException('source_recovered');
        if (!$summary['evidence_current'] || !$summary['threshold_reached'] || ($probe['state'] ?? null) !== 'failed'
            || ($summary['plan']['evidence']['episode_key'] ?? null) !== $member->episode_key) throw new CloudException('replacement_evidence_changed');
    }

    private function input(Policy $policy, array $credential): array
    {
        return ProvisionPlan::normalize($credential + ['machine_id' => $policy->machine_id, 'slot' => $policy->slot,
            'panel_origin' => app(ProvisionBootstrap::class)->configuredOrigin(), 'region' => $policy->region, 'size' => $policy->size,
            'image' => $policy->image, 'authorized_keys' => [], 'monthly_limit_usd' => $policy->monthly_limit_usd]);
    }

    private function release(int $id): array
    {
        $row = ServerMachineRelease::query()->find($id);
        if (!$row || $row->component !== 'kelinode-rs' || $row->status !== 'active' || $row->platform !== 'linux-x86_64'
            || !preg_match('/^[a-f0-9]{64}$/D', $row->binary_sha256 ?? '')) throw new CloudException('release_unavailable');
        return $row->only(['id', 'version', 'binary_sha256']);
    }

    private function candidateIp(Task $task): string
    {
        $inventory = app(\App\Services\ServerMachine\MachineAddressInventory::class);
        $machine = \App\Models\ServerMachine::query()->findOrFail($task->machine_id);
        $rows = $inventory->snapshots([$machine])[$machine->id] ?? [];
        $probe = $inventory->probes($rows, time())[$task->installation_id] ?? [];
        foreach ($task->public_ips ?? [] as $ip) if (!str_contains($ip, ':') && ($probe['addresses'][$ip]['status'] ?? null) === 'reachable') return $ip;
        throw new CloudException('candidate_address_mismatch');
    }

    private function identity(Attempt $attempt, Task $task, MachineOwnedInstance $instance): void
    {
        $this->assertAttempt($attempt->fresh(), $task);
        $member = Member::query()->findOrFail($attempt->execution_member_id); $run = Execution::query()->findOrFail($member->execution_id);
        $release = $run->payload['release'];
        if ($instance->task_id !== $task->id || $instance->policy_id !== $member->policy_id || $instance->policy_revision !== $member->policy_revision
            || $instance->installation_id !== $task->installation_id || $instance->release_id !== $release['id'] || $instance->binary_sha256 !== $release['binary_sha256']
            || $instance->version !== $release['version'] || !in_array($instance->ip, $task->public_ips ?? [], true)) throw new CloudException('native_receipt_mismatch');
    }

    private function taskIdentity(Task $task): string
    {
        return $this->hash([$task->id, $task->admin_id, $task->account_id, $task->account_revision, $task->machine_id, $task->machine_authority,
            $task->slot, $task->installation_id, $task->callback_hash, $task->marker, $task->plan_hash, $this->hash($task->payload['request'])]);
    }

    private function receipt(Attempt $attempt, string $stage, Task $task, MachineOwnedInstance $instance, ?MachineDnsTransition $dns = null): void
    {
        $this->identity($attempt->fresh(), $task, $instance); $member = Member::query()->findOrFail($attempt->execution_member_id);
        $check = \App\Models\MachineAcceptanceCheck::query()->where('instance_id', $instance->id)->where('state', 'passed')
            ->where('receipt_sha256', $instance->verification['receipt_sha256'] ?? '')->latest('issued_at')->first();
        if (!$check || $check->policy_revision !== $member->policy_revision || ($instance->verification['cleanup_complete'] ?? false) !== true
            || !$instance->verification['context']) throw new CloudException('native_receipt_mismatch');
        $proof = ['context' => $this->hash([$member->execution_id, $member->id, $attempt->id, $task->id, $task->account_id, $task->account_revision,
            $task->installation_id, $task->marker, $task->external_id, $task->provider_created_at, $instance->id, $instance->ip, $instance->binary_sha256]),
            'configuration' => $instance->verification['context']['configuration_fingerprint'], 'check_id' => $check->id,
            'receipt' => $check->receipt_sha256];
        if ($dns) {
            $this->assertDnsTransition($task, $dns);
            if (!hash_equals($attempt->fresh()->payload['forwarding_receipt'] ?? '', $this->hash($proof))) throw new CloudException('native_receipt_mismatch');
            $expected = $member->payload['dns'];
            $record = MachineOwnedDns::query()->find($dns->dns_id);
            if ($dns->state !== 'verified' || !$dns->dispatched_at || $dns->recovery_of !== null || $dns->dns_id !== $expected['id']
                || $dns->dns_revision !== $expected['revision'] || $dns->policy_revision !== $member->policy_revision
                || $dns->from_instance_id !== $member->payload['source_instance_id'] || $dns->from_ip !== $expected['ip']
                || $dns->to_instance_id !== $instance->id || $dns->to_ip !== $instance->ip || $record?->active_instance_id !== $instance->id
                || $record?->record_id !== $expected['record_id'] || $task->status !== 'accepted' || $instance->active_policy_id !== $member->policy_id
                || ($task->evidence['dns_transition_id'] ?? null) !== $dns->id) throw new CloudException('native_receipt_mismatch');
            $proof['dns'] = $dns->id;
        } elseif ($instance->verified_until <= time()) throw new CloudException('acceptance_expired');
        $this->checkpoint($attempt, $stage . '_receipt', $this->hash($proof));
    }

    private function checkpoint(Attempt $attempt, string $key, mixed $value): void
    {
        DB::transaction(function () use ($attempt, $key, $value) {
            app(MachineFailoverRunner::class)->guard(Member::query()->findOrFail($attempt->execution_member_id)->execution_id);
            $row = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail(); $payload = $row->payload;
            if (array_key_exists($key, $payload) && $payload[$key] !== $value) throw new CloudException('native_receipt_mismatch');
            $payload[$key] = $value; $row->payload = $payload; $row->save();
        });
        $attempt->refresh();
    }

    private function phase(Member $member, string $phase, ?string $reason = null): void
    {
        DB::transaction(function () use ($member, $phase, $reason) {
            app(MachineFailoverRunner::class)->guard($member->execution_id);
            $member->fill(['phase' => $phase, 'reason' => $reason])->save();
        });
    }
    private function attemptStatus(Attempt $attempt, string $status): void
    {
        DB::transaction(function () use ($attempt, $status) {
            app(MachineFailoverRunner::class)->guard(Member::query()->findOrFail($attempt->execution_member_id)->execution_id);
            $attempt->fill(['status' => $status])->save();
        });
    }
    private function hash(mixed $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }

    public function report(string $id, int $admin): array
    {
        $this->registry->actor($admin); $run = Execution::query()->findOrFail($id);
        if ($run->admin_id !== $admin) throw new CloudException('permission_revoked');
        $members = Member::query()->where('execution_id', $id)->get(); $attempts = Attempt::query()->whereIn('execution_member_id', $members->pluck('id'))->get();
        return ['id' => $run->id, 'status' => $run->status, 'execution_enabled' => false, 'automatic_rotation' => false, 'dns_cutover' => false,
            'attempts' => $attempts->count(), 'reserved_cents' => $attempts->sum('reserved_cents'), 'reservations_released' => false,
            'price_verified' => false, 'baseline_cost_included' => false,
            'members' => $members->map(fn ($row) => $row->only(['id', 'member_id', 'policy_id', 'phase', 'reason']) + ['attempts' => $attempts
                ->where('execution_member_id', $row->id)->map(fn ($attempt) => $attempt->only(['id', 'ordinal', 'task_id', 'status', 'reserved_cents']))->values()->all()])->all()];
    }
}
