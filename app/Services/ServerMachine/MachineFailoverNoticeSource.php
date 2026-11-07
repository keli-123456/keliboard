<?php
namespace App\Services\ServerMachine;

use App\Models\MachineAcceptanceCheck;
use App\Models\MachineCloudAccount;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverBudgetRelease;
use App\Models\MachineFailoverCostSnapshot;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineFailoverReviewAction;
use App\Models\MachineFailoverService;
use App\Models\MachineOperationNotice;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachine;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Local journal reads only. This path never advances execution or queries a cloud provider.
class MachineFailoverNoticeSource
{
    private const STATE = 'v2_machine_failover_notice_state';
    private const POLICY = 'v2_machine_operation_notice_policy';
    private const FINISHED = ['completed', 'cancelled', 'retained', 'needs_review'];
    private const BUDGET = ['attempt_budget', 'shared_monthly_budget', 'monthly_budget', 'budget_exceeded', 'daily_budget', 'cost_baseline_required'];

    public function available(): bool
    {
        foreach ([self::STATE, 'v2_machine_failover_execution', 'v2_machine_failover_execution_member', 'v2_machine_failover_attempt',
            'v2_machine_failover_service', 'v2_machine_failover_review_action', 'v2_machine_failover_budget_release', 'v2_machine_failover_cost_snapshot',
            'v2_machine_acceptance_check', 'v2_machine_owned_instance', 'v2_machine_owned_dns', 'v2_machine_dns_transition',
            'v2_machine_failover_policy', 'v2_machine_provision_task', 'v2_machine_cloud_account', 'v2_server_machine'] as $table)
            if (!Schema::hasTable($table)) return false;
        return DB::table(self::POLICY)->where('scope', 'failover')->exists();
    }

    public function baseline(): void
    {
        Member::query()->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $member) {
                $facts = $this->facts($member->id);
                if (!$facts) continue;
                DB::table(self::STATE)->updateOrInsert(['member_id' => $member->id], ['signals' => json_encode($this->keys($facts)),
                    'progress_at' => time(), 'risk_at' => isset($facts['signals']['risk']) ? time() : 0, 'next_scan_at' => $this->nextScan($facts), 'created_at' => time(), 'updated_at' => time()]);
            }
        });
    }

    public function due(): array
    {
        if (!$this->available() || !DB::table(self::POLICY)->where('scope', 'failover')->value('enabled')) return [];
        return DB::table('v2_machine_failover_execution_member as m')->leftJoin(self::STATE . ' as n', 'n.member_id', '=', 'm.id')
            ->where(fn ($q) => $q->whereNull('n.member_id')->orWhere('n.next_scan_at', '<=', time()))
            ->orderByRaw('COALESCE(n.next_scan_at, 0)')->orderBy('m.id')->limit(100)->pluck('m.id')->map(fn ($id) => (int) $id)->all();
    }

    public function capture(int $id, callable $emit): void
    {
        $policy = DB::table(self::POLICY)->where('scope', 'failover')->lockForUpdate()->first();
        if (!$policy?->enabled) return;
        $facts = $this->facts($id);
        if (!$facts) return;
        $state = DB::table(self::STATE)->where('member_id', $id)->lockForUpdate()->first();
        $seen = $state ? json_decode($state->signals, true, 512, JSON_THROW_ON_ERROR) : [];
        $progressAt = (int) ($state->progress_at ?? 0); $riskAt = (int) ($state->risk_at ?? 0);
        foreach ($facts['signals'] as $kind => $signal) {
            $same = ($seen[$kind] ?? null) === $signal['key'];
            if ($same && ($kind !== 'risk' || $riskAt > time() - 86400)) continue;
            if ($kind === 'stage' && $signal['phase'] === 'progress' && $progressAt > time() - 600) continue;
            if ($kind === 'risk' && ($riskAt > time() - 86400 || $facts['risk_since'] > time() - 3600)) continue;
            $emit($signal['phase'], $this->context($facts, $kind, $signal), $signal['snapshot'] + ['signal' => $kind]);
            $seen[$kind] = $signal['key'];
            if ($kind === 'stage' && $signal['phase'] === 'progress') $progressAt = time();
            if ($kind === 'risk') $riskAt = time();
        }
        // Removing a signal starts a new episode if it reappears later; retention of this cursor survives outbox pruning.
        $seen = array_intersect_key($seen, $facts['signals']);
        DB::table(self::STATE)->updateOrInsert(['member_id' => $id], ['signals' => json_encode($seen), 'progress_at' => $progressAt,
            'risk_at' => $riskAt, 'next_scan_at' => $this->nextScan($facts),
            'created_at' => $state->created_at ?? time(), 'updated_at' => time()]);
    }

    public function current(MachineOperationNotice $notice): bool
    {
        if (!$this->available()) return false;
        $facts = $this->facts($notice->source_id); $kind = $notice->snapshot['signal'] ?? '';
        $signal = $facts['signals'][$kind] ?? null;
        return $signal && $signal['phase'] === $notice->phase && hash_equals($notice->context_hash, $this->context($facts, $kind, $signal));
    }

    public function facts(int $id): ?array
    {
        $member = Member::query()->find($id); $run = $member ? Execution::query()->find($member->execution_id) : null;
        $policy = $member ? MachineFailoverPolicy::query()->find($member->policy_id) : null;
        $service = $run ? MachineFailoverService::query()->find($run->service_id) : null;
        if (!$member || !$run || !$policy || !$service) return null;
        $attempt = $member->attempt_id ? Attempt::query()->find($member->attempt_id) : null;
        $task = $attempt?->task_id ? MachineProvisionTask::query()->find($attempt->task_id) : null;
        $machine = ServerMachine::query()->find($policy->machine_id);
        $account = $attempt ? MachineCloudAccount::query()->find($attempt->account_id) : null;
        $running = !in_array($member->phase, self::FINISHED, true);
        $reason = (string) ($member->reason ?: $task?->reason); $phase = 'progress';
        if ($running && ($run->deadline_at <= time() || $task && $task->deadline_at <= time())) { $phase = 'timeout'; $reason = 'deadline'; }
        elseif (in_array($reason, self::BUDGET, true)) $phase = 'budget';
        elseif (in_array($member->phase, ['needs_review', 'reconciling_create', 'reconciling_dns'], true)
            || in_array($task?->status, ['outcome_unknown', 'needs_review'], true)
            || $task?->status === 'failed' && $member->phase !== 'retry_ready') $phase = 'review';
        elseif ($member->phase === 'retained') $phase = 'retained';
        elseif ($member->phase === 'cancelled' || $task?->status === 'cancelled') $phase = 'cancelled';
        $proof = null;
        if ($member->phase === 'completed' || $member->phase === 'awaiting_dns' && $phase === 'progress') {
            $proof = $policy->revision === $member->policy_revision && $service->revision === $run->service_revision
                && $account?->revision === $attempt?->account_revision && $machine?->is_active
                ? $this->proof($member, $run, $attempt, $task, $member->phase === 'completed') : null;
            if ($proof) $phase = $member->phase === 'completed' ? 'completed' : 'forwarding_verified';
            else { $phase = 'review'; $reason = 'receipt_unverified'; }
        }
        if ($reason === 'source_recovered') {
            if ($this->sourceHealthy($member, $policy)) $phase = 'probe_recovered';
            else $reason = 'replacement_evidence_changed';
        }
        $snapshot = ['service_id' => $run->service_id, 'run' => substr($run->id, 0, 8), 'member_id' => $member->member_id,
            'policy_id' => $policy->id, 'machine_id' => $policy->machine_id, 'attempt' => $attempt?->ordinal,
            'installation' => $task ? substr($task->installation_id, 0, 8) : '',
            'result' => MachineFailoverNoticeText::stage($member->phase === 'creating' && $task ? $task->status : $member->phase),
            'reason' => MachineFailoverNoticeText::reason($reason)];
        if ($proof) $snapshot += $proof;
        $key = $this->hash([$member->phase, $phase, $snapshot['reason'], $attempt?->id, $task?->status, $proof]);
        $signals = ['stage' => ['phase' => $phase, 'key' => $key, 'snapshot' => $snapshot]];
        $actions = MachineFailoverReviewAction::query()->where('member_id', $id)->orderBy('id')->get();
        $last = $actions->whereIn('operation', ['close', 'restore', 'claim'])->last();
        if ($last) $signals['review'] = ['phase' => match ($last->operation) { 'close' => 'review_closed', 'restore' => 'review', default => 'retained' },
            'key' => $this->hash([$last->id, $last->operation]), 'snapshot' => array_merge($snapshot, ['review_action_id' => $last->id,
                'attempt' => $last->attempt_id ? Attempt::query()->find($last->attempt_id)?->ordinal : null,
                'reason' => $last->operation === 'claim' ? 'review_claimed' : $snapshot['reason'],
                'resolution' => in_array($last->payload['resolution'] ?? '', ['retained', 'released', 'no_intent'], true) ? $last->payload['resolution'] : null])];
        $risk = $this->retained($member, $run, Attempt::query()->where('execution_member_id', $id)->get(), $running);
        if ($risk['count']) $signals['risk'] = ['phase' => 'retained_risk', 'key' => $this->hash($risk['tasks']),
            'snapshot' => $snapshot + ['retained_count' => $risk['count']]];
        $authority = $this->hash([$run->getRawOriginal(), $service->getRawOriginal(), $policy->getRawOriginal(),
            $machine?->only(['id', 'token', 'is_active']), $account?->only(['id', 'provider', 'revision', 'credentials']), $member->getRawOriginal(),
            $task?->only(['id', 'admin_id', 'account_id', 'account_revision', 'machine_id', 'machine_authority', 'slot', 'installation_id',
                'callback_hash', 'marker', 'plan_hash', 'payload', 'status', 'reason', 'external_id', 'provider_created_at', 'public_ips',
                'bootstrap_state', 'dispatched_at', 'deadline_at']),
            $attempt?->getRawOriginal(), $actions->map->getRawOriginal()->all(), $risk['tasks']]);
        return ['signals' => $signals, 'authority' => $authority, 'running' => $running, 'risk_since' => $risk['since']];
    }

    protected function sourceHealthy(Member $member, MachineFailoverPolicy $policy): bool
    {
        $summary = app(MachineFailoverObserver::class)->summary($policy, time()); $source = $member->payload['source'] ?? [];
        // Match the native executor: a new healthy report need not wait for the failure-history collector.
        return ($summary['probe']['state'] ?? '') === 'healthy'
            && ($summary['probe']['checked_at'] ?? PHP_INT_MAX) <= time() && ($summary['probe']['valid_until'] ?? 0) > time()
            && ($summary['probe']['installation_id'] ?? null) === ($source['source_installation_id'] ?? '')
            && ($summary['probe']['context_key'] ?? null) === ($source['source_context_key'] ?? '');
    }

    private function retained(Member $member, Execution $run, $attempts, bool $running): array
    {
        $tasks = []; $since = time();
        $cost = MachineFailoverCostSnapshot::query()->where('service_id', $run->service_id)->orderByDesc('sequence')->first();
        $itemFor = function (MachineProvisionTask $task) use ($cost) {
            $item = ($cost?->payload['state'] ?? '') === 'complete' ? ($cost?->payload['items'][$task->id] ?? null) : null;
            return $item && hash_equals($item['identity'] ?? '', app(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class)->taskIdentity($task)) ? $item : null;
        };
        $absent = fn ($item) => $item && $cost?->valid_until > time() && ($item['state'] ?? '') === 'absent' && ($item['absence_verified'] ?? false) === true;
        foreach ($attempts as $attempt) {
            $task = $attempt->task_id ? MachineProvisionTask::query()->find($attempt->task_id) : null;
            if (!$task || $running || $task->status === 'accepted') continue;
            $release = MachineFailoverBudgetRelease::query()->where('attempt_id', $attempt->id)->first(); $item = $itemFor($task);
            if ($absent($item)) continue;
            if ($release && !(($item['state'] ?? '') === 'present' && $cost?->checked_at >= (int) $release->created_at)) continue;
            if (!$task->external_id && (!$task->dispatched_at || app(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class)->definitelyRejected($task))) continue;
            $tasks[$task->id] = [$task->id, $task->external_id, $task->provider_created_at]; $since = min($since, (int) $task->updated_at);
        }
        $source = MachineOwnedInstance::query()->find($member->payload['source_instance_id'] ?? 0);
        if ($source?->status === 'retained') {
            $task = MachineProvisionTask::query()->find($source->task_id);
            if ($task && $task->external_id && !$absent($itemFor($task))) { $tasks[$task->id] = [$task->id, $task->external_id, $task->provider_created_at]; $since = min($since, (int) $source->updated_at); }
        }
        ksort($tasks);
        return ['tasks' => array_values($tasks), 'count' => count($tasks), 'since' => $since];
    }

    private function proof(Member $member, Execution $run, ?Attempt $attempt, ?MachineProvisionTask $task, bool $dns): ?array
    {
        if (!$attempt || !$task || $task->admin_id !== $run->admin_id || $attempt->execution_member_id !== $member->id || $attempt->account_id !== (int) $task->account_id
            || $attempt->account_revision !== (int) $task->account_revision || ($task->payload['coordinated_attempt'] ?? null) !== $attempt->id) return null;
        $instance = MachineOwnedInstance::query()->where('task_id', $task->id)->first(); $release = $run->payload['release'] ?? [];
        if (!$instance || $instance->policy_id !== $member->policy_id || $instance->policy_revision !== $member->policy_revision
            || $instance->installation_id !== $task->installation_id || $instance->binary_sha256 !== ($release['binary_sha256'] ?? '')
            || $instance->release_id !== ($release['id'] ?? null) || $instance->version !== ($release['version'] ?? '')
            || !in_array($instance->ip, $task->public_ips ?? [], true) || $instance->verified_until <= time()
            || ($instance->verification['cleanup_complete'] ?? false) !== true) return null;
        $check = MachineAcceptanceCheck::query()->where('instance_id', $instance->id)->where('state', 'passed')
            ->where('receipt_sha256', $instance->verification['receipt_sha256'] ?? '')->latest('issued_at')->first();
        $configuration = $instance->verification['context']['configuration_fingerprint'] ?? '';
        if (!$check || $check->policy_revision !== $member->policy_revision || !preg_match('/^[a-f0-9]{64}$/D', $configuration)) return null;
        $proof = ['context' => $this->hash([$member->execution_id, $member->id, $attempt->id, $task->id, $task->account_id, $task->account_revision,
            $task->installation_id, $task->marker, $task->external_id, $task->provider_created_at, $instance->id, $instance->ip, $instance->binary_sha256]),
            'configuration' => $configuration, 'check_id' => $check->id, 'receipt' => $check->receipt_sha256];
        if (!hash_equals($attempt->payload['forwarding_receipt'] ?? '', $this->hash($proof))) return null;
        if ($dns) {
            $transition = MachineDnsTransition::query()->find($attempt->payload['transition_id'] ?? ''); $expected = $member->payload['dns'] ?? [];
            $record = $transition ? MachineOwnedDns::query()->find($transition->dns_id) : null;
            if (!$transition || $transition->state !== 'verified' || !$transition->dispatched_at || $transition->recovery_of !== null
                || $transition->request_key !== hash('sha256', $run->admin_id . ':' . substr($this->hash([$attempt->id, 'dns']), 0, 32))
                || $transition->admin_id !== $run->admin_id || $transition->dns_id !== ($expected['id'] ?? null) || $transition->dns_revision !== ($expected['revision'] ?? null)
                || $transition->policy_revision !== $member->policy_revision || $transition->from_instance_id !== ($member->payload['source_instance_id'] ?? null)
                || $transition->from_ip !== ($expected['ip'] ?? null) || $transition->to_instance_id !== $instance->id || $transition->to_ip !== $instance->ip
                || $record?->active_instance_id !== $instance->id || $record?->record_id !== ($expected['record_id'] ?? null)
                || $task->status !== 'accepted' || $instance->active_policy_id !== $member->policy_id
                || ($task->evidence['dns_transition_id'] ?? null) !== $transition->id) return null;
            $proof['dns'] = $transition->id;
            if (!hash_equals($attempt->payload['dns_receipt'] ?? '', $this->hash($proof))) return null;
        }
        return ['checked_at' => $check->issued_at, 'valid_until' => $instance->verified_until];
    }

    private function keys(array $facts): array { return array_map(fn ($signal) => $signal['key'], $facts['signals']); }
    private function nextScan(array $facts): int
    {
        return time() + ($facts['running'] ? 60 : (isset($facts['signals']['risk']) ? 3600
            : (in_array($facts['signals']['stage']['phase'], ['completed', 'cancelled'], true) ? 86400 : 300)));
    }
    private function context(array $facts, string $kind, array $signal): string { return $this->hash([$facts['authority'], $kind, $signal['key']]); }
    private function hash(mixed $data): string { return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)); }
}
