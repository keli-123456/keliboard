<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineCloudTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\DigitalOceanClient;
use App\Services\ServerMachine\Cloud\LinodeClient;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\Cloud\VultrClient;
use App\Services\ServerMachine\MachineAddressInventory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineCloudProvidersTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineCloudService $service;
    private User $admin;
    private string $vultrId = '10000000-0000-4000-8000-000000000001';
    private array $actions = [];
    private string $state = 'running';
    private int $posts = 0;
    private ?\Closure $post = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase();
        app()->instance('encrypter', new Encrypter(str_repeat('d', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        (require base_path('database/migrations/2026_11_05_170000_create_machine_cloud_tables.php'))->up();
        (require base_path('database/migrations/2026_11_06_010000_add_machine_cloud_providers.php'))->up();
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id')->nullable(); $table->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $inventory = new MachineAddressInventory();
        $this->service = new MachineCloudService(new LinodeClient(), new MachineCloudHealth($inventory, new NodeInstanceRegistry()));
        $this->fake();
    }

    private function account(string $provider): Account
    {
        return $this->service->saveAccount(['name' => 'Synthetic ' . $provider, 'provider' => $provider, 'api_token' => 'synthetic-' . $provider . '-token']);
    }
    private function remote(string $provider, array $changes = []): array
    {
        return array_replace($provider === 'digitalocean' ? ['id' => 123, 'name' => 'Synthetic DO', 'status' => $this->state === 'offline' ? 'off' : 'active',
            'locked' => $this->state === 'busy', 'created_at' => '2026-01-01T00:00:00Z', 'region' => ['slug' => 'sgp1'], 'size_slug' => 's-1vcpu-1gb',
            'networks' => ['v4' => [['type' => 'public', 'ip_address' => '8.8.8.8'], ['type' => 'private', 'ip_address' => '10.0.0.1']],
                'v6' => [['type' => 'public', 'ip_address' => '2606:4700:4700::1111']]]]
            : ['id' => $this->vultrId, 'label' => 'Synthetic Vultr', 'status' => 'active', 'power_status' => $this->state === 'offline' ? 'stopped' : 'running',
                'server_status' => $this->state === 'busy' ? 'locked' : 'ok', 'date_created' => '2026-01-01T00:00:00+00:00', 'region' => 'nrt', 'plan' => 'vc2-1c-1gb',
                'main_ip' => '8.8.8.8', 'v6_main_ip' => '2606:4700:4700::1111', 'default_password' => 'private-password-never-export', 'kvm' => 'https://private-console.invalid'], $changes);
    }
    private function event(string $action = 'reboot', string $status = 'completed', int $id = 100, ?int $at = null): array
    {
        return ['id' => $id, 'resource_id' => 123, 'resource_type' => 'droplet', 'type' => $action, 'status' => $status, 'started_at' => gmdate('Y-m-d\TH:i:s\Z', $at ?? time())];
    }
    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $provider = str_starts_with($request->url(), 'https://api.digitalocean.com/v2/') ? 'digitalocean' : 'vultr';
            $this->assertStringStartsWith($provider === 'digitalocean' ? 'https://api.digitalocean.com/v2/' : 'https://api.vultr.com/v2/', $request->url());
            $this->assertSame(['Bearer synthetic-' . $provider . '-token'], $request->header('Authorization'));
            if ($request->method() === 'POST') {
                $this->posts++;
                if ($this->post) return ($this->post)($request);
                return $provider === 'vultr' ? Http::response('', 204) : Http::response(['action' => $this->event($request['type'], 'in-progress')], 201);
            }
            $this->assertSame('GET', $request->method());
            if ($provider === 'digitalocean') {
                if (str_contains($request->url(), '/actions')) return Http::response(['actions' => $this->actions, 'meta' => ['total' => count($this->actions)]]);
                return str_contains($request->url(), '/droplets/123') ? Http::response(['droplet' => $this->remote($provider)])
                    : Http::response(['droplets' => [$this->remote($provider)], 'meta' => ['total' => 1]]);
            }
            return str_contains($request->url(), '/instances/' . $this->vultrId) ? Http::response(['instance' => $this->remote($provider)])
                : Http::response(['instances' => [$this->remote($provider)], 'meta' => ['total' => 1, 'links' => ['next' => '', 'prev' => '']]]);
        });
    }
    private function resource(string $provider): Resource
    {
        $account = $this->account($provider); $this->service->refreshAccount($account->id, 1);
        return Resource::query()->where('account_id', $account->id)->firstOrFail();
    }
    private function task(Resource $resource, string $action = 'reboot'): Task
    {
        return $this->service->queue($resource->id, $resource->revision, $action, bin2hex(random_bytes(16)), $this->admin->id);
    }
    private function code(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); } catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }
    public static function providers(): array { return [['digitalocean'], ['vultr']]; }

    #[DataProvider('providers')]
    public function test_inventory_is_read_only_normalized_and_hides_provider_private_fields(string $provider): void
    {
        $resource = $this->resource($provider); $this->assertSame($provider, $resource->provider);
        $this->assertSame(['8.8.8.8', '2606:4700:4700::1111'], $resource->public_ips);
        $this->assertFalse($resource->auto_boot); $this->assertTrue($resource->manual_hold);
        $json = json_encode($this->service->overview());
        foreach (['synthetic-' . $provider . '-token', 'private-password-never-export', 'private-console.invalid', 'credentials'] as $secret) $this->assertStringNotContainsString($secret, $json);
        $this->assertSame(0, $this->posts); Bus::assertNothingDispatched();
    }

    public function test_provider_is_immutable_and_resource_ids_are_namespaced_not_global(): void
    {
        $do = $this->resource('digitalocean');
        $linode = $this->account('linode');
        $other = $do->replicate(); $other->fill(['account_id' => $linode->id, 'provider' => 'linode'])->save();
        $this->assertSame(2, Resource::query()->where('external_id', '123')->count());
        $this->code('provider_immutable', fn () => $this->service->saveAccount(['id' => $linode->id, 'expected_revision' => 1, 'name' => 'Changed', 'provider' => 'vultr']));
        $this->code('unsupported_provider', fn () => $this->account('unknown'));
        $this->assertSame('linode', $linode->fresh()->provider);
    }

    #[DataProvider('providers')]
    public function test_bound_list_uses_actual_provider_without_cloud_calls(string $provider): void
    {
        $resource = $this->resource($provider);
        $machine = ServerMachine::query()->create(['name' => 'Synthetic', 'token' => 'synthetic-machine', 'is_active' => true]);
        (new MachineAddressInventory())->record($machine, str_repeat('a', 32), ['public_ipv4' => '8.8.8.8'], time());
        $this->service->binding($resource->id, $resource->revision, $machine->id, str_repeat('a', 32));
        $before = Http::recorded()->count();
        $summary = $this->service->listSummaries([$machine])[$machine->id][0];
        $this->assertSame($provider, $summary['provider']); $this->assertSame($resource->external_id, $summary['external_id']);
        $this->assertSame($before, Http::recorded()->count());
    }

    #[DataProvider('providers')]
    public function test_auto_boot_is_rejected_for_new_providers_without_remote_mutations(string $provider): void
    {
        $resource = $this->resource($provider);
        $this->code('auto_boot_unsupported', fn () => $this->service->policy($resource->id, $resource->revision, true, $this->admin->id));
        $this->assertSame(0, $this->posts); $this->assertFalse($resource->fresh()->auto_boot);
    }

    public static function actions(): array
    {
        $cases = []; foreach (['digitalocean', 'vultr'] as $provider) foreach (['boot', 'shutdown', 'reboot'] as $action) $cases[$provider . ' ' . $action] = [$provider, $action];
        return $cases;
    }
    #[DataProvider('actions')]
    public function test_each_manual_action_is_explicit_and_posts_once_to_the_correct_resource(string $provider, string $action): void
    {
        $this->state = $action === 'boot' ? 'offline' : 'running'; $resource = $this->resource($provider); $task = $this->task($resource, $action);
        $this->assertSame(0, $this->posts); $this->assertSame($provider, $task->provider);
        $this->service->process($task->id); $this->assertSame(1, $this->posts);
        $this->state = $action === 'shutdown' ? 'offline' : 'running';
        if ($provider === 'digitalocean') $this->actions = [$this->event($action === 'boot' ? 'power_on' : $action, 'completed')];
        if ($provider === 'vultr' && $action === 'reboot') { $this->state = 'offline'; $this->service->process($task->id); $this->state = 'running'; }
        $this->service->process($task->id); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status); $this->assertSame(1, $this->posts);
        $sent = Http::recorded(fn ($r) => $r->method() === 'POST')->first()[0];
        if ($provider === 'digitalocean') { $this->assertSame('https://api.digitalocean.com/v2/droplets/123/actions', $sent->url()); $this->assertSame(['boot' => 'power_on', 'shutdown' => 'shutdown', 'reboot' => 'reboot'][$action], $sent['type']); }
        else $this->assertSame('https://api.vultr.com/v2/instances/' . $this->vultrId . '/' . ['boot' => 'start', 'shutdown' => 'halt', 'reboot' => 'reboot'][$action], $sent->url());
    }

    #[DataProvider('providers')]
    public function test_running_alone_never_proves_a_reboot_completed(string $provider): void
    {
        $resource = $this->resource($provider); $task = $this->task($resource); $this->service->process($task->id);
        $this->service->process($task->id); $this->service->process($task->id);
        $this->assertSame('waiting_provider', $task->fresh()->status); $this->assertSame(1, $this->posts);
        $task->deadline_at = time() - 1; $task->save(); $this->service->process($task->id);
        $this->assertSame('expired', $task->fresh()->status); $this->assertTrue($resource->fresh()->manual_hold);
    }

    #[DataProvider('providers')]
    public function test_post_timeout_is_not_replayed_when_the_worker_resumes(string $provider): void
    {
        $resource = $this->resource($provider); $task = $this->task($resource);
        $this->post = fn () => throw new \Illuminate\Http\Client\ConnectionException('private transport detail');
        $this->service->process($task->id); $this->service->process($task->id); $this->service->process($task->id);
        $this->assertSame(1, $this->posts); $this->assertSame('uncertain', $task->fresh()->status);
        $this->assertStringNotContainsString('private transport detail', $task->fresh()->toJson());
    }

    public function test_digitalocean_old_or_failed_actions_never_prove_a_new_reboot(): void
    {
        $this->actions = [$this->event('reboot', 'completed', 100, time() - 30)];
        $resource = $this->resource('digitalocean'); $task = $this->task($resource); $this->service->process($task->id);
        $this->actions[] = $this->event('reboot', 'errored', 101);
        $this->service->process($task->id); $this->assertSame('waiting_provider', $task->fresh()->status);
        $this->actions[] = $this->event('reboot', 'completed', 102); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_vultr_cursor_is_forwarded_as_a_query_value_never_as_a_url(): void
    {
        $account = $this->account('vultr'); $calls = 0;
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) use (&$calls) {
            $calls++; $this->assertStringStartsWith('https://api.vultr.com/v2/instances?', $request->url());
            if ($calls === 2) $this->assertStringContainsString('cursor=next_cursor', $request->url());
            return Http::response(['instances' => [$this->remote('vultr')], 'meta' => ['total' => 101, 'links' => ['next' => $calls === 1 ? 'next_cursor' : '']]]);
        });
        $this->assertSame(2, (new VultrClient())->inventory($account, 2)['pages']); $this->assertSame(2, $calls);
        $this->code('pagination_limit', fn () => (new VultrClient())->inventory($account, 11));
        Http::fake(['*' => Http::response(['instances' => [], 'meta' => ['total' => 101, 'links' => ['next' => 'https://invalid.example/steal']]])]);
        $this->code('pagination_changed', fn () => (new VultrClient())->inventory($account, 2));
    }

    public function test_busy_provider_and_unknown_event_fields_block_power_without_a_post(): void
    {
        $resource = $this->resource('digitalocean'); $this->state = 'busy';
        $this->code('state_changed', fn () => $this->task($resource));
        $this->state = 'running'; $this->actions = [$this->event('reboot', 'unexpected')];
        $this->code('safety_unknown', fn () => $this->task($resource)); $this->assertSame(0, $this->posts);
    }

    public function test_migration_preserves_linode_records_and_refuses_lossy_rollback(): void
    {
        $migration = require base_path('database/migrations/2026_11_06_010000_add_machine_cloud_providers.php');
        $migration->down(); $this->assertFalse($this->service->available()); $migration->up();
        $this->assertTrue($this->service->available()); $this->resource('vultr');
        try { $migration->down(); $this->fail('Expected rollback refusal'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('rollback refused', $error->getMessage()); }
        $this->assertSame($this->vultrId, Resource::query()->firstOrFail()->external_id);
    }
}
