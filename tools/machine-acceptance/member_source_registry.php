<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\DB;

/** Isolated source identity registration, not candidate admission or a health override. */
final class MemberSourceRegistry
{
    public function __construct(private array $scope, private \Closure $guard, private \Closure $load,
        private \Closure $save, private \Closure $clock) {}

    private static function need(bool $value, string $field): void
    {
        if (!$value) throw new \RuntimeException('member_source_' . $field);
    }

    private function context(string $role): array
    {
        ($this->guard)('source_identity', $role);
        self::need(in_array($role, ['source-a', 'source-b'], true), 'role_invalid');
        self::need(preg_match('/^keli-accept-[a-z0-9-]{8,80}$/D', $this->scope['run_id'] ?? '') === 1, 'scope_invalid');
        $pin = $this->scope['sources'][$role] ?? [];
        $other = $this->scope['sources'][$role === 'source-a' ? 'source-b' : 'source-a'] ?? [];
        foreach (['task_id', 'machine_id', 'installation_id', 'external_id', 'policy_id'] as $key)
            self::need(isset($pin[$key], $other[$key]) && $pin[$key] !== $other[$key], 'roles_collide');
        $task = Task::query()->findOrFail($pin['task_id']);
        $policy = Policy::query()->findOrFail($pin['policy_id']);
        $machine = ServerMachine::query()->findOrFail($pin['machine_id']);
        $release = ServerMachineRelease::query()->findOrFail($this->scope['release_id']);
        $account = MachineCloudAccount::query()->findOrFail($this->scope['account_id']);
        foreach (['machine_id', 'installation_id', 'external_id', 'marker', 'account_revision', 'provider_created_at'] as $key)
            self::need(isset($pin[$key]) && $task->{$key} === $pin[$key], 'binding_changed');
        self::need($account->provider === 'linode' && $task->account_id === $account->id
            && $task->account_revision === $account->revision && $policy->machine_id === $machine->id
            && $policy->revision === ($pin['policy_revision'] ?? null) && $policy->slot === $task->slot
            && $machine->is_active && $policy->isolation_confirmed
            && app(MachineFailoverCredentials::class)->owns($policy, $task)
            && hash_equals(hash('sha256', $machine->token), $task->machine_authority)
            && hash_equals($policy->machine_authority, $task->machine_authority)
            && !isset($task->payload['coordinated_attempt'])
            && !in_array($task->external_id, $this->scope['baseline_ids'] ?? [], true), 'ownership_invalid');
        self::need($release->component === 'kelinode-rs' && $release->binary_sha256 === $this->scope['binary_sha256']
            && $release->version === $this->scope['version'], 'release_changed');
        self::need($task->bootstrap_state === 'succeeded'
            && in_array($task->status, ['waiting_ipv4_probe', 'awaiting_acceptance', 'accepted'], true), 'bootstrap_missing');
        // Reuse the production exact-ID, UUID label, marker, addresses and creation receipt checks.
        $remote = app(MachineCandidateCleanupClient::class)->inspect($task);
        self::need($remote !== null && $remote['status'] === 'running', 'cloud_missing');
        $reconciled = app(MachineProvisionProviders::class)->client($account, $task->payload['plan'])
            ->reconcile($account, $task->payload['request'], $task->dispatched_at);
        self::need($reconciled !== null && $reconciled['external_id'] === $task->external_id
            && $reconciled['provider_created_at'] === $task->provider_created_at, 'cloud_identity_changed');
        return [$task, $policy, $machine, $release, $pin];
    }

    public function observe(string $role): array
    {
        [$task, , $machine, $release, $pin] = $this->context($role);
        $now = ($this->clock)();
        $row = app(MachineAddressInventory::class)->snapshots([$machine], true)[$machine->id][$task->installation_id] ?? [];
        $runtime = $row['runtime'] ?? [];
        $health = app(MachineCloudHealth::class)->snapshot(new MachineCloudResource([
            'machine_id' => $machine->id, 'installation_id' => $task->installation_id,
            'machine_authority' => $task->machine_authority, 'public_ips' => $task->public_ips]), $task->dispatched_at);
        $valid = is_int($now) && is_int($row['at'] ?? null) && $row['at'] <= $now && $row['at'] >= $now - 30
            && $row['at'] > $task->dispatched_at && $health['ready'] && $health['nodes'] === 'fresh'
            && ($runtime['binary_sha256'] ?? null) === $release->binary_sha256
            && ltrim((string) ($runtime['version'] ?? ''), 'v') === ltrim($release->version, 'v')
            && preg_match('/^[a-f0-9]{32}$/D', $runtime['observation_session'] ?? '') === 1
            && ($runtime['configured_nodes'] ?? null) === ($pin['configured_nodes'] ?? null)
            && !empty($pin['configured_nodes']);
        $old = ($this->load)($role);
        $identity = hash('sha256', json_encode([$this->scope['run_id'], $pin, $release->binary_sha256], JSON_THROW_ON_ERROR));
        self::need($old === null || ($old['identity'] ?? null) === $identity, 'journal_identity_changed');
        $state = $old ?? ['identity' => $identity, 'samples' => [], 'session' => null, 'last_clock' => $now];
        if (!$valid || $now < $state['last_clock'] || $state['session'] !== ($runtime['observation_session'] ?? null)) $state['samples'] = [];
        $state['session'] = $runtime['observation_session'] ?? null;
        $state['last_clock'] = $now;
        if ($valid) {
            $samples = array_values(array_filter($state['samples'], fn ($at) => $at >= $now - 30));
            if ($samples && $row['at'] < end($samples)) $samples = [];
            if (!$samples || $row['at'] > end($samples)) $samples[] = $row['at'];
            $state['samples'] = array_slice($samples, -3);
        }
        ($this->save)($role, $state);
        return ['ready' => $valid && count($state['samples']) === 3, 'fresh_reports' => count($state['samples']),
            'health_or_forwarding_claimed' => false];
    }

    public function register(string $role): array
    {
        self::need($this->observe($role)['ready'], 'three_reports_required');
        [$task, $policy, , $release, $pin] = $this->context($role);
        ($this->guard)('register_source', $role);
        $evidence = ($this->load)($role);
        $now = ($this->clock)();
        self::need(count($evidence['samples'] ?? []) === 3 && min($evidence['samples']) >= $now - 30
            && $evidence['last_clock'] <= $now, 'reports_expired_during_readback');
        $stamp = ['run_id' => $this->scope['run_id'], 'role' => $role, 'task_id' => $task->id,
            'external_id' => $task->external_id, 'installation_id' => $task->installation_id,
            'health_or_forwarding_claimed' => false];
        DB::transaction(function () use ($task, $policy, $release, $stamp) {
            // Serialize the two roles and any competing registration on this credential.
            MachineCloudAccount::query()->whereKey($task->account_id)->lockForUpdate()->firstOrFail();
            $locked = Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            self::need($locked->getAttributes() === $task->getAttributes(), 'changed_during_registration');
            $existing = MachineOwnedInstance::query()->where('task_id', $task->id)->first();
            if ($existing) {
                self::need(($locked->payload['member_source_registration'] ?? null) === $stamp, 'registration_conflict');
                app(MachineNativeDnsAdmission::class)->sourcePin($policy, $existing);
                return;
            }
            self::need(!MachineCloudResource::query()->where('provider', 'linode')->where('external_id', $task->external_id)->exists()
                && !MachineOwnedInstance::query()->where('installation_id', $task->installation_id)->exists(), 'registration_conflict');
            $ips = array_values(array_filter($task->public_ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
            self::need(count($ips) === 1, 'single_ipv4_required');
            $resource = MachineCloudResource::query()->create(['account_id' => $task->account_id, 'provider' => 'linode',
                'external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at,
                'label' => $task->payload['request']['label'], 'region' => $policy->region, 'size' => $policy->size,
                'status' => 'running', 'public_ips' => $task->public_ips, 'machine_id' => $task->machine_id,
                'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority,
                'observed_at' => ($this->clock)(), 'auto_boot' => false, 'armed' => false, 'manual_hold' => true, 'dns_hold' => true]);
            $instance = MachineOwnedInstance::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision,
                'task_id' => $task->id, 'installation_id' => $task->installation_id, 'release_id' => $release->id,
                'binary_sha256' => $release->binary_sha256, 'version' => $release->version, 'ip' => $ips[0],
                'resource_id' => $resource->id, 'status' => 'active', 'active_policy_id' => $policy->id,
                'verification' => null, 'verified_until' => null]);
            $payload = $locked->payload; $payload['member_source_registration'] = $stamp;
            $locked->fill(['status' => 'accepted', 'reason' => null, 'payload' => $payload])->save();
            app(MachineNativeDnsAdmission::class)->sourcePin($policy, $instance);
        });
        return ['registered' => true, 'role' => $role, 'task_id' => $task->id, 'health_or_forwarding_claimed' => false];
    }
}
