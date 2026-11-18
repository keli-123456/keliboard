<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate as Gate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge as Native;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;

/** Read-only public derivative of the root CLI grant. No provider or DNS permission. */
final class MemberCandidateCallbacks
{
    private static function need(bool $ok, string $code): void
    {
        if (!$ok) throw new \RuntimeException('member_candidate_callback_' . $code);
    }

    public static function project(array $native, string $hash, array $setup, string $setupHash): array
    {
        self::need(($native['schema'] ?? null) === 2 && ($native['execution_authorized'] ?? null) === true
            && ($native['isolated'] ?? null) === true && ($native['run_id'] ?? null) === MemberCliAuthority::RUN
            && ($setup['run_id'] ?? null) === MemberCliAuthority::RUN, 'grant_invalid');
        foreach ([$hash, $setupHash] as $value)
            self::need(preg_match('/^[a-f0-9]{64}$/D', $value) === 1, 'grant_invalid');
        foreach (['started_at', 'deadline_at', 'bundle_sha256'] as $key)
            self::need(isset($setup[$key]) && ($native[$key] ?? null) === $setup[$key], 'window_or_bundle_changed');
        self::need(($native['controller_machine_id_sha256'] ?? null) === ($setup['machine_id_sha256'] ?? null)
            && isset($native['control']['external_id'])
            && $native['control']['external_id'] === ($setup['control']['external_id'] ?? null), 'controller_changed');
        $d = $native['driver'] ?? []; $s = $setup['binding']['scope']; $c = $setup['binding']['configuration'];
        self::need(($d['run_id'] ?? null) === MemberCliAuthority::RUN
            && ($d['workflow'] ?? null) === 'agent_readiness_v1'
            && is_int($d['plan_id'] ?? null) && $d['plan_id'] > 0, 'driver_invalid');
        foreach (['admin_id', 'account_id', 'release_id', 'baseline_ids'] as $key)
            self::need(($d[$key] ?? null) === $s[$key], 'driver_changed');
        foreach (['service_id', 'service_revision'] as $key)
            self::need(($d[$key] ?? null) === $c[$key], 'driver_changed');
        self::need(array_keys($native['sources'] ?? []) === ['source-a', 'source-b'], 'sources_changed');
        foreach (['source-a' => 'policy_a', 'source-b' => 'policy_b'] as $role => $key) {
            $p = $native['sources'][$role]; $source = $c['sources'][$role];
            self::need(($d[$key] ?? null) === $source['policy_id'] && ($p['policy_id'] ?? null) === $source['policy_id']
                && ($p['machine_id'] ?? null) === $source['machine_id']
                && ($p['account_revision'] ?? null) === $s['account_revision'], 'sources_changed');
            $task = Task::query()->findOrFail($p['task_id'] ?? '');
            self::need(!isset($task->payload['coordinated_attempt']) && $task->account_id === $s['account_id'], 'sources_changed');
            foreach (['installation_id', 'external_id', 'marker', 'provider_created_at', 'machine_id', 'account_revision'] as $field)
                self::need(isset($p[$field]) && $task->{$field} === $p[$field], 'sources_changed');
        }
        // Keep only non-secret immutable identifiers, never the private grant or credentials.
        return ['schema' => 1, 'run_id' => MemberCliAuthority::RUN, 'setup_sha256' => $setupHash,
            'native_sha256' => $hash, 'started_at' => $setup['started_at'], 'deadline_at' => $setup['deadline_at'],
            'driver' => array_intersect_key($d, array_flip(['run_id', 'workflow', 'admin_id', 'account_id',
                'release_id', 'baseline_ids', 'service_id', 'service_revision', 'policy_a', 'policy_b', 'plan_id'])),
            'source_tasks' => array_map(static fn ($p) => $p['task_id'], $native['sources']),
            'source_instances' => array_map(static fn ($p) => $p['external_id'], $native['sources']),
            'control_id' => $setup['control']['external_id']];
    }

    public static function role(array $cap, Task $task): string
    {
        $d = $cap['driver'];
        self::need(($cap['schema'] ?? null) === 1 && ($cap['run_id'] ?? null) === MemberCliAuthority::RUN
            && ($d['run_id'] ?? null) === MemberCliAuthority::RUN, 'grant_invalid');
        $id = $task->payload['coordinated_attempt'] ?? null;
        self::need(is_string($id), 'task_outside_grant');
        $attempt = Attempt::query()->findOrFail($id);
        $member = Member::query()->findOrFail($attempt->execution_member_id);
        $run = Execution::query()->findOrFail($member->execution_id);
        $request = substr(hash('sha256', Json::encode([MemberCliAuthority::RUN, 'native-a'])), 0, 32);
        $key = hash('sha256', json_encode([$d['admin_id'], $request], JSON_THROW_ON_ERROR));
        self::need($run->request_key === $key && $run->service_id === $d['service_id']
            && $run->service_revision === $d['service_revision'] && $run->admin_id === $d['admin_id']
            && ($run->payload['release']['id'] ?? null) === $d['release_id']
            && ($run->payload['workflow'] ?? null) === 'agent_readiness_v1'
            && in_array($run->status, ['running', 'completed'], true)
            && $run->deadline_at > time()
            && Execution::query()->where('service_id', $d['service_id'])->count() === 1, 'execution_changed');
        self::need($member->policy_id === $d['policy_a'] && $member->plan_id === $d['plan_id']
            && $member->attempt_id === $attempt->id
            && Member::query()->where('execution_id', $run->id)->count() === 1, 'member_changed');
        $attempts = Attempt::query()->where('execution_member_id', $member->id)->orderBy('ordinal')->get();
        self::need($attempts->count() <= 2 && in_array($attempt->ordinal, [1, 2], true)
            && $attempts->every(fn ($a) => $a->account_id === $d['account_id']
                && in_array($a->ordinal, [1, 2], true) && !isset($a->payload['rotation']))
            && app(Native::class)->bindingCurrent($attempt, $task), 'task_binding_changed');
        $source = Task::query()->findOrFail($cap['source_tasks']['source-a']);
        self::need($task->machine_id === $source->machine_id && $task->slot === $source->slot
            && $task->admin_id === $d['admin_id'] && $task->account_id === $d['account_id']
            && $task->account_revision === $source->account_revision && $task->dispatched_at > 0
            && is_string($task->external_id) && ctype_digit($task->external_id)
            && !in_array($task->external_id, [...$d['baseline_ids'], $cap['control_id'], ...array_values($cap['source_instances'])], true)
            && !in_array($task->status, ['failed', 'cancelled', 'needs_review'], true)
            && !in_array($attempt->status, ['cleaned', 'released', 'rejected', 'cancelled', 'failed'], true), 'candidate_inactive');
        if ($attempt->ordinal === 2) app(\App\Services\ServerMachine\Cloud\MachineCandidateRetry::class)->proof($attempt);
        return 'source-a';
    }

    public static function within(array $cap, Task $task, \Closure $guard, \Closure $callback): mixed
    {
        // The native callback validates lineage/context, but cannot advance or create tasks.
        $guard(); self::role($cap, $task->fresh());
        $shared = app()->isShared(Gate::class); $original = app(Gate::class);
        $bridgeShared = app()->isShared(Native::class); $originalBridge = app(Native::class);
        try {
            app()->instance(Gate::class, new class($guard, $cap, $task->id) extends Gate {
                public function __construct(private \Closure $guard, private array $cap, private string $taskId) {}
                public function assertAllowed(string $operation): void
                {
                    if (!in_array($operation, ['native_task', 'context'], true))
                        throw new \RuntimeException('member_candidate_callback_operation_denied');
                    ($this->guard)(); MemberCandidateCallbacks::role($this->cap, Task::query()->findOrFail($this->taskId));
                }
            });
            app()->forgetInstance(Native::class);
            return $callback();
        } finally {
            if ($bridgeShared) app()->instance(Native::class, $originalBridge);
            else app()->forgetInstance(Native::class);
            if ($shared) app()->instance(Gate::class, $original);
            else app()->forgetInstance(Gate::class);
        }
    }
}
