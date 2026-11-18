<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverDnsRun as DnsRun;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverHandoverGrant as Grant;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover as Handover;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverEvidence as Evidence;
use App\Services\ServerMachine\Cloud\MachineFailoverHandoverRecovery as Recovery;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFailoverConcurrentFixture as Fixture;

trait TestsHandoverClosureConcurrency
{
    private function handoverFixture(bool $sets = false): Grant
    {
        $this->run = $this->fixture->seed(recordSets: $sets, handover: true);
        Fixture::control('handover-public-keys', HandoverEvidenceFixture::publicKeys(), true);
        return Grant::query()->where('execution_id', $this->run['id'])->sole();
    }

    private function issuance(Grant $previous, string $label): array
    {
        $admin = $previous->admin_id;
        $scope = $previous->only(['service_id', 'service_revision', 'context_hash', 'release'])
            + ['evidence_sha256' => hash('sha256', 'synthetic-issuance:' . $label), 'expires_at' => time() + 600];
        return ['operation' => 'issue', 'scope' => $scope, 'admin' => $admin, 'proof' => HandoverEvidenceFixture::stop($scope, $admin)];
    }

    private function review(Grant $grant, array $overrides = []): array
    {
        return HandoverEvidenceFixture::close(app(Handover::class)->closureScope($grant->id, $grant->admin_id), $overrides);
    }

    private function closing(Grant $grant, array $proof, string $operation = 'close'): array
    {
        return ['operation' => $operation, 'grant' => $grant->id, 'admin' => $grant->admin_id, 'proof' => $proof];
    }

    private function stopHandoverFixture(): void
    {
        $this->revokeHandover();
        $this->assertSame('stopped', $this->fixture->stop($this->run['id'])['state']);
    }

    private function handoverDenials(): array
    {
        return DB::table('native_fixture_event')->where('kind', 'handover_denied')->orderBy('id')->pluck('reference')->all();
    }

    public static function issuanceFences(): array
    {
        return ['normal Redis lock' => [false], 'expired Redis lock' => [true]];
    }

    #[DataProvider('issuanceFences')]
    public function test_handover_concurrent_issuance_has_one_writer_even_when_the_registry_lock_expires(bool $expireLock): void
    {
        $previous = $this->handoverFixture(); $this->stopHandoverFixture();
        app(Handover::class)->releaseStopped($previous->id, $previous->admin_id, $this->review($previous));
        $this->assertNull($previous->fresh()->writer_slot);
        $phase = $expireLock ? 'handover_issue_before_insert' : 'handover_issue_ready';
        Fixture::control('fault', $phase, true);
        $one = $this->spawn('handover', $this->issuance($previous, 'first'));
        if ($expireLock) { $this->barrier($phase); Fixture::expireHandoverRegistryLock(); }
        $two = $this->spawn('handover', $this->issuance($previous, 'second'));
        $this->barrier($phase, 2);
        $this->assertSame(0, Grant::query()->whereNotNull('writer_slot')->count());
        Fixture::control('release', true, true); $this->join($one); $this->join($two);
        $this->cleanWorkers();
        $this->assertSame(1, $this->eventCount('handover_issued'));
        $this->assertSame([$expireLock ? 'handover_conflict' : 'handover_slot_held'], $this->handoverDenials());
        $this->assertSame(2, Grant::query()->count());
        $winner = Grant::query()->where('writer_slot', 1)->sole();
        $this->assertSame('fixture-old-writer', $winner->stop_receipt['issuer']);
        $this->assertNull($winner->execution_id);
        $this->assertSame(0, Attempt::query()->count());
        $this->assertSame(0, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write'));
    }

    public function test_handover_concurrent_closures_commit_only_one_receipt_under_the_parent_lock(): void
    {
        $grant = $this->handoverFixture(); $this->workOnce(); $this->stopHandoverFixture();
        $proofs = [$this->review($grant), $this->review($grant, ['valid_until' => time() + 75])];
        $digests = array_map(fn ($proof) => hash('sha256', Evidence::signingInput(Evidence::CLOSE, $proof['issuer'], $proof['body'])), $proofs);
        $this->assertNotSame($digests[0], $digests[1]);
        $task = Task::query()->findOrFail(Attempt::query()->sole()->task_id); $original = $task->getRawOriginal();
        DB::beginTransaction();
        try {
            Runtime::query()->lockForUpdate()->findOrFail($this->run['id']);
            $workers = array_map(fn ($proof) => $this->spawn('handover', $this->closing($grant, $proof)), $proofs);
            $this->wait(fn () => Fixture::control('handover-close-read:' . $workers[0]) === true
                && Fixture::control('handover-close-read:' . $workers[1]) === true, 'both closure requests reached the parent lock');
            $this->assertSame(1, $grant->fresh()->writer_slot);
            $this->assertNull($grant->fresh()->closure_receipt);
            $this->assertSame(0, $this->eventCount('handover_closed'));
        } finally { DB::rollBack(); }
        foreach ($workers as $worker) $this->join($worker);
        $this->cleanWorkers();
        $this->assertSame(1, $this->eventCount('handover_closed'));
        $this->assertSame(['handover_release_not_stopped'], $this->handoverDenials());
        $winner = DB::table('native_fixture_event')->where('kind', 'handover_closed')->value('worker');
        $this->assertContains($winner, $workers);
        $closed = $grant->fresh();
        $this->assertNull($closed->writer_slot);
        $this->assertSame($digests[array_search($winner, $workers, true)], $closed->closure_receipt['proof_sha256']);
        $this->assertSame($original, $task->fresh()->getRawOriginal());
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write'));
    }

    public function test_handover_uncommitted_closure_keeps_the_slot_and_rolls_back_on_worker_death(): void
    {
        $grant = $this->handoverFixture(); $this->stopHandoverFixture();
        $proof = $this->review($grant);
        Fixture::control('fault', 'handover_close_uncommitted', true);
        $worker = $this->spawn('handover', $this->closing($grant, $proof, 'close_uncommitted'));
        $this->barrier('handover_close_uncommitted');
        $this->assertSame(1, $grant->fresh()->writer_slot); $this->assertNull($grant->fresh()->closure_receipt);
        $this->join($this->spawn('handover', $this->issuance($grant, 'before-commit')));
        $this->assertSame(['handover_slot_held'], $this->handoverDenials());
        $this->kill($worker);
        $this->assertSame(1, $grant->fresh()->writer_slot); $this->assertNull($grant->fresh()->closure_receipt);
        $this->join($this->spawn('handover', $this->closing($grant, $proof)));
        $this->assertSame(1, $this->eventCount('handover_closed')); $this->assertNull($grant->fresh()->writer_slot);
        $this->join($this->spawn('handover', $this->issuance($grant, 'after-commit')));
        $this->assertSame(1, $this->eventCount('handover_issued'));
        $this->assertSame(1, Grant::query()->where('writer_slot', 1)->count());
        $this->assertSame(0, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write')); $this->cleanWorkers();
    }

    public static function lateCreateReplyCases(): array { return ['before closure' => [false], 'after closure' => [true]]; }

    #[DataProvider('lateCreateReplyCases')]
    public function test_handover_late_create_reply_cannot_override_the_current_closure(bool $afterClosure): void
    {
        $grant = $this->handoverFixture();
        Fixture::control('fault', 'create_after', true); $worker = $this->spawn(); $this->barrier('create_after');
        $attempt = Attempt::query()->sole(); $task = Task::query()->findOrFail($attempt->task_id);
        $this->assertNull($task->external_id);
        $this->revokeHandover(); $this->fixture->expire($this->run['id']); $this->join($this->spawn('recover'));
        $this->assertSame('stopped', $this->runStatus());
        $baseline = $task->fresh()->getRawOriginal();
        $observed = app(Recovery::class)->cloud($this->run['id'], $attempt->id, $grant->admin_id);
        $this->assertSame('matched_original', $observed['result']);
        $outcome = $this->review($grant)['body']['outcomes'];
        $outcome[0]['evidence_sha256'] = $observed['observation_sha256'];
        $proof = $this->review($grant, ['outcomes' => $outcome]);
        if ($afterClosure) {
            $this->join($this->spawn('handover', $this->closing($grant, $proof)));
            $this->assertNull($grant->fresh()->writer_slot);
        }
        Fixture::control('release', true, true); $this->join($worker); $this->cleanWorkers();
        $this->assertSame('needs_review', $task->fresh()->status);
        $this->assertSame('stopped', $this->runStatus());
        if ($afterClosure) {
            $this->assertSame($baseline, $task->fresh()->getRawOriginal());
            $this->assertSame($outcome, $grant->fresh()->closure_receipt['outcomes']);
        } else {
            $this->assertNotNull($task->fresh()->external_id);
            $this->join($this->spawn('handover', $this->closing($grant, $proof)));
            $this->assertSame(['handover_evidence_scope_changed'], $this->handoverDenials());
            $this->assertSame(1, $grant->fresh()->writer_slot);
            $fresh = app(Recovery::class)->cloud($this->run['id'], $attempt->id, $grant->admin_id);
            $this->assertSame('matched_original', $fresh['result']);
            $outcome[0]['evidence_sha256'] = $fresh['observation_sha256'];
            $this->join($this->spawn('handover', $this->closing($grant, $this->review($grant, ['outcomes' => $outcome]))));
        }
        $this->assertSame(1, $this->eventCount('handover_closed')); $this->assertNull($grant->fresh()->writer_slot);
        $this->assertSame(1, $this->eventCount('create')); $this->assertSame(0, $this->eventCount('dns_write')); $this->cleanWorkers();
    }

    public static function lateDnsReplyCases(): array { return ['single A record' => [false], 'dual-stack set' => [true]]; }

    #[DataProvider('lateDnsReplyCases')]
    public function test_handover_late_dns_reply_cannot_reopen_a_closed_intent(bool $sets): void
    {
        $grant = $this->handoverFixture($sets); $this->workOnce(); $this->fixture->initialized(); $this->workOnce();
        Fixture::control('fault', 'dns_after', true); $this->fixture->due($this->run['id']); $worker = $this->spawn(); $this->barrier('dns_after');
        $this->revokeHandover(); $this->fixture->expire($this->run['id']); $this->join($this->spawn('recover'));
        $this->assertSame('stopped', $this->runStatus());
        $dns = $sets ? DnsRun::query()->sole() : MachineDnsTransition::query()->sole();
        $attempt = Attempt::query()->sole(); $baseline = $dns->getRawOriginal();
        $recovery = app(Recovery::class);
        $observed = $sets ? $recovery->dnsSet($this->run['id'], $attempt->id, $dns->id, $grant->admin_id)
            : $recovery->dns($this->run['id'], $attempt->id, $grant->admin_id);
        $this->assertSame('matched_original', $observed['result']);
        $outcomes = $this->review($grant)['body']['outcomes'];
        $this->assertCount(2, $outcomes);
        $outcomes[1]['evidence_sha256'] = $observed['observation_sha256'];
        $this->join($this->spawn('handover', $this->closing($grant, $this->review($grant, ['outcomes' => $outcomes]))));
        $this->assertNull($grant->fresh()->writer_slot);
        Fixture::control('release', true, true); $this->join($worker); $this->cleanWorkers();
        $this->assertSame($baseline, $dns->fresh()->getRawOriginal());
        $this->assertSame($outcomes, $grant->fresh()->closure_receipt['outcomes']);
        $this->assertSame('stopped', $this->runStatus());
        $this->assertSame(1, $this->eventCount('dns_write')); $this->assertSame(1, $this->eventCount('create'));
        $this->assertSame(0, DB::table('native_fixture_event')->where('kind', 'dns_write')->where('reference', 'AAAA')->count());
        $this->assertNull(MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->value('active_policy_id'));
    }
}
