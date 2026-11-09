<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverServiceController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverObservation as Observation;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineFailoverService;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\CloudFailurePolicy;
use App\Services\ServerMachine\Cloud\CloudRequestFailure;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverReadOnlyAdapter;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverShadow;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverShadowTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    protected User $admin;
    protected Account $account;
    protected array $policies = [];
    protected array $machines = [];
    protected array $service;
    protected int $clock;
    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('h', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_05_160000_create_machine_dns_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_07_120000_add_provider_groups_to_machine_failover.php',
            '2026_11_06_040000_create_machine_failover_observations.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_11_07_090000_create_machine_replacement_tasks.php', '2026_11_07_140000_create_machine_failover_services.php',
            '2026_11_07_160000_create_machine_credential_health.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); });
        $this->clock = time(); Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->account = Account::query()->create(['name' => 'Synthetic account', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-secret']]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-secret']]);
        for ($i = 0; $i < 2; $i++) {
            $machine = ServerMachine::query()->create(['name' => 'Synthetic ' . $i, 'token' => 'synthetic-machine-' . $i, 'is_active' => true]);
            $this->machines[] = $machine; DB::table('v2_server')->insert(['machine_id' => $machine->id, 'enabled' => true]);
            $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Member ' . $i, 'machine_id' => $machine->id,
                'account_id' => $this->account->id, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12',
                'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 300, 'monthly_limit_usd' => 40,
                'daily_limit' => 2, 'dns_credential_id' => $dns->id, 'dns_hostname' => 'synthetic-' . $i . '.example.test', 'isolation_confirmed' => true], $this->admin->id);
            $policy = Policy::query()->findOrFail($saved['id']); $this->policies[] = $policy;
            $task = Task::query()->create(['id' => (string) Str::uuid(), 'request_key' => hash('sha256', 'synthetic-' . $i), 'admin_id' => $this->admin->id,
                'account_id' => $this->account->id, 'account_revision' => 1, 'machine_id' => $machine->id, 'machine_authority' => hash('sha256', $machine->token),
                'plan_hash' => str_repeat('b', 64), 'slot' => $policy->slot, 'installation_id' => str_repeat((string) ($i + 1), 32),
                'callback_hash' => str_repeat('c', 64), 'marker' => 'synthetic-' . $i, 'payload' => ['plan' => []], 'status' => 'accepted',
                'deadline_at' => $this->clock - 90000, 'created_at' => $this->clock - 90001, 'updated_at' => $this->clock - 90001]);
            MachineOwnedInstance::query()->create(['policy_id' => $policy->id, 'policy_revision' => 1, 'task_id' => $task->id,
                'installation_id' => $task->installation_id, 'release_id' => 1, 'binary_sha256' => str_repeat('f', 64), 'version' => 'synthetic',
                'ip' => $i ? '8.8.4.4' : '8.8.8.8', 'status' => 'active', 'active_policy_id' => $policy->id]);
        }
        $this->service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Synthetic service', 'max_parallel' => 2, 'attempt_limit' => 2, 'monthly_cap_usd' => 100,
            'members' => array_map(fn ($policy, $i) => ['policy_id' => $policy->id, 'policy_revision' => 1, 'name' => $policy->name, 'priority' => $i + 1, 'enabled' => true], $this->policies, array_keys($this->policies))], $this->admin->id);
    }
    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }
    protected function sample(int $index, bool $healthy, bool $collect = true, array $changes = []): void
    {
        $policy = $this->policies[$index]; $instance = MachineOwnedInstance::query()->where('policy_id', $policy->id)->firstOrFail();
        $config = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => $this->clock,
            'targets' => array_map(fn ($target, $i) => ['id' => $target['id'], 'status' => $healthy && $i === 0 ? 'reachable' : 'unreachable', 'source_ip' => $instance->ip], $config['targets'], array_keys($config['targets']))];
        app(MachineAddressInventory::class)->record($this->machines[$index], $instance->installation_id, ['public_ipv4' => $instance->ip], $this->clock, [], array_replace($probe, $changes));
        if ($collect) app(MachineFailoverObserver::class)->observe($policy->id);
    }
    protected function tick(int $seconds = 5): void { $this->clock += $seconds; Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock)); }
    protected function trigger(): void
    {
        config(['machine_failover.observe_enabled' => true]);
        foreach ([true, false, false] as $healthy) { foreach ([0, 1] as $i) $this->sample($i, $healthy); $this->tick(); }
    }
    private function view(): array { return app(MachineFailoverShadow::class)->report($this->service['id'], 1, $this->admin->id); }
    private function code(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); } catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }
    public function test_reading_fresh_reports_does_not_collect_samples_or_enable_execution(): void
    {
        $this->sample(0, true, false); $view = $this->view();
        $this->assertSame('healthy', $view['members'][0]['decision']); $this->assertSame(1, $view['members'][0]['source']['reachable_targets']);
        $this->assertSame('insufficient_evidence', $view['members'][1]['decision']); $this->assertSame(0, $view['planned_attempts']);
        $this->assertSame(0, Observation::query()->count()); $this->assertSame(0, Plan::query()->count());
        $this->assertFalse(config('machine_failover.observe_enabled')); $this->assertFalse($view['dispatch_allowed']);
        $this->assertFalse($view['automatic_rotation']); $this->assertFalse($view['dns_cutover']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_collected_failure_makes_only_a_virtual_plan_with_shared_account_capacity(): void
    {
        $this->trigger(); $before = $this->database(); DB::enableQueryLog(); $view = $this->view(); $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(['would_replace', 'waiting'], array_column($view['members'], 'decision'));
        $this->assertContains('shared_account_wait', $view['members'][1]['blockers']); $this->assertSame(1, $view['planned_attempts']);
        $this->assertEquals(40, $view['planned_monthly_reservation_usd']); $this->assertFalse($view['pricing_checked']); $this->assertFalse($view['writes_reserved']);
        $this->assertSame($before, $this->database());
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
        $json = json_encode($view); foreach (['synthetic-secret', 'synthetic-dns-secret', 'synthetic-machine-', 'credential_key', 'episode_key', 'quota_ids'] as $secret) $this->assertStringNotContainsString($secret, $json);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    private function database(): array
    {
        $rows = [];
        foreach (['failover_observation', 'failover_plan', 'provision_task', 'replacement_task', 'owned_instance', 'dns_transition', 'owned_dns', 'failover_run', 'credential_health', 'credential_event'] as $table) {
            $rows[$table] = DB::table('v2_machine_' . $table)->orderBy('id')->get()->toArray();
        }
        return json_decode(json_encode($rows, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
    public function test_repeated_refreshes_never_increase_failure_count_or_reserve_budget(): void
    {
        $this->trigger(); $before = $this->database();
        for ($i = 0; $i < 3; $i++) $this->assertSame(2, $this->view()['members'][0]['source']['failures']);
        $this->assertSame($before, $this->database());
    }
    public function test_no_baseline_or_an_uncollected_new_report_cannot_reuse_a_trigger(): void
    {
        config(['machine_failover.observe_enabled' => true]);
        foreach ([0, 1] as $i) $this->sample($i, false); $this->tick();
        foreach ([0, 1] as $i) $this->sample($i, false);
        $view = $this->view(); $this->assertSame('insufficient_evidence', $view['members'][0]['decision']);
        $this->assertSame('healthy_baseline_required', $view['members'][0]['source']['evidence_reason']);
        $this->assertSame(0, $view['planned_attempts']);
        $this->tick(); $this->trigger(); $this->tick(); $this->sample(0, false, false);
        $view = $this->view(); $this->assertSame('insufficient_evidence', $view['members'][0]['decision']);
        $this->assertNull($view['members'][0]['source']['failures']);
    }
    public function test_service_attempt_parallelism_and_price_caps_apply_to_the_whole_shadow_batch(): void
    {
        $this->trigger();
        foreach ([['attempt_limit' => 1, 'max_parallel' => 2, 'monthly_cap_usd' => 100, 'reason' => 'service_attempt_limit', 'attempts' => 1],
            ['attempt_limit' => 2, 'max_parallel' => 1, 'monthly_cap_usd' => 100, 'reason' => 'service_parallel_limit', 'attempts' => 1],
            ['attempt_limit' => 2, 'max_parallel' => 2, 'monthly_cap_usd' => 30, 'reason' => 'service_reservation_limit', 'attempts' => 0]] as $case) {
            MachineFailoverService::query()->whereKey($this->service['id'])->update(array_intersect_key($case, array_flip(['attempt_limit', 'max_parallel', 'monthly_cap_usd'])));
            $view = $this->view(); $this->assertSame($case['attempts'], $view['planned_attempts']);
            $this->assertSame('waiting', $view['members'][1]['decision']); $this->assertContains($case['reason'], $view['members'][1]['blockers']);
            $this->assertFalse($view['writes_reserved']);
        }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_partial_and_wrong_source_reports_are_not_classified_as_confirmed_faults(): void
    {
        $this->trigger(); $this->tick();
        $this->sample(0, false, false, ['targets' => [['id' => 1, 'status' => 'unreachable', 'source_ip' => '8.8.4.4']]]);
        $view = $this->view(); $this->assertSame('insufficient_evidence', $view['members'][0]['decision']);
        $this->assertFalse($view['members'][0]['source']['threshold_reached']);
    }
    public function test_context_change_during_a_read_discards_the_whole_report(): void
    {
        $this->trigger(); $adapter = app(MachineFailoverReadOnlyAdapter::class);
        $mock = \Mockery::mock(MachineFailoverReadOnlyAdapter::class);
        $mock->shouldReceive('read')->andReturnUsing(function ($policy, $now) use ($adapter) {
            $fact = $adapter->read($policy, $now); $this->account->fill(['revision' => 2])->save(); return $fact;
        });
        app()->instance(MachineFailoverReadOnlyAdapter::class, $mock);
        $this->code('account_changed', fn () => $this->view()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_expired_or_missing_health_schema_is_not_a_successful_empty_result(): void
    {
        $this->trigger(); $view = $this->view();
        $this->assertLessThanOrEqual($view['checked_at'] + 30, $view['valid_until']);
        Schema::drop('v2_machine_credential_health'); $this->code('shadow_migration_required', fn () => $this->view());
    }
    public function test_recovery_staleness_and_missing_collection_cannot_reuse_a_previous_fault_plan(): void
    {
        $this->trigger(); $this->sample(0, true, false); $view = $this->view();
        $this->assertSame('healthy', $view['members'][0]['decision']); $this->assertSame(0, $view['planned_attempts'] - ($view['members'][1]['decision'] === 'would_replace' ? 1 : 0));
        $this->tick(120); $this->assertSame('insufficient_evidence', $this->view()['members'][0]['decision']);
        config(['machine_failover.observe_enabled' => false]); $this->sample(0, false, false); $this->assertSame('insufficient_evidence', $this->view()['members'][0]['decision']);
    }
    public function test_an_older_plan_in_the_same_continuous_episode_remains_valid_but_another_episode_does_not(): void
    {
        $this->trigger(); $this->sample(0, false); $this->assertSame('would_replace', $this->view()['members'][0]['decision']);
        Plan::query()->where('policy_id', $this->policies[0]->id)->update(['evidence' => json_encode(['episode_key' => str_repeat('0', 64)])]);
        $this->assertContains('decision_snapshot_missing', $this->view()['members'][0]['blockers']);
    }
    public function test_health_and_service_budgets_block_only_forecasts_and_never_rotate_credentials(): void
    {
        $this->trigger(); app(MachineCloudCredentialHealth::class)->record($this->account,
            new CloudRequestFailure(app(CloudFailurePolicy::class)->response('linode', 'POST', 401)), 'create', 'synthetic-auth');
        $view = $this->view(); $this->assertSame('blocked', $view['members'][0]['decision']); $this->assertContains('credential_health_blocked', $view['members'][0]['blockers']);
        $this->assertSame($this->account->id, $view['members'][0]['candidate']['account_id']); $this->assertSame(0, $view['planned_attempts']);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_unowned_installation_and_changed_policy_cannot_become_an_executable_candidate(): void
    {
        $this->trigger(); MachineOwnedInstance::query()->where('policy_id', $this->policies[0]->id)->update(['active_policy_id' => null]);
        $this->assertContains('source_ownership_unverified', $this->view()['members'][0]['blockers']);
        $this->policies[0]->fill(['revision' => 2])->save(); $this->code('member_policy_changed', fn () => $this->view());
    }
    public function test_adapter_cannot_execute_even_if_legacy_live_gates_are_enabled(): void
    {
        config(['machine_failover.allow_replacement' => true, 'machine_provision.allow_live' => true, 'machine_acceptance.allow_dns' => true]);
        $this->code('shadow_execution_disabled', fn () => app(MachineFailoverReadOnlyAdapter::class)->execute()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_endpoint_rejects_forged_receipts_and_requires_a_current_admin(): void
    {
        $controller = new MachineFailoverServiceController();
        $request = Request::create('/synthetic', 'GET', ['service_id' => $this->service['id'], 'service_revision' => 1, 'source' => ['state' => 'failed']]);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->shadow($request, app(MachineFailoverShadow::class))->getStatusCode());
        $this->admin->fill(['banned' => true])->save();
        try { $controller->shadow($request, app(MachineFailoverShadow::class)); $this->fail('Expected forbidden'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
}
