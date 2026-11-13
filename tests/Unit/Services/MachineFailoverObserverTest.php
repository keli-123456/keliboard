<?php

namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineFailoverController;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverObservation as Observation;
use App\Models\MachineFailoverPlan as Plan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use App\Support\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverObserverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineFailoverObserver $observer;
    private MachineAddressInventory $inventory;
    private ServerMachine $machine;
    private Account $account;
    private Policy $policy;
    private User $admin;
    private object $settings;
    private int $clock;
    private string $installation;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('o', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
        config(['machine_provision' => require base_path('config/machine_provision.php'),
            'machine_failover' => require base_path('config/machine_failover.php')]);
        $this->clock = time(); Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
        $this->settings = new class {
            public array $values = ['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
                'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']];
            public function get(string $key, mixed $default = null): mixed { return $this->values[$key] ?? $default; }
        };
        app()->instance(Setting::class, $this->settings);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_040000_create_machine_failover_observations.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Schema::create('v2_user', function (Blueprint $table) { $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false); $table->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $table) { $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps(); });
        Schema::create('v2_server', function (Blueprint $table) { $table->id(); $table->integer('machine_id'); $table->boolean('enabled'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Independent synthetic', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true]);
        $this->account = Account::query()->create(['name' => 'Synthetic cloud', 'provider' => 'linode', 'credentials' => ['api_token' => 'synthetic-cloud-secret'], 'revision' => 1]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'credentials' => ['api_token' => 'synthetic-dns-secret'], 'revision' => 1]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Independent SG', 'machine_id' => $this->machine->id,
            'account_id' => $this->account->id, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12',
            'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100, 'daily_limit' => 1,
            'dns_credential_id' => $dns->id, 'dns_hostname' => 'new.example.test', 'isolation_confirmed' => true], $this->admin->id);
        $this->policy = Policy::query()->findOrFail($saved['id']);
        $this->inventory = app(MachineAddressInventory::class); $this->observer = app(MachineFailoverObserver::class);
        $this->installation = str_repeat('a', 32);
        config(['machine_failover.observe_enabled' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(); parent::tearDown();
    }

    private function tick(int $seconds = 5): void
    {
        $this->clock += $seconds; Carbon::setTestNow(Carbon::createFromTimestampUTC($this->clock));
    }

    private function report(bool $healthy = false, array $changes = [], ?string $installation = null, string $ip = '8.8.8.8'): void
    {
        $config = app(MachineEntryProbe::class)->configuration();
        $raw = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => $this->clock, 'consecutive_failures' => 1,
            'targets' => array_map(fn ($target, $i) => ['id' => $target['id'], 'status' => $healthy && $i === 0 ? 'reachable' : 'unreachable', 'source_ip' => $ip], $config['targets'], array_keys($config['targets']))];
        $this->inventory->record($this->machine, $installation ?? $this->installation, ['public_ipv4' => $ip], $this->clock, [], array_replace($raw, $changes));
    }

    private function observe(): array { return $this->observer->observe($this->policy->id); }

    private function baseline(): void { $this->report(true); $this->observe(); $this->tick(); }

    private function trigger(): void
    {
        $this->baseline(); $this->report(); $this->observe(); $this->tick(); $this->report(); $this->observe();
    }

    public function test_readonly_ipv4_view_is_green_with_one_reachable_target_and_does_not_collect(): void
    {
        $this->report(true); config(['machine_failover.observe_enabled' => false]);
        $view = $this->observer->history($this->policy->id, $this->policy->revision)['current'];
        $this->assertSame('healthy', $view['probe']['state']); $this->assertSame(['8.8.8.8'], $view['probe']['ipv4']);
        $this->assertSame(['reachable', 'unreachable'], array_column($view['probe']['targets'], 'status'));
        $this->assertNull($view['observed_failures']); $this->assertFalse($view['threshold_reached']);
        $this->assertSame(0, Observation::query()->count()); $this->assertSame(0, Plan::query()->count()); Http::assertNothingSent();
    }

    public function test_all_failed_readonly_view_requires_collected_threshold_and_recovers_on_any_success(): void
    {
        $this->trigger();
        $view = $this->observer->history($this->policy->id, $this->policy->revision)['current'];
        $this->assertSame('failed', $view['probe']['state']); $this->assertSame(2, $view['observed_failures']); $this->assertTrue($view['threshold_reached']);
        $this->tick(); $this->report(true); $this->observe();
        $view = $this->observer->history($this->policy->id, $this->policy->revision)['current'];
        $this->assertSame('healthy', $view['probe']['state']); $this->assertSame(0, $view['observed_failures']); $this->assertFalse($view['threshold_reached']);
        $this->tick(120); $view = $this->observer->history($this->policy->id, $this->policy->revision)['current'];
        $this->assertSame('unknown', $view['probe']['state']); $this->assertSame([], $view['probe']['targets']);
        $this->assertNull($view['observed_failures']); Http::assertNothingSent();
    }

    public function test_default_gate_is_off_and_collect_does_not_read_or_write_samples(): void
    {
        $this->assertFalse((require base_path('config/machine_failover.php'))['observe_enabled']);
        config(['machine_failover.observe_enabled' => false, 'machine_provision.allow_live' => true]);
        DB::enableQueryLog(); $result = $this->observer->collect(); $queries = DB::getQueryLog(); DB::disableQueryLog();
        $this->assertSame(['enabled' => false, 'policies_checked' => 0, 'errors' => 0, 'executed' => false], $result);
        $this->assertSame([], $queries); $this->assertSame(0, Observation::query()->count());
        try { $this->observe(); $this->fail('Must require observation authorization.'); }
        catch (CloudException $error) { $this->assertSame('observation_disabled', $error->getMessage()); }
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_initially_unreachable_source_reaches_threshold_without_a_healthy_baseline(): void
    {
        $this->assertSame('initialization_required', $this->observe()['reason']);
        $this->report(); $first = $this->observe();
        $this->assertSame(1, $first['consecutive_failures']); $this->assertFalse($first['threshold_reached']);
        for ($i = 0; $i < 3; $i++) $this->assertSame($first['id'], $this->observe()['id']);
        $this->assertSame(0, Plan::query()->count());
        for ($i = 0; $i < 3; $i++) { $this->tick(); $this->report(); $last = $this->observe(); }
        $this->assertSame(4, $last['consecutive_failures']); $this->assertNull($last['baseline_at']);
        $this->assertTrue($last['threshold_reached']); $this->assertSame(1, Plan::query()->count());
        $view = $this->observer->summary($this->policy, $this->clock);
        $this->assertSame('ipv4_all_failed', $view['reason']); $this->assertTrue($view['evidence_current']);
        $this->assertTrue($view['threshold_reached']);
        $this->assertSame('blocked', $view['plan']['status']);
        $this->assertContains('execution_disabled', $view['plan']['blockers']);
        $this->tick(); $this->report(true); $this->observe();
        $this->assertSame('recovered', Plan::query()->firstOrFail()->closed_reason);
        $this->assertSame(0, MachineProvisionTask::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_distinct_failed_rounds_make_one_blocked_plan_and_recovery_closes_it(): void
    {
        $this->trigger(); $last = Observation::query()->latest('id')->first(); $plan = Plan::query()->firstOrFail();
        $this->assertSame(2, $last->consecutive_failures); $this->assertTrue($last->threshold_reached);
        $this->assertSame('blocked', $plan->status);
        foreach (['execution_disabled', 'pricing_unverified', 'external_ownership_unverified', 'forwarding_not_verified'] as $code) $this->assertContains($code, $plan->blockers);
        $this->tick(); $this->report(); $this->observe(); $this->assertSame(1, Plan::query()->count());
        $this->tick(); $this->report(true); $recovered = $this->observe();
        $this->assertSame(0, $recovered['consecutive_failures']); $this->assertFalse($recovered['threshold_reached']);
        $this->assertSame('superseded', $plan->fresh()->status); $this->assertSame('recovered', $plan->fresh()->closed_reason);
        $this->assertSame(0, DB::table('v2_machine_provision_task')->count());
        $this->assertSame(0, DB::table('v2_machine_cloud_task')->count()); $this->assertSame(0, DB::table('v2_machine_dns_log')->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_repeated_old_or_too_fast_samples_do_not_increment_or_recover(): void
    {
        $this->baseline(); $this->report(); $first = $this->observe();
        for ($i = 0; $i < 3; $i++) $this->assertSame($first['id'], $this->observe()['id']);
        $this->tick(1); $this->report(); $this->assertSame($first['id'], $this->observe()['id']);
        $this->tick(); $this->report(true, ['checked_at' => $first['checked_at'] - 1]);
        $this->assertSame($first['id'], $this->observe()['id']); $this->assertSame(0, Plan::query()->count());
    }

    public function test_gap_unknown_and_expired_reports_break_a_failure_streak(): void
    {
        $this->baseline(); $this->report(); $this->observe(); $this->tick(21); $this->report();
        $this->assertSame(1, $this->observe()['consecutive_failures']);
        $this->tick(); $this->report(false, ['targets' => []]);
        $this->assertSame(0, $this->observe()['consecutive_failures']);
        $this->tick(); $this->report(); $this->assertSame(1, $this->observe()['consecutive_failures']);
        $this->tick(41); $stale = $this->observe();
        $this->assertSame('probe_stale', $stale['reason']); $this->assertSame(0, $stale['consecutive_failures']);
        $this->assertSame(0, Plan::query()->count());
    }

    public static function uncertainSources(): array { return [['legacy'], ['foreign'], ['ipv6'], ['multiple'], ['future'], ['disabled'], ['error'], ['unknown'], ['partial']]; }

    #[DataProvider('uncertainSources')]
    public function test_uncertain_identity_or_probe_sources_cannot_establish_failures(string $case): void
    {
        $this->report(); $this->observe(); $this->tick();
        $config = app(MachineEntryProbe::class)->configuration();
        $targets = array_map(fn ($target) => ['id' => $target['id'], 'status' => 'unreachable'], $config['targets']);
        if ($case === 'foreign') $targets = array_map(fn ($row) => $row + ['source_ip' => '8.8.4.4'], $targets);
        if (in_array($case, ['legacy', 'foreign'], true)) $this->report(false, ['targets' => $targets]);
        if ($case === 'future') $this->report(false, ['checked_at' => $this->clock + 1]);
        if ($case === 'multiple') { $this->report(); $this->report(true, [], str_repeat('b', 32), '8.8.4.4'); }
        if ($case === 'ipv6') {
            Cache::forget('machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token));
            $this->inventory->record($this->machine, $this->installation, ['public_ipv6' => '2606:4700:4700::1111'], $this->clock);
        }
        if ($case === 'disabled') $this->settings->values['server_entry_probe_enable'] = false;
        if (in_array($case, ['error', 'unknown', 'partial'], true)) {
            $targets = array_map(fn ($row) => $row + ['source_ip' => '8.8.8.8'], $targets);
            if ($case === 'partial') array_pop($targets);
            else $targets[0]['status'] = $case;
            $this->report(false, ['targets' => $targets]);
        }
        $row = $this->observe(); $this->assertSame('unknown', $row['state']);
        $this->assertSame(0, $row['consecutive_failures']); $this->assertFalse($row['threshold_reached']);
        $this->assertSame(0, Plan::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public static function contextChanges(): array { return [['ip'], ['installation'], ['targets'], ['revision'], ['token']]; }

    #[DataProvider('contextChanges')]
    public function test_changed_context_cannot_borrow_a_baseline_or_streak(string $kind): void
    {
        $this->baseline(); $this->report(); $this->observe(); $this->tick();
        if ($kind === 'ip') $this->report(false, [], null, '8.8.4.4');
        if ($kind === 'installation') {
            $this->tick(301); $this->report(false, [], str_repeat('b', 32));
        }
        if ($kind === 'targets') { $this->settings->values['server_entry_probe_targets'] = ['1.0.0.1']; $this->report(); }
        if ($kind === 'revision') { $this->policy->revision++; $this->policy->save(); $this->report(); }
        if ($kind === 'token') { $this->machine->token = 'rotated-synthetic'; $this->machine->save(); $this->report(); }
        $row = $this->observe();
        $this->assertNull($row['baseline_at']); $this->assertFalse($row['threshold_reached']); $this->assertSame(0, Plan::query()->count());
    }

    public function test_stale_agent_is_not_repeatedly_counted_as_failed_ping_rounds(): void
    {
        $this->baseline(); $this->report(); $this->observe(); $this->tick(301);
        $row = $this->observe(); $this->assertSame('agent_stale', $row['reason']);
        $this->assertSame(0, $row['consecutive_failures']); $count = Observation::query()->count();
        $this->tick(30); $this->observe(); $this->assertSame($count, Observation::query()->count());
        $this->assertSame(0, Plan::query()->count());
    }

    private function attempt(string $status = 'failed', int $age = 60): void
    {
        MachineProvisionTask::query()->create(['id' => '00000000-0000-4000-8000-000000000001', 'request_key' => str_repeat('r', 64),
            'admin_id' => $this->admin->id, 'account_id' => $this->account->id, 'account_revision' => 1, 'machine_id' => 999,
            'machine_authority' => str_repeat('b', 64), 'plan_hash' => str_repeat('c', 64), 'slot' => 'different-policy-slot',
            'installation_id' => str_repeat('d', 32), 'callback_hash' => str_repeat('e', 64), 'marker' => 'synthetic-marker',
            'payload' => ['synthetic' => true], 'status' => $status, 'created_at' => $this->clock - $age,
            'updated_at' => $this->clock - $age, 'dispatched_at' => $this->clock - $age, 'deadline_at' => $this->clock + 120]);
    }

    public function test_daily_limit_and_cooldown_include_other_policies_on_the_same_account(): void
    {
        $this->attempt('needs_review'); $this->trigger(); $plan = Plan::query()->firstOrFail();
        foreach (['daily_budget_reached', 'active_creation_task', 'cooldown_active'] as $code) $this->assertContains($code, $plan->blockers);
        $this->assertSame(1, $plan->evidence['account_attempts_24h']);
        $this->assertSame(1, MachineProvisionTask::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_expired_attempts_do_not_consume_the_current_rolling_day_or_cooldown(): void
    {
        $this->attempt('failed', 86401); $this->trigger(); $plan = Plan::query()->firstOrFail();
        $this->assertSame(0, $plan->evidence['account_attempts_24h']);
        $this->assertNotContains('daily_budget_reached', $plan->blockers); $this->assertNotContains('cooldown_active', $plan->blockers);
    }

    public function test_missing_budget_history_and_changed_authority_remain_blocked(): void
    {
        Schema::drop('v2_machine_provision_task'); $this->account->revision++; $this->account->save();
        $this->admin->banned = true; $this->admin->save(); $this->trigger();
        $plan = Plan::query()->firstOrFail();
        foreach (['budget_history_unavailable', 'account_changed', 'permission_revoked'] as $code) $this->assertContains($code, $plan->blockers);
        $this->assertNull($plan->evidence['account_attempts_24h']);
    }

    public function test_history_and_current_rehearsal_are_read_only_and_do_not_reuse_old_evidence(): void
    {
        $this->trigger(); DB::enableQueryLog();
        $history = $this->observer->history($this->policy->id, 1);
        $current = app(MachineFailoverPolicyService::class)->rehearse($this->policy->id, 1, 'current');
        $this->assertSame('threshold_reached', $current['trigger']['state']);
        $this->assertSame(2, $current['trigger']['observed_failures']);
        $this->assertContains(['code' => 'pricing_unverified', 'status' => 'blocked'], $current['checks']);
        $simulated = app(MachineFailoverPolicyService::class)->rehearse($this->policy->id, 1, 'ipv4_unreachable');
        $this->assertNull($simulated['trigger']['observed_failures']);
        $this->assertTrue($history['current']['threshold_reached']); $this->assertSame(2, $history['current']['observed_failures']);
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query['query']);
        foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', 'machine_authority', 'callback_hash', 'root_pass', 'payload'] as $secret) $this->assertStringNotContainsString($secret, json_encode($history));
        $this->assertFalse($history['plans'][0]['executed']); $this->assertTrue($history['plans'][0]['requires_new_authorization']);
        $this->tick(); $this->report(true);
        $this->assertNull($this->observer->history($this->policy->id, 1)['current']['observed_failures']);
        $this->assertSame(2, Observation::query()->latest('id')->first()->consecutive_failures);
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_changed_guards_create_a_new_audit_snapshot_not_another_request(): void
    {
        $this->trigger(); $original = Plan::query()->firstOrFail();
        $this->attempt('needs_review'); $this->tick(); $this->report(); $this->observe();
        $next = Plan::query()->latest('id')->first();
        $this->assertSame(2, Plan::query()->count()); $this->assertSame('superseded', $original->fresh()->status);
        $this->assertSame('guards_changed', $original->fresh()->closed_reason);
        $this->assertContains('active_creation_task', $next->blockers);
        $this->assertSame('blocked', $next->status); $this->assertSame(1, MachineProvisionTask::query()->count());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_observation_writes_do_not_mutate_old_resources_or_credentials(): void
    {
        $old = ServerMachine::query()->create(['name' => 'Old synthetic', 'token' => 'old-synthetic', 'is_active' => true]);
        $before = [$old->fresh()->getRawOriginal(), $this->machine->fresh()->getRawOriginal(), $this->account->fresh()->getRawOriginal(),
            MachineDnsCredential::query()->first()->getRawOriginal(), $this->policy->getRawOriginal()];
        $this->trigger();
        $this->assertSame($before, [$old->fresh()->getRawOriginal(), $this->machine->fresh()->getRawOriginal(), $this->account->fresh()->getRawOriginal(),
            MachineDnsCredential::query()->first()->getRawOriginal(), $this->policy->fresh()->getRawOriginal()]);
        $this->assertSame(0, DB::table('v2_machine_cloud_resource')->count()); $this->assertSame(0, DB::table('v2_machine_dns_binding')->count());
    }

    public function test_old_cached_rounds_cannot_replay_after_newer_evidence(): void
    {
        $this->baseline(); $this->report(); $last = $this->observe();
        $key = 'machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token);
        $rows = Cache::get($key); $rows[$this->installation]['entry_probe']['checked_at'] -= 5;
        foreach ($rows[$this->installation]['entry_probe']['targets'] as &$target) $target['status'] = 'reachable';
        unset($target); Cache::put($key, $rows, 86400);
        $this->assertSame($last['id'], $this->observe()['id']);
        $this->assertFalse($this->observer->history($this->policy->id, 1)['current']['evidence_current']);
        $this->assertSame(0, Plan::query()->count());
    }

    public function test_one_reachable_target_resets_the_streak_even_if_another_target_errors(): void
    {
        $this->trigger(); $this->tick();
        $config = app(MachineEntryProbe::class)->configuration();
        $targets = array_map(fn ($target, $i) => ['id' => $target['id'], 'status' => $i === 0 ? 'reachable' : 'error', 'source_ip' => '8.8.8.8'], $config['targets'], array_keys($config['targets']));
        $this->report(false, ['targets' => $targets]);
        $row = $this->observe(); $this->assertSame('healthy', $row['state']); $this->assertSame(0, $row['consecutive_failures']);
        $this->assertSame('recovered', Plan::query()->first()->closed_reason);
    }

    public function test_every_known_ipv4_source_needs_complete_failure_evidence(): void
    {
        $this->report(true);
        $key = 'machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token);
        $rows = Cache::get($key); $rows[$this->installation]['ips']['8.8.4.4'] = ['ip' => '8.8.4.4', 'observed' => false];
        Cache::put($key, $rows, 86400); $this->observe(); $this->tick(); $this->report();
        $rows = Cache::get($key); $rows[$this->installation]['ips']['8.8.4.4'] = ['ip' => '8.8.4.4', 'observed' => false];
        Cache::put($key, $rows, 86400);
        $row = $this->observe(); $this->assertSame('unknown', $row['state']); $this->assertSame('source_unverified', $row['reason']);
        $this->assertSame(0, $row['consecutive_failures']); $this->assertSame(0, Plan::query()->count());
    }

    public function test_unresolved_cloud_tasks_block_a_plan_without_mutating_the_task(): void
    {
        $resource = \App\Models\MachineCloudResource::query()->create(['account_id' => $this->account->id, 'provider' => 'linode', 'external_id' => '123',
            'label' => 'Old cloud', 'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'provider_created_at' => '2026-01-01T00:00:00',
            'status' => 'running', 'public_ips' => ['8.8.4.4'], 'observed_at' => $this->clock]);
        $task = \App\Models\MachineCloudTask::query()->create(['resource_id' => $resource->id, 'resource_revision' => 1, 'account_revision' => 1,
            'provider' => 'linode', 'external_id' => '123', 'resource_label' => 'Old cloud', 'admin_id' => $this->admin->id, 'action' => 'reboot', 'automatic' => false,
            'request_key' => str_repeat('z', 64), 'status' => 'queued', 'deadline_at' => $this->clock + 900]);
        $before = $task->fresh()->getRawOriginal(); $this->trigger();
        $this->assertContains('active_cloud_task', Plan::query()->first()->blockers);
        $this->assertSame($before, $task->fresh()->getRawOriginal()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_other_live_gates_do_not_grant_execution_authority_to_observation(): void
    {
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => [$this->machine->id]]);
        $this->trigger(); $history = $this->observer->history($this->policy->id, 1);
        $this->assertFalse($history['execution_enabled']); $this->assertFalse($history['plans'][0]['executed']);
        $this->assertFalse($history['plans'][0]['execution_enabled']);
        $this->assertContains('execution_disabled', $history['plans'][0]['blockers']);
        $this->assertSame(0, MachineProvisionTask::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_the_shared_write_mutex_blocks_collection_without_duplicate_rows(): void
    {
        Carbon::setTestNow();
        $this->report(true); $lock = Cache::lock('machine-failover:write', 30); $this->assertTrue($lock->get());
        try {
            try { $this->observe(); $this->fail('Expected collection mutex.'); }
            catch (\Illuminate\Contracts\Cache\LockTimeoutException) { $this->assertSame(0, Observation::query()->count()); }
        } finally { $lock->release(); }
        $this->observe(); $this->observe(); $this->assertSame(1, Observation::query()->count());
    }

    public function test_collection_errors_are_not_reported_as_a_successful_empty_sample(): void
    {
        $inventory = $this->createMock(MachineAddressInventory::class);
        $inventory->method('snapshots')->willThrowException(new \RuntimeException('synthetic-private-error'));
        $observer = new MachineFailoverObserver($inventory, app(MachineEntryProbe::class));
        $result = $observer->collect();
        $this->assertSame(['enabled' => true, 'policies_checked' => 0, 'errors' => 1, 'executed' => false], $result);
        $this->assertSame(0, Observation::query()->count()); $this->assertStringNotContainsString('synthetic-private-error', json_encode($result));
    }

    public function test_strict_inventory_reads_preserve_cache_failures_without_changing_the_presentation_fallback(): void
    {
        Cache::partialMock()->shouldReceive('many')->andThrow(new \RuntimeException('synthetic-cache-failure'));
        $this->assertSame([], $this->inventory->snapshots([$this->machine]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('synthetic-cache-failure');
        $this->inventory->snapshots([$this->machine], true);
    }

    public function test_cli_and_schedule_gate_cannot_start_provisioning(): void
    {
        app()->instance(\Illuminate\Console\Scheduling\EventMutex::class, $this->createMock(\Illuminate\Console\Scheduling\EventMutex::class));
        app()->instance(\Illuminate\Console\Scheduling\SchedulingMutex::class, $this->createMock(\Illuminate\Console\Scheduling\SchedulingMutex::class));
        $backup = $this->createMock(\App\Services\Backup\BackupService::class); $backup->method('settings')->willReturn(['enabled' => false]);
        app()->instance(\App\Services\Backup\BackupService::class, $backup);
        app()->instance(\App\Services\Plugin\PluginManager::class, $this->createMock(\App\Services\Plugin\PluginManager::class));
        foreach ([false, true] as $enabled) {
            config(['machine_failover.observe_enabled' => $enabled]);
            $schedule = new \Illuminate\Console\Scheduling\Schedule();
            $kernel = new \App\Console\Kernel(app(), $this->createMock(\Illuminate\Contracts\Events\Dispatcher::class));
            (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);
            $events = array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'machines:observe-failover')));
            $this->assertCount($enabled ? 1 : 0, $events);
            if ($enabled) { $this->assertSame(5, $events[0]->repeatSeconds); $this->assertTrue($events[0]->withoutOverlapping); $this->assertTrue($events[0]->onOneServer); }
            $this->assertSame([], array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'advance-provisioning'))));
        }
        $command = new \App\Console\Commands\ObserveMachineFailover(); $command->setLaravel(app());
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $this->assertSame(0, $command->run(new \Symfony\Component\Console\Input\ArrayInput([]), $output));
        $this->assertFalse(json_decode($output->fetch(), true)['executed']);
        $this->assertSame(0, MachineProvisionTask::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_deleted_policy_audit_is_retained_then_boundedly_cleaned(): void
    {
        $this->trigger(); $this->policy->delete(); $this->observer->collect();
        $this->assertSame(3, Observation::query()->count()); $this->assertSame(1, Plan::query()->count());
        $this->tick(86400 * 8); $this->observer->collect();
        $this->assertSame(0, Observation::query()->count()); $this->assertSame(0, Plan::query()->count());
        $this->assertSame(1, Account::query()->count());
    }

    public function test_history_requires_admin_before_validation_and_stale_revision_is_rejected(): void
    {
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request = Request::create('/synthetic', 'GET'); $request->setUserResolver(fn () => $actor);
            try { (new MachineFailoverController())->history($request, $this->observer); $this->fail('Admin required.'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        try { $this->observer->history($this->policy->id, 2); $this->fail('Revision required.'); }
        catch (CloudException $error) { $this->assertSame('config_changed', $error->getMessage()); }
    }

    public function test_collection_is_bounded_and_retention_keeps_latest_evidence(): void
    {
        config(['machine_failover.sample_history_limit' => 50]);
        for ($i = 0; $i < 55; $i++) { $this->report(true); $this->observe(); $this->tick(); }
        $this->assertSame(50, Observation::query()->count());
        $this->assertSame('healthy', Observation::query()->latest('id')->first()->state);
        $this->assertSame(['enabled' => true, 'policies_checked' => 1, 'errors' => 0, 'executed' => false], $this->observer->collect());
        $this->assertSame(0, Plan::query()->count());
    }

    public function test_schema_rollback_does_not_touch_policies_cloud_resources_or_dns(): void
    {
        (require base_path('database/migrations/2026_11_06_040000_create_machine_failover_observations.php'))->down();
        $this->assertFalse($this->observer->available()); $this->assertFalse($this->observer->history($this->policy->id, 1)['available']);
        foreach (['v2_machine_failover_policy', 'v2_machine_cloud_resource', 'v2_machine_dns_binding'] as $table) $this->assertTrue(Schema::hasTable($table));
    }
}
