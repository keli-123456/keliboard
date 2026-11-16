<?php
namespace Tests\Support;

use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Keli\Acceptance\MemberRetryDriver;

trait TestsMemberRetryDriver
{
    private array $memberDriverSaved = [];
    private array $memberDriverCalls = [];
    private array $memberDriverScope = [];
    private $memberDriverPort;
    private $memberDriverSave;
    private $memberDriverGuard;
    private bool $memberDriverCleanupFails = false;
    private bool $memberDriverSaveFails = false;

    private function memberDriverFixture(int $sourceAge = 600): MemberRetryDriver
    {
        require_once base_path('tools/machine-acceptance/member_retry_driver.php');
        app()->instance('env', 'testing');
        $f = $this->memberRetryAcceptanceFixture($sourceAge, aliSets: true, start: false);
        // Installation is synthetic. Candidate provisioning, retry and DNS services are real
        // application code; their provider transports are the existing deny-stray HTTP fake.
        foreach ($this->fixtures as $fixture) {
            $task = $fixture['source']->fresh(); $payload = $task->payload;
            $marker = 'keli-create-' . substr(hash('sha256', $task->id), 0, 24);
            $payload['request'] = ['label' => 'keli-' . str_replace('-', '', $task->id), 'tags' => [$marker]];
            $task->fill(['marker' => $marker, 'payload' => $payload, 'bootstrap_state' => 'succeeded'])->save();
        }
        $this->memberDriverScope = ['run_id' => 'keli-accept-offline-r35-fixture', 'workflow' => 'agent_readiness_v1',
            'admin_id' => $this->admin->id, 'account_id' => $f['account']->id, 'service_id' => $f['service']['id'],
            'service_revision' => $f['service']['revision'], 'release_id' => $this->release->id, 'plan_id' => $f['plan'],
            'policy_a' => $f['a']['policy']->id, 'policy_b' => $f['b']['policy']->id, 'baseline_ids' => ['700']];
        $this->memberDriverGuard = function (string $operation): void {
            $this->assertSame('sqlite', app('db')->connection()->getDriverName());
            $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        };
        $this->memberDriverSave = function (array $state): void {
            if ($this->memberDriverSaveFails) throw new \RuntimeException('private path must never be exported');
            $this->memberDriverSaved = $state;
        };
        $this->memberDriverPort = function (string $operation, array $input) use ($f): array {
            $this->memberDriverCalls[] = [$operation, $input];
            if ($operation === 'prepare_role') return match ($input['role']) {
                'control' => ['external_id' => '800', 'ready' => true],
                'source-a' => ['task_id' => $f['a']['source']->id],
                'source-b' => ['task_id' => $f['b']['source']->id],
            };
            if ($operation === 'control_facts') return ['resource' => $this->remote[902] ?? null, 'dns' => $this->records['5002']];
            if ($operation === 'cleanup') {
                if ($this->memberDriverCleanupFails) throw new \RuntimeException('secret cleanup failure');
                // Separate final teardown fake, never used to qualify A1's native retry proof.
                foreach (['candidate-a2', 'candidate-a1', 'source-a', 'source-b', 'control'] as $role)
                    if (isset($input['roles'][$role]['external_id'])) unset($this->remote[(int) $input['roles'][$role]['external_id']]);
                return ['all_absent' => true, 'faults_restored' => true, 'private_materials_removed' => true,
                    'intents_reconciled' => true, 'inspected_ids' => array_values(array_column($input['roles'], 'external_id'))];
            }
            if (in_array($operation, ['observe_sources', 'observe_candidates'], true)) return [];
            throw new \LogicException('Unexpected offline port operation');
        };
        return $this->memberDriver();
    }

    private function memberDriver(?array $restored = null): MemberRetryDriver
    {
        return new MemberRetryDriver($this->memberDriverScope, $this->memberDriverPort, $this->memberDriverSave,
            $this->memberDriverGuard, fn () => $this->retryClock->at, $restored);
    }

    private function memberDriverCandidate(): MemberRetryDriver
    {
        $driver = $this->memberDriverFixture();
        foreach (['source-a', 'source-b', 'setup_cooldown', 'native', 'waiting_initialization'] as $expected) {
            $state = $driver->step();
            $this->assertSame($expected, $state['phase'], json_encode($state));
        }
        return $driver;
    }

    private function memberDriverFailCandidate(MemberRetryDriver $driver): void
    {
        $task = $this->task();
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 30, 'status' => 'waiting_ipv4_probe', 'next_check_at' => time() + 60])->save();
        foreach ([-20, -15, -10] as $index => $delta) {
            $this->report($this->fixtures[0], $task, $task->public_ips[0], false, time() + $delta);
            $state = $driver->step();
            $this->assertNull($state['first_error'], json_encode($state));
            $this->assertSame($index === 2 ? 1 : 0, $this->candidateDeletes);
        }
        $this->assertSame('confirming_candidate_cleanup', $state['phase']);
    }

    private function memberDriverConfirm(MemberRetryDriver $driver): void
    {
        $driver->step();
        $this->assertCount(1, Attempt::first()->payload['candidate_retry']['absent_at']);
        $driver->step();
        $this->assertCount(1, Attempt::first()->payload['candidate_retry']['absent_at']);
        $this->retryClock->at += 10;
        $this->assertSame('candidate_retry_cooldown', $driver->step()['phase']);
    }

    private function memberDriverFinishCooldown(MemberRetryDriver $driver): void
    {
        $retryAt = Attempt::first()->payload['candidate_retry']['retry_at'];
        $this->retryClock->at = $retryAt - 1;
        $state = $driver->step();
        $this->assertSame('candidate_retry_cooldown', $state['phase']);
        $this->assertCount(1, $this->posts);
        // Only the synthetic fixture advances wall-clock-dependent source/cache ages.
        // No history is deleted and every task remains inside the 24-hour fuse window.
        $this->finishCandidateCooldown();
        $this->retryClock->at = $retryAt;
        $state = $driver->step();
        $this->assertSame('waiting_initialization', $state['phase'], json_encode($state));
        $this->assertCount(2, $this->posts);
    }

    public function test_member_driver_composes_native_cleanup_cooldown_retry_and_aliyun_b_isolation(): void
    {
        $driver = $this->memberDriverCandidate();
        $b = $this->fixtures[1]['source']->fresh()->toArray(); $bDns = $this->records['5002'];
        $first = $this->task(); $canonical = $first->payload['request'];
        $this->memberDriverFailCandidate($driver);
        $this->memberDriverConfirm($driver);
        $driver = $this->memberDriver($this->memberDriverSaved);
        $this->memberDriverFinishCooldown($driver);
        $this->assertSame($canonical, $first->fresh()->payload['request']);
        $task = $this->task();
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        for ($i = 0; $i < 45 && $driver->state()['phase'] !== 'cleanup'; $i++) $driver->step();
        $state = $driver->state();
        $this->assertSame('cleanup', $state['phase'], json_encode($state));
        $this->assertNull($state['first_error'], json_encode($state));
        foreach (['native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'] as $key) $this->assertTrue($state['branches'][$key]);
        $this->assertSame($b, $this->fixtures[1]['source']->fresh()->toArray());
        $this->assertSame($bDns, $this->records['5002']);
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $this->verifications);
        $this->assertSame(1, $this->candidateDeletes); $this->assertCount(2, $this->posts);
        $this->assertSame(4, Task::count()); $this->assertSame(2, Attempt::count());
        $this->assertSame('cleanup', $driver->step()['phase']);
        $this->assertSame('cleanup', $driver->step()['phase']);
        $this->retryClock->at += 10;
        $this->assertSame('closed', $driver->step()['phase']);
        $this->assertTrue($driver->state()['branches']['final_cleanup']);
        $this->assertSame([700], array_keys($this->remote));
        $calls = count($this->memberDriverCalls); $driver->step();
        $this->assertSame($calls, count($this->memberDriverCalls));
    }

    public function test_member_driver_unknown_native_cleanup_read_does_not_release_or_retry(): void
    {
        $driver = $this->memberDriverCandidate(); $this->memberDriverFailCandidate($driver);
        $driver->step();
        $this->duringInstanceRead = fn () => throw new \Illuminate\Http\Client\ConnectionException('synthetic timeout');
        $this->retryClock->at += 10; $state = $driver->step();
        $this->assertSame('confirming_candidate_cleanup', $state['phase'], json_encode($state));
        $this->assertSame([], Attempt::first()->payload['candidate_retry']['absent_at']);
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->candidateDeletes);
        $this->assertFalse($state['branches']['native_cleanup']);
        $this->duringInstanceRead = null;
        $this->retryClock->at = Attempt::first()->payload['candidate_retry']['next_check_at'];
        $this->memberDriverConfirm($driver);
        $this->memberDriverFinishCooldown($driver);
    }

    public function test_member_driver_rejects_noncanonical_tag_and_keeps_first_error_during_cleanup_failure(): void
    {
        $driver = $this->memberDriverCandidate();
        $task = $this->task(); $payload = $task->payload; $payload['request']['tags'][] = 'acceptance-role-a1';
        $task->fill(['payload' => $payload])->save();
        $state = $driver->step();
        $this->assertSame('member_task_binding_changed', $state['first_error']);
        $this->memberDriverCleanupFails = true;
        $state = $driver->step();
        $this->assertSame('member_task_binding_changed', $state['first_error']);
        $this->assertSame('member_operation_failed', $state['cleanup_error']);
        $this->assertSame('cleanup', $state['phase']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->candidateDeletes);
        $this->assertStringNotContainsString('secret', json_encode($state));
    }

    public function test_member_driver_b_change_stops_before_another_native_action(): void
    {
        $driver = $this->memberDriverCandidate();
        $this->records['5002']['Value'] = '9.9.9.12';
        $state = $driver->step();
        $this->assertSame('member_b_changed', $state['first_error']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_driver_stop_during_cooldown_cannot_create_a2(): void
    {
        $driver = $this->memberDriverCandidate(); $this->memberDriverFailCandidate($driver); $this->memberDriverConfirm($driver);
        $this->retryClock->at += 301;
        $state = $driver->step(stop: true);
        $this->assertSame('member_stop_requested', $state['first_error']);
        $this->assertSame('cleanup', $state['phase']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_driver_journal_failure_prevents_setup_dispatch_and_ambiguous_resume_is_not_replayed(): void
    {
        $driver = $this->memberDriverFixture(); $this->memberDriverSaveFails = true;
        $state = $driver->step();
        $this->assertSame('member_operation_failed', $state['first_error']);
        $this->assertSame([], $this->memberDriverCalls);
        $this->memberDriverSaveFails = false;
        $state['phase'] = 'control'; $state['first_error'] = null;
        $driver = $this->memberDriver($state); $state = $driver->step();
        $this->assertSame('member_operation_unresolved', $state['first_error']);
        $this->assertSame([], $this->memberDriverCalls);
    }

    public function test_member_driver_waits_for_recent_source_setup_without_starting_execution(): void
    {
        $driver = $this->memberDriverFixture(sourceAge: 120);
        for ($i = 0; $i < 5; $i++) $state = $driver->step();
        $this->assertSame('setup_cooldown', $state['phase'], json_encode($state));
        $this->assertSame(0, \App\Models\MachineFailoverExecution::count());
        $this->assertCount(0, $this->posts);
    }

    public function test_member_driver_corrupt_cleanup_seal_cannot_release_capacity_or_create_a2(): void
    {
        $driver = $this->memberDriverCandidate(); $this->memberDriverFailCandidate($driver); $this->memberDriverConfirm($driver);
        $attempt = Attempt::first(); $payload = $attempt->payload;
        $payload['candidate_retry']['seal'] = str_repeat('0', 64); $attempt->fill(['payload' => $payload])->save();
        $this->retryClock->at += 301;
        $state = $driver->step();
        $this->assertSame('cleanup', $state['phase']);
        $this->assertSame('candidate_cleanup_unconfirmed', $state['first_diagnostic']['native_reason']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_driver_a1_healthy_cannot_skip_retry_and_publish_dns(): void
    {
        $driver = $this->memberDriverCandidate(); $task = $this->task();
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        for ($i = 0; $i < 12 && $driver->state()['phase'] !== 'cleanup'; $i++) $driver->step();
        $this->assertSame('cleanup', $driver->state()['phase']);
        $this->assertSame('member_first_candidate_dns_forbidden', $driver->state()['native_stop']['reason']);
        $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts);
    }

    public function test_member_driver_source_recovery_after_a1_cleanup_prevents_a2(): void
    {
        $driver = $this->memberDriverCandidate(); $this->memberDriverFailCandidate($driver); $this->memberDriverConfirm($driver);
        $this->finishCandidateCooldown();
        $this->sourceReport($this->fixtures[0], true, time());
        $state = $driver->step();
        $this->assertSame('cleanup', $state['phase']);
        $this->assertSame('source_recovered', $state['native_stop']['reason']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_driver_limit_change_and_expiry_fail_before_setup(): void
    {
        $driver = $this->memberDriverFixture();
        config(['machine_provision.daily_limit_per_account' => 2]);
        $this->assertSame('member_limits_changed', $driver->step()['first_error']);
        $this->assertSame([], $this->memberDriverCalls);
        config(['machine_provision.daily_limit_per_account' => 5]);
        $driver = $this->memberDriver(); $this->retryClock->at += 6000;
        $this->assertSame('member_deadline', $driver->step()['first_error']);
        $this->assertCount(0, $this->posts);
        $this->assertSame(0, \App\Models\MachineFailoverExecution::count());
    }

    public function test_member_driver_failed_task_alone_is_not_cleanup_confirmation(): void
    {
        $driver = $this->memberDriverCandidate();
        $this->task()->fill(['status' => 'failed', 'reason' => 'candidate_ipv4_unreachable'])->save();
        $state = $driver->step();
        $this->assertSame('cleanup', $state['phase']);
        $this->assertFalse($state['branches']['native_cleanup']);
        $this->assertFalse($state['branches']['retry']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_driver_retains_closed_native_execution_gate(): void
    {
        $driver = $this->memberDriverFixture();
        for ($i = 0; $i < 3; $i++) $driver->step();
        app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate::class,
            new \App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate());
        $state = $driver->step();
        $this->assertSame('orchestration_disabled', $state['first_diagnostic']['native_reason']);
        $this->assertSame(0, \App\Models\MachineFailoverExecution::count());
        $this->assertCount(0, $this->posts);
    }

    public function test_member_driver_incomplete_cleanup_receipt_cannot_close_or_hide_first_failure(): void
    {
        $driver = $this->memberDriverFixture(); $driver->step();
        $original = $this->memberDriverPort;
        $this->memberDriverPort = function ($op, $args) use ($original) {
            $result = $original($op, $args);
            if ($op === 'cleanup') $result['intents_reconciled'] = false;
            return $result;
        };
        $driver = $this->memberDriver($this->memberDriverSaved);
        $driver->step(stop: true); $this->retryClock->at += 10; $state = $driver->step();
        $this->assertSame('cleanup', $state['phase']);
        $this->assertFalse($state['branches']['final_cleanup']);
        $this->assertSame([], $state['cleanup_reads']);
        $this->assertSame('member_stop_requested', $state['first_error']);
    }

    public function test_member_driver_is_offline_only_even_with_injected_port(): void
    {
        $this->memberDriverFixture(); app()->instance('env', 'production');
        $this->expectExceptionMessage('member_offline_environment_required');
        $this->memberDriver();
    }

    public function test_member_driver_clock_rollback_still_restores_and_cleans_but_cannot_confirm_absence(): void
    {
        $driver = $this->memberDriverFixture(); $driver->step();
        $this->retryClock->at -= 1;
        $state = $driver->step();
        $this->assertSame('member_clock_invalid', $state['first_error']);
        $this->assertSame('cleanup', end($this->memberDriverCalls)[0]);
        $this->assertSame([], $state['cleanup_reads']);
        $this->assertFalse($state['branches']['final_cleanup']);
        $this->assertArrayNotHasKey(800, $this->remote);
        $this->assertCount(0, $this->posts);
    }
}
