<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Persisted cleanup intent precedes DELETE. Recovery only reads back that exact resource.
class MachineCandidateRetry
{
    private const KEY = 'candidate_retry';
    private const READBACK_GAP = 10;

    public function pin(Policy $policy, string $workflow): ?array
    {
        return $workflow === MachineFailoverWorkflow::READINESS && $this->allowed($policy->id)
            ? ['schema' => 1, 'failure_threshold' => $policy->failure_threshold, 'cooldown_seconds' => $policy->cooldown_seconds] : null;
    }

    private function allowed(int $policy): bool
    {
        return config('machine_failover.allow_candidate_retry') === true
            && in_array($policy, config('machine_failover.allowed_candidate_retry_policy_ids', []), true);
    }

    public function pending(Attempt $attempt): bool
    {
        return isset($attempt->payload[self::KEY]['delete_dispatched_at']);
    }

    public function advance(Attempt $attempt, Task $task, Member $member, Execution $run, Policy $policy): ?string
    {
        if (!isset($member->payload[self::KEY])) return null;
        if (!$this->allowed($policy->id)) {
            if ($this->pending($attempt)) throw new CloudException('candidate_retry_disabled');
            return null;
        }
        $lock = Cache::lock('machine-cloud:account:' . $task->account_id, 120);
        try { $lock->block(2); }
        catch (LockTimeoutException) { throw new CloudException('account_reserved'); }
        try {
            $attempt->refresh(); $task->refresh();
            $journal = $attempt->payload[self::KEY] ?? [];
            if (!$this->pending($attempt) && ($task->bootstrap_state !== 'succeeded' || $task->status !== 'waiting_ipv4_probe')) {
                if ($journal) $this->save($attempt, ['state' => 'observing', 'failures' => 0,
                    'last_checked_at' => $journal['last_checked_at'] ?? 0]);
                return null;
            }
            $this->guard($attempt, $task, $member, $run, $policy);
            if (($journal['state'] ?? null) === 'confirmed') {
                $this->confirmed($attempt, $task);
                return 'candidate_retry_cooldown';
            }
            if (!isset($journal['delete_dispatched_at'])) {
                if ($task->status !== 'waiting_ipv4_probe' || $task->bootstrap_state !== 'succeeded') return null;
                $journal = $this->observe($journal, $task, $run, $policy);
                $this->save($attempt, $journal);
                if (($journal['failures'] ?? 0) < $policy->failure_threshold) return null;
                app(MachineFailoverNativeBridge::class)->assertDns($task, false);
                $client = app(MachineCandidateCleanupClient::class);
                $snapshot = $client->inspect($task);
                if ($snapshot === null) throw new CloudException('candidate_cleanup_identity_missing');
                if (!MachineIntentJson::same($this->identity($task), $this->identity($task->fresh())))
                    throw new CloudException('candidate_cleanup_identity_changed');
                $this->guard($attempt->fresh(), $task->fresh(), $member, $run, $policy);
                $journal = $this->observe($journal, $task, $run, $policy);
                $this->save($attempt, $journal);
                if (($journal['failures'] ?? 0) < $policy->failure_threshold) return 'checking_ipv4';
                $observed = $journal;
                // A crash after this write must never dispatch a second delete or a new create.
                $journal += ['identity' => $this->identity($task), 'delete_dispatched_at' => $this->clock(),
                    'retry_after_seconds' => $policy->cooldown_seconds];
                $journal['state'] = 'confirming'; $journal['absent_at'] = [];
                $this->save($attempt, $journal);
                $this->guard($attempt->fresh(), $task->fresh(), $member, $run, $policy);
                $dispatchReady = false;
                try {
                    $client->delete($task, function () use ($attempt, $task, $member, $run, $policy, &$dispatchReady) {
                        $this->guard($attempt->fresh(), $task->fresh(), $member, $run, $policy);
                        app(MachineFailoverNativeBridge::class)->assertDns($task->fresh(), false);
                        $this->failureCurrent($attempt->fresh()->payload[self::KEY], $task->fresh(), $run, $policy);
                        $dispatchReady = true;
                    });
                } catch (LockTimeoutException $error) {
                    if ($dispatchReady) throw $error;
                    // The adapter could not enter its identity lock: no DELETE was sent.
                    $this->save($attempt, $observed);
                    return 'checking_ipv4';
                } catch (CloudException $error) {
                    $journal['first_error'] ??= $this->reason($error);
                    if (!$dispatchReady) {
                        DB::table('v2_machine_provision_step')->insertOrIgnore(['task_id' => $task->id,
                            'state' => 'candidate_delete_aborted', 'reason' => $this->reason($error), 'created_at' => $this->clock()]);
                        $journal = ['state' => 'observing', 'failures' => 0, 'last_checked_at' => $journal['last_checked_at'],
                            'first_error' => $journal['first_error']];
                        $this->save($attempt, $journal);
                        if ($error->getMessage() === 'candidate_failure_no_longer_current') return 'checking_ipv4';
                        throw $error;
                    }
                    $this->save($attempt, $journal);
                }
                return 'confirming_candidate_cleanup';
            }
            if (($journal['next_check_at'] ?? 0) > $this->clock()) return 'confirming_candidate_cleanup';
            if (!MachineIntentJson::same($journal['identity'] ?? null, $this->identity($task))) throw new CloudException('candidate_cleanup_identity_changed');
            try { $snapshot = app(MachineCandidateCleanupClient::class)->inspect($task); }
            catch (CloudException $error) {
                $journal['first_error'] ??= $this->reason($error);
                $journal['absent_at'] = []; $journal['next_check_at'] = $this->clock() + max(self::READBACK_GAP,
                    $error instanceof CloudRequestFailure ? (int) ($error->decision['cooldown_seconds'] ?? 0) : 0);
                $this->save($attempt, $journal); return 'confirming_candidate_cleanup';
            }
            $this->guard($attempt->fresh(), $task->fresh(), $member, $run, $policy);
            if ($snapshot !== null) $journal['absent_at'] = [];
            else $journal['absent_at'][] = $this->clock();
            $journal['next_check_at'] = $this->clock() + self::READBACK_GAP;
            if (count($journal['absent_at']) >= 2) {
                $journal['state'] = 'confirmed'; $journal['confirmed_at'] = $this->clock();
                $journal['retry_at'] = $this->clock() + $journal['retry_after_seconds'];
                $journal['seal'] = $this->seal($journal);
                DB::transaction(function () use ($attempt, $task, $journal) {
                    $this->save($attempt, $journal);
                    $attempt->status = 'cleaned'; $attempt->save();
                    $task->fill(['status' => 'failed', 'reason' => 'candidate_ipv4_unreachable', 'finished_at' => $this->clock()])->save();
                    DB::table('v2_machine_provision_step')->insertOrIgnore(['task_id' => $task->id, 'state' => 'candidate_cleanup_confirmed',
                        'reason' => 'candidate_ipv4_unreachable', 'created_at' => $this->clock()]);
                });
                return 'candidate_retry_cooldown';
            }
            $this->save($attempt, $journal); return 'confirming_candidate_cleanup';
        } finally { $lock->release(); }
    }

    private function observe(array $journal, Task $task, Execution $run, Policy $policy): array
    {
        $machine = ServerMachine::query()->findOrFail($task->machine_id);
        $inventory = app(MachineAddressInventory::class);
        $row = $inventory->snapshots([$machine], true)[$machine->id][$task->installation_id] ?? [];
        $probeService = app(MachineEntryProbe::class); $config = $probeService->configuration();
        $probe = $probeService->normalizeReport($row['entry_probe'] ?? null);
        $snapshot = $inventory->probes([$task->installation_id => $row], $this->clock())[$task->installation_id] ?? [];
        $runtime = $row['runtime'] ?? []; $release = $run->payload['release'];
        $health = app(MachineCloudHealth::class)->snapshot(new MachineCloudResource(['machine_id' => $task->machine_id,
            'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority, 'public_ips' => $task->public_ips]), $task->dispatched_at);
        $ips = array_values(array_filter($task->public_ips ?? [], fn ($ip) => !str_contains($ip, ':')));
        $valid = $health['ready'] && $health['nodes'] === 'fresh' && $config['enabled'] && $probe !== null
            && ($snapshot['address_installation_id'] ?? null) === $task->installation_id
            && ($snapshot['valid_until'] ?? 0) >= $this->clock() && ($snapshot['checked_at'] ?? 0) > $task->dispatched_at
            && ($snapshot['checked_at'] ?? PHP_INT_MAX) <= $this->clock()
            && count($probe['targets']) === count($config['targets']) && count($ips) === 1
            && ($runtime['binary_sha256'] ?? null) === $release['binary_sha256']
            && ($runtime['version'] ?? null) === $release['version']
            && is_string($runtime['observation_session'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $runtime['observation_session']);
        if ($valid) foreach ($probe['targets'] as $target) {
            if (($target['source_ip'] ?? null) !== $ips[0] || $target['status'] !== 'unreachable') { $valid = false; break; }
        }
        if (!$valid) return ['state' => 'observing', 'failures' => 0, 'last_checked_at' => max($journal['last_checked_at'] ?? 0, $probe['checked_at'] ?? 0)];
        $context = hash('sha256', MachineIntentJson::encode([$this->identity($task), $config, $runtime['observation_session']]));
        $at = $probe['checked_at']; $last = $journal['last_checked_at'] ?? 0;
        if (($journal['context'] ?? null) !== $context) $journal['failures'] = 0;
        if ($at <= $last) return ($journal['context'] ?? null) === $context ? $journal
            : ['state' => 'observing', 'failures' => 0, 'last_checked_at' => $last];
        $continuous = ($journal['context'] ?? null) === $context && $at - $last >= $config['interval_seconds']
            && $at - $last <= min($policy->stale_after_seconds, $config['interval_seconds'] * 2 + 30);
        return ['state' => 'observing', 'context' => $context, 'last_checked_at' => $at,
            'failures' => $continuous ? min($policy->failure_threshold, ($journal['failures'] ?? 0) + 1) : 1,
            'threshold' => $policy->failure_threshold, 'source_ip' => $ips[0], 'probe_revision' => $config['revision']];
    }

    private function guard(Attempt $attempt, Task $task, Member $member, Execution $run, Policy $policy): void
    {
        app(MachineFailoverExecutionGate::class)->assertAllowed('candidate_cleanup');
        app(MachineFailoverNativeBridge::class)->assertAttempt($attempt, $task);
        if (isset($attempt->payload[self::KEY]['identity'])
            && !MachineIntentJson::same($attempt->payload[self::KEY]['identity'], $this->identity($task)))
            throw new CloudException('candidate_cleanup_identity_changed');
        if (!$this->allowed($policy->id) || MachineFailoverWorkflow::forRun($run) !== MachineFailoverWorkflow::READINESS
            || !MachineIntentJson::same($member->payload[self::KEY] ?? null, $this->pin($policy, MachineFailoverWorkflow::READINESS)))
            throw new CloudException('candidate_retry_disabled');
        if ($task->bootstrap_state !== 'succeeded' || !$task->external_id || !$task->provider_created_at || !$task->dispatched_at
            || !in_array($task->status, ['waiting_ipv4_probe', 'failed'], true)
            || isset($attempt->payload['instance_id']) || isset($attempt->payload['transition_id']) || !empty($attempt->payload['dns_set_runs'])
            || MachineOwnedInstance::query()->where('task_id', $task->id)->exists()
            || MachineCloudResource::query()->where('provider', $task->payload['plan']['provider'] ?? 'linode')
                ->where('external_id', $task->external_id)->exists())
            throw new CloudException('candidate_cleanup_scope_changed');
    }

    private function failureCurrent(array $journal, Task $task, Execution $run, Policy $policy): void
    {
        $current = $this->observe($journal, $task, $run, $policy);
        if (($current['failures'] ?? 0) < $policy->failure_threshold || ($current['context'] ?? null) !== ($journal['context'] ?? null))
            throw new CloudException('candidate_failure_no_longer_current');
    }

    public function ready(Attempt $attempt, Task $task): bool
    {
        $proof = $this->confirmed($attempt, $task);
        return $proof['retry_at'] <= $this->clock();
    }

    public function confirmed(Attempt $attempt, Task $task): array
    {
        $j = $attempt->payload[self::KEY] ?? [];
        if ($attempt->status !== 'cleaned' || $task->status !== 'failed' || $task->reason !== 'candidate_ipv4_unreachable'
            || !app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task)
            || ($j['state'] ?? null) !== 'confirmed' || !MachineIntentJson::same($j['identity'] ?? null, $this->identity($task))
            || !is_string($j['seal'] ?? null) || !hash_equals($j['seal'], $this->seal(array_diff_key($j, ['seal' => true])))
            || count($j['absent_at'] ?? []) !== 2 || $j['absent_at'][1] - $j['absent_at'][0] < self::READBACK_GAP
            || ($j['failures'] ?? 0) < ($j['threshold'] ?? PHP_INT_MAX)) throw new CloudException('candidate_cleanup_unconfirmed');
        return $j;
    }

    public function proof(Attempt $next): void
    {
        $p = $next->payload['candidate_replacement'] ?? [];
        $previous = Attempt::query()->find($next->payload['previous_attempt_id'] ?? '');
        $task = $previous?->task_id ? Task::query()->find($previous->task_id) : null;
        if (!$previous || !$task || $previous->execution_member_id !== $next->execution_member_id || $previous->ordinal + 1 !== $next->ordinal
            || $previous->account_id !== $next->account_id || $previous->account_revision !== $next->account_revision
            || ($p['from_attempt_id'] ?? null) !== $previous->id || ($p['cleanup_seal'] ?? null) !== $this->confirmed($previous, $task)['seal']
            || !$this->ready($previous, $task)) throw new CloudException('candidate_cleanup_unconfirmed');
    }

    public function assertEnabled(Member $member): void
    {
        app(MachineFailoverExecutionGate::class)->assertAllowed('candidate_retry');
        $policy = Policy::query()->findOrFail($member->policy_id);
        if (!$this->allowed($policy->id) || !MachineIntentJson::same($member->payload[self::KEY] ?? null,
            $this->pin($policy, MachineFailoverWorkflow::READINESS))) throw new CloudException('candidate_retry_disabled');
    }

    private function identity(Task $task): array
    {
        return $task->only(['id', 'account_id', 'account_revision', 'machine_id', 'installation_id', 'marker',
            'external_id', 'provider_created_at', 'public_ips', 'dispatched_at', 'plan_hash']);
    }

    private function save(Attempt $attempt, array $journal): void
    {
        DB::transaction(function () use ($attempt, $journal) {
            $current = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $task = Task::query()->findOrFail($current->task_id);
            app(MachineFailoverNativeBridge::class)->assertAttempt($current, $task);
            $payload = $current->payload; $payload[self::KEY] = $journal;
            $current->payload = $payload; $current->save();
        });
        $attempt->refresh();
    }

    private function seal(array $journal): string
    {
        $key = config('app.key');
        if (!is_string($key) || strlen($key) < 32) throw new CloudException('candidate_cleanup_authority_missing');
        return hash_hmac('sha256', 'candidate-cleanup-v1:' . MachineIntentJson::encode($journal), $key);
    }

    private function reason(CloudException $error): string
    {
        return preg_match('/^[a-z_]{1,60}$/D', $error->getMessage()) ? $error->getMessage() : 'candidate_cleanup_uncertain';
    }

    protected function clock(): int { return time(); }

    public function summary(Attempt $attempt): ?array
    {
        $j = $attempt->payload[self::KEY] ?? null;
        return is_array($j) ? array_intersect_key($j, array_flip(['state', 'failures', 'threshold', 'last_checked_at',
            'delete_dispatched_at', 'absent_at', 'confirmed_at', 'retry_at', 'first_error'])) : null;
    }
}
