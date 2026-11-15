<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask as Task;
use Illuminate\Support\Facades\DB;

// Agent/configuration/IPv4 readiness is not a protocol-client forwarding receipt.
class MachineInstanceReadiness
{
    public function __construct(private MachineInstanceAcceptance $acceptance, private MachineAcceptanceEvidence $evidence,
        private MachineProvisionProviders $cloud, private MachineFailoverExecutionGate $gate) {}

    public function prepare(Instance $initial, Policy $policy, int $admin): array
    {
        return $this->acceptance->locked($policy->id, function ($currentPolicy) use ($initial, $policy, $admin) {
            if ($currentPolicy->revision !== $policy->revision) throw new CloudException('config_changed');
            $instance = $initial->fresh();
            [$task, $account] = $this->scope($instance, $currentPolicy, $admin);
            if (isset($task->payload['node_readiness'])) return $this->requireReady($instance, $currentPolicy, $admin);
            if ($instance->active_policy_id !== null || $task->status !== 'awaiting_acceptance') throw new CloudException('candidate_not_ready');
            $before = $this->evidence->capture($instance, $currentPolicy);
            $this->remote($instance, $task, $account);
            return DB::transaction(function () use ($instance, $currentPolicy, $admin, $before) {
                $instance = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
                [$task] = $this->scope($instance, $currentPolicy->fresh(), $admin);
                $task = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                if (isset($task->payload['node_readiness'])) throw new CloudException('readiness_evidence_changed');
                if (!MachineIntentJson::same($before, $this->evidence->capture($instance, $currentPolicy)))
                    throw new CloudException('readiness_evidence_changed');
                $attempt = MachineFailoverAttempt::query()->findOrFail($task->payload['coordinated_attempt']);
                $member = MachineFailoverExecutionMember::query()->findOrFail($attempt->execution_member_id);
                $run = MachineFailoverExecution::query()->findOrFail($member->execution_id);
                $until = min($run->deadline_at, time() + 1200);
                if ($until <= time()) throw new CloudException('native_intent_expired');
                $resource = app(MachineInstanceOwnership::class)->register($currentPolicy, $instance, $task);
                $instance->fill(['resource_id' => $resource->id, 'status' => 'ready', 'revision' => $instance->revision + 1])->save();
                $proof = ['schema' => 1, 'kind' => MachineFailoverWorkflow::READINESS, 'issued_at' => time(),
                    'expires_at' => $until, 'context' => $before, 'binding' => $this->binding($instance, $currentPolicy, $task),
                    'protocol_verified' => false];
                $proof['seal'] = $this->seal($proof);
                $payload = $task->payload; $payload['node_readiness'] = $proof;
                $task->fill(['payload' => $payload, 'evidence' => array_replace($task->evidence ?? [], ['readiness_verified' => true])])->save();
                return $proof;
            });
        });
    }

    public function requireReady(Instance $instance, Policy $policy, int $admin, bool $remote = true): array
    {
        [$task, $account] = $this->scope($instance, $policy, $admin);
        $proof = $this->record($instance, $policy, $task);
        if (!MachineIntentJson::same($proof['context'], $this->evidence->capture($instance, $policy)))
            throw new CloudException('readiness_evidence_changed');
        $resource = MachineCloudResource::query()->find($instance->resource_id);
        if (!$resource || $resource->provider !== $account->provider || $resource->account_id !== $task->account_id
            || $resource->external_id !== $task->external_id || $resource->provider_created_at !== $task->provider_created_at
            || $resource->machine_id !== $policy->machine_id || $resource->installation_id !== $instance->installation_id
            || !is_string($resource->machine_authority) || !hash_equals($resource->machine_authority, $task->machine_authority)
            || $resource->status !== 'running' || $resource->auto_boot || $resource->armed || $resource->active_task_id
            || !in_array($instance->ip, $resource->public_ips ?? [], true)) throw new CloudException('readiness_evidence_changed');
        if ($remote) $this->remote($instance, $task, $account);
        $this->scope($instance, $policy->fresh(), $admin);
        if (!MachineIntentJson::same($proof['context'], $this->evidence->capture($instance, $policy)))
            throw new CloudException('readiness_evidence_changed');
        return $this->record($instance, $policy, $task->fresh());
    }

    public function record(Instance $instance, Policy $policy, ?Task $task = null, bool $historical = false): array
    {
        $task ??= Task::query()->findOrFail($instance->task_id);
        $proof = $task->payload['node_readiness'] ?? null;
        if (!is_array($proof) || ($proof['schema'] ?? null) !== 1 || ($proof['kind'] ?? null) !== MachineFailoverWorkflow::READINESS
            || ($proof['protocol_verified'] ?? null) !== false || !is_int($proof['issued_at'] ?? null) || !is_int($proof['expires_at'] ?? null)
            || $proof['issued_at'] > time() || (!$historical && $proof['expires_at'] <= time())
            || $proof['expires_at'] <= $proof['issued_at'] || $proof['expires_at'] > $proof['issued_at'] + 1200
            || !MachineIntentJson::same($proof['binding'] ?? null, $this->binding($instance, $policy, $task))
            || !is_array($proof['context'] ?? null) || !is_string($proof['seal'] ?? null)
            || !hash_equals($proof['seal'], $this->seal(array_diff_key($proof, ['seal' => true])))) throw new CloudException('readiness_expired');
        return $proof;
    }

    private function scope(Instance $instance, Policy $policy, int $admin): array
    {
        $this->gate->assertAllowed('node_readiness');
        $this->acceptance->gate($policy->id, 'allow_readiness');
        [$task, $account] = $this->acceptance->scope($instance, $policy, $policy->revision, $admin);
        if (MachineFailoverWorkflow::forTask($task) !== MachineFailoverWorkflow::READINESS) throw new CloudException('readiness_workflow_required');
        return [$task, $account];
    }

    private function remote(Instance $instance, Task $task, MachineCloudAccount $account): void
    {
        $snapshot = $this->cloud->client($account, $task->payload['plan'])->reconcile($account, $task->payload['request'], $task->dispatched_at);
        if (!$snapshot || $snapshot['external_id'] !== $task->external_id || $snapshot['provider_created_at'] !== $task->provider_created_at
            || $snapshot['status'] !== 'running' || !in_array($instance->ip, $snapshot['public_ips'], true)) throw new CloudException('provider_identity_changed');
    }

    private function binding(Instance $instance, Policy $policy, Task $task): array
    {
        return ['instance' => $instance->only(['id', 'policy_id', 'policy_revision', 'task_id', 'installation_id', 'release_id',
                    'resource_id', 'binary_sha256', 'version', 'ip']), 'policy_revision' => $policy->revision,
            'task' => $task->only(['id', 'admin_id', 'account_id', 'account_revision', 'machine_id', 'machine_authority',
                'installation_id', 'marker', 'external_id', 'provider_created_at', 'dispatched_at', 'bootstrap_state']),
            'attempt_id' => $task->payload['coordinated_attempt'] ?? null, 'plan_hash' => $task->plan_hash];
    }

    private function seal(array $proof): string
    {
        $key = config('app.key');
        if (!is_string($key) || strlen($key) < 32) throw new CloudException('readiness_authority_missing');
        return hash_hmac('sha256', 'node-readiness-v1:' . MachineIntentJson::encode($proof), $key);
    }
}
