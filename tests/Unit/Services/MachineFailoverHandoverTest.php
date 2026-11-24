<?php

namespace Tests\Unit\Services;

use App\Models\MachineDnsCredential;
use App\Models\MachineCloudResource;
use App\Models\MachineFailoverDnsRun;
use App\Models\MachineFailoverDnsSet;
use App\Models\MachineFailoverDnsSlot;
use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverService as Service;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverWorkflow;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Usdo1;
use App\Services\ServerMachine\Cloud\MachineUsdo1SingleAExecution;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HandoverEvidenceFixture;

class MachineFailoverHandoverTest extends MachineFailoverShadowTest
{
    private const MIGRATION = '2026_11_18_000000_create_machine_failover_handover_grants.php';
    private MachineFailoverHandover $handover;
    private MachineFailoverServiceRegistry $registry;
    private ServerMachineRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_11_07_180000_create_machine_failover_native_journal.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php',
            '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php', self::MIGRATION,
            '2026_11_18_010000_add_machine_failover_handover_receipts.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        HandoverEvidenceFixture::trust();
        $this->handover = app(MachineFailoverHandover::class);
        $this->registry = app(MachineFailoverServiceRegistry::class);
        $this->release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'fixture-v1',
            'platform' => 'linux-x86_64', 'manifest_path' => 'fixture/manifest.json', 'archive_path' => 'fixture/node.tar.gz',
            'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
    }

    private function scope(array $overrides = []): array
    {
        $service = $overrides['service_id'] ?? $this->service['id'];
        return $overrides + ['service_id' => $service, 'service_revision' => 1,
            'context_hash' => $this->registry->fingerprint($this->registry->snapshot($service, 1)),
            'release' => $this->release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']),
            'evidence_sha256' => hash('sha256', 'fixture-handover:' . $service), 'expires_at' => $this->clock + 600];
    }

    private function issue(array $overrides = []): string
    {
        $scope = $this->scope($overrides);
        return $this->handover->issue($scope, $this->admin->id, HandoverEvidenceFixture::stop($scope, $this->admin->id));
    }

    private function start(string $id, array $overrides = []): void
    {
        $scope = $overrides + ['service' => $this->service['id'], 'revision' => 1, 'release' => $this->release->id, 'admin' => $this->admin->id];
        $this->handover->assertStart($id, $scope['service'], $scope['revision'], $scope['release'], $scope['admin']);
    }

    private function runFor(string $grant, array $overrides = [], array $payload = []): Execution
    {
        $scope = $this->scope();
        return Execution::query()->create($overrides + ['id' => (string) Str::uuid(), 'service_id' => $scope['service_id'],
            'service_revision' => $scope['service_revision'], 'admin_id' => $this->admin->id,
            'request_key' => hash('sha256', (string) Str::uuid()), 'intent_hash' => str_repeat('f', 64),
            'context_hash' => $scope['context_hash'], 'status' => 'running', 'deadline_at' => $this->clock + 120,
            'payload' => $payload + ['handover_grant_id' => $grant, 'workflow' => MachineFailoverWorkflow::READINESS,
                'release' => $scope['release'], 'snapshot' => $this->registry->snapshot($scope['service_id'], $scope['service_revision'])]]);
    }

    private function denied(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); }
        catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }

    public function test_handover_authorization_does_not_open_the_production_gate_even_when_bound(): void
    {
        $id = $this->issue();
        $this->start($id);
        $run = DB::transaction(function () use ($id) {
            $run = $this->runFor($id);
            $this->handover->bindExecution($id, $run->id);
            return $run;
        });
        $this->handover->bindExecution($id, $run->id);
        $this->handover->assertNewWork($run->id);
        $grant = Grant::query()->findOrFail($id);
        $this->assertSame($run->id, $grant->execution_id);
        $this->assertSame($this->clock + 600, $grant->expires_at);
        $this->assertArrayNotHasKey('evidence_sha256', $grant->toArray());
        $this->assertArrayNotHasKey('stop_receipt', $grant->toArray());
        $this->assertSame('fixture-old-writer', $grant->stop_receipt['issuer']);
        $this->assertArrayNotHasKey('release', $grant->toArray());
        $this->assertStringNotContainsString('synthetic-secret', json_encode(DB::table(Grant::TABLE)->get(), JSON_THROW_ON_ERROR));
        foreach (['start', 'queue', 'advance', 'candidate_cleanup', 'dns_write'] as $operation) {
            $this->denied('orchestration_disabled', fn () => app(MachineFailoverExecutionGate::class)->assertAllowed($operation));
        }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_manual_usdo1_grant_checks_writer_baseline_scope_expiry_and_revocation_without_opening_gate(): void
    {
        (require base_path('database/migrations/2026_11_09_180000_create_machine_failover_dns_sets.php'))->up();
        $service = $this->service['id'];
        $old = $this->machines[0];
        DB::table('v2_server_machine')->where('id', $old->id)->update(['id' => 17]);
        $this->policies[0]->update(['machine_id' => 17]);
        Member::query()->where('service_id', $service)->where('machine_id', '!=', $old->id)->update(['enabled' => false]);
        $member = Member::query()->where('service_id', $service)->where('machine_id', $old->id)->firstOrFail();
        $member->update(['machine_id' => 17]);
        Service::query()->whereKey($service)->update(['max_parallel' => 1, 'attempt_limit' => 1]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Fixture Aliyun', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture', 'access_key_secret' => 'fixture-ali-secret']]);
        $set = MachineFailoverDnsSet::query()->create(['service_id' => $service, 'provider' => 'aliyun', 'zone' => Usdo1::ZONE, 'zone_name' => Usdo1::ZONE,
            'hostname' => Usdo1::NAME, 'credential_id' => $dns->id, 'credential_revision' => 1, 'scope' => str_repeat('b', 64),
            'ttl' => 600, 'revision' => 1, 'updated_by' => $this->admin->id]);
        $slot = MachineFailoverDnsSlot::query()->create(['set_id' => $set->id, 'member_id' => $member->id, 'type' => 'A', 'line' => 'default',
            'marker' => 'keli-set-' . str_repeat('d', 48)]);
        $source = ['machine_id' => 17, 'installation_id' => str_repeat('a', 32), 'external_id' => '1717',
            'created_at' => '2026-11-18T01:02:03Z', 'ipv4' => '192.0.1.17'];
        $resource = MachineCloudResource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode',
            'machine_id' => 17, 'installation_id' => $source['installation_id'], 'external_id' => $source['external_id'],
            'provider_created_at' => $source['created_at'], 'public_ips' => [$source['ipv4']], 'label' => 'local-fixture',
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'status' => 'running', 'observed_at' => $this->clock]);
        $pin = Usdo1::pin($source, ['RecordId' => '9910', 'DomainName' => Usdo1::ZONE, 'RR' => 'usdo1', 'Type' => 'A',
            'Line' => 'default', 'Value' => $source['ipv4'], 'TTL' => 600, 'Remark' => 'fixture original', 'Status' => 'Enable', 'Locked' => false]);
        $admin = $this->admin->id;
        $scope = $this->scope();
        $issue = fn (array $scope, array $proof) => $this->handover->issueManualUsdo1($scope, $admin, $pin, $proof);
        $manualProof = fn (array $value, array $overrides = []) => HandoverEvidenceFixture::manualStop($value, $admin, $pin, $overrides);
        $this->denied('handover_authority_unavailable', fn () => $issue($scope, HandoverEvidenceFixture::stop($scope, $admin)));
        $this->denied('handover_authority_unavailable', fn () => $this->handover->issue($scope, $admin, $manualProof($scope)));
        foreach (['writer' => 'tanzhen', 'baseline_sha256' => str_repeat('0', 64), 'in_flight' => 1] as $field => $value)
            $this->denied('handover_stop_unconfirmed', fn () => $issue($scope, $manualProof($scope, [$field => $value])));
        $bad = array_replace($scope, ['expires_at' => $this->clock + 7201]);
        $this->denied('handover_window_invalid', fn () => $issue($bad, $manualProof($bad)));
        $slot->update(['type' => 'AAAA']); $wrongFamily = $this->scope();
        $this->denied('usdo1_handover_scope_changed', fn () => $issue($wrongFamily, $manualProof($wrongFamily)));
        $slot->update(['type' => 'A']);
        $grant = $issue($scope, $manualProof($scope));
        $this->assertSame($pin, Grant::query()->findOrFail($grant)->stop_receipt['manual_usdo1']);
        $this->start($grant);
        $resource->update(['installation_id' => str_repeat('c', 32)]);
        $this->denied('usdo1_handover_scope_changed', fn () => $this->start($grant));
        $resource->update(['installation_id' => $source['installation_id']]);
        $run = $this->runFor($grant);
        $this->handover->bindExecution($grant, $run->id);
        $this->handover->assertNewWork($run->id);
        $candidate = '192.0.1.18';
        $nextResource = $resource->replicate();
        $nextResource->fill(['external_id' => '1718', 'installation_id' => str_repeat('e', 32), 'public_ips' => [$candidate]])->save();
        $this->denied('usdo1_handover_scope_changed', fn () => $this->handover->assertNewWork($run->id));
        $executionMember = ExecutionMember::query()->create(['execution_id' => $run->id, 'member_id' => $member->id, 'policy_id' => $member->policy_id,
            'policy_revision' => $member->policy_revision, 'plan_id' => 17, 'episode_key' => str_repeat('d', 64), 'payload' => []]);
        $attemptId = (string) Str::uuid();
        $task = MachineProvisionTask::query()->where('machine_id', $old->id)->firstOrFail()->replicate();
        $task->fill(['id' => (string) Str::uuid(), 'request_key' => str_repeat('4', 64), 'marker' => 'candidate-fixture', 'machine_id' => 17,
            'external_id' => '1718', 'provider_created_at' => $nextResource->provider_created_at, 'installation_id' => $nextResource->installation_id,
            'payload' => ['coordinated_attempt' => $attemptId]])->save();
        MachineFailoverAttempt::query()->create(['id' => $attemptId, 'execution_member_id' => $executionMember->id, 'ordinal' => 1,
            'request_id' => str_repeat('3', 32), 'task_id' => $task->id, 'account_id' => $task->account_id,
            'account_revision' => $task->account_revision, 'reserved_cents' => 0, 'payload' => []]);
        $executionMember->update(['attempt_id' => $attemptId]);
        MachineOwnedInstance::query()->create(['task_id' => $task->id, 'resource_id' => $nextResource->id, 'installation_id' => $task->installation_id,
            'policy_id' => $member->policy_id, 'policy_revision' => $member->policy_revision, 'release_id' => $this->release->id,
            'version' => $this->release->version, 'binary_sha256' => $this->release->binary_sha256, 'ip' => $candidate, 'status' => 'ready']);
        $this->handover->assertNewWork($run->id);
        $record = ['hostname' => Usdo1::NAME, 'type' => 'A', 'line' => 'default', 'ttl' => 600, 'marker' => $slot->marker,
            'locked' => false, 'proxied' => false];
        $dns = new MachineFailoverDnsRun(['service_id' => $service, 'service_revision' => 1, 'set_id' => $set->id, 'set_revision' => 1,
            'admin_id' => $admin, 'scenario' => 'native_replace', 'payload' => ['plan' => ['actions' => [
                ['kind' => 'update', 'slot_id' => $slot->id, 'member_id' => $member->id,
                    'before' => ['id' => $pin['record_id'], 'content' => $source['ipv4']] + $record, 'after' => ['content' => $candidate] + $record],
            ]]]]);
        $binding = ['context' => ['execution_id' => $run->id, 'families' => ['A'], 'addresses' => ['A' => $candidate]]];
        $this->denied('orchestration_disabled', fn () => app(MachineUsdo1SingleAExecution::class)->guard($run, $dns, $binding, true));
        $publisher = new MachineUsdo1SingleAExecution(new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void
            {
                if ($operation !== 'usdo1_single_a_publish') parent::assertAllowed($operation);
            }
        });
        $this->assertTrue($publisher->guard($run, $dns, $binding, true));
        $this->tick(600);
        $this->denied('handover_expired', fn () => $this->handover->assertNewWork($run->id));
        $this->denied('handover_expired', fn () => $publisher->guard($run, $dns, $binding, true));
        $this->handover->revoke($grant, $admin);
        $this->denied('handover_revoked', fn () => $this->handover->assertNewWork($run->id));
        $this->denied('orchestration_disabled', fn () => app(MachineFailoverExecutionGate::class)->assertAllowed('dns_write'));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_wrong_scope_unbound_work_and_second_execution_are_denied(): void
    {
        $id = $this->issue();
        foreach (['service', 'revision', 'release', 'admin'] as $key) {
            $this->denied('handover_scope_changed', fn () => $this->start($id, [$key => 99]));
        }
        $this->denied('handover_not_authorized', fn () => $this->start((string) Str::uuid()));
        $run = $this->runFor($id);
        $this->denied('handover_not_authorized', fn () => $this->handover->assertNewWork($run->id));
        $this->handover->bindExecution($id, $run->id);
        $other = $this->runFor($id);
        $this->denied('handover_already_bound', fn () => $this->start($id));
        $this->denied('handover_already_bound', fn () => $this->handover->bindExecution($id, $other->id));
        $this->denied('handover_not_authorized', fn () => $this->handover->assertNewWork($other->id));
        $this->denied('handover_execution_pending', fn () => $this->handover->assertNewWork($run->id));
        $this->assertSame($run->id, Grant::query()->findOrFail($id)->execution_id);
    }

    public static function differentIntent(): array
    {
        return [
            'wrong service' => [['service_id' => 99], []],
            'wrong revision' => [['service_revision' => 99], []],
            'wrong admin' => [['admin_id' => 99], []],
            'wrong hash' => [['context_hash' => str_repeat('f', 64)], []],
            'old execution' => [['created_at' => 1], []],
            'stopped execution' => [['status' => 'stopped'], []],
            'extends deadline' => [['deadline_at' => 4294967295], []],
            'separate permit' => [[], ['handover_grant_id' => 'fixture-isolated-grant']],
            'legacy workflow' => [[], ['workflow' => MachineFailoverWorkflow::PROTOCOL]],
            'wrong release' => [[], ['release' => ['id' => 99]]],
            'wrong snapshot' => [[], ['snapshot' => ['service' => ['revision' => 2]]]],
        ];
    }

    #[DataProvider('differentIntent')]
    public function test_binding_rejects_any_different_original_intent(array $columns, array $payload): void
    {
        $id = $this->issue();
        $run = $this->runFor($id, $columns, $payload);
        $this->denied('handover_execution_changed', fn () => $this->handover->bindExecution($id, $run->id));
        $this->assertNull(Grant::query()->findOrFail($id)->execution_id);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_binding_and_the_original_intent_roll_back_together(): void
    {
        $id = $this->issue();
        $this->denied('fixture_interrupted', fn () => DB::transaction(function () use ($id) {
            $run = $this->runFor($id);
            $this->handover->bindExecution($id, $run->id);
            throw new CloudException('fixture_interrupted');
        }));
        $this->assertSame(0, Execution::query()->count());
        $this->assertNull(Grant::query()->findOrFail($id)->execution_id);
        $this->start($id);
    }

    public function test_expiry_and_revocation_stop_new_checks_but_retain_the_bound_execution_and_slot(): void
    {
        $id = $this->issue(['expires_at' => $this->clock + 120]);
        $run = $this->runFor($id);
        $this->handover->bindExecution($id, $run->id);
        $this->tick(119);
        $this->handover->assertNewWork($run->id);
        $this->tick(1);
        $this->denied('handover_expired', fn () => $this->handover->assertNewWork($run->id));
        $this->denied('handover_slot_held', fn () => $this->issue(['evidence_sha256' => hash('sha256', 'different-review')]));
        $this->denied('handover_release_blocked', fn () => $this->handover->releaseUnused($id, $this->admin->id));
        $operator = User::query()->create(['is_admin' => true]);
        $this->admin->fill(['banned' => true])->save();
        app(MachineFailoverHandover::class)->revoke($id, $operator->id);
        $this->tick();
        app(MachineFailoverHandover::class)->revoke($id, $operator->id);
        $this->denied('handover_revoked', fn () => $this->handover->assertNewWork($run->id));
        $this->denied('handover_revoked', fn () => $this->handover->bindExecution($id, $run->id));
        $this->denied('handover_release_blocked', fn () => $this->handover->releaseUnused($id, $operator->id));
        $grant = Grant::query()->findOrFail($id);
        $this->assertSame($this->clock - 5, $grant->revoked_at);
        $this->assertSame($operator->id, $grant->revoked_by);
        $this->assertSame(1, $grant->writer_slot);
        $this->assertNull($grant->released_at);
        $this->assertSame('running', $run->fresh()->status);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_valid_window_revocation_blocks_an_existing_worker_on_its_next_check(): void
    {
        $id = $this->issue();
        $run = $this->runFor($id);
        $this->handover->bindExecution($id, $run->id);
        $worker = app(MachineFailoverHandover::class);
        $worker->assertNewWork($run->id);
        $this->handover->revoke($id, $this->admin->id);
        $this->assertGreaterThan($this->clock, Grant::query()->findOrFail($id)->expires_at);
        $this->denied('handover_revoked', fn () => $worker->assertNewWork($run->id));
        $this->assertSame($run->id, Grant::query()->findOrFail($id)->execution_id);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_grant_and_run_deadlines_are_rechecked_after_scope_validation(): void
    {
        $id = $this->issue(['expires_at' => $this->clock + 121]);
        $run = $this->runFor($id);
        $this->handover->bindExecution($id, $run->id);
        $this->tick(119);
        $slow = \Mockery::mock(MachineFailoverServiceRegistry::class)->makePartial();
        $slow->shouldReceive('snapshot')->twice()->andReturnUsing(function ($service, $revision) {
            $this->tick(1);
            return $this->registry->snapshot($service, $revision);
        });
        $worker = new MachineFailoverHandover($slow);
        $this->denied('handover_execution_changed', fn () => $worker->assertNewWork($run->id));
        $this->denied('handover_expired', fn () => $worker->assertNewWork($run->id));
    }

    public function test_unused_slot_requires_explicit_release_and_does_not_reuse_review_evidence(): void
    {
        $otherService = Service::query()->create(['name' => 'Separate service', 'max_parallel' => 1, 'attempt_limit' => 1,
            'monthly_cap_usd' => 0, 'revision' => 1, 'updated_by' => $this->admin->id]);
        Member::query()->where('policy_id', $this->policies[1]->id)->update(['service_id' => $otherService->id]);
        $id = $this->issue();
        $this->denied('handover_slot_held', fn () => $this->issue(['service_id' => $otherService->id]));
        $this->denied('handover_release_blocked', fn () => $this->handover->releaseUnused($id, $this->admin->id));
        $this->handover->revoke($id, $this->admin->id);
        $this->denied('handover_slot_held', fn () => $this->issue(['service_id' => $otherService->id]));
        $this->handover->releaseUnused($id, $this->admin->id);
        $this->handover->releaseUnused($id, $this->admin->id);
        $this->denied('handover_evidence_used', fn () => $this->issue());
        $next = $this->issue(['service_id' => $otherService->id]);
        $this->assertNotSame($id, $next);
        $this->start($next, ['service' => $otherService->id]);
        $this->denied('handover_revoked', fn () => $this->start($id));
        $this->assertSame(2, Grant::query()->count());
        $this->assertSame($this->admin->id, Grant::query()->findOrFail($id)->released_by);
    }

    public function test_database_enforces_only_one_open_slot_even_if_issuance_lock_is_not_held(): void
    {
        $id = $this->issue();
        $row = (array) DB::table(Grant::TABLE)->where('id', $id)->first();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table(Grant::TABLE)->insert(array_replace($row, ['id' => (string) Str::uuid(), 'service_id' => 2,
            'evidence_sha256' => hash('sha256', 'different-review')]));
    }

    public static function scopeDrift(): array
    {
        return [
            'service' => ['service', 'config_changed'],
            'policy' => ['policy', 'member_policy_changed'],
            'cloud account' => ['account', 'account_changed'],
            'DNS credential' => ['dns', 'dns_credential_changed'],
            'source' => ['source', 'source_changed'],
            'node release' => ['release', 'release_changed'],
            'workflow' => ['workflow', 'handover_scope_changed'],
            'operator' => ['operator', 'permission_revoked'],
            'stopped run' => ['run', 'handover_execution_changed'],
        ];
    }

    #[DataProvider('scopeDrift')]
    public function test_worker_rechecks_persisted_run_and_current_scope(string $change, string $reason): void
    {
        $id = $this->issue();
        $run = $this->runFor($id);
        $this->handover->bindExecution($id, $run->id);
        $this->handover->assertNewWork($run->id);
        match ($change) {
            'service' => Service::query()->whereKey($this->service['id'])->update(['revision' => 2]),
            'policy' => $this->policies[0]->fill(['revision' => 2])->save(),
            'account' => $this->account->fill(['revision' => 2])->save(),
            'dns' => MachineDnsCredential::query()->whereKey($this->policies[0]->dns_credential_id)->update(['revision' => 2]),
            'source' => $this->machines[0]->fill(['token' => 'changed-fixture'])->save(),
            'release' => $this->release->fill(['binary_sha256' => str_repeat('c', 64)])->save(),
            'workflow' => config(['machine_failover.workflow' => MachineFailoverWorkflow::PROTOCOL]),
            'operator' => $this->admin->fill(['banned' => true])->save(),
            'run' => $run->fill(['status' => 'stopped'])->save(),
        };
        $this->denied($reason, fn () => $this->handover->assertNewWork($run->id));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_issuance_refuses_unreviewed_scope_invalid_window_and_non_admin(): void
    {
        foreach (['active' => true, 'expires_at' => (string) ($this->clock + 600), 'evidence_sha256' => 'no-proof',
            'release' => $this->scope()['release'] + ['secret' => 'not-accepted']] as $field => $value) {
            $this->denied('handover_scope_invalid', fn () => $this->issue([$field => $value]));
        }
        foreach ([$this->clock, $this->clock + MachineFailoverHandover::MAX_SECONDS + 1] as $expiry) {
            $this->denied('handover_window_invalid', fn () => $this->issue(['expires_at' => $expiry]));
        }
        $this->denied('handover_scope_changed', fn () => $this->issue(['context_hash' => str_repeat('d', 64)]));
        $outsider = User::query()->create(['is_admin' => false]);
        $this->denied('permission_revoked', fn () => $this->handover->issue($this->scope(), $outsider->id));
        $this->assertSame(0, Grant::query()->count());
    }

    public function test_issuance_and_start_refuse_existing_or_uncertain_native_execution(): void
    {
        $older = $this->runFor((string) Str::uuid());
        $this->denied('handover_execution_pending', fn () => $this->issue());
        $older->fill(['status' => 'needs_review'])->save();
        $this->denied('handover_execution_pending', fn () => $this->issue());
        $older->fill(['status' => 'stopped'])->save();
        $member = ExecutionMember::query()->create(['execution_id' => $older->id, 'member_id' => 1, 'policy_id' => 1,
            'policy_revision' => 1, 'plan_id' => 1, 'episode_key' => str_repeat('c', 64), 'phase' => 'needs_review', 'payload' => []]);
        $this->denied('handover_execution_pending', fn () => $this->issue());
        $this->assertSame(0, Grant::query()->count());
        $member->fill(['phase' => 'cancelled'])->save();
        $id = $this->issue();
        $this->start($id);
        $member->fill(['phase' => 'needs_review'])->save();
        $this->denied('handover_execution_pending', fn () => $this->start($id));
        $run = $this->runFor($id);
        $this->denied('handover_execution_pending', fn () => $this->handover->bindExecution($id, $run->id));
        $this->assertNull(Grant::query()->findOrFail($id)->execution_id);
    }

    public function test_handover_bare_digest_or_untrusted_authority_cannot_issue(): void
    {
        $scope = $this->scope(); $admin = $this->admin->id;
        $proof = HandoverEvidenceFixture::stop($scope, $admin);
        $this->denied('handover_evidence_required', fn () => $this->handover->issue($scope, $admin));
        app()->forgetInstance(MachineFailoverHandoverEvidence::class);
        $this->denied('handover_authority_unavailable', fn () => $this->handover->issue($scope, $admin, $proof));
        $this->assertSame(0, Grant::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function unconfirmedOldWriter(): array
    {
        return ['only turned off scheduler' => ['fence_state', 'scheduler_disabled'], 'queued task' => ['queued', 1],
            'active request' => ['in_flight', 1], 'unknown result' => ['unresolved', 1], 'unexpected executor' => ['writer', 'other'],
            'invalid baseline' => ['baseline_sha256', 'not-a-digest']];
    }

    #[DataProvider('unconfirmedOldWriter')]
    public function test_handover_signed_old_writer_evidence_must_show_fenced_and_drained(string $field, mixed $value): void
    {
        $scope = $this->scope(); $admin = $this->admin->id;
        $proof = HandoverEvidenceFixture::stop($scope, $admin, [$field => $value]);
        $this->denied('handover_stop_unconfirmed', fn () => $this->handover->issue($scope, $admin, $proof));
        $this->assertSame(0, Grant::query()->count());
    }

    public function test_handover_old_writer_evidence_refuses_drift_tampering_and_staleness(): void
    {
        $scope = $this->scope(); $admin = $this->admin->id;
        $proof = HandoverEvidenceFixture::stop($scope, $admin);
        $other = array_replace($scope, ['expires_at' => $scope['expires_at'] + 1]);
        $this->denied('handover_evidence_scope_changed', fn () => $this->handover->issue($other, $admin, $proof));
        $this->denied('handover_evidence_scope_changed', fn () => $this->handover->issue($scope, $admin, HandoverEvidenceFixture::stop($scope, $admin + 1)));
        $tampered = $proof; $tampered['body']['fence_id'] = 'tampered-fence-id';
        $this->denied('handover_evidence_invalid', fn () => $this->handover->issue($scope, $admin, $tampered));
        $this->tick(61);
        $this->denied('handover_evidence_expired', fn () => $this->handover->issue($scope, $admin, $proof));
        $this->assertSame(0, Grant::query()->count());
    }

    public function test_handover_legacy_grant_without_verified_stop_receipt_cannot_start(): void
    {
        $id = $this->issue();
        Grant::query()->whereKey($id)->update(['stop_receipt' => null]);
        $this->denied('handover_evidence_required', fn () => $this->start($id));
    }

    public function test_handover_receipt_schema_fails_closed_and_cannot_drop_signed_audit(): void
    {
        $migration = require base_path('database/migrations/2026_11_18_010000_add_machine_failover_handover_receipts.php');
        $migration->down();
        $this->denied('handover_migration_required', fn () => $this->issue());
        $migration->up();
        $id = $this->issue();
        try {
            $migration->down();
            $this->fail('Expected retained stop evidence.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Failover handover receipts must be retained.', $error->getMessage());
        }
        $this->assertSame('fixture-old-writer', Grant::query()->findOrFail($id)->stop_receipt['issuer']);
    }

    public function test_migration_downgrade_refuses_to_remove_grant_history(): void
    {
        $id = $this->issue();
        $this->handover->revoke($id, $this->admin->id);
        $this->handover->releaseUnused($id, $this->admin->id);
        try {
            (require base_path('database/migrations/' . self::MIGRATION))->down();
            $this->fail('Expected retained authorization history');
        } catch (\RuntimeException $error) {
            $this->assertSame('Failover handover authorizations must be retained.', $error->getMessage());
        }
        $this->assertTrue(Grant::query()->whereKey($id)->exists());
    }

    public function test_missing_schema_fails_closed(): void
    {
        Schema::drop(Grant::TABLE);
        $this->denied('handover_migration_required', fn () => $this->issue());
        $this->denied('handover_migration_required', fn () => $this->handover->assertNewWork((string) Str::uuid()));
    }
}
