<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
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
            '2026_11_09_180000_create_machine_failover_dns_sets.php'] as $file) (require base_path('database/migrations/' . $file))->up();
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
}
