<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverServiceController;
use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverAction as Action;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverRun as Run;
use App\Models\MachineFailoverRunMember as RunMember;
use App\Models\MachineFailoverService as Service;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverSimulation;
use App\Services\ServerMachine\Cloud\MachineFailoverSimulationDriver;
use App\Services\ServerMachine\Cloud\MachineFailoverStateMachine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverServicesTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineFailoverServiceRegistry $registry;
    private MachineFailoverSimulation $simulation;
    private User $admin;
    private array $policies = [];
    private array $service;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $this->bindTestSettings(['app_url' => 'https://panel.example.test']);
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_05_160000_create_machine_dns_tables.php',
            '2026_11_05_220000_create_machine_provision_tasks.php', '2026_11_06_030000_create_machine_failover_policies.php',
            '2026_11_07_120000_add_provider_groups_to_machine_failover.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_11_07_140000_create_machine_failover_services.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $account = MachineCloudAccount::query()->create(['name' => 'Synthetic account', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-cloud-secret']]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-secret']]);
        for ($i = 1; $i <= 2; $i++) {
            $machine = ServerMachine::query()->create(['name' => 'Isolated fixture ' . $i, 'token' => 'synthetic-machine-secret-' . $i, 'is_active' => true]);
            DB::table('v2_server')->insert(['machine_id' => $machine->id, 'enabled' => true]);
            $this->policies[] = app(MachineFailoverPolicyService::class)->save(['name' => 'Member policy ' . $i, 'machine_id' => $machine->id,
                'account_id' => $account->id, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12',
                'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100,
                'daily_limit' => 1, 'dns_credential_id' => $dns->id, 'dns_hostname' => 'isolated-' . $i . '.example.test', 'isolation_confirmed' => true], $this->admin->id);
        }
        $this->registry = app(MachineFailoverServiceRegistry::class); $this->simulation = app(MachineFailoverSimulation::class);
        $this->service = $this->registry->save($this->input(), $this->admin->id);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
    }
    private function input(): array
    {
        return ['name' => 'Synthetic service', 'max_parallel' => 1, 'attempt_limit' => 2, 'monthly_cap_usd' => 200,
            'members' => array_map(fn ($row) => ['policy_id' => $row['id'], 'policy_revision' => $row['revision'], 'name' => $row['name'],
                'priority' => $row['id'], 'enabled' => true], $this->policies)];
    }
    private function start(string $scenario = 'replace', ?int $target = 1, string $key = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        return $this->simulation->start($this->service['id'], $this->service['revision'], $scenario, $target, $key, $this->admin->id);
    }
    private function code(string $expected, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $expected); }
        catch (CloudException $error) { $this->assertSame($expected, $error->getMessage()); }
    }
    private function untouched(): void
    {
        foreach (['provision_task', 'owned_instance', 'dns_transition', 'owned_dns', 'cloud_task', 'dns_binding'] as $table) $this->assertSame(0, DB::table('v2_machine_' . $table)->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
        $this->assertFalse(config('machine_failover.allow_replacement')); $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_acceptance.allow_dns'));
    }

    public function test_service_members_are_unique_and_do_not_mutate_existing_policies(): void
    {
        $before = Policy::query()->get()->toArray(); $view = $this->registry->overview();
        $this->assertCount(2, $view['services'][0]['members']); $this->assertTrue($view['simulation_only']);
        $this->assertSame($this->service['id'], $view['policies'][0]['used_service_id']);
        $this->code('member_in_use', fn () => $this->registry->save($this->input(), $this->admin->id));
        $duplicate = $this->input(); $duplicate['members'][] = $duplicate['members'][0];
        $duplicate += ['id' => $this->service['id'], 'expected_revision' => 1];
        $this->code('duplicate_member', fn () => $this->registry->save($duplicate, $this->admin->id));
        $this->code('policy_in_use', fn () => app(MachineFailoverPolicyService::class)->drop($this->policies[0]['id'], 1, $this->admin->id));
        $this->assertSame($before, Policy::query()->get()->toArray()); $this->untouched();
    }

    public static function credentialCases(): array
    {
        return [['credential_auth_rotate', 'completed', 2, 'B'], ['credential_permission_rotate', 'completed', 2, 'B'],
            ['credential_billing_rotate', 'completed', 2, 'C'], ['credential_quota_same_account', 'failed', 1, 'A'],
            ['credential_rate_limited', 'failed', 1, 'A'], ['credential_template_error', 'failed', 1, 'A'],
            ['credential_unknown_no_rotate', 'needs_review', 1, 'A'], ['credential_pool_exhausted', 'failed', 1, 'A'],
            ['credential_identity_unknown', 'failed', 1, 'A'], ['credential_duplicate_token', 'completed', 2, 'C']];
    }
    #[DataProvider('credentialCases')]
    public function test_credential_error_matrix_uses_bounded_synthetic_attempts_only(string $scenario, string $status, int $count, string $selected): void
    {
        $run = $this->simulation->advance($this->start($scenario)['id'], $this->admin->id);
        $this->assertSame($status, $run['status']); $this->assertSame($count, $run['attempts']);
        $this->assertCount($count, $run['members'][0]['credential_attempts']);
        $this->assertSame($selected, $run['members'][0]['credential_attempts'][$count - 1]['credential']);
        $this->assertSame('healthy', $run['members'][1]['phase']);
        $this->assertSame(1, Action::query()->where('kind', 'create')->count());
        if ($scenario === 'credential_unknown_no_rotate') {
            $this->assertSame('reconcile', $run['members'][0]['credential_attempts'][0]['action']);
            $this->assertSame(1, Action::query()->where('kind', 'reconcile_create')->count());
        }
        $this->assertArrayNotHasKey('intent', $run['members'][0]['credential_attempts'][0]); $this->untouched();
    }

    public function test_credential_rotation_cannot_multiply_service_attempt_or_reservation_caps(): void
    {
        Service::query()->whereKey($this->service['id'])->update(['attempt_limit' => 1]);
        $run = $this->simulation->advance($this->start('credential_auth_rotate')['id'], $this->admin->id);
        $this->assertSame('failed', $run['status']); $this->assertSame(1, $run['attempts']); $this->assertSame(100.0, $run['reserved_usd']);
        $this->assertSame('rotation_budget_exhausted', $run['members'][0]['credential_attempts'][0]['reason']); $this->untouched();
    }

    public static function outcomes(): array
    {
        return [['healthy', 'completed', 'healthy', 0], ['replace', 'completed', 'completed', 1], ['one_target_reachable', 'completed', 'healthy', 0],
            ['no_baseline', 'failed', 'blocked', 0], ['unknown_probe', 'failed', 'blocked', 0], ['stale_probe', 'failed', 'blocked', 0],
            ['recover_before_create', 'completed', 'recovered', 0], ['recover_after_create', 'completed', 'retained', 1],
            ['create_rejected', 'failed', 'failed', 1], ['create_unknown_found', 'completed', 'completed', 1], ['create_ambiguous', 'needs_review', 'needs_review', 1],
            ['initialization_timeout', 'failed', 'failed', 1], ['reports_mismatch', 'failed', 'failed', 1], ['ipv4_unreachable', 'failed', 'failed', 1],
            ['forwarding_failed', 'failed', 'failed', 1], ['dns_conflict', 'needs_review', 'needs_review', 1],
            ['dns_unknown_applied', 'completed', 'completed', 1], ['dns_unknown_unresolved', 'needs_review', 'needs_review', 1]];
    }
    #[DataProvider('outcomes')]
    public function test_simulated_state_machine_is_member_scoped_and_never_executes_live(string $scenario, string $status, string $phase, int $creates): void
    {
        $start = $this->start($scenario); $result = $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame($status, $result['status']); $this->assertSame($phase, $result['members'][0]['phase']);
        $this->assertSame('healthy', $result['members'][1]['phase']); $this->assertFalse($result['business_verified']);
        $this->assertFalse($result['execution_enabled']); $this->assertFalse($result['dns_cutover']); $this->assertSame('synthetic', $result['evidence_origin']);
        $this->assertSame($creates, Action::query()->where('kind', 'create')->count());
        $this->assertSame(0, Action::query()->where('member_id', 2)->count());
        $this->assertSame($result['id'], $this->start($scenario)['id']);
        $this->assertSame($result, $this->simulation->advance($result['id'], $this->admin->id));
        if ($scenario === 'replace') $this->assertSame(['create', 'initialize', 'reports', 'ipv4', 'forwarding', 'prepare_dns', 'switch_dns', 'verify_dns'], array_column($result['actions'], 'kind'));
        foreach (['synthetic-cloud-secret', 'synthetic-dns-secret', 'synthetic-machine-secret', 'receipt', 'context_hash', 'root_pass'] as $secret) $this->assertStringNotContainsString($secret, json_encode($result));
        $this->untouched();
    }

    public function test_service_serialization_and_aggregate_budget_cover_all_members(): void
    {
        $run = $this->start('replace', null);
        for ($i = 0; $i < 100 && $run['status'] === 'running'; $i++) {
            $run = $this->simulation->advance($run['id'], $this->admin->id, 1);
            $this->assertLessThanOrEqual(1, RunMember::query()->whereIn('phase', array_keys(MachineFailoverStateMachine::ACTIONS))->count());
        }
        $this->assertSame('completed', $run['status']); $this->assertSame(2, $run['attempts']); $this->assertSame(200.0, $run['reserved_usd']);
        $this->assertSame(['completed', 'completed'], array_column($run['members'], 'phase')); $this->untouched();
    }

    public static function budgetCases(): array { return [['attempt_limit', 1, 'service_attempt_limit'], ['monthly_cap_usd', 100, 'service_budget_limit']]; }
    #[DataProvider('budgetCases')]
    public function test_service_caps_do_not_multiply_per_member(string $field, int $value, string $reason): void
    {
        Service::query()->whereKey($this->service['id'])->update([$field => $value]);
        $run = $this->simulation->advance($this->start('replace', null)['id'], $this->admin->id);
        $this->assertSame('partial', $run['status']); $this->assertSame($reason, $run['members'][1]['reason']);
        $this->assertSame(1, $run['attempts']); $this->assertSame(1, Action::query()->where('kind', 'create')->count()); $this->untouched();
    }

    public function test_exact_cent_reservations_do_not_reject_a_matching_service_cap(): void
    {
        Policy::query()->whereKey($this->policies[0]['id'])->update(['monthly_limit_usd' => 0.1]);
        Policy::query()->whereKey($this->policies[1]['id'])->update(['monthly_limit_usd' => 0.2]);
        Service::query()->whereKey($this->service['id'])->update(['monthly_cap_usd' => 0.3]);
        $result = $this->simulation->advance($this->start('replace', null)['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(2, $result['attempts']);
        $this->assertSame(0.3, $result['reserved_usd']); $this->untouched();
    }

    public function test_unknown_create_and_dns_intents_are_reconciled_without_replaying_writes(): void
    {
        foreach (['create_unknown_found' => 'create', 'dns_unknown_applied' => 'switch_dns'] as $scenario => $kind) {
            $run = $this->simulation->advance($this->start($scenario, 1, substr(hash('sha256', $scenario), 0, 32))['id'], $this->admin->id);
            $this->assertSame('completed', $run['status']);
            $this->assertSame(1, Action::query()->where('run_id', $run['id'])->where('kind', $kind)->count());
            $this->assertSame(1, Action::query()->where('run_id', $run['id'])->where('kind', $kind === 'create' ? 'reconcile_create' : 'reconcile_dns')->count());
        }
        $this->untouched();
    }

    public function test_lost_dispatch_receipt_cannot_repeat_create(): void
    {
        $start = $this->start(); $this->simulation->advance($start['id'], $this->admin->id, 4);
        $row = RunMember::query()->where('run_id', $start['id'])->where('member_id', 1)->firstOrFail();
        $payload = $row->payload; $payload['state']['phase'] = 'creating'; $payload['state']['create_dispatched'] = false; $payload['state']['candidate'] = null;
        $row->fill(['phase' => 'creating', 'payload' => $payload])->save();
        Action::query()->where('run_id', $start['id'])->where('kind', 'create')->update(['receipt' => null, 'status' => 'dispatched']);
        $result = $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(1, Action::query()->where('kind', 'create')->count());
        $this->assertSame(1, Action::query()->where('kind', 'reconcile_create')->count()); $this->untouched();
    }

    public function test_lost_dns_dispatch_receipt_cannot_repeat_dns_write(): void
    {
        $start = $this->start(); $this->simulation->advance($start['id'], $this->admin->id, 10);
        $row = RunMember::query()->where('run_id', $start['id'])->where('member_id', 1)->firstOrFail();
        $this->assertSame('checking_dns', $row->phase);
        $payload = $row->payload; $payload['state']['phase'] = 'switching_dns'; $payload['state']['dns_dispatched'] = false;
        $row->fill(['phase' => 'switching_dns', 'payload' => $payload])->save();
        Action::query()->where('run_id', $start['id'])->where('kind', 'switch_dns')->update(['receipt' => null, 'status' => 'dispatched']);
        $result = $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(1, Action::query()->where('kind', 'switch_dns')->count());
        $this->assertSame(1, Action::query()->where('kind', 'reconcile_dns')->count()); $this->untouched();
    }

    public static function interruptedInvalidationCases(): array { return [['cancel'], ['config']]; }
    #[DataProvider('interruptedInvalidationCases')]
    public function test_invalidation_checks_dispatch_journal_even_if_state_write_was_lost(string $cause): void
    {
        $start = $this->start(); $this->simulation->advance($start['id'], $this->admin->id, 4);
        $row = RunMember::query()->where('run_id', $start['id'])->where('member_id', 1)->firstOrFail();
        $payload = $row->payload; $payload['state']['phase'] = 'creating'; $payload['state']['create_dispatched'] = false; $payload['state']['candidate'] = null;
        $row->fill(['phase' => 'creating', 'payload' => $payload])->save();
        Action::query()->where('run_id', $start['id'])->where('kind', 'create')->update(['receipt' => null, 'status' => 'dispatched']);
        if ($cause === 'config') Policy::query()->whereKey($this->policies[0]['id'])->update(['revision' => 2]);
        $result = $cause === 'cancel' ? $this->simulation->cancel($start['id'], $this->admin->id) : $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('needs_review', $result['members'][0]['phase']);
        $this->assertFalse($result['members'][0]['synthetic_resource_retained']);
        $this->assertSame(1, Action::query()->count()); $this->untouched();
    }

    public function test_reservation_and_member_transition_roll_back_together(): void
    {
        $start = $this->start(); $fail = true;
        RunMember::setEventDispatcher(new \Illuminate\Events\Dispatcher(app()));
        RunMember::saving(function ($row) use (&$fail) {
            if ($fail && $row->phase === 'creating') throw new \RuntimeException('synthetic persistence interruption');
        });
        try { $this->simulation->advance($start['id'], $this->admin->id, 3); $this->fail('Expected interruption'); }
        catch (\RuntimeException $error) { $this->assertSame('synthetic persistence interruption', $error->getMessage()); }
        finally { $fail = false; RunMember::unsetEventDispatcher(); }
        $this->assertSame(0, Run::query()->firstOrFail()->attempts);
        $this->assertSame(0.0, Run::query()->firstOrFail()->reserved_usd);
        $this->assertSame(0, Action::query()->count());
        $result = $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(1, $result['attempts']); $this->untouched();
    }

    public function test_unresolved_member_holds_other_creation_but_not_healthy_observation(): void
    {
        $result = $this->simulation->advance($this->start('create_ambiguous', null)['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('service_review_required', $result['members'][1]['reason']);
        $this->assertSame(1, Action::query()->where('kind', 'create')->count()); $this->untouched();
    }

    public function test_lost_client_response_is_resolved_read_only_with_its_original_request(): void
    {
        $started = $this->start(); $before = Run::query()->firstOrFail()->getAttributes();
        $resolved = $this->simulation->resolve($this->service['id'], str_repeat('a', 32), $this->admin->id);
        $this->assertSame($started['id'], $resolved['id']); $this->assertSame($before, Run::query()->firstOrFail()->getAttributes());
        $this->assertNull($this->simulation->resolve($this->service['id'], str_repeat('b', 32), $this->admin->id));
        $this->assertSame(1, Run::query()->count()); $this->assertSame(0, Action::query()->count()); $this->untouched();
    }

    public function test_revoked_original_actor_stops_progress_even_when_another_admin_checks(): void
    {
        $start = $this->start(); $this->simulation->advance($start['id'], $this->admin->id, 4);
        $other = User::query()->create(['is_admin' => true]); $this->admin->fill(['banned' => true])->save();
        $result = $this->simulation->advance($start['id'], $other->id);
        $this->assertSame('permission_revoked', $result['reason']); $this->assertSame('needs_review', $result['status']);
        $this->assertSame(1, Action::query()->count()); $this->untouched();
    }

    public function test_context_changes_after_reservation_or_create_stop_future_stages(): void
    {
        $start = $this->start(); $this->simulation->advance($start['id'], $this->admin->id, 4);
        Policy::query()->whereKey($this->policies[0]['id'])->update(['revision' => 2]);
        $result = $this->simulation->advance($start['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('member_policy_changed', $result['reason']);
        $this->assertSame(1, Action::query()->count()); $this->untouched();
    }

    public function test_service_editor_and_requests_use_revisions_and_do_not_overwrite_a_running_audit(): void
    {
        $start = $this->start(); $input = $this->input() + ['id' => $this->service['id'], 'expected_revision' => 1];
        $this->code('run_active', fn () => $this->registry->save($input, $this->admin->id));
        $this->code('request_conflict', fn () => $this->start('dns_conflict'));
        $this->code('run_active', fn () => $this->start('replace', 1, str_repeat('b', 32)));
        $this->simulation->advance($start['id'], $this->admin->id); $before = $this->simulation->report($start['id'], $this->admin->id);
        $input['name'] = 'Edited'; $updated = $this->registry->save($input, $this->admin->id); $this->assertSame(2, $updated['revision']);
        $this->code('config_changed', fn () => $this->registry->save($input, $this->admin->id));
        $this->assertSame($before, $this->simulation->report($start['id'], $this->admin->id));
        $this->code('service_in_use', fn () => $this->registry->drop($updated['id'], 2, $this->admin->id));
        array_pop($input['members']); $input['expected_revision'] = 2;
        $this->code('member_audit_retained', fn () => $this->registry->save($input, $this->admin->id)); $this->untouched();
    }

    public function test_disabled_member_is_not_simulated_and_cannot_be_targeted(): void
    {
        Member::query()->whereKey(2)->update(['enabled' => false]);
        $this->code('member_missing', fn () => $this->start('replace', 2));
        $result = $this->simulation->advance($this->start()['id'], $this->admin->id);
        $this->assertCount(1, $result['members']); $this->assertSame('completed', $result['status']); $this->untouched();
    }

    public function test_receipt_identity_cleanup_and_timing_are_not_bypassed_by_a_success_label(): void
    {
        $driver = new class extends MachineFailoverSimulationDriver {
            public function execute(string $kind, array $context, array $state, string $scenario, string $operation, int $now): array {
                $fact = parent::execute($kind, $context, $state, $scenario, $operation, $now);
                if ($kind === 'forwarding') $fact['cleanup_complete'] = false;
                return $fact;
            }
        };
        $this->simulation = new MachineFailoverSimulation($this->registry, new MachineFailoverStateMachine(), $driver);
        $result = $this->simulation->advance($this->start()['id'], $this->admin->id);
        $this->assertSame('failed', $result['status']); $this->assertSame('forwarding_failed', $result['members'][0]['reason']);
        $this->assertSame(0, Action::query()->whereIn('kind', ['prepare_dns', 'switch_dns'])->count()); $this->untouched();
    }

    public function test_no_live_entry_exists_even_if_old_gates_are_enabled(): void
    {
        config(['machine_provision.allow_live' => true, 'machine_failover.allow_replacement' => true, 'machine_acceptance.allow_dns' => true]);
        $this->code('orchestration_disabled', fn () => $this->simulation->startLive());
        $input = $this->input() + ['enabled' => true];
        $this->code('invalid_service', fn () => $this->registry->save($input, $this->admin->id));
        $this->assertSame(0, Run::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_admin_api_rejects_execution_flags_and_fabricated_receipts_before_any_work(): void
    {
        $controller = new MachineFailoverServiceController();
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request = Request::create('/synthetic', 'POST'); $request->setUserResolver(fn () => $actor);
            try { $controller->simulate($request, $this->simulation); $this->fail('Expected forbidden'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $request = Request::create('/synthetic', 'POST', ['allow_live' => true, 'receipt' => ['status' => 'passed']]); $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->simulate($request, $this->simulation)->getStatusCode()); $this->assertSame(0, Run::query()->count()); $this->untouched();
    }

    public function test_admin_api_runs_the_full_simulation_then_resolves_the_same_intent_read_only(): void
    {
        $controller = new MachineFailoverServiceController();
        $input = ['service_id' => $this->service['id'], 'service_revision' => 1, 'scenario' => 'replace', 'member_id' => 1, 'request_id' => str_repeat('a', 32)];
        $request = Request::create('/synthetic', 'POST', $input); $request->setUserResolver(fn () => $this->admin);
        $response = $controller->simulate($request, $this->simulation);
        $this->assertSame(200, $response->getStatusCode()); $body = $response->getData(true);
        $this->assertSame('success', $body['status']); $this->assertSame('completed', $body['data']['status']);
        $this->assertSame('completed', $body['data']['members'][0]['phase']); $this->assertSame('healthy', $body['data']['members'][1]['phase']);
        $this->assertFalse($body['data']['execution_enabled']); $this->assertFalse($body['data']['dns_cutover']); $this->assertFalse($body['data']['business_verified']);
        foreach (['payload', 'admin_id', 'request_key', 'intent_hash', 'context_hash'] as $key) $this->assertArrayNotHasKey($key, $body['data']);
        $this->assertStringNotContainsString('synthetic-cloud-secret', $response->getContent());
        $before = Action::query()->get()->toArray();
        $lookup = Request::create('/synthetic', 'GET', ['service_id' => $this->service['id'], 'request_id' => $input['request_id']]);
        $lookup->setUserResolver(fn () => $this->admin);
        $this->assertSame($body['data']['id'], $controller->report($lookup, $this->simulation)->getData(true)['data']['id']);
        $this->assertSame($before, Action::query()->get()->toArray()); $this->assertSame(1, Run::query()->count()); $this->untouched();
    }

    public function test_downgrade_keeps_simulation_audit_and_draft_deletion_leaves_policies_untouched(): void
    {
        $before = Policy::query()->count(); $this->registry->drop($this->service['id'], 1, $this->admin->id);
        $this->assertSame(0, Member::query()->count()); $this->assertSame($before, Policy::query()->count());
        $this->service = $this->registry->save($this->input(), $this->admin->id); $this->start('healthy', null);
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_140000_create_machine_failover_services.php'))->down();
    }
}
