<?php

namespace Tests\Support;

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
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverRecovery;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

trait TestsHandoverDispatch
{
    private function issueHandover(int $service, int $revision): string
    {
        (require base_path('database/migrations/2026_11_18_000000_create_machine_failover_handover_grants.php'))->up();
        $registry = app(MachineFailoverServiceRegistry::class);
        return app(MachineFailoverHandover::class)->issue(['service_id' => $service, 'service_revision' => $revision,
            'context_hash' => $registry->fingerprint($registry->snapshot($service, $revision)),
            'release' => $this->release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256']),
            'evidence_sha256' => hash('sha256', 'fixture stopped old writer'), 'expires_at' => time() + 600], $this->admin->id);
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
}
