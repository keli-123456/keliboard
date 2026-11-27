<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Process-local authority reconstructed from a persisted handover, never from a queued ticket. */
final class MachineFailoverOperatorPermit
{
    public const MODE = 'operator_v1';
    private const WORK = ['runner', 'advance', 'context', 'native_task', 'queue', 'node_readiness', 'dns_readback', 'dns_write',
        'native_address_verify', 'native_address_read', 'native_dns_admission', 'native_dns_prepare', 'native_dns_binding', 'native_dns_complete',
        'dns_set_start', 'dns_set_advance', 'dns_set_transport', 'dns_set_read', 'dns_set_write'];
    private const LEGACY_SWITCHES = ['machine_failover.runner_enabled', 'machine_failover.admission_enabled', 'machine_failover.allow_replacement',
        'machine_failover.allow_rotation', 'machine_failover.allow_candidate_retry', 'machine_provision.allow_live',
        'machine_acceptance.allow_readiness', 'machine_acceptance.allow_verification', 'machine_acceptance.allow_dns'];

    private ?Task $creating = null;

    private function __construct(private readonly string $phase, private readonly string $grant, private readonly ?string $execution,
        private readonly array $policies, private readonly array $plans, private readonly ?string $callback = null) {}

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public static function enabled(): bool
    {
        return config('machine_failover.operator_enabled') === true;
    }

    public static function requireEnabled(): void
    {
        if (!self::enabled()) throw new CloudException('orchestration_disabled');
        foreach (self::LEGACY_SWITCHES as $key) if (config($key) !== false) throw new CloudException('operator_scope_changed');
        $queue = config('queue.connections.' . MachineFailoverQueue::CONNECTION, []);
        if (($queue['driver'] ?? null) !== 'redis' || ($queue['retry_after'] ?? 0) <= MachineFailoverQueue::JOB_TIMEOUT
            || ($queue['block_for'] ?? null) !== null) throw new CloudException('runner_disabled');
    }

    public static function requireConsumer(): void
    {
        try { $ready = Cache::get(MachineFailoverQueue::OPERATOR_HEARTBEAT); }
        catch (\Throwable) { throw new CloudException('runner_disabled'); }
        if (!is_string($ready) || !preg_match('/^[a-f0-9]{32}$/D', $ready)) throw new CloudException('runner_disabled');
    }

    public static function matches(?Execution $run): bool
    {
        return ($run?->payload['launch_mode'] ?? null) === self::MODE;
    }

    public static function issuedForOperator(?Grant $grant): bool
    {
        return ($grant?->stop_receipt['launch_mode'] ?? null) === self::MODE
            && !isset($grant->stop_receipt['manual_usdo1']);
    }

    public static function readback(Grant $grant, int $admin, callable $work): array
    {
        if (!self::issuedForOperator($grant) || !is_string($grant->execution_id))
            throw new CloudException('handover_not_authorized');
        $run = app(MachineFailoverHandover::class)->stoppedOriginal($grant->execution_id, $admin);
        if (!self::matches($run) || ($run->payload['handover_grant_id'] ?? null) !== $grant->id)
            throw new CloudException('operator_scope_changed');
        return (new self('readback', $grant->id, $run->id, [], []))->within($work);
    }

    public static function start(string $grantId, int $service, int $revision, int $release, int $admin, array $planIds, callable $work): array
    {
        self::requireEnabled();
        $grant = Grant::query()->useWritePdo()->find($grantId);
        if (!self::issuedForOperator($grant)) throw new CloudException('handover_not_authorized');
        app(MachineFailoverHandover::class)->assertStart($grantId, $service, $revision, $release, $admin);
        $policies = Plan::query()->useWritePdo()->whereIn('id', $planIds)->pluck('policy_id')->all();
        if (count($policies) !== count($planIds) || !$policies) throw new CloudException('operator_scope_changed');
        return (new self('start', $grantId, null, $policies, $planIds))->within($work);
    }

    public static function worker(Execution $run, callable $work): mixed
    {
        return self::running($run)->within($work);
    }

    public static function forCallback(Task $task): ?self
    {
        $id = $task->payload['coordinated_attempt'] ?? null;
        if (!self::enabled() || !is_string($id) || !Schema::hasTable('v2_machine_failover_attempt')) return null;
        $attempt = Attempt::query()->useWritePdo()->find($id);
        if (!$attempt || $attempt->task_id !== $task->id) return null;
        $member = Member::query()->useWritePdo()->find($attempt->execution_member_id);
        $run = $member ? Execution::query()->useWritePdo()->find($member->execution_id) : null;
        return self::matches($run) ? self::running($run, $task->id) : null;
    }

    private static function running(Execution $run, ?string $callback = null): self
    {
        self::requireEnabled();
        $grant = $run->payload['handover_grant_id'] ?? null;
        if (!self::matches($run) || !is_string($grant) || $run->status !== 'running') throw new CloudException('handover_not_authorized');
        app(MachineFailoverHandover::class)->assertNewWork($run->id);
        $members = Member::query()->useWritePdo()->where('execution_id', $run->id)->get(['policy_id', 'plan_id']);
        return new self($callback === null ? 'worker' : 'callback', $grant, $run->id,
            $members->pluck('policy_id')->all(), $members->pluck('plan_id')->all(), $callback);
    }

    public function within(callable $work): mixed
    {
        if (self::current() !== null) throw new CloudException('operator_scope_changed');
        $container = app();
        $container->instance(self::class, $this);
        try { return $work(); }
        finally { $container->forgetInstance(self::class); }
    }

    public function allows(string $operation): bool
    {
        if ($this->phase === 'readback') return in_array($operation, ['handover_readback', 'dns_set_read'], true);
        if (!self::enabled()) return false;
        return match ($this->phase) {
            'start' => $operation === 'start',
            'worker' => in_array($operation, self::WORK, true),
            'callback' => in_array($operation, ['context', 'native_task'], true),
            default => false,
        };
    }

    public function requireReadbackRun(string $execution): void
    {
        if ($this->phase !== 'readback' || $this->execution !== $execution) throw new CloudException('operator_scope_changed');
    }

    public function allowsStart(?string $grant, array $plans): bool
    {
        return $this->allows('start') && $this->grant === $grant && $this->plans === $plans;
    }

    public function allowsPolicy(int $policy): bool
    {
        return self::enabled() && $this->phase !== 'callback' && in_array($policy, $this->policies, true);
    }

    public function allowsPlan(int $plan): bool
    {
        return self::enabled() && $this->phase !== 'callback' && in_array($plan, $this->plans, true);
    }

    public function requireRun(Execution $run, bool $requireLease): void
    {
        if (!$this->allows('context') || ($requireLease ? $this->phase !== 'worker' : $this->phase !== 'callback')
            || $this->execution !== $run->id || !self::matches($run) || ($run->payload['handover_grant_id'] ?? null) !== $this->grant)
            throw new CloudException('operator_scope_changed');
    }

    public function allowsAttempt(?Attempt $attempt): bool
    {
        if (!$this->allows('queue') || !$attempt) return false;
        $member = Member::query()->useWritePdo()->find($attempt->execution_member_id);
        return $member && $member->execution_id === $this->execution && $member->attempt_id === $attempt->id
            && $this->allowsPolicy($member->policy_id);
    }

    public function allowsTask(Task $task): bool
    {
        if ($this->phase === 'callback') return self::enabled() && $this->callback === $task->id;
        $id = $task->payload['coordinated_attempt'] ?? null;
        if (!$this->allows('queue') || !is_string($id)) return false;
        $attempt = Attempt::query()->useWritePdo()->find($id);
        return $attempt?->task_id === $task->id && $this->allowsAttempt($attempt);
    }

    public function create(Task $task, callable $work): array
    {
        self::requireEnabled();
        if (!$this->allowsTask($task) || !$task->dispatched_at || $task->status !== 'dispatching' || $this->creating !== null)
            throw new CloudException('operator_scope_changed');
        $this->creating = $task;
        try { return $work(); }
        finally { $this->creating = null; }
    }

    public static function allowsCreate(Account $account, array $request, int $since): bool
    {
        $scope = self::current(); $task = $scope?->creating;
        if (!$task || !$scope->allowsTask($task) || $account->id !== $task->account_id || $account->revision !== $task->account_revision
            || $since !== $task->dispatched_at || !MachineIntentJson::same($request, $task->payload['request'])) return false;
        app(MachineFailoverNativeBridge::class)->assertTask($task);
        $scope->creating = null;
        return true;
    }
}
