<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineDnsBinding;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\LinodeClient;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\ProvisionBootstrap;
use App\Services\ServerMachine\Dns\DnsException;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\MachineDnsService;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverPolicyTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private MachineFailoverPolicyService $service;
    private MachineAddressInventory $inventory;
    private Account $account;
    private ServerMachine $machine;
    private MachineDnsCredential $credential;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('f', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
        config(['machine_provision' => require base_path('config/machine_provision.php')]);
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_05_160000_create_machine_dns_tables.php', '2026_11_06_030000_create_machine_failover_policies.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id'); $table->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Independent synthetic', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true]);
        $this->account = Account::query()->create(['name' => 'Synthetic cloud', 'provider' => 'linode',
            'credentials' => ['api_token' => 'synthetic-cloud-secret'], 'revision' => 1]);
        $this->credential = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare',
            'credentials' => ['api_token' => 'synthetic-dns-secret'], 'revision' => 1]);
        $this->inventory = new MachineAddressInventory();
        $this->service = new MachineFailoverPolicyService($this->inventory, new NodeInstanceRegistry(), new ProvisionBootstrap());
    }

    private function input(array $changes = []): array
    {
        return array_replace(['name' => 'Independent Singapore', 'machine_id' => $this->machine->id, 'account_id' => $this->account->id,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 2,
            'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100, 'daily_limit' => 1,
            'dns_credential_id' => $this->credential->id, 'dns_hostname' => 'new.example.test', 'isolation_confirmed' => true], $changes);
    }

    private function save(array $changes = []): array { return $this->service->save($this->input($changes), $this->admin->id); }

    private function code(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); }
        catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }

    public function test_drafts_are_revisioned_but_never_enabled_or_dispatched(): void
    {
        $row = $this->save(['name' => '  Independent   Singapore  ', 'dns_hostname' => 'NEW.Example.Test.']);
        $this->assertSame('Independent Singapore', $row['name']); $this->assertSame('new.example.test', $row['dns_hostname']);
        $this->assertSame('draft', $row['status']); $this->assertFalse($row['execution_enabled']);
        $this->assertStringStartsWith('independent:', $row['slot']);
        $this->assertFalse(config('machine_provision.allow_live')); $this->assertSame([], config('machine_provision.allowed_test_machine_ids'));
        $next = $this->save(['id' => $row['id'], 'expected_revision' => 1, 'name' => 'New label']);
        $this->assertSame(2, $next['revision']); $this->assertSame($row['slot'], $next['slot']);
        $this->code('config_changed', fn () => $this->save(['id' => $row['id'], 'expected_revision' => 1]));
        $this->code('config_changed', fn () => $this->service->drop($row['id'], 1, $this->admin->id));
        $this->service->drop($row['id'], 2, $this->admin->id);
        $this->assertSame(0, Policy::query()->count()); $this->assertSame(1, Account::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_money_can_be_omitted_and_legacy_values_are_preserved_without_gating(): void
    {
        $input = $this->input(); unset($input['monthly_limit_usd']);
        $row = $this->service->save($input, $this->admin->id);
        $this->assertSame(0.0, $row['monthly_limit_usd']);
        $this->assertNotContains('budget_required', array_column($this->service->rehearse($row['id'], 1, 'current')['checks'], 'code'));
        Policy::query()->whereKey($row['id'])->update(['monthly_limit_usd' => 48]);
        $row = $this->service->save($input + ['id' => $row['id'], 'expected_revision' => 1], $this->admin->id);
        $this->assertSame(48.0, $row['monthly_limit_usd']);
        $this->assertFalse($row['execution_enabled']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_overview_and_results_export_only_selected_metadata(): void
    {
        $row = $this->save(); $overview = $this->service->overview();
        $this->assertTrue($overview['available']); $this->assertFalse($overview['execution_enabled']);
        $this->assertSame(['linode'], $overview['supported_providers']);
        $this->assertSame(1, $overview['machines'][0]['node_count']); $this->assertTrue($overview['machines'][0]['candidate_available']);
        $json = json_encode([$row, $overview, $this->service->rehearse($row['id'], 1, 'current')]);
        foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', 'machine_authority',
            'updated_by', 'authorized_keys', 'root_pass', 'user_data', '#cloud-config', 'callback_hash'] as $secret) $this->assertStringNotContainsString($secret, $json);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function scenarios(): array { return [['current'], ['initial_setup'], ['agent_stale'], ['ipv4_unreachable']]; }

    #[DataProvider('scenarios')]
    public function test_rehearsal_is_read_only_even_when_other_live_config_is_enabled(string $scenario): void
    {
        $row = $this->save(); $before = Policy::query()->findOrFail($row['id'])->getRawOriginal();
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => [$this->machine->id]]);
        DB::enableQueryLog();
        $result = $this->service->rehearse($row['id'], 1, $scenario);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertFalse($result['executed']); $this->assertFalse($result['execution_enabled']);
        $this->assertFalse($result['provider_checked']); $this->assertFalse($result['pricing_checked']);
        $this->assertFalse($result['external_ownership_verified']);
        $this->assertSame($scenario !== 'current', $result['simulated']);
        $this->assertSame($scenario === 'current' ? 'not_evaluated' : 'simulated_match', $result['trigger']['state']);
        $this->assertNull($result['trigger']['observed_failures']);
        $this->assertSame(['planned'], array_values(array_unique(array_column($result['steps'], 'status'))));
        $this->assertContains(['code' => 'execution_disabled', 'status' => 'blocked'], $result['checks']);
        $this->assertContains(['code' => 'provider_not_checked', 'status' => 'unverified'], $result['checks']);
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        $this->assertSame($before, Policy::query()->findOrFail($row['id'])->getRawOriginal());
        $this->assertSame(0, DB::table('v2_machine_cloud_task')->count()); $this->assertSame(0, DB::table('v2_machine_dns_log')->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function invalidInputs(): array
    {
        return [
            'enabled' => [['enabled' => true], 'invalid_policy'], 'automatic' => [['automatic' => true], 'invalid_policy'],
            'legacy source' => [['source_ref' => ['version' => 'v2']], 'invalid_policy'],
            'script' => [['script' => 'echo unsafe'], 'invalid_policy'], 'private credentials' => [['api_token' => 'secret'], 'invalid_policy'],
            'bad name' => [['name' => "bad\nname"], 'invalid_name'], 'empty name' => [['name' => ' '], 'invalid_name'],
            'coerced id' => [['machine_id' => '1'], 'invalid_policy'], 'one failure' => [['failure_threshold' => 1], 'invalid_policy'],
            'zero cooldown' => [['cooldown_seconds' => 0], 'invalid_policy'], 'too many attempts' => [['daily_limit' => 3], 'invalid_policy'],
            'NaN' => [['monthly_limit_usd' => NAN], 'invalid_budget'], 'budget scale' => [['monthly_limit_usd' => 1.001], 'invalid_budget'],
            'negative budget' => [['monthly_limit_usd' => -1], 'invalid_budget'], 'huge budget' => [['monthly_limit_usd' => 1001], 'invalid_budget'],
            'host URL' => [['dns_hostname' => 'https://example.test/path'], 'invalid_dns'],
            'wildcard' => [['dns_hostname' => '*.example.test'], 'invalid_dns'], 'host IP' => [['dns_hostname' => '8.8.8.8'], 'invalid_dns'],
            'bad credential' => [['dns_credential_id' => -1], 'invalid_dns'],
            'false declaration type' => [['isolation_confirmed' => 'true'], 'invalid_policy'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_unsafe_or_invalid_inputs_cannot_create_a_policy(array $changes, string $reason): void
    {
        $this->code($reason, fn () => $this->save($changes));
        $this->assertSame(0, Policy::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_missing_and_unsupported_accounts_are_not_claimed_as_creation_support(): void
    {
        $this->code('credentials_required', fn () => $this->save(['account_id' => 999]));
        $this->account->provider = 'digitalocean'; $this->account->save();
        $this->code('unsupported_provider', fn () => $this->save());
        $this->account->provider = 'vultr'; $this->account->save();
        $this->code('unsupported_provider', fn () => $this->save());
        $this->assertSame(0, Policy::query()->count()); Http::assertNothingSent();
    }

    public function test_incomplete_drafts_stay_blocked_without_fabricated_readiness(): void
    {
        $row = $this->save(['monthly_limit_usd' => 0, 'isolation_confirmed' => false, 'dns_credential_id' => null, 'dns_hostname' => '']);
        DB::table('v2_server')->delete();
        $result = $this->service->rehearse($row['id'], 1, 'current');
        foreach (['dns_not_configured', 'isolation_confirmation_required', 'node_group_empty'] as $code) {
            $this->assertContains(['code' => $code, 'status' => 'blocked'], $result['checks']);
        }
        $this->assertFalse($result['execution_enabled']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_unsupported_owned_dns_provider_blocks_replacement_before_any_creation_intent(): void
    {
        $credential = MachineDnsCredential::query()->create(['name' => 'Synthetic Aliyun', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret']]);
        $row = $this->save(['dns_credential_id' => $credential->id, 'dns_hostname' => 'isolated.example.test']);
        $checks = $this->service->localChecks(Policy::query()->findOrFail($row['id']));
        $this->assertContains(['code' => 'owned_dns_unsupported', 'status' => 'blocked'], $checks);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function machineUsage(): array { return [['agent'], ['nodes'], ['cloud'], ['dns']]; }

    #[DataProvider('machineUsage')]
    public function test_existing_machine_ownership_is_not_reused(string $mode): void
    {
        match ($mode) {
            'agent' => $this->inventory->record($this->machine, str_repeat('a', 32), ['public_ipv4' => '8.8.8.8'], time()),
            'nodes' => Cache::put('node_instances:10', [['last_seen_at' => time(), 'machine_id' => $this->machine->id]], 300),
            'cloud' => $this->resource($this->machine),
            'dns' => $this->binding($this->machine),
        };
        $this->assertFalse($this->service->overview()['machines'][0]['candidate_available']);
        $this->code('machine_in_use', fn () => $this->save());
        $this->assertSame(0, Policy::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_dns_hostname_reservation_works_both_directions_across_record_families(): void
    {
        $other = ServerMachine::query()->create(['name' => 'Existing synthetic', 'token' => 'old-secret', 'is_active' => true]);
        $otherCredential = MachineDnsCredential::query()->create(['name' => 'Other synthetic DNS', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret'], 'revision' => 1]);
        $binding = $this->binding($other, 'new.example.test', 'AAAA');
        $binding->fill(['credential_id' => $otherCredential->id, 'zone' => 'example.test', 'record_id' => '123'])->save();
        $this->code('dns_record_conflict', fn () => $this->save());
        $binding->delete(); $row = $this->save();
        $this->code('dns_record_conflict', fn () => $this->save(['name' => 'Second', 'dns_hostname' => 'NEW.example.TEST.']));
        $dns = new MachineDnsService(new DnsProviderClient(), $this->inventory);
        try {
            $dns->createBinding(['credential_id' => $otherCredential->id, 'machine_id' => $other->id,
                'installation_id' => str_repeat('d', 32), 'zone' => 'example.test', 'record_id' => '123',
                'hostname' => 'NEW.example.TEST.', 'type' => 'AAAA'], $this->admin->id);
            $this->fail('Reserved hostname must not become an ordinary DNS binding.');
        } catch (DnsException $error) { $this->assertSame('record_in_use', $error->getMessage()); }
        $this->service->drop($row['id'], 1, $this->admin->id);
        $this->assertSame(0, MachineDnsBinding::query()->count()); Http::assertNothingSent();
    }

    public function test_policy_references_protect_credentials_and_changes_require_revalidation(): void
    {
        $row = $this->save();
        $cloud = new MachineCloudService(new LinodeClient(), new MachineCloudHealth($this->inventory, new NodeInstanceRegistry()));
        $this->code('account_in_use', fn () => $cloud->deleteAccount($this->account->id, 1));
        $dns = new MachineDnsService(new DnsProviderClient(), $this->inventory);
        try { $dns->deleteCredential($this->credential->id, 1, $this->admin->id); $this->fail('Credential is referenced.'); }
        catch (DnsException $error) { $this->assertSame('credential_in_use', $error->getMessage()); }
        $this->account->revision = 2; $this->account->save();
        $this->credential->revision = 2; $this->credential->save();
        $result = $this->service->rehearse($row['id'], 1, 'current');
        foreach (['account_changed', 'dns_credential_changed'] as $code) $this->assertContains(['code' => $code, 'status' => 'blocked'], $result['checks']);
        $next = $this->save(['id' => $row['id'], 'expected_revision' => 1]);
        $this->assertSame(2, $next['revision']);
        $this->code('config_changed', fn () => $this->service->rehearse($row['id'], 1, 'current'));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_new_reports_during_draft_lifetime_are_detected_without_changing_them(): void
    {
        $row = $this->save();
        $this->inventory->record($this->machine, str_repeat('a', 32), ['public_ipv4' => '8.8.8.8'], time());
        $before = $this->inventory->snapshots([$this->machine]);
        $result = $this->service->rehearse($row['id'], 1, 'current');
        $this->assertSame('fresh', $result['current']['agent']); $this->assertSame(1, $result['current']['active_instances']);
        $this->assertSame('insufficient_samples', $result['trigger']['state']);
        $this->assertContains(['code' => 'machine_in_use', 'status' => 'blocked'], $result['checks']);
        $this->assertSame($before, $this->inventory->snapshots([$this->machine]));
        $this->code('machine_in_use', fn () => $this->save(['id' => $row['id'], 'expected_revision' => 1]));
    }

    public static function probeStates(): array { return [[true, 'reachable'], [false, 'unreachable']]; }

    #[DataProvider('probeStates')]
    public function test_one_reachable_target_is_green_and_all_failed_targets_are_shown_without_execution(bool $oneReachable, string $expected): void
    {
        $row = $this->save();
        $config = app(\App\Services\ServerMachine\MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => time(), 'consecutive_failures' => 2,
            'targets' => array_map(fn ($target, $index) => ['id' => $target['id'], 'status' => $oneReachable && $index === 0 ? 'reachable' : 'unreachable', 'source_ip' => '8.8.8.8'], $config['targets'], array_keys($config['targets']))];
        $this->inventory->record($this->machine, str_repeat('a', 32), ['public_ipv4' => '8.8.8.8'], time(), [], $probe);
        $result = $this->service->rehearse($row['id'], 1, 'current');
        $this->assertSame($expected, $result['current']['ipv4_probe']);
        $this->assertSame($oneReachable ? 'healthy' : 'insufficient_samples', $result['trigger']['state']);
        $this->assertFalse($result['executed']);
        if (!$oneReachable) {
            $this->inventory->record($this->machine, str_repeat('b', 32), ['public_ipv4' => '8.8.4.4'], time());
            $this->assertSame('unknown', $this->service->rehearse($row['id'], 1, 'current')['current']['ipv4_probe']);
        }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_hostname_mutex_is_shared_with_ordinary_dns_binding_creation(): void
    {
        $otherCredential = MachineDnsCredential::query()->create(['name' => 'Other synthetic DNS', 'provider' => 'aliyun',
            'credentials' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret'], 'revision' => 1]);
        $lock = Cache::lock('machine-dns:hostname:' . hash('sha256', 'new.example.test'), 30);
        $this->assertTrue($lock->get());
        try {
            try { $this->save(); $this->fail('Expected shared hostname lock.'); }
            catch (\Illuminate\Contracts\Cache\LockTimeoutException) { $this->assertSame(0, Policy::query()->count()); }
            $dns = new MachineDnsService(new DnsProviderClient(), $this->inventory);
            try {
                $dns->createBinding(['credential_id' => $otherCredential->id, 'machine_id' => $this->machine->id,
                    'installation_id' => str_repeat('a', 32), 'hostname' => 'NEW.example.TEST.', 'zone' => 'example.test',
                    'record_id' => '123', 'type' => 'A'], $this->admin->id);
                $this->fail('Expected shared hostname lock.');
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) { $this->assertSame(0, MachineDnsBinding::query()->count()); }
        } finally { $lock->release(); }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function referenceMutexes(): array { return [['cloud'], ['dns']]; }

    #[DataProvider('referenceMutexes')]
    public function test_policy_writes_and_credential_deletion_use_the_same_reference_mutex(string $kind): void
    {
        $key = $kind === 'cloud' ? 'machine-cloud:account:' . $this->account->id : 'machine-dns:credential:' . $this->credential->id;
        $lock = Cache::lock($key, 30); $this->assertTrue($lock->get());
        try {
            try { $this->save(); $this->fail('Expected reference lock.'); }
            catch (\Illuminate\Contracts\Cache\LockTimeoutException) { $this->assertSame(0, Policy::query()->count()); }
            try {
                if ($kind === 'cloud') {
                    (new MachineCloudService(new LinodeClient(), new MachineCloudHealth($this->inventory, new NodeInstanceRegistry())))->deleteAccount($this->account->id, 1);
                } else {
                    (new MachineDnsService(new DnsProviderClient(), $this->inventory))->deleteCredential($this->credential->id, 1, $this->admin->id);
                }
                $this->fail('Expected reference lock.');
            } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
                $this->assertSame(1, Account::query()->count()); $this->assertSame(1, MachineDnsCredential::query()->count());
            }
        } finally { $lock->release(); }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_stale_instance_probe_cannot_be_combined_with_a_different_fresh_agent(): void
    {
        $row = $this->save(['stale_after_seconds' => 30]);
        $config = app(\App\Services\ServerMachine\MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => time(),
            'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => 'reachable', 'source_ip' => '8.8.8.8'], $config['targets'])];
        $this->inventory->record($this->machine, str_repeat('a', 32), ['public_ipv4' => '8.8.8.8'], time() - 35, [], $probe);
        $this->inventory->record($this->machine, str_repeat('b', 32), ['public_ipv4' => '8.8.4.4'], time());
        $result = $this->service->rehearse($row['id'], 1, 'current');
        $this->assertSame('fresh', $result['current']['agent']);
        $this->assertSame(1, $result['current']['active_instances']);
        $this->assertSame('unknown', $result['current']['ipv4_probe']);
        $this->assertSame('insufficient_samples', $result['trigger']['state']);
        $this->assertFalse($result['executed']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_unrelated_old_resources_nodes_credentials_and_dns_are_unchanged(): void
    {
        $old = ServerMachine::query()->create(['name' => 'Existing synthetic', 'token' => 'old-secret', 'is_active' => true]);
        $resource = $this->resource($old); $binding = $this->binding($old, 'old.example.test');
        DB::table('v2_server')->insert(['id' => 11, 'machine_id' => $old->id, 'enabled' => true]);
        $before = [$old->fresh()->getRawOriginal(), $resource->fresh()->getRawOriginal(), $binding->fresh()->getRawOriginal(),
            $this->account->fresh()->getRawOriginal(), $this->credential->fresh()->getRawOriginal()];
        $row = $this->save();
        foreach (['current', 'initial_setup', 'agent_stale', 'ipv4_unreachable'] as $scenario) $this->service->rehearse($row['id'], 1, $scenario);
        $this->service->drop($row['id'], 1, $this->admin->id);
        $this->assertSame($before, [$old->fresh()->getRawOriginal(), $resource->fresh()->getRawOriginal(), $binding->fresh()->getRawOriginal(),
            $this->account->fresh()->getRawOriginal(), $this->credential->fresh()->getRawOriginal()]);
        $this->assertSame($old->id, DB::table('v2_server')->where('id', 11)->value('machine_id'));
        $this->assertSame(0, DB::table('v2_machine_cloud_task')->count()); $this->assertSame(0, DB::table('v2_machine_dns_log')->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_administrator_authority_and_schema_are_required(): void
    {
        $controller = new MachineFailoverController();
        foreach (['fetch', 'save', 'drop', 'rehearse'] as $method) {
            foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
                $request = Request::create('/synthetic', 'POST', ['enabled' => true]); $request->setUserResolver(fn () => $actor);
                try { $controller->{$method}($request, $this->service); $this->fail('Admin required.'); }
                catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
            }
        }
        $this->admin->banned = true; $this->admin->save();
        $this->code('permission_revoked', fn () => $this->save());
        $this->admin->banned = false; $this->admin->save();
        (require base_path('database/migrations/2026_11_06_030000_create_machine_failover_policies.php'))->down();
        $this->assertFalse($this->service->overview()['available']);
        $this->code('migration_required', fn () => $this->save());
        $this->assertTrue(Schema::hasTable('v2_machine_cloud_resource'));
        $this->assertTrue(Schema::hasTable('v2_machine_dns_binding'));
    }

    private function resource(ServerMachine $machine): Resource
    {
        return Resource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode', 'external_id' => '123',
            'label' => 'Existing synthetic', 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'provider_created_at' => '2026-01-01T00:00:00',
            'status' => 'running', 'public_ips' => ['8.8.4.4'], 'observed_at' => time(), 'machine_id' => $machine->id,
            'auto_boot' => true, 'armed' => true, 'manual_hold' => false]);
    }

    private function binding(ServerMachine $machine, string $hostname = 'old.example.test', string $type = 'A'): MachineDnsBinding
    {
        return MachineDnsBinding::query()->create(['credential_id' => $this->credential->id, 'machine_id' => $machine->id,
            'installation_id' => str_repeat('d', 32), 'machine_authority' => hash('sha256', (string) $machine->token),
            'record_scope' => hash('sha256', $hostname . $type), 'zone' => str_repeat('a', 32), 'record_id' => str_repeat('b', 32),
            'hostname' => $hostname, 'type' => $type, 'enabled' => true, 'last_ip' => '8.8.4.4', 'status' => 'synced']);
    }
}
