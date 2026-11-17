<?php
namespace Tests\Support;

use App\Models\MachineProvisionTask as Task;
use Keli\Acceptance\MemberSourceInitialization as Initialization;
use Keli\Acceptance\MemberProviderBindings as Provider;

trait TestsMemberInitialization
{
    private array $initializationScope = [];
    private array $initializationJournal = [];
    private bool $initializationStopped = false;

    private function initializationFixture(): Initialization
    {
        require_once base_path('tools/machine-acceptance/member_provider_bindings.php');
        require_once base_path('tools/machine-acceptance/member_source_initialization.php');
        $this->memberTransportFixture();
        $this->initializationScope = ['run_id' => Provider::RUN, 'admin_id' => $this->admin->id, 'release_id' => $this->release->id,
            'baseline_ids' => ['700'], 'sources' => []];
        foreach ($this->fixtures as $i => $f) {
            $role = $i ? 'source-b' : 'source-a';
            $pin = $this->memberTransportScope['sources'][$role];
            $this->initializationScope['sources'][$role] = ['plan' => $f['source']->fresh()->payload['plan'],
                'policy_id' => $pin['policy_id'], 'policy_revision' => $pin['policy_revision'],
                'configured_nodes' => $pin['configured_nodes']];
        }
        return $this->initializationBindings();
    }

    private function initializationBindings(): Initialization
    {
        return new Initialization($this->initializationScope,
            function ($operation, $role, $callback) {
                $this->assertTrue(app()->runningUnitTests());
                $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
                if ($this->initializationStopped && $operation !== 'source-readback') throw new \RuntimeException('setup_stopped');
                return $callback();
            }, fn ($key) => $this->initializationJournal[$key] ?? null,
            function ($key, $value): void { $this->initializationJournal[$key] = $value; });
    }

    private function bindPreparedSourceTasks(Initialization $api): void
    {
        foreach ($this->fixtures as $i => $f) {
            $role = $i ? 'source-b' : 'source-a';
            $request = $api->requestId($role);
            $f['source']->refresh()->fill(['request_key' => hash('sha256', $this->admin->id . ':' . $request)])->save();
            $this->initializationJournal['source-queue-' . $role] = ['request_id' => $request,
                'scope_sha256' => Provider::digest($this->initializationScope)];
        }
    }

    public function test_member_initialization_binds_both_original_tasks_and_native_source_registry(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $labels = [];
        foreach (['source-a', 'source-b'] as $role) {
            $r = $api->prepare($role); $labels[] = $r['native_label'];
            $this->assertFalse($r['health_or_forwarding_claimed']);
            $this->assertSame($r, $this->initializationBindings()->prepare($role));
        }
        $this->assertNotSame($labels[0], $labels[1]);
        foreach ($labels as $label) $this->assertMatchesRegularExpression('/^keli-[a-f0-9]{32}$/D', $label);
        $base = $this->memberTransportScope; $base['run_id'] = Provider::RUN;
        $scope = $api->registeredScope($base);
        $registry = new \Keli\Acceptance\MemberSourceRegistry($scope, fn () => null,
            fn ($k) => $this->memberTransportJournal[$k] ?? null,
            function ($k, $v): void { $this->memberTransportJournal[$k] = $v; }, fn () => time());
        foreach ([-12, -7, -2] as $offset) foreach ($this->fixtures as $i => $f) {
            $this->report($f, $f['source']->fresh(), $f['sourceIp'], $i === 1, time() + $offset);
            $this->assertSame($offset === -2, $registry->observe($i ? 'source-b' : 'source-a')['ready']);
        }
        $this->assertTrue($registry->register('source-a')['registered']);
        $this->assertTrue($registry->register('source-b')['registered']);
        $this->assertSame([], $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_initialization_queue_calls_real_service_without_changing_standard_label(): void
    {
        $api = $this->initializationFixture();
        // Remove only this synthetic fixture's source A; B remains as audit history.
        $this->fixtures[0]['source']->delete();
        $this->fixtures[1]['source']->refresh()->fill(['status' => 'accepted'])->save();
        config(['machine_acceptance.allow_verification' => true,
            'machine_failover.allow_replacement' => false, 'machine_acceptance.allow_dns' => false,
            'machine_acceptance.allowed_policy_ids' => array_column($this->initializationScope['sources'], 'policy_id')]);
        $bus = app(\Illuminate\Contracts\Bus\Dispatcher::class); $queues = config('queue');
        $reply = $api->prepare('source-a');
        $this->assertSame('queued', $reply['status']);
        $task = Task::findOrFail($reply['task_id']);
        $this->assertSame('keli-' . str_replace('-', '', $task->id), $reply['native_label']);
        $this->assertSame([$task->marker], $task->payload['request']['tags']);
        $this->assertSame($reply, $api->prepare('source-a'));
        $this->assertCount(1, $this->initializationJournal);
        $this->assertSame([], $this->posts);
        $this->assertSame($bus, app(\Illuminate\Contracts\Bus\Dispatcher::class));
        $this->assertSame($queues, config('queue'));
    }

    public function test_member_initialization_lost_queue_reply_without_task_never_requeues(): void
    {
        $api = $this->initializationFixture();
        $this->initializationJournal['source-queue-source-a'] = ['request_id' => $api->requestId('source-a'),
            'scope_sha256' => Provider::digest($this->initializationScope)];
        $before = Task::count();
        $bus = app(\Illuminate\Contracts\Bus\Dispatcher::class);
        try { $api->prepare('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_initialization_queue_readback_required', $e->getMessage()); }
        $this->assertSame($before, Task::count()); $this->assertSame([], $this->posts);
        $this->assertSame($bus, app(\Illuminate\Contracts\Bus\Dispatcher::class));
    }

    public function test_member_initialization_changed_scope_or_task_cannot_be_adopted(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationScope['sources']['source-a']['plan']['image'] = 'linode/ubuntu24.04';
        try { $this->initializationBindings()->prepare('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_initialization_intent_changed', $e->getMessage()); }
        $this->assertSame([], $this->posts);
    }

    public function test_member_initialization_stop_allows_readback_but_not_new_source_work(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true;
        try { $api->prepare('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('setup_stopped', $e->getMessage()); }
        $before = $this->fixtures[1]['source']->fresh()->getAttributes();
        $receipt = $api->process('source-a', true);
        $this->assertSame($this->fixtures[0]['source']->id, $receipt['task_id']);
        $this->assertSame([], $this->posts);
        $this->assertSame($before, $this->fixtures[1]['source']->fresh()->getAttributes());
    }

    public function test_member_initialization_requires_actual_bootstrap_callback_before_pin_registration(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->fixtures[0]['source']->fill(['bootstrap_state' => 'pending'])->save();
        $base = $this->memberTransportScope; $base['run_id'] = Provider::RUN;
        $this->expectExceptionMessage('member_initialization_source_not_ready');
        $api->registeredScope($base);
    }

    public function test_member_initialization_serial_sources_respect_one_slot_and_real_cooldown_rule(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $b = $this->fixtures[1]; $b['source']->delete(); unset($this->remote[902]);
        unset($this->initializationJournal['source-queue-source-b']);
        // Simulate the time of a fresh A creation. Only this in-memory fixture's
        // clock evidence moves; production advance() always reads real time/history.
        $a = $this->fixtures[0]; $a['source']->refresh()->forceFill(['created_at' => time()])->save();
        config(['machine_acceptance.allow_verification' => true,
            'machine_failover.allow_replacement' => false, 'machine_acceptance.allow_dns' => false,
            'machine_acceptance.allowed_policy_ids' => array_column($this->initializationScope['sources'], 'policy_id')]);
        $base = $this->memberTransportScope; $base['run_id'] = Provider::RUN;
        foreach ([-12, -7, -2] as $offset) {
            $this->report($a, $a['source']->fresh(), $a['sourceIp'], false, time() + $offset);
            $state = $api->advance($base);
        }
        $this->assertSame('registered', $state['reason']);
        $this->assertSame('accepted', $a['source']->fresh()->status);
        $this->assertSame('account_cooldown', $api->advance($base)['reason']);
        $this->assertSame(1, Task::count()); $this->assertSame([], $this->posts);
        $a['source']->refresh()->forceFill(['created_at' => time() - 301])->save();
        $aBefore = $a['source']->fresh()->getAttributes();
        $this->assertSame('prepare', $api->advance($base)['reason']);
        $this->assertSame(2, Task::count());
        $this->assertSame('process', $api->advance($base)['reason']);
        $task = Task::query()->where('machine_id', $b['machine']->id)->firstOrFail();
        $this->assertCount(1, $this->posts);
        $beforeWait = $task->getAttributes();
        $waiting = $api->advance($base);
        $this->assertSame('native_wait', $waiting['reason']);
        $this->assertSame($task->next_check_at, $waiting['retry_at']);
        $this->assertSame($beforeWait, $task->fresh()->getAttributes());
        $this->assertCount(1, $this->posts);
        app(\App\Services\ServerMachine\Cloud\MachineProvisionService::class)->callback($task->id,
            $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 30])->save();
        $task->fill(['next_check_at' => 0])->save();
        $this->report($b, $task->fresh(), $task->public_ips[0], true, time() - 17);
        $this->assertSame('process', $api->advance($base)['reason']);
        foreach ([-12, -7, -2] as $offset) {
            $this->report($b, $task->fresh(), $task->public_ips[0], true, time() + $offset);
            $state = $api->advance($base);
        }
        $this->assertSame('registered', $state['reason']);
        $this->assertTrue($api->advance($base)['ready']);
        $pins = $api->registeredScope($base)['sources'];
        $this->assertNotSame($pins['source-a']['external_id'], $pins['source-b']['external_id']);
        $this->assertNotSame($pins['source-a']['installation_id'], $pins['source-b']['installation_id']);
        $this->assertSame($aBefore, $a['source']->fresh()->getAttributes());
        $this->assertSame(0, $this->dnsWrites);
        $this->assertCount(1, $this->posts);
    }
}
