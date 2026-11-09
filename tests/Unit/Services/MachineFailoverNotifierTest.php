<?php
namespace Tests\Unit\Services;

use App\Jobs\SendMachineOperationNoticeJob;
use App\Models\MachineAcceptanceCheck;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverBudgetRelease as Release;
use App\Models\MachineFailoverCostSnapshot as Snapshot;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverReviewAction as Action;
use App\Models\MachineFailoverService as Service;
use App\Models\MachineOperationNotice as Notice;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\MachineFailoverNoticeSource;
use App\Services\ServerMachine\MachineOperationNoticeText;
use App\Services\ServerMachine\MachineOperationNotifier;
use App\Services\TelegramService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverNotifierTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private User $admin;
    private Policy $policy;
    private Service $service;
    private Account $account;
    private ServerMachine $machine;
    private MachineOperationNotifier $notifier;
    private MachineFailoverNoticeSource $source;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('f', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_010000_add_machine_cloud_providers.php', '2026_11_06_020000_create_machine_cloud_groups.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_040000_create_machine_failover_observations.php',
            '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php', '2026_11_07_090000_create_machine_replacement_tasks.php',
            '2026_11_07_120000_add_provider_groups_to_machine_failover.php', '2026_11_07_140000_create_machine_failover_services.php',
            '2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_07_190000_create_machine_failover_finance.php',
            '2026_11_07_200000_create_machine_failover_review.php', '2026_11_07_210000_create_machine_failover_notice_state.php'] as $file)
            (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'PRIVATE_MACHINE', 'token' => 'PRIVATE_MACHINE_TOKEN', 'is_active' => true]);
        $this->account = Account::query()->create(['name' => 'PRIVATE_ACCOUNT', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'PRIVATE_CLOUD_TOKEN']]);
        $this->policy = Policy::query()->create(['name' => 'PRIVATE_POLICY', 'revision' => 1, 'slot' => 'isolated', 'machine_id' => $this->machine->id,
            'machine_authority' => hash('sha256', $this->machine->token), 'account_id' => $this->account->id, 'account_revision' => 1,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-1', 'image' => 'linode/debian12', 'failure_threshold' => 2,
            'stale_after_seconds' => 300, 'cooldown_seconds' => 300, 'monthly_limit_usd' => 100, 'daily_limit' => 2, 'updated_by' => $this->admin->id]);
        $this->service = Service::query()->create(['name' => 'PRIVATE_SERVICE', 'revision' => 1, 'max_parallel' => 1, 'attempt_limit' => 4, 'monthly_cap_usd' => 300, 'updated_by' => $this->admin->id]);
        $this->notifier = new class extends MachineOperationNotifier { public bool $ready = true; protected function channelReady(): bool { return $this->ready; } };
        $this->source = new class extends MachineFailoverNoticeSource {
            public bool $healthy = false;
            protected function sourceHealthy(Member $member, Policy $policy): bool { return $this->healthy; }
        };
        app()->instance(MachineOperationNotifier::class, $this->notifier); app()->instance(MachineFailoverNoticeSource::class, $this->source);
    }
    protected function tearDown(): void { Http::assertNothingSent(); parent::tearDown(); }
    private function enable(bool $enabled = true): void
    {
        $this->notifier->save('failover', $enabled, $this->notifier->overview('failover')['policy']['revision'], $this->admin->id);
    }
    private function member(string $phase = 'ready', ?string $reason = null): Member
    {
        $run = Execution::query()->create(['id' => (string) Str::uuid(), 'service_id' => $this->service->id, 'service_revision' => 1,
            'admin_id' => $this->admin->id, 'request_key' => bin2hex(random_bytes(32)), 'context_hash' => str_repeat('a', 64), 'intent_hash' => str_repeat('b', 64),
            'payload' => ['release' => ['id' => 1, 'version' => 'synthetic', 'binary_sha256' => str_repeat('b', 64)]], 'status' => 'running', 'deadline_at' => time() + 1200]);
        return Member::query()->create(['execution_id' => $run->id, 'member_id' => 11, 'policy_id' => $this->policy->id, 'policy_revision' => 1,
            'plan_id' => 1, 'episode_key' => bin2hex(random_bytes(32)), 'payload' => [], 'phase' => $phase, 'reason' => $reason]);
    }
    private function task(Member $member, string $status = 'needs_review', ?string $external = '123'): array
    {
        $attempt = Attempt::query()->create(['id' => (string) Str::uuid(), 'execution_member_id' => $member->id, 'ordinal' => 1,
            'request_id' => bin2hex(random_bytes(16)), 'account_id' => $this->account->id, 'account_revision' => 1, 'reserved_cents' => 10000, 'payload' => []]);
        $id = (string) Str::uuid(); $task = Task::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id,
            'account_id' => $this->account->id, 'account_revision' => 1, 'machine_id' => $this->machine->id, 'machine_authority' => hash('sha256', $this->machine->token),
            'plan_hash' => str_repeat('c', 64), 'slot' => 'isolated', 'installation_id' => bin2hex(random_bytes(16)), 'callback_hash' => str_repeat('d', 64),
            'marker' => 'synthetic-' . $id, 'payload' => ['coordinated_attempt' => $attempt->id, 'request' => []], 'status' => $status,
            'external_id' => $external, 'provider_created_at' => '2026-11-01T00:00:00', 'public_ips' => ['8.8.4.4'], 'dispatched_at' => time() - 7200,
            'deadline_at' => time() + 600, 'updated_at' => time() - 7200]);
        $attempt->task_id = $id; $attempt->save(); $member->attempt_id = $attempt->id; $member->save(); return [$attempt, $task];
    }
    private function capture(Member $member): ?Notice { $this->notifier->failover($member->id); return Notice::query()->latest('id')->first(); }
    private function transport(int $times = 1, bool $fails = false): void
    {
        $mock = \Mockery::mock(TelegramService::class); $call = $mock->shouldReceive('sendMessageWithActiveAdmins')->times($times);
        if ($fails) $call->andThrow(new \RuntimeException('PRIVATE_TRANSPORT')); else $call->andReturnNull();
        app()->instance(TelegramService::class, $mock);
    }
    private function review(Member $member, string $operation = 'close', string $resolution = 'retained'): void
    {
        Action::query()->create(['member_id' => $member->id, 'attempt_id' => $member->attempt_id, 'admin_id' => $this->admin->id,
            'operation' => $operation, 'request_key' => bin2hex(random_bytes(32)), 'payload' => ['resolution' => $resolution]]);
    }
    private function receipt(Member $member, bool $dns = false): array
    {
        [$attempt, $task] = $this->task($member, $dns ? 'accepted' : 'awaiting_acceptance');
        $instance = MachineOwnedInstance::query()->create(['policy_id' => $member->policy_id, 'policy_revision' => 1, 'task_id' => $task->id,
            'installation_id' => $task->installation_id, 'release_id' => 1, 'version' => 'synthetic', 'binary_sha256' => str_repeat('b', 64),
            'ip' => '8.8.4.4', 'status' => $dns ? 'active' : 'verified', 'active_policy_id' => $dns ? $member->policy_id : null,
            'verified_until' => time() + 600, 'verification' => ['receipt_sha256' => str_repeat('e', 64), 'cleanup_complete' => true,
                'context' => ['configuration_fingerprint' => str_repeat('f', 64)]]]);
        $check = MachineAcceptanceCheck::query()->create(['id' => bin2hex(random_bytes(16)), 'instance_id' => $instance->id, 'policy_revision' => 1,
            'request_sha256' => str_repeat('a', 64), 'state' => 'passed', 'receipt_sha256' => str_repeat('e', 64), 'issued_at' => time() - 20, 'deadline_at' => time() + 60]);
        $hash = fn ($value) => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
        $proof = ['context' => $hash([$member->execution_id, $member->id, $attempt->id, $task->id, $task->account_id, $task->account_revision,
            $task->installation_id, $task->marker, $task->external_id, $task->provider_created_at, $instance->id, $instance->ip, $instance->binary_sha256]),
            'configuration' => str_repeat('f', 64), 'check_id' => $check->id, 'receipt' => $check->receipt_sha256];
        $payload = ['forwarding_receipt' => $hash($proof)];
        if ($dns) {
            $record = MachineOwnedDns::query()->create(['policy_id' => $member->policy_id, 'credential_id' => 1, 'credential_revision' => 1,
                'zone' => str_repeat('a', 32), 'hostname' => 'synthetic.example.test', 'record_id' => str_repeat('c', 32), 'marker' => 'isolated',
                'active_instance_id' => $instance->id, 'ip' => $instance->ip, 'status' => 'live']);
            $member->payload = ['source_instance_id' => 99, 'dns' => ['id' => $record->id, 'revision' => 1, 'record_id' => $record->record_id, 'ip' => '8.8.8.8']]; $member->save();
            $transition = MachineDnsTransition::query()->create(['id' => (string) Str::uuid(), 'request_key' => hash('sha256', $this->admin->id . ':' . substr($hash([$attempt->id, 'dns']), 0, 32)),
                'dns_id' => $record->id, 'dns_revision' => 1, 'policy_revision' => 1, 'admin_id' => $this->admin->id, 'from_instance_id' => 99, 'to_instance_id' => $instance->id,
                'from_ip' => '8.8.8.8', 'to_ip' => $instance->ip, 'state' => 'verified', 'dispatched_at' => time() - 10, 'deadline_at' => time() + 60]);
            $proof['dns'] = $transition->id; $payload += ['transition_id' => $transition->id, 'dns_receipt' => $hash($proof)];
            $task->evidence = ['dns_transition_id' => $transition->id]; $task->save();
        }
        $attempt->payload = $payload; $attempt->save(); return [$attempt, $task, $instance, $check];
    }

    public function test_scope_defaults_off_and_baselines_old_records_without_enabling_execution(): void
    {
        $member = $this->member('needs_review', 'internal_failure'); $this->review($member);
        $this->assertFalse($this->notifier->overview('failover')['policy']['enabled']); $this->capture($member);
        $this->enable(); $this->capture($member); $this->notifier->advance();
        $this->assertSame(0, Notice::query()->count()); Bus::assertNothingDispatched();
        try { app(MachineFailoverExecutionGate::class)->assertAllowed('create'); $this->fail('Gate opened'); }
        catch (CloudException $e) { $this->assertSame('orchestration_disabled', $e->getMessage()); }
        $member->reason = 'shared_monthly_budget'; $member->save();
        $this->assertSame('budget', $this->capture($member)->phase);
    }
    public function test_repeat_capture_and_duplicate_delivery_submit_once_even_after_old_outbox_is_pruned(): void
    {
        $this->enable(); $member = $this->member('needs_review', 'internal_failure'); $notice = $this->capture($member);
        $this->capture($member); $this->assertSame(1, Notice::query()->count()); Bus::assertDispatchedTimes(SendMachineOperationNoticeJob::class, 1);
        $this->transport(); $this->notifier->deliver($notice->id); $this->notifier->deliver($notice->id);
        $this->assertSame('submitted', $notice->fresh()->status);
        $notice->forceFill(['created_at' => time() - 86400 * 31])->save(); $this->notifier->advance();
        $this->assertSame(0, Notice::query()->count()); $this->capture($member); $this->assertSame(0, Notice::query()->count());
    }
    public function test_running_timeout_is_observed_without_advancing_or_restarting_any_task(): void
    {
        $member = $this->member('creating'); $this->enable();
        Execution::query()->whereKey($member->execution_id)->update(['deadline_at' => time() - 1]);
        $notice = $this->capture($member); $this->assertSame('timeout', $notice->phase);
        $this->assertSame('running', Execution::query()->find($member->execution_id)->status); $this->assertSame('creating', $member->fresh()->phase);
        $this->transport(); $this->notifier->deliver($notice->id); $this->assertSame('submitted', $notice->fresh()->status);
    }
    public function test_progress_is_coalesced_but_critical_budget_and_review_are_not_delayed(): void
    {
        $this->enable(); $member = $this->member(); $this->capture($member);
        $member->phase = 'creating'; $member->save(); $this->capture($member); $this->assertSame(1, Notice::query()->count());
        DB::table('v2_machine_failover_notice_state')->update(['progress_at' => time() - 601]);
        $this->assertSame('progress', $this->capture($member)->phase); $this->assertSame(2, Notice::query()->count());
        $member->fill(['phase' => 'needs_review', 'reason' => 'attempt_budget'])->save(); $this->assertSame('budget', $this->capture($member)->phase);
        $member->reason = 'PRIVATE_ERROR_TOKEN'; $member->save(); $notice = $this->capture($member);
        $this->assertSame('review', $notice->phase); $this->assertSame('unknown', $notice->snapshot['reason']);
    }
    public function test_policy_disable_context_change_or_banned_admin_suppresses_pending_notice(): void
    {
        $this->enable(); $member = $this->member('needs_review'); $notice = $this->capture($member);
        $this->policy->revision = 2; $this->policy->save(); $this->notifier->deliver($notice->id); $this->assertSame('source_changed', $notice->fresh()->delivery_reason);
        $member->reason = 'attempt_budget'; $member->save(); $next = $this->capture($member);
        $this->admin->banned = true; $this->admin->save(); $this->notifier->deliver($next->id); $this->assertSame('policy_changed', $next->fresh()->delivery_reason);
        $this->admin->banned = false; $this->admin->save(); $this->enable(false); $this->enable();
        $this->capture($member); $this->assertSame(2, Notice::query()->count());
    }
    public function test_review_closure_preserves_resource_warning_and_never_claims_business_recovered(): void
    {
        $this->enable(); $member = $this->member('retained'); [$attempt, $task] = $this->task($member);
        $this->capture($member); $this->review($member); $notice = $this->capture($member);
        $this->assertSame('review_closed', $notice->phase); $this->assertSame('retained', $notice->snapshot['resolution']);
        $text = MachineOperationNoticeText::message($notice); $this->assertStringContainsString('资源仍保留', $text); $this->assertStringContainsString('不代表业务恢复', $text);
        $risk = Notice::query()->where('phase', 'retained_risk')->firstOrFail();
        $this->assertSame(1, $risk->snapshot['retained_count']);
        $this->assertSame('retained', $member->fresh()->phase); $this->assertSame('needs_review', $task->fresh()->status);
        $this->capture($member); $this->assertSame(1, Notice::query()->where('phase', 'retained_risk')->count());
        DB::table('v2_machine_failover_notice_state')->update(['risk_at' => time() - 86401]);
        $this->capture($member); $this->assertSame(2, Notice::query()->where('phase', 'retained_risk')->count());
    }
    public function test_retained_old_source_is_counted_after_success_and_not_mistaken_for_current_active_candidate(): void
    {
        $this->enable(); $member = $this->member('completed'); [$attempt, $task, $instance] = $this->receipt($member, true);
        $source = MachineOwnedInstance::query()->create(['policy_id' => $member->policy_id, 'policy_revision' => 1, 'task_id' => (string) Str::uuid(),
            'installation_id' => bin2hex(random_bytes(16)), 'release_id' => 1, 'version' => 'old', 'binary_sha256' => str_repeat('a', 64), 'ip' => '8.8.8.8',
            'status' => 'retained', 'updated_at' => time() - 7200]);
        $old = $task->replicate(); $old->id = $source->task_id; $old->installation_id = $source->installation_id;
        $old->request_key = bin2hex(random_bytes(32)); $old->marker = 'source-' . $source->id; $old->external_id = '456'; $old->save();
        $payload = $member->payload; $payload['source_instance_id'] = $source->id; $member->payload = $payload; $member->save();
        MachineDnsTransition::query()->update(['from_instance_id' => $source->id]);
        $this->capture($member); $risk = Notice::query()->where('phase', 'retained_risk')->firstOrFail();
        $this->assertSame(1, Notice::query()->where('phase', 'completed')->count());
        $this->assertSame(1, $risk->snapshot['retained_count']); $this->assertStringContainsString('本地记录不能证明当前账单', MachineOperationNoticeText::message($risk));
    }
    public function test_source_probe_recovery_requires_fresh_matching_probe_and_does_not_imply_forwarding(): void
    {
        $this->enable(); $member = $this->member('cancelled', 'source_recovered');
        $this->assertSame('cancelled', $this->capture($member)->phase);
        $this->source->healthy = true; $notice = $this->capture($member); $this->assertSame('probe_recovered', $notice->phase);
        $this->assertStringContainsString('不代表客户端转发恢复', MachineOperationNoticeText::message($notice));
        $this->source->healthy = false; $this->notifier->deliver($notice->id); $this->assertSame('source_changed', $notice->fresh()->delivery_reason);
    }
    public function test_forwarding_and_dns_require_pinned_receipts_not_presence_flags(): void
    {
        $this->enable(); $member = $this->member('awaiting_dns'); [$attempt, $task, $instance] = $this->receipt($member);
        $notice = $this->capture($member); $this->assertSame('forwarding_verified', $notice->phase);
        $this->assertStringContainsString('历史隔离 TCP/UDP 回执', MachineOperationNoticeText::message($notice));
        $instance->verified_until = time() - 1; $instance->save(); $this->notifier->deliver($notice->id);
        $this->assertSame('source_changed', $notice->fresh()->delivery_reason); $this->assertSame('review', $this->capture($member)->phase);
        $done = $this->member('completed'); [$a, $t, $i] = $this->receipt($done, true);
        $completion = $this->capture($done); $this->assertSame('completed', $completion->phase);
        $this->assertStringContainsString('不代表全球 DNS 传播或业务恢复', MachineOperationNoticeText::message($completion));
        $payload = $a->payload; $payload['dns_receipt'] = str_repeat('0', 64); $a->payload = $payload; $a->save();
        $this->notifier->deliver($completion->id); $this->assertSame('source_changed', $completion->fresh()->delivery_reason);
        $this->assertSame('review', $this->capture($done)->phase);
    }
    public function test_unknown_transport_and_missing_channel_do_not_blindly_replay(): void
    {
        $this->enable(); $member = $this->member('needs_review'); $notice = $this->capture($member);
        $this->transport(1, true); $this->notifier->deliver($notice->id); $this->notifier->deliver($notice->id);
        $this->assertSame('unknown', $notice->fresh()->status); $this->notifier->ready = false;
        $member->reason = 'attempt_budget'; $member->save(); $panel = $this->capture($member); $this->assertSame('panel_only', $panel->status);
        $this->notifier->ready = true; $this->capture($member); $this->notifier->deliver($panel->id); $this->assertSame('panel_only', $panel->fresh()->status);
        $output = json_encode($this->notifier->overview('failover')) . MachineOperationNoticeText::message($notice);
        foreach (['PRIVATE_MACHINE', 'PRIVATE_CLOUD_TOKEN', 'PRIVATE_POLICY', 'PRIVATE_SERVICE', 'PRIVATE_TRANSPORT', 'context_hash', 'payload'] as $private) $this->assertStringNotContainsString($private, $output);
    }
    public function test_verified_release_stops_risk_but_positive_cost_readback_can_reopen_the_warning(): void
    {
        $this->enable(); $member = $this->member('needs_review'); [$attempt, $task] = $this->task($member);
        Release::query()->create(['id' => (string) Str::uuid(), 'attempt_id' => $attempt->id, 'service_id' => $this->service->id,
            'admin_id' => $this->admin->id, 'request_key' => bin2hex(random_bytes(32)), 'amount_cents' => 10000,
            'proof_kind' => 'confirmed_absent', 'proof_hash' => str_repeat('a', 64), 'payload' => []]);
        $this->capture($member); $this->assertSame(0, Notice::query()->where('phase', 'retained_risk')->count());
        $identity = app(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class)->taskIdentity($task);
        Snapshot::query()->create(['id' => (string) Str::uuid(), 'service_id' => $this->service->id, 'service_revision' => 1,
            'admin_id' => $this->admin->id, 'context_hash' => str_repeat('b', 64), 'checked_at' => time(), 'valid_until' => time() + 300,
            'payload' => ['state' => 'complete', 'items' => [$task->id => ['state' => 'present', 'identity' => $identity, 'monthly_cents' => 4800]]]]);
        $this->notifier->failoverCosts($this->service->id); $this->assertContains($member->id, $this->source->due());
        $this->capture($member); $risk = Notice::query()->where('phase', 'retained_risk')->firstOrFail();
        $this->assertSame(1, $risk->snapshot['retained_count']); $this->assertSame(1, Release::query()->count());
        $this->assertArrayNotHasKey('monthly_cents', $risk->snapshot);
    }
    public function test_recent_confirmed_absence_suppresses_risk_but_unknown_or_mismatched_absence_does_not(): void
    {
        $this->enable(); $member = $this->member('retained'); [$attempt, $task] = $this->task($member);
        $identity = app(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class)->taskIdentity($task);
        $snapshot = Snapshot::query()->create(['id' => (string) Str::uuid(), 'service_id' => $this->service->id, 'service_revision' => 1,
            'admin_id' => $this->admin->id, 'context_hash' => str_repeat('b', 64), 'checked_at' => time(), 'valid_until' => time() + 300,
            'payload' => ['state' => 'complete', 'items' => [$task->id => ['state' => 'absent', 'identity' => $identity, 'absence_verified' => true]]]]);
        $this->capture($member); $this->assertSame(0, Notice::query()->where('phase', 'retained_risk')->count());
        $snapshot->payload = ['state' => 'complete', 'items' => [$task->id => ['state' => 'absent', 'identity' => str_repeat('0', 64), 'absence_verified' => true]]]; $snapshot->save();
        $this->capture($member); $this->assertSame(1, Notice::query()->where('phase', 'retained_risk')->count());
        $snapshot->payload = ['state' => 'complete', 'items' => [$task->id => ['state' => 'unknown', 'identity' => $identity, 'absence_verified' => false]]]; $snapshot->save();
        $this->capture($member); $this->assertSame(1, Notice::query()->where('phase', 'retained_risk')->count());
    }
    public function test_late_claim_and_restore_have_distinct_review_events_without_changing_old_execution(): void
    {
        $member = $this->member('needs_review'); [$attempt, $task] = $this->task($member); $this->enable();
        $this->review($member, 'claim'); $notice = $this->capture($member);
        $this->assertSame('retained', $notice->phase); $this->assertSame('review_claimed', $notice->snapshot['reason']);
        $this->review($member, 'close'); $this->assertSame('review_closed', $this->capture($member)->phase);
        $this->review($member, 'restore'); $this->assertSame('review', $this->capture($member)->phase);
        $this->assertSame('needs_review', $member->fresh()->phase); $this->assertSame('needs_review', $task->fresh()->status);
        $this->capture($member); $this->assertSame(3, Notice::query()->count());
    }
    public function test_expired_and_forged_completion_never_become_a_success_notice(): void
    {
        $this->enable(); $member = $this->member('completed'); [$attempt, $task, $instance] = $this->receipt($member, true);
        $instance->verified_until = time() - 1; $instance->save(); $notice = $this->capture($member);
        $this->assertSame('review', $notice->phase); $this->assertSame('receipt_unverified', $notice->snapshot['reason']);
        $this->assertSame(0, Notice::query()->where('phase', 'completed')->count());
        $instance->verified_until = time() + 300; $instance->save();
        MachineDnsTransition::query()->update(['request_key' => str_repeat('0', 64)]); $this->capture($member);
        $this->assertSame(0, Notice::query()->where('phase', 'completed')->count());
    }
    public function test_reenable_does_not_replay_disabled_period_or_touch_other_notice_policies(): void
    {
        $this->enable(); $member = $this->member('needs_review'); $this->capture($member); $this->enable(false);
        $member->reason = 'attempt_budget'; $member->save(); $this->capture($member); $this->enable(); $this->capture($member);
        $this->assertSame(1, Notice::query()->count()); $this->assertSame('suppressed', Notice::query()->first()->status);
        foreach (['cloud', 'dns', 'provision'] as $scope) $this->assertFalse($this->notifier->overview($scope)['policy']['enabled']);
    }
    public function test_scheduling_timestamp_changes_do_not_revoke_an_unchanged_progress_notice(): void
    {
        $this->enable(); $member = $this->member('creating'); [$attempt, $task] = $this->task($member, 'waiting_initialization');
        $notice = $this->capture($member); $this->assertSame('waiting_initialization', $notice->snapshot['result']);
        $task->next_check_at = time() + 20; $task->save(); $this->transport(); $this->notifier->deliver($notice->id);
        $this->assertSame('submitted', $notice->fresh()->status);
    }
    public function test_task_failure_dns_uncertainty_and_deadline_are_not_masked_by_a_passing_forwarding_receipt(): void
    {
        $this->enable(); $member = $this->member('creating'); [$attempt, $task] = $this->task($member, 'needs_review');
        $this->assertSame('review', $this->capture($member)->phase);
        $waiting = $this->member('awaiting_dns'); [$a, $t, $instance] = $this->receipt($waiting);
        Execution::query()->whereKey($waiting->execution_id)->update(['deadline_at' => time() - 1]);
        $this->assertSame('timeout', $this->capture($waiting)->phase);
        $waiting->fill(['phase' => 'reconciling_dns', 'reason' => 'original_intent_only'])->save();
        Execution::query()->whereKey($waiting->execution_id)->update(['deadline_at' => time() + 300]);
        $this->assertSame('review', $this->capture($waiting)->phase);
    }
    public function test_real_probe_reader_rejects_expired_or_different_installation_recovery_evidence(): void
    {
        app()->instance(MachineFailoverNoticeSource::class, new MachineFailoverNoticeSource());
        $observer = \Mockery::mock(\App\Services\ServerMachine\Cloud\MachineFailoverObserver::class);
        $probe = ['evidence_current' => false, 'probe' => ['state' => 'healthy', 'installation_id' => 'original', 'context_key' => 'pinned', 'checked_at' => time(), 'valid_until' => time() + 30]];
        $wrong = $probe; $wrong['probe']['installation_id'] = 'different'; $expired = $probe; $expired['probe']['valid_until'] = time() - 1;
        $observer->shouldReceive('summary')->andReturn($wrong, $expired, $probe); app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverObserver::class, $observer);
        $this->enable(); $member = $this->member('cancelled', 'source_recovered');
        $member->payload = ['source' => ['source_installation_id' => 'original', 'source_context_key' => 'pinned']]; $member->save();
        $this->assertSame('cancelled', $this->capture($member)->phase); $this->capture($member);
        $this->assertSame(0, Notice::query()->where('phase', 'probe_recovered')->count());
        $this->assertSame('probe_recovered', $this->capture($member)->phase);
    }
    public function test_missing_notification_migration_is_inert_and_down_refuses_to_delete_evidence(): void
    {
        $this->enable(); $member = $this->member('needs_review'); $this->capture($member);
        try { (require base_path('database/migrations/2026_11_07_210000_create_machine_failover_notice_state.php'))->down(); $this->fail('Audit deleted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('must be retained', $e->getMessage()); }
        Schema::drop('v2_machine_failover_notice_state'); $this->assertFalse($this->notifier->overview('failover')['available']);
        $this->capture($member); $this->assertSame(1, Notice::query()->count());
    }
}
