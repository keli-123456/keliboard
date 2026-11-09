<?php
namespace Tests\Unit\Services;

use App\Models\MachineAcceptanceCheck;
use App\Models\MachineCloudAccount as Account;
use App\Models\MachineCloudGroup;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverAttempt as Attempt;
use App\Models\MachineFailoverExecution as Execution;
use App\Models\MachineFailoverExecutionMember as Member;
use App\Models\MachineFailoverPlan;
use App\Models\MachineFailoverPolicy as Policy;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask as Task;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineFailoverCredentials;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Cloud\MachineFailoverNativeBridge;
use App\Services\ServerMachine\Cloud\MachineFailoverObserver;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineOwnedDnsService;
use App\Services\ServerMachine\Cloud\MachineProvisionService;
use App\Services\ServerMachine\Cloud\MachineReplacementService;
use App\Services\ServerMachine\Cloud\ProvisionPlan;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineFailoverNativeBridgeTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private User $admin;
    private ServerMachineRelease $release;
    private array $fixtures = [];
    private array $remote = [];
    private array $records = [];
    private array $posts = [];
    private int $dnsWrites = 0;
    private int $verifications = 0;
    private string $mode = '';
    private $duringPost = null;
    private $duringPreflight = null;
    private $duringDnsRead = null;
    private $duringDnsWrite = null;
    private $duringInstanceRead = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('n', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $budget = \Mockery::mock(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class);
        $budget->shouldReceive('assertCapacity')->andReturnNull();
        app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class, $budget);
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_010000_add_machine_cloud_providers.php', '2026_11_06_020000_create_machine_cloud_groups.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_040000_create_machine_failover_observations.php',
            '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_07_090000_create_machine_replacement_tasks.php',
            '2026_11_07_120000_add_provider_groups_to_machine_failover.php', '2026_11_07_140000_create_machine_failover_services.php',
            '2026_11_07_160000_create_machine_credential_health.php', '2026_11_07_180000_create_machine_failover_native_journal.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php', '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); $t->string('type'); $t->integer('port'); $t->integer('server_port'); $t->text('protocol_settings')->nullable(); });
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'v0.1.999', 'platform' => 'linux-x86_64',
            'manifest_path' => 'synthetic/manifest.json', 'archive_path' => 'synthetic/node.tar.gz', 'sha256' => str_repeat('a', 64),
            'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $this->fake(); $this->fixtures[] = $this->fixture(1);
    }

    protected function tearDown(): void { Carbon::setTestNow(); parent::tearDown(); }

    private function fixture(int $n): array
    {
        $machine = ServerMachine::query()->create(['name' => 'Isolated fixture ' . $n, 'token' => 'synthetic-machine-' . $n, 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => $n, 'machine_id' => $machine->id, 'enabled' => true, 'type' => 'hysteria', 'port' => 8443, 'server_port' => 8443]);
        $group = MachineCloudGroup::query()->create(['name' => 'Fixture pool ' . $n, 'name_key' => hash('sha256', 'pool' . $n), 'revision' => 1]);
        $accounts = [];
        foreach (['a', 'b'] as $letter) {
            $account = Account::query()->create(['name' => 'Fixture ' . $n . $letter, 'provider' => 'linode', 'group_id' => $group->id,
                'group_revision' => 1, 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-token-' . $n . $letter]]);
            app(MachineCloudCredentialHealth::class)->verify($account->id, 1, $this->admin->id); $accounts[] = $account;
        }
        $pool = app(MachineFailoverCredentials::class)->pool($group->id, 'linode'); $fingerprint = hash('sha256', 'synthetic-catalog-' . $n);
        Cache::put('machine-cloud:catalog:' . $pool['fingerprint'], ['checked_at' => time(), 'expires_at' => time() + 600, 'fingerprint' => $fingerprint,
            'regions' => [['id' => 'sg-sin-2']], 'sizes' => [['id' => 'g6-standard-4', 'regions' => ['sg-sin-2'], 'disk_gib' => 80]],
            'images' => [['id' => 'linode/debian12', 'regions' => ['sg-sin-2'], 'min_disk_gib' => 4]]], 600);
        $credential = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS ' . $n, 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-' . $n]]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Fixture ' . $n, 'machine_id' => $machine->id, 'provider' => 'linode',
            'credential_group_id' => $group->id, 'catalog_fingerprint' => $fingerprint, 'region' => 'sg-sin-2', 'size' => 'g6-standard-4',
            'image' => 'linode/debian12', 'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 300,
            'monthly_limit_usd' => 100, 'daily_limit' => 2, 'dns_credential_id' => $credential->id, 'dns_hostname' => 'fixture' . $n . '.example.test',
            'isolation_confirmed' => true], $this->admin->id);
        $policy = Policy::query()->findOrFail($saved['id']); $policy->forceFill(['created_at' => time() - 60])->save();
        $choice = app(MachineFailoverCredentials::class)->selection($policy);
        $plan = ProvisionPlan::normalize($choice + ['machine_id' => $machine->id, 'slot' => $policy->slot, 'panel_origin' => 'https://panel.example.test',
            'region' => $policy->region, 'size' => $policy->size, 'image' => $policy->image, 'authorized_keys' => [], 'monthly_limit_usd' => 100]);
        $id = (string) Str::uuid(); $source = Task::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id,
            'account_id' => $accounts[0]->id, 'account_revision' => 1, 'machine_id' => $machine->id, 'machine_authority' => hash('sha256', $machine->token),
            'plan_hash' => hash('sha256', json_encode($plan)), 'slot' => $policy->slot, 'installation_id' => bin2hex(random_bytes(16)),
            'callback_hash' => str_repeat('c', 64), 'marker' => 'synthetic-source-' . $n, 'payload' => ['plan' => $plan, 'request' => []],
            'status' => 'accepted', 'deadline_at' => time() - 86400, 'created_at' => time() - 86401, 'updated_at' => time() - 86401]);
        $sourceIp = $n === 1 ? '8.8.8.8' : '9.9.9.10';
        $owned = MachineOwnedInstance::query()->create(['policy_id' => $policy->id, 'policy_revision' => 1, 'task_id' => $source->id,
            'installation_id' => $source->installation_id, 'release_id' => $this->release->id, 'binary_sha256' => $this->release->binary_sha256,
            'version' => $this->release->version, 'ip' => $sourceIp, 'status' => 'active', 'active_policy_id' => $policy->id]);
        $dns = MachineOwnedDns::query()->create(['policy_id' => $policy->id, 'credential_id' => $credential->id, 'credential_revision' => 1,
            'zone' => str_repeat(dechex($n), 32), 'hostname' => $policy->dns_hostname, 'marker' => 'keli-owned-synthetic-' . $n,
            'record_id' => str_repeat(dechex($n + 8), 32), 'active_instance_id' => $owned->id, 'ip' => $sourceIp, 'status' => 'live'])->fresh();
        $this->records[$dns->record_id] = ['id' => $dns->record_id, 'name' => $dns->hostname, 'type' => 'A', 'content' => $sourceIp,
            'proxied' => false, 'ttl' => 60, 'comment' => $dns->marker, 'locked' => false];
        $fixture = compact('machine', 'policy', 'accounts', 'source', 'sourceIp', 'dns');
        config(['machine_failover.observe_enabled' => true]);
        foreach ([-12 => true, -7 => false, -2 => false] as $delta => $healthy) $this->sourceReport($fixture, $healthy, time() + $delta);
        Carbon::setTestNow();
        return $fixture;
    }

    private function sourceReport(array $f, bool $healthy, ?int $at = null): void
    {
        $at ??= time() - ($healthy ? 0 : 1); $this->report($f, $f['source'], $f['sourceIp'], $healthy, $at);
        Carbon::setTestNow(Carbon::createFromTimestampUTC($at)); app(MachineFailoverObserver::class)->observe($f['policy']->id); Carbon::setTestNow();
    }

    private function report(array $f, Task $task, string $ip, bool $healthy, int $at): void
    {
        $config = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => $at,
            'targets' => array_map(fn ($target, $index) => ['id' => $target['id'], 'status' => $healthy && $index === 0 ? 'reachable' : 'unreachable', 'source_ip' => $ip], $config['targets'], array_keys($config['targets']))];
        app(MachineAddressInventory::class)->record($f['machine'], $task->installation_id, ['public_ipv4' => $ip], $at,
            ['version' => $this->release->version, 'binary_sha256' => $this->release->binary_sha256, 'observation_session' => str_repeat('e', 32),
                'configured_nodes' => [['id' => $f['machine']->id, 'protocol' => 'hysteria2', 'port' => 8443]]], $probe);
        $rows = Cache::get('node_instances:' . $f['machine']->id, []);
        $rows[$task->installation_id] = ['machine_id' => $f['machine']->id, 'installation_id' => $task->installation_id, 'last_seen_at' => $at, 'last_status_at' => $at];
        Cache::put('node_instances:' . $f['machine']->id, $rows, 300);
    }

    private function enable(): void
    {
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate { public function assertAllowed(string $operation): void {} });
        config(['machine_provision.allow_live' => true, 'machine_provision.allowed_test_machine_ids' => array_map(fn ($f) => $f['machine']->id, $this->fixtures),
            'machine_failover.allow_replacement' => true, 'machine_failover.allowed_replacement_policy_ids' => array_map(fn ($f) => $f['policy']->id, $this->fixtures),
            'machine_acceptance.allow_verification' => true, 'machine_acceptance.allow_dns' => true,
            'machine_acceptance.allowed_policy_ids' => array_map(fn ($f) => $f['policy']->id, $this->fixtures),
            'machine_acceptance.verifier_url' => 'https://verifier.example.test/v1/verify', 'machine_acceptance.verifier_key' => str_repeat('k', 32),
            'machine_acceptance.verifier_profiles' => array_fill_keys(array_map(fn ($f) => $f['policy']->id, $this->fixtures), 'fixture-profile')]);
    }

    private function start(int $cap = 400, int $parallel = 1, int $attempts = 4): array
    {
        $this->enable();
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Native fixture', 'max_parallel' => $parallel,
            'attempt_limit' => $attempts, 'monthly_cap_usd' => $cap, 'members' => array_map(fn ($f) => ['policy_id' => $f['policy']->id,
                'policy_revision' => $f['policy']->revision, 'name' => $f['machine']->name, 'priority' => $f['machine']->id, 'enabled' => true], $this->fixtures)], $this->admin->id);
        return app(MachineFailoverNativeBridge::class)->start($service['id'], 1, array_map(fn ($f) => MachineFailoverPlan::query()
            ->where('policy_id', $f['policy']->id)->latest('id')->value('id'), $this->fixtures), $this->release->id, str_repeat('d', 32), $this->admin->id);
    }

    private function advance(array $run): array { return app(MachineFailoverNativeBridge::class)->advance($run['id'], $this->admin->id); }
    private function task(int $member = 0): Task { return Task::query()->findOrFail(Attempt::query()->where('execution_member_id', Member::query()->orderBy('id')->skip($member)->value('id'))->latest('ordinal')->value('task_id')); }
    private function ready(array $run, int $member = 0): array
    {
        $task = $this->task($member); $this->assertSame('waiting_initialization', $task->status);
        $this->assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[$member], $task, $task->public_ips[0], true, time());
        return $this->advance($run);
    }

    private function callbackSecret(Task $task): string
    {
        $data = Yaml::parse(base64_decode($task->payload['request']['metadata']['user_data']));
        preg_match('/Bearer ([a-f0-9]{64})/', $data['write_files'][2]['content'], $match);
        return $match[1];
    }

    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_starts_with($url, 'https://api.linode.com/')) {
                if ($request->method() === 'POST') {
                    $token = $request->header('Authorization')[0]; $this->posts[] = [$token, $request['label']];
                    if ($this->duringPost) ($this->duringPost)();
                    if ($this->mode === 'reject' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'fixture denial']]], 401);
                    if ($this->mode === 'billing' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'fixture billing']]], 402);
                    if ($this->mode === 'quota' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'quota exceeded']]], 422);
                    if ($this->mode === 'rate') return Http::response(['errors' => [['reason' => 'fixture rate']]], 429, ['Retry-After' => '120']);
                    $row = ['id' => 1000 + count($this->posts), 'label' => $request['label'], 'tags' => $request['tags'], 'region' => $request['region'],
                        'type' => $request['type'], 'image' => $request['image'], 'created' => gmdate('Y-m-d\TH:i:s'), 'status' => 'running',
                        'ipv4' => [count($this->posts) === 1 ? '8.8.4.4' : '9.9.9.11'], 'ipv6' => ''];
                    $row['backups'] = ['enabled' => false];
                    if ($this->mode !== 'unknown_absent') $this->remote[$row['id']] = $row;
                    if (str_starts_with($this->mode, 'unknown')) return Http::response([], 503);
                    return Http::response($row);
                }
                if (str_ends_with($url, '/v4/account')) return Http::response(['euuid' => 'fixture-account-' . substr(hash('sha256', $request->header('Authorization')[0]), 0, 16)]);
                if (str_ends_with($url, '/profile/grants')) return Http::response('', 204);
                if (str_contains($url, '/volumes?')) return Http::response(['page' => 1, 'pages' => 1, 'results' => 0, 'data' => []]);
                if (str_contains($url, '/regions/')) return Http::response(['id' => 'sg-sin-2', 'status' => 'ok', 'capabilities' => ['Metadata']]);
                if (str_contains($url, '/images/')) return Http::response(['id' => 'linode/debian12', 'status' => 'available', 'deprecated' => false, 'capabilities' => ['cloud-init']]);
                if (str_contains($url, '/linode/types/')) return Http::response(['id' => 'g6-standard-4', 'class' => 'standard', 'price' => ['monthly' => 48], 'region_prices' => []]);
                if (str_contains($url, '/account/settings')) { if ($this->duringPreflight) ($this->duringPreflight)(); return Http::response(['backups_enabled' => false]); }
                if (preg_match('~/linode/instances/(\d+)$~', $url, $m)) {
                    if ($this->duringInstanceRead) ($this->duringInstanceRead)();
                    return Http::response($this->remote[(int) $m[1]]);
                }
                if (str_contains($url, '/linode/instances?')) {
                    $filter = json_decode($request->header('X-Filter')[0] ?? '{}', true);
                    $rows = array_values(array_filter($this->remote, fn ($row) => !isset($filter['label']) || $filter['label'] === $row['label']));
                    return Http::response(['data' => $rows, 'pages' => 1, 'page' => 1, 'results' => count($rows)]);
                }
            }
            if ($url === 'https://verifier.example.test/v1/verify') {
                $this->verifications++; $challenge = json_decode($request->body(), true);
                $echo = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'bytes' => 96, 'sha256' => $challenge['echo_sha256']];
                $reply = ['schema' => 1, 'challenge_id' => $challenge['challenge_id'], 'request_sha256' => hash('sha256', $request->body()),
                    'finished_at' => time(), 'cleanup_complete' => true, 'results' => array_map(fn ($node) => ['node_id' => $node['id'],
                        'endpoint_authenticated' => true, 'tcp' => $echo, 'udp' => $echo], $challenge['nodes'])];
                if ($this->mode === 'bad_receipt') $reply['challenge_id'] = str_repeat('0', 32);
                $raw = json_encode($reply);
                return Http::response($raw, 200, ['X-Keli-Signature' => hash_hmac('sha256', "response\n" . $raw, config('machine_acceptance.verifier_key'))]);
            }
            if (str_starts_with($url, 'https://api.cloudflare.com/')) {
                if ($request->method() === 'GET') {
                    if ($this->duringDnsRead) ($this->duringDnsRead)();
                    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                    $hostname = $query['name_exact'] ?? $query['name'] ?? null;
                    return Http::response(['success' => true, 'result' => array_values(array_filter($this->records, fn ($row) => $hostname === null || $row['name'] === $hostname)), 'result_info' => ['total_pages' => 1]]);
                }
                $this->assertSame('PATCH', $request->method()); $this->dnsWrites++;
                $id = basename(parse_url($url, PHP_URL_PATH)); $this->records[$id] = array_replace($this->records[$id], $request->data());
                if ($this->duringDnsWrite) ($this->duringDnsWrite)();
                if ($this->mode === 'dns_unknown') throw new ConnectionException('synthetic outcome unknown');
                return Http::response(['success' => true, 'result' => $this->records[$id]]);
            }
            throw new \RuntimeException('Unmocked fixture transport');
        });
    }

    private function code(string $reason, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $reason); }
        catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }

    public function test_hard_gate_cannot_be_armed_by_existing_settings_or_enqueue_native_intents(): void
    {
        $this->enable(); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        config(['machine_failover.native_enabled' => true]);
        $gate = app(MachineFailoverExecutionGate::class);
        foreach (['start', 'advance', 'rotate', 'dns_write'] as $operation) $this->code('orchestration_disabled', fn () => $gate->assertAllowed($operation));
        $this->code('orchestration_disabled', fn () => app(MachineFailoverNativeBridge::class)->start(1, 1, [1], $this->release->id, str_repeat('a', 32), $this->admin->id));
        $this->code('orchestration_disabled', fn () => app(MachineProvisionService::class)->queueCoordinated([], str_repeat('a', 32), $this->admin->id, (string) Str::uuid()));
        $this->assertSame(0, Execution::count()); $this->assertSame(0, Attempt::count()); $this->assertSame([], $this->posts); Bus::assertNothingDispatched();
    }

    public function test_native_create_reports_forwarding_and_owned_dns_use_one_pinned_lineage(): void
    {
        $run = $this->start(); $run = $this->advance($run); $this->assertCount(1, $this->posts, json_encode($run));
        $run = $this->ready($run); $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->verifications); $this->assertSame(0, $this->dnsWrites);
        $run = $this->advance($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->dnsWrites); $this->assertSame(10000, $run['reserved_cents']); $this->assertSame(1, $this->verifications);
        $this->assertSame('retained', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
        $again = $this->advance($run); $this->assertSame($run, $again); $this->assertSame(1, $this->dnsWrites);
        $payload = Attempt::query()->first()->payload; $this->assertArrayHasKey('forwarding_receipt', $payload); $this->assertArrayHasKey('dns_receipt', $payload);
        $json = json_encode($run); foreach (['synthetic-token', 'synthetic-machine', 'root_pass', 'callback_hash', 'coordinated_attempt'] as $secret) $this->assertStringNotContainsString($secret, $json);
    }

    public function test_definite_create_rejection_reserves_a_distinct_child_without_rebinding_the_first_task(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start()); $first = $this->task();
        $this->assertSame('failed', $first->status); $this->assertSame('retry_ready', $run['members'][0]['phase'], json_encode($run));
        $oldIdentity = $first->only(['id', 'account_id', 'account_revision', 'installation_id', 'marker', 'plan_hash']);
        $run = $this->advance($run); $second = $this->task();
        $this->assertNotSame($first->id, $second->id); $this->assertNotSame($first->installation_id, $second->installation_id);
        $this->assertNotSame($first->account_id, $second->account_id); $this->assertSame($oldIdentity, $first->fresh()->only(array_keys($oldIdentity)));
        $this->assertCount(2, $this->posts); $this->assertSame(20000, $run['reserved_cents']);
        $this->assertSame('waiting_initialization', $second->status, json_encode($run));
        $this->assertSame(1, Member::count()); $this->assertSame(2, Attempt::count());
        $this->assertSame(Attempt::query()->oldest('ordinal')->value('id'), Attempt::query()->latest('ordinal')->first()->payload['previous_attempt_id']);
    }

    #[DataProvider('unknownModes')]
    public function test_unknown_create_only_reconciles_original_task_and_never_rotates(string $mode): void
    {
        $this->mode = $mode; $run = $this->advance($this->start()); $original = $this->task();
        $this->assertSame('outcome_unknown', $original->status, json_encode($run));
        for ($i = 0; $i < 3; $i++) { $original->fill(['next_check_at' => 0])->save(); $run = $this->advance($run); }
        $this->assertSame($original->id, $this->task()->id); $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
        $this->assertSame($mode === 'unknown_absent' ? 'outcome_unknown' : 'waiting_initialization', $original->fresh()->status);
    }
    public static function unknownModes(): array { return [['unknown_present'], ['unknown_absent']]; }

    public function test_interruption_after_native_enqueue_reuses_the_atomic_task_binding(): void
    {
        $this->duringPost = function () { throw new ConnectionException('synthetic disconnect'); };
        $run = $this->advance($this->start()); $attempt = Attempt::query()->first(); $task = $this->task();
        $again = app(MachineProvisionService::class)->queueCoordinated($attempt->payload['plan'], $attempt->request_id, $this->admin->id, $attempt->id);
        $this->assertSame($task->id, $again['id']); $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
        $this->assertSame('reconciling_create', $run['members'][0]['phase']);
    }

    public function test_rate_limit_keeps_original_credential_and_server_backoff(): void
    {
        $this->mode = 'rate'; $run = $this->advance($this->start()); $task = $this->task();
        $this->assertGreaterThanOrEqual(time() + 115, $task->next_check_at); $this->advance($run);
        $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
    }

    #[DataProvider('budgetCases')]
    public function test_shared_attempt_and_monthly_budgets_include_rejected_attempts(int $cap, int $limit, string $reason): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start($cap, 1, $limit)); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame($reason, $run['members'][0]['reason'], json_encode($run));
        $this->assertSame(10000, $run['reserved_cents']); $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }
    public static function budgetCases(): array { return [[100, 4, 'shared_monthly_budget'], [400, 1, 'attempt_budget']]; }

    public function test_parallel_limit_is_shared_by_members_and_unknown_results_keep_the_slot_reserved(): void
    {
        $this->fixtures[] = $this->fixture(2); $this->mode = 'unknown_absent'; $run = $this->advance($this->start());
        $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
        $this->assertSame('reconciling_create', $run['members'][0]['phase']); $this->assertSame('ready', $run['members'][1]['phase']);
        $this->advance($run); $this->assertCount(1, $this->posts);
    }

    public function test_source_recovery_before_create_cancels_without_reserving_or_calling_provider(): void
    {
        $run = $this->start(); $this->sourceReport($this->fixtures[0], true);
        $run = $this->advance($run); $this->assertSame('cancelled', $run['status'], json_encode($run));
        $this->assertSame(0, Attempt::count()); $this->assertSame([], $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_source_recovery_after_creation_retains_candidate_without_dns_writes(): void
    {
        $run = $this->advance($this->start()); $run = $this->ready($run); $this->sourceReport($this->fixtures[0], true);
        $run = $this->advance($run); $this->assertSame('retained', $run['status'], json_encode($run));
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites); $this->assertSame(10000, $run['reserved_cents']);
    }

    public function test_ambiguous_dns_write_is_read_back_once_without_a_second_patch_even_if_source_recovers(): void
    {
        $run = $this->advance($this->start()); $run = $this->ready($run); $this->mode = 'dns_unknown';
        $run = $this->advance($run); $this->assertSame('reconciling_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->mode = ''; $this->sourceReport($this->fixtures[0], true);
        $run = $this->advance($run); $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame(1, MachineDnsTransition::count());
    }

    public function test_bad_signed_worker_receipt_cannot_authorize_dns(): void
    {
        $run = $this->advance($this->start()); $this->mode = 'bad_receipt'; $run = $this->ready($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, MachineDnsTransition::count()); $this->assertSame(1, $this->verifications);
        $this->advance($run); $this->assertSame(1, $this->verifications);
    }

    #[DataProvider('identityChanges')]
    public function test_native_identity_and_release_changes_are_not_treated_as_valid_receipts(string $change): void
    {
        $run = $this->advance($this->start()); $task = $this->task();
        if ($change === 'release') $this->release->fill(['binary_sha256' => str_repeat('c', 64)])->save();
        elseif ($change === 'task') $task->fill(['account_id' => Account::query()->where('id', '!=', $task->account_id)->value('id')])->save();
        elseif ($change === 'installation') $task->fill(['installation_id' => str_repeat('f', 32)])->save();
        elseif ($change === 'marker') $task->fill(['marker' => 'keli-create-foreign'])->save();
        elseif ($change === 'bootstrap_request') { $payload = $task->payload; $payload['request']['metadata']['user_data'] = 'synthetic-foreign'; $task->payload = $payload; $task->save(); }
        else {
            $attempt = Attempt::query()->first(); $payload = $attempt->payload; $payload['plan']['size'] = 'g6-standard-8'; $attempt->payload = $payload; $attempt->save();
        }
        $run = $this->advance($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }
    public static function identityChanges(): array { return [['release'], ['task'], ['plan'], ['installation'], ['marker'], ['bootstrap_request']]; }

    public function test_two_members_complete_with_independent_native_tasks_and_receipts(): void
    {
        $this->fixtures[] = $this->fixture(2); $run = $this->advance($this->start(400, 2));
        $this->assertCount(2, $this->posts); $this->assertSame(2, Attempt::count());
        $run = $this->ready($run); $run = $this->ready($run, 1); $run = $this->advance($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(20000, $run['reserved_cents']);
        $this->assertSame(2, $this->verifications); $this->assertSame(2, $this->dnsWrites);
        $this->assertCount(2, array_unique(Attempt::query()->get()->map(fn ($row) => $row->payload['forwarding_receipt'])->all()));
    }

    public function test_native_notification_hooks_accept_real_internal_receipts_without_extra_cloud_operations(): void
    {
        foreach (['2026_11_07_190000_create_machine_failover_finance.php', '2026_11_07_200000_create_machine_failover_review.php',
            '2026_11_07_210000_create_machine_failover_notice_state.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        $notifier = new class extends \App\Services\ServerMachine\MachineOperationNotifier { protected function channelReady(): bool { return true; } };
        app()->instance(\App\Services\ServerMachine\MachineOperationNotifier::class, $notifier);
        $notifier->save('failover', true, 1, $this->admin->id);
        $run = $this->ready($this->advance($this->start())); $run = $this->advance($run);
        $this->assertSame('completed', $run['status']); $this->assertCount(1, $this->posts); $this->assertSame(1, $this->verifications); $this->assertSame(1, $this->dnsWrites);
        $notices = \App\Models\MachineOperationNotice::query()->where('scope', 'failover')->get();
        $this->assertTrue($notices->contains('phase', 'progress')); $this->assertTrue($notices->contains('phase', 'forwarding_verified'));
        $completion = $notices->firstWhere('phase', 'completed'); $this->assertNotNull($completion, $notices->toJson());
        $this->assertTrue(app(\App\Services\ServerMachine\MachineFailoverNoticeSource::class)->current($completion));
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\SendMachineOperationNoticeJob::class);
    }

    public function test_crash_after_dns_prepare_reattaches_the_same_intent_without_creating_a_second_one(): void
    {
        $run = $this->ready($this->advance($this->start())); $attempt = Attempt::query()->first();
        $payload = $attempt->payload; unset($payload['transition_id']); $attempt->payload = $payload; $attempt->save();
        $run = $this->advance($run); $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, MachineDnsTransition::count()); $this->assertSame(0, $this->dnsWrites);
        $run = $this->advance($run); $this->assertSame('completed', $run['status']); $this->assertSame(1, $this->dnsWrites);
    }

    public function test_crash_after_verified_dns_acknowledges_persisted_receipt_without_reverification_or_rewrite(): void
    {
        $run = $this->ready($this->advance($this->start())); $attempt = Attempt::query()->first();
        app(MachineOwnedDnsService::class)->execute($attempt->payload['transition_id'], $this->admin->id);
        $this->assertSame(1, $this->dnsWrites); $this->assertSame('awaiting_dns', Member::query()->first()->phase);
        MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->update(['verified_until' => time() - 1]);
        $run = $this->advance($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(1, $this->verifications);
    }

    public function test_verification_boundary_without_a_receipt_never_sends_the_worker_challenge_twice(): void
    {
        $run = $this->advance($this->start()); $task = $this->task();
        $task->fill(['bootstrap_state' => 'succeeded', 'dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        app(MachineProvisionService::class)->process($task->id); $task->refresh(); $this->assertSame('awaiting_acceptance', $task->status);
        $attempt = Attempt::query()->first(); $payload = $attempt->payload; $payload['verification_started_at'] = time(); $attempt->payload = $payload; $attempt->save();
        $run = $this->advance($run); $this->assertSame('needs_review', $run['status']);
        $this->assertSame('verification_requires_review', $run['members'][0]['reason']); $this->assertSame(0, $this->verifications);
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, MachineAcceptanceCheck::count());
    }

    public function test_conflicting_duplicate_forwarding_receipt_is_not_overwritten(): void
    {
        $run = $this->ready($this->advance($this->start())); $attempt = Attempt::query()->first();
        $payload = $attempt->payload; $payload['forwarding_receipt'] = str_repeat('0', 64); $attempt->payload = $payload; $attempt->save();
        $run = $this->advance($run); $this->assertSame('needs_review', $run['status']);
        $this->assertSame('native_receipt_mismatch', $run['members'][0]['reason']); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(str_repeat('0', 64), $attempt->fresh()->payload['forwarding_receipt']);
    }

    public function test_another_dns_intent_or_recovery_cannot_be_submitted_for_the_pinned_candidate(): void
    {
        $run = $this->ready($this->advance($this->start())); $instance = MachineOwnedInstance::query()->where('task_id', $this->task()->id)->first();
        $this->code('native_receipt_mismatch', fn () => app(MachineOwnedDnsService::class)->prepare($instance->id, 1, $instance->revision,
            $this->fixtures[0]['dns']->zone, str_repeat('e', 32), null, $this->admin->id));
        $transition = MachineDnsTransition::query()->first(); $transition->fill(['request_key' => str_repeat('f', 64)])->save();
        $this->code('native_receipt_mismatch', fn () => app(MachineOwnedDnsService::class)->execute($transition->id, $this->admin->id));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(1, MachineDnsTransition::count());
    }

    public function test_recovery_during_dns_preflight_is_rechecked_at_the_mutation_boundary(): void
    {
        $run = $this->ready($this->advance($this->start()));
        $f = $this->fixtures[0];
        $this->duringDnsRead = fn () => $this->report($f, $f['source'], $f['sourceIp'], true, time());
        $run = $this->advance($run); $this->assertSame('retained', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertNull(MachineDnsTransition::query()->first()->dispatched_at);
    }

    public function test_recovery_during_unknown_create_does_not_abandon_or_resend_the_original_mutation(): void
    {
        $this->mode = 'unknown_present'; $run = $this->advance($this->start()); $task = $this->task();
        $this->sourceReport($this->fixtures[0], true); $task->fill(['next_check_at' => 0])->save(); $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $task->fresh()->status); $this->assertCount(1, $this->posts);
        $run = $this->ready($run); $this->assertSame('retained', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(1, Attempt::count());
    }

    #[DataProvider('revocations')]
    public function test_create_preflight_rechecks_authority_and_the_closed_gate_before_post(string $kind): void
    {
        $run = $this->start();
        $this->duringPreflight = function () use ($kind) {
            if ($kind === 'gate') app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
            else $this->admin->fill(['banned' => true])->save();
        };
        if ($kind === 'admin') {
            $this->code('permission_revoked', fn () => $this->advance($run));
        } else $this->advance($run);
        $this->assertSame([], $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame('failed', $this->task()->status);
    }
    public static function revocations(): array { return [['gate'], ['admin']]; }

    public function test_registry_keeps_native_run_context_and_history_from_being_deleted_or_reset(): void
    {
        $run = $this->start(); $execution = Execution::query()->find($run['id']);
        $registry = app(MachineFailoverServiceRegistry::class);
        $this->code('service_in_use', fn () => $registry->drop($execution->service_id, 1, $this->admin->id));
        $this->code('run_active', fn () => $registry->save(['id' => $execution->service_id, 'expected_revision' => 1, 'name' => 'Edited',
            'max_parallel' => 1, 'attempt_limit' => 4, 'monthly_cap_usd' => 400, 'members' => array_map(fn ($f) => ['policy_id' => $f['policy']->id,
                'policy_revision' => 1, 'name' => 'Edited', 'priority' => 1, 'enabled' => true], $this->fixtures)], $this->admin->id));
        $same = app(MachineFailoverNativeBridge::class)->start($execution->service_id, 1, [MachineFailoverPlan::query()->latest('id')->value('id')], $this->release->id,
            str_repeat('d', 32), $this->admin->id);
        $this->assertSame($run, $same); $this->assertSame(1, Execution::count());
    }

    #[DataProvider('accountRejections')]
    public function test_billing_and_quota_only_rotate_to_verified_independent_accounts(string $mode): void
    {
        $this->mode = $mode; $run = $this->advance($this->start()); $first = $this->task();
        $this->assertSame('retry_ready', $run['members'][0]['phase'], json_encode($run));
        $run = $this->advance($run); $this->assertCount(2, $this->posts); $this->assertSame(2, Attempt::count());
        $this->assertNotSame($first->account_id, $this->task()->account_id); $this->assertSame(20000, $run['reserved_cents']);
    }
    public static function accountRejections(): array { return [['billing'], ['quota']]; }

    #[DataProvider('unsafeIdentities')]
    public function test_account_aliases_unknown_identity_and_duplicate_tokens_never_gain_a_new_attempt(string $kind): void
    {
        $accounts = $this->fixtures[0]['accounts'];
        if ($kind === 'unknown') DB::table('v2_machine_cloud_identity')->delete();
        elseif ($kind === 'alias') {
            $key = DB::table('v2_machine_cloud_identity')->where('account_id', $accounts[0]->id)->value('identity_key');
            DB::table('v2_machine_cloud_identity')->where('account_id', $accounts[1]->id)->update(['identity_key' => $key]);
        } else {
            $accounts[1]->credentials = $accounts[0]->credentials; $accounts[1]->save();
        }
        $this->mode = $kind === 'duplicate' ? 'reject' : 'billing'; $run = $this->advance($this->start()); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }
    public static function unsafeIdentities(): array { return [['alias'], ['unknown'], ['duplicate']]; }

    public function test_shared_price_reservation_is_not_reset_by_a_new_run_for_another_member(): void
    {
        $this->fixtures[] = $this->fixture(2); $this->enable();
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Shared native cap', 'max_parallel' => 1, 'attempt_limit' => 1,
            'monthly_cap_usd' => 100, 'members' => array_map(fn ($f) => ['policy_id' => $f['policy']->id, 'policy_revision' => 1,
                'name' => $f['machine']->name, 'priority' => $f['machine']->id, 'enabled' => true], $this->fixtures)], $this->admin->id);
        $plans = array_map(fn ($f) => MachineFailoverPlan::query()->where('policy_id', $f['policy']->id)->latest('id')->value('id'), $this->fixtures);
        $bridge = app(MachineFailoverNativeBridge::class); $this->mode = 'reject';
        $first = $bridge->start($service['id'], 1, [$plans[0]], $this->release->id, str_repeat('a', 32), $this->admin->id);
        $first = $this->advance($this->advance($first)); $this->assertSame('needs_review', $first['status']);
        $second = $bridge->start($service['id'], 1, [$plans[1]], $this->release->id, str_repeat('b', 32), $this->admin->id);
        $second = $this->advance($second); $this->assertSame('shared_monthly_budget', $second['members'][0]['reason'], json_encode($second));
        $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
    }

    public function test_revocation_while_post_is_in_flight_retains_review_instead_of_authorizing_a_child(): void
    {
        $this->mode = 'reject'; $run = $this->start();
        $this->duringPost = fn () => app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $run = $this->advance($run); $this->assertSame('needs_review', $this->task()->status);
        $this->assertSame('needs_review', $run['status']); $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }

    public function test_expired_forwarding_proof_after_unknown_dns_does_not_reverify_or_rewrite(): void
    {
        $run = $this->ready($this->advance($this->start())); $this->mode = 'dns_unknown'; $run = $this->advance($run);
        MachineOwnedInstance::query()->where('task_id', $this->task()->id)->update(['verified_until' => time() - 1]);
        $run = $this->advance($run); $this->assertSame('verification_requires_review', $run['members'][0]['reason'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(1, $this->verifications); $this->assertSame(1, MachineDnsTransition::count());
    }

    public function test_initialization_callback_is_bound_to_task_installation_secret_and_lineage(): void
    {
        $run = $this->advance($this->start()); $task = $this->task(); $secret = $this->callbackSecret($task);
        $provision = app(MachineProvisionService::class);
        $this->code('invalid_callback', fn () => $provision->callback($task->id, str_repeat('f', 32), $secret, 'succeeded'));
        $this->code('invalid_callback', fn () => $provision->callback($task->id, $task->installation_id, str_repeat('f', 64), 'succeeded'));
        $this->assertTrue($provision->callback($task->id, $task->installation_id, $secret, 'succeeded'));
        $this->assertTrue($provision->callback($task->id, $task->installation_id, $secret, 'succeeded'));
        $this->code('bootstrap_conflict', fn () => $provision->callback($task->id, $task->installation_id, $secret, 'failed'));
        $this->assertSame('succeeded', $task->fresh()->bootstrap_state); $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
    }

    public function test_failed_initialization_never_rotates_or_prepares_dns(): void
    {
        $run = $this->advance($this->start()); $task = $this->task();
        app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'failed');
        $run = $this->advance($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame('initialization_failed', $run['members'][0]['reason']); $this->assertSame(1, Attempt::count());
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->verifications); $this->assertSame(0, MachineDnsTransition::count());
    }

    public function test_existing_manual_replacement_cannot_race_a_claimed_episode(): void
    {
        $this->start(); $plan = MachineFailoverPlan::query()->latest('id')->first();
        $this->code('replacement_episode_used', fn () => app(MachineReplacementService::class)->queue($plan->id, 1, str_repeat('e', 32), $this->admin->id));
        $this->assertSame([], $this->posts); $this->assertSame(1, Task::count());
    }

    public function test_journal_schema_downgrade_never_discards_existing_intents(): void
    {
        $this->start(); $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_180000_create_machine_failover_native_journal.php'))->down();
    }

    private function realCostGuard(): \App\Services\ServerMachine\Cloud\MachineFailoverBudget
    {
        (require base_path('database/migrations/2026_11_07_190000_create_machine_failover_finance.php'))->up();
        app()->forgetInstance(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class);
        foreach ($this->fixtures as $index => $f) {
            $source = $f['source']; $plan = $source->payload['plan'];
            $request = ['label' => 'keli-' . str_replace('-', '', $source->id), 'tags' => [$source->marker],
                'region' => $plan['region'], 'type' => $plan['size'], 'image' => $plan['image']];
            $source->fill(['payload' => ['plan' => $plan, 'request' => $request], 'external_id' => (string) (900 + $index),
                'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86401), 'dispatched_at' => time() - 86401])->save();
            $this->remote[900 + $index] = ['id' => 900 + $index, 'label' => $request['label'], 'tags' => $request['tags'], 'region' => $plan['region'],
                'type' => $plan['size'], 'image' => $plan['image'], 'created' => $source->provider_created_at, 'status' => 'running',
                'ipv4' => [$f['sourceIp']], 'ipv6' => '', 'backups' => ['enabled' => false]];
        }
        return app(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class);
    }

    public function test_native_reserve_requires_a_fresh_real_cost_baseline_even_when_all_execution_gates_are_test_injected(): void
    {
        $this->realCostGuard(); $run = $this->advance($this->start());
        $this->assertSame('cost_baseline_required', $run['members'][0]['reason'], json_encode($run));
        $this->assertSame(0, Attempt::count()); $this->assertSame([], $this->posts);
    }

    public function test_native_rotation_consumes_existing_source_cost_and_release_never_resumes_the_old_attempt(): void
    {
        $finance = $this->realCostGuard(); $this->mode = 'reject'; $run = $this->start(230);
        $execution = Execution::query()->find($run['id']);
        $finance->capture($execution->service_id, 1, $this->admin->id);
        $run = $this->advance($run); $run = $this->advance($run);
        $this->assertSame('shared_monthly_budget', $run['members'][0]['reason'], json_encode($run));
        $this->assertCount(1, $this->posts); $attempt = Attempt::query()->first();
        $preview = $finance->previewRelease($attempt->id, $this->admin->id); $this->assertTrue($preview['eligible'], json_encode($preview));
        $finance->release($attempt->id, $preview['proof_token'], str_repeat('f', 32), $this->admin->id);
        $finance->assertCapacity($execution->service_id, 1, 10000);
        $this->code('budget_released', fn () => app(MachineFailoverNativeBridge::class)->assertAttempt($attempt->fresh()));
        $this->advance($run); $this->assertCount(1, $this->posts);
    }

    private function runtimeSchema(): void
    {
        foreach (['2026_11_07_190000_create_machine_failover_finance.php', '2026_11_07_200000_create_machine_failover_review.php',
            '2026_11_07_220000_create_machine_failover_runtime.php'] as $file) (require base_path('database/migrations/' . $file))->up();
    }
    private function runner(): \App\Services\ServerMachine\Cloud\MachineFailoverRunner { return app(\App\Services\ServerMachine\Cloud\MachineFailoverRunner::class); }
    private function runtimeStart(int $parallel = 1): array
    {
        $this->runtimeSchema(); config(['machine_failover.runner_enabled' => true]); return $this->start(400, $parallel);
    }
    private function tick(array $run): array
    {
        \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['next_check_at' => 0]);
        Task::query()->whereIn('id', Attempt::query()->whereIn('execution_member_id', Member::query()->where('execution_id', $run['id'])->select('id'))->select('task_id'))->update(['next_check_at' => 0]);
        $this->runner()->advance();
        $job = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last(fn ($job) => $job->execution === $run['id']);
        $this->assertNotNull($job); $job->handle($this->runner());
        return app(MachineFailoverNativeBridge::class)->report($run['id'], $this->admin->id);
    }
    private function runtimeReady(array $run, int $member = 0): void
    {
        $task = $this->task($member); $this->assertSame('waiting_initialization', $task->status);
        $this->assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[$member], $task, $task->public_ips[0], true, time());
    }
    private function stopRuntime(array $run): array
    {
        $view = $this->runner()->view(Execution::query()->findOrFail($run['id']));
        return $this->runner()->stop($run['id'], $view['revision'], str_repeat('1', 32), $this->admin->id);
    }

    public function test_runner_defaults_off_and_configuration_alone_cannot_open_the_hard_execution_gate(): void
    {
        $this->runtimeSchema(); $run = $this->start();
        $this->assertSame(0, \App\Models\MachineFailoverRuntime::count());
        config(['machine_failover.runner_enabled' => true]); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $this->runner()->enroll($run['id']); $this->assertFalse($this->runner()->advance()['enabled']);
        $this->assertSame(0, \App\Models\MachineFailoverRuntime::count()); $this->assertSame([], $this->posts);
        Bus::assertNotDispatched(\App\Jobs\MachineFailoverRunJob::class);
        $job = new \App\Jobs\MachineFailoverRunJob($run['id'], 1, str_repeat('a', 48));
        $this->assertSame('redis_failover', $job->connection); $this->assertSame('machine_failover', $job->queue);
        $queue = require base_path('config/queue.php');
        $this->assertSame(1, $job->tries); $this->assertLessThan($queue['connections']['redis']['retry_after'], $job->timeout);
    }
    public function test_runner_completes_native_queue_callback_forwarding_and_dns_with_duplicate_jobs_ignored(): void
    {
        $run = $this->runtimeStart(); $first = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        $run = $this->tick($run); $first->handle($this->runner()); $this->assertCount(1, $this->posts);
        Bus::assertNotDispatched(\App\Jobs\MachineProvisionJob::class);
        $this->runtimeReady($run); $run = $this->tick($run); $this->assertSame('awaiting_dns', $run['members'][0]['phase']);
        $run = $this->tick($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame('finished', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->dnsWrites); $this->assertSame(1, $this->verifications);
        $this->assertFalse(app()->bound(\App\Services\ServerMachine\Cloud\MachineFailoverLease::class));
    }
    public function test_runner_round_robins_members_under_the_shared_parallel_budget(): void
    {
        $this->fixtures[] = $this->fixture(2); $run = $this->runtimeStart(2);
        $run = $this->tick($run); $run = $this->tick($run); $this->assertCount(2, $this->posts);
        $this->runtimeReady($run); $this->runtimeReady($run, 1);
        for ($i = 0; $i < 4; $i++) $run = $this->tick($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(2, $this->dnsWrites);
        $this->assertSame(2, Attempt::count());
    }
    public function test_runner_stop_before_create_is_local_and_stale_jobs_cannot_restart_it(): void
    {
        $run = $this->runtimeStart(); $job = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        $view = $this->runner()->view(Execution::query()->find($run['id'])); $stopped = $this->stopRuntime($run);
        $this->assertSame('stopped', $stopped['state']); $this->assertFalse($stopped['can_stop']);
        $again = $this->runner()->stop($run['id'], $view['revision'], str_repeat('1', 32), $this->admin->id);
        $this->assertEquals($stopped, $again); $job->handle($this->runner()); $this->runner()->advance();
        $this->assertSame('cancelled', Member::query()->first()->phase); $this->assertSame(0, Attempt::count());
        $this->assertSame([], $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(1, DB::table('v2_machine_failover_runtime_event')->where('kind', 'stop_requested')->count());
    }
    public function test_runner_stop_during_preflight_prevents_the_create_post(): void
    {
        $run = $this->runtimeStart(); $this->duringPreflight = function () use ($run) {
            $this->duringPreflight = null; $this->assertSame('stopping', $this->stopRuntime($run)['state']);
        };
        $this->tick($run); $this->assertSame([], $this->posts); $this->assertSame('cancelled', $this->task()->status);
        $this->assertSame('stopped', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $this->assertSame(10000, Attempt::query()->sum('reserved_cents')); $this->assertSame(0, \App\Models\MachineFailoverBudgetRelease::count());
    }
    public function test_runner_stop_after_post_preserves_the_paid_candidate_without_dns_or_more_mutations(): void
    {
        $run = $this->runtimeStart(); $this->duringPost = function () use ($run) { $this->duringPost = null; $this->stopRuntime($run); };
        $this->tick($run); $task = $this->task();
        $this->assertNotNull($task->external_id); $this->assertSame('needs_review', $task->status);
        $this->assertSame('retained', Member::query()->first()->phase); $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->runner()->advance(); $this->assertCount(1, $this->posts); $this->assertSame(0, \App\Models\MachineFailoverBudgetRelease::count());
        $this->assertFalse(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
    }
    public function test_runner_unknown_create_stopped_during_post_stays_for_review_and_never_rotates(): void
    {
        $this->mode = 'unknown_absent'; $run = $this->runtimeStart();
        $this->duringPost = function () use ($run) { $this->duringPost = null; $this->stopRuntime($run); };
        $this->tick($run); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertNull($this->task()->external_id); $this->assertSame('needs_review', $this->task()->status);
        $this->runner()->advance(); $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }
    public function test_runner_recovers_lost_queue_receipts_and_rejects_the_superseded_ticket(): void
    {
        $run = $this->runtimeStart(); $old = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['next_dispatch_at' => time() - 1]);
        $this->assertSame(1, $this->runner()->advance()['queued']);
        $next = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last(); $this->assertGreaterThan($old->generation, $next->generation);
        $old->handle($this->runner()); $this->assertSame([], $this->posts);
        $next->handle($this->runner()); $this->assertCount(1, $this->posts);
    }
    public function test_runner_expired_worker_is_fenced_and_recovers_only_the_original_create_intent(): void
    {
        $run = $this->runtimeStart(); $old = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        $this->duringPost = function () use ($run) {
            $this->duringPost = null; \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['lease_until' => time() - 1]);
        };
        $old->handle($this->runner()); $this->assertSame('processing', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $this->runner()->advance(); $new = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        $old->handle($this->runner()); $new->handle($this->runner());
        $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count()); $this->assertSame('waiting_initialization', $this->task()->status);
    }
    public function test_runner_deadline_or_revoked_owner_stops_locally_without_new_cloud_intents(): void
    {
        $run = $this->runtimeStart(); Execution::query()->whereKey($run['id'])->update(['deadline_at' => time() - 1]);
        $this->runner()->advance(); $this->assertSame('stopped', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $this->assertSame([], $this->posts); $this->assertSame(0, Attempt::count());
    }
    public function test_runner_banned_owner_cannot_dispatch_but_another_active_admin_can_stop_the_run(): void
    {
        $run = $this->runtimeStart(); $this->admin->banned = true; $this->admin->save();
        $this->runner()->advance(); $this->assertSame([], $this->posts);
        $this->assertSame('permission_revoked', $this->runner()->view(Execution::query()->find($run['id']))['reason']);
        $this->code('permission_revoked', fn () => $this->stopRuntime($run));
    }
    public function test_runner_stop_invalid_revision_and_request_conflict_do_not_change_or_resume_the_run(): void
    {
        $run = $this->runtimeStart(); $view = $this->runner()->view(Execution::query()->find($run['id']));
        $this->code('runtime_changed', fn () => $this->runner()->stop($run['id'], $view['revision'] + 1, str_repeat('1', 32), $this->admin->id));
        $this->assertNull($this->runner()->view(Execution::query()->find($run['id']))['stop_requested_at']);
        $this->stopRuntime($run);
        $this->code('request_conflict', fn () => $this->runner()->stop($run['id'], 999, str_repeat('1', 32), $this->admin->id));
        $this->assertSame([], $this->posts);
    }
    public function test_runner_stopping_a_prepared_dns_transition_cancels_only_local_unsubmitted_work(): void
    {
        $run = $this->tick($this->runtimeStart()); $this->runtimeReady($run); $run = $this->tick($run);
        $dns = MachineDnsTransition::query()->firstOrFail(); $this->assertSame('prepared', $dns->state);
        $view = $this->stopRuntime($run); $this->assertSame('stopped', $view['state']);
        $this->assertSame('cancelled', $dns->fresh()->state); $this->assertSame('retained', Member::query()->first()->phase);
        $this->assertSame(0, $this->dnsWrites); $this->assertSame($this->fixtures[0]['sourceIp'], $this->fixtures[0]['dns']->fresh()->ip);
    }
    public function test_runner_stop_is_available_with_hard_gates_closed_and_direct_child_jobs_are_fenced(): void
    {
        $run = $this->tick($this->runtimeStart()); $requests = Http::recorded()->count();
        $task = $this->task(); $task->next_check_at = 0; $task->save();
        (new \App\Jobs\MachineProvisionJob($task->id))->handle(app(MachineProvisionService::class));
        $this->assertSame($requests, Http::recorded()->count());
        config(['machine_failover.runner_enabled' => false]); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $this->assertSame('stopped', $this->stopRuntime($run)['state']);
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }
    public function test_runner_disabling_during_preflight_prevents_a_new_create_boundary(): void
    {
        $run = $this->runtimeStart(); $this->duringPreflight = function () { $this->duringPreflight = null; config(['machine_failover.runner_enabled' => false]); };
        $this->tick($run); $this->assertSame([], $this->posts);
        $view = $this->runner()->view(Execution::query()->find($run['id']));
        $this->assertSame('stopped', $view['state']); $this->assertSame('runner_disabled', $view['reason']);
    }
    public function test_runner_queue_failure_keeps_a_recoverable_ticket_and_hides_transport_errors(): void
    {
        $run = $this->runtimeStart(); $original = Bus::getFacadeRoot();
        $failed = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $failed->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('PRIVATE_QUEUE_URL'));
        Bus::swap($failed);
        \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['next_dispatch_at' => time() - 1]);
        $this->runner()->advance(); $view = $this->runner()->view(Execution::query()->find($run['id']));
        $this->assertSame('queued', $view['state']); $this->assertSame('queue_unavailable', $view['reason']);
        $this->assertStringNotContainsString('PRIVATE_QUEUE_URL', json_encode($view));
        Bus::swap($original); \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['next_dispatch_at' => time() - 1]);
        $this->tick($run); $this->assertCount(1, $this->posts);
    }
    public function test_runner_stop_during_dns_preflight_prevents_the_patch(): void
    {
        $run = $this->tick($this->runtimeStart()); $this->runtimeReady($run); $run = $this->tick($run);
        $this->duringDnsRead = function () use ($run) { $this->duringDnsRead = null; $this->stopRuntime($run); };
        $this->tick($run); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame('cancelled', MachineDnsTransition::query()->first()->state);
        $this->assertSame('retained', Member::query()->first()->phase);
    }
    public function test_runner_stop_during_an_inflight_dns_patch_preserves_uncertainty_without_reversal(): void
    {
        $run = $this->tick($this->runtimeStart()); $this->runtimeReady($run); $run = $this->tick($run);
        $this->duringDnsWrite = function () use ($run) { $this->duringDnsWrite = null; $this->stopRuntime($run); };
        $this->tick($run); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame('needs_review', MachineDnsTransition::query()->first()->state);
        $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->runner()->advance(); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($this->fixtures[0]['sourceIp'], $this->fixtures[0]['dns']->fresh()->ip);
    }
    public function test_runner_expired_worker_recovers_an_unknown_post_without_creating_or_rotating_again(): void
    {
        $this->mode = 'unknown_success'; $run = $this->runtimeStart();
        $this->duringPost = function () use ($run) { $this->duringPost = null; \App\Models\MachineFailoverRuntime::query()->whereKey($run['id'])->update(['lease_until' => time() - 1]); };
        $this->tick($run); $this->assertNull($this->task()->external_id);
        $this->tick($run); $this->assertNotNull($this->task()->external_id);
        $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }
    public function test_runner_waits_for_an_old_member_lock_without_stopping_or_allocating_again(): void
    {
        $run = $this->runtimeStart(); $member = Member::query()->first();
        $lock = Cache::lock('machine-native:member:' . $member->id, 300); $this->assertTrue($lock->get());
        try {
            $this->tick($run); $view = $this->runner()->view(Execution::query()->find($run['id']));
            $this->assertNull($view['stop_requested_at']); $this->assertSame(0, Attempt::count()); $this->assertSame([], $this->posts);
            $this->assertTrue(DB::table('v2_machine_failover_runtime_event')->where('reason', 'worker_busy')->exists());
        } finally { $lock->release(); }
        $this->tick($run); $this->assertCount(1, $this->posts);
    }
    public function test_runner_stop_request_survives_worker_death_with_execution_disabled(): void
    {
        $run = $this->runtimeStart(); $lock = Cache::lock('machine-native:run:' . $run['id'], 120); $this->assertTrue($lock->get());
        try { $this->assertSame('stopping', $this->stopRuntime($run)['state']); } finally { $lock->release(); }
        config(['machine_failover.runner_enabled' => false]); app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        $result = $this->runner()->advance(true); $this->assertSame(1, $result['stopped']); $this->assertFalse($result['enabled']);
        $this->assertSame('stopped', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $this->assertSame([], $this->posts); $this->assertSame(0, Attempt::count());
    }
    public function test_runner_early_queue_delivery_cannot_execute_before_its_persisted_due_time(): void
    {
        $run = $this->tick($this->runtimeStart()); $job = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        $before = Http::recorded()->count(); $job->handle($this->runner());
        $this->assertSame($before, Http::recorded()->count()); $this->assertCount(1, $this->posts);
        $view = $this->runner()->view(Execution::query()->find($run['id']));
        foreach (['ticket_hash', 'ticket', 'request_key', 'intent_hash', 'stop_requested_by'] as $secret) $this->assertArrayNotHasKey($secret, $view);
    }
    public function test_runner_scheduler_keeps_local_stop_recovery_separate_from_disabled_execution(): void
    {
        $this->runtimeSchema();
        app()->instance(\Illuminate\Console\Scheduling\EventMutex::class, $this->createMock(\Illuminate\Console\Scheduling\EventMutex::class));
        app()->instance(\Illuminate\Console\Scheduling\SchedulingMutex::class, $this->createMock(\Illuminate\Console\Scheduling\SchedulingMutex::class));
        $backup = $this->createMock(\App\Services\Backup\BackupService::class); $backup->method('settings')->willReturn(['enabled' => false]);
        app()->instance(\App\Services\Backup\BackupService::class, $backup);
        app()->instance(\App\Services\Plugin\PluginManager::class, $this->createMock(\App\Services\Plugin\PluginManager::class));
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        foreach ([false, true] as $enabled) {
            config(['machine_failover.runner_enabled' => $enabled]); $schedule = new \Illuminate\Console\Scheduling\Schedule();
            $kernel = new \App\Console\Kernel(app(), $this->createMock(\Illuminate\Contracts\Events\Dispatcher::class));
            (new \ReflectionMethod($kernel, 'schedule'))->invoke($kernel, $schedule);
            $events = array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'machines:advance-native-failover')));
            $this->assertCount($enabled ? 2 : 1, $events);
            $this->assertCount(1, array_filter($events, fn ($event) => str_contains($event->command, '--stops-only')));
            foreach ($events as $event) { $this->assertTrue($event->onOneServer); $this->assertTrue($event->withoutOverlapping); }
            $command = new \App\Console\Commands\AdvanceMachineFailover(); $command->setLaravel(app());
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            $this->assertSame(0, $command->run(new \Symfony\Component\Console\Input\ArrayInput(['--stops-only' => true]), $output));
            $this->assertSame(['enabled' => false, 'queued' => 0, 'stopped' => 0], json_decode($output->fetch(), true));
            $this->assertFalse($this->runner()->advance()['enabled']);
        }
        $this->assertSame([], $this->posts); Bus::assertNotDispatched(\App\Jobs\MachineFailoverRunJob::class);
    }
    public function test_runner_missing_event_schema_never_turns_a_managed_task_into_a_legacy_worker(): void
    {
        $run = $this->tick($this->runtimeStart()); $task = $this->task(); $before = Http::recorded()->count();
        Schema::drop('v2_machine_failover_runtime_event');
        $this->assertSame($run['id'], $this->runner()->managed($task)); $this->assertFalse($this->runner()->workerOwns($task));
        $this->code('runtime_migration_required', fn () => $this->runner()->guard($run['id']));
        (new \App\Jobs\MachineProvisionJob($task->id))->handle(app(MachineProvisionService::class));
        $this->assertSame($before, Http::recorded()->count()); $this->assertFalse($this->runner()->view(Execution::query()->find($run['id']))['available']);
    }
    public function test_runner_missing_payload_marker_cannot_bypass_reverse_task_ownership(): void
    {
        $run = $this->tick($this->runtimeStart()); $task = $this->task(); $payload = $task->payload;
        unset($payload['coordinated_attempt']); $task->payload = $payload; $task->save(); $before = Http::recorded()->count();
        $this->assertSame($run['id'], $this->runner()->managed($task)); $this->assertFalse($this->runner()->workerOwns($task));
        (new \App\Jobs\MachineProvisionJob($task->id))->handle(app(MachineProvisionService::class));
        $this->stopRuntime($run); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertSame($before, Http::recorded()->count());
    }
    public function test_runner_stop_does_not_cancel_a_dns_transition_with_changed_lineage(): void
    {
        $run = $this->tick($this->runtimeStart()); $this->runtimeReady($run); $run = $this->tick($run);
        $transition = MachineDnsTransition::query()->firstOrFail(); $transition->from_ip = '192.0.2.254'; $transition->save();
        $before = Http::recorded()->count(); $this->stopRuntime($run);
        $this->assertSame('prepared', $transition->fresh()->state); $this->assertSame('needs_review', Member::query()->first()->phase);
        $this->assertSame($before, Http::recorded()->count()); $this->assertSame(0, $this->dnsWrites);
    }
    public function test_runner_recovers_a_crash_after_terminal_run_commit_without_replaying_work(): void
    {
        $run = $this->runtimeStart(); $job = Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class)->last();
        Execution::query()->whereKey($run['id'])->update(['status' => 'needs_review']);
        $this->runner()->advance(); $this->assertSame('finished', $this->runner()->view(Execution::query()->find($run['id']))['state']);
        $job->handle($this->runner()); $this->assertSame([], $this->posts); $this->assertSame(0, Attempt::count());
    }
    public function test_runner_http_stop_and_metadata_require_admin_and_reject_execution_options(): void
    {
        $controller = new \App\Http\Controllers\V2\Admin\Server\MachineFailoverBudgetController();
        foreach (['stop', 'runtime'] as $method) {
            $request = \Illuminate\Http\Request::create('/synthetic', 'POST', ['passed' => true, 'enabled' => true]);
            $request->setUserResolver(fn () => $this->admin);
            $this->assertSame(422, $controller->{$method}($request, $this->runner())->getStatusCode());
            $request->setUserResolver(fn () => new User(['is_admin' => false]));
            try { $controller->{$method}($request, $this->runner()); $this->fail('Expected admin denial.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $this->assertSame([], $this->posts);
    }
    public function test_runner_migration_downgrade_preserves_durable_tickets_and_stop_audit(): void
    {
        $this->runtimeStart(); $this->expectException(\RuntimeException::class);
        (require base_path('database/migrations/2026_11_07_220000_create_machine_failover_runtime.php'))->down();
    }
    public function test_runner_late_readback_cannot_overwrite_a_stop_settled_after_its_lock_expired(): void
    {
        $run = $this->runtimeStart();
        $this->duringInstanceRead = function () use ($run) {
            $this->duringInstanceRead = null;
            Cache::lock('machine-native:run:' . $run['id'], 120)->forceRelease();
            $this->assertSame('stopped', $this->stopRuntime($run)['state']);
        };
        $this->tick($run);
        $this->assertSame('needs_review', $this->task()->status); $this->assertSame('retained', Attempt::query()->first()->status);
        $this->assertSame('retained', Member::query()->first()->phase); $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $bridge = app(MachineFailoverNativeBridge::class);
        $this->code('native_stop_requested', fn () => (new \ReflectionMethod($bridge, 'phase'))->invoke($bridge, Member::query()->first(), 'creating'));
        $this->code('native_stop_requested', fn () => (new \ReflectionMethod($bridge, 'attemptStatus'))->invoke($bridge, Attempt::query()->first(), 'dispatched'));
        $this->assertSame('retained', Attempt::query()->first()->status); $this->assertSame('retained', Member::query()->first()->phase);
    }
    public function test_runner_stop_after_a_simulated_rotation_preserves_the_original_definite_rejection(): void
    {
        $this->mode = 'reject'; $run = $this->tick($this->runtimeStart()); $first = Attempt::query()->firstOrFail();
        $task = Task::query()->findOrFail($first->task_id); $identity = $task->only(['id', 'account_id', 'installation_id', 'status', 'reason', 'evidence']);
        $this->assertSame('rejected', $first->status); $this->assertSame('retry_ready', Member::query()->first()->phase);
        $run = $this->tick($run); $this->stopRuntime($run);
        $this->assertSame('rejected', $first->fresh()->status); $this->assertSame($identity, $task->fresh()->only(array_keys($identity)));
        $this->assertSame('retained', Member::query()->first()->phase); $this->assertCount(2, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(20000, Attempt::query()->sum('reserved_cents')); $this->assertSame(0, \App\Models\MachineFailoverBudgetRelease::count());
    }
    public function test_runner_accepts_mysql_object_key_normalization_without_relaxing_pinned_values(): void
    {
        $reorder = function ($value) use (&$reorder) {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($reorder, $value);
        };
        $roundTrip = function () use ($reorder) {
            foreach ([Execution::class, Member::class, Attempt::class, Task::class] as $model) foreach ($model::query()->get() as $row) {
                $row->payload = $reorder($row->payload); $row->save();
            }
        };
        $run = $this->runtimeStart(); $roundTrip(); $run = $this->tick($run);
        $this->assertCount(1, $this->posts); $roundTrip(); $this->runtimeReady($run); $run = $this->tick($run);
        $this->assertSame('awaiting_dns', $run['members'][0]['phase'], $run['members'][0]['reason'] ?? 'no reason');
        $roundTrip(); $run = $this->tick($run); $this->assertSame('completed', $run['status']);
    }
    public function test_replacement_validation_preserves_native_pool_identity_after_json_key_reordering(): void
    {
        $this->advance($this->start()); $task = $this->task(); $attempt = Attempt::query()->firstOrFail();
        $input = ProvisionPlan::normalize($task->payload['plan']); $payload = $attempt->payload;
        $payload['plan']['credential_pool'] = array_reverse($payload['plan']['credential_pool'], true); $attempt->payload = $payload; $attempt->save();
        $member = Member::query()->firstOrFail();
        $result = app(MachineReplacementService::class)->validate($member->plan_id, $input, $task, $attempt->fresh());
        $this->assertSame($member->episode_key, $result['episode_key']); $this->assertCount(1, $this->posts);
    }

    private function automaticAdmission(): array
    {
        $this->runtimeSchema();
        (require base_path('database/migrations/2026_11_08_000000_create_machine_failover_trigger_previews.php'))->up();
        $this->enable(); config(['app.key' => str_repeat('g', 32), 'machine_failover.runner_enabled' => true, 'machine_failover.preview_enabled' => true]);
        $finance = app(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class);
        $finance->shouldReceive('available')->andReturnTrue();
        $finance->shouldReceive('forecast')->andReturn(['state' => 'verified', 'available_cents' => 100000, 'checked_at' => time(), 'valid_until' => time() + 300]);
        $registry = app(MachineFailoverServiceRegistry::class);
        $service = $registry->save(['name' => 'Automatic fixture', 'max_parallel' => 1, 'attempt_limit' => 2, 'monthly_cap_usd' => 400,
            'members' => array_map(fn ($f) => ['policy_id' => $f['policy']->id, 'policy_revision' => 1, 'name' => $f['machine']->name,
                'priority' => $f['machine']->id, 'enabled' => true], $this->fixtures)], $this->admin->id);
        $collected = app(\App\Services\ServerMachine\Cloud\MachineFailoverTriggerPreview::class)->collect();
        $this->assertSame(0, $collected['errors']);
        $pin = ['service_id' => $service['id'], 'service_revision' => 1, 'admin_id' => $this->admin->id,
            'issued_at' => time() - 1, 'expires_at' => time() + 600,
            'context_hash' => $registry->fingerprint($registry->snapshot($service['id'], 1)),
            'release' => $this->release->only(['id', 'component', 'platform', 'version', 'sha256', 'binary_sha256'])];
        config(['machine_failover.admission_enabled' => true, 'machine_failover.admission_authorizations' => [$pin]]);
        $result = app(\App\Services\ServerMachine\Cloud\MachineFailoverAdmission::class)->advance();
        $this->assertSame(1, $result['started'], json_encode($result));
        return app(MachineFailoverNativeBridge::class)->report($result['services'][0]['execution_id'], $this->admin->id);
    }
    public function test_automatic_admission_runs_the_real_bridge_runner_and_signed_receipts_with_mocked_transports(): void
    {
        $run = $this->automaticAdmission();
        $again = app(\App\Services\ServerMachine\Cloud\MachineFailoverAdmission::class)->advance();
        $this->assertSame(1, $again['resumed']); $this->assertSame(1, Execution::count());
        $this->assertSame($run['id'], $again['services'][0]['execution_id']);
        $this->assertCount(1, Bus::dispatched(\App\Jobs\MachineFailoverRunJob::class));
        $run = $this->tick($run); $this->runtimeReady($run); $run = $this->tick($run); $run = $this->tick($run);
        $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->verifications); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame(1, Attempt::count()); Bus::assertNotDispatched(\App\Jobs\MachineProvisionJob::class);
        $this->assertFalse(app()->bound(\App\Services\ServerMachine\Cloud\MachineFailoverLease::class));
    }
    public function test_automatic_authorization_revoked_during_create_preflight_prevents_the_post(): void
    {
        $run = $this->automaticAdmission();
        $this->duringPreflight = function () { $this->duringPreflight = null; config(['machine_failover.admission_authorizations' => []]); };
        $this->tick($run); $this->assertCount(0, $this->posts); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(1, Execution::count());
        $this->assertSame(0, app(\App\Services\ServerMachine\Cloud\MachineFailoverAdmission::class)->advance()['checked']);
    }
    public function test_automatic_authorization_revoked_during_dns_preflight_prevents_the_patch(): void
    {
        $run = $this->tick($this->automaticAdmission()); $this->runtimeReady($run); $run = $this->tick($run);
        $this->duringDnsRead = function () { $this->duringDnsRead = null; config(['machine_failover.admission_authorizations' => []]); };
        $this->tick($run); $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $this->assertSame(1, Execution::count()); $this->assertSame(1, Attempt::count());
    }
    public function test_archive_digest_change_cannot_reuse_a_native_release_pin(): void
    {
        $run = $this->start(); $this->release->fill(['sha256' => str_repeat('c', 64)])->save(); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame('release_changed', $run['members'][0]['reason']);
        $this->assertCount(0, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }
}
