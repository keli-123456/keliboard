<?php
declare(strict_types=1);

final class KeliSourceFixtureEvidenceRejected extends RuntimeException
{
    public bool $diagnosticRecorded = false;
    public function __construct(public readonly array $diagnostic)
    {
        parent::__construct($diagnostic['reason'] . '_' . $diagnostic['failed_fields'][0]);
    }
}

function keli_acceptance_source_facts(array $task, array $cloud, array $row, array $summary,
    array $expected, int $now, ?callable $remember = null): void
{
    $checker = require __DIR__ . '/source_fixture_evidence.php';
    $diagnostic = $checker($task, $cloud, $row, $summary, $expected, $now);
    if ($diagnostic['ok']) return;
    $error = new KeliSourceFixtureEvidenceRejected($diagnostic);
    try {
        if ($remember !== null) {
            $remember($diagnostic);
            $error->diagnosticRecorded = true;
        }
    } catch (Throwable) {
        // Preserve the validation failure even when its separate diagnostic cannot be saved.
    }
    throw $error;
}

function keli_acceptance_source_current(array $ids): App\Models\MachineOwnedInstance
{
    KeliLiveScope::assertManual(['initial-dns']);
    $task = App\Models\MachineProvisionTask::query()->findOrFail($ids['source_task_id']);
    $policy = App\Models\MachineFailoverPolicy::query()->findOrFail($ids['policy_id']);
    $instance = App\Models\MachineOwnedInstance::query()->where('task_id', $task->id)->firstOrFail();
    $fact = $task->payload['source_fixture_adoption'] ?? [];
    if ($task->status !== 'accepted' || $task->bootstrap_state !== 'succeeded'
        || ($task->payload['request']['label'] ?? null) !== KeliLiveScope::RUN . '-source'
        || ($fact['run_id'] ?? null) !== KeliLiveScope::RUN || ($fact['health_or_forwarding_claimed'] ?? null) !== false
        || $instance->status !== 'active' || $instance->active_policy_id !== $policy->id
        || $instance->verification !== null || $instance->verified_until !== null) {
        throw new RuntimeException('source_fixture_registration_changed');
    }
    app(App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->sourcePin($policy, $instance);
    $summary = app(App\Services\ServerMachine\Cloud\MachineFailoverObserver::class)->summary($policy, time());
    if (($summary['probe']['state'] ?? null) !== 'failed'
        || ($summary['probe']['installation_id'] ?? null) !== $task->installation_id) {
        throw new RuntimeException('source_fixture_failure_not_current');
    }
    return $instance;
}

function keli_acceptance_adopt_source(array $ids, array $scope): array
{
    KeliLiveScope::assertSource('adopt-source', true);
    if (($GLOBALS['argv'][2] ?? null) !== $ids['source_task_id']) throw new RuntimeException('exact_source_reference_required');
    $task = App\Models\MachineProvisionTask::query()->findOrFail($ids['source_task_id']);
    $policy = App\Models\MachineFailoverPolicy::query()->findOrFail($ids['policy_id']);
    $machine = App\Models\ServerMachine::query()->findOrFail(17);
    $release = App\Models\ServerMachineRelease::query()->findOrFail($ids['release_id']);
    $account = App\Models\MachineCloudAccount::query()->findOrFail(1);
    if (App\Models\MachineProvisionTask::query()->count() !== 1 || App\Models\MachineOwnedInstance::query()->exists()
        || $policy->machine_id !== 17 || $task->slot !== $policy->slot || $task->account_revision !== $account->revision
        || !app(App\Services\ServerMachine\Cloud\MachineFailoverCredentials::class)->owns($policy, $task)
        || !hash_equals(hash('sha256', $machine->token), $task->machine_authority)
        || $task->machine_authority !== $policy->machine_authority || isset($task->payload['coordinated_attempt'])
        || $release->binary_sha256 !== KeliLiveScope::BINARY_SHA256 || $release->component !== 'kelinode-rs') {
        throw new RuntimeException('source_fixture_ownership_invalid');
    }
    $ips = array_values(array_filter($task->public_ips ?? [], fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
    if (count($ips) !== 1) throw new RuntimeException('single_source_ipv4_required');
    $remote = app(App\Services\ServerMachine\Cloud\MachineProvisionProviders::class)->client($account, $task->payload['plan'])
        ->reconcile($account, $task->payload['request'], $task->dispatched_at);
    if (!$remote) throw new RuntimeException('source_fixture_cloud_missing');
    $observer = app(App\Services\ServerMachine\Cloud\MachineFailoverObserver::class);
    $summary = [];
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $observer->observe($policy->id);
        $summary = $observer->summary($policy, time());
        if (($summary['probe']['state'] ?? null) !== 'failed') throw new RuntimeException('source_fixture_not_failed');
        if ($summary['evidence_current'] && $summary['threshold_reached'] && $summary['observed_failures'] >= 3) break;
        if ($attempt < 4) sleep(5);
    }
    $row = app(App\Services\ServerMachine\MachineAddressInventory::class)->snapshots([$machine], true)[17][$task->installation_id] ?? [];
    $health = app(App\Services\ServerMachine\Cloud\MachineCloudHealth::class)->snapshot(new App\Models\MachineCloudResource([
        'machine_id' => 17, 'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority,
        'public_ips' => $task->public_ips]), $task->dispatched_at);
    if (!$health['ready'] || $health['nodes'] !== 'fresh') throw new RuntimeException('source_fixture_reports_missing');
    $expected = ['run_id' => KeliLiveScope::RUN, 'machine_id' => 17, 'account_id' => 1, 'ip' => $ips[0],
        'binary_sha256' => $release->binary_sha256, 'version' => $release->version,
        'node_id' => $ids['node_id'], 'health_or_forwarding_claimed' => false];
    keli_acceptance_source_facts($task->only(['installation_id', 'status', 'bootstrap_state', 'machine_id', 'account_id',
        'dispatched_at', 'external_id', 'provider_created_at', 'public_ips']) + ['label' => $task->payload['request']['label']],
        $remote, $row, $summary, $expected, time(), static function (array $diagnostic): void {
            KeliLiveScope::journal('source-fixture-evidence-first-error.json', $diagnostic);
        });
    KeliLiveScope::journal('source-adoption-intent.json', ['task_id' => $task->id, 'external_id' => $task->external_id,
        'installation_id' => $task->installation_id, 'health_or_forwarding_claimed' => false]);
    Illuminate\Support\Facades\DB::transaction(function () use ($task, $policy, $release, $ips, $expected) {
        $locked = App\Models\MachineProvisionTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $task->status || $locked->external_id !== $task->external_id
            || App\Models\MachineCloudResource::query()->where('provider', 'linode')->where('external_id', $task->external_id)->exists()) {
            throw new RuntimeException('source_fixture_original_identity_changed');
        }
        $resource = App\Models\MachineCloudResource::query()->create(['account_id' => 1, 'provider' => 'linode',
            'external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at,
            'label' => KeliLiveScope::RUN . '-source', 'region' => $policy->region, 'size' => $policy->size,
            'status' => 'running', 'public_ips' => $task->public_ips, 'machine_id' => 17,
            'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority,
            'observed_at' => time(), 'auto_boot' => false, 'armed' => false, 'manual_hold' => true, 'dns_hold' => true]);
        App\Models\MachineOwnedInstance::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision,
            'task_id' => $task->id, 'installation_id' => $task->installation_id, 'release_id' => $release->id,
            'binary_sha256' => $release->binary_sha256, 'version' => $release->version, 'ip' => $ips[0],
            'resource_id' => $resource->id, 'status' => 'active', 'active_policy_id' => $policy->id,
            'verification' => null, 'verified_until' => null]);
        $payload = $locked->payload; $payload['source_fixture_adoption'] = $expected + ['registered_at' => time()];
        $locked->fill(['status' => 'accepted', 'reason' => null, 'payload' => $payload])->save();
    });
    return ['source_fixture_registered' => true, 'health_or_forwarding_success_claimed' => false,
        'registration_basis' => 'real_cloud_bootstrap_runtime_and_collected_failure', 'source_task_id' => $task->id];
}
