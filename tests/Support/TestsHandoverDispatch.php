<?php

namespace Tests\Support;

use App\Jobs\MachineFailoverOperatorRunJob;
use App\Jobs\MachineFailoverRunJob;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineOwnedInstance;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverRecovery;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverOperator;
use App\Services\ServerMachine\Cloud\MachineFailoverOperatorPermit;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

trait TestsHandoverDispatch
{
    private function issueHandover(int $service, int $revision): string
    {
        (require base_path('database/migrations/2026_11_18_000000_create_machine_failover_handover_grants.php'))->up();
        (require base_path('database/migrations/2026_11_18_010000_add_machine_failover_handover_receipts.php'))->up();
        HandoverEvidenceFixture::trust();
        $registry = app(MachineFailoverServiceRegistry::class);
        $scope = ['service_id' => $service, 'service_revision' => $revision,
            'context_hash' => $registry->fingerprint($registry->snapshot($service, $revision)),
            'release' => $this->release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']),
            'evidence_sha256' => hash('sha256', 'fixture stopped old writer'), 'expires_at' => time() + 600];
        return app(MachineFailoverHandover::class)->issue($scope, $this->admin->id, HandoverEvidenceFixture::stop($scope, $this->admin->id));
    }

    private function handoverStart(string $dns = 'single'): array
    {
        $this->nodeReadiness = true;
        $this->runtimeSchema();
        config(['machine_failover.runner_enabled' => true]);
        return $dns === 'single' ? $this->start(handover: true) : $this->startSets(dnsProvider: $dns, handover: true);
    }

    private function revokeHandover(): void
    {
        app(MachineFailoverHandover::class)->revoke(Grant::query()->firstOrFail()->id, $this->admin->id);
    }

    private function operatorService(): array
    {
        $this->nodeReadiness = true; $this->runtimeSchema(); $this->enable();
        $fixture = $this->fixtures[0];
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Operator fixture', 'max_parallel' => 1,
            'attempt_limit' => 1, 'members' => [['policy_id' => $fixture['policy']->id, 'policy_revision' => $fixture['policy']->revision,
                'name' => $fixture['machine']->name, 'priority' => 1, 'enabled' => true]]], $this->admin->id);
        $this->issueHandover($service['id'], $service['revision']);
        config(['machine_failover.operator_enabled' => true, 'machine_failover.allow_replacement' => false,
            'machine_failover.allow_rotation' => false, 'machine_provision.allow_live' => false,
            'machine_acceptance.allow_readiness' => false, 'machine_acceptance.allow_verification' => false, 'machine_acceptance.allow_dns' => false,
            'queue.connections.' . MachineFailoverQueue::CONNECTION => ['driver' => 'redis', 'retry_after' => MachineFailoverQueue::RETRY_AFTER]]);
        $this->operatorConsumer(true);
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        return $service;
    }

    private function operatorConsumer(bool $running): void
    {
        if ($running) Cache::put(MachineFailoverQueue::OPERATOR_HEARTBEAT, str_repeat('a', 32), MachineFailoverQueue::OPERATOR_HEARTBEAT_SECONDS);
        else Cache::forget(MachineFailoverQueue::OPERATOR_HEARTBEAT);
    }

    private function operatorTick(array $run): array
    {
        Runtime::query()->whereKey($run['id'])->update(['next_check_at' => 0]);
        $this->task()->fill(['next_check_at' => 0])->save();
        $this->runner()->advanceOperator();
        $job = Bus::dispatched(MachineFailoverOperatorRunJob::class)->last(fn ($job) => $job->execution === $run['id']);
        $job->handle($this->runner());
        return app(MachineFailoverNativeBridge::class)->report($run['id'], $this->admin->id);
    }

    public function test_operator_ticket_is_bound_to_one_run_while_the_legacy_switches_stay_closed(): void
    {
        $service = $this->operatorService(); $admin = $this->admin->id;
        $operator = app(MachineFailoverOperator::class);
        $inspect = fn () => $operator->preview($service['id'], $service['revision'], $admin);
        config(['machine_failover.operator_enabled' => false]);
        self::assertSame('orchestration_disabled', $inspect()['blocker']);
        config(['machine_failover.operator_enabled' => true]);
        $this->operatorConsumer(false);
        self::assertSame('runner_disabled', $inspect()['blocker']);
        $this->operatorConsumer(true);
        $preview = $inspect();
        self::assertNull($preview['blocker'], json_encode($preview));
        self::assertNull($operator->receipt($service['id'], $preview['request_id'], $admin)['execution_id']);
        $this->code('provisioning_disabled', fn () => app(MachineProvisionService::class)->queue([], str_repeat('a', 32), $admin));
        $this->code('orchestration_disabled', fn () => app(MachineFailoverExecutionGate::class)->assertAllowed('admission'));
        $accepted = $operator->start($service['id'], $preview['confirmation'], $admin);
        self::assertSame($accepted, $operator->start($service['id'], $preview['confirmation'], $admin));
        self::assertSame($accepted, $operator->receipt($service['id'], $preview['request_id'], $admin));
        self::assertSame(MachineFailoverOperatorPermit::MODE, Execution::query()->sole()->payload['launch_mode']);
        self::assertSame($accepted['execution_id'], Grant::query()->sole()->execution_id);
        self::assertSame(1, Runtime::query()->count());
        Bus::assertDispatchedTimes(MachineFailoverOperatorRunJob::class, 1);
        Bus::assertNotDispatched(MachineFailoverRunJob::class);
        $lost = Bus::dispatched(MachineFailoverOperatorRunJob::class)->last();
        self::assertSame(MachineFailoverQueue::OPERATOR_NAME, $lost->queue);
        config(['machine_failover.runner_enabled' => true]);
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void { if ($operation !== 'runner') parent::assertAllowed($operation); }
        });
        (new MachineFailoverRunJob($lost->execution, $lost->generation, $lost->ticket))->handle($this->runner());
        self::assertSame('queued', Runtime::query()->sole()->state);
        self::assertSame(0, Attempt::query()->count());
        config(['machine_failover.runner_enabled' => false]);
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        Runtime::query()->whereKey($accepted['execution_id'])->update(['next_dispatch_at' => 0]);
        self::assertSame(0, $this->runner()->advance()['queued']);
        $this->operatorConsumer(false);
        self::assertSame(0, $this->runner()->advanceOperator()['queued']);
        Bus::assertDispatchedTimes(MachineFailoverOperatorRunJob::class, 1);
        $this->operatorConsumer(true);
        self::assertSame(1, $this->runner()->advanceOperator()['queued']);
        $lost->handle($this->runner());
        self::assertCount(0, $this->posts);
        $job = Bus::dispatched(MachineFailoverOperatorRunJob::class)->last();
        $job->handle($this->runner()); $job->handle($this->runner());
        self::assertCount(1, $this->posts);
        $run = ['id' => $accepted['execution_id']];
        $task = $this->task();
        $this->code('invalid_callback', fn () => app(MachineProvisionService::class)->callback($task->id,
            $task->installation_id, str_repeat('0', 64), 'succeeded'));
        $this->runtimeReady($run);
        $run = $this->operatorTick($run);
        self::assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        self::assertSame('completed', $this->operatorTick($run)['status']);
        Runtime::query()->whereKey($run['id'])->update(['state' => 'processing', 'lease_until' => time() + 30]);
        self::assertSame(0, $this->runner()->advanceOperator(true)['queued']);
        self::assertSame('finished', Runtime::query()->findOrFail($run['id'])->state);
        self::assertSame(1, $this->dnsWrites);
        self::assertCount(1, $this->posts);
        self::assertNull(MachineFailoverOperatorPermit::current());
        self::assertFalse(config('machine_provision.allow_live'));
        self::assertFalse(config('machine_failover.runner_enabled'));
        self::assertFalse(config('machine_acceptance.allow_dns'));
        Bus::assertNotDispatched(\App\Jobs\MachineProvisionJob::class);
    }

    public function test_revoked_operator_ticket_cannot_claim_a_provider_intent(): void
    {
        $service = $this->operatorService(); $operator = app(MachineFailoverOperator::class); $admin = $this->admin->id;
        $preview = $operator->preview($service['id'], $service['revision'], $admin);
        $accept = $operator->start($service['id'], $preview['confirmation'], $admin);
        $job = Bus::dispatched(MachineFailoverOperatorRunJob::class)->last();
        $this->revokeHandover();
        $this->operatorConsumer(false);
        self::assertSame(1, $this->runner()->advanceOperator()['stopped']);
        $job->handle($this->runner()); $job->handle($this->runner());
        self::assertSame('stopped', $operator->receipt($service['id'], $preview['request_id'], $admin)['status']);
        self::assertSame('handover_revoked', Runtime::query()->findOrFail($accept['execution_id'])->reason);
        self::assertSame(0, Attempt::query()->count());
        self::assertCount(0, $this->posts);
        self::assertSame(0, $this->dnsWrites);
        self::assertNull(MachineFailoverOperatorPermit::current());
    }

    public function test_handover_binds_native_intent_before_enqueue_and_rejects_a_stale_ticket(): void
    {
        $run = $this->handoverStart();
        $original = Execution::query()->findOrFail($run['id']);
        $grant = Grant::query()->firstOrFail();
        $this->assertSame($run['id'], $grant->execution_id);
        $this->assertSame($grant->id, $original->payload['handover_grant_id']);
        $this->assertSame($grant->expires_at, $original->deadline_at);
        $plans = Member::query()->where('execution_id', $run['id'])->pluck('plan_id')->all();
        $native = app(MachineFailoverNativeBridge::class);
        $args = [$original->service_id, $original->service_revision, $plans, $this->release->id, str_repeat('d', 32), $this->admin->id];
        $this->assertSame($run['id'], $native->start(...[...$args, null, $grant->id])['id']);
        $this->code('request_conflict', fn () => $native->start(...$args));
        $job = Bus::dispatched(MachineFailoverRunJob::class)->last();
        $this->revokeHandover();
        $this->code('handover_revoked', fn () => $native->start(...[...$args, null, $grant->id]));
        $job->handle($this->runner()); $job->handle($this->runner());
        $this->assertSame('stopped', $original->fresh()->status);
        $this->assertSame('handover_revoked', Runtime::query()->findOrFail($run['id'])->reason);
        $this->assertSame(0, Attempt::query()->count());
        $this->assertCount(0, $this->posts);
        Bus::assertDispatchedTimes(MachineFailoverRunJob::class, 1);
    }

    public function test_handover_due_sweep_stops_expired_grants_instead_of_resubmitting(): void
    {
        $run = $this->handoverStart();
        $grant = Grant::query()->firstOrFail();
        Carbon::setTestNow(Carbon::createFromTimestampUTC($grant->expires_at));
        Runtime::query()->whereKey($run['id'])->update(['next_dispatch_at' => 0, 'next_check_at' => 0]);
        $this->assertSame(0, $this->runner()->advance()['queued']);
        $runtime = Runtime::query()->findOrFail($run['id']);
        $this->assertSame('stopped', $runtime->state);
        $this->assertSame('handover_expired', $runtime->reason);
        $this->assertCount(0, $this->posts);
        Bus::assertDispatchedTimes(MachineFailoverRunJob::class, 1);
    }

    public function test_handover_persisted_binding_blocks_dispatch_when_the_payload_tag_is_missing(): void
    {
        $run = $this->handoverStart();
        $original = Execution::query()->findOrFail($run['id']);
        $payload = $original->payload;
        unset($payload['handover_grant_id']);
        $original->fill(['payload' => $payload])->save();

        Runtime::query()->whereKey($run['id'])->update(['next_check_at' => 0, 'next_dispatch_at' => 0]);
        $this->assertSame(0, $this->runner()->advance()['queued']);
        $this->assertSame('stopped', $original->fresh()->status);
        $this->assertSame('handover_execution_changed', Runtime::query()->findOrFail($run['id'])->reason);
        $this->assertCount(0, $this->posts);
        Bus::assertDispatchedTimes(MachineFailoverRunJob::class, 1);
    }

    public static function handoverCreateBoundaries(): array
    {
        return ['preflight' => ['preflight', 0, null], 'lost ack with resource' => ['unknown_success', 1, 'matched_original'],
            'lost ack without visibility' => ['unknown_absent', 1, 'unconfirmed'], 'known ID' => ['', 1, 'matched_original']];
    }

    #[DataProvider('handoverCreateBoundaries')]
    public function test_handover_revocation_at_create_preserves_only_original_readback(string $mode, int $posts, ?string $expected): void
    {
        $run = $this->handoverStart();
        $job = Bus::dispatched(MachineFailoverRunJob::class)->last();
        if ($mode === 'preflight') $this->duringPreflight = fn () => $this->revokeHandover();
        else { $this->mode = $mode; $this->duringPost = fn () => $this->revokeHandover(); }
        $this->tick($run); $job->handle($this->runner());
        $this->assertSame('stopped', Execution::query()->findOrFail($run['id'])->status);
        $attempt = Attempt::query()->sole(); $task = $this->task();
        $this->assertCount($posts, $this->posts);
        $this->assertSame(0, $this->dnsWrites);
        $recovery = app(MachineFailoverHandoverRecovery::class);
        $this->code('handover_readback_identity', fn () => $recovery->cloud($run['id'], (string) Str::uuid(), $this->admin->id));
        if ($expected === null) {
            $this->assertNull($task->dispatched_at);
            $this->code('handover_readback_identity', fn () => $recovery->cloud($run['id'], $attempt->id, $this->admin->id));
        } else {
            $this->assertNotNull($task->dispatched_at);
            $this->assertSame('needs_review', $task->status);
            $before = $task->getRawOriginal();
            $result = $recovery->cloud($run['id'], $attempt->id, $this->admin->id);
            $this->assertSame($expected, $result['result']);
            $this->assertSame($result, $recovery->cloud($run['id'], $attempt->id, $this->admin->id));
            $this->assertSame($before, $task->fresh()->getRawOriginal());
        }
        $this->assertCount($posts, $this->posts);
        $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame(1, Grant::query()->sole()->writer_slot);
    }

    public static function handoverDnsBoundaries(): array
    {
        return ['before legacy patch' => ['single', false], 'after legacy patch' => ['single', true],
            'before set patch' => ['cloudflare', false], 'after set patch' => ['cloudflare', true],
            'after Aliyun patch' => ['aliyun', true]];
    }

    #[DataProvider('handoverDnsBoundaries')]
    public function test_handover_revocation_at_dns_never_writes_again_or_promotes_a_candidate(string $provider, bool $written): void
    {
        $run = $this->tick($this->handoverStart($provider));
        $this->runtimeReady($run); $run = $this->tick($run);
        $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $task = $this->task(); $candidate = MachineOwnedInstance::query()->where('task_id', $task->id)->firstOrFail();
        $recovery = app(MachineFailoverHandoverRecovery::class);
        $attempt = Attempt::query()->sole();
        $this->code('handover_readback_not_stopped', fn () => $recovery->dns($run['id'], $attempt->id, $this->admin->id));
        $revoke = function () { $this->duringDnsRead = $this->duringDnsWrite = null; $this->revokeHandover(); };
        if ($written) $this->duringDnsWrite = $revoke;
        else $this->duringDnsRead = function () use ($provider, $revoke) { if ($provider === 'single' || DnsRun::query()->exists()) $revoke(); };
        $job = Bus::dispatched(MachineFailoverRunJob::class)->last();
        $this->tick($run); $job->handle($this->runner());
        $this->assertSame('stopped', Execution::query()->findOrFail($run['id'])->status);
        $this->assertSame($written ? 1 : 0, $this->dnsWrites);
        $this->assertNull($candidate->fresh()->active_policy_id);
        $attempt = $attempt->fresh();
        $dns = $provider === 'single' ? null : DnsRun::query()->sole();
        $inspect = fn () => $dns ? $recovery->dnsSet($run['id'], $attempt->id, $dns->id, $this->admin->id)
            : $recovery->dns($run['id'], $attempt->id, $this->admin->id);
        if ($written) {
            $recordsBefore = $this->records; $dnsBefore = $dns?->getRawOriginal();
            $this->assertSame('matched_original', $inspect()['result']); $this->assertSame($recordsBefore, $this->records);
            $this->assertSame($dnsBefore, $dns?->fresh()->getRawOriginal());
            $credentialId = $dns ? $dns->payload['snapshot']['set']['credential_id'] : $this->fixtures[0]['policy']->dns_credential_id;
            MachineDnsCredential::query()->whereKey($credentialId)->increment('revision');
            $this->code('dns_credential_changed', $inspect);
        } else $this->code('handover_readback_identity', $inspect);
        $this->assertSame($written ? 1 : 0, $this->dnsWrites);
        $this->assertCount(1, $this->posts);
        $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame('needs_review', $task->fresh()->status);
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $count = Http::recorded()->count();
        $this->code('orchestration_disabled', fn () => app(MachineFailoverHandoverRecovery::class)->cloud($run['id'], $attempt->id, $this->admin->id));
        $this->assertSame($count, Http::recorded()->count());
    }

    public function test_handover_closure_requires_a_settled_parent_and_separate_authority_even_with_no_dispatch(): void
    {
        $run = $this->handoverStart(); $grant = Grant::query()->sole(); $admin = $this->admin->id;
        $handover = app(MachineFailoverHandover::class);
        $scope = fn () => $handover->closureScope($grant->id, $admin);
        $job = Bus::dispatched(MachineFailoverRunJob::class)->last();
        $this->code('handover_release_not_stopped', $scope);
        $this->revokeHandover();
        $this->code('handover_release_not_stopped', $scope);
        $job->handle($this->runner());
        $closed = $scope(); $this->assertSame([], $closed['operations']);
        $proof = HandoverEvidenceFixture::close($closed);
        $oldScope = $grant->only(['service_id', 'service_revision', 'context_hash', 'release', 'evidence_sha256', 'expires_at']);
        $this->code('handover_evidence_invalid', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::stop($oldScope, $admin)));
        app()->forgetInstance(MachineFailoverHandoverEvidence::class);
        $this->code('handover_authority_unavailable', fn () => $handover->releaseStopped($grant->id, $admin, $proof));
        HandoverEvidenceFixture::trust();
        Runtime::query()->whereKey($run['id'])->update(['ticket_hash' => str_repeat('f', 64)]);
        $this->code('handover_release_not_stopped', fn () => $handover->releaseStopped($grant->id, $admin, $proof));
        Runtime::query()->whereKey($run['id'])->update(['ticket_hash' => null]);
        $this->assertSame(1, $grant->fresh()->writer_slot);
        $http = Http::recorded()->count();
        try {
            DB::transaction(function () use ($handover, $grant, $admin, $proof) {
                $handover->releaseStopped($grant->id, $admin, $proof);
                throw new \RuntimeException('fixture rollback after writing closure');
            });
            $this->fail('Expected rolled-back release.');
        } catch (\RuntimeException $error) {
            $this->assertSame('fixture rollback after writing closure', $error->getMessage());
        }
        $this->assertSame(1, $grant->fresh()->writer_slot);
        $this->assertNull($grant->fresh()->closure_receipt);
        $handover->releaseStopped($grant->id, $admin, $proof);
        $this->assertNull($grant->fresh()->writer_slot);
        $this->assertArrayNotHasKey('closure_receipt', $grant->fresh()->toArray());
        $this->assertSame('fixture-reviewer', $grant->fresh()->closure_receipt['issuer']);
        $this->code('handover_release_not_stopped', fn () => $handover->releaseStopped($grant->id, $admin, $proof));
        $job->handle($this->runner());
        $this->assertSame($http, Http::recorded()->count());
        $this->assertSame(0, Attempt::query()->count());
    }

    public function test_handover_closure_after_lost_create_ack_needs_final_evidence_and_preserves_history(): void
    {
        $run = $this->handoverStart(); $grant = Grant::query()->sole(); $admin = $this->admin->id;
        $handover = app(MachineFailoverHandover::class);
        $this->mode = 'unknown_success'; $this->duringPost = fn () => $this->revokeHandover();
        $this->tick($run);
        $attempt = Attempt::query()->sole(); $task = $this->task();
        $scope = $handover->closureScope($grant->id, $admin);
        $this->assertSame(['cloud_create'], array_column($scope['operations'], 'kind'));
        $this->assertNull($scope['operations'][0]['known_outcome']);
        $review = HandoverEvidenceFixture::close($scope)['body']['outcomes'];
        foreach ([[], [array_replace($review[0], ['result' => 'unknown'])], [array_replace($review[0], ['basis' => 'inventory_empty'])]] as $outcomes) {
            $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['outcomes' => $outcomes])));
        }
        $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['in_flight' => 1])));
        $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['fence_state' => 'resumed'])));
        $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['observed_at' => $scope['settled_at'] - 1])));
        $this->code('handover_evidence_expired', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['valid_until' => time() - 1])));
        $proof = HandoverEvidenceFixture::close($scope);
        $task->fill(['reason' => 'fixture_changed_after_snapshot'])->save();
        $this->code('handover_evidence_scope_changed', fn () => $handover->releaseStopped($grant->id, $admin, $proof));
        $this->assertSame(1, $grant->fresh()->writer_slot);
        $readback = app(MachineFailoverHandoverRecovery::class)->cloud($run['id'], $attempt->id, $admin);
        $this->assertSame('matched_original', $readback['result']);
        $review[0]['evidence_sha256'] = $readback['observation_sha256'];
        $before = $task->refresh()->getRawOriginal(); $http = Http::recorded()->count();
        $scope = $handover->closureScope($grant->id, $admin);
        $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['outcomes' => $review]));
        $this->assertSame($before, $task->fresh()->getRawOriginal());
        $this->assertSame('needs_review', Member::query()->sole()->phase);
        $this->assertSame($review, $grant->fresh()->closure_receipt['outcomes']);
        $this->assertNull($grant->fresh()->writer_slot);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $next = $grant->only(['service_id', 'service_revision', 'context_hash', 'release', 'expires_at']);
        $next['evidence_sha256'] = hash('sha256', 'fixture: newly reviewed old-writer evidence');
        $nextId = $handover->issue($next, $admin, HandoverEvidenceFixture::stop($next, $admin));
        $this->assertNotSame($grant->id, $nextId);
        $this->assertSame($http, Http::recorded()->count());
    }

    public function test_handover_partial_dns_closure_binds_every_original_write_and_keeps_the_candidate_inactive(): void
    {
        $run = $this->tick($this->handoverStart('cloudflare'));
        $this->runtimeReady($run); $run = $this->tick($run);
        $this->duringDnsWrite = function () { $this->duringDnsWrite = null; $this->revokeHandover(); };
        $this->tick($run);
        $grant = Grant::query()->sole(); $admin = $this->admin->id; $handover = app(MachineFailoverHandover::class);
        $scope = $handover->closureScope($grant->id, $admin);
        $this->assertSame(['cloud_create', 'dns_record'], array_column($scope['operations'], 'kind'));
        $this->assertSame('confirmed_applied', $scope['operations'][0]['known_outcome']);
        $dns = DnsRun::query()->sole(); $attempt = Attempt::query()->sole();
        $original = $attempt->payload; $missingLink = $original; unset($missingLink['dns_set_runs']);
        $attempt->fill(['payload' => $missingLink])->save();
        $this->code('handover_closure_journal_changed', fn () => $handover->closureScope($grant->id, $admin));
        $attempt->fill(['payload' => $original])->save();
        $scope = $handover->closureScope($grant->id, $admin);
        $review = HandoverEvidenceFixture::close($scope)['body']['outcomes'];
        foreach ([[$review[0]], [$review[0], $review[0]], [array_replace($review[0], ['result' => 'confirmed_not_applied']), $review[1]]] as $outcomes) {
            $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['outcomes' => $outcomes])));
        }
        $this->assertSame(1, $grant->fresh()->writer_slot);
        $readback = app(MachineFailoverHandoverRecovery::class)->dnsSet($run['id'], $attempt->id, $dns->id, $admin);
        $this->assertSame('matched_original', $readback['result']);
        $review[1]['evidence_sha256'] = $readback['observation_sha256'];
        $records = $this->records; $http = Http::recorded()->count();
        $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['outcomes' => $review]));
        $this->assertSame($records, $this->records);
        $this->assertSame($http, Http::recorded()->count());
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame($dns->getRawOriginal(), $dns->fresh()->getRawOriginal());
        $this->assertNull(MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->firstOrFail()->active_policy_id);
        $this->assertNull($grant->fresh()->writer_slot);
    }

    public function test_handover_does_not_settle_an_unconfirmed_candidate_delete_from_one_missing_read(): void
    {
        $run = $this->retryFixture(handover: true);
        $run = $this->candidateSample($run, time() - 15, viaRunner: true);
        $run = $this->candidateSample($run, time() - 10, viaRunner: true);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->revokeHandover(); $this->tick($run);
        $grant = Grant::query()->sole(); $admin = $this->admin->id; $handover = app(MachineFailoverHandover::class);
        $scope = $handover->closureScope($grant->id, $admin);
        $this->assertSame(['candidate_delete', 'cloud_create'], array_column($scope['operations'], 'kind'));
        $this->assertNull($scope['operations'][0]['known_outcome']);
        $outcomes = HandoverEvidenceFixture::close($scope)['body']['outcomes'];
        $outcomes[0]['basis'] = 'single_404';
        $this->code('handover_closure_incomplete', fn () => $handover->releaseStopped($grant->id, $admin, HandoverEvidenceFixture::close($scope, ['outcomes' => $outcomes])));
        $this->task()->fill(['external_id' => '98765'])->save();
        $this->code('candidate_cleanup_identity_changed', fn () => $handover->closureScope($grant->id, $admin));
        $this->assertSame(1, $grant->fresh()->writer_slot);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_handover_closure_can_release_a_revoked_completed_run_without_reopening_it(): void
    {
        $run = $this->tick($this->handoverStart());
        $this->runtimeReady($run); $run = $this->tick($run); $run = $this->tick($run);
        $this->assertSame('completed', $run['status']);
        $grant = Grant::query()->sole(); $handover = app(MachineFailoverHandover::class);
        $scope = fn () => $handover->closureScope($grant->id, $this->admin->id);
        $this->code('handover_release_not_stopped', $scope);
        $this->revokeHandover(); $closing = $scope();
        $this->assertSame(['cloud_create', 'dns_transition'], array_column($closing['operations'], 'kind'));
        $this->assertSame(['confirmed_applied', 'confirmed_applied'], array_column($closing['operations'], 'known_outcome'));
        $handover->releaseStopped($grant->id, $this->admin->id, HandoverEvidenceFixture::close($closing));
        $this->assertNull($grant->fresh()->writer_slot);
        $this->assertSame('completed', Execution::query()->findOrFail($run['id'])->status);
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->dnsWrites);
    }
}
