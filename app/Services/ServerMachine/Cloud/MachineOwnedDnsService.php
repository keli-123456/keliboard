<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudResource;
use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsTransition as Transition;
use App\Models\MachineOwnedDns as OwnedDns;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MachineOwnedDnsService
{
    public function __construct(private MachineInstanceAcceptance $acceptance, private DnsProviderClient $provider) {}

    public function overview(): array
    {
        return ['available' => $this->acceptance->available(), 'automatic' => false, 'delete_strategy' => 'keep',
            'records' => $this->acceptance->available() ? OwnedDns::query()->latest('id')->limit(100)->get() : [],
            'transitions' => $this->acceptance->available() ? Transition::query()->latest('created_at')->limit(100)->get() : []];
    }

    public function prepare(int $instanceId, int $policyRevision, int $instanceRevision, string $zone, string $requestId, ?string $recovery, int $admin): array
    {
        if (!$this->acceptance->available()) throw new CloudException('migration_required');
        if (!preg_match('/^[a-f0-9]{32}$/D', $requestId) || !preg_match('/^[a-f0-9]{32}$/D', $zone)) throw new CloudException('invalid_dns_intent');
        $initial = Instance::query()->findOrFail($instanceId);
        return $this->acceptance->locked($initial->policy_id, function ($policy) use ($instanceId, $policyRevision, $instanceRevision, $zone, $requestId, $recovery, $admin) {
            $this->acceptance->gate($policy->id, 'allow_dns'); $instance = Instance::query()->findOrFail($instanceId);
            if (!$policy->dns_credential_id || !$policy->dns_credential_revision || $policy->dns_hostname === '') throw new CloudException('dns_not_configured');
            $this->acceptance->scope($instance, $policy, $policyRevision, $admin);
            $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
            if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertDnsIntent($task, $zone, $requestId, $recovery);
            $key = hash('sha256', $admin . ':' . $requestId);
            if ($old = Transition::query()->where('request_key', $key)->first()) {
                $record = OwnedDns::query()->findOrFail($old->dns_id);
                if ($record->policy_id !== $policy->id || $record->zone !== $zone || $old->to_instance_id !== $instanceId || $old->recovery_of !== $recovery) throw new CloudException('request_conflict');
                return $old->toArray();
            }
            if ($instance->revision !== $instanceRevision) throw new CloudException('config_changed');
            return $this->dnsLock($policy->dns_hostname, $policy->dns_credential_id, function () use ($policy, $instance, $zone, $key, $recovery, $admin) {
                $this->acceptance->requireVerified($instance, $policy, $admin);
                $credential = $this->credential($policy->dns_credential_id, $policy->dns_credential_revision);
                if (MachineDnsBinding::query()->where('hostname', $policy->dns_hostname)->exists()) throw new CloudException('dns_record_conflict');
                $record = OwnedDns::query()->where('policy_id', $policy->id)->first();
                if (!$record) {
                    $record = OwnedDns::query()->create(['policy_id' => $policy->id, 'credential_id' => $credential->id,
                        'credential_revision' => $credential->revision, 'zone' => $zone, 'hostname' => $policy->dns_hostname,
                        'marker' => 'keli-owned-' . bin2hex(random_bytes(24)), 'status' => 'reserved'])->fresh();
                }
                if ($record->zone !== $zone || $record->hostname !== $policy->dns_hostname || $record->credential_id !== $credential->id
                    || $record->credential_revision !== $credential->revision) throw new CloudException('dns_ownership_changed');
                $pending = Transition::query()->where('dns_id', $record->id)->whereIn('state', ['prepared', 'dispatching', 'needs_review']);
                $from = $record->active_instance_id; $fromIp = $record->ip;
                if ($recovery !== null) {
                    $prior = Transition::query()->findOrFail($recovery);
                    if ($prior->dns_id !== $record->id || $prior->from_instance_id !== $instance->id || !$prior->dispatched_at
                        || !in_array($prior->state, ['verified', 'needs_review'], true) || !$record->record_id) throw new CloudException('invalid_recovery');
                    $current = $this->oneOwned($record, $credential);
                    if (!in_array($current['content'], [$prior->from_ip, $prior->to_ip], true)) throw new CloudException('dns_record_changed');
                    $fromIp = $current['content']; $from = $fromIp === $prior->to_ip ? $prior->to_instance_id : $prior->from_instance_id;
                    $pending->where('id', '!=', $recovery);
                }
                if ($pending->exists()) throw new CloudException('dns_transition_pending');
                if ($recovery === null && $record->status === 'needs_review') throw new CloudException('dns_transition_pending');
                return Transition::query()->create(['id' => (string) Str::uuid(), 'request_key' => $key, 'recovery_of' => $recovery,
                    'dns_id' => $record->id, 'dns_revision' => $record->revision, 'policy_revision' => $policy->revision, 'admin_id' => $admin,
                    'from_instance_id' => $from, 'to_instance_id' => $instance->id, 'from_ip' => $fromIp, 'to_ip' => $instance->ip,
                    'state' => 'prepared', 'deadline_at' => time() + 120])->fresh()->toArray();
            });
        });
    }

    public function execute(string $id, int $admin): array
    {
        if (!$this->acceptance->available()) throw new CloudException('migration_required');
        $initial = Transition::query()->findOrFail($id); $dns = OwnedDns::query()->findOrFail($initial->dns_id);
        return $this->acceptance->locked($dns->policy_id, function ($policy) use ($id, $admin) {
            $this->acceptance->gate($policy->id, 'allow_dns'); $transition = Transition::query()->findOrFail($id);
            $record = OwnedDns::query()->findOrFail($transition->dns_id);
            return $this->dnsLock($record->hostname, $record->credential_id, function () use ($policy, $transition, $record, $admin) {
                $instance = Instance::query()->findOrFail($transition->to_instance_id);
                $this->acceptance->scope($instance, $policy, $transition->policy_revision, $admin);
                $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
                if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertDnsTransition($task, $transition);
                if ($transition->state === 'verified') return $transition->toArray();
                if ($transition->dispatched_at !== null) return $this->reconcile($transition, $record, $instance, $policy, $admin);
                if ($transition->state !== 'prepared' || $transition->deadline_at <= time() || $record->revision !== $transition->dns_revision) throw new CloudException('dns_intent_expired');
                $this->acceptance->requireVerified($instance, $policy, $admin);
                $credential = $this->credential($record->credential_id, $record->credential_revision);
                $records = $this->provider->ownedRecords($credential, $record->zone, $record->hostname);
                if ($record->record_id === null) {
                    if ($records !== []) throw new CloudException('dns_record_conflict');
                    $kind = 'create';
                } else {
                    $current = $this->assertOne($record, $records);
                    if ($current['content'] !== $transition->from_ip) throw new CloudException('dns_record_changed');
                    $kind = $current['content'] === $transition->to_ip ? 'none' : 'patch';
                }
                $this->acceptance->gate($policy->id, 'allow_dns');
                $this->acceptance->requireVerified($instance, $policy->fresh(), $admin);
                if ($transition->deadline_at <= time()) throw new CloudException('dns_intent_expired');
                $this->credential($record->credential_id, $record->credential_revision);
                $this->acceptance->scope($instance, $policy->fresh(), $transition->policy_revision, $admin);
                $this->acceptance->gate($policy->id, 'allow_dns');
                // Persist the boundary before the request, never around the provider mutation itself.
                $transition = DB::transaction(function () use ($transition, $record, $instance, $policy, $admin, $kind) {
                    $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
                    if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertTask($task);
                    $this->current($record, true); $row = $this->current($transition, true);
                    $this->acceptance->scope($instance, $policy->fresh(), $transition->policy_revision, $admin);
                    $this->acceptance->gate($policy->id, 'allow_dns');
                    $this->credential($record->credential_id, $record->credential_revision);
                    $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
                    if (isset($task->payload['coordinated_attempt'])) app(MachineFailoverNativeBridge::class)->assertDnsTransition($task, $row);
                    if ($row->deadline_at <= time()) throw new CloudException('dns_intent_expired');
                    $row->fill(['state' => 'dispatching', 'dispatched_at' => time(), 'write_kind' => $kind])->save();
                    return $row;
                });
                try {
                    if ($kind === 'create') {
                        $created = $this->provider->createOwnedA($credential, $record->zone, $record->hostname, $transition->to_ip, $record->marker);
                        $this->assertOne($record, [$created]);
                        $record = DB::transaction(function () use ($record, $created) {
                            $row = $this->current($record, true); $row->record_id = $created['id']; $row->save(); return $row;
                        });
                    } elseif ($kind === 'patch') {
                        $binding = new MachineDnsBinding(['zone' => $record->zone, 'hostname' => $record->hostname, 'type' => 'A', 'record_id' => $record->record_id]);
                        $this->provider->update($credential, $binding, $current, $transition->to_ip);
                    }
                    return $this->reconcile($transition, $record, $instance, $policy, $admin);
                } catch (\Throwable $error) {
                    $reason = $error instanceof CloudException || $error instanceof DnsException ? $error->getMessage() : 'dns_outcome_unknown';
                    $this->review($transition, $record, $reason);
                    return $transition->fresh()->toArray();
                }
            });
        });
    }

    public function cancel(string $id, int $admin): array
    {
        if (!$this->acceptance->available()) throw new CloudException('migration_required');
        $transition = Transition::query()->findOrFail($id); $record = OwnedDns::query()->findOrFail($transition->dns_id);
        return $this->acceptance->locked($record->policy_id, function () use ($id, $admin) {
            $this->acceptance->actor($admin); $row = Transition::query()->findOrFail($id);
            if ($row->state === 'cancelled') return $row->toArray();
            if ($row->state !== 'prepared' || $row->dispatched_at !== null) throw new CloudException('dns_outcome_requires_review');
            $row->fill(['state' => 'cancelled', 'reason' => 'cancelled_by_admin'])->save();
            return $row->toArray();
        });
    }

    private function reconcile(Transition $transition, OwnedDns $record, Instance $instance, $policy, int $admin): array
    {
        try {
            $credential = $this->credential($record->credential_id, $record->credential_revision);
            $remote = $this->oneOwned($record, $credential);
            if ($remote['content'] !== $transition->to_ip) throw new CloudException('dns_outcome_unknown');
            $this->acceptance->gate($policy->id, 'allow_dns');
            $this->acceptance->requireVerified($instance->fresh(), $policy->fresh(), $admin);
            $this->acceptance->gate($policy->id, 'allow_dns');
            if ($record->revision !== $transition->dns_revision) throw new CloudException('dns_ownership_changed');
            DB::transaction(function () use ($transition, $record, $instance, $policy, $remote, $admin) {
                $record = $this->current($record, true); $transition = $this->current($transition, true);
                $currentInstance = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
                $this->acceptance->scope($instance, $policy->fresh(), $transition->policy_revision, $admin);
                $instance = $currentInstance;
                $this->acceptance->gate($policy->id, 'allow_dns');
                $this->credential($record->credential_id, $record->credential_revision);
                if ($instance->verified_until <= time() || empty($instance->verification['cleanup_complete'])) throw new CloudException('acceptance_expired');
                Instance::query()->where('active_policy_id', $policy->id)->where('id', '!=', $instance->id)->update(['active_policy_id' => null, 'status' => 'retained']);
                if ($transition->from_instance_id && $transition->from_instance_id !== $instance->id) Instance::query()->where('policy_id', $policy->id)
                    ->whereKey($transition->from_instance_id)->update(['active_policy_id' => null, 'status' => 'retained']);
                MachineCloudResource::query()->whereIn('id', Instance::query()->where('policy_id', $policy->id)->where('status', 'retained')->select('resource_id'))->update(['dns_hold' => true]);
                $instance->fill(['status' => 'active', 'active_policy_id' => $policy->id, 'revision' => $instance->revision + 1])->save();
                $record->fill(['status' => 'live', 'record_id' => $remote['id'], 'active_instance_id' => $instance->id,
                    'ip' => $transition->to_ip, 'revision' => $record->revision + 1])->save();
                MachineCloudResource::query()->whereKey($instance->resource_id)->update(['dns_hold' => false]);
                $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
                $task->fill(['status' => 'accepted', 'evidence' => array_replace($task->evidence ?? [], ['forwarding_verified' => true,
                    'dns_changed' => true, 'acceptance_instance_id' => $instance->id, 'dns_transition_id' => $transition->id])])->save();
                $transition->fill(['state' => 'verified', 'reason' => null])->save();
                if ($transition->recovery_of) Transition::query()->whereKey($transition->recovery_of)->update(['state' => 'recovered']);
                if ($task->replacement_id) app(MachineReplacementService::class)->sync($task);
            });
        } catch (\Throwable $error) {
            $reason = $error instanceof CloudException || $error instanceof DnsException ? $error->getMessage() : 'dns_outcome_unknown';
            $this->review($transition, $record, $reason);
        }
        return $transition->fresh()->toArray();
    }

    private function oneOwned(OwnedDns $record, MachineDnsCredential $credential): array
    {
        return $this->assertOne($record, $this->provider->ownedRecords($credential, $record->zone, $record->hostname));
    }

    private function current(OwnedDns|Transition $expected, bool $lock): Model
    {
        $query = $expected->newQuery()->whereKey($expected->getKey());
        $row = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        $fields = $expected instanceof OwnedDns
            ? ['policy_id', 'revision', 'credential_id', 'credential_revision', 'zone', 'hostname', 'record_id', 'marker', 'active_instance_id', 'ip', 'status']
            : ['request_key', 'recovery_of', 'write_kind', 'dns_id', 'dns_revision', 'policy_revision', 'admin_id', 'from_instance_id', 'to_instance_id', 'from_ip', 'to_ip', 'state', 'reason', 'dispatched_at', 'deadline_at'];
        if ($row->only($fields) !== $expected->only($fields)) throw new CloudException('dns_ownership_changed');
        return $row;
    }

    private function review(Transition $transition, OwnedDns $record, string $reason): void
    {
        Transition::query()->whereKey($transition->id)->where('dispatched_at', $transition->dispatched_at)->whereIn('state', ['dispatching', 'needs_review'])
            ->update(['state' => 'needs_review', 'reason' => $reason]);
        OwnedDns::query()->whereKey($record->id)->where('revision', $transition->dns_revision)->update(['status' => 'needs_review']);
    }

    private function assertOne(OwnedDns $record, array $rows): array
    {
        if (count($rows) !== 1) throw new CloudException('dns_record_conflict');
        $row = $rows[0];
        if (($record->record_id !== null && $row['id'] !== $record->record_id) || $row['hostname'] !== $record->hostname
            || $row['type'] !== 'A' || !hash_equals($record->marker, $row['comment']) || $row['ttl'] !== 60
            || $row['proxied'] !== false || $row['private_routing'] !== false || $row['locked']) throw new CloudException('dns_ownership_changed');
        return $row;
    }

    private function credential(int $id, int $revision): MachineDnsCredential
    {
        $credential = MachineDnsCredential::query()->find($id);
        if (!$credential || $credential->provider !== 'cloudflare' || $credential->revision !== $revision) throw new CloudException('dns_credential_changed');
        return $credential;
    }

    private function dnsLock(string $hostname, ?int $credential, callable $work): mixed
    {
        return Cache::lock('machine-dns:hostname:' . hash('sha256', $hostname), 120)->block(2,
            fn () => Cache::lock('machine-dns:credential:' . $credential, 120)->block(2, $work));
    }
}
