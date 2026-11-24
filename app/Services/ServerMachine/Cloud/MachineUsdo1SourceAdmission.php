<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineFailoverExecution;
use App\Models\MachineFailoverDnsRun;
use App\Models\MachineFailoverHandoverGrant;
use App\Models\MachineFailoverMember;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineFailoverRun;
use App\Models\MachineFailoverService;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachineRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Explicit intake of an existing instance. No VM create, bootstrap, or DNS operation. */
final class MachineUsdo1SourceAdmission
{
    public function __construct(private MachineFailoverServiceRegistry $registry, private MachineFailoverExistingSource $source,
        private MachineFailoverExecutionGate $gate, private MachineFailoverHandoverEvidence $evidence) {}

    public function review(int $service, int $revision, int $release, array $record, int $admin): array
    {
        return $this->context($service, $revision, $release, $record, $admin)[0];
    }

    public function admit(int $service, int $revision, int $release, array $record, int $admin, array $proof): array
    {
        $this->gate->assertAllowed('usdo1_source_admission');
        return $this->registry->locked(function () use ($service, $revision, $release, $record, $admin, $proof) {
            [$scope, $policy] = $this->context($service, $revision, $release, $record, $admin);
            $this->evidence->sourceAdmission($scope, $proof, now()->timestamp);
            // The existing verifier performs an exact provider GET and rechecks Agent identity.
            $remote = $this->source->inspect(MachineUsdo1HandoverScope::MACHINE, $policy->id, $scope['pin']['source']['ipv4']);
            return DB::transaction(function () use ($scope, $remote, $service, $revision, $release, $record, $admin, $proof) {
                $this->gate->assertAllowed('usdo1_source_admission');
                MachineFailoverService::query()->whereKey($service)->lockForUpdate()->firstOrFail();
                [$current, $policy, $resource, $account, $releaseRow, $plan, $member] = $this->context($service, $revision, $release, $record, $admin, true);
                if (!MachineIntentJson::same($current, $scope) || $remote['proof'] !== $scope['source_proof']) $this->changed();
                $receipt = $this->evidence->sourceAdmission($scope, $proof, now()->timestamp);
                $id = (string) Str::uuid(); $slot = 'adopted-usdo1:' . $id;
                $policy->fill(['slot' => $slot, 'revision' => $policy->revision + 1, 'updated_by' => $admin, 'isolation_confirmed' => true])->save();
                $member->fill(['policy_revision' => $policy->revision])->save();
                $owner = MachineFailoverService::query()->findOrFail($service);
                $owner->fill(['revision' => $revision + 1, 'updated_by' => $admin])->save();
                $plan += ['machine_id' => $policy->machine_id, 'slot' => $slot, 'region' => $policy->region, 'size' => $policy->size, 'image' => $policy->image];
                $ip = $scope['pin']['source']['ipv4'];
                $task = MachineProvisionTask::query()->create(['id' => $id, 'request_key' => $receipt['proof_sha256'], 'admin_id' => $admin,
                    'account_id' => $account->id, 'account_revision' => $account->revision, 'machine_id' => $policy->machine_id,
                    'machine_authority' => $policy->machine_authority, 'slot' => $slot, 'installation_id' => $resource->installation_id,
                    'callback_hash' => hash('sha256', random_bytes(32)), 'marker' => $slot,
                    'plan_hash' => MachineFailoverHandoverEvidence::hash($plan), 'payload' => ['plan' => $plan],
                    'external_id' => $resource->external_id, 'provider_created_at' => $resource->provider_created_at, 'public_ips' => [$ip],
                    'status' => 'accepted', 'reason' => MachineProvisionTask::EXISTING_SOURCE_ADOPTED,
                    'deadline_at' => time(), 'finished_at' => time(), 'evidence' => [
                        'source_admission' => $receipt, 'scope_sha256' => MachineFailoverHandoverEvidence::hash($scope), 'retained_source' => true,
                    ]]);
                $instance = MachineOwnedInstance::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision,
                    'task_id' => $task->id, 'installation_id' => $resource->installation_id, 'resource_id' => $resource->id,
                    'release_id' => $release, 'binary_sha256' => $releaseRow->binary_sha256, 'version' => $releaseRow->version,
                    'ip' => $ip, 'active_policy_id' => $policy->id, 'status' => 'active']);
                app(MachineInstanceOwnership::class)->assertExclusive($policy);
                return ['service_id' => $service, 'service_revision' => $owner->revision, 'instance_id' => $instance->id];
            });
        });
    }

    private function context(int $service, int $revision, int $release, array $record, int $admin, bool $lock = false): array
    {
        $this->registry->actor($admin);
        $snapshot = $this->registry->snapshot($service, $revision);
        $members = MachineFailoverMember::query()->where('service_id', $service)->limit(2)->get();
        if ($members->count() !== 1 || !$members[0]->enabled || $members[0]->machine_id !== MachineUsdo1HandoverScope::MACHINE
            || MachineFailoverExecution::query()->where('service_id', $service)->exists()
            || MachineFailoverRun::query()->where('service_id', $service)->where('status', 'running')->exists()
            || MachineFailoverDnsRun::query()->where('service_id', $service)->where('status', 'running')->exists()
            || MachineFailoverHandoverGrant::query()->whereNotNull('writer_slot')->exists()) $this->changed();
        $member = $members[0]; $policy = MachineFailoverPolicy::query()->findOrFail($member->policy_id);
        if (!MachineFailoverExistingSource::isDraft($policy) || MachineOwnedInstance::query()->where('policy_id', $policy->id)->exists()) $this->changed();
        [$resource, $account, $sourceProof, $ips, $runtime] = $this->source->admissionEvidence($policy, $lock);
        $baseline = MachineUsdo1HandoverScope::baseline($record);
        $pin = MachineUsdo1HandoverScope::pin(['machine_id' => $policy->machine_id, 'external_id' => $resource->external_id,
            'installation_id' => $resource->installation_id, 'created_at' => $resource->provider_created_at, 'ipv4' => $baseline['Value']], $baseline);
        MachineUsdo1HandoverScope::assertCurrent($pin, $snapshot);
        $releaseRow = ServerMachineRelease::query()->find($release);
        if (!in_array($baseline['Value'], $ips, true) || !$releaseRow || $releaseRow->status !== ServerMachineRelease::STATUS_ACTIVE
            || $releaseRow->component !== 'kelinode-rs' || $releaseRow->platform !== 'linux-x86_64'
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $releaseRow->binary_sha256) || !preg_match('/^[a-f0-9]{64}$/D', (string) $releaseRow->sha256)
            || ($runtime['version'] ?? null) !== $releaseRow->version || ($runtime['binary_sha256'] ?? null) !== $releaseRow->binary_sha256
            || !preg_match('/^[a-f0-9]{32}$/D', (string) ($runtime['observation_session'] ?? null))) $this->changed();
        if (MachineProvisionTask::query()->where('installation_id', $resource->installation_id)->exists()) $this->changed();
        $credentials = app(MachineFailoverCredentials::class);
        $plan = ['account_id' => $account->id, 'provider' => 'linode'];
        if ($credentials->grouped($policy)) {
            $pool = $credentials->pool($policy->credential_group_id, $policy->provider);
            if ($account->group_id !== $pool['id'] || !$pool['accounts']->contains('id', $account->id)) $this->changed();
            $plan['credential_pool'] = ['group_id' => $pool['id'], 'provider' => 'linode', 'fingerprint' => $pool['fingerprint'],
                'account_revision' => $account->revision];
        } elseif ($policy->account_id !== $account->id || $policy->account_revision !== $account->revision) $this->changed();
        $scope = ['service_id' => $service, 'service_revision' => $revision, 'admin_id' => $admin, 'pin' => $pin, 'source_proof' => $sourceProof,
            'context_sha256' => $this->registry->fingerprint($snapshot), 'runtime_session' => $runtime['observation_session'],
            'release' => $releaseRow->only(['id', 'version', 'sha256', 'binary_sha256'])];
        return [$scope, $policy, $resource, $account, $releaseRow, $plan, $member];
    }

    private function changed(): never { throw new CloudException('usdo1_source_admission_changed'); }
}
