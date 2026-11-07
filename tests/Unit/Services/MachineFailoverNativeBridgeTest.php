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
                if (preg_match('~/linode/instances/(\d+)$~', $url, $m)) return Http::response($this->remote[(int) $m[1]]);
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
}
