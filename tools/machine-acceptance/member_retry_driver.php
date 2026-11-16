<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCandidateRetry;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge as Native;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Cloud\MachineIntentJson;

/** Multi-source coordinator; no SSH, provider transport, credential loader or live launcher. */
final class MemberRetryDriver
{
    private array $state;
    private bool $busy = false;
    private const ROLES = ['control', 'source-a', 'source-b', 'candidate-a1', 'candidate-a2'];

    // Port implements role-local setup/observation and scoped final cleanup. The guard must
    // authorize every operation; this module never opens the application's execution gate.
    public function __construct(private array $scope, private \Closure $port, private \Closure $save,
        private \Closure $guard, private \Closure $clock, ?array $restored = null)
    {
        // This version deliberately has no live authority adapter. A prepared transport
        // cannot turn local rehearsal into a real cloud run just by supplying a callback.
        self::need(app()->runningUnitTests() && app('db')->connection()->getDriverName() === 'sqlite'
            && app('db')->connection()->getDatabaseName() === ':memory:', 'member_offline_environment_required');
        self::need(($scope['workflow'] ?? null) === 'agent_readiness_v1'
            && preg_match('/^keli-accept-[a-z0-9-]{8,80}$/D', $scope['run_id'] ?? '') === 1,
            'member_scope_invalid');
        foreach (['admin_id', 'account_id', 'service_id', 'service_revision', 'release_id', 'plan_id', 'policy_a', 'policy_b'] as $key)
            self::need(is_int($scope[$key] ?? null) && $scope[$key] > 0, 'member_scope_invalid');
        self::need($scope['policy_a'] !== $scope['policy_b'], 'member_scope_invalid');
        self::need(is_array($scope['baseline_ids'] ?? null) && array_is_list($scope['baseline_ids'])
            && count($scope['baseline_ids']) <= 996 && count(array_unique($scope['baseline_ids'])) === count($scope['baseline_ids']),
            'member_inventory_baseline_invalid');
        foreach ($scope['baseline_ids'] as $id) self::need(is_string($id) && ctype_digit($id), 'member_inventory_baseline_invalid');
        $now = ($this->clock)();
        $this->state = $restored ?? ['schema' => 1, 'run_id' => $scope['run_id'], 'scope_hash' => $this->hash($scope),
            'started_at' => $now, 'last_at' => $now, 'phase' => 'control', 'roles' => [], 'pending' => null,
            'first_error' => null, 'cleanup_error' => null, 'cleanup_reads' => [],
            'branches' => ['native_cleanup' => false, 'cooldown' => false, 'retry' => false, 'b_unchanged' => false,
                'dns_cutover' => false, 'retry_exhausted' => false, 'final_cleanup' => false]];
        self::need(($this->state['schema'] ?? null) === 1 && ($this->state['run_id'] ?? null) === $scope['run_id']
            && ($this->state['scope_hash'] ?? null) === $this->hash($scope), 'member_resume_scope_changed');
    }

    public function state(): array { return $this->state; }

    public function step(bool $stop = false): array
    {
        self::need(!$this->busy, 'member_driver_reentered');
        if ($this->state['phase'] === 'closed') return $this->state;
        $this->busy = true;
        try {
            $now = ($this->clock)();
            if (!is_int($now) || $now < $this->state['last_at']) {
                $this->fail('member_clock_invalid');
                return $this->cleanup(false);
            }
            $this->state['last_at'] = $now;
            if ($stop) $this->fail('member_stop_requested');
            if ($now - $this->state['started_at'] >= 6000 && $this->state['phase'] !== 'cleanup') $this->fail('member_deadline');
            if ($this->state['phase'] === 'cleanup') return $this->cleanup();
            // A persisted ambiguous operation is never automatically dispatched again.
            self::need($this->state['pending'] === null, 'member_operation_unresolved');
            ($this->guard)('advance');
            $this->assertLimits();
            if (in_array($this->state['phase'], ['control', 'source-a', 'source-b'], true)) $this->setup();
            elseif ($this->state['phase'] === 'setup_cooldown') $this->start();
            else $this->advance();
            $this->persist();
        } catch (\Throwable $error) {
            $class = (new \ReflectionClass($error))->getShortName();
            $this->state['first_diagnostic'] ??= ['class' => preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $class) ? $class : 'Throwable',
                'site' => $error->getFile() === __FILE__ ? 'member_retry_driver' : 'native_or_port',
                'line' => $error->getFile() === __FILE__ ? $error->getLine() : null,
                'native_reason' => $error instanceof CloudException && preg_match('/^[a-z_]{1,60}$/D', $error->getMessage())
                    ? $error->getMessage() : null];
            $this->fail($this->reason($error));
            try { $this->persist(); } catch (\Throwable) { /* Keep the first failure in memory for the caller. */ }
        } finally { $this->busy = false; }
        return $this->state;
    }

    private function setup(): void
    {
        $role = $this->state['phase'];
        $result = $this->action('prepare_role', ['role' => $role,
            'request_id' => substr($this->hash([$this->scope['run_id'], $role]), 0, 32)]);
        if ($role === 'control') {
            self::need(is_string($result['external_id'] ?? null) && ctype_digit($result['external_id'])
                && !in_array($result['external_id'], $this->scope['baseline_ids'], true)
                && ($result['ready'] ?? null) === true, 'member_control_not_ready');
            $this->state['roles'][$role] = ['external_id' => $result['external_id']];
            $this->state['phase'] = 'source-a';
            return;
        }
        self::need(is_string($result['task_id'] ?? null), 'member_source_missing');
        $task = Task::query()->findOrFail($result['task_id']);
        $policy = Policy::query()->findOrFail($this->scope[$role === 'source-a' ? 'policy_a' : 'policy_b']);
        self::need($task->status === 'accepted' && $task->bootstrap_state === 'succeeded'
            && $task->machine_id === $policy->machine_id && $task->slot === $policy->slot
            && !isset($task->payload['coordinated_attempt']), 'member_source_not_registered');
        $owned = \App\Models\MachineOwnedInstance::query()->where('task_id', $task->id)->firstOrFail();
        app(MachineNativeDnsAdmission::class)->sourcePin($policy, $owned);
        $this->bind($role, $task);
        if ($role === 'source-a') $this->state['phase'] = 'source-b';
        else {
            self::need($this->state['roles']['source-a']['machine_id'] !== $task->machine_id, 'member_sources_collide');
            $this->state['b_hash'] = $this->hash($this->controlFacts());
            $this->state['setup_retry_at'] = max(array_map(fn ($r) => Task::query()
                ->findOrFail($this->state['roles'][$r]['task_id'])->created_at, ['source-a', 'source-b'])) + 300;
            $this->state['phase'] = 'setup_cooldown';
        }
    }

    private function start(): void
    {
        $this->assertB();
        if ($this->state['last_at'] < $this->state['setup_retry_at']) return;
        $this->action('observe_sources', ['roles' => ['source-a', 'source-b'], 'fault_allowed_roles' => ['source-a']]);
        $this->assertB();
        $run = $this->native(fn () => app(Native::class)->start($this->scope['service_id'], $this->scope['service_revision'],
            [$this->scope['plan_id']], $this->scope['release_id'],
            substr($this->hash([$this->scope['run_id'], 'native-a']), 0, 32), $this->scope['admin_id']));
        $this->state['execution_id'] = $run['id'];
        $this->state['phase'] = 'native';
        $this->validateRun();
    }

    private function advance(): void
    {
        $this->assertB();
        $attempts = $this->validateRun();
        if ($attempts->count() === 1 && $attempts[0]->status === 'cleaned') {
            $proof = $this->proof($attempts[0]);
            $this->state['branches']['native_cleanup'] = true;
            $this->state['retry_at'] = $proof['retry_at'];
            $this->state['phase'] = 'candidate_retry_cooldown';
            if ($this->state['last_at'] < $proof['retry_at']) return;
            $this->state['branches']['cooldown'] = true;
        }
        $this->action('observe_candidates', ['roles' => array_values(array_intersect(['candidate-a1', 'candidate-a2'],
            array_keys($this->state['roles']))), 'fault_allowed_roles' => ['candidate-a1']]);
        $this->assertB();
        $run = $this->native(fn () => app(Native::class)->advance($this->state['execution_id'], $this->scope['admin_id']));
        $attempts = $this->validateRun();
        $this->assertB();
        if ($attempts->count() === 2) {
            $this->proof($attempts[0]);
            app(MachineCandidateRetry::class)->proof($attempts[1]);
            self::need($this->state['branches']['cooldown'], 'member_retry_before_cooldown');
            $this->state['branches']['retry'] = true;
        }
        $phase = $run['members'][0]['phase'] ?? 'unknown';
        $this->state['phase'] = $phase;
        if ($run['status'] === 'completed') {
            self::need($this->state['branches']['retry'] && $attempts->count() === 2, 'member_retry_not_exercised');
            $this->state['branches']['dns_cutover'] = true;
            $this->state['phase'] = 'cleanup';
        } elseif ($run['status'] !== 'running') {
            $this->state['native_stop'] = ['phase' => $phase, 'reason' => $run['members'][0]['reason'] ?? null];
            if ($attempts->count() === 2 && $attempts[1]->status === 'cleaned'
                && in_array($run['members'][0]['reason'] ?? null, ['attempt_budget', 'member_attempt_limit'], true))
                $this->state['branches']['retry_exhausted'] = true;
            $this->fail('member_native_stopped');
        }
    }

    private function validateRun(): \Illuminate\Support\Collection
    {
        $run = Execution::query()->findOrFail($this->state['execution_id']);
        $members = Member::query()->where('execution_id', $run->id)->get();
        self::need($run->service_id === $this->scope['service_id'] && $members->count() === 1
            && $members[0]->policy_id === $this->scope['policy_a'], 'member_execution_changed');
        $attempts = Attempt::query()->whereIn('execution_member_id', $members->pluck('id'))->orderBy('ordinal')->get();
        self::need($attempts->count() <= 2, 'member_attempt_limit');
        foreach ($attempts as $index => $attempt) {
            self::need($attempt->ordinal === $index + 1 && $attempt->execution_member_id === $members[0]->id
                && $attempt->account_id === $this->scope['account_id'], 'member_attempt_changed');
            if ($attempt->task_id) {
                $task = Task::query()->findOrFail($attempt->task_id);
                self::need(app(Native::class)->bindingCurrent($attempt, $task), 'member_task_binding_changed');
                if ($attempt->status === 'cleaned') $this->proof($attempt);
                elseif ($run->status === 'running' && !in_array($members[0]->phase, ['completed', 'cancelled', 'retained', 'needs_review'], true))
                    app(Native::class)->assertAttempt($attempt, $task);
                self::need($task->machine_id === $this->state['roles']['source-a']['machine_id'], 'member_candidate_machine_changed');
                $this->bind('candidate-a' . $attempt->ordinal, $task);
            }
        }
        return $attempts;
    }

    private function bind(string $role, Task $task): void
    {
        $request = $task->payload['request'] ?? [];
        self::need(in_array($role, self::ROLES, true) && $task->account_id === $this->scope['account_id']
            && preg_match('/^keli-create-[a-f0-9]{24}$/D', $task->marker) === 1
            && ($request['label'] ?? null) === 'keli-' . str_replace('-', '', $task->id)
            && ($request['tags'] ?? null) === [$task->marker], 'member_task_identity_invalid');
        self::need(!$task->external_id || !in_array($task->external_id, $this->scope['baseline_ids'], true), 'member_existing_resource_forbidden');
        $identity = ['task_id' => $task->id, 'installation_id' => $task->installation_id, 'machine_id' => $task->machine_id,
            'account_id' => $task->account_id, 'account_revision' => $task->account_revision, 'marker' => $task->marker];
        $previous = $this->state['roles'][$role] ?? null;
        self::need($previous === null || array_intersect_key($previous, $identity) === $identity, 'member_role_rebound');
        foreach ($this->state['roles'] as $other => $value) if ($other !== $role) {
            self::need(($value['task_id'] ?? null) !== $task->id && ($value['installation_id'] ?? null) !== $task->installation_id,
                'member_identity_reused');
            if ($task->external_id) self::need(($value['external_id'] ?? null) !== $task->external_id, 'member_resource_reused');
        }
        if (isset($previous['external_id'])) self::need($previous['external_id'] === $task->external_id, 'member_resource_changed');
        $this->state['roles'][$role] = $identity + ($task->external_id ? ['external_id' => $task->external_id] : []);
    }

    private function proof(Attempt $attempt): array
    {
        $proof = app(MachineCandidateRetry::class)->confirmed($attempt, Task::query()->findOrFail($attempt->task_id));
        self::need($proof['retry_after_seconds'] === 300 && $proof['retry_at'] === $proof['confirmed_at'] + 300,
            'member_cooldown_changed');
        return $proof;
    }

    private function native(\Closure $operation): array
    {
        $this->state['pending'] = ['operation' => 'native', 'role' => 'A']; $this->persist();
        $original = app(MachineFailoverExecutionGate::class);
        $driver = $this;
        app()->instance(MachineFailoverExecutionGate::class, new class($original, $driver) extends MachineFailoverExecutionGate {
            public function __construct(private MachineFailoverExecutionGate $original, private MemberRetryDriver $driver) {}
            public function assertAllowed(string $operation): void {
                $this->original->assertAllowed($operation);
                $this->driver->guardNative($operation);
            }
        });
        try { $result = $operation(); }
        finally { app()->instance(MachineFailoverExecutionGate::class, $original); }
        $this->state['pending'] = null;
        return $result;
    }

    public function guardNative(string $operation): void
    {
        ($this->guard)($operation);
        $this->assertB();
        if (in_array($operation, ['rotate'], true)) throw new CloudException('member_rotation_forbidden');
        if (in_array($operation, ['native_dns_admission', 'native_dns_prepare', 'native_dns_binding', 'native_dns_complete'], true)) {
            $attempts = $this->validateRun();
            self::need($attempts->count() === 2 && $this->state['branches']['cooldown'], 'member_first_candidate_dns_forbidden');
            $this->proof($attempts[0]);
        }
    }

    private function controlFacts(): array
    {
        ($this->guard)('control_facts');
        $task = Task::query()->findOrFail($this->state['roles']['source-b']['task_id']);
        $policy = Policy::query()->findOrFail($this->scope['policy_b']);
        $owned = \App\Models\MachineOwnedInstance::query()->where('task_id', $task->id)->firstOrFail();
        return ['task' => $task->only(['id', 'installation_id', 'external_id', 'account_id', 'status', 'marker', 'payload']),
            'policy' => $policy->toArray(), 'pin' => app(MachineNativeDnsAdmission::class)->sourcePin($policy, $owned),
            'remote' => ($this->port)('control_facts', ['role' => 'source-b', 'task_id' => $task->id])];
    }

    private function assertB(): void
    {
        if (!isset($this->state['b_hash'])) return;
        self::need(hash_equals($this->state['b_hash'], $this->hash($this->controlFacts())), 'member_b_changed');
        $this->state['branches']['b_unchanged'] = true;
    }

    private function action(string $operation, array $arguments): array
    {
        ($this->guard)($operation);
        $this->state['pending'] = ['operation' => $operation, 'role' => $arguments['role'] ?? 'A'];
        if (isset($arguments['request_id'])) $this->state['pending']['request_id'] = $arguments['request_id'];
        $this->persist();
        $result = ($this->port)($operation, $arguments);
        self::need(is_array($result), 'member_port_response_invalid');
        $this->state['pending'] = null;
        return $result;
    }

    private function cleanup(bool $clockValid = true): array
    {
        // The injected teardown reconciles pending intents, restores both fault handles,
        // preserves evidence, deletes only bound resources, and deletes control last.
        try {
            ($this->guard)('cleanup');
            $result = ($this->port)('cleanup', ['roles' => $this->state['roles'], 'pending' => $this->state['pending'],
                'execution_id' => $this->state['execution_id'] ?? null]);
            $expected = array_values(array_column($this->state['roles'], 'external_id')); sort($expected);
            $inspected = $result['inspected_ids'] ?? []; sort($inspected);
            if (!$clockValid || ($result['all_absent'] ?? null) !== true || ($result['faults_restored'] ?? null) !== true
                || ($result['private_materials_removed'] ?? null) !== true || ($result['intents_reconciled'] ?? null) !== true
                || $inspected !== $expected) {
                $this->state['cleanup_reads'] = [];
            } else {
                $reads = $this->state['cleanup_reads']; $now = $this->state['last_at'];
                if (!$reads || $now - end($reads) >= 10) $this->state['cleanup_reads'][] = $now;
                if (count($this->state['cleanup_reads']) >= 2) {
                    $this->state['branches']['final_cleanup'] = true;
                    $this->state['phase'] = 'closed';
                }
            }
        } catch (\Throwable $error) {
            $this->state['cleanup_error'] ??= $this->reason($error);
            $this->state['cleanup_reads'] = [];
        }
        $this->persist();
        return $this->state;
    }

    private function fail(string $reason): void
    {
        $this->state['first_error'] ??= $reason;
        $this->state['phase'] = 'cleanup';
    }

    private function assertLimits(): void
    {
        $account = MachineCloudAccount::query()->findOrFail($this->scope['account_id']);
        $limits = app(\App\Services\ServerMachine\Cloud\MachineProvisionLimits::class)->settings($account);
        self::need($account->provider === 'linode' && $limits === ['max_instances' => count($this->scope['baseline_ids']) + 4,
            'max_parallel' => 1, 'daily_limit' => 5], 'member_limits_changed');
        $snapshot = app(\App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry::class)
            ->snapshot($this->scope['service_id'], $this->scope['service_revision']);
        self::need(\App\Models\MachineFailoverPlan::query()->findOrFail($this->scope['plan_id'])->policy_id === $this->scope['policy_a'],
            'member_selected_plan_changed');
        self::need($snapshot['service']['max_parallel'] === 1 && $snapshot['service']['attempt_limit'] === 2
            && count($snapshot['members']) === 2, 'member_limits_changed');
        foreach (['policy_a' => [3, 2], 'policy_b' => [1, 1]] as $name => [$daily, $tries]) {
            $policy = Policy::query()->findOrFail($this->scope[$name]);
            $members = array_values(array_filter($snapshot['members'], fn ($m) => $m['policy_id'] === $policy->id));
            self::need(count($members) === 1 && $members[0]['attempt_limit'] === $tries
                && $policy->daily_limit === $daily && $policy->failure_threshold === 3 && $policy->cooldown_seconds === 300,
                'member_limits_changed');
        }
        self::need(Task::query()->count() <= 4 && config('machine_failover.allow_rotation') === false
            && config('machine_failover.runner_enabled') === false, 'member_isolation_changed');
    }

    private function reason(\Throwable $error): string
    {
        return $error instanceof CloudException && preg_match('/^member_[a-z_]{1,60}$/D', $error->getMessage())
            ? $error->getMessage() : 'member_operation_failed';
    }

    private function persist(): void { ($this->save)($this->state); }
    private function hash(array $value): string { return hash('sha256', MachineIntentJson::encode($value)); }
    private static function need(bool $condition, string $reason): void { if (!$condition) throw new CloudException($reason); }
}
