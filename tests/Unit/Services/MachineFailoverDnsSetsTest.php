<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsSetRuntime as Runtime;
use App\Models\MachineDnsSetFixture as Fixture;
use App\Models\MachineFailoverDnsRun as Run;
use App\Models\MachineFailoverDnsSet as DnsSet;
use App\Models\MachineFailoverDnsSlot as Slot;
use App\Models\MachineFailoverMember as Member;
use App\Models\MachineFailoverService as Service;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsSimulation;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetPlanner;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetProvider;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution as Engine;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetSimulationTransport as Driver;
use Illuminate\Support\Carbon;
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

class MachineFailoverDnsSetsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineFailoverDnsRegistry $dns;
    private MachineFailoverDnsSimulation $simulation;
    private array $service;
    private User $admin;
    private MachineDnsCredential $cf;
    private MachineDnsCredential $ali;

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('s', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_05_160000_create_machine_dns_tables.php',
            '2026_11_05_220000_create_machine_provision_tasks.php', '2026_11_06_030000_create_machine_failover_policies.php',
            '2026_11_07_120000_add_provider_groups_to_machine_failover.php', '2026_11_06_050000_create_machine_acceptance_tables.php',
            '2026_11_09_140000_add_machine_dns_readback_state.php', '2026_11_07_140000_create_machine_failover_services.php',
            '2026_11_09_180000_create_machine_failover_dns_sets.php', '2026_11_09_200000_create_machine_dns_set_runtime.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $account = MachineCloudAccount::query()->create(['name' => 'Synthetic cloud', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-cloud-secret']]);
        $this->cf = MachineDnsCredential::query()->create(['name' => 'CF fixture', 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-secret']]);
        $this->ali = MachineDnsCredential::query()->create(['name' => 'Ali fixture', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-dns-secret']]);
        $members = [];
        for ($i = 1; $i <= 2; $i++) {
            $machine = ServerMachine::query()->create(['name' => 'Isolated ' . $i, 'token' => 'synthetic-machine-secret-' . $i, 'is_active' => true]);
            DB::table('v2_server')->insert(['machine_id' => $machine->id, 'enabled' => true]);
            $policy = app(MachineFailoverPolicyService::class)->save(['name' => 'Policy ' . $i, 'machine_id' => $machine->id, 'account_id' => $account->id,
                'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 2,
                'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100, 'daily_limit' => 1,
                'dns_credential_id' => $this->cf->id, 'dns_hostname' => 'policy-' . $i . '.example.test', 'isolation_confirmed' => true], $this->admin->id);
            $members[] = ['policy_id' => $policy['id'], 'policy_revision' => $policy['revision'], 'name' => 'Member ' . $i, 'priority' => $i, 'enabled' => true];
        }
        $this->service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'DNS set fixture', 'max_parallel' => 1,
            'attempt_limit' => 2, 'monthly_cap_usd' => 200, 'members' => $members], $this->admin->id);
        $this->dns = app(MachineFailoverDnsRegistry::class); $this->simulation = app(MachineFailoverDnsSimulation::class);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
    }
    private function input(string $provider = 'cloudflare', array $lines = ['default']): array
    {
        $credential = $provider === 'cloudflare' ? $this->cf : $this->ali;
        return ['service_id' => $this->service['id'], 'service_revision' => Service::query()->findOrFail($this->service['id'])->revision,
            'credential_id' => $credential->id, 'credential_revision' => $credential->revision,
            'zone' => $provider === 'cloudflare' ? str_repeat('a', 32) : 'example.test', 'zone_name' => 'example.test',
            'hostname' => 'shared.example.test', 'ttl' => $provider === 'cloudflare' ? 60 : 600,
            'members' => array_map(fn ($row) => ['member_id' => $row['id'], 'types' => ['A', 'AAAA'], 'lines' => $lines], $this->service['members'])];
    }
    private function configure(string $provider = 'cloudflare', array $lines = ['default']): array { return $this->dns->save($this->input($provider, $lines), $this->admin->id); }
    private function start(array $view, string $scenario, string $request = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): array
    {
        return $this->simulation->start($view['sets'][0]['id'], $view['sets'][0]['revision'], $view['service_revision'], $this->service['members'][0]['id'], $scenario, $request, $this->admin->id);
    }
    private function code(string $reason, callable $call): void
    {
        try { $call(); $this->fail('Expected ' . $reason); } catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    private function inert(): void
    {
        Http::assertNothingSent(); Bus::assertNothingDispatched();
        foreach (['cloud_task', 'provision_task', 'owned_instance', 'owned_dns', 'dns_transition', 'dns_binding'] as $table) $this->assertSame(0, DB::table('v2_machine_' . $table)->count());
        $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_failover.allow_rotation')); $this->assertFalse(config('machine_acceptance.allow_dns'));
    }

    public static function scenarios(): array
    {
        $result = [];
        foreach (['cloudflare', 'aliyun'] as $provider) foreach ([['replace', 'completed', null], ['bootstrap', 'completed', null], ['detach', 'completed', null],
            ['restore', 'completed', null], ['source_recovered', 'retained', 'source_recovered'], ['missing_ipv6', 'blocked', 'dns_address_missing'],
            ['foreign_record', 'blocked', 'dns_foreign_record'], ['ownership_changed', 'blocked', 'dns_ownership_changed'], ['duplicate_record', 'blocked', 'dns_ownership_changed'],
            ['inventory_incomplete', 'blocked', 'dns_inventory_incomplete'], ['record_changed', 'blocked', 'dns_record_changed'],
            ['partial_failure', 'needs_review', 'dns_partial_failure'], ['permission_denied', 'needs_review', 'provider_forbidden'],
            ['unknown_applied', 'completed', null], ['unknown_unresolved', 'needs_review', 'dns_original_intent_only'],
            ['claim_unknown', 'completed', null], ['readback_rate_limited', 'completed', null]] as $row) $result[$provider . ':' . $row[0]] = [$provider, ...$row];
        $result['cloudflare:create_unknown'] = ['cloudflare', 'create_unknown', 'completed', null];
        $result['aliyun:create_unknown'] = ['aliyun', 'create_unknown', 'needs_review', 'dns_unclaimed_record'];
        return $result;
    }
    #[DataProvider('scenarios')]
    public function test_provider_member_line_dual_stack_and_failure_matrix(string $provider, string $scenario, string $status, ?string $reason): void
    {
        $view = $this->configure($provider); $run = $this->start($view, $scenario);
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame($status, $result['status']); $this->assertSame($reason, $result['reason']);
        $this->assertSame($result, $this->simulation->advance($run['id'], $this->admin->id));
        $this->assertSame($result['id'], $this->start($view, $scenario)['id']);
        foreach (['execution_enabled', 'automatic_rotation', 'dns_cutover', 'business_verified', 'propagation_verified'] as $key) $this->assertFalse($result[$key]);
        $this->assertSame(0, $result['dns_writes']); $this->assertSame('synthetic', $result['evidence_origin']);
        if ($scenario === 'readback_rate_limited') $this->assertSame(120, $result['virtual_wait_seconds']);
        if ($scenario === 'partial_failure') $this->assertSame(1, $result['simulated_writes']);
        if (in_array($scenario, ['replace', 'detach', 'restore', 'unknown_applied'], true)) {
            $this->assertSame([$this->service['members'][0]['id']], array_values(array_unique(array_column($result['actions'], 'member_id'))));
            $payload = Run::query()->findOrFail($run['id'])->payload;
            foreach ($payload['plan']['protected'] as $before) $this->assertContains($before, $payload['records']);
        }
        foreach (['synthetic-dns-secret', 'synthetic-cloud-secret', 'synthetic-machine-secret', 'marker', 'parameters', 'context_hash'] as $secret)
            $this->assertStringNotContainsString($secret, json_encode($result));
        $this->inert();
    }

    public function test_two_members_share_a_hostname_and_three_aliyun_lines_with_distinct_record_slots(): void
    {
        $view = $this->configure('aliyun', ['default', 'telecom', 'unicom']);
        $this->assertCount(12, $view['sets'][0]['slots']); $this->assertSame(2, $view['service_revision']);
        $slots = Slot::query()->get(); $this->assertCount(12, $slots->pluck('marker')->unique());
        $run = $this->simulation->advance($this->start($view, 'replace')['id'], $this->admin->id);
        $this->assertSame('completed', $run['status']); $this->assertCount(6, $run['actions']); $this->assertCount(12, $run['records']);
        $this->assertSame(['default', 'telecom', 'unicom'], array_values(array_unique(array_column($run['records'], 'line'))));
        $this->inert();
    }
    public static function propagationScenarios(): array
    {
        return [['ttl_wait', 'waiting_ttl'], ['converged', 'converged'], ['old_cache', 'waiting_ttl'], ['nxdomain', 'waiting_ttl'],
            ['timeout', 'unknown'], ['mixed', 'waiting_ttl'], ['cname', 'unknown'], ['servfail', 'unknown']];
    }
    #[DataProvider('propagationScenarios')]
    public function test_external_propagation_rehearsal_does_not_repeat_dns_writes(string $scenario, string $state): void
    {
        $view = $this->configure(); $run = $this->simulation->advance($this->start($view, 'replace')['id'], $this->admin->id);
        $before = app(Driver::class)->metrics($run['id']);
        $result = $this->simulation->propagation($run['id'], $scenario, $this->admin->id);
        $this->assertSame($state, $result['propagation']['state']); $this->assertSame(1, $result['propagation']['checks']);
        $this->assertSame('synthetic', $result['propagation']['evidence_origin']); $this->assertSame(0, $result['propagation']['network_queries']);
        $this->assertFalse($result['propagation_verified']); $this->assertFalse($result['propagation']['propagation_verified']);
        $this->assertFalse($result['propagation']['global_propagation_verified']); $this->assertCount(4, $result['propagation']['rows']);
        $this->assertSame($before, app(Driver::class)->metrics($run['id']));
        $this->assertSame($result, $this->simulation->report($run['id'], $this->admin->id)); $this->inert();
    }
    public function test_aliyun_nondefault_lines_are_not_certified_by_global_recursive_observations(): void
    {
        $view = $this->configure('aliyun', ['default', 'telecom', 'unicom']);
        $run = $this->simulation->advance($this->start($view, 'replace')['id'], $this->admin->id);
        $result = $this->simulation->propagation($run['id'], 'converged', $this->admin->id);
        $this->assertSame('partial_scope', $result['propagation']['state']);
        $this->assertSame(['telecom', 'unicom'], $result['propagation']['unobserved_lines']); $this->inert();
    }
    public function test_propagation_preserves_original_ttl_wait_across_process_reconstruction_and_bounds_checks(): void
    {
        $view = $this->configure(); $run = $this->simulation->advance($this->start($view, 'replace')['id'], $this->admin->id);
        $first = $this->simulation->propagation($run['id'], 'nxdomain', $this->admin->id)['propagation'];
        $result = app(MachineFailoverDnsSimulation::class)->propagation($run['id'], 'converged', $this->admin->id)['propagation'];
        $this->assertGreaterThanOrEqual($first['wait_until'], $result['checked_at']); $this->assertSame('converged', $result['state']);
        for ($i = 2; $i < 12; $i++) $this->simulation->propagation($run['id'], 'converged', $this->admin->id);
        $this->code('dns_propagation_limit', fn () => $this->simulation->propagation($run['id'], 'converged', $this->admin->id));
        $this->inert();
    }
    public function test_propagation_rejects_incomplete_stopped_stale_foreign_and_freeform_contexts(): void
    {
        $view = $this->configure(); $run = $this->start($view, 'replace');
        $this->code('dns_propagation_not_ready', fn () => $this->simulation->propagation($run['id'], 'converged', $this->admin->id));
        $this->simulation->advance($run['id'], $this->admin->id);
        $this->code('invalid_dns_propagation', fn () => $this->simulation->propagation($run['id'], 'http://127.0.0.1', $this->admin->id));
        $request = Request::create('/', 'POST', ['id' => $run['id'], 'scenario' => 'converged', 'hostname' => 'other.example.test']);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, (new \App\Http\Controllers\V2\Admin\Server\MachineFailoverDnsController())->propagation($request, $this->simulation)->getStatusCode());
        Service::query()->whereKey($this->service['id'])->increment('revision');
        $this->code('config_changed', fn () => $this->simulation->propagation($run['id'], 'converged', $this->admin->id));
        $this->inert();
    }

    public function test_create_claim_interruption_resumes_with_original_id_without_a_second_add(): void
    {
        $view = $this->configure('aliyun'); $run = $this->start($view, 'bootstrap');
        $first = $this->simulation->advance($run['id'], $this->admin->id, 1);
        $this->assertSame('awaiting_claim', $first['actions'][0]['state']); $this->assertSame(1, $first['simulated_writes']);
        $known = Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0]['known_id'];
        $result = app(MachineFailoverDnsSimulation::class)->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']);
        $action = Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0];
        $this->assertSame($known, $action['known_id']);
        $this->assertSame(['AddDomainRecord', 'UpdateDomainRecordRemark'], array_column($action['requests'], 'action'));
        $this->assertSame($known, $action['requests'][1]['parameters']['RecordId']); $this->inert();
    }

    public function test_stop_and_context_changes_never_resend_or_complete_an_unverified_intent(): void
    {
        $view = $this->configure(); $run = $this->start($view, 'replace');
        $first = $this->simulation->advance($run['id'], $this->admin->id, 1); $this->assertSame('awaiting_readback', $first['actions'][0]['state']);
        $stopped = $this->simulation->stop($run['id'], $this->admin->id);
        $this->assertSame('stopped', $stopped['status']); $this->assertSame($stopped, $this->simulation->advance($run['id'], $this->admin->id));
        $run = $this->start($view, 'replace', str_repeat('b', 32));
        $this->simulation->advance($run['id'], $this->admin->id, 1);
        DnsSet::query()->whereKey($view['sets'][0]['id'])->increment('revision');
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('config_changed', $result['reason']);
        $this->assertSame(1, $result['simulated_writes']); $this->inert();
    }

    public function test_saved_dns_draft_invalidates_service_revision_and_preserves_slot_markers(): void
    {
        $view = $this->configure(); $before = Slot::query()->pluck('marker', 'id')->all(); $input = $this->input();
        $input += ['id' => $view['sets'][0]['id'], 'expected_revision' => 1]; $input['ttl'] = 120;
        $view = $this->dns->save($input, $this->admin->id);
        $this->assertSame(3, $view['service_revision']); $this->assertSame(2, $view['sets'][0]['revision']); $this->assertSame($before, Slot::query()->pluck('marker', 'id')->all());
        $this->code('config_changed', fn () => $this->dns->save($input, $this->admin->id));
        $this->code('dns_set_in_use', fn () => app(MachineFailoverServiceRegistry::class)->drop($this->service['id'], 3, $this->admin->id)); $this->inert();
    }

    public function test_invalid_provider_lines_members_domains_and_live_flags_are_rejected_atomically(): void
    {
        $input = $this->input();
        foreach ([['members', [['member_id' => 999, 'types' => ['A'], 'lines' => ['default']]], 'invalid_dns_members'],
            ['hostname', 'other.test', 'invalid_dns_set'], ['ttl', 1, 'invalid_dns_set'], ['zone', 'https://example.test', 'invalid_dns_set'],
            ['members', [['member_id' => 1, 'types' => ['A', 'A'], 'lines' => ['default']]], 'invalid_dns_members'],
            ['members', [['member_id' => 1, 'types' => ['A'], 'lines' => ['telecom']]], 'invalid_dns_members'],
            ['dns_cutover', true, 'invalid_dns_set']] as [$key, $value, $reason]) {
            $this->code($reason, fn () => $this->dns->save(array_replace($input, [$key => $value]), $this->admin->id));
            $this->assertSame(0, DnsSet::query()->count()); $this->assertSame(1, Service::query()->first()->revision);
        }
        $this->inert();
    }

    public function test_permission_original_admin_request_binding_and_audit_retention(): void
    {
        $view = $this->configure(); $run = $this->start($view, 'replace');
        $other = User::query()->create(['is_admin' => true]);
        $this->code('permission_revoked', fn () => $this->simulation->report($run['id'], $other->id));
        $this->code('request_conflict', fn () => $this->start($view, 'detach'));
        $this->simulation->advance($run['id'], $this->admin->id);
        $this->code('dns_set_audit_retained', fn () => $this->dns->drop($view['sets'][0]['id'], 1, $this->service['id'], 2, $this->admin->id));
        $this->admin->banned = true; $this->admin->save();
        $this->code('permission_revoked', fn () => $this->simulation->report($run['id'], $this->admin->id));
        $this->inert();
    }

    public function test_record_set_scope_conflicts_with_legacy_policy_and_credential_delete_is_blocked(): void
    {
        $this->code('dns_record_conflict', fn () => $this->dns->save(array_replace($this->input(), ['hostname' => 'policy-1.example.test']), $this->admin->id));
        $this->configure('aliyun');
        try { app(\App\Services\ServerMachine\Dns\MachineDnsService::class)->deleteCredential($this->ali->id, 1, $this->admin->id); $this->fail('Expected credential in use'); }
        catch (\App\Services\ServerMachine\Dns\DnsException $error) { $this->assertSame('credential_in_use', $error->getMessage()); }
        $this->inert();
    }

    public function test_missing_migration_and_downgrade_preserve_the_offline_boundary(): void
    {
        $migration = require base_path('database/migrations/2026_11_09_180000_create_machine_failover_dns_sets.php');
        $migration->down(); $this->assertFalse($this->dns->available());
        $this->code('migration_required', fn () => $this->dns->save($this->input(), $this->admin->id));
        $migration->up(); $this->configure();
        try { $migration->down(); $this->fail('Expected retained draft'); } catch (\RuntimeException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
        $this->code('dns_set_simulation_only', fn () => $this->simulation->executeLive()); $this->inert();
    }

    public function test_legacy_single_record_rehearsal_and_native_bridge_cannot_adopt_record_sets(): void
    {
        $view = $this->configure();
        $this->code('dns_set_rehearsal_required', fn () => app(\App\Services\ServerMachine\Cloud\MachineFailoverSimulation::class)
            ->start($this->service['id'], $view['service_revision'], 'replace', null, str_repeat('c', 32), $this->admin->id));
        $this->assertSame(0, \App\Models\MachineFailoverRun::query()->count());
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge::class)
            ->start($this->service['id'], $view['service_revision'], [1], 1, str_repeat('d', 32), $this->admin->id));
        $this->inert();
    }

    public function test_bound_member_removal_is_blocked_and_parent_downgrade_cannot_orphan_slots(): void
    {
        $view = $this->configure(); $registry = app(MachineFailoverServiceRegistry::class);
        $service = $registry->present(Service::query()->first());
        $input = array_intersect_key($service, array_flip(['name', 'max_parallel', 'attempt_limit', 'monthly_cap_usd']));
        $input += ['id' => $service['id'], 'expected_revision' => $service['revision'], 'members' => array_map(fn ($r) => array_intersect_key($r,
            array_flip(['policy_id', 'policy_revision', 'name', 'priority', 'enabled'])), array_slice($service['members'], 0, 1))];
        $this->code('dns_member_in_use', fn () => $registry->save($input, $this->admin->id));
        try { (require base_path('database/migrations/2026_11_07_140000_create_machine_failover_services.php'))->down(); $this->fail('Expected retained DNS draft'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('record-set', $e->getMessage()); }
        $this->assertCount(4, $this->dns->overview($service['id'], $view['service_revision'], $this->admin->id)['sets'][0]['slots']); $this->inert();
    }

    public function test_record_set_identity_cannot_move_after_auditing_and_credential_revision_invalidates_continuation(): void
    {
        $view = $this->configure(); $run = $this->start($view, 'replace'); $this->simulation->advance($run['id'], $this->admin->id);
        $input = $this->input() + ['id' => $view['sets'][0]['id'], 'expected_revision' => 1]; $input['hostname'] = 'other.example.test';
        $this->code('dns_set_identity_locked', fn () => $this->dns->save($input, $this->admin->id));
        $run = $this->start($view, 'replace', str_repeat('b', 32));
        MachineDnsCredential::query()->whereKey($this->cf->id)->increment('revision');
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('blocked', $result['status']); $this->assertSame('dns_credential_changed', $result['reason']); $this->assertSame(0, $result['simulated_writes']); $this->inert();
    }

    public function test_unknown_post_crash_state_is_read_only_and_never_sends_the_same_action_twice(): void
    {
        $view = $this->configure(); $run = $this->start($view, 'replace'); $this->simulation->advance($run['id'], $this->admin->id, 1);
        $row = Run::query()->findOrFail($run['id']); $payload = $row->payload; $payload['plan']['actions'][0]['state'] = 'dispatched'; $row->payload = $payload; $row->save();
        $result = app(MachineFailoverDnsSimulation::class)->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(1, $result['actions'][0]['request_count']); $this->assertSame(2, $result['simulated_writes']); $this->inert();
    }

    public function test_detach_does_not_remove_the_last_enabled_member_but_can_remove_a_disabled_member(): void
    {
        $view = $this->configure(); Member::query()->whereKey($this->service['members'][1]['id'])->update(['enabled' => false]);
        $run = $this->start($view, 'detach'); $this->assertSame('blocked', $run['status']); $this->assertSame('dns_last_member', $run['reason']);
        $target = $this->service['members'][1]['id'];
        $run = $this->simulation->start($view['sets'][0]['id'], 1, $view['service_revision'], $target, 'detach', str_repeat('b', 32), $this->admin->id);
        $result = $this->simulation->advance($run['id'], $this->admin->id); $this->assertSame('completed', $result['status']); $this->assertCount(2, $result['records']); $this->inert();
    }

    public function test_admin_controller_rejects_forged_live_flags_and_returns_the_same_synthetic_intent(): void
    {
        $controller = new \App\Http\Controllers\V2\Admin\Server\MachineFailoverDnsController();
        $view = $this->configure('aliyun');
        $request = Request::create('/synthetic', 'POST', ['allow_live' => true, 'receipt' => ['verified' => true]]); $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->simulate($request, $this->simulation)->getStatusCode()); $this->assertSame(0, Run::query()->count());
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request->setUserResolver(fn () => $actor);
            try { $controller->simulate($request, $this->simulation); $this->fail('Expected forbidden'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        }
        $input = ['set_id' => $view['sets'][0]['id'], 'set_revision' => 1, 'service_revision' => $view['service_revision'], 'member_id' => $this->service['members'][0]['id'], 'scenario' => 'replace', 'request_id' => str_repeat('a', 32)];
        $request = Request::create('/synthetic', 'POST', $input); $request->setUserResolver(fn () => $this->admin);
        $body = $controller->simulate($request, $this->simulation)->getData(true); $this->assertSame('completed', $body['data']['status']); $this->assertSame('success', $body['status']);
        $lookup = Request::create('/synthetic', 'GET', ['service_id' => $this->service['id'], 'request_id' => $input['request_id']]); $lookup->setUserResolver(fn () => $this->admin);
        $this->assertSame($body['data']['id'], $controller->report($lookup, $this->simulation)->getData(true)['data']['id']); $this->assertSame(1, Run::query()->count()); $this->inert();
    }

    public function test_one_member_single_stack_change_does_not_prune_another_members_ipv6(): void
    {
        $input = $this->input('aliyun', ['default', 'mobile']); $input['members'][0]['types'] = ['A'];
        $view = $this->dns->save($input, $this->admin->id); $run = $this->simulation->advance($this->start($view, 'replace')['id'], $this->admin->id);
        $this->assertSame('completed', $run['status']); $this->assertCount(2, $run['actions']);
        $other = array_filter($run['records'], fn ($r) => $r['type'] === 'AAAA'); $this->assertCount(2, $other); $this->assertSame(['2001:db8:2::1'], array_values(array_unique(array_column($other, 'content')))); $this->inert();
    }

    public function test_partial_schema_never_falls_back_to_a_legacy_single_record_plan(): void
    {
        $view = $this->configure(); Schema::drop('v2_machine_failover_dns_slot');
        $this->assertFalse($this->dns->available());
        $this->code('migration_required', fn () => app(MachineFailoverServiceRegistry::class)->snapshot($this->service['id'], $view['service_revision']));
        $this->code('migration_required', fn () => app(\App\Services\ServerMachine\Cloud\MachineFailoverSimulation::class)
            ->start($this->service['id'], $view['service_revision'], 'replace', null, str_repeat('c', 32), $this->admin->id));
        $this->inert();
    }

    private function hook(?\Closure $read = null, ?\Closure $write = null): void
    {
        $driver = new class(app(MachineDnsRecordSetProvider::class), $read, $write) extends Driver {
            public function __construct($provider, private ?\Closure $read, private ?\Closure $write) { parent::__construct($provider); }
            public function inventory(string $run, array $set): array
            {
                $rows = parent::inventory($run, $set); $hook = $this->read; $this->read = null; $hook?->__invoke($run); return $rows;
            }
            public function write(string $run, array $set, array $action, ?string $knownId = null): string
            {
                $id = parent::write($run, $set, $action, $knownId); $hook = $this->write; $this->write = null; $hook?->__invoke($run); return $id;
            }
        };
        app()->instance(Driver::class, $driver); $this->simulation = app(MachineFailoverDnsSimulation::class);
    }
    private function calls(string $run, string $kind): array
    {
        return array_values(array_filter(Fixture::query()->findOrFail($run)->payload['calls'], fn ($r) => $r['kind'] === $kind));
    }
    public function test_execution_provider_state_is_independent_from_the_journal_and_preserves_other_members(): void
    {
        $view = $this->configure('aliyun', ['default', 'telecom', 'unicom']); $run = $this->start($view, 'replace');
        $before = Run::query()->findOrFail($run['id'])->payload['records'];
        app(Engine::class)->tick($run['id'], $this->admin->id);
        $this->assertSame($before, Run::query()->findOrFail($run['id'])->payload['records']);
        $this->assertNotSame($before, Fixture::query()->findOrFail($run['id'])->payload['records']);
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertSame(6, $result['verified_actions']);
        $this->assertCount(6, $this->calls($run['id'], 'update')); $this->assertCount(12, $result['records']);
        $this->assertNotNull(Runtime::query()->findOrFail($run['id'])->finished_at); $this->inert();
    }
    public function test_execution_restart_after_provider_applied_a_write_only_reads_back_the_original_action(): void
    {
        $this->hook(write: fn () => throw new \RuntimeException('simulated process interruption'));
        $run = $this->start($this->configure('aliyun', ['default', 'mobile']), 'replace');
        try { app(Engine::class)->tick($run['id'], $this->admin->id); $this->fail('Expected interruption'); } catch (\RuntimeException $e) { $this->assertSame('simulated process interruption', $e->getMessage()); }
        $this->assertSame('dispatched', Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0]['state']);
        $result = app(MachineFailoverDnsSimulation::class)->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertCount(4, $this->calls($run['id'], 'update'));
        $this->assertSame(1, $result['actions'][0]['request_count']); $this->inert();
    }
    public function test_execution_unknown_aliyun_create_cannot_be_adopted_after_a_crash(): void
    {
        $this->hook(write: fn () => throw new \RuntimeException('simulated process interruption'));
        $run = $this->start($this->configure('aliyun'), 'bootstrap');
        try { app(Engine::class)->tick($run['id'], $this->admin->id); } catch (\RuntimeException) {}
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('dns_unclaimed_record', $result['reason']);
        $this->assertCount(1, $this->calls($run['id'], 'create')); $this->assertCount(0, $this->calls($run['id'], 'claim')); $this->inert();
    }
    public function test_execution_claim_crash_keeps_the_acknowledged_record_id_and_does_not_add_again(): void
    {
        $run = $this->start($this->configure('aliyun'), 'bootstrap'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $known = Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0]['known_id'];
        $this->hook(write: fn () => throw new \RuntimeException('simulated claim interruption'));
        try { app(Engine::class)->tick($run['id'], $this->admin->id); } catch (\RuntimeException) {}
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertCount(4, $this->calls($run['id'], 'create')); $this->assertCount(4, $this->calls($run['id'], 'claim'));
        $this->assertSame($known, $this->calls($run['id'], 'claim')[0]['wire']['parameters']['RecordId']); $this->inert();
    }
    public function test_execution_busy_worker_does_no_io_and_expired_lease_can_resume(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $run = $this->start($this->configure('aliyun'), 'replace');
        Runtime::query()->whereKey($run['id'])->update(['generation' => 8, 'lease_hash' => hash('sha256', 'other-worker'), 'lease_until' => Carbon::now()->timestamp + 120]);
        $this->assertFalse(app(Engine::class)->tick($run['id'], $this->admin->id)); $this->assertCount(0, Fixture::query()->findOrFail($run['id'])->payload['calls']);
        Carbon::setTestNow(Carbon::now()->addSeconds(121)); $this->assertTrue(app(Engine::class)->tick($run['id'], $this->admin->id));
        $this->assertSame(9, Runtime::query()->findOrFail($run['id'])->generation); $this->assertCount(1, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_stale_worker_cannot_commit_or_release_a_newer_workers_lease(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $this->hook(write: function ($id) { Runtime::query()->whereKey($id)->update(['generation' => 99, 'lease_hash' => hash('sha256', 'successor'), 'lease_until' => Carbon::now()->timestamp + 120]); });
        $run = $this->start($this->configure('aliyun'), 'replace'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $r = Runtime::query()->findOrFail($run['id']); $this->assertSame(99, $r->generation); $this->assertSame(hash('sha256', 'successor'), $r->lease_hash);
        $this->assertSame('dispatched', Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0]['state']);
        Carbon::setTestNow(Carbon::now()->addSeconds(121));
        $this->assertSame('completed', $this->simulation->advance($run['id'], $this->admin->id)['status']);
        $this->assertCount(2, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_stop_during_preflight_prevents_the_first_write_without_waiting_on_the_worker_lock(): void
    {
        $this->hook(read: fn ($id) => app(Engine::class)->stop($id, $this->admin->id));
        $run = $this->start($this->configure('aliyun'), 'replace'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $this->assertNotNull(Runtime::query()->findOrFail($run['id'])->stop_requested_at);
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('stopped', $result['status']); $this->assertSame(0, $result['simulated_writes']); $this->assertCount(0, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_stop_during_inflight_write_settles_read_only_and_never_rolls_back_or_advances_other_lines(): void
    {
        $this->hook(write: fn ($id) => app(Engine::class)->stop($id, $this->admin->id));
        $run = $this->start($this->configure('aliyun', ['default', 'telecom', 'unicom']), 'replace');
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('stopped', $result['status']); $this->assertTrue($result['stop_requested']);
        $this->assertSame(1, $result['verified_actions']); $this->assertSame(1, $result['simulated_writes']);
        $this->assertCount(1, $this->calls($run['id'], 'update')); $this->assertCount(0, $this->calls($run['id'], 'delete'));
        $this->assertSame($result, $this->simulation->advance($run['id'], $this->admin->id)); $this->inert();
    }
    public function test_execution_stop_between_add_and_remark_keeps_the_unclaimed_record_for_review(): void
    {
        $run = $this->start($this->configure('aliyun'), 'bootstrap'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $result = $this->simulation->stop($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame('dns_unclaimed_record', $result['reason']);
        $this->assertCount(1, $this->calls($run['id'], 'create')); $this->assertCount(0, $this->calls($run['id'], 'claim')); $this->assertCount(0, $this->calls($run['id'], 'delete')); $this->inert();
    }
    public function test_execution_backoff_and_read_budget_are_persisted_and_do_not_reissue_mutations(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $run = $this->start($this->configure('aliyun'), 'readback_rate_limited'); $engine = app(Engine::class);
        $engine->tick($run['id'], $this->admin->id); $engine->tick($run['id'], $this->admin->id);
        $this->assertSame(Carbon::now()->timestamp + 120, Runtime::query()->findOrFail($run['id'])->next_check_at);
        $calls = count($this->calls($run['id'], 'inventory')); $this->assertFalse($engine->tick($run['id'], $this->admin->id)); $this->assertCount($calls, $this->calls($run['id'], 'inventory'));
        Carbon::setTestNow(Carbon::now()->addSeconds(121)); $engine->tick($run['id'], $this->admin->id); $this->assertCount(1, $this->calls($run['id'], 'update'));
        $this->simulation->advance($run['id'], $this->admin->id);
        $run = $this->start($this->dns->overview($this->service['id'], 2, $this->admin->id), 'unknown_unresolved', str_repeat('b', 32));
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertSame(12, Run::query()->findOrFail($run['id'])->payload['plan']['actions'][0]['readback_attempts']);
        $this->assertCount(1, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_rechecks_authority_after_preflight_before_dispatch_and_at_final_completion(): void
    {
        $this->hook(read: fn () => MachineDnsCredential::query()->whereKey($this->ali->id)->increment('revision'));
        $run = $this->start($this->configure('aliyun'), 'replace');
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('blocked', $result['status']); $this->assertSame('dns_credential_changed', $result['reason']);
        $this->assertSame(0, $result['simulated_writes']); $this->inert();
    }
    public function test_execution_final_inventory_cannot_complete_after_credential_revision_changes(): void
    {
        $run = $this->start($this->configure('aliyun'), 'replace');
        for ($i = 0; $i < 4; $i++) app(Engine::class)->tick($run['id'], $this->admin->id);
        $this->assertSame(2, Run::query()->findOrFail($run['id'])->cursor);
        $this->hook(read: fn () => MachineDnsCredential::query()->whereKey($this->ali->id)->increment('revision'));
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertNull(Runtime::query()->findOrFail($run['id'])->fresh()->next_check_at); $this->inert();
    }
    public function test_execution_tampered_journal_and_legacy_history_cannot_be_redispatched(): void
    {
        $run = $this->start($this->configure('aliyun'), 'replace'); $row = Run::query()->findOrFail($run['id']); $p = $row->payload;
        $p['plan']['actions'][0]['after']['content'] = '203.0.113.250'; $row->payload = $p; $row->save();
        $result = $this->simulation->advance($run['id'], $this->admin->id); $this->assertSame('dns_journal_changed', $result['reason']); $this->assertSame(0, $result['simulated_writes']);
        Runtime::query()->whereKey($run['id'])->delete(); Fixture::query()->whereKey($run['id'])->delete();
        $this->assertSame('legacy_plan_v1', $this->simulation->report($run['id'], $this->admin->id)['execution_engine']);
        $this->code('dns_legacy_read_only', fn () => app(Engine::class)->tick($run['id'], $this->admin->id)); $this->inert();
    }
    public function test_execution_driver_downgrade_and_live_entry_points_remain_closed(): void
    {
        $run = $this->start($this->configure('aliyun'), 'replace');
        try { (require base_path('database/migrations/2026_11_09_200000_create_machine_dns_set_runtime.php'))->down(); $this->fail('Expected retained execution'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('retained', $e->getMessage()); }
        config(['machine_provision.allow_live' => true, 'machine_acceptance.allow_dns' => true, 'machine_failover.allow_rotation' => true]);
        $this->code('orchestration_disabled', fn () => app(Engine::class)->initialize(Run::query()->findOrFail($run['id'])));
        $set = Run::query()->findOrFail($run['id'])->payload['snapshot']['set'];
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport::class)->inventory($run['id'], $set));
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Dns\DnsProviderClient::class)->recordSetInventory($this->ali, $set));
        config(['machine_provision.allow_live' => false, 'machine_acceptance.allow_dns' => false, 'machine_failover.allow_rotation' => false]); $this->inert();
    }
    public static function executionLines(): array { return array_map(fn ($s) => [$s], ['replace', 'bootstrap', 'detach', 'restore', 'unknown_applied', 'claim_unknown']); }
    #[DataProvider('executionLines')]
    public function test_execution_all_six_aliyun_lines_and_both_families_complete_without_pruning_another_member(string $scenario): void
    {
        $view = $this->configure('aliyun', MachineDnsRecordSetProvider::LINES); $run = $this->start($view, $scenario);
        $result = $this->simulation->advance($run['id'], $this->admin->id);
        $this->assertSame('completed', $result['status']); $this->assertCount($scenario === 'detach' ? 12 : 24, $result['records']);
        $this->assertSame($scenario === 'detach' ? ['delete'] : ($scenario === 'bootstrap' || $scenario === 'claim_unknown' ? ['create'] : ['update']), array_values(array_unique(array_column($result['actions'], 'kind'))));
        $this->assertSame(['A', 'AAAA'], array_values(array_unique(array_column($result['actions'][0]['kind'] === 'delete'
            ? array_column($result['actions'], 'before') : array_column($result['actions'], 'after'), 'type'))));
        $this->assertSame(MachineDnsRecordSetProvider::LINES, array_values(array_unique(array_column($result['records'], 'line'))));
        $this->assertSame(count($result['actions']), $result['verified_actions']); $this->inert();
    }
    public function test_execution_transient_preflight_read_is_delayed_and_recovered_without_a_write_retry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00:00', 'UTC'));
        $this->hook(read: fn () => throw new \App\Services\ServerMachine\Dns\DnsRequestFailure('provider_unavailable', 30));
        $run = $this->start($this->configure('aliyun'), 'replace'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $this->assertSame('running', Run::query()->findOrFail($run['id'])->status);
        $this->assertSame(Carbon::now()->timestamp + 30, Runtime::query()->findOrFail($run['id'])->next_check_at);
        $this->assertCount(0, $this->calls($run['id'], 'update'));
        Carbon::setTestNow(Carbon::now()->addSeconds(31));
        $this->assertSame('completed', $this->simulation->advance($run['id'], $this->admin->id)['status']);
        $this->assertCount(2, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_stop_after_unknown_unapplied_write_never_retries_or_reports_a_clean_stop(): void
    {
        $run = $this->start($this->configure('aliyun'), 'unknown_unresolved'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $result = $this->simulation->stop($run['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['status']); $this->assertTrue($result['stop_requested']);
        $this->assertSame(0, $result['simulated_writes']); $this->assertCount(1, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_revoked_admin_after_inventory_prevents_dispatch_and_a_changed_wire_journal_cannot_be_accepted(): void
    {
        $this->hook(read: fn () => User::query()->whereKey($this->admin->id)->update(['banned' => true]));
        $run = $this->start($this->configure('aliyun'), 'replace'); app(Engine::class)->tick($run['id'], $this->admin->id);
        $this->assertSame('blocked', Run::query()->findOrFail($run['id'])->status); $this->assertCount(0, $this->calls($run['id'], 'update'));
        User::query()->whereKey($this->admin->id)->update(['banned' => false]);
        $run = $this->start($this->dns->overview($this->service['id'], 2, $this->admin->id), 'replace', str_repeat('b', 32));
        app(Engine::class)->tick($run['id'], $this->admin->id); $row = Run::query()->findOrFail($run['id']); $p = $row->payload;
        $p['plan']['actions'][0]['requests'][0]['parameters']['RecordId'] = '999999999999'; $row->payload = $p; $row->save();
        $result = $this->simulation->advance($run['id'], $this->admin->id); $this->assertSame('dns_journal_changed', $result['reason']);
        $this->assertSame('needs_review', $result['status']); $this->assertCount(1, $this->calls($run['id'], 'update')); $this->inert();
    }
    public function test_execution_fixture_does_not_hide_an_accidental_duplicate_create(): void
    {
        $run = $this->start($this->configure(), 'bootstrap'); $p = Run::query()->findOrFail($run['id'])->payload;
        $driver = app(Driver::class); $first = $driver->write($run['id'], $p['snapshot']['set'], $p['plan']['actions'][0]);
        $second = $driver->write($run['id'], $p['snapshot']['set'], $p['plan']['actions'][0]);
        $this->assertNotSame($first, $second); $this->assertCount(2, Fixture::query()->findOrFail($run['id'])->payload['records']);
        $this->assertCount(2, $this->calls($run['id'], 'create'));
        $result = $this->simulation->advance($run['id'], $this->admin->id); $this->assertSame('blocked', $result['status']);
        $this->assertCount(2, $this->calls($run['id'], 'create')); $this->inert();
    }
}
