<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\MachineRollout;
use App\Models\MachineRolloutTarget;
use App\Models\ServerMachine;
use App\Models\ServerMachineLoadHistory;
use App\Models\ServerMachineRelease;
use App\Services\ServerMachine\MachineRolloutService;
use App\Services\ServerMachine\MachineRuntimeObservation;
use App\Services\ServerMachine\MachineUpgradeQueue;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class MachineRolloutServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private MachineRolloutService $service;
    private ServerMachineRelease $release;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 10:00:00');
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        app()->instance(Setting::class, new class extends Setting {
            public function get(string $key, mixed $default = null): mixed { return $key === 'server_machine_distribution_source' ? 'panel' : $default; }
        });
        Schema::create('v2_server_machine', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active');
            $table->integer('last_seen_at')->nullable(); $table->json('load_status')->nullable(); $table->json('upgrade_state')->nullable(); $table->timestamps();
        });
        Schema::create('v2_server_machine_load_history', function (Blueprint $table): void {
            $table->id(); $table->integer('machine_id'); $table->json('load_status'); $table->timestamps();
        });
        Schema::create('v2_server_machine_release', function (Blueprint $table): void {
            $table->id(); foreach (['component', 'version', 'platform', 'status', 'sha256', 'binary_sha256', 'archive_path', 'manifest_path'] as $key) $table->string($key);
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_10_16_000001_create_machine_rollouts.php'))->up();
        Storage::fake('local');
        $this->release = $this->makeRelease('v0.1.372', 'b');
        $this->service = app(MachineRolloutService::class);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function makeRelease(string $version, string $hash): ServerMachineRelease
    {
        Storage::disk('local')->put("$version/manifest.json", '{}');
        Storage::disk('local')->put("$version/archive.tar.gz", 'fixture');
        return ServerMachineRelease::create(['component' => 'kelinode-rs', 'version' => $version, 'platform' => 'linux-x86_64', 'status' => 'active', 'sha256' => str_repeat($hash, 64), 'binary_sha256' => str_repeat($hash, 64), 'manifest_path' => "$version/manifest.json", 'archive_path' => "$version/archive.tar.gz"]);
    }

    private function reportStatus(bool $after = false, int $uptime = 1800): array
    {
        return [
            'updated_at' => now()->timestamp, 'version' => $after ? 'v0.1.372' : 'v0.1.371',
            'system' => ['os' => 'linux', 'arch' => 'x86_64'],
            'runtime' => ['agent' => 'kelinode-rs', 'core_mode' => 'embedded', 'binary_sha256' => str_repeat($after ? 'b' : 'a', 64), 'observation' => [
                'schema' => 1, 'session' => str_repeat($after ? 'b' : 'a', 32), 'pid' => $after ? 200 : 100, 'started_at' => now()->timestamp - $uptime, 'uptime_seconds' => $uptime,
                'oom' => ['scope' => str_repeat('c', 64), 'oom' => 0, 'oom_kill' => 0],
            ]],
            'metrics' => ['keli_core_rs' => [
                'keli_core_process_rss_bytes' => 256 * 1024 * 1024, 'keli_core_process_cpu_percent_x100' => 1000,
                'keli_core_quic_resource' => ['active_connections' => 50], 'keli_core_hy2_tcp_active_streams' => 100, 'keli_core_hy2_udp_active_sessions' => 10,
                'keli_core_process_memory_trim_error_total' => 0, 'keli_core_user_delta_apply_error_total' => 0,
            ]],
        ];
    }

    private function machine(string $name = 'edge'): ServerMachine
    {
        $machine = ServerMachine::create(['name' => $name, 'token' => 'private', 'is_active' => true, 'last_seen_at' => now()->timestamp, 'load_status' => $this->reportStatus()]);
        $this->baselines($machine);
        return $machine;
    }

    private function baselines(ServerMachine $machine): void
    {
        foreach ([180, 120, 60] as $age) {
            $status = $this->reportStatus(); $status['updated_at'] -= $age;
            ServerMachineLoadHistory::create(['machine_id' => $machine->id, 'load_status' => $status, 'created_at' => now()->subSeconds($age)]);
        }
        $machine->update(['load_status' => $this->reportStatus(), 'last_seen_at' => now()->timestamp]);
    }

    private function create(array $machines): MachineRollout
    {
        return $this->service->create(1, ['client_token' => (string) Str::uuid(), 'release_id' => $this->release->id, 'machine_ids' => array_map(fn ($m) => $m->id, $machines), 'observe_minutes' => 10]);
    }

    private function report(ServerMachine $machine, ?array $status = null): void
    {
        $state = $machine->fresh()->upgrade_state;
        $state['status'] = 'succeeded'; $state['running_binary_sha256'] = str_repeat('b', 64);
        $machine->update(['upgrade_state' => $state, 'load_status' => $status ?? $this->reportStatus(true), 'last_seen_at' => now()->timestamp]);
    }

    public function test_creating_is_idempotent_and_only_the_scheduler_dispatches_the_first_machine(): void
    {
        $a = $this->machine(); $b = $this->machine('second');
        $input = ['client_token' => (string) Str::uuid(), 'release_id' => $this->release->id, 'machine_ids' => [$a->id, $b->id], 'observe_minutes' => 10];
        $task = $this->service->create(1, $input);
        $this->assertSame($task->id, $this->service->create(1, $input)->id);
        $this->assertNull($a->fresh()->upgrade_state);
        $this->service->tick($task->id);
        $id = $a->fresh()->upgrade_state['id'];
        $this->service->tick($task->id);
        $this->assertSame($id, $a->fresh()->upgrade_state['id']);
        $this->assertSame(str_repeat('b', 64), $a->fresh()->upgrade_state['expected_binary_sha256']);
        $this->assertNull($b->fresh()->upgrade_state);
        $this->assertCount(2, $task->targets);
    }

    public function test_restarts_unknown_oom_and_missing_baselines_are_never_treated_as_healthy(): void
    {
        $normalizer = new MachineRuntimeObservation();
        $this->assertNull($normalizer->normalize(null));
        $observation = $this->reportStatus()['runtime']['observation'];
        $state = $normalizer->track([], $observation, 1);
        $this->assertSame(0, $state['observed_restarts']);
        $observation['session'] = str_repeat('d', 32);
        $state = $normalizer->track(['runtime_observation_state' => $state], $observation, 2);
        $this->assertSame(1, $state['observed_restarts']);
        $observation['oom'] = ['oom' => 0];
        $this->assertNull($normalizer->normalize($observation)['oom']);
        $machine = $this->machine();
        ServerMachineLoadHistory::query()->delete();
        $this->expectExceptionMessage('baseline_insufficient');
        $this->create([$machine]);
    }

    public function test_reserved_machines_cannot_be_upgraded_even_with_force(): void
    {
        $machine = $this->machine(); $this->create([$machine]);
        $this->expectExceptionMessage('机器已加入灰度升级');
        app(MachineUpgradeQueue::class)->enqueue($machine->id, 'kelinode-rs', 'v0.1.373', true);
    }

    public function test_changed_artifacts_pause_without_dispatch(): void
    {
        $machine = $this->machine(); $task = $this->create([$machine]);
        $this->release->update(['binary_sha256' => str_repeat('d', 64)]);
        $this->service->tick($task->id);
        $this->assertSame('release_changed', $task->fresh()->reason);
        $this->assertNull($machine->fresh()->upgrade_state);
    }

    public function test_initial_warmup_memory_is_not_evidence_and_additional_restarts_pause(): void
    {
        $machine = $this->machine(); $task = $this->create([$machine]); $this->service->tick($task->id);
        Carbon::setTestNow(now()->addMinute());
        $this->report($machine, $this->reportStatus(true, 30)); $this->service->tick($task->id);
        $this->assertSame([], $task->targets()->first()->samples);
        $status = $this->reportStatus(true, 10); $status['runtime']['observation']['session'] = str_repeat('e', 32);
        $this->report($machine, $status); $this->service->tick($task->id);
        $this->assertSame('unexpected_restart', $task->fresh()->reason);
    }

    public function test_oom_and_new_runtime_errors_stop_subsequent_machines(): void
    {
        $machine = $this->machine(); $other = $this->machine('other'); $task = $this->create([$machine, $other]); $this->service->tick($task->id);
        $status = $this->reportStatus(true); $status['runtime']['observation']['oom']['oom_kill'] = 1;
        $this->report($machine, $status); $this->service->tick($task->id);
        $this->assertSame('oom_observed', $task->fresh()->reason);
        $this->assertNull($other->fresh()->upgrade_state);
    }

    public function test_three_matched_regression_samples_pause_and_preserve_evidence(): void
    {
        $machine = $this->machine(); $task = $this->create([$machine]); $this->service->tick($task->id);
        for ($i = 0; $i < 3; $i++) {
            Carbon::setTestNow(now()->addMinute());
            $status = $this->reportStatus(true); $status['metrics']['keli_core_rs']['keli_core_process_rss_bytes'] = 450 * 1024 * 1024;
            $this->report($machine, $status); $this->service->tick($task->id);
        }
        $this->assertSame('performance_regression', $task->fresh()->reason);
        $this->assertCount(3, $task->targets()->first()->samples);
    }

    public function test_canary_pass_requires_human_gate_before_second_machine(): void
    {
        $a = $this->machine(); $b = $this->machine('second'); $task = $this->create([$a, $b]); $this->service->tick($task->id);
        for ($i = 0; $i <= 5; $i++) {
            Carbon::setTestNow(now()->addSeconds(120));
            $this->report($a); $this->baselines($b); $this->service->tick($task->id);
        }
        $this->assertSame('awaiting_gate', $task->fresh()->status);
        $this->assertNull($b->fresh()->upgrade_state);
        $this->service->tick($task->id);
        $this->assertNull($b->fresh()->upgrade_state);
        $this->service->continue($task);
        $this->assertNull($b->fresh()->upgrade_state);
        $this->service->tick($task->id);
        $this->assertSame('queued', $b->fresh()->upgrade_state['status']);
    }

    public function test_unmatched_load_cannot_pass_even_after_two_hours(): void
    {
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id);
        Carbon::setTestNow(now()->addSeconds(7201));
        $status = $this->reportStatus(true); $status['metrics']['keli_core_rs']['keli_core_quic_resource']['active_connections'] = 400;
        $this->report($a, $status); $this->service->tick($task->id);
        $this->assertSame('insufficient_evidence', $task->fresh()->reason);
        $this->assertSame([], $task->targets()->first()->samples);
    }

    public function test_stop_does_not_revoke_already_queued_upgrade_and_never_dispatches_remaining(): void
    {
        $a = $this->machine(); $b = $this->machine('second'); $task = $this->create([$a, $b]); $this->service->tick($task->id);
        $upgrade = $a->fresh()->upgrade_state;
        $this->service->cancel($task); $this->service->tick($task->id);
        $this->assertSame($upgrade, $a->fresh()->upgrade_state);
        $this->assertNull($b->fresh()->upgrade_state);
        $this->assertSame(0, MachineRolloutTarget::whereNotNull('active_machine_id')->count());
    }

    public function test_manual_restore_is_pinned_to_recorded_baseline_and_stops_the_rollout(): void
    {
        $this->makeRelease('v0.1.371', 'a');
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id);
        $this->report($a); $task->update(['status' => 'paused']);
        $this->service->restore($task, $a->id);
        $this->assertSame('cancelled', $task->fresh()->status);
        $this->assertSame('v0.1.371', $a->fresh()->upgrade_state['target_version']);
        $this->assertSame(str_repeat('a', 64), $a->fresh()->upgrade_state['expected_binary_sha256']);
    }

    public function test_missing_oom_does_not_create_a_task_or_issue_an_upgrade(): void
    {
        $a = $this->machine(); $status = $this->reportStatus(); $status['runtime']['observation']['oom'] = null;
        $a->update(['load_status' => $status]);
        try { $this->create([$a]); $this->fail('Expected missing-evidence rejection'); }
        catch (RuntimeException $error) { $this->assertSame('observation_unsupported', $error->getMessage()); }
        $this->assertSame(0, MachineRollout::count());
        $this->assertNull($a->fresh()->upgrade_state);
    }

    public function test_replaying_the_same_report_never_accumulates_samples(): void
    {
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id); $this->report($a);
        for ($i = 0; $i < 6; $i++) $this->service->tick($task->id);
        $target = $task->targets()->first();
        $this->assertSame(1, $target->matched_count);
        $this->assertCount(1, $target->samples);
        $this->assertSame('running', $task->fresh()->status);
    }

    public function test_load_mismatch_resets_the_observation_window(): void
    {
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id);
        for ($i = 0; $i < 2; $i++) {
            Carbon::setTestNow(now()->addMinute()); $this->report($a); $this->service->tick($task->id);
        }
        Carbon::setTestNow(now()->addMinute());
        $status = $this->reportStatus(true); $status['metrics']['keli_core_rs']['keli_core_hy2_tcp_active_streams'] = 900;
        $this->report($a, $status); $this->service->tick($task->id);
        $target = $task->targets()->first();
        $this->assertSame(0, $target->matched_count);
        $this->assertNull($target->observing_at);
        $this->assertSame([], $target->samples);
    }

    public function test_old_oom_totals_are_not_mistaken_for_new_oom_events(): void
    {
        $a = $this->machine(); $status = $this->reportStatus(); $status['runtime']['observation']['oom']['oom'] = 2;
        $a->update(['load_status' => $status]);
        $task = $this->create([$a]); $this->service->tick($task->id);
        $status = $this->reportStatus(true); $status['runtime']['observation']['oom']['oom'] = 2;
        $this->report($a, $status); $this->service->tick($task->id);
        $this->assertSame('running', $task->fresh()->status);
        $this->assertNull($task->fresh()->reason);
    }

    public function test_stale_full_reports_cannot_be_rescued_by_a_fresh_last_seen(): void
    {
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id);
        Carbon::setTestNow(now()->addSeconds(301));
        $a->update(['last_seen_at' => now()->timestamp]); $this->service->tick($task->id);
        $this->assertSame('report_stale', $task->fresh()->reason);
    }

    public function test_changed_baseline_artifact_prevents_manual_restore(): void
    {
        $old = $this->makeRelease('v0.1.371', 'a');
        $a = $this->machine(); $task = $this->create([$a]); $this->service->tick($task->id); $this->report($a);
        $task->update(['status' => 'paused']); $old->update(['binary_sha256' => str_repeat('e', 64)]);
        $before = $a->fresh()->upgrade_state;
        try { $this->service->restore($task, $a->id); $this->fail('Expected changed-artifact rejection'); }
        catch (RuntimeException) { $this->assertSame($before, $a->fresh()->upgrade_state); }
        $this->assertSame('paused', $task->fresh()->status);
    }

    public function test_task_history_and_actions_are_scoped_to_the_requesting_admin(): void
    {
        $a = $this->machine(); $task = $this->create([$a]);
        $controller = app(\App\Http\Controllers\V2\Admin\Server\MachineRolloutController::class);
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $user = new \App\Models\User(); $user->id = 2; $request->setUserResolver(fn () => $user);
        $this->assertSame([], $controller->index($request)->getData(true)['data']);
        $request = \Illuminate\Http\Request::create('/', 'POST', ['id' => $task->id, 'action' => 'cancel']);
        $request->setUserResolver(fn () => $user);
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $controller->action($request);
    }

    public function test_invalid_continue_does_not_drive_the_scheduler_or_dispatch_a_machine(): void
    {
        $a = $this->machine(); $task = $this->create([$a]);
        try { $this->service->continue($task); $this->fail('Expected invalid-stage rejection'); }
        catch (RuntimeException) { $this->assertNull($a->fresh()->upgrade_state); }
        $this->assertNull($task->fresh()->last_tick_at);
    }

    public function test_cleanup_only_removes_expired_terminal_task_evidence(): void
    {
        $expired = $this->create([$this->machine('expired')]);
        $recent = $this->create([$this->machine('recent')]);
        $paused = $this->create([$this->machine('paused')]);
        $this->service->cancel($expired);
        $this->service->cancel($recent);
        $paused->update(['status' => 'paused']);
        \Illuminate\Support\Facades\DB::table('v2_machine_rollout')->whereIn('id', [$expired->id, $paused->id])
            ->update(['updated_at' => now()->subDays(31)->toDateTimeString()]);

        $result = app(\App\Console\Commands\AdvanceMachineRollouts::class)->handle($this->service);

        $this->assertSame(0, $result);
        $this->assertNull($expired->fresh());
        $this->assertSame(0, $expired->targets()->count());
        $this->assertNotNull($recent->fresh());
        $this->assertSame(1, $recent->targets()->count());
        $this->assertSame('paused', $paused->fresh()->status);
        $this->assertSame(1, $paused->targets()->count());
    }
}
