<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup as Group;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCloudCatalog;
use App\Services\ServerMachine\Cloud\MachineCloudGroupService;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineProviderGroupsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private Group $group;
    private Account $linode;
    private Account $second;
    private User $admin;
    private ServerMachine $machine;
    private ?string $fault = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('p', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $this->bindTestSettings(['app_url' => 'https://panel.example.test']);
        config(['machine_provision' => require base_path('config/machine_provision.php'),
            'machine_failover' => require base_path('config/machine_failover.php'),
            'machine_acceptance' => require base_path('config/machine_acceptance.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_05_160000_create_machine_dns_tables.php',
            '2026_11_05_220000_create_machine_provision_tasks.php', '2026_11_06_030000_create_machine_failover_policies.php',
            '2026_11_07_120000_add_provider_groups_to_machine_failover.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); $t->string('type'); $t->integer('port'); $t->integer('server_port'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic isolated', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true, 'type' => 'hysteria', 'port' => 8443, 'server_port' => 8443]);
        $this->group = app(MachineCloudGroupService::class)->save(['name' => 'Mixed synthetic']);
        $this->linode = $this->account('linode'); $this->second = $this->account('linode');
        $this->account('digitalocean'); $this->account('vultr');
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $this->assertSame('GET', $request->method());
            if ($this->fault === 'forbidden') return Http::response([], 403);
            $path = parse_url($request->url(), PHP_URL_PATH);
            $provider = str_contains($request->url(), 'api.linode.com') ? 'linode' : (str_contains($request->url(), 'api.digitalocean.com') ? 'digitalocean' : 'vultr');
            $collections = $this->rows($provider); $key = basename($path);
            $items = $collections[$key] ?? throw new \RuntimeException('Unexpected catalog URL');
            if ($this->fault === 'duplicate') $items[] = $items[0];
            if ($this->fault === 'malformed') $items = ['invalid'];
            if ($this->fault === 'move') Account::query()->whereKey($this->linode->id)->update(['group_revision' => 2]);
            $result = $provider === 'linode' ? ['data' => $items, 'pages' => 1, 'page' => 1, 'results' => count($items)]
                : [$key => $items, 'meta' => ['total' => count($items)]];
            if ($this->fault === 'incomplete') {
                if ($provider === 'linode') $result['pages'] = 4;
                else $result['meta']['total'] = 301;
            }
            if ($this->fault === 'miscount' && $provider !== 'linode') $result['meta']['total'] = 0;
            if ($this->fault === 'next' && $provider === 'digitalocean') $result['links']['pages']['next'] = 'https://untrusted.example.test/next';
            return Http::response($result);
        });
    }

    private function account(string $provider): Account
    {
        return Account::query()->create(['name' => 'Synthetic ' . $provider, 'provider' => $provider, 'revision' => 1,
            'group_id' => $this->group->id, 'group_revision' => 1, 'credentials' => ['api_token' => 'synthetic-never-output-secret']]);
    }
    private function rows(string $provider): array
    {
        return match ($provider) {
            'linode' => ['regions' => [['id' => 'sg-sin-2', 'label' => 'Singapore', 'status' => 'ok', 'site_type' => 'core', 'capabilities' => ['Linodes', 'Metadata']],
                ['id' => 'distributed-site', 'label' => 'Distributed', 'status' => 'ok', 'site_type' => 'distributed', 'capabilities' => ['Linodes', 'Metadata']]],
                'types' => [['id' => 'g6-standard-4', 'class' => 'standard', 'vcpus' => 4, 'memory' => 8192, 'disk' => 163840, 'price' => ['monthly' => 48]]],
                'images' => [['id' => 'linode/debian12', 'label' => 'Debian 12', 'is_public' => true, 'deprecated' => false, 'status' => 'available', 'capabilities' => ['cloud-init']]]],
            'digitalocean' => ['regions' => [['slug' => 'sgp1', 'name' => 'Singapore', 'available' => true], ['slug' => 'ams3', 'name' => 'Amsterdam', 'available' => true]],
                'sizes' => [['slug' => 's-1vcpu-1gb', 'available' => true, 'vcpus' => 1, 'memory' => 1024, 'disk' => 25, 'price_monthly' => 6, 'regions' => ['sgp1']]],
                'images' => [['slug' => 'debian-12-x64', 'name' => 'Debian 12', 'public' => true, 'status' => 'available', 'type' => 'base', 'regions' => ['sgp1'], 'min_disk_size' => 10],
                    ['slug' => null, 'name' => 'Private snapshot', 'public' => false]]],
            'vultr' => ['regions' => [['id' => '40', 'city' => 'Singapore']],
                'plans' => [['id' => 'vc2-1c-1gb', 'type' => 'vc2', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 5, 'locations' => ['40']]],
                'os' => [['id' => 2136, 'name' => 'Debian 12', 'arch' => 'x64', 'family' => 'debian']]],
        };
    }
    private function catalog(string $provider = 'linode', bool $refresh = false): array { return app(MachineCloudCatalog::class)->read($this->group->id, $provider, $refresh); }
    private function input(array $catalog): array
    {
        return ['name' => 'Independent group', 'provider' => $catalog['provider'], 'credential_group_id' => $this->group->id,
            'catalog_fingerprint' => $catalog['fingerprint'], 'machine_id' => $this->machine->id,
            'region' => $catalog['regions'][0]['id'], 'size' => $catalog['sizes'][0]['id'], 'image' => $catalog['images'][0]['id'],
            'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100,
            'daily_limit' => 1, 'dns_credential_id' => null, 'dns_hostname' => '', 'isolation_confirmed' => true];
    }
    private function policy(): Policy
    {
        $row = app(MachineFailoverPolicyService::class)->save($this->input($this->catalog()), $this->admin->id);
        return Policy::query()->findOrFail($row['id']);
    }
    private function code(string $expected, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $expected); }
        catch (CloudException $error) { $this->assertSame($expected, $error->getMessage()); }
    }
    private function history(Policy $policy, Account $account, string $status = 'failed', ?string $slot = null): Task
    {
        $id = (string) Str::uuid();
        return Task::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id,
            'account_id' => $account->id, 'account_revision' => 1, 'machine_id' => $policy->machine_id,
            'machine_authority' => $policy->machine_authority, 'plan_hash' => hash('sha256', $id), 'slot' => $slot ?? $policy->slot,
            'installation_id' => bin2hex(random_bytes(16)), 'callback_hash' => hash('sha256', 'callback' . $id),
            'payload' => ['plan' => ['account_id' => $account->id]], 'marker' => hash('sha256', 'marker' . $id),
            'status' => $status, 'deadline_at' => time() + 900]);
    }

    public static function providers(): array { return [['linode', 'sg-sin-2', 'g6-standard-4', 'linode/debian12'], ['digitalocean', 'sgp1', 's-1vcpu-1gb', 'debian-12-x64'], ['vultr', '40', 'vc2-1c-1gb', '2136']]; }
    #[DataProvider('providers')]
    public function test_native_catalog_and_group_policy_do_not_select_or_create_a_cloud_instance(string $provider, string $region, string $size, string $image): void
    {
        $catalog = $this->catalog($provider);
        $this->assertSame($region, $catalog['regions'][0]['id']); $this->assertSame($size, $catalog['sizes'][0]['id']); $this->assertSame($image, $catalog['images'][0]['id']);
        $this->assertSame([$region], $catalog['sizes'][0]['regions']); $this->assertFalse($catalog['inventory_guaranteed']);
        $input = $this->input($catalog); $saved = app(MachineFailoverPolicyService::class)->save($input, $this->admin->id);
        $this->assertNull($saved['account_id']); $this->assertSame($this->group->id, $saved['credential_group_id']);
        $policy = Policy::query()->findOrFail($saved['id']); $this->assertNull($policy->account_revision);
        $this->assertSame($provider, $saved['provider']); $this->assertFalse($saved['execution_enabled']);
        $this->assertStringNotContainsString('synthetic-never-output-secret', json_encode([$catalog, $saved]));
        $this->assertStringNotContainsString('Private snapshot', json_encode($catalog));
        $this->assertSame($catalog, $this->catalog($provider)); Http::assertSentCount(3);
        $result = app(MachineFailoverPolicyService::class)->rehearse($policy->id, $policy->revision, 'initial_setup');
        if ($provider !== 'linode') $this->assertContains('provider_creation_unsupported', array_column($result['checks'], 'code'));
        $this->assertFalse($result['executed']); Http::assertSentCount(3);
        $this->assertSame(0, Task::query()->count()); Bus::assertNothingDispatched();
        $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_acceptance.allow_dns')); $this->assertFalse(config('machine_failover.allow_replacement'));
    }

    public function test_region_size_and_image_are_validated_against_the_same_provider_snapshot(): void
    {
        $do = $this->input($this->catalog('digitalocean'));
        $service = app(MachineFailoverPolicyService::class);
        foreach (['region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode-debian12', 'region2' => 'ams3'] as $key => $value) {
            $this->code('provider_template_mismatch', fn () => $service->save(array_replace($do, [str_starts_with($key, 'region') ? 'region' : $key => $value]), $this->admin->id));
        }
        $this->code('catalog_expired', fn () => $service->save(array_replace($do, ['provider' => 'vultr']), $this->admin->id));
        $this->code('invalid_policy', fn () => $service->save($do + ['account_id' => $this->linode->id], $this->admin->id));
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_catalog_endpoint_requires_admin_and_rejects_extra_authority_fields(): void
    {
        $controller = new \App\Http\Controllers\V2\Admin\Server\MachineFailoverController();
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request = \Illuminate\Http\Request::create('/synthetic', 'GET'); $request->setUserResolver(fn () => $actor);
            try { $controller->catalog($request, app(MachineCloudCatalog::class)); $this->fail('Expected forbidden'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $request = \Illuminate\Http\Request::create('/synthetic', 'GET', ['provider' => 'linode', 'credential_group_id' => $this->group->id, 'allow_live' => true]);
        $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->catalog($request, app(MachineCloudCatalog::class))->getStatusCode()); Http::assertNothingSent();
    }

    public function test_image_minimum_disk_is_rechecked_on_the_backend(): void
    {
        $catalog = $this->catalog('digitalocean'); $input = $this->input($catalog);
        $catalog['images'][0]['min_disk_gib'] = 80;
        Cache::put('machine-cloud:catalog:' . $catalog['pool_fingerprint'], $catalog, 600);
        $this->code('provider_template_mismatch', fn () => app(MachineCloudCatalog::class)->validate($input));
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_expired_or_changed_group_catalog_cannot_authorize_a_new_template(): void
    {
        $catalog = $this->catalog(); $input = $this->input($catalog);
        $key = 'machine-cloud:catalog:' . $catalog['pool_fingerprint'];
        Cache::put($key, array_replace($catalog, ['expires_at' => time() - 1]), 600);
        $this->code('catalog_expired', fn () => app(MachineCloudCatalog::class)->validate($input));
        $catalog = $this->catalog('linode', true); $input = $this->input($catalog);
        $this->linode->fill(['group_revision' => 2])->save();
        $this->code('catalog_expired', fn () => app(MachineCloudCatalog::class)->validate($input));
        $this->assertSame(0, Policy::query()->count());
    }

    public static function catalogFaults(): array { return [['forbidden', 'provider_forbidden'], ['duplicate', 'catalog_incomplete'], ['malformed', 'provider_invalid'], ['incomplete', 'catalog_incomplete'], ['miscount', 'catalog_incomplete'], ['next', 'catalog_incomplete']]; }
    #[DataProvider('catalogFaults')]
    public function test_failed_catalog_refresh_discards_previous_proof_and_never_uses_foreign_options(string $fault, string $expected): void
    {
        $input = $this->input($this->catalog('digitalocean')); $this->fault = $fault;
        $this->code($expected, fn () => $this->catalog('digitalocean', true));
        $this->code('catalog_expired', fn () => app(MachineCloudCatalog::class)->validate($input));
        Http::assertNotSent(fn ($request) => !str_starts_with($request->url(), 'https://api.digitalocean.com/v2/'));
    }

    public function test_credential_edits_during_catalog_read_cannot_publish_a_valid_snapshot(): void
    {
        $this->fault = 'move'; $this->code('credential_group_changed', fn () => $this->catalog());
        $this->assertSame(0, Policy::query()->count());
    }

    public function test_group_selection_skips_busy_members_and_never_uses_other_providers(): void
    {
        $policy = $this->policy(); $credentials = app(MachineFailoverCredentials::class);
        $this->assertSame($this->linode->id, $credentials->selection($policy)['account_id']);
        $this->history($policy, $this->linode, 'outcome_unknown', 'different-slot');
        $choice = $credentials->selection($policy); $this->assertSame($this->second->id, $choice['account_id']);
        $this->history($policy, $this->second, 'failed', 'different-slot');
        $this->code('credential_group_exhausted', fn () => $credentials->selection($policy));
        $empty = app(MachineCloudGroupService::class)->save(['name' => 'Empty']);
        $this->code('credential_group_empty', fn () => $credentials->pool($empty->id, 'linode'));
    }

    public function test_group_cannot_multiply_the_policy_daily_budget_across_tokens(): void
    {
        $policy = $this->policy(); $this->history($policy, $this->linode);
        $this->code('credential_group_daily_budget', fn () => app(MachineFailoverCredentials::class)->selection($policy));
    }

    public function test_captured_task_credential_is_not_reselected_after_dispatch_or_group_change(): void
    {
        $policy = $this->policy(); $credentials = app(MachineFailoverCredentials::class);
        $choice = $credentials->selection($policy); $task = $this->history($policy, $this->linode, 'outcome_unknown');
        $task->fill(['payload' => ['plan' => $choice], 'dispatched_at' => time()])->save();
        $this->linode->fill(['group_id' => null, 'group_revision' => 2])->save();
        $this->assertTrue($credentials->owns($policy, $task));
        $this->assertSame($this->linode->id, $credentials->context($policy, $task)['account_id']);
        $this->code('credential_group_changed', fn () => $credentials->assertPlan($choice));
        $task->fill(['dispatched_at' => null])->save();
        $this->code('credential_group_changed', fn () => $credentials->context($policy, $task));
    }

    public function test_group_cannot_be_deleted_while_referenced_and_rename_does_not_change_membership_proof(): void
    {
        $this->policy(); $groups = app(MachineCloudGroupService::class);
        $before = app(MachineFailoverCredentials::class)->pool($this->group->id, 'linode')['fingerprint'];
        $groups->save(['id' => $this->group->id, 'expected_revision' => 1, 'name' => 'Renamed']);
        $this->assertSame($before, app(MachineFailoverCredentials::class)->pool($this->group->id, 'linode')['fingerprint']);
        $this->code('group_in_use', fn () => $groups->delete($this->group->id, 2));
        $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_120000_add_provider_groups_to_machine_failover.php'))->down();
    }

    public function test_legacy_account_policies_survive_upgrade_and_downgrade(): void
    {
        $input = $this->input($this->catalog()); unset($input['credential_group_id'], $input['provider'], $input['catalog_fingerprint']);
        $input['account_id'] = $this->linode->id;
        $saved = app(MachineFailoverPolicyService::class)->save($input, $this->admin->id); $this->assertSame($this->linode->id, $saved['account_id']);
        $migration = require base_path('database/migrations/2026_11_07_120000_add_provider_groups_to_machine_failover.php');
        $migration->down(); $this->assertFalse(Schema::hasColumn('v2_machine_failover_policy', 'credential_group_id'));
        $migration->up(); $this->assertSame($this->linode->id, Policy::query()->findOrFail($saved['id'])->account_id);
        $this->assertNull(Policy::query()->findOrFail($saved['id'])->credential_group_id);
    }

    public function test_explicit_conversion_to_legacy_does_not_retain_a_stale_group_scope(): void
    {
        $policy = $this->policy(); $input = $this->input($this->catalog());
        unset($input['credential_group_id'], $input['provider'], $input['catalog_fingerprint']);
        $input += ['id' => $policy->id, 'expected_revision' => $policy->revision, 'account_id' => $this->linode->id];
        $saved = app(MachineFailoverPolicyService::class)->save($input, $this->admin->id);
        $this->assertSame($this->linode->id, $saved['account_id']); $this->assertNull($saved['credential_group_id']);
        $this->assertNull($policy->fresh()->catalog_fingerprint);
    }

    public function test_group_plan_preserves_native_provider_and_rejects_mismatched_provenance(): void
    {
        $policy = $this->policy(); $choice = app(MachineFailoverCredentials::class)->selection($policy);
        $plan = $choice + ['machine_id' => $policy->machine_id, 'slot' => $policy->slot, 'panel_origin' => 'https://panel.example.test',
            'region' => $policy->region, 'size' => $policy->size, 'image' => $policy->image, 'authorized_keys' => [], 'monthly_limit_usd' => 100];
        $normalized = ProvisionPlan::normalize($plan); $this->assertSame($choice['credential_pool'], $normalized['credential_pool']);
        $this->code('invalid_plan', fn () => ProvisionPlan::normalize(array_replace($plan, ['credential_pool' => array_replace($choice['credential_pool'], ['provider' => 'vultr'])])));
        $this->code('provisioning_disabled', fn () => app(MachineProvisionService::class)->queue($plan, str_repeat('r', 32), $this->admin->id));
        config(['machine_provision.allow_live' => true]);
        $this->code('invalid_plan', fn () => app(MachineProvisionService::class)->queue($plan, str_repeat('a', 32), $this->admin->id));
        $this->assertSame(0, Task::query()->count()); Bus::assertNothingDispatched();
    }
}
