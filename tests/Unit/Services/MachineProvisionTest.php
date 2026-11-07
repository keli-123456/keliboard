<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineProvisionController as AdminController;
use App\Http\Controllers\V2\Server\MachineProvisionController as ReceiptController;
use App\Jobs\MachineProvisionJob;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\LinodeClient;
use App\Services\ServerMachine\Cloud\LinodeProvisionClient;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineCloudService;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\ProvisionBootstrap;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Support\Setting;
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
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineProvisionTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineProvisionService $service;
    private LinodeProvisionClient $provider;
    private MachineAddressInventory $inventory;
    private Account $account;
    private ServerMachine $machine;
    private User $admin;
    private object $settings;
    private int $postCalls = 0;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('p', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        config(['machine_provision' => require base_path('config/machine_provision.php')]);
        (require base_path('database/migrations/2026_11_05_170000_create_machine_cloud_tables.php'))->up();
        (require base_path('database/migrations/2026_11_06_010000_add_machine_cloud_providers.php'))->up();
        (require base_path('database/migrations/2026_11_05_220000_create_machine_provision_tasks.php'))->up();
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id')->nullable(); $table->boolean('enabled'); });
        $this->settings = new class {
            public array $values = ['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
                'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']];
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        };
        app()->instance(Setting::class, $this->settings);
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic slot', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        $this->account = Account::query()->create(['name' => 'Synthetic account', 'provider' => 'linode',
            'credentials' => ['api_token' => 'synthetic-provider-secret'], 'revision' => 1]);
        $this->provider = new LinodeProvisionClient(); $this->inventory = new MachineAddressInventory();
        $this->service = new MachineProvisionService($this->provider, new ProvisionBootstrap(),
            new MachineCloudHealth($this->inventory, new NodeInstanceRegistry()), $this->inventory);
        $this->fake();
    }

    private function plan(array $changes = []): array
    {
        return array_replace(['account_id' => $this->account->id, 'machine_id' => $this->machine->id, 'slot' => 'isolated:test',
            'panel_origin' => 'https://panel.example.test', 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'image' => 'linode/debian12', 'authorized_keys' => [], 'monthly_limit_usd' => 100], $changes);
    }
    private function enable(): void
    {
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => [$this->machine->id]]);
    }
    private function task(string $request = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): Task
    {
        $this->enable();
        $result = $this->service->queue($this->plan(), $request, $this->admin->id);
        return Task::query()->findOrFail($result['id']);
    }
    private function remote(array $request, array $changes = []): array
    {
        return array_replace(['id' => 987, 'label' => $request['label'], 'tags' => $request['tags'], 'image' => $request['image'],
            'region' => $request['region'], 'type' => $request['type'], 'created' => gmdate('Y-m-d\TH:i:s'),
            'status' => 'running', 'ipv4' => ['8.8.8.8'], 'ipv6' => '2606:4700:4700::1111/128'], $changes);
    }
    private function fake(mixed $post = null, ?callable $reads = null): void
    {
        $this->postCalls = 0;
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) use ($post, $reads) {
            if ($request->method() !== 'GET') {
                $this->postCalls++;
                $this->assertSame('POST', $request->method());
                $this->assertSame('https://api.linode.com/v4/linode/instances', $request->url());
                return $post ? $post($request) : Http::response($this->remote($request->data()));
            }
            if ($reads && ($response = $reads($request)) !== null) return $response;
            if (str_contains($request->url(), '/regions/')) return Http::response(['id' => 'sg-sin-2', 'capabilities' => ['Metadata'], 'status' => 'ok']);
            if (str_contains($request->url(), '/images/')) return Http::response(['id' => 'linode/debian12', 'capabilities' => ['cloud-init'], 'status' => 'available', 'deprecated' => false]);
            if (str_contains($request->url(), '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => 'standard', 'region_prices' => [], 'price' => ['monthly' => 48]]);
            if (str_contains($request->url(), '/account/settings')) return Http::response(['backups_enabled' => false]);
            $task = Task::query()->firstOrFail();
            if (str_contains($request->url(), '/instances/987')) return Http::response($this->remote($task->payload['request'], ['created' => $task->provider_created_at ?? gmdate('Y-m-d\TH:i:s', $task->dispatched_at)]));
            if (str_contains($request->url(), '/linode/instances?')) return Http::response(['data' => [], 'pages' => 1]);
            throw new \RuntimeException('Unexpected synthetic HTTP route');
        });
    }
    private function code(string $expected, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $expected); }
        catch (CloudException $error) { $this->assertSame($expected, $error->getMessage()); }
    }
    private function poll(Task $task): Task
    {
        $task->refresh()->fill(['next_check_at' => 0])->save(); $this->service->process($task->id); return $task->refresh();
    }
    private function receipt(Task $task, string $state = 'succeeded'): bool
    {
        $data = Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data']));
        preg_match('/Bearer ([a-f0-9]{64})/', $data['write_files'][2]['content'], $match);
        return $this->service->callback($task->id, $task->installation_id, $match[1], $state);
    }
    private function reports(Task $task, string $mode = 'valid'): void
    {
        $at = time(); $instance = $mode === 'old_instance' ? str_repeat('e', 32) : $task->installation_id;
        $task->refresh()->fill(['dispatched_at' => $at - 3])->save();
        $public = $mode === 'address_mismatch' ? '8.8.4.4' : '8.8.8.8';
        $targets = (new MachineEntryProbe())->configuration();
        $probe = ['schema' => 1, 'revision' => $targets['revision'], 'state' => 'ready', 'checked_at' => $at,
            'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => $target['ip'] === '1.1.1.1' ? 'reachable' : 'unreachable', 'source_ip' => $public], $targets['targets'])];
        if ($mode === 'stale_probe') $probe['checked_at'] = $at - 100;
        if ($mode === 'unscoped_probe') foreach ($probe['targets'] as &$row) unset($row['source_ip']);
        if ($mode === 'failed_probe') foreach ($probe['targets'] as &$row) $row['status'] = 'unreachable';
        if ($mode === 'wrong_source_probe') foreach ($probe['targets'] as &$row) $row['source_ip'] = '8.8.4.4';
        $this->inventory->record($this->machine, $instance, $mode === 'ipv6_only' ? ['public_ipv6' => '2606:4700:4700::1111'] : ['public_ipv4' => $public],
            $mode === 'stale_agent' ? $at - 181 : $at, [], $probe);
        if ($mode !== 'no_nodes') DB::table('v2_server')->updateOrInsert(['id' => 91], ['machine_id' => $this->machine->id, 'enabled' => true]);
        $node = ['machine_id' => $this->machine->id, 'installation_id' => $instance, 'last_seen_at' => $at, 'last_online_at' => $at];
        if ($mode === 'stale_node') $node['last_online_at'] = $at - 181;
        if ($mode === 'config_only') { unset($node['last_online_at']); $node['last_config_at'] = $at; }
        Cache::put('node_instances:91', [$node], 300);
    }

    public function test_default_phase_is_off_and_has_no_scheduled_or_provider_work(): void
    {
        $this->assertFalse(config('machine_provision.allow_live'));
        $this->assertSame([], config('machine_provision.allowed_test_machine_ids'));
        $this->code('provisioning_disabled', fn () => $this->service->queue($this->plan(), str_repeat('a', 32), $this->admin->id));
        $this->assertSame(['queued' => 0], $this->service->advance());
        $this->assertSame(0, Task::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
        $this->assertStringNotContainsString('advance-provisioning', file_get_contents(base_path('app/Console/Kernel.php')));
    }

    public function test_preview_is_local_and_never_exports_installation_secrets(): void
    {
        $result = $this->service->preview($this->plan()); $encoded = json_encode($result);
        $this->assertGreaterThan(10000, $result['bootstrap_bytes']);
        foreach (['synthetic-machine-secret', 'synthetic-provider-secret', '"root_pass":', '#cloud-config', 'Bearer', 'authorized_keys'] as $secret) $this->assertStringNotContainsString($secret, $encoded);
        $this->assertSame('keep', $result['plan']['delete_strategy']);
        $this->assertFalse($result['provider_checked']); $this->assertFalse($result['takeover_performed']);
        $this->assertSame(0, Task::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_queue_is_encrypted_idempotent_and_has_no_provider_effect(): void
    {
        $task = $this->task();
        $again = $this->service->queue($this->plan(), str_repeat('a', 32), $this->admin->id);
        $this->assertSame($task->id, $again['id']); $this->assertSame(1, Task::query()->count());
        $stored = DB::table('v2_machine_provision_task')->value('payload');
        $json = json_encode($this->service->overview());
        foreach (['synthetic-machine-secret', 'synthetic-provider-secret', $task->payload['request']['root_pass'], $task->payload['request']['metadata']['user_data']] as $secret) {
            $this->assertStringNotContainsString($secret, $stored); $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString('callback_hash', $json); $this->assertStringNotContainsString($task->installation_id, $json);
        $this->code('request_conflict', fn () => $this->service->queue($this->plan(['region' => 'jp-tyo-3']), str_repeat('a', 32), $this->admin->id));
        $this->code('task_active', fn () => $this->service->queue($this->plan(), str_repeat('b', 32), $this->admin->id));
        Http::assertNothingSent(); Bus::assertDispatched(MachineProvisionJob::class, fn ($job) => $job->queue === 'admin_operations' && $job->connection === 'redis');
    }

    public function test_accepted_tasks_allow_replacement_but_still_count_against_daily_budget(): void
    {
        $first = $this->task();
        $this->code('task_active', fn () => $this->service->queue($this->plan(), str_repeat('b', 32), $this->admin->id));
        $first->fill(['status' => 'accepted'])->save();
        $this->service->process($first->id);
        $this->assertSame('accepted', $first->fresh()->status);
        $second = $this->service->queue($this->plan(), str_repeat('b', 32), $this->admin->id);
        $this->assertNotSame($first->id, $second['id']);
        Task::query()->whereKey($second['id'])->update(['status' => 'accepted']);
        $this->code('daily_budget', fn () => $this->service->queue($this->plan(), str_repeat('c', 32), $this->admin->id));
        $this->assertSame(2, Task::query()->count()); Http::assertNothingSent();
    }

    public static function badPlans(): array
    {
        return ['script' => [['script' => 'echo unsafe'], 'invalid_plan'], 'private key' => [['authorized_keys' => ['-----BEGIN PRIVATE KEY-----']], 'invalid_ssh_key'],
            'fake public key' => [['authorized_keys' => ['ssh-ed25519 aaa']], 'invalid_ssh_key'], 'path injection' => [['region' => '../region'], 'invalid_plan'],
            'private image' => [['image' => 'private/secret'], 'invalid_plan'], 'id coercion' => [['machine_id' => '1'], 'invalid_plan'],
            'budget NaN' => [['monthly_limit_usd' => 'NaN'], 'invalid_budget'], 'negative budget' => [['monthly_limit_usd' => -1], 'invalid_budget'],
            'bad source' => [['source_ref' => ['version' => 'v3']], 'invalid_source_ref']];
    }
    #[DataProvider('badPlans')]
    public function test_malformed_plans_never_start_work(array $changes, string $code): void
    {
        $this->code($code, fn () => $this->service->preview($this->plan($changes))); Http::assertNothingSent();
    }
    public static function badOrigins(): array
    {
        return array_map(fn ($url) => [$url], ['http://panel.example.test', 'https://8.8.8.8', 'https://user:pass@panel.example.test',
            'https://panel.example.test/subpath', 'https://panel.example.test?token=secret', 'https://panel.example.test#x',
            'https://panel.example.test:444', 'https://x.localhost']);
    }
    #[DataProvider('badOrigins')]
    public function test_bootstrap_origin_is_strict(string $origin): void
    {
        $this->code('invalid_panel_origin', fn () => $this->service->preview($this->plan(['panel_origin' => $origin]))); Http::assertNothingSent();
    }
    public function test_only_configured_panel_origin_can_receive_machine_credentials(): void
    {
        $this->code('panel_origin_changed', fn () => $this->service->preview($this->plan(['panel_origin' => 'https://other.example.test'])));
        $this->settings->values['node_api_base_url'] = 'https://nodes.example.test';
        $this->code('panel_origin_changed', fn () => $this->service->preview($this->plan()));
        $this->assertSame('https://nodes.example.test', $this->service->preview($this->plan(['panel_origin' => 'https://nodes.example.test']))['plan']['panel_origin']);
        Http::assertNothingSent();
    }

    public static function auditedSources(): array
    {
        return ['service2 member3' => ['sg-sin-2', 2, 3, 123, 22], 'service2 member5' => ['sg-sin-2', 2, 5, 123, 22], 'service3 member7' => ['jp-tyo-3', 3, 7, 122, 21]];
    }
    #[DataProvider('auditedSources')]
    public function test_audited_templates_are_provenance_only_not_permission_to_take_over(string $region, int $service, int $member, int $script, int $machine): void
    {
        $plan = $this->plan(['region' => $region, 'source_ref' => ['version' => 'v2', 'service_id' => $service, 'member_id' => $member, 'script_id' => $script]]);
        $preview = $this->service->preview($plan); $this->assertTrue($preview['plan']['script_review_required']);
        $this->assertFalse($preview['takeover_performed']); $this->assertContains($machine, [21, 22]);
        $this->enable(); $this->code('source_review_required', fn () => $this->service->queue($plan, str_repeat('a', 32), $this->admin->id));
        Http::assertNothingSent(); $this->assertSame(0, Task::query()->count());
    }

    public function test_cloud_init_reuses_native_installer_and_preseeds_distinct_private_identity(): void
    {
        $task = $this->task(); $request = $task->payload['request'];
        $data = Yaml::parse(base64_decode($request['metadata']['user_data']));
        $this->assertCount(3, $data['write_files']);
        foreach ($data['write_files'] as $file) { $this->assertSame('root:root', $file['owner']); $this->assertSame('0600', $file['permissions']); }
        $install = $data['write_files'][0]['content']; $bootstrap = $data['write_files'][1]['content'];
        $this->assertStringNotContainsString("\r", $install); $this->assertStringNotContainsString("\r", $bootstrap);
        $this->assertStringContainsString('AGENT_TYPE="kelinode-rs"', $install);
        foreach (['INSTALL_DEPS', 'ENABLE_BBR', 'ENABLE_SYS_TUNING', 'ENABLE_EGRESS_GUARD'] as $flag) $this->assertStringContainsString($flag . '="1"', $install);
        $this->assertStringContainsString('synthetic-machine-secret', $install);
        $this->assertStringContainsString('identity=\'' . $task->installation_id . '\'', $bootstrap);
        $this->assertStringContainsString('id_file=/etc/kelinode/report-instance-id', $bootstrap);
        $this->assertStringContainsString('--retry 3', $bootstrap);
        $this->assertStringContainsString('timeout --signal=TERM --kill-after=15s 600s bash', $bootstrap);
        $this->assertStringNotContainsString('Bearer', $bootstrap); $this->assertStringNotContainsString('tanzhen', $bootstrap);
        $this->assertStringContainsString('trap report_result EXIT', $bootstrap);
        $this->assertSame([['bash', $data['write_files'][1]['path']]], $data['runcmd']);
        $this->assertFalse($request['backups_enabled']); $this->assertTrue($request['booted']);
        $this->assertGreaterThanOrEqual(40, strlen($request['root_pass'])); Http::assertNothingSent();
    }

    public function test_create_sends_only_one_post_and_requires_an_initialization_receipt(): void
    {
        $task = $this->task(); $this->poll($task);
        $this->assertSame('waiting_initialization', $task->status); $this->assertSame('987', $task->external_id);
        $this->poll($task); $this->poll($task);
        $this->assertSame(1, Http::recorded(fn ($r) => $r->method() === 'POST')->count());
        $this->assertSame(0, Resource::query()->count());
        $this->assertSame(0, DB::table('v2_machine_cloud_task')->count());
    }

    public function test_independent_creation_needs_no_legacy_handoff_and_preserves_existing_resources(): void
    {
        $oldMachine = ServerMachine::query()->create(['name' => 'Existing synthetic machine',
            'token' => 'existing-synthetic-machine-secret', 'is_active' => true]);
        $oldResource = Resource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode',
            'external_id' => '123', 'label' => 'existing-resource', 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'provider_created_at' => '2026-01-01T00:00:00', 'status' => 'running', 'public_ips' => ['8.8.4.4'],
            'observed_at' => time(), 'machine_id' => $oldMachine->id, 'installation_id' => str_repeat('d', 32),
            'auto_boot' => true, 'armed' => true, 'manual_hold' => false]);
        DB::table('v2_server')->insert(['id' => 92, 'machine_id' => $oldMachine->id, 'enabled' => true]);
        $machineBefore = $oldMachine->fresh()->getRawOriginal();
        $resourceBefore = $oldResource->fresh()->getRawOriginal();
        $preview = $this->service->preview($this->plan());
        $this->assertFalse($preview['plan']['script_review_required']);
        $this->assertFalse(config('machine_provision.allow_live'));

        $task = $this->task();
        $this->poll($task);
        $this->receipt($task);
        $this->reports($task);
        $this->poll($task);

        $this->assertSame('awaiting_acceptance', $task->status);
        $this->assertSame([], $task->payload['plan']['source_ref']);
        $this->assertSame('987', $task->external_id);
        $this->assertSame($this->machine->id, $task->machine_id);
        $this->assertNotSame($oldResource->installation_id, $task->installation_id);
        $this->assertSame($machineBefore, $oldMachine->fresh()->getRawOriginal());
        $this->assertSame($resourceBefore, $oldResource->fresh()->getRawOriginal());
        $this->assertSame($oldMachine->id, DB::table('v2_server')->where('id', 92)->value('machine_id'));
        $this->assertSame(1, Resource::query()->count());
        $this->assertSame(0, DB::table('v2_machine_cloud_task')->count());
        $this->assertSame(1, $this->postCalls);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET'
            && !($request->method() === 'POST' && $request->url() === 'https://api.linode.com/v4/linode/instances'));
    }

    public static function ambiguousCreates(): array { return ['timeout' => [0], 'server error' => [503], 'invalid successful reply' => [200]]; }
    #[DataProvider('ambiguousCreates')]
    public function test_ambiguous_create_only_reconciles_and_never_repeats_post(int $status): void
    {
        $this->fake(function () use ($status) {
            if ($status === 0) throw new \Illuminate\Http\Client\ConnectionException('secret transport detail');
            return Http::response($status === 200 ? ['malformed' => true] : ['message' => 'secret provider detail'], $status);
        });
        $task = $this->task(); $this->poll($task); $this->assertSame('outcome_unknown', $task->status);
        $this->poll($task); $this->poll($task);
        $this->assertSame(1, $this->postCalls);
        $this->assertStringNotContainsString('secret provider detail', $task->toJson());
        $task->deadline_at = time() - 1; $task->save(); $this->poll($task);
        $this->assertSame('needs_review', $task->status); $this->assertSame('create_not_resolved', $task->reason);
        $this->assertNotEmpty($task->marker); $this->assertNotNull($task->dispatched_at);
    }

    public function test_worker_lost_after_dispatch_can_find_exact_marker_without_another_create(): void
    {
        $task = $this->task(); $task->fill(['dispatched_at' => time() - 2, 'status' => 'dispatching'])->save();
        $this->fake(null, function ($request) use ($task) {
            if (!str_contains($request->url(), '/linode/instances?')) return null;
            $this->assertSame($task->payload['request']['label'], json_decode($request->header('X-Filter')[0], true)['label']);
            return Http::response(['data' => [$this->remote($task->payload['request'], ['created' => gmdate('Y-m-d\TH:i:s', $task->dispatched_at)])], 'pages' => 1]);
        });
        $this->poll($task); $this->assertSame('987', $task->external_id); $this->assertSame('waiting_initialization', $task->status);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_duplicate_candidates_require_review_and_are_never_selected_or_deleted(): void
    {
        $task = $this->task(); $task->fill(['dispatched_at' => time() - 2, 'status' => 'dispatching'])->save();
        $this->fake(null, fn ($r) => str_contains($r->url(), '/linode/instances?') ? Http::response(['pages' => 1, 'data' => [
            $this->remote($task->payload['request']), $this->remote($task->payload['request'], ['id' => 988])]]) : null);
        $this->poll($task); $this->assertSame('needs_review', $task->status); $this->assertSame('duplicate_candidates', $task->reason);
        $this->assertNull($task->external_id); Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_authenticated_receipt_is_idempotent_but_cannot_change_its_result(): void
    {
        $task = $this->task(); $this->poll($task);
        $this->assertTrue($this->receipt($task)); $this->assertTrue($this->receipt($task));
        $this->code('bootstrap_conflict', fn () => $this->receipt($task, 'failed'));
        $this->assertSame(1, DB::table('v2_machine_provision_step')->where('state', 'bootstrap_succeeded')->count());
        $this->poll($task); $this->assertSame('waiting_agent', $task->status);
    }

    public function test_receipts_need_bearer_secret_exact_installation_and_post_dispatch_window(): void
    {
        $task = $this->task();
        $this->code('invalid_callback', fn () => $this->receipt($task));
        $this->poll($task);
        $this->code('invalid_callback', fn () => $this->service->callback($task->id, $task->installation_id, str_repeat('f', 64), 'succeeded'));
        $this->code('invalid_callback', fn () => $this->service->callback($task->id, str_repeat('f', 32), str_repeat('f', 64), 'succeeded'));
        $task->deadline_at = time() - 1; $task->save(); $this->code('invalid_callback', fn () => $this->receipt($task));
    }

    public function test_initialization_failure_keeps_provider_resource_for_review(): void
    {
        $task = $this->task(); $this->poll($task); $this->receipt($task, 'failed'); $this->poll($task);
        $this->assertSame('needs_review', $task->status); $this->assertSame('initialization_failed', $task->reason);
        $this->assertSame('987', $task->external_id); Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public static function reportCases(): array
    {
        return ['old installation' => ['old_instance', 'waiting_agent'], 'different IPv4' => ['address_mismatch', 'waiting_agent'],
            'stale agent' => ['stale_agent', 'waiting_agent'], 'stale node' => ['stale_node', 'waiting_node_reports'],
            'config poll only' => ['config_only', 'waiting_node_reports'], 'no business node' => ['no_nodes', 'waiting_node_reports'],
            'old ping' => ['stale_probe', 'waiting_ipv4_probe'], 'unscoped ping' => ['unscoped_probe', 'waiting_ipv4_probe'],
            'failed ping' => ['failed_probe', 'waiting_ipv4_probe'], 'different ping source' => ['wrong_source_probe', 'waiting_ipv4_probe']];
    }
    #[DataProvider('reportCases')]
    public function test_wrong_or_insufficient_evidence_does_not_complete_acceptance(string $mode, string $state): void
    {
        $task = $this->task(); $this->poll($task); $this->receipt($task); $this->reports($task, $mode); $this->poll($task);
        $this->assertSame($state, $task->status); $this->assertNull($task->finished_at);
    }

    public function test_valid_exact_instance_reports_and_one_reachable_ipv4_only_reach_acceptance_boundary(): void
    {
        $task = $this->task(); $this->poll($task); $this->receipt($task); $this->reports($task); $this->poll($task);
        $this->assertSame('awaiting_acceptance', $task->status);
        $this->assertSame('fresh', $task->evidence['reports']['nodes']); $this->assertSame('reachable', $task->evidence['ipv4_probe']);
        foreach (['forwarding_verified', 'dns_changed', 'old_resources_changed'] as $key) $this->assertFalse($task->evidence[$key]);
        $this->assertSame(0, Resource::query()->count()); $this->assertNotNull($task->finished_at);
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' || str_contains($r->url(), '/domains'));
    }

    public static function revokeCases(): array { return array_map(fn ($mode) => [$mode], ['admin', 'account', 'machine', 'active', 'origin', 'gate', 'whitelist']); }
    #[DataProvider('revokeCases')]
    public function test_execution_revalidates_authority_before_provider_calls(string $mode): void
    {
        $task = $this->task();
        match ($mode) {
            'admin' => $this->admin->fill(['banned' => true])->save(),
            'account' => $this->account->fill(['revision' => 2])->save(),
            'machine' => $this->machine->fill(['token' => 'rotated-synthetic'])->save(),
            'active' => $this->machine->fill(['is_active' => false])->save(),
            'origin' => $this->settings->values['node_api_base_url'] = 'https://other.example.test',
            'gate' => config(['machine_provision.allow_live' => false]),
            'whitelist' => config(['machine_provision.allowed_test_machine_ids' => []]),
        };
        $this->poll($task); $this->assertSame('failed', $task->status); $this->assertNull($task->dispatched_at); Http::assertNothingSent();
    }

    public function test_revalidation_after_slow_preflight_prevents_a_revoked_create(): void
    {
        $task = $this->task();
        $this->fake(null, function ($request) {
            if (!str_contains($request->url(), '/account/settings')) return null;
            $this->admin->fill(['banned' => true])->save(); return Http::response(['backups_enabled' => false]);
        });
        $this->poll($task); $this->assertSame('failed', $task->status); $this->assertSame('permission_revoked', $task->reason);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_slow_preflight_cannot_create_a_resource_after_the_task_deadline(): void
    {
        $task = $this->task();
        $task->fill(['deadline_at' => time() + 1])->save();
        $this->fake(null, function ($request) {
            if (!str_contains($request->url(), '/account/settings')) return null;
            sleep(2);
            return Http::response(['backups_enabled' => false]);
        });

        $this->poll($task);

        $this->assertSame('failed', $task->status);
        $this->assertSame('deadline', $task->reason);
        $this->assertNull($task->dispatched_at);
        $this->assertNull($task->external_id);
        $this->assertSame(0, $this->postCalls);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_cancel_before_and_after_dispatch_never_deletes_or_reverses_provider_work(): void
    {
        $task = $this->task(); $this->assertSame('cancelled', $this->service->cancel($task->id, $this->admin->id)['status']);
        $task = $this->task(str_repeat('b', 32)); $this->poll($task);
        $this->assertSame('needs_review', $this->service->cancel($task->id, $this->admin->id)['status']);
        $this->assertSame('987', $task->fresh()->external_id);
        $this->code('task_active', fn () => $this->service->queue($this->plan(), str_repeat('c', 32), $this->admin->id));
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
    }

    public function test_account_cannot_be_removed_with_an_unresolved_creation_intent(): void
    {
        $task = $this->task(); $cloud = new MachineCloudService(new LinodeClient(), new MachineCloudHealth($this->inventory, new NodeInstanceRegistry()));
        $this->code('task_active', fn () => $cloud->saveAccount(['id' => $this->account->id, 'expected_revision' => 1, 'name' => 'Changed']));
        $this->code('account_in_use', fn () => $cloud->deleteAccount($this->account->id, 1));
        $this->poll($task); $this->service->cancel($task->id, $this->admin->id);
        $this->code('account_in_use', fn () => $cloud->deleteAccount($this->account->id, 1));
        $this->assertSame(1, Account::query()->count());
    }

    public function test_daily_attempt_limit_counts_definite_failures_and_cancellations(): void
    {
        for ($i = 0; $i < 2; $i++) { $task = $this->task(str_repeat((string) ($i + 1), 32)); $this->service->cancel($task->id, $this->admin->id); }
        $this->code('daily_budget', fn () => $this->service->queue($this->plan(), str_repeat('c', 32), $this->admin->id));
        $this->assertSame(2, Task::query()->count()); Http::assertNothingSent();
    }

    public function test_same_slot_cannot_create_a_second_candidate_through_another_account(): void
    {
        $this->task();
        $other = Account::query()->create(['name' => 'Second synthetic', 'provider' => 'linode', 'credentials' => ['api_token' => 'other-synthetic'], 'revision' => 1]);
        $this->code('task_active', fn () => $this->service->queue($this->plan(['account_id' => $other->id]), str_repeat('b', 32), $this->admin->id));
        $this->assertSame(1, Task::query()->count()); Http::assertNothingSent();
    }

    public function test_request_permissions_and_budget_are_required_even_with_live_gate(): void
    {
        config(['machine_provision.allow_live' => true]);
        $this->code('test_machine_not_allowed', fn () => $this->service->queue($this->plan(), str_repeat('a', 32), $this->admin->id));
        $this->enable();
        $this->code('budget_required', fn () => $this->service->queue($this->plan(['monthly_limit_usd' => 0]), str_repeat('a', 32), $this->admin->id));
        $this->admin->fill(['is_admin' => false])->save();
        $this->code('permission_revoked', fn () => $this->service->queue($this->plan(), str_repeat('a', 32), $this->admin->id));
        Http::assertNothingSent();
    }

    public function test_admin_authorization_happens_before_validation_and_receipt_only_accepts_json_bearer(): void
    {
        $request = Request::create('/synthetic', 'POST', []); $request->setUserResolver(fn () => null);
        try { (new AdminController())->queue($request, $this->service); $this->fail('Expected forbidden'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $response = (new ReceiptController())->result(Request::create('/synthetic', 'POST', ['task_id' => 'wrong', 'secret' => 'unsafe']), $this->service);
        $this->assertSame(401, $response->getStatusCode()); $this->assertSame('invalid_callback', $response->getData(true)['message']);
    }

    public function test_migration_rolls_back_only_its_own_tables(): void
    {
        (require base_path('database/migrations/2026_11_05_220000_create_machine_provision_tasks.php'))->down();
        $this->assertFalse($this->service->available()); $this->assertTrue(Schema::hasTable('v2_machine_cloud_account'));
        $this->assertSame([], $this->service->overview()['tasks']);
    }

    public static function unsupportedPreflight(): array
    {
        return ['metadata unavailable' => ['region', ['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => []], 'bootstrap_unsupported'],
            'region outage' => ['region', ['id' => 'sg-sin-2', 'status' => 'outage', 'capabilities' => ['Metadata']], 'bootstrap_unsupported'],
            'image lacks cloud init' => ['image', ['id' => 'linode/debian12', 'status' => 'available', 'capabilities' => [], 'deprecated' => false], 'bootstrap_unsupported'],
            'image retired' => ['image', ['id' => 'linode/debian12', 'status' => 'available', 'capabilities' => ['cloud-init'], 'deprecated' => true], 'bootstrap_unsupported'],
            'backups override' => ['settings', ['backups_enabled' => true], 'account_backups_enabled'],
            'settings unknown' => ['settings', [], 'provider_invalid'],
            'price exceeds budget' => ['type', ['id' => 'g6-standard-4', 'class' => 'standard', 'price' => ['monthly' => 110], 'region_prices' => []], 'budget_exceeded'],
            'regional price exceeds budget' => ['type', ['id' => 'g6-standard-4', 'class' => 'standard', 'price' => ['monthly' => 48], 'region_prices' => [['id' => 'sg-sin-2', 'monthly' => 110]]], 'budget_exceeded']];
    }
    #[DataProvider('unsupportedPreflight')]
    public function test_preflight_blocks_incompatible_or_unapproved_charges(string $field, array $body, string $reason): void
    {
        $task = $this->task();
        $fragment = ['region' => '/regions/', 'image' => '/images/', 'type' => '/linode/types/', 'settings' => '/account/settings'][$field];
        $this->fake(null, fn ($request) => str_contains($request->url(), $fragment) ? Http::response($body) : null);
        $this->poll($task); $this->assertSame('failed', $task->status); $this->assertSame($reason, $task->reason);
        $this->assertNull($task->dispatched_at); $this->assertSame(0, $this->postCalls);
    }

    public function test_unreviewed_sizes_and_images_never_reach_provider(): void
    {
        $this->code('bootstrap_unsupported', fn () => $this->provider->preflight($this->account, ProvisionPlan::normalize($this->plan(['size' => 'unknown-architecture']))));
        $this->code('bootstrap_unsupported', fn () => $this->provider->preflight($this->account, ProvisionPlan::normalize($this->plan(['image' => 'linode/unknown']))));
        Http::assertNothingSent();
    }

    public function test_incomplete_provider_pagination_only_waits_until_deadline(): void
    {
        $task = $this->task(); $task->fill(['dispatched_at' => time() - 2, 'status' => 'dispatching'])->save();
        $this->fake(null, fn ($request) => str_contains($request->url(), '/linode/instances?') ? Http::response(['pages' => 4, 'data' => []]) : null);
        $this->poll($task); $this->assertSame('outcome_unknown', $task->status); $this->assertSame('reconcile_incomplete', $task->reason);
        $this->assertNull($task->external_id); $this->assertSame(0, $this->postCalls);
    }

    public function test_matching_marker_with_changed_identity_cannot_be_adopted(): void
    {
        $task = $this->task(); $task->fill(['dispatched_at' => time() - 2, 'status' => 'dispatching'])->save();
        $this->fake(null, fn ($request) => str_contains($request->url(), '/linode/instances?') ? Http::response(['pages' => 1,
            'data' => [$this->remote($task->payload['request'], ['region' => 'other-region'])]]) : null);
        $this->poll($task); $this->assertSame('needs_review', $task->status); $this->assertSame('identity_changed', $task->reason);
        $this->assertNull($task->external_id); $this->assertSame(0, $this->postCalls);
    }

    public function test_permission_removal_after_create_stops_processing_without_touching_vm(): void
    {
        $task = $this->task(); $this->poll($task); $this->fake();
        $this->admin->fill(['banned' => true])->save(); $this->poll($task);
        $this->assertSame('needs_review', $task->status); $this->assertSame('permission_revoked', $task->reason);
        $this->assertSame('987', $task->external_id); Http::assertNothingSent();
    }

    public function test_public_receipt_route_and_admin_queue_keep_their_separate_authorization(): void
    {
        $task = $this->task(); $this->poll($task);
        $data = Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data']));
        preg_match('/Bearer ([a-f0-9]{64})/', $data['write_files'][2]['content'], $match);
        $request = Request::create('/api/v2/server/machine/provision/result', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $match[1]], json_encode(['task_id' => $task->id, 'installation_id' => $task->installation_id, 'state' => 'succeeded']));
        $response = (new ReceiptController())->result($request, $this->service);
        $this->assertSame(200, $response->getStatusCode()); $this->assertTrue($response->getData(true)['data']['accepted']);
        $routes = file_get_contents(base_path('app/Http/Routes/V2/ServerRoute.php'));
        $this->assertStringContainsString("post('provision/result'", $routes);
        $this->assertStringNotContainsString('provision/queue', $routes);
    }
}
