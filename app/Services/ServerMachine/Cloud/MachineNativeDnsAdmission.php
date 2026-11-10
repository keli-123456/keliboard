<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsSetRuntime;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedInstance as Instance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MachineNativeDnsAdmission
{
    public function __construct(private MachineFailoverExecutionGate $gate) {}
    public function available(): bool
    {
        return Schema::hasTable('v2_machine_native_address_proof') && app(MachineFailoverDnsRegistry::class)->available();
    }
    public function sourcePin(Policy $policy, Instance $instance): array
    {
        $task = Task::query()->find($instance->task_id); $machine = ServerMachine::query()->find($policy->machine_id);
        $account = $task ? MachineCloudAccount::query()->find($task->account_id) : null;
        $resource = $instance->resource_id ? MachineCloudResource::query()->find($instance->resource_id) : null;
        $release = ServerMachineRelease::query()->find($instance->release_id);
        if (!$task || !$machine?->is_active || !$account || !$resource || !$release || !$policy->isolation_confirmed
            || $instance->policy_id !== $policy->id || $instance->active_policy_id !== $policy->id || $instance->status !== 'active'
            || $task->status !== 'accepted' || $task->machine_id !== $policy->machine_id || $task->slot !== $policy->slot
            || $task->installation_id !== $instance->installation_id || !$task->external_id || !$task->provider_created_at
            || !app(MachineFailoverCredentials::class)->owns($policy, $task) || $account->revision !== $task->account_revision
            || !hash_equals($policy->machine_authority, $task->machine_authority) || !hash_equals($task->machine_authority, hash('sha256', (string) $machine->token))
            || $resource->machine_id !== $policy->machine_id || $resource->installation_id !== $instance->installation_id
            || !is_string($resource->machine_authority) || !hash_equals($task->machine_authority, $resource->machine_authority)
            || $resource->provider !== $account->provider || $resource->account_id !== $account->id || $resource->external_id !== $task->external_id
            || $resource->provider_created_at !== $task->provider_created_at || $resource->auto_boot || $resource->armed || $resource->active_task_id
            || $release->component !== 'kelinode-rs' || $release->binary_sha256 !== $instance->binary_sha256 || $release->version !== $instance->version)
            throw new CloudException('native_source_identity_changed');
        $ips = $this->ips($task->public_ips); $addresses = $this->addresses($ips, $instance->ip);
        if (array_diff($ips, $this->ips($resource->public_ips))) throw new CloudException('native_source_identity_changed');
        return ['instance' => $instance->only(['id', 'revision', 'policy_id', 'policy_revision', 'task_id', 'installation_id', 'resource_id', 'release_id', 'binary_sha256', 'version', 'ip']),
            'task' => $task->only(['id', 'account_id', 'account_revision', 'machine_id', 'machine_authority', 'external_id', 'provider_created_at', 'marker']),
            'provider' => $account->provider, 'addresses' => $addresses];
    }
    public function sourceForMember(Member $member): array
    {
        $policy = Policy::query()->findOrFail($member->policy_id); $source = Instance::query()->find($member->payload['source_instance_id']);
        if (!$source) throw new CloudException('native_source_identity_changed');
        $pin = $this->sourcePin($policy, $source);
        if (!MachineIntentJson::same($member->payload['dns_source'] ?? null, $pin)) throw new CloudException('native_source_identity_changed');
        return $pin;
    }
    public function candidateContext(Attempt $attempt, Instance $instance, bool $remote = false): array
    {
        $this->gate->assertAllowed('native_dns_admission');
        app(MachineFailoverNativeBridge::class)->assertAttempt($attempt);
        $member = Member::query()->findOrFail($attempt->execution_member_id); $run = Execution::query()->findOrFail($member->execution_id);
        $policy = Policy::query()->findOrFail($member->policy_id); $task = Task::query()->findOrFail($attempt->task_id);
        $source = $this->sourceForMember($member); $acceptance = app(MachineInstanceAcceptance::class);
        $acceptance->gate($policy->id, 'allow_verification');
        if ($remote) $acceptance->requireVerified($instance, $policy, $run->admin_id);
        else $acceptance->scope($instance, $policy, $policy->revision, $run->admin_id);
        $release = $run->payload['release']; $resource = MachineCloudResource::query()->find($instance->resource_id);
        $families = $this->families($run->payload['snapshot'], $member->member_id);
        if ($instance->task_id !== $task->id || $instance->policy_id !== $member->policy_id || $instance->policy_revision !== $member->policy_revision
            || $instance->status !== 'verified' || $instance->active_policy_id !== null || $task->status !== 'awaiting_acceptance'
            || $instance->installation_id !== $task->installation_id || $instance->release_id !== $release['id']
            || $instance->binary_sha256 !== $release['binary_sha256'] || $instance->version !== $release['version']
            || $instance->verified_until <= time() || !$instance->verification || ($instance->verification['cleanup_complete'] ?? null) !== true
            || !$resource || $resource->account_id !== $task->account_id || $resource->provider !== ($task->payload['plan']['provider'] ?? 'linode')
            || $resource->external_id !== $task->external_id || $resource->id === $source['instance']['resource_id']
            || $resource->provider_created_at !== $task->provider_created_at || $resource->installation_id !== $instance->installation_id
            || $resource->machine_id !== $task->machine_id || $resource->auto_boot || $resource->armed || $resource->active_task_id
            || $resource->status !== 'running' || !$resource->manual_hold || !$resource->dns_hold
            || !is_string($resource->machine_authority) || !hash_equals($task->machine_authority, $resource->machine_authority)
            || $instance->id === $source['instance']['id'] || $instance->installation_id === $source['instance']['installation_id']
            || $task->external_id === $source['task']['external_id'] && $task->provider_created_at === $source['task']['provider_created_at']) throw new CloudException('native_candidate_identity_changed');
        $runtime = app(MachineAcceptanceEvidence::class)->capture($instance, $policy);
        if (!MachineIntentJson::same($runtime, $instance->verification['context'] ?? null)) throw new CloudException('native_address_context_changed');
        $machine = ServerMachine::query()->findOrFail($task->machine_id);
        $row = app(MachineAddressInventory::class)->snapshots([$machine], true)[$machine->id][$instance->installation_id] ?? [];
        if (($row['at'] ?? 0) < time() - 90 || ($row['at'] ?? PHP_INT_MAX) > time() + 3 || ($row['at'] ?? 0) <= $task->dispatched_at) throw new CloudException('native_address_report_stale');
        $reported = array_keys(array_filter($row['ips'] ?? [], fn ($r) => ($r['observed'] ?? true) === false));
        $addresses = $this->addresses(array_values(array_intersect($this->ips($task->public_ips), $reported)), $instance->ip);
        foreach ($families as $family) if (!isset($addresses[$family], $source['addresses'][$family]) || $addresses[$family] === $source['addresses'][$family]
            || !in_array($addresses[$family], $resource->public_ips ?? [], true)) throw new CloudException('native_address_required');
        if ($remote) {
            $account = MachineCloudAccount::query()->findOrFail($task->account_id);
            $remoteIdentity = app(MachineProvisionProviders::class)->client($account, $task->payload['plan'])->reconcile($account, $task->payload['request'], $task->dispatched_at);
            foreach ($families as $family) if (!$remoteIdentity || $remoteIdentity['external_id'] !== $task->external_id
                || $remoteIdentity['provider_created_at'] !== $task->provider_created_at || $remoteIdentity['status'] !== 'running'
                || !in_array($addresses[$family], $remoteIdentity['public_ips'], true)) throw new CloudException('native_candidate_identity_changed');
        }
        $config = app(MachineForwardingVerifier::class)->configuration($policy->id);
        return ['execution_id' => $run->id, 'admin_id' => $run->admin_id, 'service_id' => $run->service_id, 'service_revision' => $run->service_revision,
            'member_id' => $member->member_id, 'execution_member_id' => $member->id, 'attempt_id' => $attempt->id,
            'ordinal' => $attempt->ordinal, 'policy_id' => $policy->id, 'policy_revision' => $policy->revision,
            'task_identity' => $attempt->payload['task_identity'], 'task' => $task->only(['id', 'account_id', 'account_revision', 'external_id', 'provider_created_at',
                'installation_id', 'machine_id', 'machine_authority', 'marker', 'slot', 'status', 'dispatched_at', 'bootstrap_state']),
            'instance' => $instance->only(['id', 'revision', 'policy_id', 'policy_revision', 'task_id', 'installation_id', 'release_id', 'binary_sha256',
                'resource_id', 'version', 'ip', 'status', 'active_policy_id']),
            'resource' => $resource->only(['id', 'revision', 'provider', 'account_id', 'external_id', 'provider_created_at', 'machine_id',
                'installation_id', 'machine_authority', 'public_ips', 'manual_hold', 'dns_hold', 'status']),
            'source' => $source, 'addresses' => array_intersect_key($addresses, array_flip($families)), 'families' => $families,
            'runtime' => $runtime, 'release' => $release, 'sets_hash' => $this->hash($run->payload['snapshot']['dns_sets']),
            'verifier_fingerprint' => $config['fingerprint'], 'deadline_at' => min($run->deadline_at, $instance->verified_until)];
    }
    public function families(array $snapshot, int $member): array
    {
        $families = [];
        foreach ($snapshot['dns_sets'] ?? [] as $set) foreach ($set['slots'] as $slot) if ($slot['member_id'] === $member) $families[$slot['type']] = true;
        $result = array_values(array_intersect(['A', 'AAAA'], array_keys($families)));
        if (!$result || count($result) !== count($families)) throw new CloudException('native_dns_topology_invalid'); return $result;
    }
    public function policyChecks(Policy $policy, array $checks, ?array $snapshot): array
    {
        if (empty($snapshot['dns_sets'])) return $checks;
        $registered = app(MachineFailoverServiceRegistry::class)->snapshot($snapshot['service']['id'], $snapshot['service']['revision']);
        if (!MachineIntentJson::same($registered, $snapshot)) throw new CloudException('native_context_changed');
        $members = array_values(array_filter($snapshot['members'], fn ($m) => $m['policy_id'] === $policy->id && $m['policy_revision'] === $policy->revision));
        if (count($members) !== 1) throw new CloudException('native_dns_topology_invalid');
        $this->families($snapshot, $members[0]['id']);
        foreach ($snapshot['dns_sets'] as $set) if (in_array($members[0]['id'], array_column($set['slots'], 'member_id'), true))
            app(MachineFailoverDnsRegistry::class)->snapshot($set['id'], $snapshot['service']['revision'], $set['revision']);
        // Only a registered service scope supersedes legacy single-record DNS checks; it grants no execution authority.
        return array_values(array_filter($checks, fn ($check) => !in_array($check['code'], ['dns_not_configured', 'dns_credential_missing',
            'dns_credential_changed', 'owned_dns_unsupported', 'dns_record_conflict'], true)));
    }
    public function pinSources(array $snapshot): array
    {
        if (!$this->available()) throw new CloudException('migration_required');
        $this->authority();
        $pins = []; $members = array_column($snapshot['members'], null, 'id');
        foreach ($snapshot['dns_sets'] ?? [] as $set) foreach ($set['slots'] as $slot) {
            $m = $members[$slot['member_id']] ?? null;
            if (!$m) throw new CloudException('native_dns_topology_invalid');
            if (!isset($pins[$m['id']])) {
                $policy = Policy::query()->findOrFail($m['policy_id']); $active = Instance::query()->where('active_policy_id', $policy->id)->first();
                if (!$active) throw new CloudException('native_source_identity_changed'); $pins[$m['id']] = $this->sourcePin($policy, $active);
            }
            if (!isset($pins[$m['id']]['addresses'][$slot['type']])) throw new CloudException('native_address_required');
        }
        return $pins;
    }
    public function prepare(Attempt $attempt, Instance $instance): array
    {
        $this->gate->assertAllowed('native_dns_prepare');
        $this->assertTurn($attempt);
        $context = app(MachineNativeAddressProofs::class)->assertCurrent($attempt->fresh(), $instance, true);
        app(MachineInstanceAcceptance::class)->gate($context['policy_id'], 'allow_dns');
        $parent = Execution::query()->findOrFail($context['execution_id']); $sources = $this->workingSources($parent);
        $sets = array_values(array_filter($parent->payload['snapshot']['dns_sets'], fn ($set) => in_array($context['member_id'], array_column($set['slots'], 'member_id'), true)));
        $links = $attempt->fresh()->payload['dns_set_runs'] ?? [];
        foreach ($sets as $set) {
            if (isset($links[$set['id']])) continue;
            $snapshot = app(MachineFailoverDnsRegistry::class)->snapshot($set['id'], $parent->service_revision, $set['revision']);
            $records = app(MachineDnsRecordSetHttpTransport::class)->inventory($parent->id, $snapshot['set']);
            $addresses = array_map(fn ($source) => $source['addresses'], $sources);
            $planner = app(MachineDnsRecordSetPlanner::class);
            if ($planner->plan($snapshot, $addresses, $records)['actions'] !== []) throw new CloudException('native_dns_source_unverified');
            $addresses[$context['member_id']] = $context['addresses'];
            $plan = $planner->plan($snapshot, $addresses, $records, 'replace', $context['member_id']);
            $key = hash('sha256', 'native-dns-set:' . $attempt->id . ':' . $set['id']);
            $id = (string) Str::uuid();
            foreach ($plan['actions'] as $i => &$a) $a += ['id' => hash('sha256', $id . ':' . $i), 'state' => 'prepared', 'known_id' => null, 'requests' => []];
            unset($a);
            $binding = ['dns_run_id' => $id, 'attempt_id' => $attempt->id, 'instance_id' => $instance->id, 'context' => $context,
                'proofs' => $attempt->fresh()->payload['address_proofs'], 'sources' => $sources, 'origin' => 'native_dns_admission_v1',
                'snapshot_sha256' => $this->hash($snapshot), 'plan_sha256' => $this->publicationHash($plan), 'initial_sha256' => $this->hash($records)];
            $binding['seal'] = $this->seal($binding);
            DB::transaction(function () use (&$links, $attempt, $set, $context, $snapshot, $plan, $records, $binding, $key, $id) {
                $this->lockExecution($context['execution_id']);
                $current = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
                $this->assertTurn($current);
                app(MachineFailoverNativeBridge::class)->assertDns(Task::query()->findOrFail($current->task_id), false);
                if (isset($current->payload['dns_set_runs'][$set['id']])) { $links = $current->payload['dns_set_runs']; return; }
                $run = DnsRun::query()->create(['id' => $id, 'set_id' => $set['id'], 'service_id' => $context['service_id'], 'set_revision' => $set['revision'],
                    'service_revision' => $context['service_revision'], 'admin_id' => $context['admin_id'],
                    'request_key' => $key, 'intent_hash' => $this->hash([$context, $set]),
                    'context_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 'scenario' => 'native_replace', 'status' => 'running',
                    'payload' => ['snapshot' => $snapshot, 'native_binding' => $binding, 'target' => $context['member_id'], 'plan' => $plan,
                        'records' => $records, 'initial' => $records, 'writes' => 0, 'reads' => 0, 'virtual_wait_seconds' => 0, 'events' => []]]);
                $p = $current->payload; $p['dns_set_runs'][$set['id']] = $id; $current->payload = $p; $current->save(); $links = $p['dns_set_runs'];
                app(MachineDnsRecordSetExecution::class)->initialize($run, 'native');
            });
        }
        return $links;
    }
    public function assertTurn(Attempt $attempt): void
    {
        $member = Member::query()->findOrFail($attempt->execution_member_id); $parent = Execution::query()->findOrFail($member->execution_id);
        $links = $attempt->payload['dns_set_runs'] ?? [];
        foreach (Member::query()->where('execution_id', $parent->id)->where('id', '!=', $member->id)->whereNotIn('phase', ['completed', 'cancelled', 'retained'])->get() as $other) {
            $otherAttempt = $other->attempt_id ? Attempt::query()->find($other->attempt_id) : null;
            if (!empty($otherAttempt?->payload['dns_set_runs'])) throw new CloudException('dns_set_busy');
        }
        if (DnsRun::query()->where('service_id', $parent->service_id)->where('status', 'running')->whereNotIn('id', array_values($links))->exists()) throw new CloudException('dns_set_busy');
    }
    public function guard(DnsRun $dns, bool $dispatch, bool $remote = false): void
    {
        $this->gate->assertAllowed('native_dns_binding'); $b = $dns->payload['native_binding'] ?? null;
        if (!is_array($b) || ($b['origin'] ?? null) !== 'native_dns_admission_v1' || ($b['dns_run_id'] ?? null) !== $dns->id
            || !is_string($b['seal'] ?? null) || !hash_equals($b['seal'], $this->seal(array_diff_key($b, ['seal' => true])))) throw new CloudException('native_dns_binding_changed');
        $attempt = Attempt::query()->findOrFail($b['attempt_id']); $instance = Instance::query()->findOrFail($b['instance_id']);
        $task = Task::query()->findOrFail($attempt->task_id); app(MachineFailoverNativeBridge::class)->assertDns($task, !$dispatch);
        $context = app(MachineNativeAddressProofs::class)->assertCurrent($attempt, $instance, $remote);
        app(MachineInstanceAcceptance::class)->gate($context['policy_id'], 'allow_dns');
        if (!MachineIntentJson::same($context, $b['context']) || !MachineIntentJson::same($attempt->payload['address_proofs'], $b['proofs'])
            || ($attempt->payload['dns_set_runs'][$dns->set_id] ?? null) !== $dns->id || $dns->service_id !== $context['service_id']
            || $dns->service_revision !== $context['service_revision'] || $dns->payload['target'] !== $context['member_id']
            || ($b['snapshot_sha256'] ?? null) !== $this->hash($dns->payload['snapshot'])
            || ($b['plan_sha256'] ?? null) !== $this->publicationHash($dns->payload['plan'])
            || ($b['initial_sha256'] ?? null) !== $this->hash($dns->payload['initial'])) throw new CloudException('native_dns_binding_changed');
        $parent = Execution::query()->findOrFail($context['execution_id']);
        if ($dns->admin_id !== $parent->admin_id || !MachineIntentJson::same($this->workingSources($parent), $b['sources'])) throw new CloudException('native_source_identity_changed');
    }
    public function lockParent(DnsRun $dns): void
    {
        $b = $dns->payload['native_binding'] ?? [];
        if (!is_string($b['seal'] ?? null) || ($b['dns_run_id'] ?? null) !== $dns->id
            || !hash_equals($b['seal'], $this->seal(array_diff_key($b, ['seal' => true])))) throw new CloudException('native_dns_binding_changed');
        $this->lockExecution($b['context']['execution_id']);
    }
    private function lockExecution(string $id): void
    {
        if (Schema::hasTable('v2_machine_failover_runtime')) \App\Models\MachineFailoverRuntime::query()->whereKey($id)->lockForUpdate()->first();
        Execution::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
    public function complete(Attempt $attempt, Instance $instance): void
    {
        $this->gate->assertAllowed('native_dns_complete');
        $context = app(MachineNativeAddressProofs::class)->assertCurrent($attempt->fresh(), $instance, true);
        $links = $attempt->fresh()->payload['dns_set_runs'] ?? [];
        $expected = [];
        foreach (Execution::query()->findOrFail($context['execution_id'])->payload['snapshot']['dns_sets'] as $set)
            if (in_array($context['member_id'], array_column($set['slots'], 'member_id'), true)) $expected[] = $set['id'];
        if (array_keys($links) !== $expected) throw new CloudException('native_dns_binding_changed');
        $readbacks = [];
        foreach ($links as $id) {
            $dns = DnsRun::query()->findOrFail($id); $this->guard($dns, false);
            if (MachineDnsSetRuntime::query()->findOrFail($id)->mode !== 'native') throw new CloudException('native_dns_receipt_required');
            $records = app(MachineDnsRecordSetHttpTransport::class)->inventory($id, $dns->payload['snapshot']['set']);
            app(MachineDnsRecordSetExecution::class)->verifyReadback($dns, $records);
            $readbacks[$id] = ['digest' => $this->hash($dns->payload), 'read_at' => time(), 'inventory_sha256' => $this->hash($records)];
        }
        DB::transaction(function () use ($attempt, $instance, $context, $links, $readbacks) {
            $this->lockExecution($context['execution_id']);
            $current = Attempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $candidate = Instance::query()->whereKey($instance->id)->lockForUpdate()->firstOrFail();
            app(MachineNativeAddressProofs::class)->assertCurrent($current, $candidate);
            $receipts = [];
            foreach ($links as $set => $id) {
                $runtime = MachineDnsSetRuntime::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $dns = DnsRun::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $this->guard($dns, false);
                if ($dns->status !== 'completed' || $dns->set_id !== $set || $runtime->mode !== 'native' || $runtime->stop_requested_at
                    || !$runtime->finished_at || $runtime->finished_at > time() + 3
                    || $readbacks[$id]['read_at'] < time() - 30 || !hash_equals($readbacks[$id]['digest'], $this->hash($dns->payload))
                    || $dns->cursor !== count($dns->payload['plan']['actions']) || !$dns->payload['plan']['actions']
                    || array_filter($dns->payload['plan']['actions'], fn ($a) => $a['state'] !== 'verified')) throw new CloudException('native_dns_receipt_required');
                $receipts[$set] = ['id' => $id] + $readbacks[$id];
            }
            $policy = Policy::query()->findOrFail($context['policy_id']); $member = Member::query()->findOrFail($context['execution_member_id']);
            $source = Instance::query()->where('active_policy_id', $policy->id)->lockForUpdate()->firstOrFail();
            if ($source->id !== $context['source']['instance']['id']) throw new CloudException('native_source_identity_changed');
            $source->fill(['active_policy_id' => null, 'status' => 'retained'])->save();
            MachineCloudResource::query()->whereKey($source->resource_id)->update(['dns_hold' => true]);
            $candidate->fill(['active_policy_id' => $policy->id, 'status' => 'active', 'revision' => $candidate->revision + 1])->save();
            MachineCloudResource::query()->whereKey($candidate->resource_id)->update(['dns_hold' => false]);
            $task = Task::query()->findOrFail($current->task_id); $task->status = 'accepted'; $task->save();
            $receipt = ['attempt_id' => $current->id, 'execution_id' => $member->execution_id,
                'published_pin' => $this->sourcePin($policy, $candidate), 'runs' => $receipts, 'completed_at' => time()];
            $receipt['seal'] = $this->seal($receipt); $p = $current->payload; $p['native_completion'] = $receipt;
            $current->fill(['payload' => $p, 'status' => 'completed'])->save();
            $task->fill(['evidence' => array_replace($task->evidence ?? [], ['forwarding_verified' => true, 'dns_changed' => true,
                'acceptance_instance_id' => $candidate->id, 'native_dns_completion' => $receipt['seal']])])->save();
            $member->fill(['phase' => 'completed', 'reason' => null])->save();
        });
        $task = Task::query()->findOrFail($attempt->task_id); if ($task->replacement_id) app(MachineReplacementService::class)->sync($task);
    }
    public function completionCurrent(Attempt $attempt, Task $task): bool
    {
        try {
            $r = $attempt->payload['native_completion'] ?? []; $member = Member::query()->findOrFail($attempt->execution_member_id);
            $instance = Instance::query()->where('task_id', $task->id)->firstOrFail(); $policy = Policy::query()->findOrFail($member->policy_id);
            if ($attempt->status !== 'completed' || $member->phase !== 'completed' || !app(MachineFailoverNativeBridge::class)->bindingCurrent($attempt, $task)
                || ($r['attempt_id'] ?? null) !== $attempt->id || ($r['execution_id'] ?? null) !== $member->execution_id
                || !is_string($r['seal'] ?? null) || !hash_equals($r['seal'], $this->seal(array_diff_key($r, ['seal' => true])))
                || ($task->evidence['native_dns_completion'] ?? null) !== $r['seal'] || !MachineIntentJson::same($this->sourcePin($policy, $instance), $r['published_pin'])) return false;
            foreach ($r['runs'] as $set => $receipt) {
                $run = DnsRun::query()->find($receipt['id']);
                if (!$run || $run->status !== 'completed' || $run->set_id !== $set || ($run->payload['native_binding']['attempt_id'] ?? null) !== $attempt->id
                    || !hash_equals($receipt['digest'], $this->hash($run->payload)) || MachineDnsSetRuntime::query()->find($run->id)?->mode !== 'native') return false;
            }
            return $r['runs'] !== [];
        } catch (\Throwable) { return false; }
    }
    // Stop is local and fenced: an in-flight or partial write is retained for review, never replayed or rolled back.
    public function stopForAttempt(Attempt $attempt): bool
    {
        if (empty($attempt->payload['dns_set_runs'])) return false;
        $this->lockExecution(Member::query()->findOrFail($attempt->execution_member_id)->execution_id);
        $uncertain = false;
        foreach ($attempt->payload['dns_set_runs'] ?? [] as $set => $id) {
            $runtime = MachineDnsSetRuntime::query()->whereKey($id)->lockForUpdate()->first();
            $run = DnsRun::query()->whereKey($id)->lockForUpdate()->first(); $b = $run?->payload['native_binding'] ?? [];
            if (!$run || !$runtime || $runtime->mode !== 'native' || $run->set_id !== $set || ($b['attempt_id'] ?? null) !== $attempt->id
                || ($b['dns_run_id'] ?? null) !== $id || !is_string($b['seal'] ?? null)
                || !hash_equals($b['seal'], $this->seal(array_diff_key($b, ['seal' => true])))) { $uncertain = true; continue; }
            $written = (bool) array_filter($run->payload['plan']['actions'], fn ($a) => $a['state'] !== 'prepared');
            $uncertain = $uncertain || $written;
            $runtime->fill(['stop_requested_at' => $runtime->stop_requested_at ?: time(), 'generation' => $runtime->generation + 1,
                'lease_hash' => null, 'lease_until' => null, 'next_check_at' => null, 'finished_at' => time()])->save();
            if ($run->status === 'running') $run->fill(['status' => $written ? 'needs_review' : 'stopped', 'reason' => 'native_stop_requested'])->save();
        }
        return $uncertain;
    }
    private function workingSources(Execution $parent): array
    {
        $original = $parent->payload['dns_sources'] ?? []; $current = $this->pinSources($parent->payload['snapshot']);
        foreach ($current as $id => $pin) if (!MachineIntentJson::same($original[$id] ?? null, $pin)) {
            $m = Member::query()->where('execution_id', $parent->id)->where('member_id', $id)->first();
            $a = $m?->attempt_id ? Attempt::query()->find($m->attempt_id) : null; $t = $a?->task_id ? Task::query()->find($a->task_id) : null;
            if (!$a || !$t || !$this->completionCurrent($a, $t)) throw new CloudException('native_source_identity_changed');
        }
        return $current;
    }
    private function seal(array $v): string
    {
        return hash_hmac('sha256', 'native-dns-admission-v1:' . MachineIntentJson::encode($v), $this->authority());
    }
    private function authority(): string
    {
        $key = config('app.key'); if (!is_string($key) || strlen($key) < 32) throw new CloudException('native_address_authority_missing');
        return $key;
    }
    private function publicationHash(array $plan): string
    {
        $plan['actions'] = array_map(fn ($a) => array_intersect_key($a, array_flip(['id', 'kind', 'slot_id', 'member_id', 'before', 'after'])), $plan['actions']);
        return $this->hash($plan);
    }
    private function ips(mixed $ips): array
    {
        if (!is_array($ips) || !array_is_list($ips) || count($ips) > 16) throw new CloudException('native_address_required');
        $result = [];
        foreach ($ips as $ip) { $normalized = MachineEntryProbe::publicIp($ip); if ($normalized === null || $normalized !== $ip || isset($result[$ip])) throw new CloudException('native_address_required'); $result[$ip] = true; }
        return array_keys($result);
    }
    private function addresses(array $ips, string $primary): array
    {
        if (!in_array($primary, $ips, true) || str_contains($primary, ':')) throw new CloudException('native_address_required');
        $v6 = array_values(array_filter($ips, fn ($ip) => str_contains($ip, ':')));
        if (count($v6) > 1) throw new CloudException('native_address_ambiguous');
        return ['A' => $primary] + ($v6 ? ['AAAA' => $v6[0]] : []);
    }
    private function hash(array $data): string { return hash('sha256', MachineIntentJson::encode($data)); }
}
