<?php
namespace Tests\Unit\Services;

use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as ExecutionMember;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverRuntime as Runtime;
use App\Models\MachineFailoverTriggerPreview as Preview;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use App\Services\ServerMachine\Cloud\MachineFailoverAdmission;
use App\Services\ServerMachine\Cloud\MachineFailoverBudget;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverRunner;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverTriggerPreview;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

class MachineFailoverAdmissionTest extends MachineFailoverShadowTest
{
    private MachineFailoverBudget $finance;
    private ServerMachineRelease $release;
    private array $starts = [];
    private array $enrollments = [];
    private $beforeStart = null;
    private $afterCommit = null;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_11_07_180000_create_machine_failover_native_journal.php', '2026_11_07_190000_create_machine_failover_finance.php',
            '2026_11_07_220000_create_machine_failover_runtime.php', '2026_11_08_000000_create_machine_failover_trigger_previews.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php', '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php'] as $file)
            (require base_path('database/migrations/' . $file))->up();
        config(['app.key' => str_repeat('x', 32)]);
        $this->finance = new class(app(MachineFailoverServiceRegistry::class), app(MachineProvisionProviders::class)) extends MachineFailoverBudget {
            public array $value = [];
            public function forecast(int $id, int $revision, int $admin): array { return $this->value; }
        };
        $this->finance->value = ['state' => 'verified', 'available_cents' => 10000, 'checked_at' => $this->clock, 'valid_until' => $this->clock + 300];
        app()->instance(MachineFailoverBudget::class, $this->finance);
        $this->release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'synthetic-v1',
            'platform' => 'linux-x86_64', 'manifest_path' => 'synthetic/manifest.json', 'archive_path' => 'synthetic/node.tar.gz',
            'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $native = \Mockery::mock(MachineFailoverNativeBridge::class);
        $native->shouldReceive('available')->andReturnTrue();
        $native->shouldReceive('start')->andReturnUsing(function ($id, $revision, $plans, $release, $request, $admin, $admission) {
            $this->starts[] = $request;
            if ($this->beforeStart) ($this->beforeStart)();
            $run = DB::transaction(function () use ($id, $revision, $plans, $request, $admin, $admission) {
                $snapshot = app(MachineFailoverServiceRegistry::class)->snapshot($id, $revision);
                $episodes = Plan::query()->whereIn('id', $plans)->get()->mapWithKeys(fn ($p) => [$p->id => $p->evidence['episode_key']])->all();
                app(MachineFailoverAdmission::class)->assertStart($admission, $snapshot, $admission['authorization']['release'], $episodes);
                $run = Execution::query()->create(['id' => (string) Str::uuid(), 'service_id' => $id, 'service_revision' => $revision, 'admin_id' => $admin,
                    'request_key' => hash('sha256', json_encode([$admin, $request])), 'intent_hash' => str_repeat('f', 64),
                    'context_hash' => app(MachineFailoverServiceRegistry::class)->fingerprint($snapshot),
                    'payload' => ['snapshot' => $snapshot, 'release' => $admission['authorization']['release'], 'admission' => $admission], 'deadline_at' => $this->clock + 1200]);
                foreach (Plan::query()->whereIn('id', $plans)->get() as $plan) {
                    $member = \App\Models\MachineFailoverMember::query()->where('service_id', $id)->where('policy_id', $plan->policy_id)->firstOrFail();
                    ExecutionMember::query()->create(['execution_id' => $run->id, 'member_id' => $member->id, 'policy_id' => $plan->policy_id,
                        'policy_revision' => $plan->policy_revision, 'plan_id' => $plan->id, 'episode_key' => $plan->evidence['episode_key'], 'payload' => ['priority' => $member->priority]]);
                }
                return $run;
            });
            if ($this->afterCommit) ($this->afterCommit)();
            app(MachineFailoverRunner::class)->enroll($run->id);
            return ['id' => $run->id];
        });
        app()->instance(MachineFailoverNativeBridge::class, $native);
        $runner = \Mockery::mock(MachineFailoverRunner::class); $runner->shouldReceive('available')->andReturnTrue();
        $runner->shouldReceive('enroll')->andReturnUsing(function ($id) { $this->enrollments[] = $id; });
        app()->instance(MachineFailoverRunner::class, $runner);
    }

    private function prepareAdmission(): array
    {
        $this->trigger(); config(['machine_failover.preview_enabled' => true]);
        app(MachineFailoverTriggerPreview::class)->collect();
        $registry = app(MachineFailoverServiceRegistry::class);
        $pin = ['service_id' => $this->service['id'], 'service_revision' => 1, 'admin_id' => $this->admin->id,
            'issued_at' => $this->clock - 1, 'expires_at' => $this->clock + 600,
            'context_hash' => $registry->fingerprint($registry->snapshot($this->service['id'], 1)),
            'release' => $this->release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256'])];
        config(['machine_failover.admission_enabled' => true, 'machine_failover.admission_authorizations' => [$pin],
            'machine_failover.runner_enabled' => true, 'machine_failover.allow_replacement' => true,
            'machine_provision.allow_live' => true, 'machine_acceptance.allow_verification' => true, 'machine_acceptance.allow_dns' => true]);
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        return $pin;
    }
    private function advanceAdmission(): array { return app(MachineFailoverAdmission::class)->advance(); }
    private function blocked(string $reason): array
    {
        $result = $this->advanceAdmission();
        $this->assertSame($reason, $result['reason'] ?? ($result['services'][0]['reason'] ?? null));
        $this->assertSame(0, $result['started']); Http::assertNothingSent(); Bus::assertNothingDispatched();
        return $result;
    }

    public function test_defaults_never_admit_and_config_flags_cannot_bypass_the_production_gate(): void
    {
        $this->assertFalse($this->advanceAdmission()['enabled']); $this->prepareAdmission();
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $this->blocked('orchestration_disabled'); $this->assertSame([], $this->starts); $this->assertSame(0, Execution::query()->count());
    }
    public function test_current_collected_proposal_starts_once_and_recovers_the_same_execution(): void
    {
        $this->prepareAdmission(); $first = $this->advanceAdmission(); $again = $this->advanceAdmission();
        $this->assertSame(1, $first['started']); $this->assertSame(1, $again['resumed']);
        $this->assertSame($first['services'][0]['execution_id'], $again['services'][0]['execution_id']);
        $this->assertCount(1, $this->starts); $this->assertSame(1, Execution::query()->count()); $this->assertSame(1, ExecutionMember::query()->count());
        $this->assertCount(2, $this->enrollments); Http::assertNothingSent(); Bus::assertNothingDispatched();
        $json = json_encode($first); foreach (['synthetic-secret', 'context_hash', 'episode_key', 'authorization', 'request_key', 'binary_sha256'] as $secret)
            $this->assertStringNotContainsString($secret, $json);
    }
    public function test_a_crash_after_commit_resumes_original_intent_without_another_start(): void
    {
        $this->prepareAdmission(); $this->afterCommit = fn () => throw new \RuntimeException('synthetic private diagnostic');
        $first = $this->blocked('admission_unavailable'); $this->assertSame(1, $first['errors']);
        $this->assertSame(1, Execution::query()->count()); $this->assertSame([], $this->enrollments);
        $this->afterCommit = null; $this->assertSame(1, $this->advanceAdmission()['resumed']);
        $this->assertCount(1, $this->starts); $this->assertCount(1, $this->enrollments);
        $this->assertStringNotContainsString('synthetic private diagnostic', json_encode($first));
    }
    public function test_recovery_does_not_adopt_foreign_runs_or_rearm_a_stop(): void
    {
        $this->prepareAdmission(); $this->advanceAdmission(); $run = Execution::query()->firstOrFail();
        Runtime::query()->create(['execution_id' => $run->id, 'stop_requested_at' => $this->clock, 'state' => 'stopping']);
        $this->blocked('native_stop_requested'); $this->assertCount(1, $this->enrollments);
        $payload = $run->payload; unset($payload['admission']); $run->fill(['payload' => $payload])->save();
        $this->blocked('native_run_active'); $this->assertCount(1, $this->starts);
    }
    public function test_closed_and_expired_executions_cannot_create_a_second_paid_intent_for_the_episode(): void
    {
        $this->prepareAdmission(); $this->advanceAdmission(); $run = Execution::query()->firstOrFail();
        $run->fill(['deadline_at' => $this->clock - 1])->save(); $this->blocked('native_intent_expired');
        $run->fill(['status' => 'stopped'])->save(); $this->blocked('admission_no_proposal');
        $this->assertCount(1, $this->starts); $this->assertSame(1, Execution::query()->count());
    }
    public function test_a_terminal_runtime_cannot_be_rearmed_when_its_run_is_inconsistent(): void
    {
        $this->prepareAdmission(); $this->advanceAdmission(); $run = Execution::query()->firstOrFail();
        Runtime::query()->create(['execution_id' => $run->id, 'state' => 'finished']);
        $this->blocked('native_stop_requested'); $this->assertCount(1, $this->enrollments);
        $this->assertSame('finished', Runtime::query()->findOrFail($run->id)->state);
    }
    public function test_source_recovery_and_uncollected_or_stale_evidence_cannot_use_a_prior_preview(): void
    {
        $this->prepareAdmission(); $this->sample(0, true); $this->sample(1, true); $this->blocked('admission_no_proposal');
        $this->assertCount(0, $this->starts);
    }
    public function test_proposal_is_revalidated_inside_start_before_its_commit(): void
    {
        $this->prepareAdmission();
        $this->beforeStart = fn () => Preview::query()->where('decision', 'would_start')->update(['revision' => 99]);
        $this->blocked('admission_evidence_changed'); $this->assertSame(0, Execution::query()->count());
    }
    public function test_cost_deterioration_between_selection_and_start_does_not_gate_execution(): void
    {
        $this->prepareAdmission(); $this->beforeStart = function () { $this->finance->value['available_cents'] = 3999; };
        $this->assertSame(1, $this->advanceAdmission()['started']); $this->assertSame(1, Execution::query()->count());
    }
    public function test_replacing_the_grant_before_start_and_after_enrollment_revokes_progress(): void
    {
        $this->prepareAdmission(); $this->beforeStart = fn () => config(['machine_failover.admission_authorizations' => []]);
        $this->blocked('admission_revoked'); $this->assertSame(0, Execution::query()->count());
        $this->beforeStart = null; $this->prepareAdmission(); $this->advanceAdmission();
        $run = Execution::query()->firstOrFail(); config(['machine_failover.admission_authorizations' => []]);
        try { app(MachineFailoverAdmission::class)->assertRun($run); $this->fail('Revoked authorization'); }
        catch (CloudException $e) { $this->assertSame('admission_revoked', $e->getMessage()); }
    }
    public function test_json_object_order_does_not_change_authorization_or_recovery_identity(): void
    {
        $pin = $this->prepareAdmission(); $this->advanceAdmission();
        $pin['release'] = array_reverse($pin['release'], true); $pin = array_reverse($pin, true);
        config(['machine_failover.admission_authorizations' => [$pin]]);
        $run = Execution::query()->firstOrFail(); $payload = $run->payload;
        $payload['admission'] = array_reverse($payload['admission'], true); $run->fill(['payload' => $payload])->save();
        $this->assertSame(1, $this->advanceAdmission()['resumed']); $this->assertCount(1, $this->starts);
    }
    public function test_changed_archive_hash_blocks_admission_and_existing_run_recovery(): void
    {
        $this->prepareAdmission(); $this->release->fill(['sha256' => str_repeat('c', 64)])->save();
        $this->blocked('release_changed'); $this->assertCount(0, $this->starts);
        $this->release->fill(['sha256' => str_repeat('a', 64)])->save(); $this->advanceAdmission();
        $this->release->fill(['sha256' => str_repeat('c', 64)])->save(); $this->blocked('release_changed');
        $this->assertCount(1, $this->starts); $this->assertCount(1, $this->enrollments);
    }
    public function test_unsupported_provider_is_not_silently_routed_to_linode(): void
    {
        $this->prepareAdmission(); $this->account->fill(['provider' => 'unsupported'])->save();
        $registry = app(MachineFailoverServiceRegistry::class); $pins = config('machine_failover.admission_authorizations');
        $pins[0]['context_hash'] = $registry->fingerprint($registry->snapshot($this->service['id'], 1));
        config(['machine_failover.admission_authorizations' => $pins]); $this->blocked('admission_provider_unsupported');
        $this->assertCount(0, $this->starts);
    }
    public function test_registry_sweep_and_service_locks_are_bounded_and_default_command_cannot_run(): void
    {
        $this->prepareAdmission(); $lock = Cache::lock('machine-failover:admission-sweep', 60); $lock->get();
        $this->blocked('admission_busy'); $lock->release();
        $lock = Cache::lock('machine-failover:admission:' . $this->service['id'], 120); $lock->get();
        $this->blocked('admission_busy'); $lock->release(); $this->assertCount(0, $this->starts);
        $command = new \App\Console\Commands\AdmitMachineFailover(); $command->setLaravel(app());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $this->assertSame(2, $command->run(new \Symfony\Component\Console\Input\ArrayInput(['--limit' => 11]), $output));
        config(['machine_failover.admission_enabled' => false]);
        $this->assertSame(0, $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output));
        $this->assertFalse(json_decode($output->fetch(), true)['enabled']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public static function invalidPins(): iterable
    {
        yield 'missing context' => ['context_hash', null, 'admission_scope_invalid'];
        yield 'empty context' => ['context_hash', '', 'admission_scope_invalid'];
        yield 'bad numeric identity' => ['admin_id', '1', 'admission_scope_invalid'];
        yield 'already expired' => ['expires_at', 1, 'admission_scope_invalid'];
        yield 'wrong service revision' => ['service_revision', 999, 'config_changed'];
    }
    #[DataProvider('invalidPins')]
    public function test_bad_or_changed_scope_never_starts(string $field, mixed $value, string $reason): void
    {
        $pin = $this->prepareAdmission(); $pin[$field] = $value; config(['machine_failover.admission_authorizations' => [$pin]]);
        $this->blocked($reason); $this->assertCount(0, $this->starts);
    }
    public function test_future_expired_duplicate_and_empty_authorizations_do_not_grant_execution(): void
    {
        $pin = $this->prepareAdmission(); $pin['issued_at'] = $this->clock + 1; $pin['expires_at'] = $this->clock + 300;
        config(['machine_failover.admission_authorizations' => [$pin]]); $this->blocked('admission_expired');
        $pin['issued_at'] = $this->clock - 100; $pin['expires_at'] = $this->clock;
        config(['machine_failover.admission_authorizations' => [$pin]]); $this->blocked('admission_expired');
        config(['machine_failover.admission_authorizations' => [$pin, $pin]]); $this->blocked('admission_scope_invalid');
        config(['machine_failover.admission_authorizations' => []]); $this->assertSame(0, $this->advanceAdmission()['checked']);
        $this->assertCount(0, $this->starts);
    }
    public static function alteredPreviews(): iterable
    {
        yield 'expired preview' => ['valid_until', 1];
        yield 'no longer a proposal' => ['decision', 'blocked'];
        yield 'wrong source' => ['source_hash', 'not-the-collected-source'];
        yield 'changed context' => ['context_hash', str_repeat('f', 64)];
        yield 'forged price' => ['planned_cents', 1];
        yield 'changed policy' => ['policy_revision', 999];
    }
    #[DataProvider('alteredPreviews')]
    public function test_a_preview_alone_cannot_supply_forged_authority(string $field, mixed $value): void
    {
        $this->prepareAdmission(); Preview::query()->where('decision', 'would_start')->update([$field => $value]);
        $this->blocked('admission_evidence_changed'); $this->assertCount(0, $this->starts); $this->assertSame(0, Execution::count());
    }
    public function test_future_and_expired_preview_timestamps_are_not_current_evidence(): void
    {
        $this->prepareAdmission(); Preview::query()->where('decision', 'would_start')->update(['checked_at' => $this->clock + 1]);
        $this->blocked('admission_evidence_changed');
        Preview::query()->where('decision', 'would_start')->update(['checked_at' => $this->clock - 31]);
        $this->blocked('admission_evidence_changed'); $this->assertCount(0, $this->starts);
    }
    public function test_unknown_expired_and_insufficient_finance_does_not_block_or_duplicate_execution(): void
    {
        $this->prepareAdmission();
        foreach ([['state' => 'unread'], ['available_cents' => null], ['available_cents' => 3999], ['valid_until' => $this->clock]] as $change) {
            $this->finance->value = array_replace(['state' => 'verified', 'available_cents' => 10000, 'checked_at' => $this->clock, 'valid_until' => $this->clock + 300], $change);
            $result = $this->advanceAdmission(); $this->assertSame(1, $result['started'] + $result['resumed']);
        }
        $this->assertSame(1, Execution::count()); $this->assertCount(1, $this->starts);
    }
    public function test_cost_windows_do_not_participate_in_admission_or_replay(): void
    {
        $this->prepareAdmission();
        foreach ([['checked_at' => $this->clock + 1], ['checked_at' => $this->clock - 301], ['valid_until' => $this->clock + 301],
            ['checked_at' => (string) $this->clock], ['valid_until' => (string) ($this->clock + 300)]] as $change) {
            $this->finance->value = array_replace(['state' => 'verified', 'available_cents' => 10000, 'checked_at' => $this->clock, 'valid_until' => $this->clock + 300], $change);
            $result = $this->advanceAdmission(); $this->assertSame(1, $result['started'] + $result['resumed']);
        }
        $this->assertSame(1, Execution::count()); $this->assertCount(1, $this->starts);
    }
    public function test_expired_authorization_and_banned_owner_never_resume_an_existing_run(): void
    {
        $this->prepareAdmission(); $this->advanceAdmission(); $this->tick(601);
        $this->blocked('admission_expired'); $this->assertCount(1, $this->enrollments);
        $this->prepareAdmission(); $this->admin->fill(['banned' => true])->save();
        $this->blocked('permission_revoked'); $this->assertCount(1, $this->starts);
    }
    public function test_only_the_bounded_cursor_slice_is_checked_per_sweep(): void
    {
        $pin = $this->prepareAdmission(); $other = $pin; $other['service_id'] = 999;
        config(['machine_failover.admission_authorizations' => [$other, $pin]]);
        $admission = app(MachineFailoverAdmission::class);
        $first = $admission->advance(1); $this->assertSame(1, $first['checked']); $this->assertSame($pin['service_id'], $first['services'][0]['service_id']);
        $second = $admission->advance(1); $this->assertSame(1, $second['checked']); $this->assertSame(999, $second['services'][0]['service_id']);
        $third = $admission->advance(1); $this->assertSame(1, $third['resumed']); $this->assertCount(1, $this->starts);
        $this->assertStringNotContainsString('ModelNotFoundException', json_encode($second));
    }
    public function test_conditional_admission_schedule_keeps_all_existing_queues_and_stop_recovery(): void
    {
        app()->instance(\Illuminate\Console\Scheduling\EventMutex::class, $this->createMock(\Illuminate\Console\Scheduling\EventMutex::class));
        app()->instance(\Illuminate\Console\Scheduling\SchedulingMutex::class, $this->createMock(\Illuminate\Console\Scheduling\SchedulingMutex::class));
        $backup = $this->createMock(\App\Services\Backup\BackupService::class); $backup->method('settings')->willReturn(['enabled' => false]);
        app()->instance(\App\Services\Backup\BackupService::class, $backup);
        app()->instance(\App\Services\Plugin\PluginManager::class, $this->createMock(\App\Services\Plugin\PluginManager::class));
        $baseline = null;
        foreach ([false, true] as $enabled) {
            config(['machine_failover.admission_enabled' => $enabled]); $schedule = new \Illuminate\Console\Scheduling\Schedule();
            $kernel = new \App\Console\Kernel(app(), $this->createMock(\Illuminate\Contracts\Events\Dispatcher::class));
            (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);
            $admission = array_values(array_filter($schedule->events(), fn ($e) => str_contains($e->command ?? '', 'machines:admit-failover')));
            $others = array_values(array_map(fn ($e) => $e->command, array_filter($schedule->events(), fn ($e) => !str_contains($e->command ?? '', 'machines:admit-failover'))));
            if (!$enabled) $baseline = $others; else $this->assertSame($baseline, $others);
            $this->assertCount($enabled ? 1 : 0, $admission);
            if ($enabled) { $this->assertTrue($admission[0]->onOneServer); $this->assertTrue($admission[0]->withoutOverlapping); $this->assertTrue($admission[0]->runInBackground); }
        }
        $this->assertFalse((require base_path('config/machine_failover.php'))['admission_enabled']);
        $this->assertSame([], (require base_path('config/machine_failover.php'))['admission_authorizations']);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
}
