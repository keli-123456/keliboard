<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineDnsSetRuntime;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;
use Illuminate\Support\Facades\Schema;

// Called under the stopped parent lock. Return only identifiers and digests, not raw requests.
final class MachineFailoverHandoverJournal
{
    public function __construct(private MachineFailoverNativeBridge $native, private MachineNativeDnsAdmission $sets,
        private MachineDnsRecordSetExecution $engine, private MachineCandidateRetry $retry) {}

    public function snapshot(Execution $run): array
    {
        $members = Member::query()->where('execution_id', $run->id)->orderBy('id')->lockForUpdate()->get();
        $plans = $members->pluck('plan_id')->all(); sort($plans);
        $intent = [$run->service_id, $run->service_revision, $plans, $run->payload['release'], ['workflow' => MachineFailoverWorkflow::READINESS],
            ['handover_grant_id' => $run->payload['handover_grant_id']]];
        if (MachineFailoverOperatorPermit::matches($run)) $intent[] = ['launch_mode' => MachineFailoverOperatorPermit::MODE];
        if (!$plans || count(array_unique($plans)) !== count($plans)
            || !hash_equals($run->intent_hash, hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)))) $this->changed();
        $attempts = Attempt::query()->whereIn('execution_member_id', $members->modelKeys())->orderBy('ordinal')->lockForUpdate()->get()->groupBy('execution_member_id');
        $operations = []; $rows = [$run->getRawOriginal()];
        $scope = collect($run->payload['snapshot']['members'])->keyBy('id');
        foreach ($members as $member) {
            $pin = $scope->get($member->member_id);
            if (!$pin || $pin['policy_id'] !== $member->policy_id || $pin['policy_revision'] !== $member->policy_revision
                || !in_array($member->phase, ['cancelled', 'retained', 'needs_review', 'completed'], true)) $this->changed();
            $ledger = $attempts->get($member->id, collect());
            if ($ledger->last()?->id !== $member->attempt_id) $this->changed();
            $rows[] = $member->getRawOriginal(); $previous = null; $ordinal = 0;
            foreach ($ledger as $attempt) {
                if ($attempt->ordinal !== ++$ordinal || ($attempt->payload['previous_attempt_id'] ?? null) !== $previous) $this->changed();
                $previous = $attempt->id; $rows[] = $attempt->getRawOriginal();
                $this->attempt($run, $member, $attempt, $operations, $rows);
            }
        }
        ksort($operations, SORT_STRING);
        return [MachineFailoverHandoverEvidence::hash($rows), array_values($operations), max(array_map(fn ($row) => (int) $row['updated_at'], $rows))];
    }

    private function attempt(Execution $run, Member $member, Attempt $attempt, array &$operations, array &$rows): void
    {
        $task = Task::query()->where('request_key', hash('sha256', $run->admin_id . ':' . $attempt->request_id))->lockForUpdate()->first();
        if ($task?->id !== $attempt->task_id) $this->changed();
        $p = $attempt->payload; $cleanup = $p['candidate_retry'] ?? [];
        if (!$task) {
            if (isset($p['transition_id']) || !empty($p['dns_set_runs']) || isset($cleanup['delete_dispatched_at'])) $this->changed();
            return;
        }
        if (!$this->native->bindingCurrent($attempt, $task) || $task->admin_id !== $run->admin_id) $this->changed();
        $rows[] = $task->getRawOriginal();
        if ($task->dispatched_at !== null) {
            $outcome = $task->external_id !== null ? 'confirmed_applied' : ($this->native->definitelyRejected($task) ? 'confirmed_not_applied' : null);
            $this->add($operations, 'cloud_create', $task->id, $task->getRawOriginal(), $outcome);
        } elseif ($task->external_id !== null || $task->bootstrap_state !== null) $this->changed();
        if (isset($cleanup['delete_dispatched_at']) || $attempt->status === 'cleaned') {
            $journal = $this->retry->originalDelete($attempt, $task);
            $this->add($operations, 'candidate_delete', $attempt->id, $journal, $journal['state'] === 'confirmed' ? 'confirmed_applied' : null);
        }
        $sets = array_filter($run->payload['snapshot']['dns_sets'] ?? [], fn ($set) => in_array($member->member_id, array_column($set['slots'], 'member_id'), true));
        if ((bool) $sets !== isset($member->payload['dns_source'])) $this->changed();
        if ($sets) {
            if (isset($p['transition_id'])) $this->changed();
            if (!Schema::hasTable('v2_machine_failover_dns_run') || !Schema::hasTable('v2_machine_dns_set_runtime')) $this->changed();
            $links = $p['dns_set_runs'] ?? [];
            if (array_diff(array_keys($links), array_column($sets, 'id'))) $this->changed();
            foreach ($sets as $set) {
                $key = MachineNativeDnsAdmission::requestKey($attempt->id, $set['id']);
                $id = DnsRun::query()->where('request_key', $key)->value('id');
                // Cross-check the independent request key so a broken child link cannot hide a write.
                if ($id !== ($links[$set['id']] ?? null)) $this->changed();
                if ($id !== null) $this->recordSet($run, $member, $attempt, $task, $set, $id, $key, $operations, $rows);
            }
        } else {
            if (!empty($p['dns_set_runs'])) $this->changed();
            // A prepared legacy transition may exist before its attempt checkpoint was saved.
            $transition = MachineDnsTransition::query()->where('request_key', $this->native->dnsRequestKey($attempt, $run->admin_id))->lockForUpdate()->first();
            if (isset($p['transition_id']) && $p['transition_id'] !== $transition?->id) $this->changed();
            if (!$transition) return;
            if (!$this->native->transitionCurrent($attempt, $task, $transition)) $this->changed();
            $rows[] = $transition->getRawOriginal();
            if ($transition->dispatched_at === null) return;
            if (!$task->dispatched_at || !isset($p['transition_id']) || !in_array($transition->write_kind, ['create', 'patch', 'none'], true)) $this->changed();
            $outcome = $transition->write_kind === 'none' ? 'confirmed_not_applied' : ($transition->state === 'verified' ? 'confirmed_applied' : null);
            $this->add($operations, 'dns_transition', $transition->id, $transition->getRawOriginal(), $outcome);
        }
    }

    private function recordSet(Execution $run, Member $member, Attempt $attempt, Task $task, array $set, string $id, string $key, array &$operations, array &$rows): void
    {
        $runtime = MachineDnsSetRuntime::query()->lockForUpdate()->find($id);
        $dns = DnsRun::query()->lockForUpdate()->find($id);
        if (!$dns || !$runtime || $runtime->mode !== 'native' || !$runtime->finished_at || $runtime->lease_hash !== null || $runtime->lease_until !== null
            || !in_array($dns->status, ['blocked', 'completed', 'needs_review', 'stopped'], true) || !$task->dispatched_at
            || $dns->set_id !== $set['id'] || $dns->set_revision !== $set['revision'] || $dns->request_key !== $key
            || $dns->service_id !== $run->service_id || $dns->service_revision !== $run->service_revision
            || $dns->admin_id !== $run->admin_id) $this->changed();
        $binding = $this->sets->publicationBinding($dns);
        if ($binding['attempt_id'] !== $attempt->id || ($binding['context']['task']['id'] ?? null) !== $task->id
            || ($binding['context']['execution_id'] ?? null) !== $run->id || ($binding['context']['execution_member_id'] ?? null) !== $member->id) $this->changed();
        $rows[] = $dns->getRawOriginal(); $rows[] = $runtime->getRawOriginal();
        $actions = $dns->payload['plan']['actions'];
        if ($dns->cursor < 0 || $dns->cursor > count($actions)) $this->changed();
        foreach ($actions as $index => $action) {
            $this->engine->assertActionJournal($action, $dns->payload['snapshot']['set']);
            if ($index < $dns->cursor ? $action['state'] !== 'verified' : ($action['state'] === 'verified' || $index > $dns->cursor && $action['state'] !== 'prepared'))
                $this->changed();
            foreach ($action['requests'] as $sequence => $request)
                $this->add($operations, $sequence === 0 ? 'dns_record' : 'dns_claim', $id . ':' . $action['id'] . ':' . $sequence, $request,
                    $action['state'] === 'verified' || ($sequence === 0 && $action['kind'] === 'create' && $action['known_id']) ? 'confirmed_applied' : null);
        }
    }

    private function add(array &$operations, string $kind, string $id, array $journal, ?string $outcome): void
    {
        $key = $kind . ':' . $id;
        if (isset($operations[$key])) $this->changed();
        $operations[$key] = ['operation_id' => $key, 'kind' => $kind, 'intent_sha256' => MachineFailoverHandoverEvidence::hash($journal), 'known_outcome' => $outcome];
    }

    private function changed(): never { throw new CloudException('handover_closure_journal_changed'); }
}
