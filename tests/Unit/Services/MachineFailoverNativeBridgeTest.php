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
    private $duringVerification = null;
    private bool $dualStack = false;
    private string $addressFailure = '';
    private array $challenges = [];

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('n', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $budget = \Mockery::mock(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class);
        $budget->shouldNotReceive('forecast');
        app()->instance(\App\Services\ServerMachine\Cloud\MachineFailoverBudget::class, $budget);
        foreach (['machine_provision', 'machine_failover', 'machine_acceptance'] as $key) config([$key => require base_path('config/' . $key . '.php')]);
        foreach (['2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_170000_create_machine_cloud_tables.php',
            '2026_11_05_200000_create_machine_operation_notices.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_010000_add_machine_cloud_providers.php', '2026_11_06_020000_create_machine_cloud_groups.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_040000_create_machine_failover_observations.php',
            '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php', '2026_11_07_090000_create_machine_replacement_tasks.php',
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

    private function fixture(int $n, string $provider = 'linode', bool $initiallyUnreachable = false): array
    {
        [$region, $size, $image] = match ($provider) {
            'digitalocean' => ['sgp1', 's-1vcpu-1gb', 'debian-12-x64'], 'vultr' => ['40', 'vc2-1c-1gb', '2136'],
            default => ['sg-sin-2', 'g6-standard-4', 'linode/debian12'],
        };
        if ($provider !== 'linode') foreach (['regions' => $region, 'sizes' => $size, 'images' => $image] as $key => $value)
            config(['machine_provision.providers.' . $provider . '.' . $key => [$value]]);
        $machine = ServerMachine::query()->create(['name' => 'Isolated fixture ' . $n, 'token' => 'synthetic-machine-' . $n, 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => $n, 'machine_id' => $machine->id, 'enabled' => true, 'type' => 'hysteria', 'port' => 8443, 'server_port' => 8443]);
        $group = MachineCloudGroup::query()->create(['name' => 'Fixture pool ' . $n, 'name_key' => hash('sha256', 'pool' . $n), 'revision' => 1]);
        $accounts = [];
        foreach (['a', 'b'] as $letter) {
            $account = Account::query()->create(['name' => 'Fixture ' . $n . $letter, 'provider' => $provider, 'group_id' => $group->id,
                'group_revision' => 1, 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-token-' . $n . $letter]]);
            app(MachineCloudCredentialHealth::class)->verify($account->id, 1, $this->admin->id); $accounts[] = $account;
        }
        $pool = app(MachineFailoverCredentials::class)->pool($group->id, $provider); $fingerprint = hash('sha256', 'synthetic-catalog-' . $n);
        Cache::put('machine-cloud:catalog:' . $pool['fingerprint'], ['checked_at' => time(), 'expires_at' => time() + 600, 'fingerprint' => $fingerprint,
            'regions' => [['id' => $region]], 'sizes' => [['id' => $size, 'regions' => [$region], 'disk_gib' => 80]],
            'images' => [['id' => $image, 'regions' => [$region], 'min_disk_gib' => 4]]], 600);
        $credential = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS ' . $n, 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-' . $n]]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Fixture ' . $n, 'machine_id' => $machine->id, 'provider' => $provider,
            'credential_group_id' => $group->id, 'catalog_fingerprint' => $fingerprint, 'region' => $region, 'size' => $size,
            'image' => $image, 'failure_threshold' => 2, 'stale_after_seconds' => 300, 'cooldown_seconds' => 300,
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
        foreach ([-12 => !$initiallyUnreachable, -7 => false, -2 => false] as $delta => $healthy) $this->sourceReport($fixture, $healthy, time() + $delta);
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
        $addresses = ['public_ipv4' => $ip];
        if ($this->dualStack && ($v6 = array_values(array_filter($task->public_ips ?? [], fn ($v) => str_contains($v, ':'))))) $addresses['public_ipv6'] = $v6[0];
        app(MachineAddressInventory::class)->record($f['machine'], $task->installation_id, $addresses, $at,
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
            'machine_failover.allow_rotation' => true, 'machine_failover.allowed_rotation_policy_ids' => array_map(fn ($f) => $f['policy']->id, $this->fixtures),
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
        $provider = $task->payload['plan']['provider'] ?? 'linode'; $request = $task->payload['request'];
        $data = Yaml::parse($provider === 'digitalocean' ? $request['user_data'] : base64_decode($provider === 'vultr' ? $request['user_data'] : $request['metadata']['user_data']));
        preg_match('/Bearer ([a-f0-9]{64})/', $data['write_files'][2]['content'], $match);
        return $match[1];
    }

    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($this->dualStack) $this->assertSame(0, DB::transactionLevel(), 'Native provider/verifier I/O must not hold SQL locks');
            $url = $request->url();
            if (str_starts_with($url, 'https://api.digitalocean.com/') || str_starts_with($url, 'https://api.vultr.com/')) return $this->jsonProvider($request);
            if (str_starts_with($url, 'https://api.linode.com/')) {
                if ($request->method() === 'POST') {
                    $token = $request->header('Authorization')[0]; $this->posts[] = [$token, $request['label']];
                    if ($this->duringPost) ($this->duringPost)();
                    if ($this->mode === 'reject_all' || $this->mode === 'reject' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'fixture denial']]], 401);
                    if ($this->mode === 'billing' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'fixture billing']]], 402);
                    if ($this->mode === 'quota' && count($this->posts) === 1) return Http::response(['errors' => [['reason' => 'quota exceeded']]], 422);
                    if ($this->mode === 'rate') return Http::response(['errors' => [['reason' => 'fixture rate']]], 429, ['Retry-After' => '120']);
                    $row = ['id' => 1000 + count($this->posts), 'label' => $request['label'], 'tags' => $request['tags'], 'region' => $request['region'],
                        'type' => $request['type'], 'image' => $request['image'], 'created' => gmdate('Y-m-d\TH:i:s'), 'status' => 'running',
                        'ipv4' => [count($this->posts) === 1 ? '8.8.4.4' : '9.9.9.11'], 'ipv6' => $this->dualStack ? '2600:3c00::' . (100 + count($this->posts)) : ''];
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
                $this->challenges[] = $challenge;
                $echo = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'bytes' => 96, 'sha256' => $challenge['echo_sha256']];
                $reply = ['schema' => $challenge['schema'], 'challenge_id' => $challenge['challenge_id'], 'request_sha256' => hash('sha256', $request->body()),
                    'finished_at' => time(), 'cleanup_complete' => true, 'results' => array_map(fn ($node) => ['node_id' => $node['id'],
                        'endpoint_authenticated' => true, 'tcp' => $echo, 'udp' => $echo], $challenge['nodes'])];
                if ($this->mode === 'bad_receipt') $reply['challenge_id'] = str_repeat('0', 32);
                if ($challenge['schema'] === 2) {
                    foreach (['address_family', 'target_ip', 'task_id', 'installation_id', 'attempt_id', 'native_context_sha256',
                        'source_installation_id', 'session', 'binary_sha256', 'configuration_fingerprint'] as $field) $reply[$field] = $challenge[$field];
                    if ($this->addressFailure === 'legacy') $reply['schema'] = 1;
                    elseif ($this->addressFailure === 'udp_missing') $reply['results'][0]['udp']['received'] = 2;
                    elseif ($this->addressFailure === 'cleanup') $reply['cleanup_complete'] = false;
                    elseif ($this->addressFailure !== '') $reply[$this->addressFailure] = 'foreign-receipt';
                }
                if ($this->duringVerification) ($this->duringVerification)($challenge);
                $raw = json_encode($reply);
                return Http::response($raw, 200, ['X-Keli-Signature' => hash_hmac('sha256', "response\n" . $raw, config('machine_acceptance.verifier_key'))]);
            }
            if ($url === 'https://alidns.aliyuncs.com/') {
                $this->assertSame(0, DB::transactionLevel(), 'Provider I/O must not hold database locks');
                if ($request['Action'] === 'DescribeSubDomainRecords') {
                    if ($this->duringDnsRead) ($this->duringDnsRead)();
                    $rows = array_values(array_filter($this->records, fn ($r) => isset($r['DomainName']) && $r['RR'] . '.' . $r['DomainName'] === $request['SubDomain']));
                    return Http::response(['RequestId' => 'fixture-read', 'PageNumber' => 1, 'PageSize' => 50,
                        'TotalCount' => count($rows), 'DomainRecords' => ['Record' => $rows]]);
                }
                $this->assertSame('UpdateDomainRecord', $request['Action']); $this->dnsWrites++;
                $id = $request['RecordId']; foreach (['RR', 'Type', 'Value', 'TTL', 'Line'] as $field) $this->records[$id][$field] = $request[$field];
                if ($this->duringDnsWrite) ($this->duringDnsWrite)();
                return Http::response(['RequestId' => 'fixture-write', 'RecordId' => $id]);
            }
            if (str_starts_with($url, 'https://api.cloudflare.com/')) {
                if ($request->method() === 'GET') {
                    if ($this->duringDnsRead) ($this->duringDnsRead)();
                    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
                    $hostname = $query['name_exact'] ?? $query['name'] ?? null;
                    $rows = array_values(array_filter($this->records, fn ($row) => $hostname === null || $row['name'] === $hostname));
                    return Http::response(['success' => true, 'result' => $rows,
                        'result_info' => ['total_pages' => 1, 'total_count' => count($rows), 'page' => 1, 'per_page' => 50]]);
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

    private function jsonProvider($request)
    {
        $provider = str_contains($request->url(), 'api.digitalocean.com') ? 'digitalocean' : 'vultr';
        $path = substr(parse_url($request->url(), PHP_URL_PATH), 3);
        if ($path === '/account') return Http::response(['account' => $provider === 'digitalocean'
            ? ['status' => 'active', 'uuid' => 'fixture-account-' . substr(hash('sha256', $request->header('Authorization')[0]), 0, 16)] : ['balance' => 20]]);
        if ($request->method() === 'POST') {
            $this->assertSame($provider === 'digitalocean' ? '/droplets' : '/instances', $path);
            $label = $request[$provider === 'digitalocean' ? 'name' : 'label'];
            $this->posts[] = [$request->header('Authorization')[0], $label];
            if ($this->mode === 'reject_all' || $this->mode === 'reject' && count($this->posts) === 1) return Http::response([], 401);
            if ($this->mode === 'billing' && count($this->posts) === 1) return Http::response([], 402);
            if ($this->mode === 'quota' && count($this->posts) === 1) return Http::response([$provider === 'digitalocean' ? 'message' : 'error' => 'Instance limit reached'], 422);
            if ($this->mode === 'rate') return Http::response([], 429, ['Retry-After' => '120']);
            $n = count($this->posts);
            $row = ['tags' => $request['tags'], 'features' => ['ipv6']] + ($provider === 'digitalocean'
                ? ['id' => 2000 + $n, 'name' => $label, 'region' => ['slug' => $request['region']], 'size_slug' => $request['size'],
                    'image' => ['slug' => $request['image']], 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'status' => 'active', 'locked' => false,
                    'volume_ids' => [], 'networks' => ['v4' => [['ip_address' => '8.8.4.4', 'type' => 'public']]]]
                : ['id' => sprintf('20000000-0000-4000-8000-%012d', $n), 'label' => $label, 'region' => $request['region'], 'plan' => $request['plan'],
                    'os_id' => $request['os_id'], 'date_created' => gmdate('Y-m-d H:i:s'), 'status' => 'active', 'server_status' => 'ok',
                    'power_status' => 'running', 'main_ip' => '8.8.4.4', 'default_password' => 'never-export-this']);
            if ($this->dualStack) {
                if ($provider === 'digitalocean') $row['networks']['v6'] = [['ip_address' => '2600:3c00::100', 'type' => 'public']];
                else $row['v6_main_ip'] = '2600:3c00::100';
            }
            if ($this->mode !== 'unknown_absent') $this->remote[$row['id']] = $row;
            if (str_starts_with($this->mode, 'unknown')) return Http::response([], 503);
            return Http::response([$provider === 'digitalocean' ? 'droplet' : 'instance' => $row], 202);
        }
        $this->assertSame('GET', $request->method());
        $key = ltrim($path, '/');
        $catalog = $provider === 'digitalocean' ? [
            'regions' => [['slug' => 'sgp1', 'name' => 'Singapore', 'available' => true]],
            'sizes' => [['slug' => 's-1vcpu-1gb', 'available' => true, 'regions' => ['sgp1'], 'vcpus' => 1, 'memory' => 1024, 'disk' => 25, 'price_monthly' => 6]],
            'images' => [['slug' => 'debian-12-x64', 'name' => 'Debian', 'public' => true, 'type' => 'base', 'status' => 'available', 'regions' => ['sgp1'], 'min_disk_size' => 4]],
        ] : ['regions' => [['id' => '40', 'city' => 'Singapore']],
            'plans' => [['id' => 'vc2-1c-1gb', 'type' => 'vc2', 'locations' => ['40'], 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 5]],
            'os' => [['id' => 2136, 'name' => 'Debian', 'arch' => 'x64', 'family' => 'debian']]];
        if (isset($catalog[$key])) return Http::response([$key => $catalog[$key], 'meta' => ['total' => count($catalog[$key])]]);
        if (str_ends_with($path, '/availability')) return Http::response(['available_plans' => ['vc2-1c-1gb']]);
        if (preg_match('~^/(?:droplets|instances)/([^/]+)$~', $path, $m)) return Http::response([$provider === 'digitalocean' ? 'droplet' : 'instance' => $this->remote[$m[1]]]);
        if (in_array($path, ['/droplets', '/instances'], true)) {
            $rows = array_values(array_filter($this->remote, fn ($row) => $provider === 'digitalocean' ? isset($row['size_slug']) : isset($row['os_id'])));
            return Http::response([$provider === 'digitalocean' ? 'droplets' : 'instances' => $rows, 'meta' => ['total' => count($rows)]]);
        }
        throw new \RuntimeException('Unmocked JSON provider transport');
    }

    public static function jsonProviders(): array { return [['digitalocean'], ['vultr']]; }

    private function startSets(array $types = ['A', 'AAAA'], string $dnsProvider = 'cloudflare', int $setCount = 1, bool $legacyDns = true): array
    {
        foreach (['2026_11_09_180000_create_machine_failover_dns_sets.php', '2026_11_09_200000_create_machine_dns_set_runtime.php',
            '2026_11_10_010000_create_machine_native_address_proofs.php'] as $file) (require base_path('database/migrations/' . $file))->up();
        config(['app.key' => str_repeat('n', 32)]); $this->dualStack = in_array('AAAA', $types, true); $this->enable();
        foreach ($this->fixtures as $i => $f) {
            if (!$legacyDns) {
                $f['policy']->fill(['dns_credential_id' => null, 'dns_credential_revision' => null, 'dns_hostname' => '', 'dns_scope' => null])->save();
                MachineOwnedDns::query()->where('policy_id', $f['policy']->id)->delete();
            }
            $task = $f['source']; $ips = [$f['sourceIp']]; if ($this->dualStack) $ips[] = '2600:3c00::' . ($i + 1);
            $task->fill(['external_id' => (string) (900 + $i), 'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400), 'public_ips' => $ips])->save();
            $resource = \App\Models\MachineCloudResource::query()->create(['account_id' => $task->account_id, 'provider' => $f['accounts'][0]->provider,
                'external_id' => $task->external_id, 'provider_created_at' => $task->provider_created_at, 'public_ips' => $ips, 'status' => 'running',
                'label' => $task->marker, 'region' => $f['policy']->region, 'size' => $f['policy']->size,
                'machine_id' => $task->machine_id, 'installation_id' => $task->installation_id, 'machine_authority' => $task->machine_authority, 'observed_at' => time()]);
            MachineOwnedInstance::query()->where('task_id', $task->id)->update(['resource_id' => $resource->id]);
        }
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'Native sets fixture', 'max_parallel' => count($this->fixtures),
            'attempt_limit' => 4, 'monthly_cap_usd' => 400, 'members' => array_map(fn ($f) => ['policy_id' => $f['policy']->id,
                'policy_revision' => $f['policy']->revision, 'name' => $f['machine']->name, 'priority' => $f['machine']->id, 'enabled' => true], $this->fixtures)], $this->admin->id);
        $credential = $dnsProvider === 'cloudflare' ? MachineDnsCredential::query()->first() : MachineDnsCredential::query()->create([
            'name' => 'Aliyun fixture', 'provider' => 'aliyun', 'revision' => 1, 'credentials' => ['access_key_id' => 'synthetic', 'access_key_secret' => 'synthetic']]);
        $input = ['service_id' => $service['id'], 'service_revision' => 1,
            'credential_id' => $credential->id, 'credential_revision' => 1, 'zone' => $dnsProvider === 'cloudflare' ? str_repeat('c', 32) : 'example.test',
            'zone_name' => 'example.test', 'hostname' => 'native-sets.example.test', 'ttl' => $dnsProvider === 'cloudflare' ? 60 : 600,
            'members' => array_map(fn ($m) => ['member_id' => $m['id'], 'types' => $types, 'lines' => $dnsProvider === 'cloudflare'
                ? ['default'] : ['default', 'telecom', 'unicom']], $service['members'])];
        for ($i = 0; $i < $setCount; $i++) {
            $input['service_revision'] = 1 + $i; $input['hostname'] = 'native-sets' . ($i ? '-' . $i : '') . '.example.test';
            $view = app(\App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry::class)->save($input, $this->admin->id);
        }
        foreach ($view['sets'] as $set) foreach (\App\Models\MachineFailoverDnsSlot::query()->where('set_id', $set['id'])->get() as $slot) {
            $index = array_search($slot['member_id'], array_column($service['members'], 'id'), true);
            $ip = $slot['type'] === 'A' ? $this->fixtures[$index]['sourceIp'] : '2600:3c00::' . ($index + 1);
            $id = $dnsProvider === 'cloudflare' ? hash('md5', (string) $slot['id']) : (string) (5000 + $slot['id']);
            $this->records[$id] = $dnsProvider === 'cloudflare' ? ['id' => $id, 'type' => $slot['type'], 'content' => $ip, 'name' => $set['hostname'],
                'ttl' => 60, 'proxied' => false, 'locked' => false, 'comment' => $slot['marker']] : ['RecordId' => $id, 'Type' => $slot['type'], 'Value' => $ip,
                    'RR' => substr($set['hostname'], 0, -strlen('.example.test')), 'DomainName' => 'example.test', 'Line' => $slot['line'], 'TTL' => 600, 'Remark' => $slot['marker'], 'Status' => 'ENABLE', 'Locked' => false];
        }
        return app(MachineFailoverNativeBridge::class)->start($service['id'], $view['service_revision'], array_map(fn ($f) => MachineFailoverPlan::query()
            ->where('policy_id', $f['policy']->id)->latest('id')->value('id'), $this->fixtures), $this->release->id, str_repeat('d', 32), $this->admin->id);
    }
    private function finishSets(array $run): array
    {
        for ($i = 0; $i < 45 && $run['status'] === 'running'; $i++) $run = $this->advance($run);
        return $run;
    }
    public function test_native_record_set_pins_independent_signed_address_family_evidence(): void
    {
        $run = $this->ready($this->advance($this->startSets()));
        $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame([1, 2, 2], array_column($this->challenges, 'schema'));
        $this->assertSame(['A', 'AAAA'], array_column(array_slice($this->challenges, 1), 'address_family'));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(2, \App\Models\MachineNativeAddressProof::count());
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(2, $this->dnsWrites); $this->assertSame('accepted', $this->task()->status);
        $this->assertTrue(app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->completionCurrent(Attempt::query()->first(), $this->task()));
        $completion = new \ReflectionMethod(MachineReplacementService::class, 'completed');
        $row = (new \App\Models\MachineReplacementTask())->forceFill(['policy_id' => $this->fixtures[0]['policy']->id, 'policy_revision' => 1]);
        $this->assertTrue($completion->invoke(app(MachineReplacementService::class), $row, $this->task()));
        $row->policy_id = 999; $this->assertFalse($completion->invoke(app(MachineReplacementService::class), $row, $this->task()));
        $this->assertSame($run, $this->advance($run)); $this->assertCount(1, $this->posts);
    }
    public function test_native_a_only_record_set_does_not_require_ipv6_or_accept_legacy_receipts(): void
    {
        $run = $this->ready($this->advance($this->startSets(['A'])));
        $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(['A'], array_column(array_slice($this->challenges, 1), 'address_family'));
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(1, $this->dnsWrites);
    }
    public function test_native_aliyun_three_lines_publish_only_after_both_family_proofs(): void
    {
        $run = $this->ready($this->advance($this->startSets(['A', 'AAAA'], 'aliyun')));
        $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(6, $this->dnsWrites); $this->assertCount(1, $this->posts); $this->assertSame(3, $this->verifications);
    }
    public function test_service_aliyun_scope_needs_no_dummy_cloudflare_or_legacy_owned_dns(): void
    {
        $run = $this->startSets(['A', 'AAAA'], 'aliyun', 1, false); $parent = Execution::query()->first();
        $this->assertSame(0, MachineOwnedDns::count());
        $raw = app(MachineFailoverPolicyService::class)->localChecks($this->fixtures[0]['policy']);
        $this->assertContains('dns_not_configured', array_column($raw, 'code'));
        $unregistered = $parent->payload['snapshot']; $unregistered['dns_sets'][0]['credential_revision']++;
        $this->code('native_context_changed', fn () => app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->policyChecks($this->fixtures[0]['policy'], $raw, $unregistered));
        $view = app(\App\Services\ServerMachine\Cloud\MachineFailoverShadow::class)->report($parent->service_id, $parent->service_revision, $this->admin->id);
        $this->assertSame('would_replace', $view['members'][0]['decision'], json_encode($view));
        $run = $this->ready($this->advance($run)); $run = $this->finishSets($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(6, $this->dnsWrites);
        $this->assertSame(0, MachineOwnedDns::count()); $this->assertCount(1, $this->posts);
    }
    #[DataProvider('jsonProviders')]
    public function test_native_json_provider_record_set_uses_the_same_identity_and_dual_stack_admission(string $provider): void
    {
        $this->fixtures = [$this->fixture(2, $provider)];
        $run = $this->ready($this->advance($this->startSets())); $run = $this->finishSets($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(2, $this->dnsWrites);
        $this->assertSame(['A', 'AAAA'], array_column(array_slice($this->challenges, 1), 'address_family'));
        $this->assertSame($provider, \App\Models\MachineCloudResource::query()->where('external_id', $this->task()->external_id)->value('provider'));
    }
    public function test_native_two_members_share_one_set_without_overwriting_the_other_member(): void
    {
        $this->fixtures[] = $this->fixture(2);
        $run = $this->advance($this->startSets()); $run = $this->ready($run); $run = $this->ready($run, 1);
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(4, $this->dnsWrites); $this->assertSame(4, \App\Models\MachineNativeAddressProof::count());
        $this->assertCount(2, $this->posts);
        foreach (Attempt::query()->get() as $attempt) $this->assertTrue(app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)
            ->completionCurrent($attempt, Task::query()->findOrFail($attempt->task_id)));
    }
    public static function changedContexts(): array
    {
        return array_map(fn ($kind) => [$kind], ['expired', 'seal', 'origin', 'proof_links', 'source', 'task_ip', 'task_identity', 'resource',
            'authority', 'session', 'configuration', 'release', 'dns_binding', 'publication', 'initial_inventory', 'dns_permission', 'verification_permission',
            'candidate_retained', 'candidate_accepted', 'resource_stopped', 'manual_hold', 'dns_hold']);
    }
    #[DataProvider('changedContexts')]
    public function test_native_context_or_publication_changes_after_proof_cannot_write_dns(string $kind): void
    {
        $run = $this->ready($this->advance($this->startSets())); $task = $this->task();
        $proof = \App\Models\MachineNativeAddressProof::query()->first(); $attempt = Attempt::query()->first();
        $dns = \App\Models\MachineFailoverDnsRun::query()->first();
        if ($kind === 'expired') $proof->fill(['valid_until' => time() - 1])->save();
        elseif ($kind === 'seal') $proof->fill(['seal' => str_repeat('0', 64)])->save();
        elseif ($kind === 'origin') { $p = $proof->payload; $p['origin'] = 'synthetic'; $proof->payload = $p; $proof->save(); }
        elseif ($kind === 'proof_links') { $p = $attempt->payload; $p['address_proofs'] = array_reverse($p['address_proofs'], true); $attempt->payload = $p; $attempt->save(); }
        elseif ($kind === 'source') MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->increment('revision');
        elseif ($kind === 'task_ip') $task->fill(['public_ips' => ['8.8.4.4', '2600:3c00::999']])->save();
        elseif ($kind === 'task_identity') $task->fill(['provider_created_at' => '2000-01-01T00:00:00Z'])->save();
        elseif ($kind === 'resource') \App\Models\MachineCloudResource::query()->where('external_id', $task->external_id)->update(['provider_created_at' => '2000-01-01T00:00:00Z']);
        elseif ($kind === 'authority') $this->fixtures[0]['machine']->fill(['token' => 'changed-synthetic-authority'])->save();
        elseif ($kind === 'session') {
            app(MachineAddressInventory::class)->record($this->fixtures[0]['machine'], $task->installation_id,
                ['public_ipv4' => $task->public_ips[0], 'public_ipv6' => $task->public_ips[1]], time() + 1,
                ['version' => $this->release->version, 'binary_sha256' => $this->release->binary_sha256,
                    'observation_session' => str_repeat('f', 32), 'configured_nodes' => [['id' => 1, 'protocol' => 'hysteria2', 'port' => 8443]]]);
        } elseif ($kind === 'configuration') DB::table('v2_server')->where('id', 1)->update(['server_port' => 9443]);
        elseif ($kind === 'release') $this->release->fill(['binary_sha256' => str_repeat('c', 64)])->save();
        elseif ($kind === 'dns_permission') config(['machine_acceptance.allow_dns' => false]);
        elseif ($kind === 'verification_permission') config(['machine_acceptance.allow_verification' => false]);
        elseif ($kind === 'candidate_retained') MachineOwnedInstance::query()->where('task_id', $task->id)->update(['status' => 'retained']);
        elseif ($kind === 'candidate_accepted') $task->fill(['status' => 'accepted'])->save();
        elseif ($kind === 'resource_stopped') \App\Models\MachineCloudResource::query()->where('external_id', $task->external_id)->update(['status' => 'offline']);
        elseif (in_array($kind, ['manual_hold', 'dns_hold'], true)) \App\Models\MachineCloudResource::query()->where('external_id', $task->external_id)->update([$kind => false]);
        else {
            $p = $dns->payload;
            if ($kind === 'dns_binding') $p['native_binding']['attempt_id'] = (string) Str::uuid();
            elseif ($kind === 'publication') $p['plan']['actions'][0]['after']['content'] = '1.0.0.1';
            else $p['initial'] = [];
            $dns->payload = $p; $dns->save();
        }
        $run = $this->finishSets($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts); $this->assertSame(3, $this->verifications);
    }
    public function test_ipv6_must_be_fresh_and_reported_by_the_candidate_installation(): void
    {
        $run = $this->advance($this->startSets());
        $this->duringVerification = function ($challenge) {
            if ($challenge['schema'] !== 1) return;
            $task = $this->task(); $this->dualStack = false;
            $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        };
        $run = $this->ready($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, \App\Models\MachineNativeAddressProof::count());
    }
    public function test_context_change_while_ipv6_measurement_is_running_rejects_the_late_receipt(): void
    {
        $run = $this->advance($this->startSets());
        $this->duringVerification = function ($challenge) {
            if (($challenge['address_family'] ?? null) === 'AAAA') MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->increment('revision');
        };
        $run = $this->ready($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(['passed', 'failed'], \App\Models\MachineNativeAddressProof::query()->orderBy('family')->pluck('state')->all());
    }
    public function test_lost_dns_acknowledgement_resumes_only_the_original_journal(): void
    {
        $run = $this->ready($this->advance($this->startSets())); $this->mode = 'dns_unknown';
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(2, $this->dnsWrites); $this->assertSame(1, \App\Models\MachineFailoverDnsRun::count());
        $this->assertSame(3, $this->verifications); $this->assertCount(1, $this->posts);
    }
    public function test_native_bridge_waits_for_dns_due_time_without_polling_cloud_or_verifier(): void
    {
        $run = $this->ready($this->advance($this->startSets()));
        $child = \App\Models\MachineDnsSetRuntime::query()->first(); $child->fill(['next_check_at' => time() + 60])->save();
        $calls = Http::recorded()->count();
        for ($i = 0; $i < 3; $i++) $run = $this->advance($run);
        $this->assertSame('running', $run['status']); $this->assertSame($calls, Http::recorded()->count());
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(3, $this->verifications);
        $child->fill(['next_check_at' => null])->save(); $run = $this->finishSets($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(2, $this->dnsWrites);
    }
    public function test_dns_receipt_from_a_revoked_lease_cannot_commit_and_recovery_does_not_rewrite(): void
    {
        $run = $this->ready($this->advance($this->startSets()));
        $this->duringDnsWrite = function () {
            $this->duringDnsWrite = null;
            \App\Models\MachineDnsSetRuntime::query()->update(['generation' => 99, 'lease_hash' => null, 'lease_until' => null]);
        };
        $run = $this->advance($run); $dns = \App\Models\MachineFailoverDnsRun::query()->first();
        $this->assertSame('dispatched', $dns->payload['plan']['actions'][0]['state']);
        $this->assertSame(0, $dns->payload['writes']); $this->assertSame(1, $this->dnsWrites);
        $run = $this->finishSets($run); $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(2, $this->dnsWrites);
    }
    public function test_source_recovery_after_one_family_write_is_review_not_clean_retention(): void
    {
        $run = $this->ready($this->advance($this->startSets())); $run = $this->advance($run);
        $this->assertSame(1, $this->dnsWrites); $this->sourceReport($this->fixtures[0], true, time() + 1);
        $run = $this->finishSets($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->assertSame('needs_review', \App\Models\MachineFailoverDnsRun::query()->first()->status);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
    }
    public function test_candidate_address_challenge_is_not_reissued_after_a_worker_crash(): void
    {
        $run = $this->advance($this->startSets());
        $this->duringVerification = function ($challenge) {
            if ($challenge['schema'] !== 2) return;
            $proof = \App\Models\MachineNativeAddressProof::query()->findOrFail($challenge['challenge_id']);
            $this->assertSame('issued', $proof->state); $this->assertSame(0, DB::transactionLevel());
            $proof->fill(['state' => 'interrupted'])->save();
        };
        $run = $this->ready($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(2, $this->verifications);
        $attempt = Attempt::query()->first(); $instance = MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->first();
        $this->code('native_intent_expired', fn () => app(\App\Services\ServerMachine\Cloud\MachineNativeAddressProofs::class)->ensure($attempt, $instance));
        $this->assertSame(2, $this->verifications);
    }
    public function test_all_live_native_and_http_gates_remain_closed_even_with_valid_simulated_receipts(): void
    {
        $run = $this->ready($this->advance($this->startSets())); $attempt = Attempt::query()->first();
        $instance = MachineOwnedInstance::query()->where('task_id', $attempt->task_id)->first(); $dns = \App\Models\MachineFailoverDnsRun::query()->first();
        app()->instance(MachineFailoverExecutionGate::class, new MachineFailoverExecutionGate());
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests(); Http::fake();
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Cloud\MachineNativeAddressProofs::class)->ensure($attempt, $instance));
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Cloud\MachineNativeDnsAdmission::class)->prepare($attempt, $instance));
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution::class)->tick($dns->id, $this->admin->id));
        $this->code('orchestration_disabled', fn () => app(\App\Services\ServerMachine\Dns\MachineDnsRecordSetHttpTransport::class)->inventory($dns->id, $dns->payload['snapshot']['set']));
        Http::assertNothingSent(); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(2, \App\Models\MachineNativeAddressProof::count()); $this->assertSame('running', Execution::query()->find($run['id'])->status);
    }
    public function test_prepare_interruption_between_sets_reuses_the_existing_child_and_address_proofs(): void
    {
        $run = $this->advance($this->startSets(['A', 'AAAA'], 'cloudflare', 2)); $task = $this->task();
        $this->assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], true, time());
        $this->duringDnsRead = function () {
            if (\App\Models\MachineFailoverDnsRun::count() === 1) throw new ConnectionException('isolated preparation interruption');
        };
        try {
            // Invoke the step without the outer supervisor to model a worker dying between durable child commits.
            (new \ReflectionMethod(MachineFailoverNativeBridge::class, 'step'))->invoke(app(MachineFailoverNativeBridge::class), Member::query()->first(), Execution::query()->first());
            $this->fail('Expected interrupted read');
        } catch (\App\Services\ServerMachine\Dns\DnsRequestFailure $error) { $this->assertSame('provider_unavailable', $error->getMessage()); }
        $original = \App\Models\MachineFailoverDnsRun::query()->firstOrFail()->id;
        $this->assertSame(1, \App\Models\MachineFailoverDnsRun::count()); $this->assertSame(3, $this->verifications); $this->assertSame(0, $this->dnsWrites);
        $this->duringDnsRead = null; $run = $this->finishSets($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(2, \App\Models\MachineFailoverDnsRun::count());
        $this->assertNotNull(\App\Models\MachineFailoverDnsRun::query()->find($original));
        $this->assertSame(4, $this->dnsWrites); $this->assertSame(3, $this->verifications); $this->assertCount(1, $this->posts);
    }
    public function test_missing_source_dns_record_is_not_silently_created_or_adopted(): void
    {
        $run = $this->advance($this->startSets());
        foreach ($this->records as $id => $record) if (($record['name'] ?? null) === 'native-sets.example.test' && $record['type'] === 'AAAA') unset($this->records[$id]);
        $run = $this->ready($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame('native_dns_source_unverified', $run['members'][0]['reason']);
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, \App\Models\MachineFailoverDnsRun::count());
    }
    public function test_completed_dns_journal_or_simulation_cannot_replace_fresh_native_address_proofs(): void
    {
        $run = $this->ready($this->advance($this->startSets())); $dns = \App\Models\MachineFailoverDnsRun::query()->first();
        $p = $dns->payload; $p['native_binding']['origin'] = 'synthetic'; $dns->fill(['payload' => $p, 'status' => 'completed'])->save();
        $run = $this->finishSets($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame('awaiting_acceptance', $this->task()->status);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
    }
    public function test_completed_flag_without_actual_provider_publication_cannot_activate_a_candidate(): void
    {
        $run = $this->ready($this->advance($this->startSets())); $dns = \App\Models\MachineFailoverDnsRun::query()->first();
        $p = $dns->payload; foreach ($p['plan']['actions'] as &$a) $a['state'] = 'verified'; unset($a);
        $dns->fill(['payload' => $p, 'status' => 'completed', 'cursor' => count($p['plan']['actions'])])->save();
        \App\Models\MachineDnsSetRuntime::query()->whereKey($dns->id)->update(['finished_at' => time()]);
        $run = $this->finishSets($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame('awaiting_acceptance', $this->task()->status);
    }
    public function test_dns_change_after_kernel_readback_is_rechecked_before_candidate_activation(): void
    {
        $run = $this->ready($this->advance($this->startSets()));
        for ($i = 0; $i < 10 && \App\Models\MachineFailoverDnsRun::query()->first()->status !== 'completed'; $i++) $run = $this->advance($run);
        $this->assertSame('completed', \App\Models\MachineFailoverDnsRun::query()->first()->status);
        foreach ($this->records as &$record) if (($record['name'] ?? null) === 'native-sets.example.test' && $record['type'] === 'A') $record['content'] = '1.0.0.1'; unset($record);
        $run = $this->finishSets($run); $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(2, $this->dnsWrites); $this->assertSame('awaiting_acceptance', $this->task()->status);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
    }
    public static function stopStages(): array { return [[false], [true]]; }
    #[DataProvider('stopStages')]
    public function test_parent_stop_fences_native_dns_children_without_replay_or_rollback(bool $written): void
    {
        $run = $this->ready($this->advance($this->startSets()));
        if ($written) $run = $this->advance($run);
        (require base_path('database/migrations/2026_11_07_220000_create_machine_failover_runtime.php'))->up();
        $runtime = \App\Models\MachineFailoverRuntime::query()->create(['execution_id' => $run['id']]);
        $view = app(\App\Services\ServerMachine\Cloud\MachineFailoverRunner::class)->stop($run['id'], $runtime->fresh()->revision, str_repeat('a', 32), $this->admin->id);
        $this->assertSame('stopped', $view['state']);
        $this->assertSame($written ? 'needs_review' : 'retained', Member::query()->first()->phase);
        $this->assertSame($written ? 'needs_review' : 'stopped', \App\Models\MachineFailoverDnsRun::query()->first()->status);
        $child = \App\Models\MachineDnsSetRuntime::query()->first(); $this->assertNotNull($child->stop_requested_at); $this->assertNull($child->lease_hash);
        app(\App\Services\ServerMachine\Dns\MachineDnsRecordSetExecution::class)->tick($child->run_id, $this->admin->id);
        $this->assertSame($written ? 1 : 0, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
    }
    public static function addressFailures(): array
    {
        return array_map(fn ($field) => [$field], ['legacy', 'address_family', 'target_ip', 'task_id', 'installation_id', 'attempt_id',
            'native_context_sha256', 'source_installation_id', 'session', 'binary_sha256', 'configuration_fingerprint', 'udp_missing', 'cleanup']);
    }
    #[DataProvider('addressFailures')]
    public function test_native_rejects_unbound_or_incomplete_address_receipts_before_dns(string $failure): void
    {
        $run = $this->advance($this->startSets()); $this->addressFailure = $failure; $run = $this->ready($run);
        $this->assertSame('needs_review', $run['status'], json_encode($run)); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, \App\Models\MachineFailoverDnsRun::count());
        $this->assertSame('failed', \App\Models\MachineNativeAddressProof::query()->first()->state);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
        $calls = $this->verifications; $this->advance($run); $this->assertSame($calls, $this->verifications);
    }
    #[DataProvider('jsonProviders')]
    public function test_json_providers_complete_the_pinned_create_report_forwarding_dns_chain(string $provider): void
    {
        $this->fixtures = [$this->fixture(2, $provider)];
        $run = $this->advance($this->start()); $task = $this->task();
        $this->assertSame($provider, $task->payload['plan']['provider']); $this->assertCount(1, $this->posts, json_encode($run));
        $run = $this->ready($run); $this->assertSame('awaiting_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->verifications); $this->assertSame(0, $this->dnsWrites);
        $run = $this->advance($run); $this->assertSame('completed', $run['status'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $resource = \App\Models\MachineCloudResource::query()->findOrFail(MachineOwnedInstance::query()->where('task_id', $task->id)->value('resource_id'));
        $this->assertSame($provider, $resource->provider); $this->assertSame($task->external_id, $resource->external_id);
        $this->assertSame($task->installation_id, $resource->installation_id);
        $this->assertSame('retained', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
        $this->assertArrayHasKey('forwarding_receipt', Attempt::query()->first()->payload); $this->assertArrayHasKey('dns_receipt', Attempt::query()->first()->payload);
        $this->assertSame($run, $this->advance($run));
        $this->assertStringNotContainsString('never-export-this', json_encode($run));
    }

    public static function jsonUnknowns(): array
    {
        return [['digitalocean', 'unknown_present'], ['digitalocean', 'unknown_absent'], ['vultr', 'unknown_present'], ['vultr', 'unknown_absent']];
    }
    #[DataProvider('jsonUnknowns')]
    public function test_json_provider_unknown_result_never_duplicates_or_switches_accounts(string $provider, string $mode): void
    {
        $this->fixtures = [$this->fixture(2, $provider)]; $this->mode = $mode;
        $run = $this->advance($this->start()); $task = $this->task(); $identity = $task->only(['id', 'account_id', 'installation_id', 'marker']);
        $this->assertSame('outcome_unknown', $task->status, json_encode($run));
        for ($i = 0; $i < 3; $i++) { $task->fill(['next_check_at' => 0])->save(); $run = $this->advance($run); }
        $this->assertSame($identity, $task->fresh()->only(array_keys($identity))); $this->assertCount(1, $this->posts);
        $this->assertSame($mode === 'unknown_present' ? 'waiting_initialization' : 'outcome_unknown', $task->fresh()->status, json_encode($run));
        $this->assertSame(1, Attempt::count()); $this->assertSame(0, $this->dnsWrites);
    }

    #[DataProvider('jsonProviders')]
    public function test_json_provider_bad_forwarding_receipt_cannot_switch_dns(string $provider): void
    {
        $this->fixtures = [$this->fixture(2, $provider)];
        $run = $this->advance($this->start()); $this->mode = 'bad_receipt';
        $run = $this->ready($run);
        $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame(0, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $this->assertSame('active', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
        $this->assertArrayNotHasKey('forwarding_receipt', Attempt::query()->first()->payload);
    }

    public function test_vultr_cannot_rotate_to_an_unproven_distinct_account_after_rejection(): void
    {
        $this->fixtures = [$this->fixture(2, 'vultr')]; $this->mode = 'reject';
        $run = $this->advance($this->start()); $original = $this->task(); $run = $this->advance($run);
        $this->assertSame('needs_review', $run['status'], json_encode($run));
        $this->assertSame('credential_group_exhausted', $run['members'][0]['reason']);
        $this->assertSame($original->id, $this->task()->id); $this->assertSame(1, Attempt::count());
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
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
        $this->assertCount(1, $this->posts); $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $run['reserved_cents']); $this->assertSame(1, $this->verifications);
        $this->assertSame('retained', MachineOwnedInstance::query()->where('task_id', $this->fixtures[0]['source']->id)->value('status'));
        $again = $this->advance($run); $this->assertSame($run, $again); $this->assertSame(1, $this->dnsWrites);
        $payload = Attempt::query()->first()->payload; $this->assertArrayHasKey('forwarding_receipt', $payload); $this->assertArrayHasKey('dns_receipt', $payload);
        $json = json_encode($run); foreach (['synthetic-token', 'synthetic-machine', 'root_pass', 'callback_hash', 'coordinated_attempt'] as $secret) $this->assertStringNotContainsString($secret, $json);
    }
    public function test_initially_unreachable_source_completes_only_after_candidate_forwarding_and_owned_dns(): void
    {
        $this->fixtures = [$this->fixture(2, 'linode', true)];
        $plan = MachineFailoverPlan::query()->where('policy_id', $this->fixtures[0]['policy']->id)->latest('id')->firstOrFail();
        $this->assertNull($plan->evidence['baseline_at']);
        $run = $this->advance($this->start()); $this->assertCount(1, $this->posts);
        $this->assertSame(0, $this->verifications); $this->assertSame(0, $this->dnsWrites);
        $run = $this->ready($run); $this->assertSame('awaiting_dns', $run['members'][0]['phase']);
        $this->assertSame(1, $this->verifications); $this->assertSame(0, $this->dnsWrites);
        $run = $this->advance($run); $this->assertSame('completed', $run['status']);
        $this->assertSame(1, $this->dnsWrites); $this->assertCount(1, $this->posts);
        $this->assertSame($run, $this->advance($run));
        Bus::assertDispatched(\App\Jobs\MachineProvisionJob::class, fn ($job) => $job->taskId === $this->task()->id);
    }
    public function test_initially_unreachable_source_does_not_allow_an_unreachable_candidate_to_switch_dns(): void
    {
        $this->fixtures = [$this->fixture(2, 'linode', true)];
        $run = $this->advance($this->start()); $task = $this->task();
        $this->assertTrue(app(MachineProvisionService::class)->callback($task->id, $task->installation_id, $this->callbackSecret($task), 'succeeded'));
        $task->refresh()->fill(['dispatched_at' => time() - 2, 'next_check_at' => 0])->save();
        $this->report($this->fixtures[0], $task, $task->public_ips[0], false, time());
        $run = $this->advance($run);
        $this->assertNotSame('completed', $run['status']); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, $this->verifications); $this->assertSame(0, MachineDnsTransition::count());
        $this->assertSame('waiting_ipv4_probe', $task->fresh()->status); $this->assertCount(1, $this->posts);
        Bus::assertDispatched(\App\Jobs\MachineProvisionJob::class, fn ($job) => $job->taskId === $task->id);
    }
    public function test_initially_unreachable_source_still_requires_a_valid_forwarding_receipt(): void
    {
        $this->fixtures = [$this->fixture(2, 'linode', true)];
        $run = $this->advance($this->start()); $this->mode = 'bad_receipt'; $run = $this->ready($run);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(0, $this->dnsWrites);
        $this->assertSame(0, MachineDnsTransition::count()); $this->assertSame(1, $this->verifications);
        $this->advance($run); $this->assertCount(1, $this->posts); $this->assertSame(1, $this->verifications);
        Bus::assertDispatched(\App\Jobs\MachineProvisionJob::class, fn ($job) => $job->taskId === $this->task()->id);
    }

    public function test_definite_create_rejection_reserves_a_distinct_child_without_rebinding_the_first_task(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start()); $first = $this->task();
        $this->assertSame('failed', $first->status); $this->assertSame('retry_ready', $run['members'][0]['phase'], json_encode($run));
        $oldIdentity = $first->only(['id', 'account_id', 'account_revision', 'installation_id', 'marker', 'plan_hash']);
        $run = $this->advance($run); $second = $this->task();
        $this->assertNotSame($first->id, $second->id); $this->assertNotSame($first->installation_id, $second->installation_id);
        $this->assertNotSame($first->account_id, $second->account_id); $this->assertSame($oldIdentity, $first->fresh()->only(array_keys($oldIdentity)));
        $this->assertCount(2, $this->posts); $this->assertSame(0, $run['reserved_cents']);
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
        $this->assertSame(0, $run['reserved_cents']); $this->assertCount(1, $this->posts); $this->assertSame(1, Attempt::count());
    }
    public static function budgetCases(): array { return [[0, 1, 'attempt_budget'], [400, 1, 'attempt_budget']]; }

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
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, $run['reserved_cents']);
    }

    public function test_ambiguous_dns_write_is_read_back_once_without_a_second_patch_even_if_source_recovers(): void
    {
        $run = $this->advance($this->start()); $run = $this->ready($run); $this->mode = 'dns_unknown';
        $run = $this->advance($run); $this->assertSame('reconciling_dns', $run['members'][0]['phase'], json_encode($run));
        $this->assertSame(1, $this->dnsWrites); $this->mode = ''; $this->sourceReport($this->fixtures[0], true);
        MachineDnsTransition::query()->update(['next_check_at' => time() - 1]);
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
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(0, $run['reserved_cents']);
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
        $this->assertNotSame($first->account_id, $this->task()->account_id); $this->assertSame(0, $run['reserved_cents']);
    }
    public static function accountRejections(): array { return [['billing'], ['quota']]; }

    public static function digitalOceanRejections(): array { return [['reject'], ['billing'], ['quota']]; }
    #[DataProvider('digitalOceanRejections')]
    public function test_digitalocean_rotation_and_dns_completion_preserve_both_attempt_identities(string $mode): void
    {
        $this->fixtures = [$this->fixture(2, 'digitalocean')]; $this->mode = $mode;
        $run = $this->advance($this->start()); $first = $this->task();
        $identity = $first->only(['id', 'account_id', 'installation_id', 'marker', 'plan_hash']);
        $run = $this->advance($run); $second = $this->task();
        $this->assertNotSame($first->account_id, $second->account_id); $this->assertCount(2, $this->posts, json_encode($run));
        $run = $this->ready($run); $run = $this->advance($run);
        $this->assertSame('completed', $run['status'], json_encode($run)); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($identity, $first->fresh()->only(array_keys($identity)));
        $proof = Attempt::query()->latest('ordinal')->firstOrFail()->payload['rotation'];
        $this->assertSame($first->account_id, $proof['from_account_id']);
        $this->assertSame('definite_rejection_next_credential', $proof['reason']);
    }

    public function test_first_create_permission_does_not_authorize_a_rotation(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start());
        config(['machine_failover.allow_rotation' => false]);
        $run = $this->advance($run);
        $this->assertSame('rotation_disabled', $run['members'][0]['reason']);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(1, Attempt::count());
        $this->assertCount(1, $this->posts); $this->assertSame(0, $run['reserved_cents']);
    }

    public function test_rotation_revocation_during_preflight_blocks_the_child_post(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start());
        $this->duringPreflight = fn () => config(['machine_failover.allow_rotation' => false]);
        $run = $this->advance($run);
        $this->assertSame('rotation_disabled', $this->task()->reason); $this->assertNull($this->task()->dispatched_at);
        $this->assertSame('needs_review', $run['status']); $this->assertSame(2, Attempt::count());
        $this->assertCount(1, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_disabling_rotation_after_a_child_post_does_not_resend_or_abandon_its_original_reconciliation(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->start()); $this->mode = 'unknown_present';
        $run = $this->advance($run); $task = $this->task(); $this->assertSame('outcome_unknown', $task->status);
        config(['machine_failover.allow_rotation' => false]); $this->mode = ''; $task->fill(['next_check_at' => 0])->save();
        $run = $this->advance($run);
        $this->assertSame('waiting_initialization', $task->fresh()->status, json_encode($run));
        $this->assertCount(2, $this->posts); $this->assertSame(2, Attempt::count()); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_full_member_history_prevents_a_b_a_rotation_even_after_health_and_cooldown_recover(): void
    {
        $this->fixtures = [$this->fixture(2)]; $this->mode = 'reject_all';
        $run = $this->advance($this->start()); $run = $this->advance($run); $this->assertCount(2, $this->posts);
        DB::table('v2_machine_credential_health')->update(['cleared_at' => time()]);
        foreach (Attempt::query()->get() as $attempt) Task::query()->whereKey($attempt->task_id)->update(['created_at' => time() - 86401]);
        $this->mode = ''; $run = $this->advance($run);
        $this->assertSame('credential_group_exhausted', $run['members'][0]['reason'], json_encode($run));
        $this->assertSame('needs_review', $run['status']); $this->assertCount(2, $this->posts); $this->assertSame(2, Attempt::count());
    }

    public function test_rotation_proof_cannot_survive_a_changed_rejection_or_ancestor_binding(): void
    {
        $this->mode = 'reject'; $run = $this->advance($this->advance($this->start()));
        $first = Attempt::query()->oldest('ordinal')->firstOrFail(); $task = Task::query()->findOrFail($first->task_id);
        $evidence = $task->evidence; $evidence['credential_failure']['kind'] = 'billing_locked'; $task->evidence = $evidence; $task->save();
        $run = $this->advance($run);
        $this->assertSame('native_lineage_changed', $run['members'][0]['reason'], json_encode($run));
        $this->assertCount(2, $this->posts); $this->assertSame(0, $this->dnsWrites);
    }

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

    public function test_legacy_price_caps_do_not_block_an_independent_member_after_a_definite_rejection(): void
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
        $second = $this->advance($second); $this->assertNull($second['members'][0]['reason'], json_encode($second));
        $this->assertSame(2, Attempt::count()); $this->assertCount(2, $this->posts);
        $this->assertSame(0, $second['reserved_cents']);
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
        MachineDnsTransition::query()->update(['next_check_at' => time() - 1]);
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

    public function test_native_reserve_does_not_require_a_cost_baseline_when_execution_is_test_injected(): void
    {
        $this->realCostGuard(); $run = $this->advance($this->start(0));
        $this->assertSame(1, Attempt::count()); $this->assertCount(1, $this->posts);
        $this->assertSame(0, $run['reserved_cents']); $this->assertSame('waiting_initialization', $this->task()->status);
    }

    public function test_native_rotation_uses_credential_availability_not_legacy_cost_capacity(): void
    {
        $this->realCostGuard(); $this->mode = 'reject'; $run = $this->start(0);
        $run = $this->advance($run); $run = $this->advance($run);
        $this->assertCount(2, $this->posts); $this->assertSame(2, Attempt::count());
        $this->assertSame(0, $run['reserved_cents']);
        $this->assertNotSame(Attempt::query()->first()->account_id, Attempt::query()->latest('ordinal')->first()->account_id);
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
        $this->assertSame(0, Attempt::query()->sum('reserved_cents')); $this->assertSame(0, \App\Models\MachineFailoverBudgetRelease::count());
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
        $this->assertSame(0, Attempt::query()->sum('reserved_cents')); $this->assertSame(0, \App\Models\MachineFailoverBudgetRelease::count());
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
