<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineDnsSetRuntime;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate as Gate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge as Native;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverWorkflow;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport as DnsTransport;

require_once __DIR__ . '/usdo1_existing_a.php';

/** Real application services with a test-only adapter. No live capability is issued here. */
final class Usdo1Native
{
    private const OPS = ['start', 'advance', 'context', 'queue', 'native_task', 'node_readiness', 'native_address_verify',
        'native_address_read', 'native_dns_admission', 'native_dns_prepare', 'native_dns_binding', 'native_dns_complete',
        'dns_write', 'dns_readback', 'dns_set_start', 'dns_set_read', 'dns_set_transport', 'dns_set_advance'];
    private const TERMINAL = ['completed', 'stopped', 'cancelled', 'retained', 'needs_review'];
    private string $binding;
    private bool $active = false;

    public function __construct(private array $scope, private \Closure $load, private \Closure $save)
    {
        $this->isolated();
        foreach (['service_id', 'service_revision', 'plan_id', 'policy_id', 'release_id', 'admin_id', 'account_id'] as $key) {
            self::need(is_int($scope[$key] ?? null) && $scope[$key] > 0, 'scope_invalid');
        }
        self::need(is_string($scope['run_id'] ?? null) && preg_match('/^keli-accept-[a-z0-9-]{8,70}$/D', $scope['run_id']) === 1
            && is_string($scope['record_id'] ?? null) && preg_match('/^[0-9]{1,40}$/D', $scope['record_id']) === 1
            && is_string($scope['source_task_id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/D', $scope['source_task_id']) === 1
            && is_int($scope['stop_new_work_at'] ?? null) && $scope['stop_new_work_at'] > 0, 'scope_invalid');
        $this->binding = self::hash($scope);
    }

    private static function need(bool $ok, string $reason): void
    { if (!$ok) throw new CloudException('usdo1_native_' . $reason); }

    private static function hash(array $data): string { return hash('sha256', Json::encode($data)); }

    private function isolated(): void
    {
        self::need(app()->runningUnitTests() && app('db')->connection()->getDriverName() === 'sqlite'
            && app('db')->connection()->getDatabaseName() === ':memory:'
            && config('machine_failover.runner_enabled') === false && config('machine_failover.allow_rotation') === false,
            'isolated_environment_required');
    }

    private function forward(): void
    {
        $this->isolated();
        self::need(($this->load)('native-stop') === null && time() < $this->scope['stop_new_work_at'], 'fenced');
    }

    private function persist(string $key, array $value): void
    {
        ($this->save)($key, $value);
        self::need(Json::same(($this->load)($key), $value), 'journal_unconfirmed');
    }

    private function intent(): ?array
    {
        $data = ($this->load)('native-start');
        self::need($data === null || is_array($data) && ($data['binding'] ?? null) === $this->binding
            && preg_match('/^[a-f0-9]{32}$/D', $data['request'] ?? '') === 1
            && preg_match('/^[a-f0-9]{64}$/D', $data['episode'] ?? '') === 1, 'intent_changed');
        return $data;
    }

    public function start(string $request, string $episode): array
    {
        $this->forward();
        self::need(preg_match('/^[a-f0-9]{32}$/D', $request) === 1 && preg_match('/^[a-f0-9]{64}$/D', $episode) === 1
            && MachineFailoverWorkflow::configured() === MachineFailoverWorkflow::READINESS, 'intent_invalid');
        $intent = ['binding' => $this->binding, 'request' => $request, 'episode' => $episode];
        if (($old = $this->intent()) !== null) {
            self::need(Json::same($old, $intent), 'intent_changed');
            return $this->status();
        }
        $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($this->scope['service_id'], $this->scope['service_revision']);
        $members = $snapshot['members'];
        $sets = $snapshot['dns_sets'] ?? [];
        self::need(count($members) === 1 && $members[0]['policy_id'] === $this->scope['policy_id']
            && $members[0]['provider'] === 'linode' && $members[0]['attempt_limit'] === 1
            && $snapshot['service']['attempt_limit'] === 1 && $snapshot['service']['max_parallel'] === 1
            && count($sets) === 1, 'single_source_required');
        $this->assertSet($sets[0], $members[0]['id']);
        $plan = Plan::query()->useWritePdo()->find($this->scope['plan_id']);
        $source = Instance::query()->useWritePdo()->where('active_policy_id', $this->scope['policy_id'])->first();
        self::need($plan && $plan->policy_id === $this->scope['policy_id'] && ($plan->evidence['episode_key'] ?? null) === $episode
            && $source && $source->task_id === $this->scope['source_task_id'], 'source_changed');
        $task = Task::query()->useWritePdo()->find($source->task_id);
        $policy = Policy::query()->useWritePdo()->find($this->scope['policy_id']);
        self::need($task && $task->account_id === $this->scope['account_id'] && $task->status === 'accepted'
            && !in_array($task->machine_id, [8, 17], true) && $policy && $policy->failure_threshold === 3
            && (app(MachineFailoverCredentials::class)->context($policy)['account_id'] ?? null) === $this->scope['account_id'], 'source_changed');
        $this->persist('native-start', $intent);
        $this->within(null, fn () => app(Native::class)->start($this->scope['service_id'], $this->scope['service_revision'],
            [$this->scope['plan_id']], $this->scope['release_id'], $request, $this->scope['admin_id']));
        return $this->status();
    }

    private function assertSet(array $set, int $member): void
    {
        $slots = $set['slots'] ?? [];
        self::need(($set['provider'] ?? null) === 'aliyun' && ($set['zone'] ?? null) === Usdo1ExistingA::ZONE
            && ($set['hostname'] ?? null) === Usdo1ExistingA::NAME && ($set['ttl'] ?? null) === 600 && count($slots) === 1
            && $slots[0]['type'] === 'A' && $slots[0]['line'] === 'default' && $slots[0]['member_id'] === $member, 'record_scope_changed');
    }

    private function original(): array
    {
        $intent = $this->intent();
        if ($intent === null) return [null, null, null, null];
        $key = hash('sha256', json_encode([$this->scope['admin_id'], $intent['request']], JSON_THROW_ON_ERROR));
        $run = Execution::query()->useWritePdo()->where('request_key', $key)->first();
        if ($run === null) return [null, null, null, null];
        self::need($run->service_id === $this->scope['service_id'] && $run->service_revision === $this->scope['service_revision']
            && $run->admin_id === $this->scope['admin_id'] && MachineFailoverWorkflow::forRun($run) === MachineFailoverWorkflow::READINESS
            && ($run->payload['release']['id'] ?? null) === $this->scope['release_id'], 'execution_changed');
        $members = Member::query()->useWritePdo()->where('execution_id', $run->id)->get();
        self::need($members->count() === 1 && $members[0]->policy_id === $this->scope['policy_id']
            && $members[0]->plan_id === $this->scope['plan_id'] && $members[0]->episode_key === $intent['episode'], 'execution_changed');
        $member = $members[0];
        $sets = $run->payload['snapshot']['dns_sets'] ?? [];
        self::need(count($sets) === 1 && isset($member->payload['dns_source']) && !isset($member->payload['dns']), 'record_scope_changed');
        $this->assertSet($sets[0], $member->member_id);
        $attempts = Attempt::query()->useWritePdo()->where('execution_member_id', $member->id)->get();
        self::need($attempts->count() <= 1 && $attempts->first()?->id === $member->attempt_id, 'attempt_changed');
        $attempt = $attempts->first();
        if (!$attempt) return [$run, $member, null, null];
        self::need($attempt->ordinal === 1 && $attempt->account_id === $this->scope['account_id'], 'attempt_changed');
        $task = $attempt->task_id ? Task::query()->useWritePdo()->find($attempt->task_id) : null;
        self::need(!$attempt->task_id || $task !== null, 'task_missing');
        self::need(!$task || $task->id !== $this->scope['source_task_id'], 'source_task_reused');
        self::need(!$task || app(Native::class)->bindingCurrent($attempt, $task), 'task_binding_changed');
        return [$run, $member, $attempt, $task];
    }

    public function status(): array
    {
        $this->isolated();
        [$run, $member, $attempt, $task] = $this->original();
        return ['execution_id' => $run?->id, 'phase' => $member?->phase, 'status' => $run?->status, 'reason' => $member?->reason,
            'attempt_id' => $attempt?->id, 'task' => $task?->only(['id', 'external_id', 'account_id', 'installation_id',
                'provider_created_at', 'dispatched_at', 'status', 'bootstrap_state', 'marker', 'public_ips'])];
    }

    public function advance(?Usdo1ExistingA $publisher = null): array
    {
        $this->forward();
        [$run, , $attempt, $task] = $this->original();
        self::need($run !== null, 'start_readback_required');
        // A rejected/dispatched intent is never replaced by another attempt in this round.
        if ($attempt && in_array($attempt->status, ['rejected', 'cleaned'], true)) return $this->status();
        if ($task && $task->status === 'outcome_unknown') return $this->status();
        $this->within($publisher, fn () => app(Native::class)->advance($run->id, $this->scope['admin_id']));
        return $this->status();
    }

    private function within(?Usdo1ExistingA $publisher, \Closure $work): mixed
    {
        self::need(!$this->active, 'reentered');
        $originalGate = app(Gate::class);
        $app = app();
        $originalTransport = $app->resolved(DnsTransport::class) ? $app->make(DnsTransport::class) : null;
        $this->active = true;
        $app->instance(Gate::class, new class($originalGate, function (string $operation) use ($publisher): void {
            $this->forward();
            if ($operation === 'dns_set_advance' && $publisher === null) throw new CloudException('replacement_evidence_pending');
            self::need(in_array($operation, self::OPS, true), 'operation_forbidden');
            if (in_array($operation, ['queue', 'native_task', 'dns_write', 'dns_readback'], true)) $this->original();
        }) extends Gate {
            public function __construct(private Gate $original, private \Closure $check) {}
            public function assertAllowed(string $operation): void
            { ($this->check)($operation); $this->original->assertAllowed($operation); }
        });
        // Bind only the concrete transport needed by the native DNS engine; never use its HTTP writer.
        try {
            $app->instance(DnsTransport::class, new class($this->publication(...), $publisher) extends DnsTransport {
                public function __construct(private \Closure $permit, private ?Usdo1ExistingA $publisher)
                { parent::__construct(app(\App\Services\ServerMachine\Dns\DnsProviderClient::class), app(Gate::class)); }
                public function write(string $run, array $set, array $action, ?string $knownId = null): string
                {
                    ($this->permit)($run, $set, $action, $knownId);
                    if (!$this->publisher) throw new CloudException('usdo1_native_publisher_required');
                    return $this->publisher->cutover($action);
                }
            });
            return $work();
        } finally {
            if ($originalTransport) $app->instance(DnsTransport::class, $originalTransport);
            else $app->forgetInstance(DnsTransport::class);
            $app->instance(Gate::class, $originalGate);
            $this->active = false;
        }
    }

    private function publication(string $dnsId, array $set, array $action, ?string $knownId): void
    {
        $this->forward();
        [$run, $member, $attempt, $task] = $this->original();
        self::need($run && $member && $attempt && $task, 'attempt_changed');
        $child = DnsRun::query()->useWritePdo()->find($dnsId);
        self::need($child && Json::same($child->payload['snapshot']['set'] ?? null, $set), 'record_scope_changed');
        $this->assertSet($set + ['slots' => $child->payload['snapshot']['slots'] ?? []], $member->member_id);
        $journal = $child?->payload['plan']['actions'] ?? [];
        $instance = Instance::query()->useWritePdo()->where('task_id', $task->id)->first();
        $fields = array_flip(['kind', 'member_id', 'slot_id', 'before', 'after', 'seal']);
        self::need($child && ($attempt->payload['dns_set_runs'][$set['id']] ?? null) === $dnsId
            && $child->request_key === MachineNativeDnsAdmission::requestKey($attempt->id, $set['id'])
            && count($journal) === 1 && ($journal[0]['state'] ?? null) === 'dispatched'
            && Json::same(array_intersect_key($journal[0], $fields), array_intersect_key($action, $fields))
            && $knownId === null && ($action['kind'] ?? null) === 'update' && ($action['before']['id'] ?? null) === $this->scope['record_id']
            && $instance && ($action['after']['content'] ?? null) === $instance->ip, 'publication_changed');
        app(\App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution::class)->assertActionJournal($journal[0], $set);
    }

    public function stop(): void
    {
        $this->isolated();
        if (($this->load)('native-stop') !== null) return;
        [$run] = $this->original();
        $runtime = $run ? Runtime::query()->useWritePdo()->find($run->id) : null;
        $this->persist('native-stop', ['binding' => $this->binding, 'requested_at' => time(), 'execution_id' => $run?->id]);
        if ($run?->status === 'running') app(MachineFailoverRunner::class)->stop($run->id, $runtime?->revision ?? 0,
            substr(self::hash([$this->binding, 'stop']), 0, 32), $this->scope['admin_id']);
    }

    public function stoppedSnapshot(): ?array
    {
        $this->isolated();
        $fence = ($this->load)('native-stop');
        if ($fence === null) return null;
        self::need(($fence['binding'] ?? null) === $this->binding && is_int($fence['requested_at'] ?? null)
            && array_key_exists('execution_id', $fence), 'stop_binding_changed');
        [$run, $member, $attempt, $task] = $this->original();
        if ($this->intent() !== null && $run === null) return null; // An unacknowledged start is not proof of absence.
        self::need($fence['execution_id'] === $run?->id, 'stop_binding_changed');
        if ($run && !in_array($run->status, self::TERMINAL, true)) return null;
        $runtime = $run ? Runtime::query()->useWritePdo()->find($run->id) : null;
        if ($runtime && ($runtime->state !== 'stopped' || $runtime->lease_until !== null || $runtime->ticket_hash !== null)) return null;
        $dns = []; $operations = [];
        $settledAt = max($fence['requested_at'], (int) $runtime?->updated_at, (int) $run?->updated_at);
        if ($task?->dispatched_at !== null) $operations[] = ['operation_id' => self::hash(['cloud_create', $task->id, $task->getRawOriginal()]),
            'known_outcome' => $task->external_id !== null ? 'confirmed_applied' : null];
        foreach (($run?->payload['snapshot']['dns_sets'] ?? []) as $set) {
            if (!$attempt) continue;
            $id = $attempt->payload['dns_set_runs'][$set['id']] ?? null;
            $child = DnsRun::query()->useWritePdo()->where('request_key', MachineNativeDnsAdmission::requestKey($attempt->id, $set['id']))->first();
            self::need($id === $child?->id, 'dns_child_changed');
            if (!$child) continue;
            $lease = MachineDnsSetRuntime::query()->useWritePdo()->find($child->id);
            if ($child->status === 'running' || !$lease || !$lease->finished_at || $lease->lease_until !== null || $lease->lease_hash !== null) return null;
            $dns[] = [$child->getRawOriginal(), $lease->getRawOriginal()];
            $settledAt = max($settledAt, $lease->finished_at);
            $actions = $child->payload['plan']['actions'] ?? [];
            self::need(count($actions) <= 1, 'publication_changed');
            foreach ($actions as $action) {
                self::need(($action['kind'] ?? null) === 'update' && ($action['before']['id'] ?? null) === $this->scope['record_id'], 'publication_changed');
                if ($action['state'] === 'prepared') continue;
                $operations[] = ['operation_id' => self::hash(['native_dns', $child->id, $action]),
                    'known_outcome' => $action['state'] === 'verified' ? 'confirmed_applied' : null];
            }
        }
        return ['binding' => $this->binding, 'record_id' => $this->scope['record_id'], 'settled_at' => $settledAt, 'operations' => $operations,
            'native_sha256' => self::hash([$fence, $run?->getRawOriginal(), $runtime?->getRawOriginal(),
                $member?->getRawOriginal(), $attempt?->getRawOriginal(), $task?->getRawOriginal(), $dns])];
    }
}
