<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MachineCloudResource;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverDnsSet;
use App\Models\MachineFailoverDnsSlot;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverExistingSource;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission;
use App\Services\ServerMachine\Cloud\MachineProvisionLimits;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1SingleAExecution as Publisher;
use App\Services\ServerMachine\Cloud\MachineUsdo1SourceAdmission as Admission;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\HandoverEvidenceFixture as Proof;

final class MachineUsdo1ProductionEntryTest extends MachineFailoverShadowTest
{
    private const IP = '192.0.1.17';

    private function refused(string $code, callable $work): void
    {
        try { $work(); self::fail('Expected ' . $code); }
        catch (CloudException $error) { self::assertSame($code, $error->getMessage()); }
    }

    private function record(): array
    {
        return ['RecordId' => '171717', 'DomainName' => Scope::ZONE, 'RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default',
            'Value' => self::IP, 'TTL' => 600, 'Remark' => '', 'Status' => 'Enable', 'Locked' => false];
    }

    public function test_existing_source_intake_is_scoped_signed_and_does_not_fabricate_provisioning(): void
    {
        foreach (['2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_09_180000_create_machine_failover_dns_sets.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php', '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php',
            '2026_11_18_000000_create_machine_failover_handover_grants.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Proof::trust();
        $machine = new ServerMachine(['name' => 'usdo1-fixture', 'token' => 'fixture-17', 'is_active' => true]);
        $machine->id = 17; $machine->save();
        $release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'fixture-v1',
            'platform' => 'linux-x86_64', 'manifest_path' => 'fixture/manifest.json', 'archive_path' => 'fixture/node.tar.gz',
            'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $resource = MachineCloudResource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode',
            'machine_id' => 17, 'installation_id' => str_repeat('1', 31) . '7', 'external_id' => '1717',
            'provider_created_at' => '2026-11-18T01:02:03Z', 'public_ips' => [self::IP], 'label' => 'local-fixture',
            'machine_authority' => hash('sha256', $machine->token), 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'status' => 'running', 'observed_at' => time()]);
        $runtime = ['version' => $release->version, 'binary_sha256' => $release->binary_sha256, 'observation_session' => str_repeat('c', 32),
            'configured_nodes' => []];
        $report = fn (array $details) => app(MachineAddressInventory::class)->record($machine, $resource->installation_id,
            ['public_ipv4' => self::IP], time(), $details);
        $report($runtime);
        $proofKey = hash_hmac('sha256', json_encode([17, $resource->id, $this->account->id, $this->account->revision, 'linode',
            '1717', $resource->provider_created_at, 'sg-sin-2', 'g6-standard-4', $resource->installation_id, [self::IP]], JSON_THROW_ON_ERROR), $machine->token);
        $policy = $this->policies[0]->replicate();
        $policy->fill(['machine_id' => 17, 'machine_authority' => $resource->machine_authority, 'slot' => MachineFailoverExistingSource::slot($proofKey),
            'dns_credential_id' => null, 'dns_credential_revision' => null, 'dns_hostname' => '', 'dns_scope' => null, 'isolation_confirmed' => false])->save();
        $admin = $this->admin->id;
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Existing A fixture', 'max_parallel' => 1, 'attempt_limit' => 1,
            'members' => [['policy_id' => $policy->id, 'policy_revision' => 1, 'name' => 'Source', 'priority' => 1, 'enabled' => true]]], $admin);
        $credential = MachineDnsCredential::query()->create(['name' => 'Fixture Aliyun', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture', 'access_key_secret' => 'fixture-ali-secret']]);
        $set = MachineFailoverDnsSet::query()->create(['service_id' => $service['id'], 'provider' => 'aliyun',
            'zone' => Scope::ZONE, 'zone_name' => Scope::ZONE, 'hostname' => Scope::NAME, 'credential_id' => $credential->id,
            'credential_revision' => 1, 'scope' => str_repeat('4', 64), 'ttl' => 600, 'revision' => 1, 'updated_by' => $admin]);
        MachineFailoverDnsSlot::query()->create(['set_id' => $set->id, 'member_id' => $service['members'][0]['id'], 'type' => 'A',
            'line' => 'default', 'marker' => 'keli-set-' . str_repeat('e', 48)]);
        $admission = app(Admission::class);
        $args = [$service['id'], 1, $release->id, $this->record(), $admin];
        $scope = $admission->review(...$args);
        $signature = Proof::sourceAdmission($scope);
        $this->refused('orchestration_disabled', fn () => $admission->admit(...[...$args, $signature]));
        Http::assertNothingSent();
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void
            {
                if ($operation !== 'usdo1_source_admission') parent::assertAllowed($operation);
            }
        });
        $admission = app(Admission::class);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['https://api.linode.com/v4/linode/instances/1717*' => Http::response(['id' => 1717, 'label' => 'local-fixture',
            'status' => 'running', 'created' => $resource->provider_created_at, 'region' => $resource->region, 'type' => $resource->size,
            'ipv4' => [self::IP]], 200)]);
        $this->refused('handover_evidence_scope_changed', fn () => $admission->admit(...[...$args, Proof::sourceAdmission($scope + ['wrong' => true])]));
        $report(array_replace($runtime, ['binary_sha256' => str_repeat('0', 64)]));
        $this->refused('usdo1_source_admission_changed', fn () => $admission->admit(...[...$args, $signature]));
        $report($runtime);
        Http::assertNothingSent();
        $usage = app(MachineProvisionLimits::class)->usage($this->account);
        $resource->refresh();
        $adopted = $admission->admit(...[...$args, $signature]);
        self::assertSame(2, $adopted['service_revision']);
        $instance = MachineOwnedInstance::query()->findOrFail($adopted['instance_id']);
        $task = MachineProvisionTask::query()->findOrFail($instance->task_id);
        self::assertSame(MachineProvisionTask::EXISTING_SOURCE_ADOPTED, $task->reason);
        self::assertNull($task->dispatched_at);
        self::assertNull($task->bootstrap_state);
        self::assertNull($instance->verification);
        self::assertSame(self::IP, app(MachineNativeDnsAdmission::class)->sourcePin($policy->fresh(), $instance)['addresses']['A']);
        self::assertSame($usage, app(MachineProvisionLimits::class)->usage($this->account));
        self::assertSame($resource->getRawOriginal(), $resource->fresh()->getRawOriginal());
        $this->refused('config_changed', fn () => $admission->admit(...[...$args, $signature]));
        Http::assertSentCount(1); Http::assertSent(fn ($request) => $request->method() === 'GET');
        Bus::assertNothingDispatched();
    }

    public function test_publication_accepts_only_the_pinned_ipv4_update_and_keeps_the_hard_gate(): void
    {
        $pin = Scope::pin(['machine_id' => 17, 'external_id' => '1717', 'installation_id' => str_repeat('a', 32),
            'created_at' => '2026-11-18T01:02:03Z', 'ipv4' => self::IP], $this->record());
        $marker = 'keli-set-' . str_repeat('4', 48);
        $set = ['provider' => 'aliyun', 'zone' => Scope::ZONE, 'hostname' => Scope::NAME, 'ttl' => 600,
            'slots' => [['id' => 1, 'member_id' => 6, 'type' => 'A', 'line' => 'default', 'marker' => $marker]]];
        $target = '192.0.1.18';
        $row = ['hostname' => Scope::NAME, 'type' => 'A', 'line' => 'default', 'ttl' => 600, 'marker' => $marker,
            'locked' => false, 'proxied' => false];
        $action = ['kind' => 'update', 'slot_id' => 1, 'member_id' => 6, 'before' => ['id' => $pin['record_id'], 'content' => self::IP] + $row,
            'after' => ['content' => $target] + $row];
        Publisher::assertPlan($pin, $set, [$action], $target);
        foreach (['create', 'delete', 'claim'] as $kind) $this->refused('usdo1_single_a_scope_changed',
            fn () => Publisher::assertPlan($pin, $set, [array_replace($action, ['kind' => $kind])], $target));
        foreach ([['id' => '999'], ['type' => 'AAAA'], ['line' => 'telecom'], ['ttl' => 60], ['marker' => 'wrong']] as $drift)
            $this->refused('usdo1_single_a_scope_changed', fn () => Publisher::assertPlan($pin, $set,
                [array_replace($action, ['before' => array_replace($action['before'], $drift)])], $target));
        $this->refused('usdo1_single_a_scope_changed', fn () => Publisher::assertPlan($pin, $set, [$action, $action], $target));
        $this->refused('usdo1_single_a_scope_changed', fn () => Publisher::assertPlan($pin, $set, [$action], '192.0.1.19'));
        $this->refused('orchestration_disabled', fn () => app(Publisher::class)->publish('not-authorized', $this->admin->id));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
}
