<?php
namespace Tests\Support;

use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate as Gate;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry as Registry;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use Keli\Acceptance\MemberCliAuthority as Authority;
use Keli\Acceptance\MemberRoundBridge as Bridge;

trait TestsMemberCli
{
    private array $cliScope = [];
    private array $cliEnvironment = [];
    private int $cliSequence = 0;

    private function cliFixture(): array
    {
        require_once base_path('tools/machine-acceptance/member_cli_authority.php');
        require_once base_path('tools/machine-acceptance/member_round_bridge.php');
        $this->memberTransportFixture();
        $this->memberTransportScope['run_id'] = Authority::RUN;
        $driver = $this->memberDriverScope; $driver['run_id'] = Authority::RUN;
        $this->cliScope = ['schema' => 2, 'execution_authorized' => true, 'isolated' => true,
            'run_id' => Authority::RUN, 'grant_id' => str_repeat('a', 32), 'bundle_sha256' => str_repeat('b', 64),
            'controller_machine_id_sha256' => str_repeat('c', 64), 'database' => 'keli_acceptance_r35',
            'started_at' => $this->retryClock->at - 1, 'deadline_at' => $this->retryClock->at + 7199,
            'control' => ['external_id' => '800'], 'driver' => $driver,
            'sources' => $this->memberTransportScope['sources'],
            'binary_sha256' => $this->release->binary_sha256, 'version' => $this->release->version,
            'service_sha256' => hash('sha256', Json::encode(app(Registry::class)->snapshot($driver['service_id'], $driver['service_revision'])))];
        $this->cliEnvironment = ['sapi' => 'cli', 'os' => 'Linux', 'env' => 'acceptance',
            'root' => '/opt/keli-isolated/app', 'machine_sha256' => str_repeat('c', 64),
            'database_driver' => 'mysql', 'database' => 'keli_acceptance_r35', 'database_host' => '127.0.0.1',
            'cache' => 'redis', 'cache_prefix' => Authority::RUN . ':', 'cache_connection' => 'cache',
            'redis_default' => ['host' => '127.0.0.1'], 'redis_cache' => ['host' => '127.0.0.1']];
        config(Authority::closed());
        return $this->cliBindings();
    }

    private function cliBindings(): array
    {
        $authority = Authority::fixture($this->cliScope, str_repeat('d', 64), fn () => $this->cliEnvironment,
            fn () => $this->retryClock->at, fn () => isset($this->memberTransportJournal['round-stop']));
        $bridge = new Bridge($this->cliScope, $authority, fn ($k) => $this->memberTransportJournal[$k] ?? null,
            function ($k, $v): void { $this->memberTransportJournal[$k] = $v; }, fn () => $this->retryClock->at);
        return [$authority, $bridge];
    }

    private function cliRequest(string $operation, string $role = 'control'): array
    {
        $seq = ++$this->cliSequence;
        return ['schema' => 2, 'run_id' => Authority::RUN, 'grant_id' => str_repeat('a', 32),
            'grant_sha256' => str_repeat('d', 64), 'bundle_sha256' => str_repeat('b', 64),
            'request_id' => substr(hash('sha256', (string) $seq), 0, 32), 'sequence' => $seq,
            'operation' => $operation, 'role' => $role];
    }

    private function cliAssertClosed(): void
    {
        foreach (Authority::closed() as $k => $v) $this->assertSame($v, config($k), $k);
    }

    private function memberCallbackFixture(): array
    {
        require_once base_path('tools/machine-acceptance/member_candidate_callbacks.php');
        [$authority, $bridge] = $this->cliFixture();
        foreach ([-12, -7, -2] as $offset) foreach ($this->fixtures as $index => $f) {
            $this->report($f, $f['source']->fresh(), $f['sourceIp'], $index === 1, time() + $offset);
            $bridge->dispatch($this->cliRequest('source-observe', $index === 0 ? 'source-a' : 'source-b'));
        }
        foreach (['source-a', 'source-b'] as $role) $bridge->dispatch($this->cliRequest('source-register', $role));
        $authority->within('round-step', 'control', fn () => $this->sourceReport($this->fixtures[0], false, time() - 1));
        $this->cliScope['driver']['plan_id'] = \App\Models\MachineFailoverPlan::query()
            ->where('policy_id', $this->fixtures[0]['policy']->id)->latest('id')->value('id');
        [, $bridge] = $this->cliBindings();
        foreach (['source-a', 'source-b', 'setup_cooldown', 'native', 'waiting_initialization'] as $phase) {
            $result = $bridge->dispatch($this->cliRequest('round-step'))['result'];
            $this->assertSame($phase, $result['native_phase']);
        }
        return $this->memberCallbackProjection();
    }

    private function memberCallbackProjection(): array
    {
        require_once base_path('tools/machine-acceptance/member_candidate_callbacks.php');
        $s = $this->cliScope; $d = $s['driver'];
        $setup = ['run_id' => Authority::RUN, 'started_at' => $s['started_at'], 'deadline_at' => $s['deadline_at'],
            'bundle_sha256' => $s['bundle_sha256'], 'machine_id_sha256' => $s['controller_machine_id_sha256'],
            'control' => $s['control'], 'binding' => [
                'scope' => array_intersect_key($d, array_flip(['admin_id', 'account_id', 'release_id', 'baseline_ids']))
                    + ['account_revision' => $s['sources']['source-a']['account_revision']],
                'configuration' => ['service_id' => $d['service_id'], 'service_revision' => $d['service_revision'],
                    'sources' => array_map(static fn ($p) => array_intersect_key($p, array_flip(['machine_id', 'policy_id'])), $s['sources'])]]];
        return [$setup, \Keli\Acceptance\MemberCandidateCallbacks::project($s, str_repeat('e', 64), $setup, str_repeat('f', 64))];
    }

    public function test_member_candidate_callbacks_use_native_lineage_with_no_create_dns_or_rotation_permission(): void
    {
        [, $cap] = $this->memberCallbackFixture(); $task = $this->task();
        $type = \Keli\Acceptance\MemberCandidateCallbacks::class;
        $this->assertSame('source-a', $type::role($cap, $task));
        $before = config('queue'); $original = app(Gate::class);
        config(['machine_provision.allow_live' => true]);
        try {
            $this->assertTrue($type::within($cap, $task, function (): void {
                $this->assertFalse(config('machine_failover.allow_replacement'));
                $this->assertFalse(config('machine_failover.allow_rotation'));
                $this->assertFalse(config('machine_acceptance.allow_dns'));
            }, function () use ($task) {
                foreach (['start', 'advance', 'queue', 'rotate', 'dns_write', 'dns_readback', 'candidate_retry'] as $operation) {
                    try { app(Gate::class)->assertAllowed($operation); $this->fail('Unexpected callback permission'); }
                    catch (\RuntimeException $error) { $this->assertSame('member_candidate_callback_operation_denied', $error->getMessage()); }
                }
                // Same dispatcher isolation used by the HTTP capability, no worker is started.
                $contract = \Illuminate\Contracts\Bus\Dispatcher::class; $bus = app($contract);
                app()->instance($contract, new \Illuminate\Bus\Dispatcher(app(), static fn () => new \Illuminate\Queue\NullQueue()));
                try { return app(\App\Services\ServerMachine\Cloud\MachineProvisionService::class)
                    ->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'); }
                finally { app()->instance($contract, $bus); }
            }));
        } finally { config(Authority::closed()); }
        $this->assertSame('succeeded', $task->fresh()->bootstrap_state);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame($original, app(Gate::class)); $this->assertSame($before, config('queue')); $this->cliAssertClosed();
    }

    public function test_member_candidate_callbacks_reject_other_members_lineage_drift_and_cleaned_attempts(): void
    {
        [, $cap] = $this->memberCallbackFixture(); $task = $this->task();
        $type = \Keli\Acceptance\MemberCandidateCallbacks::class;
        foreach (['policy_a', 'plan_id', 'service_id', 'admin_id', 'release_id'] as $key) {
            $changed = $cap; $changed['driver'][$key]++;
            try { $type::role($changed, $task); $this->fail('Unbound callback accepted'); }
            catch (\RuntimeException $error) { $this->assertStringStartsWith('member_candidate_callback_', $error->getMessage()); }
        }
        try { $type::role($cap, $this->fixtures[1]['source']->fresh()); $this->fail('B allowed as candidate'); }
        catch (\RuntimeException $error) { $this->assertSame('member_candidate_callback_task_outside_grant', $error->getMessage()); }
        $attempt = \App\Models\MachineFailoverAttempt::query()->findOrFail($task->payload['coordinated_attempt']);
        foreach (['cleaned', 'released', 'cancelled', 'failed'] as $status) {
            $attempt->status = $status; $attempt->save();
            try { $type::role($cap, $task); $this->fail('Retired candidate allowed'); }
            catch (\RuntimeException $error) { $this->assertSame('member_candidate_callback_candidate_inactive', $error->getMessage()); }
        }
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites); $this->cliAssertClosed();
    }

    public function test_member_candidate_grant_inherits_original_window_and_preserves_root_scope(): void
    {
        [$setup, $cap] = $this->memberCallbackFixture(); $type = \Keli\Acceptance\MemberCandidateCallbacks::class;
        $this->assertSame($setup['deadline_at'], $cap['deadline_at']);
        $this->assertSame($setup['started_at'], $cap['started_at']);
        foreach (['started_at', 'deadline_at'] as $key) {
            $s = $this->cliScope; $s[$key]++;
            try { $type::project($s, str_repeat('e', 64), $setup, str_repeat('f', 64)); $this->fail('Extended window'); }
            catch (\RuntimeException $error) { $this->assertSame('member_candidate_callback_window_or_bundle_changed', $error->getMessage()); }
        }
        $task = $this->task(); $ran = false;
        try {
            $type::within($cap, $task, static fn () => throw new \RuntimeException('revoked'), function () use (&$ran) { $ran = true; });
            $this->fail('Revoked guard accepted');
        } catch (\RuntimeException $error) { $this->assertSame('revoked', $error->getMessage()); }
        $this->assertFalse($ran); $this->cliAssertClosed();
        $gate = app(Gate::class); $native = app(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class);
        app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class, $native);
        try {
            $type::within($cap, $task, static fn () => null, static fn () => throw new \RuntimeException('handler_failed'));
            $this->fail('Callback exception lost');
        } catch (\RuntimeException $error) { $this->assertSame('handler_failed', $error->getMessage()); }
        $this->assertSame($gate, app(Gate::class));
        $this->assertSame($native, app(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class));
    }

    public function test_member_cli_temporarily_scopes_permissions_and_restores_on_exception(): void
    {
        [$authority] = $this->cliFixture();
        $original = app(Gate::class);
        $bus = app(\Illuminate\Contracts\Bus\Dispatcher::class); $queues = config('queue');
        try {
            $authority->within('round-step', 'control', function (): void {
                $this->assertTrue(config('machine_provision.allow_live'));
                $this->assertSame([$this->cliScope['driver']['policy_a']], config('machine_failover.allowed_candidate_retry_policy_ids'));
                $this->assertFalse(config('machine_failover.runner_enabled'));
                $this->assertFalse(config('machine_failover.allow_rotation'));
                app(Gate::class)->assertAllowed('rotate');
            });
            $this->fail('rotation must be denied');
        } catch (\Throwable $e) { $this->assertSame('member_cli_native_operation_denied', $e->getMessage()); }
        $this->cliAssertClosed(); $this->assertSame($original, app(Gate::class));
        $this->assertSame($bus, app(\Illuminate\Contracts\Bus\Dispatcher::class));
        $this->assertSame($queues, config('queue'));
        try { (new Gate())->assertAllowed('advance'); $this->fail(); }
        catch (\Throwable $e) { $this->assertSame('orchestration_disabled', $e->getMessage()); }
        $this->assertSame([], $this->posts);
    }

    public function test_member_cli_restores_transient_and_shared_binding_lifetimes(): void
    {
        [$authority] = $this->cliFixture();
        $busContract = \Illuminate\Contracts\Bus\Dispatcher::class;
        foreach ([false, true] as $shared) foreach ([false, true] as $throw) {
            app()->bind(Gate::class, fn () => new Gate(), $shared);
            app()->bind($busContract, fn () => new \Illuminate\Bus\Dispatcher(app()), $shared);
            $gate = app(Gate::class); $bus = app($busContract);
            try {
                $this->assertSame('completed', $authority->within('round-status', 'control', function () use ($throw) {
                    if ($throw) throw new \RuntimeException('fixture_lifetime_failure');
                    return 'completed';
                }));
                $this->assertFalse($throw);
            } catch (\RuntimeException $error) {
                $this->assertTrue($throw);
                $this->assertSame('fixture_lifetime_failure', $error->getMessage());
            }
            foreach ([Gate::class => $gate, $busContract => $bus] as $type => $original) {
                $this->assertSame($shared, app()->isShared($type));
                $this->assertSame(get_class($original), get_class(app($type)));
                if ($shared) $this->assertSame($original, app($type));
                else $this->assertNotSame($original, app($type));
            }
            $this->cliAssertClosed();
        }
        $this->assertSame([], $this->posts);
    }

    public function test_member_cli_rejects_wrong_environment_grant_and_changed_persistent_switches(): void
    {
        [$authority] = $this->cliFixture(); $before = $this->cliEnvironment;
        foreach ([['sapi' => 'fpm-fcgi'], ['machine_sha256' => str_repeat('e', 64)], ['database_host' => '192.0.2.1'],
            ['database_url' => 'mysql://outside'], ['database' => 'production'], ['cache_connection' => 'external'],
            ['redis_default' => ['host' => '127.0.0.1', 'read' => ['host' => 'outside']]],
            ['redis_cache' => ['host' => '127.0.0.1', 'url' => 'redis://outside']]] as $patch) {
            $this->cliEnvironment = array_replace($before, $patch);
            try { $authority->allow('round-ready'); $this->fail(); }
            catch (\Throwable $e) { $this->assertStringStartsWith('member_cli_isolated_', $e->getMessage()); }
        }
        $this->cliEnvironment = $before;
        config(['machine_failover.allow_rotation' => true]);
        try { $authority->allow('round-ready'); $this->fail(); }
        catch (\Throwable $e) { $this->assertSame('member_cli_persistent_gate_changed', $e->getMessage()); }
        config(Authority::closed());
        $this->cliScope['execution_authorized'] = false;
        try { $this->cliBindings(); $this->fail(); }
        catch (\Throwable $e) { $this->assertSame('member_cli_grant_required', $e->getMessage()); }
        $this->assertSame([], $this->posts);
    }

    public function test_member_cli_rpc_binds_grant_and_never_accepts_caller_commands_or_health(): void
    {
        [, $bridge] = $this->cliFixture();
        foreach ([['grant_id' => str_repeat('e', 32)], ['bundle_sha256' => str_repeat('e', 64)],
            ['healthy' => true], ['command' => 'id'], ['operation' => 'create'], ['role' => 'candidate-b1']] as $patch) {
            try { $bridge->dispatch(array_replace($this->cliRequest('round-ready'), $patch)); $this->fail(); }
            catch (\Throwable $e) { $this->assertMatchesRegularExpression('/^member_(cli|round)_/', $e->getMessage()); }
        }
        $this->assertTrue($bridge->dispatch($this->cliRequest('round-ready'))['result']['ready']);
        $this->cliAssertClosed(); $this->assertSame([], $this->posts);
    }

    public function test_member_cli_expired_or_stopped_round_can_only_read_and_clean_exact_roles(): void
    {
        [, $bridge] = $this->cliFixture();
        $this->retryClock->at = $this->cliScope['deadline_at'] + 1;
        try { $bridge->dispatch($this->cliRequest('round-step')); $this->fail(); }
        catch (\Throwable $e) { $this->assertSame('member_cli_execution_expired_or_stopped', $e->getMessage()); }
        try { $bridge->dispatch($this->cliRequest('task-delete', 'source-a')); $this->fail(); }
        catch (\Throwable $e) { $this->assertSame('member_cli_final_cleanup_required', $e->getMessage()); }
        $bridge->dispatch($this->cliRequest('round-stop'));
        $this->assertTrue($bridge->dispatch($this->cliRequest('round-status'))['result']['stopped']);
        $request = $this->cliRequest('task-delete', 'source-a');
        $reply = $bridge->dispatch($request);
        $this->assertSame($reply, $bridge->dispatch($request));
        $this->assertSame(1, $this->candidateDeletes);
        $this->assertArrayHasKey(902, $this->remote); $this->assertArrayHasKey(700, $this->remote);
        $this->assertTrue($bridge->dispatch($this->cliRequest('task-inspect', 'source-a'))['result']['absent']);
        $this->cliAssertClosed();
    }

    public function test_member_cli_uncertain_mutation_is_not_redispatched_and_stop_still_works(): void
    {
        [, $bridge] = $this->cliFixture();
        $request = $this->cliRequest('round-step');
        $this->memberTransportJournal['rpc-' . $request['request_id']] = ['request' => $request];
        $this->memberTransportJournal['rpc-mutation'] = ['sequence' => $request['sequence'], 'completed' => false];
        foreach ([$request, $this->cliRequest('round-step')] as $r) {
            try { $bridge->dispatch($r); $this->fail(); }
            catch (\Throwable $e) { $this->assertSame('member_round_uncertain_request_readback_required', $e->getMessage()); }
        }
        $this->assertTrue($bridge->dispatch($this->cliRequest('round-stop'))['result']['stopped']);
        $this->assertSame([], $this->posts); $this->cliAssertClosed();
    }

    public function test_member_cli_task_queue_cannot_outlive_capability_or_change_queue_configuration(): void
    {
        [$authority] = $this->cliFixture();
        $before = config('queue');
        $authority->within('round-step', 'control', function (): void {
            $bus = app(\Illuminate\Contracts\Bus\Dispatcher::class);
            $this->assertSame(\Illuminate\Bus\Dispatcher::class, get_class($bus));
            $resolver = new \ReflectionProperty($bus, 'queueResolver');
            $this->assertInstanceOf(\Illuminate\Queue\NullQueue::class, ($resolver->getValue($bus))('redis'));
            $this->assertNull($bus->dispatch(new \App\Jobs\MachineProvisionJob('not-a-real-task')));
        });
        $this->assertSame($before, config('queue')); $this->cliAssertClosed();
    }

    public function test_member_cli_round_waits_for_real_source_reports_and_registers_both(): void
    {
        [, $bridge] = $this->cliFixture();
        $this->assertSame('source-a', $bridge->dispatch($this->cliRequest('round-step'))['result']['native_phase']);
        $waiting = $bridge->dispatch($this->cliRequest('round-step'));
        $this->assertSame('source-a', $waiting['result']['native_phase'], json_encode($waiting));
        foreach ([-12, -7, -2] as $offset) foreach ($this->fixtures as $index => $f) {
            $this->report($f, $f['source']->fresh(), $f['sourceIp'], $index === 1, time() + $offset);
            $result = $bridge->dispatch($this->cliRequest('source-observe', $index === 0 ? 'source-a' : 'source-b'));
            $this->assertSame($offset === -2, $result['result']['ready']);
        }
        foreach (['source-b', 'setup_cooldown'] as $phase) {
            $r = $bridge->dispatch($this->cliRequest('round-step'));
            $this->assertSame($phase, $r['result']['native_phase'], json_encode($r));
        }
        $this->assertSame(2, \App\Models\MachineOwnedInstance::count());
        $this->assertSame([], $this->posts); $this->assertSame(0, $this->dnsWrites); $this->cliAssertClosed();
    }

    public function test_member_cli_native_a1_cleanup_cooldown_a2_dns_and_exact_final_teardown(): void
    {
        [$authority, $bridge] = $this->cliFixture();
        foreach ([-12, -7, -2] as $offset) foreach ($this->fixtures as $index => $f) {
            $this->report($f, $f['source']->fresh(), $f['sourceIp'], $index === 1, time() + $offset);
            $bridge->dispatch($this->cliRequest('source-observe', $index === 0 ? 'source-a' : 'source-b'));
        }
        foreach (['source-a', 'source-b'] as $role) $bridge->dispatch($this->cliRequest('source-register', $role));
        $authority->within('round-step', 'control', fn () => $this->sourceReport($this->fixtures[0], false, time() - 1));
        // The final grant pins the post-registration native plan, before the first round step.
        $this->cliScope['driver']['plan_id'] = \App\Models\MachineFailoverPlan::query()
            ->where('policy_id', $this->fixtures[0]['policy']->id)->latest('id')->value('id');
        [$authority, $bridge] = $this->cliBindings();
        $step = function () use ($bridge): array {
            $r = $bridge->dispatch($this->cliRequest('round-step'))['result'];
            $this->assertNull($r['first_error'], json_encode($this->memberTransportJournal['round-driver']));
            $this->cliAssertClosed();
            return $r;
        };
        $b = $this->fixtures[1]['source']->fresh()->toArray(); $bDns = $this->records['5002'];
        foreach (['source-a', 'source-b', 'setup_cooldown', 'native', 'waiting_initialization'] as $phase)
            $this->assertSame($phase, $step()['native_phase']);
        $first = $this->task(); $originalRequest = $first->payload['request'];
        [, $callbackCap] = $this->memberCallbackProjection();
        $this->assertSame('source-a', \Keli\Acceptance\MemberCandidateCallbacks::role($callbackCap, $first));
        $authority->within('round-step', 'control', fn () => app(\App\Services\ServerMachine\Cloud\MachineProvisionService::class)
            ->callback($first->id, $first->installation_id, $this->callbackSecret($first), 'succeeded'));
        $first->refresh()->fill(['dispatched_at' => time() - 30, 'status' => 'waiting_ipv4_probe', 'next_check_at' => time() + 60])->save();
        foreach ([-20, -15, -10] as $index => $delta) {
            $this->report($this->fixtures[0], $first, $first->public_ips[0], false, time() + $delta);
            $step(); $this->assertSame($index === 2 ? 1 : 0, $this->candidateDeletes);
        }
        $step(); $step();
        $this->assertCount(1, \App\Models\MachineFailoverAttempt::first()->payload['candidate_retry']['absent_at']);
        $this->retryClock->at += 10;
        $this->assertSame('candidate_retry_cooldown', $step()['native_phase']);
        $retryAt = \App\Models\MachineFailoverAttempt::first()->payload['candidate_retry']['retry_at'];
        $this->retryClock->at = $retryAt - 1; $step(); $this->assertCount(1, $this->posts);
        $authority->within('round-step', 'control', fn () => $this->finishCandidateCooldown());
        $this->retryClock->at = $retryAt;
        $this->assertSame('waiting_initialization', $step()['native_phase']);
        $this->assertCount(2, $this->posts);
        $task = $this->task();
        $this->assertSame('source-a', \Keli\Acceptance\MemberCandidateCallbacks::role($callbackCap, $task));
        try { \Keli\Acceptance\MemberCandidateCallbacks::role($callbackCap, $first->fresh()); $this->fail('Cleaned A1 admitted'); }
        catch (\RuntimeException $error) { $this->assertSame('member_candidate_callback_member_changed', $error->getMessage()); }
        $authority->within('round-step', 'control', fn () => app(\App\Services\ServerMachine\Cloud\MachineProvisionService::class)
            ->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        for ($i = 0; $i < 45; $i++) {
            $r = $step(); if ($r['phase'] === 'finished') break;
        }
        $this->assertSame('finished', $r['phase']);
        foreach (['native_cleanup', 'cooldown', 'retry', 'b_unchanged', 'dns_cutover'] as $key) $this->assertTrue($r['branches'][$key]);
        $this->assertSame($originalRequest, $first->fresh()->payload['request']);
        $this->assertSame($b, $this->fixtures[1]['source']->fresh()->toArray());
        $this->assertSame($bDns, $this->records['5002']); $this->assertSame(1, $this->dnsWrites);
        $bridge->dispatch($this->cliRequest('round-stop'));
        $status = $bridge->dispatch($this->cliRequest('round-status'))['result'];
        $this->assertCount(4, $status['roles']);
        foreach (['candidate-a2', 'candidate-a1', 'source-a', 'source-b'] as $role) {
            $bridge->dispatch($this->cliRequest('task-delete', $role));
            $this->assertTrue($bridge->dispatch($this->cliRequest('task-inspect', $role))['result']['absent']);
        }
        $this->assertSame(4, $this->candidateDeletes); $this->assertSame(0, $this->verifications);
        $this->assertArrayHasKey(700, $this->remote); $this->cliAssertClosed();
    }
}
