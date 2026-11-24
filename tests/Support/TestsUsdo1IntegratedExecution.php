<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\MachineFailoverRunJob;
use App\Jobs\MachineProvisionJob;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsSetRuntime;
use App\Models\MachineFailoverDnsRun;
use App\Models\MachineFailoverAttempt;
use App\Models\MachineFailoverExecutionMember;
use App\Models\MachineFailoverPlan;
use App\Models\MachineFailoverRuntime;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\ServerMachine;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverExistingSource;
use App\Services\ServerMachine\Cloud\MachineFailoverHandover;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineIntentJson as Json;
use App\Services\ServerMachine\Cloud\MachineUsdo1HandoverScope as Scope;
use App\Services\ServerMachine\Cloud\MachineUsdo1LaunchScope;
use App\Services\ServerMachine\Cloud\MachineUsdo1Runtime;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Keli\Acceptance\Usdo1IsolatedExecution;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;

require_once dirname(__DIR__, 2) . '/tools/machine-acceptance/usdo1_isolated_execution.php';

trait TestsUsdo1IntegratedExecution
{
    public static function usdo1PackageCases(): array
    {
        return ['success' => ['success'], 'lost create and DNS receipts' => ['lost_receipts'],
            'operator stop' => ['stop'], 'deadline before dispatch' => ['timeout'],
            'deadline with unknown create' => ['unknown_at_timeout'], 'competing DNS change' => ['dns_drift'],
            'dedicated worker stops after verified DNS' => ['success', true],
            'dedicated worker stops after unknown DNS receipt' => ['lost_receipts', true]];
    }

    protected function prepareUsdo1Package(string $mode): array
    {
        app()->instance('env', 'testing');
        $this->runtimeSchema();
        foreach (['2026_11_09_180000_create_machine_failover_dns_sets.php', '2026_11_09_200000_create_machine_dns_set_runtime.php',
            '2026_11_10_010000_create_machine_native_address_proofs.php', '2026_11_18_000000_create_machine_failover_handover_grants.php',
            '2026_11_18_010000_add_machine_failover_handover_receipts.php'] as $migration)
            (require base_path('database/migrations/' . $migration))->up();
        HandoverEvidenceFixture::trust();
        config(['app.key' => str_repeat('n', 32), 'machine_failover.observe_enabled' => false]);
        $f = $this->fixtures[0];
        MachineOwnedDns::query()->where('policy_id', $f['policy']->id)->delete();
        MachineOwnedInstance::query()->where('task_id', $f['source']->id)->delete();
        $f['source']->delete();
        DB::table('v2_server_machine')->where('id', $f['machine']->id)->update(['id' => 17]);
        DB::table('v2_server')->where('machine_id', $f['machine']->id)->update(['id' => 17, 'machine_id' => 17]);
        $f['machine'] = ServerMachine::query()->findOrFail(17);
        $installation = str_repeat('7', 32);
        $source = ['machine_id' => 17, 'external_id' => '917', 'installation_id' => $installation,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400), 'ipv4' => $f['sourceIp']];
        $resource = MachineCloudResource::query()->create(['account_id' => $f['accounts'][0]->id, 'provider' => 'linode',
            'external_id' => $source['external_id'], 'provider_created_at' => $source['created_at'], 'public_ips' => [$source['ipv4']],
            'status' => 'running', 'label' => 'isolated-source-fixture', 'region' => $f['policy']->region, 'size' => $f['policy']->size,
            'machine_id' => 17, 'installation_id' => $installation, 'machine_authority' => hash('sha256', $f['machine']->token), 'observed_at' => time()]);
        $this->remote[917] = ['id' => 917, 'label' => $resource->label, 'region' => $resource->region, 'type' => $resource->size,
            'created' => $resource->provider_created_at, 'status' => 'running', 'ipv4' => [$source['ipv4']]];
        $key = hash_hmac('sha256', json_encode([17, $resource->id, $f['accounts'][0]->id, $f['accounts'][0]->revision,
            'linode', '917', $source['created_at'], $resource->region, $resource->size, $installation, [$source['ipv4']]], JSON_THROW_ON_ERROR), $f['machine']->token);
        $f['policy']->fill(['machine_id' => 17, 'slot' => MachineFailoverExistingSource::slot($key), 'isolation_confirmed' => false,
            'dns_credential_id' => null, 'dns_credential_revision' => null, 'dns_hostname' => '', 'dns_scope' => null])->save();
        $old = new \App\Models\MachineProvisionTask(['installation_id' => $installation]);
        $t = time();
        $this->report($f, $old, $source['ipv4'], true, $t - 30);
        $admin = $this->admin->id;
        $registry = app(MachineFailoverServiceRegistry::class);
        $service = $registry->save(['name' => '123', 'max_parallel' => 1, 'attempt_limit' => 1,
            'members' => [['policy_id' => $f['policy']->id, 'policy_revision' => $f['policy']->revision,
                'name' => 'isolated source', 'priority' => 1, 'enabled' => true]]], $admin);
        $credential = MachineDnsCredential::query()->create(['name' => 'Isolated DNS', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture', 'access_key_secret' => 'synthetic-only']]);
        $set = app(\App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry::class)->save([
            'service_id' => $service['id'], 'service_revision' => $service['revision'], 'credential_id' => $credential->id,
            'credential_revision' => 1, 'zone' => Scope::ZONE, 'zone_name' => Scope::ZONE, 'hostname' => Scope::NAME, 'ttl' => 600,
            'members' => [['member_id' => $service['members'][0]['id'], 'types' => ['A'], 'lines' => ['default']]],
        ], $admin);
        $baseline = ['RecordId' => '9123', 'DomainName' => Scope::ZONE, 'RR' => 'usdo1', 'Type' => 'A', 'Line' => 'default',
            'Value' => $source['ipv4'], 'TTL' => 600, 'Remark' => 'original fixture remark', 'Status' => 'Enable', 'Locked' => false];
        $record = $baseline; $writes = []; $resolver = false;
        DB::statement('CREATE TABLE usdo1_test_journal (scope_key VARCHAR(80) PRIMARY KEY, payload TEXT NOT NULL)');
        $load = fn ($key) => ($raw = DB::table('usdo1_test_journal')->where('scope_key', $key)->value('payload')) === null
            ? null : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $save = fn ($key, $state) => DB::table('usdo1_test_journal')->updateOrInsert(['scope_key' => $key], ['payload' => Json::encode($state)]);
        $send = function (string $lane, array $wire) use (&$record, &$writes, $mode): array {
            self::assertSame(0, DB::transactionLevel());
            $p = $wire['parameters'];
            self::assertSame('9123', $p['RecordId']);
            $writes[] = [$lane, $wire['action']];
            foreach (['RR','Type','Line','Value','TTL','Remark'] as $attribute) if (array_key_exists($attribute, $p)) $record[$attribute] = $p[$attribute];
            if ($mode === 'lost_receipts' && $lane === 'forward' && $wire['action'] === 'UpdateDomainRecord')
                throw new \RuntimeException('synthetic lost DNS acknowledgement');
            return ['RequestId' => 'fixture', 'RecordId' => '9123'];
        };
        $read = function ($name, $id) use (&$record): array { self::assertSame([Scope::NAME,'9123'], [$name,$id]); return [$record]; };
        $readResolvers = function () use (&$resolver): bool { return $resolver; };
        $make = fn () => new Usdo1IsolatedExecution($admin, $this->release->id, $source, $baseline, $read,
            fn ($wire) => $send('forward', $wire), fn ($wire) => $send('rollback', $wire), $load, $save,
            fn ($pin) => Json::same($pin, $source), $readResolvers, fn () => now()->timestamp);
        $package = $make();
        $review = $package->sourceReview($set['service_revision']);
        $intake = $package->admit($set['service_revision'], HandoverEvidenceFixture::sourceAdmission($review));
        self::assertSame([], $this->posts); self::assertSame([], $writes);
        $sourceInstance = MachineOwnedInstance::query()->findOrFail($intake['instance_id']);
        $f['source'] = \App\Models\MachineProvisionTask::query()->findOrFail($sourceInstance->task_id);
        $f['policy']->refresh();
        foreach ([-20 => true, -15 => false, -10 => false] as $offset => $healthy) {
            $this->report($f, $f['source'], $source['ipv4'], $healthy, $t + $offset);
            $package->observeSource($intake['service_revision']);
        }
        $plan = MachineFailoverPlan::query()->where('policy_id', $f['policy']->id)->latest('id')->firstOrFail();
        $scope = ['service_id' => 1, 'service_revision' => $intake['service_revision'],
            'context_hash' => $registry->fingerprint($registry->snapshot(1, $intake['service_revision'])),
            'release' => $this->release->only(['id','component','platform','version','sha256','binary_sha256']),
            'evidence_sha256' => str_repeat('d', 64), 'expires_at' => now()->timestamp + 7200];
        $pin = Scope::pin($source, $baseline);
        $grant = app(MachineFailoverHandover::class)->issueManualUsdo1($scope, $admin, $pin, HandoverEvidenceFixture::manualStop($scope, $admin, $pin));
        $run = $package->start($grant, $plan->id);
        return compact('package','make','source','baseline','admin','grant','run','plan','f','sourceInstance')
            + ['record' => &$record, 'writes' => &$writes, 'resolver' => &$resolver, 'release' => $this->release->id];
    }

    protected function usdo1FixtureSecret(\App\Models\MachineProvisionTask $task): string
    {
        return $this->callbackSecret($task);
    }

    public function test_usdo1_worker_pins_independent_callback_and_only_one_native_create(): void
    {
        ['package'=>$package,'grant'=>$grant,'admin'=>$admin,'run'=>$run] = $this->prepareUsdo1Package('success');
        $launch = new MachineUsdo1LaunchScope($grant, $admin, 'https://receipt.example.test');
        $closed = app(MachineFailoverExecutionGate::class);
        $this->code('orchestration_disabled', fn () => app(MachineUsdo1Runtime::class)->pulse($launch));
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void
            {
                if ($operation !== 'usdo1_execution_tick') parent::assertAllowed($operation);
            }
        });
        try {
            $this->code('usdo1_transport_unconfigured', fn () => app(MachineUsdo1Runtime::class)->pulse($launch));
        } finally {
            app()->instance(MachineFailoverExecutionGate::class, $closed);
        }
        self::assertSame([], $this->posts);
        $package->pulse($grant, $launch);
        $task = $this->task();
        self::assertSame($launch->origin, $task->payload['usdo1_callback_origin']);
        $data = Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data'], true));
        self::assertStringContainsString('url = "' . $launch->origin . MachineUsdo1LaunchScope::CALLBACK_PATH . '"', $data['write_files'][2]['content']);
        self::assertSame('https://panel.example.test', $task->payload['plan']['panel_origin']);
        self::assertFalse(app()->bound(MachineUsdo1LaunchScope::class));

        $secret = $this->callbackSecret($task);
        $server = ['HTTPS'=>'on','HTTP_HOST'=>'receipt.example.test','REQUEST_URI'=>MachineUsdo1LaunchScope::CALLBACK_PATH,
            'REQUEST_METHOD'=>'POST','CONTENT_TYPE'=>'application/json','HTTP_AUTHORIZATION'=>'Bearer ' . $secret];
        $receipt = ['task_id'=>$task->id,'installation_id'=>$task->installation_id,'state'=>'succeeded'];
        $receive = fn (array $input) => MachineUsdo1Runtime::receive($launch, $server, Json::encode($input),
            fn ($scope, $id, $installation, $key, $state) => $package->callback($grant, $id, $installation, $key, $state, $scope));
        $this->code('orchestration_disabled', fn () => app(MachineUsdo1Runtime::class)->callback(
            $launch, $task->id, $task->installation_id, $secret, 'succeeded'));
        config(['machine_provision.allow_live' => true]);
        try {
            $this->code('invalid_callback', fn () => app(MachineProvisionService::class)->callback(
                $task->id, $task->installation_id, $secret, 'succeeded'));
        } finally {
            config(['machine_provision.allow_live' => false]);
        }
        self::assertSame(401, $receive(array_replace($receipt, ['installation_id'=>str_repeat('c', 32)])));
        self::assertNull($task->fresh()->bootstrap_state);
        self::assertSame(200, $receive($receipt));
        self::assertSame(200, $receive($receipt));
        self::assertSame(401, $receive(array_replace($receipt, ['state'=>'failed'])));
        self::assertSame(1, DB::table('v2_machine_provision_step')->where('task_id',$task->id)->where('state','bootstrap_succeeded')->count());
        self::assertSame($closed, app(MachineFailoverExecutionGate::class));
        self::assertSame('stopped', $package->stop($grant)['status']);
        self::assertSame(401, $receive($receipt));
        self::assertSame('stopped', $package->pulse($grant, $launch)['status']);
        self::assertSame('stopped', MachineFailoverRuntime::query()->findOrFail($run['execution_id'])->state);
        self::assertCount(1, $this->posts);
        Bus::assertNotDispatched(MachineFailoverRunJob::class); Bus::assertNotDispatched(MachineProvisionJob::class);
    }

    #[DataProvider('usdo1PackageCases')]
    public function test_usdo1_isolated_package_uses_real_native_journals_and_never_resends(string $mode, bool $dedicated = false): void
    {
        $prepared = $this->prepareUsdo1Package($mode);
        ['package'=>$package,'make'=>$make,'source'=>$source,'baseline'=>$baseline,'grant'=>$grant,
            'run'=>$run,'plan'=>$plan,'f'=>$f,'sourceInstance'=>$sourceInstance] = $prepared;
        $record = &$prepared['record']; $writes = &$prepared['writes']; $resolver = &$prepared['resolver'];
        $id = $run['execution_id'];
        $launch = $dedicated ? new MachineUsdo1LaunchScope($grant, $prepared['admin'], 'https://receipt.example.test') : null;
        self::assertSame($run, $package->start($grant, $plan->id));
        if ($mode === 'success') {
            $closedGate = app(MachineFailoverExecutionGate::class);
            config(['machine_failover.runner_enabled' => true]);
            app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
                public function assertAllowed(string $operation): void
                { if ($operation !== 'runner') parent::assertAllowed($operation); }
            });
            try {
                $this->runner()->enroll($id);
                self::assertNull(MachineFailoverRuntime::query()->find($id));
                self::assertSame(0, $this->runner()->advance()['queued']);
            } finally {
                config(['machine_failover.runner_enabled' => false]);
                app()->instance(MachineFailoverExecutionGate::class, $closedGate);
            }
        }
        $pulse = function () use (&$package, $grant, $id, $launch): array {
            MachineFailoverRuntime::query()->whereKey($id)->update(['next_check_at' => 0]);
            \App\Models\MachineProvisionTask::query()->whereIn('id', MachineFailoverAttempt::query()
                ->whereIn('execution_member_id', MachineFailoverExecutionMember::query()->where('execution_id', $id)->select('id'))
                ->select('task_id'))->update(['next_check_at' => 0]);
            MachineDnsSetRuntime::query()->update(['next_check_at' => 0]);
            return $package->pulse($grant, $launch);
        };
        if ($mode === 'timeout') {
            Carbon::setTestNow(Carbon::createFromTimestampUTC($run['deadline_at']));
            self::assertSame('stopped', $pulse()['status']);
            self::assertSame('stopped', $pulse()['status']);
            self::assertSame([], $this->posts);
            self::assertSame([], $writes);
            Bus::assertNotDispatched(MachineFailoverRunJob::class); Bus::assertNotDispatched(MachineProvisionJob::class);
            return;
        }
        if ($mode === 'lost_receipts') $this->mode = 'unknown_success';
        if ($mode === 'unknown_at_timeout') $this->mode = 'unknown_absent';
        $pulse();
        self::assertCount(1, $this->posts, json_encode($this->runner()->view(\App\Models\MachineFailoverExecution::query()->findOrFail($id))));
        if (in_array($mode, ['stop','unknown_at_timeout'], true)) {
            if ($mode === 'stop') self::assertSame('stopped', $package->stop($grant)['status']);
            else {
                self::assertSame('outcome_unknown', $this->task()->status);
                Carbon::setTestNow(Carbon::createFromTimestampUTC($run['deadline_at']));
            }
            self::assertSame('stopped', $pulse()['status']);
            self::assertCount(1, $this->posts); self::assertSame([], $writes);
            self::assertSame(0, $this->candidateDeletes);
            Bus::assertNotDispatched(MachineFailoverRunJob::class); Bus::assertNotDispatched(MachineProvisionJob::class);
            return;
        }
        if ($mode === 'lost_receipts') {
            self::assertSame('outcome_unknown', $this->task()->status);
            $package = $make();
            self::assertSame($id, $package->start($grant, $plan->id)['execution_id']);
            $pulse();
        }
        $task = $this->task();
        self::assertSame('waiting_initialization', $task->status);
        self::assertTrue($package->callback($grant, $task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded', $launch));
        $task->fresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($f, $task, $task->public_ips[0], true, time());
        if ($mode === 'dns_drift') $record['Remark'] = 'competing writer';
        for ($i = 0; $i < 10; $i++) if (($last = $pulse())['status'] !== 'running') break;
        if ($mode === 'dns_drift') {
            self::assertSame('needs_review', $last['status']);
            self::assertTrue($last['revoked']);
            self::assertSame('competing writer', $record['Remark']);
            self::assertSame($baseline['Value'], $record['Value']);
            self::assertSame([], $writes); self::assertCount(1, $this->posts);
            self::assertSame(0, $this->candidateDeletes);
            return;
        }
        self::assertSame('stopped', $last['status'], json_encode($last));
        self::assertSame($task->public_ips[0], $record['Value'], json_encode(MachineFailoverDnsRun::query()->pluck('reason')));
        self::assertSame([['forward','UpdateDomainRecordRemark'],['forward','UpdateDomainRecord']], $writes);
        $package = $make();
        self::assertSame('stopped', $pulse()['status']);
        self::assertCount(1, $this->posts);
        self::assertSame($sourceInstance->id, MachineOwnedInstance::query()->whereNotNull('active_policy_id')->sole()->id);

        $closed = fn () => HandoverEvidenceFixture::close($package->closureScope($grant));
        try { $package->cleanup($grant, $closed()); self::fail('Cleanup must wait for rollback'); }
        catch (\RuntimeException $e) { self::assertSame('usdo1_existing_a_rollback_unconfirmed', $e->getMessage()); }
        $proof = $closed();
        $approval = $package->rollbackApprovalScope($grant, $proof);
        $wrongSigner = HandoverEvidenceFixture::manualRollback($approval);
        $wrongSigner['issuer'] = 'fixture-reviewer';
        try { $package->rollback($grant, $proof, $wrongSigner); self::fail('Independent rollback approval is required'); }
        catch (\App\Services\ServerMachine\Cloud\CloudException $e) { self::assertSame('handover_authority_unavailable', $e->getMessage()); }
        $package->rollback($grant, $proof, HandoverEvidenceFixture::manualRollback($approval));
        self::assertTrue(Json::same($baseline, $record));
        self::assertSame([['rollback','UpdateDomainRecord'],['rollback','UpdateDomainRecordRemark']], array_slice($writes, 2));
        self::assertSame(0, $this->candidateDeletes);
        try { $package->cleanup($grant, $closed()); self::fail('Cleanup must wait for the TTL window'); }
        catch (\RuntimeException $e) { self::assertSame('usdo1_existing_a_cache_drain_pending', $e->getMessage()); }
        Carbon::setTestNow(now()->addSeconds(600));
        try { $package->cleanup($grant, $closed()); self::fail('Cleanup must verify resolvers'); }
        catch (\RuntimeException $e) { self::assertSame('usdo1_existing_a_guard_refused', $e->getMessage()); }
        $resolver = true;
        self::assertSame('first_absence', $package->cleanup($grant, $closed()));
        self::assertSame('first_absence', $package->cleanup($grant, $closed()));
        Carbon::setTestNow(now()->addSeconds(10));
        self::assertSame('absent_confirmed', $package->cleanup($grant, $closed()));
        self::assertSame(1, $this->candidateDeletes);
        self::assertArrayHasKey(917, $this->remote);
        self::assertSame(0, $this->dnsWrites);
        self::assertFalse(config('machine_failover.runner_enabled'));
        self::assertFalse(config('machine_failover.observe_enabled'));
        self::assertFalse(config('machine_provision.allow_live'));
        Bus::assertNotDispatched(MachineFailoverRunJob::class); Bus::assertNotDispatched(MachineProvisionJob::class);
        $this->code('orchestration_disabled', fn () => app(MachineFailoverExecutionGate::class)->assertAllowed('usdo1_execution_tick'));
    }
}
