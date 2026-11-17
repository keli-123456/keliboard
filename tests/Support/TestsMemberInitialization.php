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
    private bool $initializationJournalFails = false;

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
                if ($this->initializationStopped && !in_array($operation, ['source-readback', 'source-cleanup'], true)) throw new \RuntimeException('setup_stopped');
                if ($operation === 'source-cleanup' && !$this->initializationStopped) throw new \RuntimeException('stop_required');
                return $callback();
            }, fn ($key) => $this->initializationJournal[$key] ?? null,
            function ($key, $value): void {
                if ($this->initializationJournalFails) throw new \RuntimeException('journal_unavailable');
                $this->initializationJournal[$key] = $value;
            });
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

    public function test_member_initialization_cleanup_requires_stop_and_keeps_peer_and_task_history(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        try { $api->cleanup('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('stop_required', $e->getMessage()); }
        $this->initializationStopped = true;
        $before = Task::orderBy('id')->get()->toArray(); $peer = $this->remote[902];
        $this->assertSame('delete_dispatched', $api->cleanup('source-a')['classification']);
        $first = $api->cleanup('source-a'); $this->assertFalse($first['complete']);
        $this->assertSame('exact_absence', $first['classification']);
        $this->assertFalse($api->cleanup('source-a')['complete']);
        // Unit fixture only; the full Linux application test waits real elapsed time.
        $this->initializationJournal['cleanup-source-a']['absence_at'] = time() - 11;
        $this->assertTrue($this->initializationBindings()->cleanup('source-a')['complete']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertSame($peer, $this->remote[902]);
        $this->assertArrayHasKey(700, $this->remote); $this->assertSame([], $this->posts);
        $this->assertSame($before, Task::orderBy('id')->get()->toArray());
    }

    public function test_member_resource_pins_are_read_only_and_include_partial_bootstrap(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->fixtures[0]['source']->refresh()->fill(['bootstrap_state' => 'failed'])->save();
        $before = Task::orderBy('id')->get()->toArray(); $journal = $this->initializationJournal;
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \RuntimeException('unexpected_provider_call'));
        $this->assertSame(['source-a' => ['task_id' => $this->fixtures[0]['source']->id, 'external_id' => '901'],
            'source-b' => ['task_id' => $this->fixtures[1]['source']->id, 'external_id' => '902']], $api->resourcePins());
        $this->assertSame($before, Task::orderBy('id')->get()->toArray());
        $this->assertSame($journal, $this->initializationJournal);
        $this->assertSame(0, $this->candidateDeletes); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_resource_pins_do_not_turn_lost_create_ack_into_absence(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->fixtures[0]['source']->refresh()->fill(['external_id' => null, 'provider_created_at' => null, 'public_ips' => []])->save();
        $this->expectExceptionMessage('create_receipt_unknown'); $api->resourcePins();
    }

    public function test_member_resource_pins_refuse_missing_or_unowned_task(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        unset($this->initializationJournal['source-queue-source-a']);
        try { $api->resourcePins(); $this->fail(); }
        catch (\RuntimeException $error) { $this->assertSame('member_initialization_queue_intent_missing', $error->getMessage()); }
        $this->bindPreparedSourceTasks($api); $this->fixtures[0]['source']->delete();
        $this->expectExceptionMessage('queue_readback_required'); $api->resourcePins();
    }

    public function test_member_resource_pins_distinguish_never_queued_and_never_dispatched(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->fixtures[0]['source']->refresh()->fill(['dispatched_at' => null, 'external_id' => null,
            'provider_created_at' => null, 'public_ips' => []])->save();
        $this->fixtures[1]['source']->delete(); unset($this->initializationJournal['source-queue-source-b']);
        $this->assertSame([], $api->resourcePins());
        $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_initialization_partial_cleanup_needs_no_registered_peer(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true;
        $this->fixtures[1]['source']->delete(); unset($this->remote[902]);
        unset($this->initializationJournal['source-queue-source-b']);
        $this->assertSame(['complete' => true, 'classification' => 'never_queued'], $api->cleanup('source-b'));
        $this->assertSame('delete_dispatched', $api->cleanup('source-a')['classification']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_member_initialization_undispatched_cleanup_never_calls_provider(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true;
        $this->fixtures[0]['source']->refresh()->fill(['dispatched_at' => null, 'external_id' => null,
            'provider_created_at' => null, 'public_ips' => []])->save();
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \RuntimeException('unexpected_provider_call'));
        $reply = $api->cleanup('source-a');
        $this->assertTrue($reply['complete']); $this->assertSame('never_dispatched', $reply['classification']);
        $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_initialization_missing_task_after_queue_intent_is_not_absence(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true; $this->fixtures[0]['source']->delete();
        $this->expectExceptionMessage('queue_readback_required'); $api->cleanup('source-a');
    }

    public function test_member_initialization_lost_create_reply_uses_exact_native_reconcile_without_task_rewrite(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true;
        $task = $this->fixtures[0]['source']->refresh();
        $task->fill(['external_id' => null, 'provider_created_at' => null, 'public_ips' => []])->save();
        $before = $task->fresh()->getAttributes();
        $this->assertSame('delete_dispatched', $api->cleanup('source-a')['classification']);
        $this->assertSame($before, $task->fresh()->getAttributes());
        $this->assertSame('901', $this->initializationJournal['cleanup-source-a']['receipt']['external_id']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertSame([], $this->posts);
    }

    public function test_member_initialization_empty_inventory_cannot_resolve_lost_create_reply(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true;
        $this->fixtures[0]['source']->refresh()->fill(['external_id' => null, 'provider_created_at' => null, 'public_ips' => []])->save();
        unset($this->remote[901]);
        $this->assertSame(['complete' => false, 'classification' => 'create_receipt_unknown'], $api->cleanup('source-a'));
        $this->assertSame(0, $this->candidateDeletes); $this->assertSame([], $this->posts);
    }

    public function test_member_initialization_uncertain_delete_is_read_only_on_resume(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true; $remote = $this->remote[901];
        $api->cleanup('source-a'); $this->remote[901] = $remote;
        $this->assertSame(['complete' => false, 'classification' => 'delete_readback_required'],
            $this->initializationBindings()->cleanup('source-a'));
        $this->assertSame(1, $this->candidateDeletes); $this->assertSame([], $this->posts);
    }

    public function test_member_initialization_cleanup_journal_failure_or_scope_change_never_deletes(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api);
        $this->initializationStopped = true; $this->initializationJournalFails = true;
        try { $api->cleanup('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('journal_unavailable', $e->getMessage()); }
        $this->initializationJournalFails = false;
        $this->initializationScope['baseline_ids'][] = '901';
        try { $this->initializationBindings()->cleanup('source-a'); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('member_initialization_intent_changed', $e->getMessage()); }
        $this->assertSame(0, $this->candidateDeletes); $this->assertSame([], $this->posts);
    }

    public function test_member_initialization_interrupted_read_resets_absence_proof(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api); $this->initializationStopped = true;
        $api->cleanup('source-a'); $api->cleanup('source-a');
        $this->initializationJournal['cleanup-source-a']['absence_at'] = time() - 11;
        $this->initializationJournal['cleanup-source-a']['read_pending'] = true;
        $this->assertFalse($api->cleanup('source-a')['complete']);
        $this->assertSame(1, $this->candidateDeletes);
    }

    public function test_member_initialization_read_failure_resets_previous_absence_proof(): void
    {
        $api = $this->initializationFixture(); $this->bindPreparedSourceTasks($api); $this->initializationStopped = true;
        $api->cleanup('source-a'); $api->cleanup('source-a');
        $this->initializationJournal['cleanup-source-a']['absence_at'] = time() - 11;
        $this->duringInstanceRead = fn () => \Illuminate\Support\Facades\Http::response(['errors' => [['reason' => 'fixture']]], 503);
        try { $api->cleanup('source-a'); $this->fail(); }
        catch (\App\Services\ServerMachine\Cloud\CloudRequestFailure) { }
        $this->assertNull($this->initializationJournal['cleanup-source-a']['absence_at']);
        $this->duringInstanceRead = null;
        $this->assertFalse($api->cleanup('source-a')['complete']); $this->assertSame(1, $this->candidateDeletes);
    }
}
