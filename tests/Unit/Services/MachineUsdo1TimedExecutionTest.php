<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Console\Commands\RunUsdo1Failover;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverDnsSet;
use App\Models\MachineFailoverDnsSlot;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineFailoverService as Service;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverWorkflow;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1TimedExecution as Entry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\HandoverEvidenceFixture as Proof;

final class MachineUsdo1TimedExecutionTest extends MachineFailoverShadowTest
{
    private string $grant;
    private int $plan;
    private array $scope;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_11_07_180000_create_machine_failover_native_journal.php',
            '2026_11_07_220000_create_machine_failover_runtime.php',
            '2026_11_09_180000_create_machine_failover_dns_sets.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php',
            '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php',
            '2026_11_18_000000_create_machine_failover_handover_grants.php',
            '2026_11_18_010000_add_machine_failover_handover_receipts.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        $policy = $this->policies[0];
        DB::table('v2_server_machine')->where('id', $policy->machine_id)->update(['id' => 17]);
        $policy->update(['machine_id' => 17]);
        $service = $this->service['id'];
        self::assertSame(1, $service);
        Member::query()->where('service_id', $service)->where('policy_id', '!=', $policy->id)->update(['enabled' => false]);
        $member = Member::query()->where('service_id', $service)->where('policy_id', $policy->id)->firstOrFail();
        $member->update(['machine_id' => 17]);
        Service::query()->whereKey($service)->update(['name' => '123', 'attempt_limit' => 1, 'max_parallel' => 1]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Fixture Aliyun', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture', 'access_key_secret' => 'fixture-secret']]);
        $set = MachineFailoverDnsSet::query()->create(['service_id' => $service, 'provider' => 'aliyun', 'zone' => Scope::ZONE,
            'zone_name' => Scope::ZONE, 'hostname' => Scope::NAME, 'credential_id' => $dns->id, 'credential_revision' => 1,
            'scope' => str_repeat('2', 64), 'ttl' => 600, 'revision' => 1, 'updated_by' => $this->admin->id]);
        MachineFailoverDnsSlot::query()->create(['set_id' => $set->id, 'member_id' => $member->id,
            'type' => 'A', 'line' => 'default', 'marker' => 'keli-set-' . str_repeat('b', 48)]);
        $source = ['machine_id' => 17, 'external_id' => '171701', 'installation_id' => str_repeat('3', 32),
            'created_at' => '2026-11-18T01:02:03Z', 'ipv4' => '192.0.1.17'];
        MachineCloudResource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode', 'machine_id' => 17,
            'external_id' => $source['external_id'], 'installation_id' => $source['installation_id'],
            'provider_created_at' => $source['created_at'], 'public_ips' => [$source['ipv4']], 'label' => 'local-fixture',
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'status' => 'running', 'observed_at' => $this->clock]);
        $pin = Scope::pin($source, ['RecordId' => '9910', 'DomainName' => Scope::ZONE, 'RR' => 'usdo1', 'Type' => 'A',
            'Line' => 'default', 'Value' => $source['ipv4'], 'TTL' => 600, 'Remark' => '', 'Status' => 'Enable', 'Locked' => false]);
        $release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'fixture-v1',
            'platform' => 'linux-x86_64', 'manifest_path' => 'fixture/manifest.json', 'archive_path' => 'fixture/agent.tgz',
            'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $registry = app(MachineFailoverServiceRegistry::class);
        $this->scope = ['service_id' => $service, 'service_revision' => 1, 'expires_at' => $this->clock + 600,
            'release' => $release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']),
            'context_hash' => $registry->fingerprint($registry->snapshot($service, 1)), 'evidence_sha256' => str_repeat('f', 64)];
        Proof::trust();
        $this->grant = app(MachineFailoverHandover::class)->issueManualUsdo1($this->scope, $this->admin->id, $pin,
            Proof::manualStop($this->scope, $this->admin->id, $pin));
        $this->plan = Plan::query()->create(['policy_id' => $policy->id, 'policy_revision' => $policy->revision,
            'observation_id' => 1, 'decision_key' => str_repeat('f', 64), 'status' => 'proposed', 'evidence' => [], 'blockers' => [],
            'created_at' => $this->clock])->id;
    }

    private function enableEntry(): void
    {
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void
            {
                if ($operation !== 'usdo1_execution_start') parent::assertAllowed($operation);
            }
        });
    }

    private function native(bool $lostAck): void
    {
        $native = \Mockery::mock(MachineFailoverNativeBridge::class);
        $native->shouldReceive('start')->once()->andReturnUsing(function ($service, $revision, $plans, $release, $key, $admin, $admission, $grant) use ($lostAck) {
            self::assertSame([1, 1, [$this->plan], $this->scope['release']['id'], $this->admin->id, null, $this->grant],
                [$service, $revision, $plans, $release, $admin, $admission, $grant]);
            $run = DB::transaction(function () use ($service, $revision, $key, $admin, $grant) {
                $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($service, $revision);
                $run = Execution::query()->create(['id' => (string) Str::uuid(), 'service_id' => $service, 'service_revision' => $revision,
                    'admin_id' => $admin, 'request_key' => hash('sha256', json_encode([$admin, $key], JSON_THROW_ON_ERROR)),
                    'intent_hash' => str_repeat('e', 64), 'context_hash' => $this->scope['context_hash'], 'status' => 'running',
                    'deadline_at' => $this->scope['expires_at'], 'payload' => ['snapshot' => $snapshot, 'release' => $this->scope['release'],
                        'handover_grant_id' => $grant, 'workflow' => MachineFailoverWorkflow::READINESS]]);
                $m = $snapshot['members'][0];
                ExecutionMember::query()->create(['execution_id' => $run->id, 'member_id' => $m['id'], 'policy_id' => $m['policy_id'],
                    'policy_revision' => $m['policy_revision'], 'plan_id' => $this->plan, 'episode_key' => str_repeat('a', 64), 'payload' => []]);
                app(MachineFailoverHandover::class)->bindExecution($grant, $run->id);
                Runtime::query()->create(['execution_id' => $run->id, 'generation' => 1, 'state' => 'queued', 'ticket_hash' => str_repeat('1', 64)]);
                return $run;
            });
            if ($lostAck) throw new \RuntimeException('synthetic missing start receipt');
            return ['id' => $run->id];
        });
        app()->instance(MachineFailoverNativeBridge::class, $native);
    }

    public function test_service_123_entry_stays_closed_and_expired_or_revoked_grants_cannot_submit(): void
    {
        $admin = $this->admin->id;
        $entry = app(Entry::class);
        $this->deniedEntry('orchestration_disabled', fn () => $entry->start($this->grant, $this->plan, $admin));
        $this->enableEntry();
        $entry = app(Entry::class);
        $this->deniedEntry('usdo1_execution_plan_changed', fn () => $entry->start($this->grant, $this->plan + 99, $admin));
        $this->tick(600);
        $this->deniedEntry('handover_expired', fn () => $entry->start($this->grant, $this->plan, $admin));
        self::assertTrue($entry->status($this->grant, $admin)['expired']);
        self::assertTrue($entry->stop($this->grant, $admin)['revoked']);
        $this->deniedEntry('handover_revoked', fn () => $entry->start($this->grant, $this->plan, $admin));
        self::assertSame(0, Execution::query()->count());
        self::assertSame(1, Grant::query()->findOrFail($this->grant)->writer_slot);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_service_123_cli_does_not_expose_grants_or_direct_writes(): void
    {
        $command = new RunUsdo1Failover();
        $command->setLaravel(app());
        $tester = new CommandTester($command);
        $args = ['grant' => $this->grant, '--admin' => (string) $this->admin->id];
        self::assertSame(2, $tester->execute(['operation' => 'publish'] + $args));
        self::assertSame(0, $tester->execute(['operation' => 'status'] + $args));
        $view = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('not_started', $view['status']);
        self::assertArrayNotHasKey('stop_receipt', $view);
        self::assertSame(1, $tester->execute(['operation' => 'start', '--plan' => (string) $this->plan] + $args));
        self::assertSame('orchestration_disabled', trim($tester->getDisplay()));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_service_123_lost_start_receipt_only_reads_the_original_even_after_stop_and_expiry(): void
    {
        $this->enableEntry(); $this->native(lostAck: true);
        $entry = app(Entry::class); $admin = $this->admin->id;
        try { $entry->start($this->grant, $this->plan, $admin); self::fail('Expected missing receipt'); }
        catch (\RuntimeException $error) { self::assertSame('synthetic missing start receipt', $error->getMessage()); }
        $first = $entry->status($this->grant, $admin);
        self::assertSame('queued', $first['runtime']);
        self::assertSame($first, $entry->start($this->grant, $this->plan, $admin));
        $this->deniedEntry('usdo1_execution_intent_changed', fn () => $entry->start($this->grant, $this->plan + 1, $admin));
        $other = User::query()->create(['is_admin' => true]);
        $this->deniedEntry('usdo1_execution_grant_scope', fn () => $entry->stop($this->grant, $other->id));

        $lock = Cache::lock('machine-native:run:' . $first['execution_id'], 120);
        self::assertTrue($lock->get());
        try {
            $stop = $entry->stop($this->grant, $admin);
            self::assertTrue($stop['revoked']); self::assertTrue($stop['stop_requested']);
            self::assertSame('stopping', $stop['runtime']);
            self::assertNull(Runtime::query()->findOrFail($first['execution_id'])->ticket_hash);
        } finally { $lock->release(); }
        $stopped = $entry->stop($this->grant, $admin);
        self::assertSame('stopped', $stopped['status']);
        self::assertSame($stopped, $entry->stop($this->grant, $admin));
        self::assertTrue($stopped['writer_held']);
        self::assertSame(1, DB::table('v2_machine_failover_runtime_event')->where('kind', 'stop_requested')->count());
        $this->tick(600);
        $expired = $entry->start($this->grant, $this->plan, $admin);
        self::assertTrue($expired['expired']);
        self::assertSame($first['execution_id'], $expired['execution_id']);
        self::assertSame(1, Execution::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    private function deniedEntry(string $reason, callable $call): void
    {
        try { $call(); self::fail('Expected refusal: ' . $reason); }
        catch (CloudException $error) { self::assertSame($reason, $error->getMessage()); }
    }
}
