<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineCloudGroupController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup as Group;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineCloudTask as Task;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\LinodeClient;
use App\Services\ServerMachine\Cloud\MachineCloudGroupService;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\MachineAddressInventory;
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

class MachineCloudGroupsTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineCloudGroupService $groups;
    private MachineCloudService $cloud;
    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('g', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_06_020000_create_machine_cloud_groups.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        $this->groups = new MachineCloudGroupService();
        $this->cloud = new MachineCloudService(new LinodeClient(), new MachineCloudHealth(new MachineAddressInventory(), new NodeInstanceRegistry()));
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
    }
    private function code(string $expected, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $expected); }
        catch (CloudException $error) { $this->assertSame($expected, $error->getMessage()); }
    }
    private function account(string $provider = 'linode', ?int $group = null): Account
    {
        return $this->cloud->saveAccount(['provider' => $provider, 'name' => 'Synthetic account', 'api_token' => 'synthetic-only-secret', 'group_id' => $group])->fresh();
    }
    public function test_groups_can_be_created_renamed_and_listed_without_credentials(): void
    {
        $group = $this->groups->save(['name' => "\u{00A0} Production \u{00A0}  Asia \u{00A0}"]);
        $this->assertSame('Production Asia', $group->name); $this->assertSame(1, $group->revision);
        $this->code('group_name_taken', fn () => $this->groups->save(['name' => 'production asia']));
        $this->code('config_changed', fn () => $this->groups->save(['id' => $group->id, 'name' => 'New', 'expected_revision' => 2]));
        $group = $this->groups->save(['id' => $group->id, 'name' => 'Production EU', 'expected_revision' => 1]);
        $this->assertSame(2, $group->revision); $this->assertSame('Production EU', $this->groups->overview()[0]['name']);
        $this->assertArrayNotHasKey('name_key', $group->toArray());
        $this->assertTrue($this->cloud->overview()['groups_available']); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public static function providers(): array { return [['linode'], ['digitalocean'], ['vultr']]; }
    #[DataProvider('providers')]
    public function test_each_provider_can_be_created_in_a_group_or_left_ungrouped(string $provider): void
    {
        $group = $this->groups->save(['name' => 'Production']);
        $account = $this->account($provider, $group->id);
        $this->assertSame($group->id, $account->group_id); $this->assertSame(1, $account->group_revision);
        $this->assertNull($this->account($provider)->group_id); $this->assertSame(2, Account::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public function test_group_moves_and_deletion_do_not_modify_credentials_policies_or_running_tasks(): void
    {
        $one = $this->groups->save(['name' => 'One']); $two = $this->groups->save(['name' => 'Two']); $account = $this->account('linode', $one->id);
        $resource = Resource::query()->create(['account_id' => $account->id, 'external_id' => '123', 'label' => 'Synthetic',
            'region' => 'sg', 'size' => 'size', 'provider_created_at' => '2026-01-01T00:00:00Z', 'status' => 'running', 'public_ips' => [],
            'observed_at' => time(), 'auto_boot' => true, 'armed' => true, 'manual_hold' => false, 'revision' => 8]);
        $task = Task::query()->create(['resource_id' => $resource->id, 'admin_id' => 1, 'request_key' => str_repeat('a', 64),
            'action' => 'boot', 'resource_revision' => 8, 'account_revision' => $account->revision, 'external_id' => '123', 'resource_label' => 'Synthetic',
            'status' => 'waiting_reports', 'deadline_at' => time() + 900]);
        $resource->active_task_id = $task->id; $resource->save();
        $before = ['resource' => $resource->fresh()->getAttributes(), 'task' => $task->fresh()->getAttributes(),
            'secret' => DB::table('v2_machine_cloud_account')->where('id', $account->id)->value('credentials')];
        $moved = $this->groups->assign($account->id, $two->id, 1);
        $this->assertSame($account->revision, $moved->revision); $this->assertSame(2, $moved->group_revision); $this->assertSame($two->id, $moved->group_id);
        $this->assertSame(2, $this->groups->assign($account->id, $two->id, 2)->group_revision);
        $this->code('group_changed', fn () => $this->groups->assign($account->id, null, 1));
        $this->groups->delete($two->id, $two->revision); $account->refresh();
        $this->assertNull($account->group_id); $this->assertSame(3, $account->group_revision); $this->assertSame(1, $account->revision);
        $this->assertSame($before['secret'], DB::table('v2_machine_cloud_account')->where('id', $account->id)->value('credentials'));
        $this->assertSame($before['resource'], $resource->fresh()->getAttributes());
        $this->assertSame($before['task'], $task->fresh()->getAttributes());
        $this->assertSame(1, Account::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public static function invalidNames(): array { return [[''], [' '], [str_repeat('a', 61)], ["Bad\nName"], ["Bad\0Name"]]; }
    #[DataProvider('invalidNames')]
    public function test_invalid_group_names_do_not_create_records(string $name): void
    {
        $this->code('invalid_group_name', fn () => $this->groups->save(['name' => $name])); $this->assertSame(0, Group::query()->count());
    }
    public function test_missing_groups_and_stale_revisions_cannot_reassign_or_delete(): void
    {
        $account = $this->account(); $group = $this->groups->save(['name' => 'Live']);
        $this->code('group_missing', fn () => $this->groups->assign($account->id, 999, 1));
        $this->code('group_missing', fn () => $this->account('vultr', 999));
        $this->code('config_changed', fn () => $this->groups->delete($group->id, 2));
        $this->assertSame(1, Group::query()->count()); $this->assertNull($account->fresh()->group_id);
        $this->code('group_assignment_required', fn () => $this->cloud->saveAccount(['id' => $account->id, 'group_id' => $group->id]));
    }
    public function test_group_limit_is_bounded_and_existing_groups_can_still_be_renamed(): void
    {
        for ($i = 0; $i < 100; $i++) $this->groups->save(['name' => 'Group ' . $i]);
        $this->code('group_limit', fn () => $this->groups->save(['name' => 'Excess']));
        $group = Group::query()->firstOrFail();
        $this->assertSame('Renamed', $this->groups->save(['id' => $group->id, 'expected_revision' => 1, 'name' => 'Renamed'])->name);
        $this->assertSame(100, Group::query()->count());
    }
    public function test_group_mutex_prevents_delete_during_credential_creation_or_movement(): void
    {
        $account = $this->account(); $group = $this->groups->save(['name' => 'Protected']);
        $lock = Cache::lock('machine-cloud:credential-groups', 120); $this->assertTrue($lock->get());
        try {
            $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);
            $this->groups->assign($account->id, $group->id, 1);
        } finally { $lock->release(); $this->assertNull($account->fresh()->group_id); }
    }
    public function test_group_api_checks_admin_before_validation_and_never_returns_secrets(): void
    {
        $request = Request::create('/synthetic', 'POST'); $request->setUserResolver(fn () => null);
        try { (new MachineCloudGroupController())->assign($request, $this->groups); $this->fail('Expected forbidden'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->account(); $json = json_encode($this->cloud->overview());
        $this->assertStringNotContainsString('synthetic-only-secret', $json); $this->assertStringNotContainsString('credentials', $json);
    }
    public function test_migration_preserves_old_accounts_and_can_be_rolled_back_without_deleting_them(): void
    {
        $account = $this->account(); $secret = $account->getRawOriginal('credentials');
        $migration = require base_path('database/migrations/2026_11_06_020000_create_machine_cloud_groups.php');
        $migration->down(); $this->assertFalse($this->groups->available());
        $this->assertFalse($this->cloud->overview()['groups_available']);
        $this->code('groups_migration_required', fn () => $this->groups->save(['name' => 'New']));
        $migration->up(); $account->refresh();
        $this->assertNull($account->group_id); $this->assertSame(1, $account->group_revision);
        $this->assertSame($secret, $account->getRawOriginal('credentials')); $this->assertSame(1, $account->revision);
    }
}
