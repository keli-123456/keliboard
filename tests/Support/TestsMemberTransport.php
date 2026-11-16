<?php
namespace Tests\Support;

use App\Models\MachineCloudResource;
use App\Models\MachineOwnedInstance;
use Keli\Acceptance\MemberControllerBridge;
use Keli\Acceptance\MemberSourceRegistry;

trait TestsMemberTransport
{
    private array $memberTransportScope = [];
    private array $memberTransportJournal = [];
    private array $memberTransportFixtures = [];
    private bool $memberTransportJournalFails = false;

    private function memberTransportFixture(): array
    {
        require_once base_path('tools/machine-acceptance/member_source_registry.php');
        require_once base_path('tools/machine-acceptance/member_controller_bridge.php');
        $this->memberDriverFixture();
        $this->memberTransportFixtures = $this->fixtures;
        $scope = $this->memberDriverScope + ['binary_sha256' => $this->release->binary_sha256,
            'version' => $this->release->version, 'sources' => []];
        foreach ($this->fixtures as $index => $f) {
            $task = $f['source']->fresh(); $payload = $task->payload;
            $payload['request'] += ['region' => 'sg-sin-2', 'type' => 'g6-standard-4', 'image' => 'linode/debian12'];
            $task->fill(['payload' => $payload, 'status' => 'waiting_ipv4_probe', 'dispatched_at' => time() - 610])->save();
            $remote = &$this->remote[(int) $task->external_id];
            $remote['label'] = $payload['request']['label']; $remote['tags'] = [$task->marker];
            $remote['created'] = rtrim($task->provider_created_at, 'Z');
            $scope['sources']['source-' . ($index === 0 ? 'a' : 'b')] = $task->only(['machine_id', 'installation_id', 'external_id',
                'marker', 'account_revision', 'provider_created_at']) + ['task_id' => $task->id,
                'policy_id' => $f['policy']->id, 'policy_revision' => $f['policy']->revision,
                'configured_nodes' => [['id' => $f['machine']->id, 'protocol' => 'hysteria2', 'port' => 8443]]];
        }
        MachineOwnedInstance::query()->delete(); MachineCloudResource::query()->delete();
        $this->memberTransportScope = $scope;
        $guard = function (): void {
            $this->assertSame('sqlite', app('db')->connection()->getDriverName());
            $this->assertSame(':memory:', app('db')->connection()->getDatabaseName());
        };
        $load = fn ($key) => $this->memberTransportJournal[$key] ?? null;
        $save = function ($key, $value): void {
            if ($this->memberTransportJournalFails) throw new \RuntimeException('secret path');
            $this->memberTransportJournal[$key] = $value;
        };
        $registry = new MemberSourceRegistry($scope, $guard, $load, $save, fn () => time());
        return [$registry, new MemberControllerBridge($scope, $registry, $guard, $load, $save)];
    }

    private function memberTransportRequest(string $operation, string $role): array
    {
        return ['schema' => 1, 'run_id' => $this->memberTransportScope['run_id'],
            'request_id' => str_repeat('d', 32), 'operation' => $operation, 'role' => $role];
    }

    private function memberTransportReports(MemberControllerBridge $bridge): void
    {
        foreach ([-12, -7, -2] as $offset) foreach ($this->fixtures as $index => $f) {
            $this->report($f, $f['source']->fresh(), $f['sourceIp'], $index === 1, time() + $offset);
            $reply = $bridge->dispatch($this->memberTransportRequest('source-observe', $index === 0 ? 'source-a' : 'source-b'));
            $this->assertSame($offset === -2, $reply['result']['ready']);
        }
    }

    public function test_member_transport_registers_both_sources_with_real_receipts_and_distinct_reports(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        $this->memberTransportReports($bridge);
        foreach (['source-a', 'source-b'] as $role) {
            $request = $this->memberTransportRequest('source-register', $role);
            $result = $bridge->dispatch($request);
            $this->assertTrue($result['result']['registered']);
            $this->assertFalse($result['result']['health_or_forwarding_claimed']);
            $this->assertSame($result, $bridge->dispatch($request));
        }
        $this->assertSame(2, MachineOwnedInstance::count()); $this->assertSame(2, MachineCloudResource::count());
        $this->assertSame(0, MachineOwnedInstance::query()->whereNotNull('verification')->count());
        $this->assertSame(0, $this->dnsWrites); $this->assertSame([], $this->posts);
    }

    public function test_member_transport_duplicates_do_not_replace_three_fresh_reports(): void
    {
        [$registry] = $this->memberTransportFixture();
        $f = $this->fixtures[0]; $this->report($f, $f['source']->fresh(), $f['sourceIp'], false, time() - 2);
        for ($i = 0; $i < 4; $i++) $this->assertSame(1, $registry->observe('source-a')['fresh_reports']);
        $this->expectExceptionMessage('member_source_three_reports_required'); $registry->register('source-a');
    }

    public function test_member_transport_session_or_binary_change_resets_report_window(): void
    {
        [$registry, $bridge] = $this->memberTransportFixture(); $this->memberTransportReports($bridge);
        $f = $this->fixtures[0];
        app(\App\Services\ServerMachine\MachineAddressInventory::class)->record($f['machine'], $f['source']->installation_id,
            ['public_ipv4' => $f['sourceIp']], time(), ['version' => 'v0.0.1', 'binary_sha256' => str_repeat('f', 64),
            'observation_session' => str_repeat('a', 32), 'configured_nodes' => []]);
        $this->assertSame(0, $registry->observe('source-a')['fresh_reports']);
        $this->assertSame(0, MachineOwnedInstance::count());
    }

    public function test_member_transport_wrong_tag_or_cross_role_never_registers(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        $task = $this->fixtures[1]['source']->fresh(); $payload = $task->payload;
        $payload['request']['tags'][] = 'old-round'; $task->fill(['payload' => $payload])->save();
        $this->expectExceptionMessage('ownership_mismatch');
        $bridge->dispatch($this->memberTransportRequest('source-observe', 'source-b'));
    }

    public function test_member_transport_final_cleanup_is_exact_id_and_delete_once(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        $beforeB = $this->remote[902];
        $request = $this->memberTransportRequest('task-delete', 'source-a');
        $this->assertTrue($bridge->dispatch($request)['result']['delete_dispatched']);
        $this->assertFalse($bridge->dispatch($request)['result']['delete_dispatched']);
        $this->assertTrue($bridge->dispatch($this->memberTransportRequest('task-inspect', 'source-a'))['result']['absent']);
        $this->assertSame(1, $this->candidateDeletes); $this->assertSame($beforeB, $this->remote[902]);
        $this->assertArrayHasKey(700, $this->remote);
    }

    public function test_member_transport_existing_delete_intent_only_reconciles(): void
    {
        [, $bridge] = $this->memberTransportFixture(); $task = $this->fixtures[0]['source']->fresh();
        $this->memberTransportJournal['delete-source-a'] = ['task_id' => $task->id, 'external_id' => $task->external_id, 'marker' => $task->marker];
        $reply = $bridge->dispatch($this->memberTransportRequest('task-delete', 'source-a'));
        $this->assertTrue($reply['result']['reconciliation_required']); $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_transport_journal_failure_prevents_delete(): void
    {
        [, $bridge] = $this->memberTransportFixture(); $this->memberTransportJournalFails = true;
        try { $bridge->dispatch($this->memberTransportRequest('task-delete', 'source-a')); $this->fail(); }
        catch (\RuntimeException $e) { $this->assertSame('secret path', $e->getMessage()); }
        $this->assertSame(0, $this->candidateDeletes); $this->assertArrayHasKey(901, $this->remote);
    }

    public function test_member_transport_unknown_read_is_not_absence(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        $this->duringInstanceRead = fn () => throw new \Illuminate\Http\Client\ConnectionException('private transport error');
        $this->expectException(\Throwable::class);
        $bridge->dispatch($this->memberTransportRequest('task-inspect', 'source-a'));
    }

    public function test_member_transport_rpc_cannot_choose_another_run_or_pass_commands(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        foreach ([['run_id' => 'keli-accept-other-run'], ['operation' => 'create'], ['shell' => 'id'], ['role' => 'control']] as $patch) {
            try { $bridge->dispatch(array_replace($this->memberTransportRequest('task-inspect', 'source-a'), $patch)); $this->fail(); }
            catch (\RuntimeException $e) { $this->assertSame('member_rpc_scope_invalid', $e->getMessage()); }
        }
        $this->assertSame(0, $this->candidateDeletes);
    }

    public function test_member_transport_registration_composes_with_native_a1_cooldown_a2_and_b_guard(): void
    {
        [, $bridge] = $this->memberTransportFixture();
        $this->memberTransportReports($bridge);
        $originalPort = $this->memberDriverPort;
        $this->memberDriverPort = function ($operation, $input) use ($bridge, $originalPort) {
            if ($operation === 'prepare_role' && $input['role'] !== 'control') {
                $reply = $bridge->dispatch($this->memberTransportRequest('source-register', $input['role']));
                return ['task_id' => $reply['result']['task_id']];
            }
            return $originalPort($operation, $input);
        };
        $driver = $this->memberDriver();
        for ($i = 0; $i < 3; $i++) $this->assertNull($driver->step()['first_error']);
        $b = $this->fixtures[1]['source']->fresh()->toArray(); $bDns = $this->records['5002'];
        // Registration grants identity only. Collect the native observer's current failure
        // episode after both sources exist; the production planner still owns eligibility.
        $this->sourceReport($this->fixtures[0], false, time() - 1);
        $this->memberDriverScope['plan_id'] = \App\Models\MachineFailoverPlan::query()
            ->where('policy_id', $this->fixtures[0]['policy']->id)->latest('id')->value('id');
        // No operation has been dispatched by this setup-only driver; rebuild its scope
        // to pin the now-available native plan, retaining the idempotent registrations.
        $driver = $this->memberDriver();
        foreach (['source-a', 'source-b', 'setup_cooldown', 'native', 'waiting_initialization'] as $phase)
            $this->assertSame($phase, $driver->step()['phase'], json_encode($driver->state()));
        $this->memberDriverFailCandidate($driver);
        $this->memberDriverConfirm($driver);
        $this->memberDriverFinishCooldown($driver);
        $this->assertTrue($driver->state()['branches']['native_cleanup']);
        $this->assertTrue($driver->state()['branches']['cooldown']);
        $this->assertTrue($driver->state()['branches']['retry']);
        $this->assertSame($b, $this->fixtures[1]['source']->fresh()->toArray());
        $this->assertSame($bDns, $this->records['5002']);
        $this->assertCount(2, $this->posts); $this->assertSame(1, $this->candidateDeletes);

        // Finish the same registered-source run, including DNS, using application services.
        $task = $this->task();
        app(\App\Services\ServerMachine\Cloud\MachineProvisionService::class)->callback(
            $task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded');
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        for ($i = 0; $i < 45 && $driver->state()['phase'] !== 'cleanup'; $i++) $driver->step();
        $this->assertSame('cleanup', $driver->state()['phase'], json_encode($driver->state()));
        $this->assertNull($driver->state()['first_error']);
        $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($bDns, $this->records['5002']);
        $this->assertSame($b, $this->fixtures[1]['source']->fresh()->toArray());

        $scope = $this->memberTransportScope;
        foreach ($driver->state()['roles'] as $role => $pin) {
            if ($role === 'control') continue;
            $task = \App\Models\MachineProvisionTask::query()->findOrFail($pin['task_id']);
            $scope['tasks'][$role] = $task->only(['external_id', 'installation_id', 'account_revision',
                'provider_created_at', 'marker', 'machine_id']) + ['task_id' => $task->id];
        }
        $guard = $this->memberDriverGuard;
        $load = fn ($key) => $this->memberTransportJournal[$key] ?? null;
        $save = function ($key, $value): void { $this->memberTransportJournal[$key] = $value; };
        $registry = new MemberSourceRegistry($scope, $guard, $load, $save, fn () => time());
        $bridge = new MemberControllerBridge($scope, $registry, $guard, $load, $save);
        foreach (['candidate-a2', 'candidate-a1', 'source-a', 'source-b'] as $role) {
            $bridge->dispatch($this->memberTransportRequest('task-delete', $role));
            $this->assertTrue($bridge->dispatch($this->memberTransportRequest('task-inspect', $role))['result']['absent']);
        }
        $this->assertSame(4, $this->candidateDeletes); // A1 native + A2 + both source tasks.
        $this->assertArrayHasKey(700, $this->remote);
        $this->assertSame(0, $this->verifications); // HY2 specialist acceptance is not inferred.
    }
}
