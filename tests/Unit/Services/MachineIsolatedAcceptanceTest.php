<?php
namespace Tests\Unit\Services;

use App\Http\Controllers\V2\Admin\Server\MachineIsolatedAcceptanceController;
use App\Models\MachineCloudAccount;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineInstanceAcceptance;
use App\Services\ServerMachine\Cloud\MachineIsolatedAcceptance;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
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

class MachineIsolatedAcceptanceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineIsolatedAcceptance $service;
    private MachineFailoverPolicy $policy;
    private ServerMachineRelease $release;
    private User $admin;
    private ServerMachine $machine;
    private int $creates = 0;
    private bool $missingUdp = false;
    private bool $changeDuringPreflight = false;
    private bool $ambiguousCreate = false;
    private bool $changeGroupDuringPreflight = false;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
        config(['app.key' => 'base64:' . base64_encode(str_repeat('a', 32)),
            'machine_provision' => require base_path('config/machine_provision.php'),
            'machine_acceptance' => require base_path('config/machine_acceptance.php'),
            'machine_failover' => require base_path('config/machine_failover.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php', '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); $t->string('type'); $t->integer('port'); $t->integer('server_port'); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Isolated synthetic', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true, 'type' => 'hysteria', 'port' => 8443, 'server_port' => 8443]);
        $account = MachineCloudAccount::query()->create(['name' => 'Synthetic', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-cloud-secret']]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Independent', 'account_id' => $account->id, 'machine_id' => $this->machine->id,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 2, 'stale_after_seconds' => 300,
            'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100, 'daily_limit' => 1, 'dns_credential_id' => null,
            'dns_hostname' => '', 'isolation_confirmed' => true], $this->admin->id);
        $this->policy = MachineFailoverPolicy::query()->findOrFail($saved['id']);
        $this->release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'v0.1.386', 'platform' => 'linux-x86_64',
            'manifest_path' => 'synthetic/manifest', 'archive_path' => 'synthetic/release', 'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $this->service = app(MachineIsolatedAcceptance::class);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.linode.com/')) {
                if ($request->method() === 'POST') {
                    $this->assertSame('https://api.linode.com/v4/linode/instances', $request->url()); $this->creates++;
                    if ($this->ambiguousCreate) return Http::response([], 503);
                    return Http::response($this->remote(Task::query()->firstOrFail()));
                }
                $this->assertSame('GET', $request->method());
                if (str_contains($request->url(), '/regions/')) return Http::response(['id' => 'sg-sin-2', 'capabilities' => ['Metadata'], 'status' => 'ok']);
                if (str_contains($request->url(), '/images/')) return Http::response(['id' => 'linode/debian12', 'capabilities' => ['cloud-init'], 'status' => 'available', 'deprecated' => false]);
                if (str_contains($request->url(), '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => 'standard', 'region_prices' => [], 'price' => ['monthly' => 48]]);
                if (str_contains($request->url(), '/account/settings')) {
                    if ($this->changeDuringPreflight) $this->release->fill(['binary_sha256' => str_repeat('d', 64)])->save();
                    if ($this->changeGroupDuringPreflight) MachineCloudAccount::query()->where('id', '!=', Task::query()->firstOrFail()->account_id)->update(['group_revision' => 2]);
                    return Http::response(['backups_enabled' => false]);
                }
                $remote = $this->remote(Task::query()->firstOrFail());
                return Http::response(str_contains($request->url(), '/instances/987') ? $remote : ['data' => [$remote], 'pages' => 1]);
            }
            $this->assertSame('https://verifier.example.test/v1/verify', $request->url());
            $challenge = json_decode($request->body(), true);
            $sample = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'bytes' => 96, 'sha256' => $challenge['echo_sha256']];
            $reply = ['schema' => 1, 'challenge_id' => $challenge['challenge_id'], 'request_sha256' => hash('sha256', $request->body()),
                'cleanup_complete' => true, 'finished_at' => time(), 'results' => [['node_id' => 10, 'endpoint_authenticated' => true, 'tcp' => $sample, 'udp' => $sample]]];
            if ($this->missingUdp) $reply['results'][0]['udp']['missing'] = 1;
            $raw = json_encode($reply);
            return Http::response($raw, 200, ['X-Keli-Signature' => hash_hmac('sha256', "response\n" . $raw, str_repeat('k', 32))]);
        });
    }

    private function enable(): void
    {
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => [$this->machine->id],
            'machine_acceptance.allow_verification' => true, 'machine_acceptance.allowed_policy_ids' => [$this->policy->id],
            'machine_acceptance.verifier_url' => 'https://verifier.example.test/v1/verify', 'machine_acceptance.verifier_key' => str_repeat('k', 32),
            'machine_acceptance.verifier_profiles' => [$this->policy->id => 'synthetic-profile']]);
    }

    private function preview(): array { return $this->service->preview($this->policy->id, $this->policy->revision, $this->release->id, $this->admin->id); }
    private function input(array $check): array
    {
        return array_intersect_key($check, array_flip(['policy_id', 'policy_revision', 'fingerprint', 'expires_at', 'token']))
            + ['release_id' => $this->release->id, 'request_id' => str_repeat('a', 32)];
    }
    private function task(): Task
    {
        $this->enable(); $result = $this->service->queue($this->input($this->preview()), $this->admin->id);
        return Task::query()->findOrFail($result['task']['id']);
    }
    private function remote(Task $task): array
    {
        $request = $task->payload['request'];
        return ['id' => 987, 'label' => $request['label'], 'tags' => $request['tags'], 'type' => $request['type'], 'region' => $request['region'],
            'image' => $request['image'], 'created' => $task->provider_created_at ?? gmdate('Y-m-d\TH:i:s', $task->dispatched_at ?? time()), 'status' => 'running', 'ipv4' => ['8.8.8.8'], 'ipv6' => ''];
    }
    private function ready(Task $task): MachineOwnedInstance
    {
        $provision = app(MachineProvisionService::class); $provision->process($task->id);
        $task->refresh()->fill(['dispatched_at' => time() - 3, 'bootstrap_state' => 'succeeded', 'next_check_at' => 0])->save();
        $config = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => time(),
            'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => $target['ip'] === '1.1.1.1' ? 'reachable' : 'unreachable', 'source_ip' => '8.8.8.8'], $config['targets'])];
        app(MachineAddressInventory::class)->record($this->machine, $task->installation_id, ['public_ipv4' => '8.8.8.8'], time(),
            ['version' => $this->release->version, 'binary_sha256' => $this->release->binary_sha256, 'observation_session' => str_repeat('e', 32),
                'configured_nodes' => [['id' => 10, 'protocol' => 'hysteria2', 'port' => 8443]]], $probe);
        Cache::put('node_instances:10', [['machine_id' => $this->machine->id, 'installation_id' => $task->installation_id, 'last_seen_at' => time(), 'last_status_at' => time()]], 300);
        $provision->process($task->id);
        $this->assertSame('awaiting_acceptance', $task->fresh()->status, (string) $task->fresh()->reason);
        $enrolled = app(MachineInstanceAcceptance::class)->enroll($this->policy->id, $this->policy->revision, $task->id, $this->release->id, '8.8.8.8', $this->admin->id);
        return MachineOwnedInstance::query()->findOrFail($enrolled['id']);
    }
    private function report(?Task $task = null): array { return $this->service->report($this->policy->id, $this->policy->revision, $task?->id, null, $this->admin->id); }
    private function useGroup(): void
    {
        foreach (['2026_11_06_020000_create_machine_cloud_groups.php', '2026_11_07_120000_add_provider_groups_to_machine_failover.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        (new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns'))->setValue(null, []);
        $group = app(\App\Services\ServerMachine\Cloud\MachineCloudGroupService::class)->save(['name' => 'Synthetic isolated pool']);
        $account = MachineCloudAccount::query()->findOrFail($this->policy->account_id);
        $account->fill(['group_id' => $group->id, 'group_revision' => 1])->save();
        MachineCloudAccount::query()->create(['name' => 'Other synthetic token', 'provider' => 'linode', 'revision' => 1,
            'group_id' => $group->id, 'group_revision' => 1, 'credentials' => ['api_token' => 'different-synthetic-secret']]);
        $pool = app(\App\Services\ServerMachine\Cloud\MachineFailoverCredentials::class)->pool($group->id, 'linode');
        $catalog = ['fingerprint' => str_repeat('f', 64), 'expires_at' => time() + 600, 'checked_at' => time(),
            'regions' => [['id' => 'sg-sin-2']], 'sizes' => [['id' => 'g6-standard-4', 'regions' => ['sg-sin-2'], 'disk_gib' => 160]],
            'images' => [['id' => 'linode/debian12', 'regions' => ['sg-sin-2'], 'min_disk_gib' => 0]]];
        Cache::put('machine-cloud:catalog:' . $pool['fingerprint'], $catalog, 600);
        $this->policy->fill(['account_id' => null, 'account_revision' => null, 'provider' => 'linode',
            'credential_group_id' => $group->id, 'catalog_fingerprint' => $catalog['fingerprint'], 'catalog_checked_at' => time()])->save();
    }
    private function code(string $reason, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $reason); }
        catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }

    public function test_local_precheck_needs_no_dns_and_cannot_enable_anything(): void
    {
        $check = $this->preview(); $this->assertFalse($check['creation_allowed']);
        $this->assertNotContains('dns_not_configured', array_column($check['checks'], 'code'));
        $this->code('isolated_creation_disabled', fn () => $this->service->queue($this->input($check), $this->admin->id));
        $this->assertSame('not_started', $this->report()['verdict']);
        $this->assertSame(0, Task::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
        $this->assertFalse(config('machine_failover.allow_replacement')); $this->assertFalse(config('machine_acceptance.allow_dns'));
    }

    public function test_one_explicit_queue_is_pinned_atomic_and_idempotent_even_after_gates_close(): void
    {
        $this->enable(); $input = $this->input($this->preview()); $first = $this->service->queue($input, $this->admin->id);
        config(['machine_provision.allow_live' => false]);
        $second = $this->service->queue($input, $this->admin->id);
        $this->assertSame($first['task']['id'], $second['task']['id']); $this->assertSame(1, Task::query()->count());
        $this->assertSame($this->release->binary_sha256, $first['task']['release']['binary_sha256']);
        $this->assertSame(1, count(Bus::dispatched(\App\Jobs\MachineProvisionJob::class))); Http::assertNothingSent();
        $raw = DB::table('v2_machine_provision_task')->value('payload'); $this->assertStringNotContainsString('synthetic-machine-secret', $raw);
    }

    #[DataProvider('changedContexts')]
    public function test_precheck_changes_refuse_before_queue(string $change): void
    {
        $this->enable(); $input = $this->input($this->preview());
        match ($change) {
            'node' => DB::table('v2_server')->where('id', 10)->update(['port' => 9443]),
            'release' => $this->release->fill(['binary_sha256' => str_repeat('c', 64)])->save(),
            'account' => MachineCloudAccount::query()->whereKey($this->policy->account_id)->update(['revision' => 2]),
            'gate' => config(['machine_provision.allow_live' => false]),
            'verifier' => config(['machine_acceptance.verifier_key' => str_repeat('n', 32)]),
            'verification_gate' => config(['machine_acceptance.allow_verification' => false]),
            'scope' => config(['machine_acceptance.allow_dns' => true]),
        };
        $this->code('precheck_changed', fn () => $this->service->queue($input, $this->admin->id));
        $this->assertSame(0, Task::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
    public static function changedContexts(): array { return array_map(fn ($v) => [$v], ['node', 'release', 'account', 'gate', 'verifier', 'verification_gate', 'scope']); }

    public function test_precheck_expiry_and_cross_actor_token_are_not_authority(): void
    {
        $this->enable(); $input = $this->input($this->preview());
        $this->code('precheck_expired', fn () => $this->service->queue(array_replace($input, ['expires_at' => time() - 1]), $this->admin->id));
        $other = User::query()->create(['is_admin' => true]);
        $this->code('precheck_expired', fn () => $this->service->queue($input, $other->id));
        $this->assertSame(0, Task::query()->count()); Http::assertNothingSent();
    }

    public function test_post_queue_context_changes_stop_before_create_and_another_intent_cannot_retry(): void
    {
        $task = $this->task(); DB::table('v2_server')->where('id', 10)->update(['server_port' => 9443]);
        app(MachineProvisionService::class)->process($task->id);
        $this->assertSame('failed', $task->fresh()->status); $this->assertSame('isolated_context_changed', $task->fresh()->reason);
        $check = $this->preview(); $this->assertFalse($check['creation_allowed']);
        $this->code('isolated_creation_disabled', fn () => $this->service->queue(array_replace($this->input($check), ['request_id' => str_repeat('b', 32)]), $this->admin->id));
        $this->assertSame(1, Task::query()->count()); Http::assertNothingSent();
    }

    public function test_a_pin_change_during_provider_preflight_stops_before_the_create_post(): void
    {
        $task = $this->task(); $this->changeDuringPreflight = true;
        app(MachineProvisionService::class)->process($task->id);
        $this->assertSame('failed', $task->fresh()->status); $this->assertSame('isolated_context_changed', $task->fresh()->reason);
        $this->assertSame(0, $this->creates); $this->assertNull($task->fresh()->dispatched_at);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_simulated_full_flow_stops_at_readiness_without_dns_activation_or_vm_deletion(): void
    {
        $task = $this->task(); $instance = $this->ready($task);
        app(MachineInstanceAcceptance::class)->verify($instance->id, $this->policy->revision, $instance->revision, $this->admin->id);
        $report = $this->report($task);
        $this->assertSame('passed', $report['verdict']); $this->assertTrue($report['evidence_current']);
        $this->assertTrue($report['temporary_client_cleanup']); $this->assertFalse($report['fixture_revocation_verified']);
        $this->assertFalse($report['dns_cutover']); $this->assertSame('keep', $report['delete_strategy']);
        $this->assertSame(1, $this->creates); $this->assertNull($instance->fresh()->active_policy_id);
        $this->assertSame(0, DB::table('v2_machine_dns_transition')->count()); $this->assertSame('awaiting_acceptance', $task->fresh()->status);
        $json = json_encode($report); foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'root_pass', 'callback_hash', 'metadata', 'challenge_id'] as $secret) $this->assertStringNotContainsString($secret, $json);
        $before = Http::recorded()->count(); $this->report($task); $this->assertSame($before, Http::recorded()->count());
    }

    public function test_group_flow_pins_one_account_through_create_reports_and_verification(): void
    {
        $this->useGroup(); $task = $this->task();
        $this->assertSame(1, $task->account_id); $this->assertNull($this->policy->account_id);
        $this->assertSame($this->policy->credential_group_id, $task->payload['plan']['credential_pool']['group_id']);
        $instance = $this->ready($task);
        app(MachineInstanceAcceptance::class)->verify($instance->id, $this->policy->revision, $instance->revision, $this->admin->id);
        $this->assertSame('passed', $this->report($task)['verdict']); $this->assertSame(1, $this->creates);
        $this->assertSame(1, Task::query()->count()); $this->assertSame(0, DB::table('v2_machine_dns_transition')->count());
        $otherPolicy = $this->policy->replicate(); $otherPolicy->credential_group_id = $this->policy->credential_group_id + 1;
        $this->assertFalse(app(\App\Services\ServerMachine\Cloud\MachineFailoverCredentials::class)->owns($otherPolicy, $task));
    }

    public function test_group_change_during_preflight_prevents_paid_creation_without_token_fallback(): void
    {
        $this->useGroup(); $task = $this->task(); $this->changeGroupDuringPreflight = true;
        app(MachineProvisionService::class)->process($task->id);
        $this->assertSame('failed', $task->fresh()->status); $this->assertSame('credential_group_changed', $task->fresh()->reason);
        $this->assertNull($task->fresh()->dispatched_at); $this->assertSame(0, $this->creates);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_uncertain_group_create_only_reconciles_original_account_after_membership_changes(): void
    {
        $this->useGroup(); $task = $this->task(); $this->ambiguousCreate = true;
        $provision = app(MachineProvisionService::class); $provision->process($task->id);
        $this->assertSame('outcome_unknown', $task->fresh()->status);
        MachineCloudAccount::query()->whereKey($task->account_id)->update(['group_id' => null, 'group_revision' => 2]);
        $task->refresh()->fill(['next_check_at' => 0])->save(); $provision->process($task->id);
        $this->assertSame('waiting_initialization', $task->fresh()->status); $this->assertSame(1, $this->creates);
        $this->assertSame(1, Task::query()->count()); $this->assertSame(1, $task->fresh()->account_id);
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer different-synthetic-secret'));
    }

    public function test_wrong_enrollment_release_and_same_version_different_binary_are_refused(): void
    {
        $task = $this->task(); $instance = $this->ready($task);
        $other = $this->release->replicate(); $other->version = 'v0.1.387'; $other->binary_sha256 = str_repeat('d', 64); $other->save();
        $this->code('isolated_release_mismatch', fn () => app(MachineInstanceAcceptance::class)->enroll($this->policy->id, $this->policy->revision, $task->id, $other->id, '8.8.8.8', $this->admin->id));
        $this->release->fill(['binary_sha256' => str_repeat('c', 64)])->save();
        $this->assertSame('blocked', $this->report($task)['verdict']); $this->assertSame('isolated_context_changed', $this->report($task)['reason']);
    }

    public function test_missing_udp_never_passes_or_changes_dns(): void
    {
        $task = $this->task(); $instance = $this->ready($task); $this->missingUdp = true;
        app(MachineInstanceAcceptance::class)->verify($instance->id, $this->policy->revision, $instance->revision, $this->admin->id);
        $this->assertSame('blocked', $this->report($task)['verdict']); $this->assertFalse($this->report($task)['temporary_client_cleanup']);
        $this->assertSame(0, DB::table('v2_machine_dns_transition')->count()); $this->assertSame(1, $this->creates);
    }

    public function test_expired_or_changed_reports_cannot_reuse_a_previous_pass(): void
    {
        $task = $this->task(); $instance = $this->ready($task);
        app(MachineInstanceAcceptance::class)->verify($instance->id, $this->policy->revision, $instance->revision, $this->admin->id);
        $instance->refresh()->fill(['verified_until' => time() - 1])->save();
        $this->assertNotSame('passed', $this->report($task)['verdict']);
        $instance->fill(['verified_until' => time() + 120])->save(); Cache::forget('node_instances:10');
        $this->assertSame('blocked', $this->report($task)['verdict']); $this->assertFalse($this->report($task)['evidence_current']);
    }

    public function test_read_only_request_resolution_never_submits_a_retry(): void
    {
        $task = $this->task(); $resolved = $this->service->report($this->policy->id, $this->policy->revision, null, str_repeat('a', 32), $this->admin->id);
        $this->assertSame($task->id, $resolved['task']['id']);
        $empty = $this->service->report($this->policy->id, $this->policy->revision, null, str_repeat('b', 32), $this->admin->id);
        $this->assertSame('not_started', $empty['verdict']); $this->assertSame(1, Task::query()->count()); Http::assertNothingSent();
    }

    public function test_ambiguous_cloud_creation_reconciles_the_original_task_without_a_second_post(): void
    {
        $task = $this->task(); $this->ambiguousCreate = true; $provision = app(MachineProvisionService::class);
        $provision->process($task->id); $this->assertSame('outcome_unknown', $task->fresh()->status);
        $task->refresh()->fill(['next_check_at' => 0])->save(); $provision->process($task->id);
        $this->assertSame('waiting_initialization', $task->fresh()->status); $this->assertSame(1, $this->creates);
        $this->assertSame(1, Task::query()->count()); $this->assertSame(0, DB::table('v2_machine_dns_transition')->count());
    }

    public function test_controller_requires_admin_and_rejects_dns_and_arbitrary_plan_fields(): void
    {
        $controller = new MachineIsolatedAcceptanceController();
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request = Request::create('/synthetic', 'POST'); $request->setUserResolver(fn () => $actor);
            try { $controller->preview($request, $this->service); $this->fail('Expected forbidden'); }
            catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $request = Request::create('/synthetic', 'POST', ['dns_cutover' => true, 'plan' => ['user_data' => 'never-run']]); $request->setUserResolver(fn () => $this->admin);
        $this->assertSame(422, $controller->queue($request, $this->service)->getStatusCode());
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }
}
