<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverController;
use App\Jobs\MachineProvisionJob;
use App\Jobs\SendMachineOperationNoticeJob;
use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverPlan;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineOperationNotice as Notice;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\MachineReplacementTask as Replacement;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\MachineReplacementService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Services\ServerMachine\MachineOperationNotifier;
use App\Services\ServerMachine\MachineOperationNoticeText;
use App\Services\TelegramService;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineReplacementTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineReplacementService $service;
    private MachineProvisionService $provision;
    private MachineOperationNotifier $notifier;
    private MachineAddressInventory $inventory;
    private MachineFailoverPolicy $policy;
    private MachineCloudAccount $account;
    private ServerMachine $machine;
    private User $admin;
    private Task $source;
    private int $posts = 0;
    private int $clock;
    private array $remote = [];

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('r', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $name) config([$name => require base_path('config/' . $name . '.php')]);
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_010000_add_machine_cloud_providers.php', '2026_11_06_030000_create_machine_failover_policies.php',
            '2026_11_06_040000_create_machine_failover_observations.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_11_07_090000_create_machine_replacement_tasks.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id'); $table->boolean('enabled'); });
        app()->instance(Setting::class, new class {
            public function get(string $key, mixed $default = null): mixed { return ['app_url' => 'https://panel.example.test',
                'server_entry_probe_enable' => true, 'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']][$key] ?? $default; }
        });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Independent fixture', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true]);
        $this->account = MachineCloudAccount::query()->create(['name' => 'Synthetic Linode', 'provider' => 'linode', 'credentials' => ['api_token' => 'synthetic-cloud-secret'], 'revision' => 1]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'credentials' => ['api_token' => 'synthetic-dns-secret'], 'revision' => 1]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Independent replacement', 'machine_id' => $this->machine->id,
            'account_id' => $this->account->id, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12',
            'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100,
            'daily_limit' => 1, 'dns_credential_id' => $dns->id, 'dns_hostname' => 'isolated.example.test', 'isolation_confirmed' => true], $this->admin->id);
        $this->policy = MachineFailoverPolicy::query()->findOrFail($saved['id']);
        $this->policy->forceFill(['created_at' => time() - 60])->save();
        $this->source = Task::query()->create(['id' => (string) Str::uuid(), 'request_key' => bin2hex(random_bytes(32)),
            'admin_id' => $this->admin->id, 'account_id' => $this->account->id, 'account_revision' => 1,
            'machine_id' => $this->machine->id, 'machine_authority' => hash('sha256', $this->machine->token), 'plan_hash' => str_repeat('a', 64),
            'slot' => $this->policy->slot, 'installation_id' => str_repeat('a', 32), 'callback_hash' => str_repeat('a', 64),
            'marker' => 'synthetic-source-marker', 'payload' => ['plan' => [], 'request' => []], 'status' => 'accepted',
            'deadline_at' => time() - 86400, 'created_at' => time() - 86401, 'updated_at' => time() - 86401]);
        MachineOwnedInstance::query()->create(['policy_id' => $this->policy->id, 'policy_revision' => 1, 'task_id' => $this->source->id,
            'installation_id' => $this->source->installation_id, 'release_id' => 1, 'binary_sha256' => str_repeat('f', 64),
            'version' => 'synthetic', 'ip' => '8.8.8.8', 'status' => 'active', 'active_policy_id' => $this->policy->id]);
        $this->inventory = app(MachineAddressInventory::class);
        $this->service = app(MachineReplacementService::class); $this->provision = app(MachineProvisionService::class);
        $this->notifier = new class extends MachineOperationNotifier { protected function channelReady(): bool { return true; } };
        app()->instance(MachineOperationNotifier::class, $this->notifier);
        $this->clock = time(); config(['machine_failover.observe_enabled' => true]);
        foreach ([-15 => true, -10 => false, -5 => false] as $delta => $healthy) {
            $this->report($healthy, $this->clock + $delta);
            app(MachineFailoverObserver::class)->observe($this->policy->id);
        }
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
        $this->fake();
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function report(bool $healthy, ?int $at = null, ?Task $task = null, ?string $ip = null): void
    {
        $at ??= $this->clock; $task ??= $this->source;
        $ip ??= $task->id === $this->source->id ? '8.8.8.8' : '8.8.4.4';
        Carbon::setTestNow(Carbon::createFromTimestampUTC($at));
        $configuration = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $configuration['revision'], 'state' => 'ready', 'checked_at' => $at,
            'targets' => array_map(fn ($target, $index) => ['id' => $target['id'], 'status' => $healthy && $index === 0 ? 'reachable' : 'unreachable',
                'source_ip' => $ip], $configuration['targets'], array_keys($configuration['targets']))];
        $this->inventory->record($this->machine, $task->installation_id, ['public_ipv4' => $ip], $at, [], $probe);
    }

    private function enable(): void
    {
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => [$this->machine->id],
            'machine_failover.allow_replacement' => true, 'machine_failover.allowed_replacement_policy_ids' => [$this->policy->id]]);
    }

    private function queue(string $key = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'): Task
    {
        $this->enable();
        $result = $this->service->queue(MachineFailoverPlan::query()->latest('id')->value('id'), 1, $key, $this->admin->id);
        return Task::query()->findOrFail($result['id']);
    }

    private function code(string $expected, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $expected); }
        catch (CloudException $error) { $this->assertSame($expected, $error->getMessage()); }
    }

    private function fake(?callable $post = null, ?callable $preflight = null, int $price = 48): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) use ($post, $preflight, $price) {
            $this->assertSame('api.linode.com', parse_url($request->url(), PHP_URL_HOST));
            if ($request->method() !== 'GET') {
                $this->posts++; $this->assertSame('POST', $request->method());
                $this->assertSame('https://api.linode.com/v4/linode/instances', $request->url());
                if ($post) return $post($request);
                $this->remote = ['id' => 987, 'label' => $request['label'], 'tags' => $request['tags'], 'region' => 'sg-sin-2',
                    'type' => 'g6-standard-4', 'image' => 'linode/debian12', 'created' => gmdate('Y-m-d\TH:i:s'),
                    'status' => 'running', 'ipv4' => ['8.8.4.4'], 'ipv6' => ''];
                return Http::response($this->remote);
            }
            if (str_contains($request->url(), '/regions/')) return Http::response(['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']]);
            if (str_contains($request->url(), '/images/')) return Http::response(['id' => 'linode/debian12', 'status' => 'available', 'deprecated' => false, 'capabilities' => ['cloud-init']]);
            if (str_contains($request->url(), '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => 'standard', 'price' => ['monthly' => $price], 'region_prices' => []]);
            if (str_contains($request->url(), '/account/settings')) { if ($preflight) $preflight(); return Http::response(['backups_enabled' => false]); }
            if (str_contains($request->url(), '/instances/987')) return Http::response($this->remote);
            if (str_contains($request->url(), '/linode/instances?')) return Http::response(['data' => [], 'pages' => 1]);
            throw new \RuntimeException('Unmocked synthetic route');
        });
    }

    private function poll(Task $task): Task
    {
        $task->refresh()->fill(['next_check_at' => 0])->save(); $this->provision->process($task->id); return $task->refresh();
    }

    private function enableNotices(): void { $this->notifier->save('provision', true, 1, $this->admin->id); }
    private function transport(int $times, bool $fails = false): void
    {
        $mock = \Mockery::mock(TelegramService::class);
        $expectation = $mock->shouldReceive('sendMessageWithActiveAdmins')->times($times)->withArgs(function ($message) {
            foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', 'root_pass', 'Bearer'] as $secret) $this->assertStringNotContainsString($secret, $message);
            return is_string($message);
        });
        $fails ? $expectation->andThrow(new \RuntimeException('Synthetic ambiguous delivery')) : $expectation->andReturnNull();
        app()->instance(TelegramService::class, $mock);
    }

    public function test_defaults_are_closed_and_observation_is_not_creation_authority(): void
    {
        $this->assertFalse(config('machine_failover.allow_replacement'));
        $this->assertSame([], config('machine_failover.allowed_replacement_policy_ids'));
        $this->assertFalse(config('machine_provision.allow_live')); $this->assertFalse(config('machine_acceptance.allow_dns'));
        $this->assertFalse($this->notifier->overview('provision')['policy']['enabled']);
        $this->code('replacement_disabled', fn () => $this->service->queue(MachineFailoverPlan::query()->value('id'), 1, str_repeat('b', 32), $this->admin->id));
        app(MachineFailoverObserver::class)->collect();
        $this->assertSame(1, Task::count()); $this->assertSame(0, Replacement::count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_atomic_budget_reservation_and_episode_deduplication_are_idempotent(): void
    {
        $task = $this->queue(); $again = $this->queue();
        $this->assertSame($task->id, $again->id); $this->assertSame(1, Replacement::count());
        $row = Replacement::query()->firstOrFail();
        $this->assertSame($task->replacement_id, $row->id); $this->assertSame(100.0, $row->reserved_monthly_usd);
        $this->assertSame($task->id, $row->task_id); $this->assertSame('queued', $row->status);
        $this->code('replacement_episode_used', fn () => $this->queue(str_repeat('c', 32)));
        $this->assertSame(2, Task::count()); Http::assertNothingSent();
        Bus::assertDispatched(MachineProvisionJob::class); Bus::assertNotDispatched(SendMachineOperationNoticeJob::class);
        $json = json_encode($this->service->overview());
        foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', 'root_pass', 'Bearer', $task->installation_id, $this->source->installation_id] as $secret) $this->assertStringNotContainsString($secret, $json);
    }

    public function test_each_execution_gate_and_the_test_machine_allowlist_are_required(): void
    {
        $this->enable(); config(['machine_failover.allowed_replacement_policy_ids' => []]);
        $this->code('replacement_not_allowed', fn () => $this->service->queue(MachineFailoverPlan::query()->value('id'), 1, str_repeat('b', 32), $this->admin->id));
        $this->enable(); config(['machine_provision.allow_live' => false]);
        $this->code('provisioning_disabled', fn () => $this->service->queue(MachineFailoverPlan::query()->value('id'), 1, str_repeat('b', 32), $this->admin->id));
        $this->enable(); config(['machine_provision.allowed_test_machine_ids' => []]);
        $this->code('test_machine_not_allowed', fn () => $this->service->queue(MachineFailoverPlan::query()->value('id'), 1, str_repeat('b', 32), $this->admin->id));
        $this->assertSame(0, Replacement::count()); Http::assertNothingSent();
    }

    public function test_manual_queue_cannot_bypass_owned_replacement_guards(): void
    {
        $this->enable();
        $input = ['account_id' => $this->account->id, 'machine_id' => $this->machine->id, 'slot' => $this->policy->slot,
            'panel_origin' => 'https://panel.example.test', 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'image' => 'linode/debian12', 'monthly_limit_usd' => 100];
        $this->code('replacement_required', fn () => $this->provision->queue($input, str_repeat('b', 32), $this->admin->id));
        $this->assertSame(1, Task::count()); Http::assertNothingSent();
    }

    public function test_source_recovery_before_dispatch_cancels_without_a_provider_request(): void
    {
        $task = $this->queue(); $this->report(true);
        $task = $this->poll($task);
        $this->assertSame('cancelled', $task->status); $this->assertSame('source_recovered', $task->reason);
        $this->assertNotNull(Replacement::query()->firstOrFail()->source_recovered_at);
        Http::assertNothingSent(); $this->assertSame(0, $this->posts);
    }

    public function test_provider_price_is_checked_before_the_only_create_post(): void
    {
        $task = $this->queue(); $this->fake(price: 101); $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('budget_exceeded', $task->reason);
        $this->assertSame(0, $this->posts); $this->assertSame(100.0, Replacement::query()->firstOrFail()->reserved_monthly_usd);
    }

    public function test_revoked_actor_after_preflight_prevents_creation(): void
    {
        $task = $this->queue(); $this->fake(preflight: fn () => $this->admin->fill(['banned' => true])->save());
        $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('permission_revoked', $task->reason); $this->assertSame(0, $this->posts);
    }

    public function test_recovery_during_preflight_is_rechecked_before_post(): void
    {
        $task = $this->queue(); $this->fake(preflight: fn () => $this->report(true));
        $task = $this->poll($task);
        $this->assertSame('cancelled', $task->status); $this->assertSame(0, $this->posts);
    }

    public function test_observer_guard_refresh_for_our_own_reservation_does_not_create_another_attempt(): void
    {
        $task = $this->queue(); $this->report(false);
        app(MachineFailoverObserver::class)->observe($this->policy->id);
        $task = $this->poll($task);
        $this->assertSame('waiting_initialization', $task->status); $this->assertSame(1, $this->posts);
        $this->assertSame(1, Replacement::count());
    }

    public function test_ambiguous_post_is_reconciled_never_repeated_even_after_deadline(): void
    {
        $task = $this->queue(); $this->fake(post: fn () => throw new ConnectionException('Synthetic timeout'));
        $task = $this->poll($task); $this->assertSame('outcome_unknown', $task->status);
        $this->poll($task); $this->poll($task); $this->assertSame(1, $this->posts);
        $task->fill(['deadline_at' => time() - 1])->save(); $task = $this->poll($task);
        $this->assertSame('needs_review', $task->status); $this->assertSame('create_not_resolved', $task->reason);
        $this->assertSame(1, $this->posts); $this->assertSame('needs_review', Replacement::query()->firstOrFail()->status);
    }

    public function test_account_wide_daily_limit_includes_previous_failed_attempts(): void
    {
        $prior = $this->source->replicate();
        $prior->fill(['id' => (string) Str::uuid(), 'request_key' => bin2hex(random_bytes(32)), 'installation_id' => bin2hex(random_bytes(16)),
            'marker' => 'synthetic-prior-failure', 'status' => 'failed', 'created_at' => time() - 10])->save();
        $this->code('daily_budget', fn () => $this->queue());
        $this->assertSame(0, Replacement::count()); Http::assertNothingSent();
    }

    public function test_account_wide_cooldown_is_checked_before_reserving(): void
    {
        $this->policy->fill(['daily_limit' => 2])->save(); $this->source->fill(['created_at' => time() - 10])->save();
        $this->code('replacement_cooldown', fn () => $this->queue());
        $this->assertSame(0, Replacement::count()); Http::assertNothingSent();
    }

    public function test_simulated_initialization_reports_and_ipv4_stop_at_forwarding_acceptance(): void
    {
        $task = $this->poll($this->queue()); $this->assertSame('waiting_initialization', $task->status);
        $data = Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data']));
        preg_match('/Bearer ([a-f0-9]{64})/', $data['write_files'][2]['content'], $match);
        $this->assertTrue($this->provision->callback($task->id, $task->installation_id, $match[1], 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 3])->save();
        $this->report(true, $this->clock, $task);
        Cache::put('node_instances:10', [['machine_id' => $this->machine->id, 'installation_id' => $task->installation_id,
            'last_seen_at' => $this->clock, 'last_online_at' => $this->clock]], 300);
        $task = $this->poll($task);
        $this->assertSame('awaiting_acceptance', $task->status);
        $this->assertSame('awaiting_acceptance', Replacement::query()->firstOrFail()->status);
        $this->assertFalse($task->evidence['forwarding_verified']); $this->assertFalse($task->evidence['dns_changed']);
        $this->assertSame(1, $this->posts); $this->assertSame('accepted', $this->source->fresh()->status);
        $this->assertFalse(config('machine_acceptance.allow_dns')); $this->assertSame(0, DB::table('v2_machine_dns_transition')->count());
    }

    public function test_progress_failure_and_later_source_recovery_notices_are_deduplicated_and_bounded(): void
    {
        $this->enableNotices(); $this->transport(3); $task = $this->queue();
        $queued = Notice::query()->latest('id')->firstOrFail(); $this->notifier->deliver($queued->id);
        $this->service->sync($task); $this->service->sync($task); $this->assertSame(1, Notice::count());
        $task->fill(['status' => 'failed', 'reason' => 'initialization_failed'])->save();
        $this->service->sync($task); $this->service->sync($task);
        $failed = Notice::query()->latest('id')->firstOrFail(); $this->assertSame('failed', $failed->phase); $this->notifier->deliver($failed->id);
        $this->report(true); $this->notifier->advance();
        $recovered = Notice::query()->latest('id')->firstOrFail(); $this->assertSame('recovered', $recovered->phase);
        $this->assertStringContainsString('原实例 IPv4 探测恢复', MachineOperationNoticeText::message($recovered));
        $this->assertStringContainsString('不代表客户端转发恢复', MachineOperationNoticeText::message($recovered));
        $this->notifier->deliver($recovered->id); $this->notifier->advance();
        $this->assertSame(3, Notice::count()); $this->assertSame('failed', Replacement::query()->firstOrFail()->status);
        $this->assertSame(0, $this->posts); Http::assertNothingSent();
    }

    public function test_old_queued_progress_is_suppressed_after_failure(): void
    {
        $this->enableNotices(); $this->transport(0); $task = $this->queue(); $notice = Notice::query()->firstOrFail();
        $task->fill(['status' => 'failed', 'reason' => 'initialization_failed'])->save(); $this->service->sync($task);
        $this->notifier->deliver($notice->id); $this->assertSame('suppressed', $notice->fresh()->status);
    }

    public function test_notification_ambiguity_is_not_retried_and_policy_toggle_does_not_replay_disabled_events(): void
    {
        $task = $this->queue(); $disabled = Notice::query()->firstOrFail(); $this->assertSame('disabled', $disabled->status);
        $this->enableNotices(); $this->notifier->advance(); $this->assertSame('disabled', $disabled->fresh()->status);
        $task->fill(['status' => 'failed', 'reason' => 'initialization_failed'])->save(); $this->service->sync($task);
        $notice = Notice::query()->latest('id')->firstOrFail(); $this->transport(1, true); $this->notifier->deliver($notice->id);
        $this->notifier->advance(); $this->notifier->deliver($notice->id); $this->assertSame('unknown', $notice->fresh()->status);
    }

    public function test_existing_tanzhen_resource_and_global_gates_are_not_modified_by_progress_reads(): void
    {
        $before = $this->source->fresh()->getRawOriginal();
        $this->service->overview(); $this->notifier->overview('provision'); $this->notifier->advance();
        $this->assertSame($before, $this->source->fresh()->getRawOriginal());
        $this->assertFalse(config('machine_failover.allow_replacement')); $this->assertFalse(config('machine_provision.allow_live'));
        $this->assertFalse(config('machine_acceptance.allow_dns')); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_policy_change_during_preflight_invalidates_the_reserved_attempt(): void
    {
        $task = $this->queue(); $this->fake(preflight: fn () => $this->policy->increment('revision'));
        $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('config_changed', $task->reason); $this->assertSame(0, $this->posts);
    }

    public function test_changed_ipv4_context_is_not_reported_as_original_ip_recovery(): void
    {
        $task = $this->queue(); $this->report(true, ip: '8.8.4.4'); $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('replacement_evidence_changed', $task->reason);
        $this->assertNull(Replacement::query()->firstOrFail()->source_recovered_at); Http::assertNothingSent();
    }

    public function test_stale_probe_is_not_a_failure_or_recovery_authorization(): void
    {
        $task = $this->queue(); $this->report(true, $this->clock - 600);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
        $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('replacement_evidence_changed', $task->reason);
        $this->assertNull(Replacement::query()->firstOrFail()->source_recovered_at); Http::assertNothingSent();
    }

    public function test_after_post_source_recovery_retains_the_candidate_instead_of_cancelling_or_cutting_dns(): void
    {
        $task = $this->poll($this->queue()); $this->assertSame(1, $this->posts);
        $this->report(true); $task = $this->poll($task);
        $this->assertSame('waiting_initialization', $task->status); $this->assertNotNull($task->external_id);
        $this->assertNotNull(Replacement::query()->firstOrFail()->source_recovered_at);
        $this->assertSame(1, $this->posts); $this->assertSame(0, DB::table('v2_machine_dns_transition')->count());
    }

    public function test_disabling_execution_after_reservation_stops_the_queued_attempt(): void
    {
        $task = $this->queue(); config(['machine_failover.allow_replacement' => false]); $task = $this->poll($task);
        $this->assertSame('failed', $task->status); $this->assertSame('replacement_disabled', $task->reason); Http::assertNothingSent();
    }

    public function test_budget_is_rechecked_again_before_dispatch(): void
    {
        $task = $this->queue(); $prior = $this->source->replicate();
        $prior->fill(['id' => (string) Str::uuid(), 'request_key' => bin2hex(random_bytes(32)), 'installation_id' => bin2hex(random_bytes(16)),
            'marker' => 'synthetic-concurrent-failure', 'status' => 'failed', 'created_at' => time()])->save();
        $task = $this->poll($task); $this->assertSame('failed', $task->status); $this->assertSame('daily_budget', $task->reason); Http::assertNothingSent();
    }

    public function test_native_accepted_without_dns_and_forwarding_proof_is_not_completion(): void
    {
        $task = $this->queue(); $task->fill(['status' => 'accepted'])->save(); $this->service->sync($task);
        $this->assertSame('needs_review', Replacement::query()->firstOrFail()->status);
        $this->assertFalse(Notice::query()->where('phase', 'completed')->exists());
    }

    public function test_reservation_rolls_back_if_native_task_creation_is_vetoed(): void
    {
        $previous = Task::getEventDispatcher(); Task::setEventDispatcher(new Dispatcher(app())); Task::creating(fn () => false);
        try {
            $this->code('internal_failure', fn () => $this->queue());
            $this->assertSame(0, Replacement::count()); $this->assertSame(1, Task::count());
            $this->assertSame(0, Notice::count()); Http::assertNothingSent();
        } finally { $previous ? Task::setEventDispatcher($previous) : Task::unsetEventDispatcher(); }
    }

    public function test_notification_permission_is_rechecked_before_submission(): void
    {
        $this->enableNotices(); $this->transport(0); $this->queue(); $notice = Notice::query()->firstOrFail();
        $this->admin->fill(['banned' => true])->save(); $this->notifier->deliver($notice->id);
        $this->assertSame('suppressed', $notice->fresh()->status);
    }

    public function test_empty_migration_can_roll_back_but_used_audit_cannot_be_dropped(): void
    {
        $migration = require base_path('database/migrations/2026_11_07_090000_create_machine_replacement_tasks.php');
        $migration->down(); $this->assertFalse(Schema::hasTable('v2_machine_replacement_task'));
        $this->assertSame(1, Task::count()); $migration->up(); $this->queue();
        try { $migration->down(); $this->fail('Expected retained replacement audit'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('audit must be retained', $error->getMessage()); }
        $this->assertSame(1, Replacement::count());
    }

    public function test_transient_preflight_reasons_do_not_spam_the_same_progress_milestone(): void
    {
        $this->enableNotices(); $task = $this->queue();
        foreach (['provider_unavailable', 'provider_rate_limited', 'provider_unavailable'] as $reason) {
            $task->fill(['reason' => $reason])->save(); $this->service->sync($task);
        }
        $this->assertSame(1, Notice::count()); $this->assertSame(4, count(Replacement::query()->firstOrFail()->steps));
    }

    public function test_queue_failure_alert_supersedes_queued_progress_without_creating_another_attempt(): void
    {
        $this->enableNotices(); $this->transport(0); $task = $this->queue(); $old = Notice::query()->firstOrFail();
        $task->fill(['reason' => 'queue_unavailable'])->save(); $this->service->sync($task);
        $this->assertSame('failed', Notice::query()->latest('id')->firstOrFail()->phase);
        $this->notifier->deliver($old->id); $this->assertSame('suppressed', $old->fresh()->status);
        $this->assertSame(1, Replacement::count()); $this->assertSame(0, $this->posts);
    }

    public function test_progress_endpoint_reads_local_journal_without_advancing_or_creating_tasks(): void
    {
        $this->queue(); Bus::fake();
        config(['machine_failover.allow_replacement' => false, 'machine_provision.allow_live' => false]);
        $before = Replacement::query()->firstOrFail()->getRawOriginal();
        $request = Request::create('/cloud/failover/replacements', 'GET'); $request->setUserResolver(fn () => $this->admin);
        $data = app(MachineFailoverController::class)->replacements($request, $this->service)->getData(true)['data'];
        $this->assertTrue($data['available']); $this->assertFalse($data['execution_enabled']); $this->assertFalse($data['dns_cutover']);
        $this->assertCount(1, $data['tasks']); $this->assertSame($before, Replacement::query()->firstOrFail()->getRawOriginal());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_progress_endpoint_requires_an_active_admin(): void
    {
        foreach ([['is_admin' => false, 'banned' => false], ['is_admin' => true, 'banned' => true]] as $changes) {
            $this->admin->fill($changes)->save(); $request = Request::create('/cloud/failover/replacements', 'GET');
            $request->setUserResolver(fn () => $this->admin);
            try { app(MachineFailoverController::class)->replacements($request, $this->service); $this->fail('Expected active admin requirement'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
}
