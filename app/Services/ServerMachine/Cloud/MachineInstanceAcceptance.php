<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineAcceptanceCheck as Check;
use App\Models\MachineCloudResource;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MachineInstanceAcceptance
{
    public function __construct(private LinodeProvisionClient $cloud, private MachineAcceptanceEvidence $evidence,
        private MachineForwardingVerifier $verifier) {}

    public function available(): bool { return Schema::hasTable('v2_machine_owned_instance') && Schema::hasTable('v2_machine_owned_dns') && Schema::hasTable('v2_machine_dns_transition') && Schema::hasTable('v2_machine_acceptance_check'); }

    public function overview(): array
    {
        return ['available' => $this->available(), 'verification_enabled' => config('machine_acceptance.allow_verification') === true,
            'isolated_wizard_available' => $this->available() && app(MachineProvisionService::class)->available(),
            'dns_enabled' => config('machine_acceptance.allow_dns') === true, 'automatic' => false, 'delete_strategy' => 'keep',
            'instances' => $this->available() ? Instance::query()->latest('id')->limit(100)->get() : [],
            'checks' => $this->available() ? Check::query()->latest('issued_at')->limit(100)->get() : [],
            'policies' => $this->available() ? Policy::query()->orderBy('id')->limit(100)->get()->map(function ($policy) {
                try { $this->verifier->configuration($policy->id); $ready = true; } catch (CloudException) { $ready = false; }
                $allowed = in_array($policy->id, config('machine_acceptance.allowed_policy_ids', []), true);
                return $policy->only(['id', 'name', 'revision', 'machine_id', 'account_id', 'dns_hostname', 'dns_credential_id', 'slot', 'failure_threshold'])
                    + ['verification_allowed' => $allowed && config('machine_acceptance.allow_verification') === true,
                        'dns_allowed' => $allowed && config('machine_acceptance.allow_dns') === true, 'verifier_ready' => $ready];
            }) : [],
            'releases' => $this->available() && Schema::hasTable('v2_server_machine_release') ? ServerMachineRelease::query()
                ->where('component', 'kelinode-rs')->where('status', 'active')->where('platform', 'linux-x86_64')
                ->whereNotNull('binary_sha256')->orderByDesc('id')->limit(100)->get(['id', 'version', 'binary_sha256']) : []];
    }

    public function enroll(int $policyId, int $revision, string $taskId, int $releaseId, string $ip, int $admin): array
    {
        return $this->locked($policyId, function ($policy) use ($revision, $taskId, $releaseId, $ip, $admin) {
            $this->actor($admin); $this->revision($policy, $revision);
            $task = Task::query()->findOrFail($taskId); $this->task($policy, $task);
            if (isset($task->payload['isolated_acceptance'])) {
                app(MachineIsolatedAcceptance::class)->assertTask($task);
                if ($task->payload['isolated_acceptance']['release_id'] !== $releaseId) throw new CloudException('isolated_release_mismatch');
            }
            if ($existing = Instance::query()->where('task_id', $taskId)->first()) {
                if ($existing->policy_id !== $policy->id || $existing->release_id !== $releaseId || $existing->ip !== $ip) throw new CloudException('request_conflict');
                return $existing->toArray();
            }
            if ($task->status !== 'awaiting_acceptance' || $task->bootstrap_state !== 'succeeded') throw new CloudException('candidate_not_ready');
            $release = $this->release($releaseId);
            if (MachineEntryProbe::publicIp($ip) !== $ip || str_contains($ip, ':') || !in_array($ip, $task->public_ips ?? [], true)) throw new CloudException('candidate_address_mismatch');
            $this->exclusive($policy, $task->installation_id);
            return Instance::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'task_id' => $task->id,
                'installation_id' => $task->installation_id, 'release_id' => $release->id, 'binary_sha256' => strtolower($release->binary_sha256),
                'version' => $release->version, 'ip' => $ip, 'status' => 'candidate'])->fresh()->toArray();
        });
    }

    public function verify(int $id, int $policyRevision, int $revision, int $admin): array
    {
        if (!$this->available()) throw new CloudException('migration_required');
        $initial = Instance::query()->findOrFail($id);
        return $this->locked($initial->policy_id, function ($policy) use ($id, $policyRevision, $revision, $admin) {
            $this->gate($policy->id, 'allow_verification'); $instance = Instance::query()->findOrFail($id);
            if ($instance->revision !== $revision) throw new CloudException('config_changed');
            [$task, $account] = $this->scope($instance, $policy, $policyRevision, $admin);
            if (Check::query()->where('instance_id', $instance->id)->where('state', 'running')->where('deadline_at', '>', time())->exists()) throw new CloudException('acceptance_pending');
            $configuration = $this->verifier->configuration($policy->id);
            $this->remote($instance, $task, $account);
            $before = $this->evidence->capture($instance, $policy);
            $id = bin2hex(random_bytes(16)); $frames = [random_bytes(32), random_bytes(32), random_bytes(32)];
            $challenge = ['schema' => 1, 'challenge_id' => $id, 'issued_at' => time(), 'deadline_at' => time() + 45,
                'profile' => $configuration['profile'], 'task_id' => $task->id, 'installation_id' => $instance->installation_id,
                'target_ip' => $instance->ip, 'configuration_fingerprint' => $before['configuration_fingerprint'], 'session' => $before['session'],
                'binary_sha256' => $instance->binary_sha256, 'nodes' => $before['nodes'], 'echo_frames' => array_map('base64_encode', $frames),
                'echo_sha256' => hash('sha256', implode('', $frames))];
            Check::query()->create(['id' => $id, 'instance_id' => $instance->id, 'policy_revision' => $policy->revision,
                'request_sha256' => hash('sha256', json_encode($challenge, JSON_THROW_ON_ERROR)), 'state' => 'running',
                'issued_at' => $challenge['issued_at'], 'deadline_at' => $challenge['deadline_at']]);
            $instance->fill(['challenge_id' => $id, 'challenge_deadline' => $challenge['deadline_at'], 'verification' => null,
                'verified_until' => null, 'revision' => $instance->revision + 1, 'policy_revision' => $policy->revision])->save();
            try {
                $receipt = $this->verifier->verify($challenge, $configuration);
                $this->gate($policy->id, 'allow_verification'); $this->scope($instance, $policy->fresh(), $policyRevision, $admin);
                if ($this->verifier->configuration($policy->id)['fingerprint'] !== $configuration['fingerprint']) throw new CloudException('verifier_changed');
                if ($this->evidence->capture($instance, $policy) !== $before || time() > $challenge['deadline_at']) throw new CloudException('acceptance_evidence_changed');
                $this->remote($instance, $task, $account);
                if (time() > $challenge['deadline_at']) throw new CloudException('acceptance_expired');
                $this->scope($instance, $policy->fresh(), $policyRevision, $admin);
                if ($this->evidence->capture($instance, $policy) !== $before) throw new CloudException('acceptance_evidence_changed');
                DB::transaction(function () use ($instance, $policy, $task, $receipt, $before, $id, $admin, $policyRevision, $configuration) {
                    $current = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
                    if ($current->challenge_id !== $id || $current->revision !== $instance->revision) throw new CloudException('acceptance_evidence_changed');
                    $this->scope($instance, $policy->fresh(), $policyRevision, $admin);
                    $this->gate($policy->id, 'allow_verification');
                    if (time() > $instance->challenge_deadline || $this->evidence->capture($instance, $policy) !== $before
                        || $this->verifier->configuration($policy->id)['fingerprint'] !== $configuration['fingerprint']) throw new CloudException('acceptance_evidence_changed');
                    $resource = MachineCloudResource::query()->where('provider', 'linode')->where('external_id', $task->external_id)->first();
                    if ($resource && ($resource->account_id !== $task->account_id || $resource->provider_created_at !== $task->provider_created_at
                        || ($resource->machine_id && ($resource->machine_id !== $policy->machine_id || $resource->installation_id !== $instance->installation_id))
                        || $resource->auto_boot || $resource->armed || $resource->active_task_id)) throw new CloudException('resource_already_owned');
                    $resource ??= new MachineCloudResource();
                    $resource->fill(['account_id' => $task->account_id, 'provider' => 'linode', 'external_id' => $task->external_id,
                        'provider_created_at' => $task->provider_created_at, 'label' => $task->payload['request']['label'],
                        'region' => $policy->region, 'size' => $policy->size, 'status' => 'running', 'public_ips' => $task->public_ips,
                        'machine_id' => $policy->machine_id, 'installation_id' => $instance->installation_id, 'machine_authority' => $task->machine_authority,
                        'observed_at' => time(), 'auto_boot' => false, 'armed' => false, 'manual_hold' => true, 'dns_hold' => true])->save();
                    $instance->fill(['resource_id' => $resource->id, 'verification' => $receipt + ['context' => $before],
                        'verified_until' => time() + 120, 'reason' => null, 'challenge_id' => null, 'challenge_deadline' => null,
                        'status' => in_array($instance->status, ['active', 'retained'], true) ? $instance->status : 'verified'])->save();
                    Check::query()->whereKey($id)->update(['state' => 'passed', 'finished_at' => time(), 'receipt_sha256' => $receipt['receipt_sha256']]);
                });
            } catch (\Throwable $error) {
                $reason = $error instanceof CloudException ? $error->getMessage() : 'acceptance_failed';
                Instance::query()->whereKey($instance->id)->where('challenge_id', $id)->where('revision', $instance->revision)
                    ->update(['reason' => $reason, 'challenge_id' => null, 'challenge_deadline' => null, 'verification' => null, 'verified_until' => null]);
                Check::query()->whereKey($id)->update(['state' => 'failed', 'finished_at' => time(), 'reason' => $reason]);
            }
            $old = Check::query()->where('instance_id', $instance->id)->where('id', '!=', $id)->latest('issued_at')->orderByDesc('id')->skip(99)->take(100)->pluck('id');
            Check::query()->whereIn('id', $old)->delete();
            if ($task->replacement_id) app(MachineReplacementService::class)->sync($task->fresh());
            return $instance->fresh()->toArray();
        });
    }

    public function scope(Instance $instance, Policy $policy, int $revision, int $admin): array
    {
        $this->actor($admin); $this->revision($policy, $revision);
        $fields = ['revision', 'policy_id', 'policy_revision', 'task_id', 'installation_id', 'release_id', 'binary_sha256', 'version',
            'ip', 'resource_id', 'active_policy_id', 'status', 'verification', 'verified_until', 'challenge_id', 'challenge_deadline'];
        $stored = $instance->fresh();
        if (!$stored || $stored->only($fields) !== $instance->only($fields)) throw new CloudException('acceptance_evidence_changed');
        if ($instance->policy_id !== $policy->id) throw new CloudException('ownership_mismatch');
        $task = Task::query()->findOrFail($instance->task_id); $account = $this->task($policy, $task);
        if (isset($task->payload['isolated_acceptance'])) {
            app(MachineIsolatedAcceptance::class)->assertTask($task);
            if ($task->payload['isolated_acceptance']['release_id'] !== $instance->release_id) throw new CloudException('isolated_release_mismatch');
        }
        $release = $this->release($instance->release_id);
        if (strtolower($release->binary_sha256) !== $instance->binary_sha256 || $release->version !== $instance->version) throw new CloudException('release_changed');
        $this->exclusive($policy, $instance->installation_id);
        return [$task, $account];
    }

    public function requireVerified(Instance $instance, Policy $policy, int $admin): void
    {
        [$task, $account] = $this->scope($instance, $policy, $policy->revision, $admin);
        if ($instance->policy_revision !== $policy->revision || !$instance->resource_id || $instance->verified_until <= time()
            || empty($instance->verification['cleanup_complete']) || empty($instance->verification['context'])
            || ($instance->verification['verifier_fingerprint'] ?? null) !== $this->verifier->configuration($policy->id)['fingerprint']
            || $this->evidence->capture($instance, $policy) !== $instance->verification['context']) throw new CloudException('acceptance_expired');
        $this->remote($instance, $task, $account);
        $resource = MachineCloudResource::query()->find($instance->resource_id);
        if (!$resource || $resource->account_id !== $task->account_id || $resource->external_id !== $task->external_id
            || $resource->provider_created_at !== $task->provider_created_at || $resource->machine_id !== $policy->machine_id
            || $resource->installation_id !== $instance->installation_id || $resource->auto_boot || $resource->armed || $resource->active_task_id
            || $instance->verified_until <= time() || $this->evidence->capture($instance, $policy) !== $instance->verification['context']) throw new CloudException('acceptance_expired');
        $this->scope($instance, $policy->fresh(), $policy->revision, $admin);
    }

    public function gate(int $policy, string $key): void
    {
        if (config('machine_acceptance.' . $key) !== true || !in_array($policy, config('machine_acceptance.allowed_policy_ids', []), true)) throw new CloudException('acceptance_disabled');
    }

    public function locked(int $policyId, callable $work): mixed
    {
        if (!$this->available()) throw new CloudException('migration_required');
        return Cache::lock('machine-failover:write', 120)->block(2, function () use ($policyId, $work) {
            $policy = Policy::query()->findOrFail($policyId);
            return Cache::lock('machine-cloud:account:' . $policy->account_id, 120)->block(2, fn () => $work($policy->fresh()));
        });
    }

    private function task(Policy $policy, Task $task): MachineCloudAccount
    {
        $machine = ServerMachine::query()->find($policy->machine_id); $account = MachineCloudAccount::query()->find($policy->account_id);
        if (!$policy->isolation_confirmed || !$machine || !$machine->is_active || !$account || $account->provider !== 'linode'
            || $task->account_id !== $policy->account_id || $task->machine_id !== $policy->machine_id || $task->slot !== $policy->slot
            || $account->revision !== $task->account_revision || $policy->account_revision !== $account->revision
            || !hash_equals($task->machine_authority, hash('sha256', (string) $machine->token))
            || !hash_equals($policy->machine_authority, $task->machine_authority)) throw new CloudException('ownership_mismatch');
        $plan = $task->payload['plan']; $request = $task->payload['request'];
        foreach (['account_id', 'machine_id', 'slot', 'region', 'size', 'image'] as $key) if (($plan[$key] ?? null) !== $policy->{$key}) throw new CloudException('ownership_mismatch');
        if (!in_array($task->status, ['awaiting_acceptance', 'accepted'], true) || !$task->dispatched_at || !$task->external_id || !$task->provider_created_at
            || $task->bootstrap_state !== 'succeeded' || ($plan['source_ref'] ?? null) !== []
            || !in_array($task->marker, $request['tags'] ?? [], true)
            || ($plan['panel_origin'] ?? null) !== app(ProvisionBootstrap::class)->configuredOrigin()) throw new CloudException('candidate_not_ready');
        return $account;
    }

    private function remote(Instance $instance, Task $task, MachineCloudAccount $account): void
    {
        $snapshot = $this->cloud->reconcile($account, $task->payload['request'], $task->dispatched_at);
        if (!$snapshot || $snapshot['external_id'] !== $task->external_id || $snapshot['provider_created_at'] !== $task->provider_created_at
            || $snapshot['status'] !== 'running' || !in_array($instance->ip, $snapshot['public_ips'], true)) throw new CloudException('provider_identity_changed');
    }

    private function release(int $id): ServerMachineRelease
    {
        if (!Schema::hasTable('v2_server_machine_release')) throw new CloudException('release_unavailable');
        $release = ServerMachineRelease::query()->find($id);
        if (!$release || $release->component !== 'kelinode-rs' || $release->status !== 'active' || $release->platform !== 'linux-x86_64'
            || !preg_match('/^[a-f0-9]{64}$/Di', $release->binary_sha256 ?? '')) throw new CloudException('release_unavailable');
        return $release;
    }

    private function exclusive(Policy $policy, string $candidate): void
    {
        app(MachineInstanceOwnership::class)->assertExclusive($policy, $candidate);
    }
    private function revision(Policy $policy, int $revision): void { if ($policy->revision !== $revision) throw new CloudException('config_changed'); }
    public function actor(int $admin): void
    {
        $user = User::query()->find($admin, ['id', 'is_admin', 'banned']);
        if (!$user || !$user->is_admin || $user->banned) throw new CloudException('permission_revoked');
    }
}
