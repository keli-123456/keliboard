<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineCloudController;
use App\Jobs\CheckMachineCloudJob;
use App\Jobs\MachineCloudPowerJob;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudResource as Resource;
use App\Models\MachineCloudTask as Task;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Node\NodeInstanceRegistry;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\LinodeClient;
use App\Services\ServerMachine\Cloud\MachineCloudHealth;
use App\Services\ServerMachine\Cloud\MachineCloudService;
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
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineCloudTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineCloudService $service;
    private MachineCloudHealth $health;
    private MachineAddressInventory $inventory;
    private Account $account;
    private Resource $resource;
    private User $admin;
    private ServerMachine $machine;
    private string $instance = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('c', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        (require base_path('database/migrations/2026_11_05_170000_create_machine_cloud_tables.php'))->up();
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id')->nullable(); $table->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Synthetic machine', 'token' => 'synthetic-machine-token', 'is_active' => true]);
        $this->inventory = new MachineAddressInventory();
        $this->health = new MachineCloudHealth($this->inventory, new NodeInstanceRegistry());
        $this->service = new MachineCloudService(new LinodeClient(), $this->health);
        $this->account = $this->service->saveAccount(['name' => 'Synthetic Linode', 'api_token' => 'synthetic-cloud-token']);
        $this->fake(); $this->service->refreshAccount($this->account->id, 1);
        $this->resource = Resource::query()->firstOrFail();
        $this->report(); $this->fake();
    }

    private function remote(string $status = 'running'): array
    {
        return ['id' => 123, 'label' => 'Synthetic VM', 'status' => $status, 'created' => '2026-01-01T00:00:00',
            'region' => 'jp-osa', 'type' => 'g6-standard-1', 'ipv4' => ['8.8.8.8', '192.168.0.1'], 'ipv6' => '2606:4700:4700::1111/128'];
    }
    private function event(string $action, string $status = 'finished', int $id = 40): array
    {
        return ['id' => $id, 'action' => $action, 'status' => $status, 'created' => gmdate('Y-m-d\TH:i:s'), 'entity' => ['id' => 123, 'type' => 'linode']];
    }
    private function fake(string $status = 'running', array $events = [], array $maintenance = [], mixed $post = null): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) use ($status, $events, $maintenance, $post) {
            if ($request->method() === 'POST') return is_callable($post) ? $post($request) : Http::response($post ?? []);
            if (str_contains($request->url(), '/account/events')) return Http::response(['data' => $events, 'pages' => 1]);
            if (str_contains($request->url(), '/account/maintenance')) return Http::response(['data' => $maintenance, 'pages' => 1]);
            if (str_contains($request->url(), '/instances/123')) return Http::response($this->remote($status));
            if (str_contains($request->url(), '/instances/456')) return Http::response(array_replace($this->remote($status), ['id' => 456]));
            return Http::response(['data' => [$this->remote($status)], 'pages' => 1]);
        });
    }
    private function report(?string $instance = null, ?int $at = null): void
    {
        $this->inventory->record($this->machine, $instance ?? $this->instance, ['public_ipv4' => '8.8.8.8'], $at ?? time());
    }
    private function nodeReport(array $fields, int $nodeId = 9): void
    {
        DB::table('v2_server')->updateOrInsert(['id' => $nodeId], ['machine_id' => $this->machine->id, 'enabled' => true]);
        Cache::put('node_instances:' . $nodeId, [array_replace(['machine_id' => $this->machine->id,
            'installation_id' => $this->instance, 'last_seen_at' => time()], $fields)], 300);
    }
    public static function nodeReportTypes(): array
    {
        return ['resource status' => ['last_status_at'], 'online report' => ['last_online_at'], 'traffic report' => ['last_traffic_at']];
    }
    private function bind(): void
    {
        $this->service->binding($this->resource->id, $this->resource->fresh()->revision, $this->machine->id, $this->instance);
        $this->resource->refresh();
    }
    private function queue(string $action = 'reboot', string $request = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'): Task
    {
        return $this->service->queue($this->resource->id, $this->resource->fresh()->revision, $action, $request, $this->admin->id);
    }
    private function assertCode(string $code, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $code); }
        catch (CloudException $error) { $this->assertSame($code, $error->getMessage()); }
    }
    private function readyToRecover(): void
    {
        $this->bind();
        $this->service->policy($this->resource->id, $this->resource->revision, true, $this->admin->id);
        $this->resource->refresh();
    }
    private function offlineSamples(): void
    {
        $this->service->check($this->resource->id, $this->resource->fresh()->revision);
        $this->resource->refresh()->fill(['offline_since' => time() - 61, 'next_check_at' => 0])->save();
        $this->service->check($this->resource->id, $this->resource->revision);
    }

    public function test_accounts_are_encrypted_and_all_overviews_hide_credentials(): void
    {
        $this->assertStringNotContainsString('synthetic-cloud-token', DB::table('v2_machine_cloud_account')->value('credentials'));
        $this->assertSame('synthetic-cloud-token', $this->account->fresh()->credentials['api_token']);
        $this->assertStringNotContainsString('credentials', json_encode($this->service->overview()));
        $this->assertFalse($this->resource->auto_boot); $this->assertTrue($this->resource->manual_hold);
    }

    public function test_machine_list_cloud_summaries_are_local_read_only_and_scoped_to_full_installation(): void
    {
        $this->bind();
        $other = $this->resource->replicate();
        $other->fill(['external_id' => '456', 'label' => 'Second VM', 'installation_id' => str_repeat('b', 32),
            'status' => 'offline', 'observed_at' => time() - 400])->save();
        $this->fake();
        $summary = $this->service->listSummaries([$this->machine]);
        $rows = $summary[$this->machine->id];
        $this->assertCount(2, $rows);
        $byInstance = array_column($rows, null, 'installation_id');
        $this->assertSame('running', $byInstance[$this->instance]['status']);
        $this->assertSame('offline', $byInstance[str_repeat('b', 32)]['status']);
        $this->assertSame('jp-osa', $rows[0]['region']);
        $this->assertFalse($rows[0]['auto_boot']);
        $this->assertArrayNotHasKey('machine_authority', $rows[0]);
        $this->assertArrayNotHasKey('account_id', $rows[0]);
        $this->assertStringNotContainsString('synthetic-cloud-token', json_encode($summary));
        $this->assertSame([], $this->service->listSummaries([]));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_list_summary_never_attaches_an_old_binding_to_a_new_machine_authority(): void
    {
        $this->bind();
        $this->machine->token = 'new-machine-identity'; $this->machine->save();
        $this->fake();
        $this->assertSame([], $this->service->listSummaries([$this->machine]));
        $this->resource->installation_id = null; $this->resource->save();
        $this->assertSame([], $this->service->listSummaries([$this->machine]));
        Http::assertNothingSent();
    }

    public function test_list_summary_exposes_only_its_own_latest_task_without_private_request_or_admin_fields(): void
    {
        $this->bind(); $task = $this->queue(); $this->fake();
        $row = $this->service->listSummaries([$this->machine])[$this->machine->id][0];
        $this->assertSame($task->id, $row['task']['id']);
        $this->assertSame('queued', $row['task']['status']);
        $this->assertArrayNotHasKey('request_key', $row['task']);
        $this->assertArrayNotHasKey('admin_id', $row['task']);
        $task->resource_id = 999; $task->save();
        $this->assertNull($this->service->listSummaries([$this->machine])[$this->machine->id][0]['task']);
        Http::assertNothingSent();
    }

    public function test_list_summary_is_empty_before_cloud_migration(): void
    {
        Schema::drop('v2_machine_cloud_task');
        $this->fake();
        $this->assertSame([], $this->service->listSummaries([$this->machine])); Http::assertNothingSent();
    }
    public function test_blank_account_edit_keeps_token_but_disarms_policy(): void
    {
        $this->readyToRecover();
        $this->service->saveAccount(['id' => $this->account->id, 'expected_revision' => $this->account->revision, 'name' => 'Renamed', 'api_token' => '']);
        $this->assertSame('synthetic-cloud-token', $this->account->fresh()->credentials['api_token']);
        $this->assertFalse($this->resource->fresh()->auto_boot);
        $this->assertCode('config_changed', fn () => $this->service->saveAccount(['id' => $this->account->id, 'expected_revision' => 1, 'name' => 'Old']));
    }
    public function test_inventory_uses_only_official_https_and_normalizes_public_ips(): void
    {
        $this->service->refreshAccount($this->account->id, 1);
        $this->assertSame(['8.8.8.8', '2606:4700:4700::1111'], $this->resource->fresh()->public_ips);
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://api.linode.com/v4/linode/instances') && $r->method() === 'GET');
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }
    public function test_inventory_rejects_resource_identity_reuse(): void
    {
        $this->resource->provider_created_at = '2025-01-01T00:00:00Z'; $this->resource->save();
        $this->assertCode('identity_changed', fn () => $this->service->refreshAccount($this->account->id, 1));
        $this->assertSame('identity_changed', $this->account->fresh()->last_error);
    }
    public function test_same_resource_cannot_be_imported_from_another_account(): void
    {
        $other = $this->service->saveAccount(['name' => 'Other', 'api_token' => 'other-synthetic']);
        $this->assertCode('resource_in_use', fn () => $this->service->refreshAccount($other->id, 1));
        $this->assertSame(1, Resource::query()->count());
    }
    public function test_binding_requires_full_fresh_instance_and_matching_explicit_public_ip(): void
    {
        $this->assertCode('invalid_binding', fn () => $this->service->binding($this->resource->id, 1, $this->machine->id, 'aaaaaaaa'));
        $this->assertCode('source_unavailable', fn () => $this->service->binding($this->resource->id, 1, $this->machine->id, str_repeat('e', 32)));
        $this->report(null, time() - 181);
        $this->assertCode('source_unavailable', fn () => $this->bind());
        $this->report(); $this->bind();
        $this->assertSame($this->instance, $this->resource->installation_id);
        $this->assertStringNotContainsString('machine_authority', $this->resource->toJson());
    }
    public function test_an_instance_cannot_bind_two_cloud_resources(): void
    {
        $this->bind();
        $other = Resource::query()->create(['account_id' => $this->account->id, 'external_id' => '456',
            'provider_created_at' => $this->resource->provider_created_at, 'label' => 'Second VM', 'status' => 'running', 'region' => 'jp-osa', 'size' => 'g6-standard-1', 'public_ips' => [], 'observed_at' => time()]);
        $other->refresh();
        $this->assertCode('instance_in_use', fn () => $this->service->binding($other->id, $other->revision, $this->machine->id, $this->instance));
    }
    public function test_power_is_queued_idempotently_with_no_post_before_worker(): void
    {
        $task = $this->queue();
        $again = $this->service->queue($this->resource->id, 1, 'reboot', str_repeat('b', 32), $this->admin->id);
        $this->assertSame($task->id, $again->id); $this->assertSame(1, Task::query()->count());
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        Bus::assertDispatched(MachineCloudPowerJob::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'admin_operations');
        $this->assertCode('task_active', fn () => $this->queue('shutdown', str_repeat('c', 32)));
        $this->assertCode('request_conflict', fn () => $this->service->queue($this->resource->id, 1, 'shutdown', str_repeat('b', 32), $this->admin->id));
    }
    public function test_reboot_requires_a_new_finished_event_not_merely_running(): void
    {
        $task = $this->queue(); $this->service->process($task->id); $this->service->process($task->id);
        $this->assertSame('waiting_provider', $task->fresh()->status);
        $this->fake('running', [$this->event('linode_reboot')]); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertNull($this->resource->fresh()->active_task_id);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_post_timeout_is_uncertain_and_never_replayed(): void
    {
        $task = $this->queue(); $attempts = 0;
        $this->fake('running', [], [], function () use (&$attempts) { $attempts++; throw new \Illuminate\Http\Client\ConnectionException('private provider detail'); });
        $this->service->process($task->id); $this->service->process($task->id); $this->service->process($task->id);
        $this->assertSame('uncertain', $task->fresh()->status);
        $this->assertSame(1, $attempts);
        $this->assertStringNotContainsString('private provider detail', $task->fresh()->toJson());
    }
    public function test_worker_crash_after_dispatch_stamp_never_replays_post(): void
    {
        $task = $this->queue(); $task->fill(['status' => 'dispatching', 'dispatched_at' => time()])->save();
        $this->service->process($task->id); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
        $this->assertSame('dispatching', $task->fresh()->status);
    }
    public function test_only_queued_task_can_be_cancelled(): void
    {
        $task = $this->queue(); $this->service->cancel($task->id, $this->admin->id); $this->service->process($task->id);
        $this->assertSame('cancelled', $task->fresh()->status); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_dispatch_boundary_cannot_be_cancelled_or_retried(): void
    {
        $task = $this->queue(); $this->service->process($task->id);
        $this->assertCode('already_dispatched', fn () => $this->service->cancel($task->id, $this->admin->id));
        $task->deadline_at = time() - 1; $task->save(); $this->service->process($task->id);
        $this->assertSame('expired', $task->fresh()->status); $this->assertTrue($this->resource->fresh()->manual_hold);
    }
    public function test_changed_revision_or_revoked_admin_blocks_queued_power(): void
    {
        $task = $this->queue(); $this->admin->is_admin = false; $this->admin->save(); $this->service->process($task->id);
        $this->assertSame('failed', $task->fresh()->status); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_rotated_machine_authority_blocks_queued_power(): void
    {
        $this->bind(); $task = $this->queue(); $this->machine->token = 'replacement-token'; $this->machine->save();
        $this->service->process($task->id); $this->assertSame('identity_changed', $task->fresh()->reason);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_authorization_revoked_during_cloud_preflight_still_blocks_post(): void
    {
        $task = $this->queue();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/account/events')) {
                $this->admin->is_admin = false; $this->admin->save();
                return Http::response(['data' => [], 'pages' => 1]);
            }
            if (str_contains($request->url(), '/account/maintenance')) return Http::response(['data' => [], 'pages' => 1]);
            return Http::response($this->remote());
        });
        $this->service->process($task->id);
        $this->assertSame('permission_revoked', $task->fresh()->reason); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_maintenance_and_pending_events_block_manual_power(): void
    {
        $this->fake('running', [], [['entity' => ['id' => 123, 'type' => 'linode'], 'status' => 'pending']]);
        $this->assertCode('maintenance', fn () => $this->queue());
        $this->fake('running', [$this->event('linode_resize', 'started')]);
        $this->assertCode('provider_busy', fn () => $this->queue()); $this->assertSame(0, Task::query()->count());
    }
    public function test_long_running_events_are_not_hidden_by_a_recent_time_window(): void
    {
        $event = $this->event('linode_migrate', 'in_progress'); $event['created'] = gmdate('Y-m-d\TH:i:s', time() - 86400);
        $this->fake('running', [$event]); $this->assertCode('provider_busy', fn () => $this->queue());
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/account/events')) return false;
            $filter = json_decode($request->header('X-Filter')[0], true);
            return $filter['entity.id'] === 123 && $filter['entity.type'] === 'linode' && !isset($filter['created']);
        });
    }
    public function test_automatic_policy_is_off_and_running_cloud_never_reboots_for_stale_agent(): void
    {
        $this->service->check($this->resource->id, 1); Http::assertNothingSent();
        $this->readyToRecover(); $this->report(null, time() - 181); $this->fake();
        $this->service->check($this->resource->id, $this->resource->revision);
        $this->assertSame('reports_pending', $this->resource->fresh()->recovery_status);
        $this->assertSame(0, Task::query()->count()); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_auto_boot_requires_two_offline_samples_and_queues_only_once(): void
    {
        $this->readyToRecover(); $this->fake('offline'); $this->offlineSamples();
        $task = Task::query()->firstOrFail(); $this->assertTrue($task->automatic); $this->assertSame('boot', $task->action);
        $this->service->check($this->resource->id, $this->resource->fresh()->revision);
        $this->assertSame(1, Task::query()->count()); $this->assertFalse($this->resource->fresh()->armed);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_external_shutdown_suppresses_auto_boot(): void
    {
        $this->readyToRecover(); $this->fake('offline', [$this->event('linode_shutdown')]); $this->offlineSamples();
        $this->assertSame(0, Task::query()->count()); $this->assertTrue($this->resource->fresh()->manual_hold);
        $this->assertSame('external_shutdown', $this->resource->fresh()->recovery_status);
    }
    public function test_manual_shutdown_holds_auto_boot_and_dns(): void
    {
        $this->readyToRecover(); $task = $this->queue('shutdown'); $this->service->process($task->id);
        $this->fake('offline'); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status); $this->assertTrue($this->resource->fresh()->manual_hold);
        $this->assertTrue($this->resource->fresh()->dns_hold);
        $this->resource->refresh()->fill(['next_check_at' => 0])->save(); $this->service->check($this->resource->id, $this->resource->revision);
        $this->assertSame(1, Task::query()->count());
    }
    public function test_auto_boot_obeys_cooldown_and_daily_budget(): void
    {
        $this->readyToRecover(); $this->resource->last_auto_boot_at = time() - 60; $this->resource->save();
        $this->fake('offline'); $this->offlineSamples();
        $this->assertSame('auto_budget', $this->resource->fresh()->recovery_status); $this->assertSame(0, Task::query()->count());
    }
    public function test_stale_baseline_cannot_boot(): void
    {
        $this->readyToRecover(); $this->resource->last_running_at = time() - 601; $this->resource->save();
        $this->fake('offline'); $this->service->check($this->resource->id, $this->resource->revision);
        $this->assertSame('baseline_required', $this->resource->fresh()->recovery_status); $this->assertSame(0, Task::query()->count());
    }
    public function test_binding_and_account_changes_are_locked_during_power(): void
    {
        $this->queue();
        $this->assertCode('task_active', fn () => $this->service->binding($this->resource->id, $this->resource->fresh()->revision, null, null));
        $this->assertCode('task_active', fn () => $this->service->saveAccount(['id' => $this->account->id, 'expected_revision' => 1, 'name' => 'Busy']));
    }
    public function test_recovery_waits_for_same_instance_agent_and_node_reports(): void
    {
        $this->bind(); DB::table('v2_server')->insert(['id' => 9, 'machine_id' => $this->machine->id, 'enabled' => true]);
        $task = $this->queue(); $this->service->process($task->id);
        $task->refresh()->fill(['dispatched_at' => time() - 1])->save();
        $this->fake('running', [$this->event('linode_reboot')]); $this->service->process($task->id);
        $this->assertSame('waiting_reports', $task->fresh()->status); $this->assertTrue($this->resource->fresh()->dns_hold);
        $node = ['machine_id' => $this->machine->id, 'installation_id' => str_repeat('d', 32), 'last_seen_at' => time(), 'last_status_at' => time()];
        Cache::put('node_instances:9', [$node], 300); $this->service->process($task->id);
        $this->assertSame('waiting_reports', $task->fresh()->status);
        $node['installation_id'] = $this->instance; Cache::put('node_instances:9', [$node], 300); $this->report(); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status); $this->assertFalse($this->resource->fresh()->dns_hold);
    }

    #[DataProvider('nodeReportTypes')]
    public function test_fresh_node_report_types_are_all_valid_recovery_evidence(string $field): void
    {
        $this->bind(); $this->nodeReport([$field => time(), 'online_users' => 0]);
        $this->assertSame(['agent' => 'fresh', 'nodes' => 'fresh', 'ready' => true], $this->health->snapshot($this->resource));
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    #[DataProvider('nodeReportTypes')]
    public function test_a_new_node_report_can_complete_recovery_without_another_power_post(string $field): void
    {
        $this->bind(); $this->nodeReport([]);
        $task = $this->queue(); $this->service->process($task->id);
        $task->refresh()->fill(['dispatched_at' => time() - 10])->save();
        $this->fake('running', [$this->event('linode_reboot')]);
        $this->service->process($task->id);
        $this->assertSame('waiting_reports', $task->fresh()->status);
        $this->assertTrue($this->resource->fresh()->dns_hold);
        $this->nodeReport([$field => time()]); $this->service->process($task->id);
        $this->assertSame('completed', $task->fresh()->status);
        $this->assertFalse($this->resource->fresh()->dns_hold);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_generic_node_poll_or_heartbeat_alone_does_not_prove_recovery(): void
    {
        $this->bind(); $this->nodeReport(['last_config_at' => time(), 'last_heartbeat_at' => time()]);
        $this->assertSame(['agent' => 'fresh', 'nodes' => 'waiting', 'ready' => false], $this->health->snapshot($this->resource));
    }

    #[DataProvider('nodeReportTypes')]
    public function test_node_evidence_must_respect_the_existing_node_freshness_window(string $field): void
    {
        $this->bind();
        $this->nodeReport([$field => time() - min(180, \App\Services\UserOnlineService::nodeDataExpirySeconds()) - 1]);
        $this->assertFalse($this->health->snapshot($this->resource)['ready']);
    }

    #[DataProvider('nodeReportTypes')]
    public function test_new_poll_does_not_make_pre_dispatch_node_evidence_fresh_again(string $field): void
    {
        $this->bind(); $after = time() - 10;
        foreach ([$after - 1, $after] as $at) {
            $this->nodeReport([$field => $at]);
            $this->assertFalse($this->health->snapshot($this->resource, $after)['ready']);
        }
        $this->nodeReport([$field => $after + 1]);
        $this->assertTrue($this->health->snapshot($this->resource, $after)['ready']);
    }

    public function test_future_or_malformed_field_does_not_hide_another_valid_report(): void
    {
        $this->bind(); $this->nodeReport(['last_status_at' => time() + 3600, 'last_online_at' => 'invalid', 'last_traffic_at' => time()]);
        $this->assertTrue($this->health->snapshot($this->resource)['ready']);
    }

    public function test_future_malformed_or_missing_reports_do_not_prove_recovery(): void
    {
        $this->bind();
        foreach ([time() + 3600, 'invalid', 0, -1, null] as $at) {
            $this->nodeReport(['last_status_at' => $at, 'last_online_at' => $at, 'last_traffic_at' => $at]);
            $this->assertFalse($this->health->snapshot($this->resource)['ready']);
        }
    }

    public function test_online_or_traffic_evidence_never_crosses_machine_or_installation_identity(): void
    {
        $this->bind();
        foreach ([['machine_id' => $this->machine->id + 1], ['installation_id' => null],
            ['installation_id' => substr($this->instance, 0, 8)], ['installation_id' => str_repeat('d', 32)]] as $identity) {
            $this->nodeReport($identity + ['last_online_at' => time(), 'last_traffic_at' => time()]);
            $this->assertFalse($this->health->snapshot($this->resource)['ready']);
        }
    }

    public function test_every_enabled_bound_node_needs_its_own_report(): void
    {
        $this->bind(); $this->nodeReport(['last_online_at' => time()], 9); $this->nodeReport([], 10);
        $this->assertFalse($this->health->snapshot($this->resource)['ready']);
        $this->nodeReport(['last_traffic_at' => time()], 10);
        $this->assertTrue($this->health->snapshot($this->resource)['ready']);
        $this->nodeReport([], 10); DB::table('v2_server')->where('id', 10)->update(['enabled' => false]);
        $this->assertTrue($this->health->snapshot($this->resource)['ready']);
    }

    public function test_replica_bound_nodes_cannot_borrow_another_machines_report(): void
    {
        $this->bind();
        Schema::create('v2_server_machine_binding', function (Blueprint $table) { $table->integer('machine_id'); $table->integer('server_id'); });
        $this->nodeReport(['last_online_at' => time()], 10);
        DB::table('v2_server')->where('id', 10)->update(['machine_id' => $this->machine->id + 1]);
        DB::table('v2_server_machine_binding')->insert(['machine_id' => $this->machine->id, 'server_id' => 10]);
        $this->assertTrue($this->health->snapshot($this->resource)['ready']);
        Cache::put('node_instances:10', [['machine_id' => $this->machine->id + 1, 'installation_id' => $this->instance,
            'last_seen_at' => time(), 'last_online_at' => time()]], 300);
        $this->assertFalse($this->health->snapshot($this->resource)['ready']);
    }

    public function test_node_business_reports_cannot_replace_missing_or_pre_dispatch_agent_evidence(): void
    {
        $this->bind(); $this->nodeReport(['last_traffic_at' => time()]); $after = time() - 10;
        $this->report(null, $after); $this->assertFalse($this->health->snapshot($this->resource, $after)['ready']);
        $this->report(null, time() - 181); $this->assertFalse($this->health->snapshot($this->resource)['ready']);
        $this->report(); $this->assertTrue($this->health->snapshot($this->resource)['ready']);
    }
    public function test_observed_peer_does_not_prove_agent_identity_or_recovery(): void
    {
        $this->bind(); $this->inventory->record($this->machine, $this->instance, ['panel_seen' => '8.8.8.8'], time());
        $this->assertSame('address_mismatch', $this->health->snapshot($this->resource)['agent']);
        $this->assertCode('baseline_required', fn () => $this->service->policy($this->resource->id, $this->resource->revision, true, $this->admin->id));
    }
    public function test_reports_from_dispatch_second_are_not_new_recovery_evidence(): void
    {
        $this->bind(); $this->assertFalse($this->health->snapshot($this->resource, time())['ready']);
        $this->assertTrue($this->health->snapshot($this->resource, time() - 1)['ready']);
    }
    public function test_automatic_daily_budget_counts_prior_attempts_not_only_successes(): void
    {
        $this->readyToRecover();
        foreach ([7200, 14400] as $age) Task::query()->create(['resource_id' => $this->resource->id, 'admin_id' => $this->admin->id,
            'request_key' => hash('sha256', (string) $age), 'action' => 'boot', 'automatic' => true, 'resource_revision' => 1,
            'account_revision' => 1, 'external_id' => '123', 'resource_label' => 'Synthetic VM', 'status' => 'failed',
            'deadline_at' => time() - $age + 900, 'created_at' => time() - $age]);
        $this->fake('offline'); $this->offlineSamples();
        $this->assertSame('auto_budget', $this->resource->fresh()->recovery_status);
        $this->assertSame(2, Task::query()->count());
    }
    public function test_recovery_verification_only_releases_dns_after_live_matching_reports(): void
    {
        $this->bind(); $this->resource->dns_hold = true; $this->resource->save();
        $this->report(null, time() - 181);
        $this->assertCode('reports_pending', fn () => $this->service->verifyRecovery($this->resource->id, $this->resource->revision));
        $this->report(); $this->service->verifyRecovery($this->resource->id, $this->resource->revision);
        $this->assertFalse($this->resource->fresh()->dns_hold); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_cloud_state_change_between_queue_and_dispatch_is_not_overridden(): void
    {
        $task = $this->queue(); $this->fake('offline'); $this->service->process($task->id);
        $this->assertSame('state_changed', $task->fresh()->reason); Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }
    public function test_admin_cannot_queue_power_from_a_stale_resource_revision(): void
    {
        $this->resource->revision = 2; $this->resource->save();
        $this->assertCode('config_changed', fn () => $this->service->queue($this->resource->id, 1, 'reboot', str_repeat('c', 32), $this->admin->id));
        Http::assertNothingSent();
    }
    public function test_delete_removes_local_account_only_and_refuses_bound_account(): void
    {
        $this->bind(); $this->assertCode('account_in_use', fn () => $this->service->deleteAccount($this->account->id, 1));
        $this->service->binding($this->resource->id, $this->resource->revision, null, null); $this->fake();
        $this->service->deleteAccount($this->account->id, 1); $this->assertSame(0, Resource::query()->count()); Http::assertNothingSent();
    }
    public function test_safety_pagination_and_unknown_event_status_fail_closed(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => [], 'pages' => 4])]);
        $this->assertCode('safety_unknown', fn () => (new LinodeClient())->safety($this->account, '123', time() - 600));
        $this->fake('running', [$this->event('linode_boot', 'unknown')]);
        $this->assertCode('safety_unknown', fn () => $this->queue());
    }
    public function test_provider_errors_never_expose_response_bodies_or_follow_redirects(): void
    {
        foreach ([302 => 'provider_rejected', 403 => 'provider_forbidden', 429 => 'provider_rate_limited', 503 => 'provider_unavailable'] as $status => $code) {
            Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
            Http::fake(['*' => Http::response(['secret' => 'private-body'], $status, ['Location' => 'http://127.0.0.1'])]);
            $this->assertCode($code, fn () => (new LinodeClient())->instance($this->account, '123'));
        }
    }
    public function test_actions_are_allowlisted_and_resource_paths_reject_injection(): void
    {
        $this->assertCode('invalid_action', fn () => $this->queue('delete'));
        $this->assertCode('invalid_resource', fn () => (new LinodeClient())->power($this->account, '../other', 'boot'));
        Http::assertNothingSent();
    }
    public function test_scheduler_is_bounded_and_inert_without_migration(): void
    {
        $this->readyToRecover(); $this->assertSame(['tasks' => 0, 'checks' => 1], $this->service->schedule());
        Bus::assertDispatched(CheckMachineCloudJob::class);
        (require base_path('database/migrations/2026_11_05_170000_create_machine_cloud_tables.php'))->down();
        $this->assertSame(['tasks' => 0, 'checks' => 0], $this->service->schedule());
        $this->assertFalse($this->service->overview()['available']);
    }
    public function test_non_admin_cannot_read_accounts_or_resources(): void
    {
        $request = Request::create('/cloud', 'GET'); $request->setUserResolver(fn () => new User(['is_admin' => false]));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new MachineCloudController())->fetch($request, $this->service);
    }
}
