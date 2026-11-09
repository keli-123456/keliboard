<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineCloudTask;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask as Task;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease as Release;
use App\Services\Node\NodeMachineBinding;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MachineIsolatedAcceptance
{
    public function __construct(private MachineInstanceAcceptance $acceptance, private MachineProvisionService $provision,
        private MachineFailoverPolicyService $policies, private MachineForwardingVerifier $verifier,
        private MachineAcceptanceEvidence $evidence, private MachineAddressInventory $addresses) {}

    public function preview(int $id, int $revision, int $releaseId, int $admin): array
    {
        $this->acceptance->actor($admin); $policy = $this->policy($id, $revision); $release = $this->release($releaseId);
        $plan = $this->plan($policy); $accountId = $plan['account_id'];
        $checks = array_values(array_filter($this->policies->localChecks($policy), fn ($check) => !in_array($check['code'], [
            'execution_disabled', 'dns_not_configured', 'dns_credential_missing', 'dns_credential_changed', 'dns_record_conflict',
            'external_ownership_unverified', 'forwarding_not_verified', 'owned_dns_unsupported'], true)));
        $add = function (string $code, bool $passed) use (&$checks) { $checks[] = ['code' => $code, 'status' => $passed ? 'passed' : 'blocked']; };
        $add('provisioning_enabled', config('machine_provision.allow_live') === true);
        $add('test_machine_allowed', in_array($policy->machine_id, config('machine_provision.allowed_test_machine_ids', []), true));
        $add('verification_enabled', config('machine_acceptance.allow_verification') === true
            && in_array($id, config('machine_acceptance.allowed_policy_ids', []), true));
        $add('automation_disabled', $this->isolatedScope($policy));
        $verifier = null;
        try { $verifier = $this->verifier->configuration($id)['fingerprint']; } catch (CloudException) {}
        $add('verifier_configured', $verifier !== null);
        $add('slot_empty', !Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)->exists()
            && !Instance::query()->where('policy_id', $id)->exists());
        $add('account_idle', !Task::query()->where('account_id', $accountId)->whereNotIn('status', ['accepted', 'failed', 'cancelled'])->exists()
            && !MachineCloudTask::query()->whereIn('resource_id', MachineCloudResource::query()->where('account_id', $accountId)->select('id'))
                ->whereNotIn('status', MachineCloudTask::TERMINAL)->exists());
        $attempts = Task::query()->where('account_id', $accountId)->where('created_at', '>=', time() - 86400)->count();
        $limit = min($policy->daily_limit, max(1, min(2, (int) config('machine_provision.daily_limit_per_account', 2))));
        $add('daily_allowance', $attempts < $limit);
        $pin = $this->pin($policy, $release);
        $fingerprint = hash('sha256', json_encode([$plan, $pin, $checks, $verifier], JSON_THROW_ON_ERROR));
        $expires = time() + 120;
        return ['policy_id' => $id, 'policy_revision' => $revision, 'release' => $pin,
            'plan' => ProvisionPlan::summary($plan), 'checks' => $checks, 'attempts' => $attempts, 'daily_limit' => $limit,
            'creation_allowed' => !in_array('blocked', array_column($checks, 'status'), true),
            'provider_checked' => false, 'automatic' => false, 'dns_cutover' => false, 'delete_strategy' => 'keep',
            'fingerprint' => $fingerprint, 'expires_at' => $expires, 'token' => $this->token($fingerprint, $expires, $admin)];
    }

    public function queue(array $input, int $admin): array
    {
        $this->acceptance->actor($admin);
        return Cache::lock('machine-failover:write', 120)->block(2, function () use ($input, $admin) {
            $policy = $this->policy($input['policy_id'], $input['policy_revision']);
            $existing = Task::query()->where('request_key', hash('sha256', $admin . ':' . $input['request_id']))->first();
            if ($existing) {
                $intent = $existing->payload['isolated_acceptance'] ?? [];
                if (($intent['policy_id'] ?? null) !== $policy->id || ($intent['policy_revision'] ?? null) !== $policy->revision
                    || ($intent['release_id'] ?? null) !== $input['release_id']) throw new CloudException('request_conflict');
                return $this->report($policy->id, $policy->revision, $existing->id, null, $admin);
            }
            if ($input['expires_at'] <= time() || $input['expires_at'] > time() + 120
                || !hash_equals($this->token($input['fingerprint'], $input['expires_at'], $admin), $input['token'])) throw new CloudException('precheck_expired');
            $check = $this->preview($policy->id, $policy->revision, $input['release_id'], $admin);
            if (!hash_equals($check['fingerprint'], $input['fingerprint'])) throw new CloudException('precheck_changed');
            if (!$check['creation_allowed']) throw new CloudException('isolated_creation_disabled');
            $task = $this->provision->queueIsolated($this->plan($policy), $input['request_id'], $admin, $check['release']);
            return $this->report($policy->id, $policy->revision, $task['id'], null, $admin);
        });
    }

    public function assertTask(Task $task): void
    {
        $intent = $task->payload['isolated_acceptance'] ?? null;
        if ($intent === null) return;
        $policy = $this->policy($intent['policy_id'], $intent['policy_revision']);
        $release = $this->release($intent['release_id']);
        if ($intent !== $this->pin($policy, $release, $task) || ProvisionPlan::normalize($task->payload['plan']) !== $this->plan($policy, $task)) throw new CloudException('isolated_context_changed');
        if (!$policy->isolation_confirmed) throw new CloudException('isolation_confirmation_required');
        if (!$this->isolatedScope($policy)) throw new CloudException('isolated_scope_changed');
        if (config('machine_acceptance.allow_verification') !== true
            || !in_array($policy->id, config('machine_acceptance.allowed_policy_ids', []), true)) throw new CloudException('isolated_context_changed');
    }

    public function report(int $id, int $revision, ?string $taskId, ?string $requestId, int $admin): array
    {
        $this->acceptance->actor($admin); $policy = $this->policy($id, $revision);
        $query = Task::query()->where('machine_id', $policy->machine_id)->where('slot', $policy->slot)->latest('created_at');
        if ($taskId !== null) $query->whereKey($taskId);
        if ($requestId !== null) $query->where('request_key', hash('sha256', $admin . ':' . $requestId));
        $task = $query->first();
        $result = ['policy_id' => $id, 'policy_revision' => $revision, 'checked_at' => time(), 'automatic' => false,
            'dns_cutover' => false, 'delete_strategy' => 'keep', 'fixture_revocation_verified' => false,
            'task' => null, 'instance' => null, 'probe' => null, 'verdict' => 'not_started', 'reason' => null,
            'evidence_current' => false, 'forwarding' => null, 'temporary_client_cleanup' => false];
        if (!$task) return $result;
        $intent = $task->payload['isolated_acceptance'] ?? null;
        if (($intent['policy_id'] ?? null) !== $id) {
            if ($taskId !== null || $requestId !== null) throw new CloudException('ownership_mismatch');
            $result['verdict'] = 'blocked'; $result['reason'] = 'slot_occupied'; return $result;
        }
        $result['task'] = $task->only(['id', 'status', 'reason', 'public_ips', 'bootstrap_state', 'created_at', 'dispatched_at', 'deadline_at'])
            + ['installation_id' => $task->installation_id, 'resource_may_exist' => $task->dispatched_at !== null,
                'release' => $intent, 'steps' => DB::table('v2_machine_provision_step')->where('task_id', $task->id)->orderBy('id')->limit(100)->get()];
        $result['verdict'] = in_array($task->status, ['failed', 'cancelled', 'needs_review', 'outcome_unknown'], true) ? 'blocked' : 'pending';
        $result['reason'] = $task->reason;
        $instance = Instance::query()->where('task_id', $task->id)->first();
        if ($instance) $result['instance'] = $instance->only(['id', 'revision', 'policy_id', 'policy_revision', 'task_id', 'installation_id',
            'release_id', 'binary_sha256', 'version', 'ip', 'status', 'resource_id', 'active_policy_id', 'verified_until', 'reason', 'updated_at']);
        try {
            $this->assertTask($task);
            if ($task->account_revision !== MachineCloudAccount::query()->whereKey($task->account_id)->value('revision')) throw new CloudException('account_changed');
            $machine = ServerMachine::query()->findOrFail($policy->machine_id);
            if (!$machine->is_active || !hash_equals($task->machine_authority, hash('sha256', $machine->token))) throw new CloudException('source_changed');
            $rows = $this->addresses->snapshots([$machine], true)[$machine->id] ?? [];
            $probe = $this->addresses->probes(array_intersect_key($rows, [$task->installation_id => true]), time())[$task->installation_id] ?? [];
            if (($probe['address_installation_id'] ?? null) === $task->installation_id && ($probe['checked_at'] ?? 0) > ($task->dispatched_at ?? PHP_INT_MAX)) {
                $result['probe'] = array_intersect_key($probe, array_flip(['status', 'checked_at', 'addresses']));
            }
            if (!$instance || $instance->policy_revision !== $revision || $instance->release_id !== $intent['release_id']
                || $instance->binary_sha256 !== $intent['binary_sha256']) return $result;
            $result['reason'] = $instance->reason ?: $task->reason;
            if ($instance->reason) $result['verdict'] = 'blocked';
            $receipt = $instance->verification;
            if ($task->status !== 'awaiting_acceptance') return $result;
            if (!$receipt || ($instance->verified_until ?? 0) <= time()) {
                if ($receipt) $result['reason'] = 'acceptance_expired';
                return $result;
            }
            $this->acceptance->scope($instance, $policy, $revision, $admin);
            if (($receipt['context'] ?? null) !== $this->evidence->capture($instance, $policy)) throw new CloudException('acceptance_evidence_changed');
            $result['evidence_current'] = true;
            $result['forwarding'] = ['finished_at' => $receipt['finished_at'], 'results' => $receipt['results']];
            $result['temporary_client_cleanup'] = ($receipt['cleanup_complete'] ?? null) === true;
            $result['verdict'] = $result['temporary_client_cleanup'] ? 'passed' : 'blocked';
        } catch (CloudException $error) { $result['verdict'] = 'blocked'; $result['reason'] = $error->getMessage(); }
        return $result;
    }

    private function policy(int $id, int $revision): Policy
    {
        if (!$this->acceptance->available() || !$this->provision->available() || !$this->policies->available()) throw new CloudException('migration_required');
        $policy = Policy::query()->findOrFail($id);
        if ($policy->revision !== $revision) throw new CloudException('config_changed');
        return $policy;
    }

    private function release(int $id): Release
    {
        $release = Release::query()->find($id);
        if (!$release || $release->component !== 'kelinode-rs' || $release->platform !== 'linux-x86_64' || $release->status !== 'active'
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $release->binary_sha256)) throw new CloudException('release_changed');
        return $release;
    }

    private function pin(Policy $policy, Release $release, ?Task $task = null): array
    {
        $verifier = null;
        try { $verifier = $this->verifier->configuration($policy->id)['fingerprint']; } catch (CloudException) {}
        $bindings = NodeMachineBinding::available() ? DB::table('v2_server_machine_binding')->where('machine_id', $policy->machine_id)->pluck('server_id') : [];
        $nodes = Server::query()->where('enabled', true)->where(fn ($query) => $query->where('machine_id', $policy->machine_id)->orWhereIn('id', $bindings))->orderBy('id')->get();
        $projection = $nodes->map(fn ($node) => array_intersect_key($node->getAttributes(), array_flip(['id', 'type', 'host', 'port', 'server_port',
            'network', 'protocol_settings', 'password', 'group_ids', 'route_ids', 'enabled', 'machine_id', 'parent_id'])))->all();
        $context = app(MachineFailoverCredentials::class)->context($policy, $task);
        $group = isset($context['credential_pool']) ? ['credential_fingerprint' => hash('sha256', json_encode($context, JSON_THROW_ON_ERROR))] : [];
        return $group + ['schema' => 1, 'policy_id' => $policy->id, 'policy_revision' => $policy->revision, 'release_id' => $release->id,
            'version' => $release->version, 'binary_sha256' => $release->binary_sha256,
            'verifier_fingerprint' => $verifier,
            'node_fingerprint' => hash('sha256', json_encode($projection, JSON_THROW_ON_ERROR))];
    }

    private function isolatedScope(Policy $policy): bool
    {
        return !(config('machine_failover.allow_replacement') === true && in_array($policy->id, config('machine_failover.allowed_replacement_policy_ids', []), true))
            && !(config('machine_acceptance.allow_dns') === true && in_array($policy->id, config('machine_acceptance.allowed_policy_ids', []), true));
    }

    private function plan(Policy $policy, ?Task $task = null): array
    {
        return ProvisionPlan::normalize(app(MachineFailoverCredentials::class)->context($policy, $task) + ['machine_id' => $policy->machine_id, 'slot' => $policy->slot,
            'panel_origin' => app(ProvisionBootstrap::class)->configuredOrigin(), 'region' => $policy->region, 'size' => $policy->size,
            'image' => $policy->image, 'authorized_keys' => [], 'monthly_limit_usd' => $policy->monthly_limit_usd]);
    }

    private function token(string $fingerprint, int $expires, int $admin): string
    {
        return hash_hmac('sha256', 'isolated-precheck:' . $admin . ':' . $expires . ':' . $fingerprint, (string) config('app.key'));
    }
}
