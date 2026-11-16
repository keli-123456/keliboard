<?php
namespace Tests\Support;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineCandidateRetry;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;

trait TestsCandidateRetry
{
    private $retryClock;
    private int $candidateDeletes = 0;
    private bool $candidateAbsent = false;
    private bool $cleanupReadError = false;
    private bool $cleanupDeleteError = false;
    private $onCleanupRead = null;
    private $onBeforeCleanupDelete = null;

    private function retryFixture(int $attempts = 3, int $parallel = 1): array
    {
        $this->nodeReadiness = true;
        config(['machine_failover.allow_candidate_retry' => true,
            'machine_failover.allowed_candidate_retry_policy_ids' => array_column(array_map(fn ($f) => ['id' => $f['policy']->id], $this->fixtures), 'id')]);
        $this->retryClock = new class extends MachineCandidateRetry {
            public int $at;
            protected function clock(): int { return $this->at; }
        };
        $this->retryClock->at = time(); app()->instance(MachineCandidateRetry::class, $this->retryClock);
        $client = \Mockery::mock(MachineCandidateCleanupClient::class);
        $client->shouldReceive('inspect')->andReturnUsing(function ($task) {
            if ($this->onCleanupRead) ($this->onCleanupRead)();
            if ($this->cleanupReadError) throw new CloudException('provider_unavailable');
            return $this->candidateAbsent ? null : ['external_id' => $task->external_id];
        });
        $client->shouldReceive('delete')->andReturnUsing(function ($task, $guard = null) {
            if ($this->onBeforeCleanupDelete) ($this->onBeforeCleanupDelete)();
            if ($guard) $guard();
            $this->candidateDeletes++;
            if ($this->cleanupDeleteError) throw new CloudException('outcome_uncertain');
            $this->candidateAbsent = true;
        });
        app()->instance(MachineCandidateCleanupClient::class, $client);
        $run = $this->advance($this->start(parallel: $parallel, attempts: $attempts));
        config(['machine_failover.allow_rotation' => false]);
        $task = $this->task();
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 30, 'status' => 'waiting_ipv4_probe', 'next_check_at' => time() + 60])->save();
        return $run;
    }

    private function candidateSample(array $run, int $at, string $kind = 'failed'): array
    {
        $task = $this->task(); $f = $this->fixtures[0];
        $this->report($f, $task, $task->public_ips[0], $kind === 'healthy', $at);
        if (!in_array($kind, ['failed', 'healthy'], true)) {
            $key = 'machine_addresses:' . $task->machine_id . ':' . $task->machine_authority;
            $rows = Cache::get($key); $row = &$rows[$task->installation_id];
            match ($kind) {
                'error' => $row['entry_probe']['targets'][0]['status'] = 'error',
                'partial' => array_pop($row['entry_probe']['targets']),
                'wrong_source' => $row['entry_probe']['targets'][0]['source_ip'] = '7.7.7.7',
                'loopback' => $row['entry_probe']['targets'][0]['source_ip'] = '127.0.0.1',
                'stale' => $row['entry_probe_updated_at'] = time() - 1000,
                'session' => $row['runtime']['observation_session'] = str_repeat('f', 32),
                'runtime' => $row['runtime']['binary_sha256'] = str_repeat('f', 64),
                'config' => $row['entry_probe']['revision'] = str_repeat('f', 64),
                'agent' => $row['at'] = time() - 1000,
            };
            unset($row); Cache::put($key, $rows, 300);
        }
        return $this->advance($run);
    }

    private function cleanupStarted(int $attempts = 3): array
    {
        $run = $this->retryFixture($attempts);
        $run = $this->candidateSample($run, time() - 15);
        $this->assertSame(0, $this->candidateDeletes);
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        return $run;
    }

    private function cleanupConfirmed(array $run): array
    {
        $run = $this->advance($run);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->assertSame(1, count(Attempt::first()->payload['candidate_retry']['absent_at']));
        $this->advance($run);
        $this->assertSame(1, count(Attempt::first()->payload['candidate_retry']['absent_at']));
        $this->retryClock->at += 10;
        $run = $this->advance($run);
        $this->assertSame('candidate_retry_cooldown', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame('cleaned', Attempt::first()->status); $this->assertSame('failed', $this->task()->status);
        return $run;
    }

    private function finishCandidateCooldown(): void
    {
        $this->retryClock->at += 301;
        // Preserve the production wall-clock policy guard while advancing the isolated retry clock.
        Task::query()->whereKey($this->task()->id)->update(['created_at' => time() - 301]);
        $task = $this->task(); $key = 'machine_addresses:' . $task->machine_id . ':' . $task->machine_authority;
        $rows = Cache::get($key); $rows[$task->installation_id]['at'] = time() - 301; Cache::put($key, $rows, 300);
        $key = 'node_instances:' . $task->machine_id; $rows = Cache::get($key);
        $rows[$task->installation_id]['last_seen_at'] = time() - 301; Cache::put($key, $rows, 300);
    }

    public function test_candidate_retry_cleans_confirms_cools_down_then_switches_only_to_healthy_child(): void
    {
        $originalDns = $this->records;
        $run = $this->cleanupStarted(); $first = $this->task(); $identity = $first->only(['id', 'installation_id', 'marker', 'external_id']);
        app(MachineProvisionService::class)->readback($first->id);
        $this->assertSame('waiting_ipv4_probe', $first->fresh()->status);
        $run = $this->cleanupConfirmed($run);
        $this->assertSame($originalDns, $this->records); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame('candidate_retry_cooldown', $this->advance($run)['members'][0]['phase']);
        $this->assertCount(1, $this->posts);
        $this->finishCandidateCooldown(); $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase'], json_encode($run));
        $second = $this->task();
        $this->assertNotSame($first->id, $second->id); $this->assertNotSame($first->installation_id, $second->installation_id);
        $this->assertNotSame($first->marker, $second->marker); $this->assertSame($first->account_id, $second->account_id);
        $this->assertSame('waiting_initialization', $second->status, json_encode($run));
        $this->assertSame($identity, $first->fresh()->only(array_keys($identity)));
        $this->assertArrayHasKey('candidate_replacement', Attempt::query()->latest('ordinal')->first()->payload);
        $this->assertCount(2, $this->posts); $this->assertSame(1, $this->candidateDeletes);
        $run = $this->ready($run); $run = $this->advance($run);
        $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $this->verifications);
        $this->assertSame($run, $this->advance($run)); $this->assertCount(2, $this->posts);
    }

    public function test_candidate_retry_duplicate_samples_do_not_reach_failure_threshold(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 10);
        for ($n = 0; $n < 4; $n++) $run = $this->advance($run);
        $this->assertSame(1, Attempt::first()->payload['candidate_retry']['failures']);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_node_report_gap_resets_previous_probe_failures(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 20);
        $task = $this->task(); $task->fill(['status' => 'waiting_node_reports'])->save();
        $run = $this->advance($run);
        $this->assertSame('waiting_reports', $run['members'][0]['phase']);
        $this->assertSame(0, Attempt::first()->payload['candidate_retry']['failures']);
        $task->refresh()->fill(['status' => 'waiting_ipv4_probe'])->save();
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame(1, Attempt::first()->payload['candidate_retry']['failures']);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public static function candidateRetryInvalidSamples(): array
    {
        return array_map(fn ($kind) => [$kind], ['healthy', 'error', 'partial', 'wrong_source', 'loopback', 'stale', 'session', 'runtime', 'config', 'agent']);
    }

    #[DataProvider('candidateRetryInvalidSamples')]
    public function test_candidate_retry_resets_on_non_failure_or_changed_evidence(string $kind): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 20);
        $run = $this->candidateSample($run, time() - 15, $kind);
        $this->assertSame(0, $this->candidateDeletes); $this->assertSame(0, $this->dnsWrites);
        $this->assertLessThan(2, Attempt::first()->payload['candidate_retry']['failures']);
    }

    public function test_candidate_retry_uncertain_delete_only_reads_back_without_redeleting_or_creating(): void
    {
        $run = $this->retryFixture(); $this->cleanupDeleteError = true;
        $run = $this->candidateSample($run, time() - 15); $run = $this->candidateSample($run, time() - 10);
        for ($i = 0; $i < 3; $i++) { $this->retryClock->at += 10; $run = $this->advance($run); }
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        $this->assertSame('outcome_uncertain', Attempt::first()->payload['candidate_retry']['first_error']);
        $this->candidateAbsent = true; $this->retryClock->at += 10; $run = $this->cleanupConfirmed($run);
        $this->assertSame('candidate_retry_cooldown', $run['members'][0]['phase']);
    }

    public function test_candidate_retry_read_errors_are_not_absence_and_reset_confirmation(): void
    {
        $run = $this->cleanupStarted(); $run = $this->advance($run);
        $this->cleanupReadError = true; $this->retryClock->at += 10; $run = $this->advance($run);
        $this->assertSame([], Attempt::first()->payload['candidate_retry']['absent_at']);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->cleanupReadError = false; $this->retryClock->at += 10; $this->cleanupConfirmed($run);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_rate_limit_defers_readback_without_recreating(): void
    {
        $run = $this->cleanupStarted(); $reads = 0;
        $this->onCleanupRead = function () use (&$reads) {
            $reads++;
            throw new \App\Services\ServerMachine\Cloud\CloudRequestFailure([
                'code' => 'rate_limited', 'cooldown_seconds' => 120]);
        };
        $run = $this->advance($run);
        $this->assertSame($this->retryClock->at + 120, Attempt::first()->payload['candidate_retry']['next_check_at']);
        $this->retryClock->at += 119; $run = $this->advance($run);
        $this->assertSame(1, $reads); $this->assertSame([], Attempt::first()->payload['candidate_retry']['absent_at']);
        $this->retryClock->at++; $run = $this->advance($run);
        $this->assertSame(2, $reads); $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_future_probe_cannot_authorize_delete(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 10);
        $run = $this->candidateSample($run, time() + 10);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        $this->assertSame(0, Attempt::first()->payload['candidate_retry']['failures']);
    }

    public function test_candidate_retry_attempt_cap_includes_cleaned_candidate(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted(1)); $this->finishCandidateCooldown();
        $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame('attempt_budget', $run['members'][0]['reason']);
        $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }

    public function test_candidate_retry_revocation_before_delete_does_not_issue_delete(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15);
        $this->onCleanupRead = fn () => config(['machine_failover.allow_candidate_retry' => false]);
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(0, $this->candidateDeletes);
        $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_confirmed_receipt_tampering_never_authorizes_child(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        $attempt = Attempt::first(); $payload = $attempt->payload; $payload['candidate_retry']['retry_at'] = 0;
        $attempt->payload = $payload; $attempt->save(); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertCount(1, $this->posts);
        $this->assertSame('candidate_cleanup_unconfirmed', $run['members'][0]['reason']);
    }

    public function test_candidate_retry_identity_change_during_confirmation_never_creates_child(): void
    {
        $run = $this->cleanupStarted(); $this->task()->fill(['external_id' => '99999'])->save();
        $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertCount(1, $this->posts);
        $this->assertSame(1, $this->candidateDeletes);
    }

    public function test_candidate_retry_dns_ownership_change_prevents_cleanup_and_new_create(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15);
        $this->fixtures[0]['dns']->fill(['marker' => 'foreign-marker'])->save();
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_source_recovery_after_cleanup_prevents_new_create(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        $this->sourceReport($this->fixtures[0], true); $run = $this->advance($run);
        $this->assertNotSame('running', $run['status']); $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_candidate_retry_source_recovery_during_cleanup_only_finishes_readbacks(): void
    {
        $run = $this->cleanupStarted(); $this->sourceReport($this->fixtures[0], true);
        $run = $this->advance($run);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase']);
        $this->retryClock->at += 10; $run = $this->advance($run);
        $this->assertSame('cleaned', Attempt::first()->status);
        $this->finishCandidateCooldown(); $run = $this->advance($run);
        $this->assertSame('cancelled', $run['status']); $this->assertSame('source_recovered', $run['members'][0]['reason']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_waiting_cleanup_holds_parallel_slot_for_other_members(): void
    {
        $this->fixtures[] = $this->fixture(2); $run = $this->cleanupStarted();
        $this->cleanupReadError = true; $run = $this->advance($run);
        $this->assertSame('ready', $run['members'][1]['phase']); $this->assertCount(1, $this->posts);
        $this->assertSame(1, Attempt::count());
    }

    public function test_candidate_retry_expired_execution_cannot_create_after_confirmed_cleanup(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        Execution::query()->whereKey($run['id'])->update(['deadline_at' => time() - 1]);
        $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_probe_recovery_during_delete_preflight_prevents_delete(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15);
        $this->onBeforeCleanupDelete = function () {
            $task = $this->task(); $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        };
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        $this->assertSame('candidate_failure_no_longer_current', Attempt::first()->payload['candidate_retry']['first_error']);
        $this->assertFalse($this->retryClock->pending(Attempt::first()));
        $this->assertSame('checking_ipv4', $run['members'][0]['phase']);
        $this->advance($run); $this->assertSame(0, $this->candidateDeletes); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_candidate_retry_historical_run_does_not_gain_cleanup_permission(): void
    {
        $run = $this->retryFixture(); $member = Member::first(); $payload = $member->payload;
        unset($payload['candidate_retry']); $member->payload = $payload; $member->save();
        $run = $this->candidateSample($run, time() - 15); $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('checking_ipv4', $run['members'][0]['phase']);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_recovery_during_inspection_keeps_same_candidate_eligible(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15);
        $this->onCleanupRead = function () {
            $task = $this->task(); $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        };
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame('checking_ipv4', $run['members'][0]['phase']);
        $this->assertFalse($this->retryClock->pending(Attempt::first()));
        $this->assertSame(0, $this->candidateDeletes); $this->onCleanupRead = null;
        $this->task()->fill(['next_check_at' => 0])->save();
        $run = $this->advance($run); $run = $this->advance($run);
        $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->dnsWrites);
    }

    public function test_candidate_retry_busy_identity_lock_does_not_leave_a_false_delete_intent(): void
    {
        $run = $this->retryFixture(); app()->forgetInstance(MachineCandidateCleanupClient::class);
        $task = $this->task();
        $lock = Cache::lock(MachineCandidateCleanupClient::lockKey('linode', $task->external_id), 120);
        $this->assertTrue($lock->get());
        try {
            $run = $this->candidateSample($run, time() - 15); $run = $this->candidateSample($run, time() - 10);
            $this->assertSame('checking_ipv4', $run['members'][0]['phase'], json_encode($run));
            $this->assertFalse($this->retryClock->pending(Attempt::first()));
            $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        } finally { $lock->release(); }
        $run = $this->advance($run);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public static function candidateRetryBusyStages(): array { return [[false], [true]]; }

    #[DataProvider('candidateRetryBusyStages')]
    public function test_candidate_retry_busy_account_waits_without_stopping_or_creating(bool $pending): void
    {
        if ($pending) $run = $this->cleanupStarted();
        else { $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15); }
        $journal = Attempt::first()->payload['candidate_retry'];
        $lock = Cache::lock('machine-cloud:account:' . $this->task()->account_id, 120);
        $this->assertTrue($lock->get());
        try {
            $run = $pending ? $this->advance($run) : $this->candidateSample($run, time() - 10);
            $this->assertSame('running', $run['status']);
            $this->assertSame($journal, Attempt::first()->payload['candidate_retry']);
            $this->assertSame($pending, $this->retryClock->pending(Attempt::first()));
            $this->assertSame($pending ? 1 : 0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
        } finally { $lock->release(); }
        $run = $this->advance($run);
        $this->assertSame('confirming_candidate_cleanup', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_stop_preserves_confirmed_cleanup_without_retaining_deleted_vm(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted());
        $this->runtimeSchema(); $this->runner()->enroll($run['id']);
        $this->stopRuntime($run);
        $this->assertSame('cleaned', Attempt::first()->status); $this->assertSame('failed', $this->task()->status);
        $this->assertSame('cancelled', Member::first()->phase);
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->candidateDeletes);
    }

    public function test_candidate_retry_stop_with_unknown_delete_preserves_review_without_another_mutation(): void
    {
        $run = $this->cleanupStarted(); $this->runtimeSchema(); $this->runner()->enroll($run['id']);
        $this->stopRuntime($run);
        $this->assertSame('needs_review', Attempt::first()->status); $this->assertSame('needs_review', Member::first()->phase);
        $this->runner()->advance(true);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_revocation_after_confirmation_does_not_create_child(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        config(['machine_failover.allow_candidate_retry' => false]); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame('candidate_retry_disabled', $run['members'][0]['reason']);
        $this->assertSame('cleaned', Attempt::first()->status); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_daily_limit_still_blocks_after_confirmed_cleanup(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        $copy = $this->fixtures[0]['source']->replicate(); $copy->id = (string) \Illuminate\Support\Str::uuid();
        $copy->request_key = hash('sha256', $copy->id); $copy->installation_id = bin2hex(random_bytes(16));
        $copy->marker = 'synthetic-quota-' . bin2hex(random_bytes(8)); $copy->created_at = time() - 500; $copy->save();
        $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_candidate_retry_real_adapter_mocked_http_integrates_delete_and_two_404s(): void
    {
        $run = $this->retryFixture(); app()->forgetInstance(MachineCandidateCleanupClient::class);
        $run = $this->candidateSample($run, time() - 15); $run = $this->candidateSample($run, time() - 10);
        $this->assertSame(1, $this->candidateDeletes, json_encode($run));
        $this->assertArrayNotHasKey((int) $this->task()->external_id, $this->remote);
        $run = $this->cleanupConfirmed($run); $this->finishCandidateCooldown(); $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $run['members'][0]['phase'], json_encode($run));
        $this->assertCount(2, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $run = $this->ready($run); $run = $this->advance($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(1, $this->dnsWrites);
    }

    public function test_candidate_retry_session_change_with_same_probe_time_prevents_delete(): void
    {
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 15);
        $this->onCleanupRead = function () {
            $task = $this->task(); $key = 'machine_addresses:' . $task->machine_id . ':' . $task->machine_authority;
            $rows = Cache::get($key); $rows[$task->installation_id]['runtime']['observation_session'] = str_repeat('f', 32);
            Cache::put($key, $rows, 300);
        };
        $run = $this->candidateSample($run, time() - 10);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_foreign_account_resource_binding_prevents_delete(): void
    {
        $run = $this->retryFixture(); $task = $this->task();
        \App\Models\MachineCloudResource::query()->create(['account_id' => $this->fixtures[0]['accounts'][1]->id,
            'provider' => 'linode', 'external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'label' => 'foreign-resource', 'status' => 'running', 'public_ips' => $task->public_ips, 'observed_at' => time()]);
        $run = $this->candidateSample($run, time() - 15);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_dns_drift_during_cooldown_prevents_reservation(): void
    {
        $run = $this->cleanupConfirmed($this->cleanupStarted()); $this->finishCandidateCooldown();
        $this->fixtures[0]['dns']->fill(['marker' => 'foreign-marker'])->save(); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
        $this->assertSame('dns_ownership_changed', $run['members'][0]['reason']);
    }

    public function test_candidate_retry_runner_keeps_sampling_ahead_of_queued_members(): void
    {
        $this->fixtures[] = $this->fixture(2); $this->fixtures[] = $this->fixture(3);
        $run = $this->retryFixture(); $run = $this->candidateSample($run, time() - 10);
        $this->runtimeSchema(); config(['machine_failover.runner_enabled' => true]); $this->runner()->enroll($run['id']);
        \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['last_member_id' => $run['members'][0]['id']]);
        $members = Member::query()->where('execution_id', $run['id'])->get();
        $this->assertSame($run['members'][0]['id'], $this->runner()->order($run['id'], $members)->first()->id);
        $before = time(); $run = $this->tick($run);
        $next = $this->runner()->view(Execution::findOrFail($run['id']))['next_check_at'];
        $this->assertGreaterThanOrEqual($before + 10, $next); $this->assertLessThanOrEqual(time() + 10, $next);
        $this->assertSame('checking_ipv4', $run['members'][0]['phase']);
        $this->assertSame(0, $this->candidateDeletes); $this->assertCount(1, $this->posts);
    }

    public function test_candidate_retry_runner_round_robins_sampling_and_dns_without_raising_parallel_limit(): void
    {
        $this->fixtures[] = $this->fixture(2); $this->fixtures[] = $this->fixture(3);
        $run = $this->retryFixture(3, 2); $this->runtimeSchema();
        config(['machine_failover.runner_enabled' => true]); $this->runner()->enroll($run['id']);
        $members = Member::query()->where('execution_id', $run['id'])->get();
        $members[0]->fill(['phase' => 'checking_ipv4'])->save(); $members[1]->fill(['phase' => 'awaiting_dns'])->save();
        foreach ([[0, 1], [1, 0]] as [$last, $next]) {
            \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['last_member_id' => $members[$last]->id]);
            $this->assertSame($members[$next]->id, $this->runner()->order($run['id'], $members)->first()->id);
        }
        $this->assertCount(2, $this->posts); $this->assertSame(0, $this->candidateDeletes);
        $this->assertSame('ready', $members[2]->phase);
    }
}
