<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount;
use App\Models\MachineCloudResource;
use App\Models\MachineDnsCredential;
use App\Models\MachineDnsTransition;
use App\Models\MachineFailoverPolicy;
use App\Models\MachineOwnedDns;
use App\Models\MachineOwnedInstance;
use App\Models\MachineProvisionTask;
use App\Models\ServerMachine;
use App\Models\ServerMachineRelease;
use App\Models\User;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineInstanceAcceptance;
use App\Services\ServerMachine\Cloud\MachineOwnedDnsService;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\MachineAddressInventory;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineInstanceAcceptanceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;
    private MachineInstanceAcceptance $service;
    private MachineOwnedDnsService $dns;
    private ServerMachine $machine;
    private MachineCloudAccount $account;
    private MachineFailoverPolicy $policy;
    private ServerMachineRelease $release;
    private User $admin;
    private array $records = [];
    private array $nodeReports = [];
    private string $mode = '';
    private int $cloudWrites = 0;
    private int $dnsWrites = 0;
    private int $taskNumber = 0;
    private $duringVerifier = null;
    private $duringDnsRead = null;
    private $afterDnsWrite = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase(); $this->bindJsonResponseFactory();
        app()->instance('encrypter', new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        app()->instance(\Illuminate\Contracts\Bus\Dispatcher::class, new \Illuminate\Bus\Dispatcher(app())); Bus::fake();
        $this->bindTestSettings(['app_url' => 'https://panel.example.test', 'server_entry_probe_enable' => true,
            'server_entry_probe_interval' => 5, 'server_entry_probe_targets' => ['1.1.1.1', '9.9.9.9']]);
        config(['machine_provision' => require base_path('config/machine_provision.php'),
            'machine_acceptance' => require base_path('config/machine_acceptance.php')]);
        foreach (['2026_11_05_170000_create_machine_cloud_tables.php', '2026_11_06_010000_add_machine_cloud_providers.php',
            '2026_11_05_160000_create_machine_dns_tables.php', '2026_11_05_220000_create_machine_provision_tasks.php',
            '2026_11_06_030000_create_machine_failover_policies.php', '2026_11_06_050000_create_machine_acceptance_tables.php', '2026_11_09_140000_add_machine_dns_readback_state.php',
            '2026_06_12_000002_create_v2_server_machine_release_table.php', '2026_07_14_000001_add_binary_sha256_to_server_machine_release.php'] as $file) {
            (require base_path('database/migrations/' . $file))->up();
        }
        Schema::create('v2_user', function (Blueprint $t) { $t->id(); $t->boolean('is_admin'); $t->boolean('banned')->default(false); $t->timestamps(); });
        Schema::create('v2_server_machine', function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('token'); $t->boolean('is_active'); $t->timestamps(); });
        Schema::create('v2_server', function (Blueprint $t) { $t->id(); $t->integer('machine_id'); $t->boolean('enabled'); $t->string('type'); $t->integer('port'); $t->integer('server_port'); $t->text('protocol_settings')->nullable(); });
        $this->admin = User::query()->create(['is_admin' => true]);
        $this->machine = ServerMachine::query()->create(['name' => 'Independent synthetic', 'token' => 'synthetic-machine-secret', 'is_active' => true]);
        DB::table('v2_server')->insert(['id' => 10, 'machine_id' => $this->machine->id, 'enabled' => true, 'type' => 'hysteria', 'port' => 8443, 'server_port' => 8443]);
        $this->account = MachineCloudAccount::query()->create(['name' => 'Synthetic cloud', 'provider' => 'linode', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-cloud-secret']]);
        $credential = MachineDnsCredential::query()->create(['name' => 'Synthetic DNS', 'provider' => 'cloudflare', 'revision' => 1, 'credentials' => ['api_token' => 'synthetic-dns-secret']]);
        $saved = app(MachineFailoverPolicyService::class)->save(['name' => 'Independent SG', 'account_id' => $this->account->id, 'machine_id' => $this->machine->id,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 2, 'stale_after_seconds' => 300,
            'cooldown_seconds' => 1800, 'monthly_limit_usd' => 100, 'daily_limit' => 1, 'dns_credential_id' => $credential->id,
            'dns_hostname' => 'new.example.test', 'isolation_confirmed' => true], $this->admin->id);
        $this->policy = MachineFailoverPolicy::query()->findOrFail($saved['id']);
        $this->release = ServerMachineRelease::query()->create(['component' => 'kelinode-rs', 'version' => 'v0.1.386', 'platform' => 'linux-x86_64',
            'manifest_path' => 'synthetic/manifest.json', 'archive_path' => 'synthetic/release.tar.gz', 'sha256' => str_repeat('a', 64), 'binary_sha256' => str_repeat('b', 64), 'status' => 'active']);
        $this->service = app(MachineInstanceAcceptance::class); $this->dns = app(MachineOwnedDnsService::class); $this->fake();
    }

    private function enable(): void
    {
        config(['machine_acceptance.allow_verification' => true, 'machine_acceptance.allow_dns' => true,
            'machine_acceptance.allowed_policy_ids' => [$this->policy->id], 'machine_acceptance.verifier_url' => 'https://verifier.example.test/v1/verify',
            'machine_acceptance.verifier_key' => str_repeat('k', 32), 'machine_acceptance.verifier_profiles' => [$this->policy->id => 'synthetic-profile']]);
    }

    private function task(): MachineProvisionTask
    {
        $n = ++$this->taskNumber; $id = (string) Str::uuid(); $installation = str_pad(dechex($n), 32, '0', STR_PAD_LEFT);
        $ip = $n === 1 ? '8.8.8.8' : '8.8.4.4';
        $plan = ['account_id' => $this->account->id, 'machine_id' => $this->machine->id, 'slot' => $this->policy->slot,
            'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'source_ref' => [], 'panel_origin' => 'https://panel.example.test'];
        $request = ['label' => 'keli-' . str_replace('-', '', $id), 'tags' => ['keli-create-' . $n], 'type' => $plan['size'], 'region' => $plan['region'], 'image' => $plan['image']];
        return MachineProvisionTask::query()->create(['id' => $id, 'request_key' => hash('sha256', $id), 'admin_id' => $this->admin->id,
            'account_id' => $this->account->id, 'account_revision' => 1, 'machine_id' => $this->machine->id, 'machine_authority' => hash('sha256', $this->machine->token),
            'plan_hash' => hash('sha256', json_encode($plan)), 'slot' => $this->policy->slot, 'installation_id' => $installation, 'callback_hash' => str_repeat('c', 64),
            'marker' => $request['tags'][0], 'payload' => ['plan' => $plan, 'request' => $request], 'status' => 'awaiting_acceptance',
            'external_id' => (string) (987 + $n), 'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 10), 'public_ips' => [$ip],
            'bootstrap_state' => 'succeeded', 'dispatched_at' => time() - 10, 'deadline_at' => time() + 600]);
    }

    private function reports(MachineProvisionTask $task): void
    {
        $ip = $task->public_ips[0]; $config = app(MachineEntryProbe::class)->configuration();
        $probe = ['schema' => 1, 'revision' => $config['revision'], 'state' => 'ready', 'checked_at' => time(),
            'targets' => array_map(fn ($target) => ['id' => $target['id'], 'status' => 'reachable', 'source_ip' => $ip], $config['targets'])];
        app(MachineAddressInventory::class)->record($this->machine, $task->installation_id, ['public_ipv4' => $ip], time(),
            ['version' => $this->release->version, 'binary_sha256' => $this->release->binary_sha256, 'observation_session' => str_repeat('e', 32),
                'configured_nodes' => [['id' => 10, 'protocol' => 'hysteria2', 'port' => 8443]]], $probe);
        $this->nodeReports[$task->installation_id] = ['machine_id' => $this->machine->id, 'installation_id' => $task->installation_id, 'last_seen_at' => time(), 'last_status_at' => time()];
        Cache::put('node_instances:10', $this->nodeReports, 300);
    }

    private function candidate(bool $reports = true): MachineOwnedInstance
    {
        $task = $this->task(); if ($reports) $this->reports($task);
        $result = $this->service->enroll($this->policy->id, $this->policy->revision, $task->id, $this->release->id, $task->public_ips[0], $this->admin->id);
        return MachineOwnedInstance::query()->findOrFail($result['id']);
    }

    private function verified(): MachineOwnedInstance
    {
        $this->enable(); $instance = $this->candidate();
        $result = $this->service->verify($instance->id, $this->policy->revision, $instance->revision, $this->admin->id);
        $this->assertNull($result['reason']); $this->assertSame('verified', $result['status']);
        return $instance->fresh();
    }

    private function intent(MachineOwnedInstance $instance, ?string $recovery = null): array
    {
        return $this->dns->prepare($instance->id, $this->policy->revision, $instance->revision, str_repeat('a', 32), bin2hex(random_bytes(16)), $recovery, $this->admin->id);
    }

    private function code(string $reason, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $reason); }
        catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }

    private function fake(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.linode.com/')) {
                if ($request->method() !== 'GET') { $this->cloudWrites++; throw new \RuntimeException('No synthetic cloud mutations expected'); }
                $filter = json_decode($request->header('X-Filter')[0] ?? '{}', true); $rows = [];
                foreach (MachineProvisionTask::query()->get() as $task) {
                    $r = $task->payload['request']; if (($filter['label'] ?? '') !== $r['label']) continue;
                    $rows[] = ['id' => (int) $task->external_id, 'label' => $r['label'], 'tags' => $r['tags'], 'type' => $r['type'], 'region' => $r['region'],
                        'image' => $r['image'], 'created' => $task->provider_created_at, 'status' => 'running', 'ipv4' => $task->public_ips, 'ipv6' => ''];
                }
                return Http::response(['data' => $rows, 'pages' => 1]);
            }
            if ($request->url() === 'https://verifier.example.test/v1/verify') {
                $challenge = json_decode($request->body(), true); $key = config('machine_acceptance.verifier_key');
                $this->assertSame(hash_hmac('sha256', "request\n" . $request->body(), $key), $request->header('X-Keli-Signature')[0]);
                $echo = ['sent' => 3, 'received' => 3, 'missing' => 0, 'duplicates' => 0, 'bytes' => 96, 'sha256' => $challenge['echo_sha256']];
                $reply = ['schema' => 1, 'challenge_id' => $challenge['challenge_id'], 'request_sha256' => hash('sha256', $request->body()),
                    'cleanup_complete' => true, 'finished_at' => time(), 'results' => array_map(fn ($node) => ['node_id' => $node['id'], 'endpoint_authenticated' => true, 'tcp' => $echo, 'udp' => $echo], $challenge['nodes'])];
                if ($this->mode === 'missing_udp') $reply['results'][0]['udp']['missing'] = 1;
                if ($this->mode === 'bad_bytes') $reply['results'][0]['tcp']['bytes'] = 95;
                if ($this->mode === 'wrong_request') $reply['request_sha256'] = str_repeat('0', 64);
                if ($this->mode === 'future_receipt') $reply['finished_at'] = time() + 60;
                if ($this->mode === 'wrong_node') $reply['results'][0]['node_id'] = 999;
                if ($this->mode === 'no_authentication') $reply['results'][0]['endpoint_authenticated'] = false;
                if ($this->mode === 'no_cleanup') $reply['cleanup_complete'] = false;
                if ($this->mode === 'replay') $reply['challenge_id'] = str_repeat('0', 32);
                if ($this->duringVerifier) ($this->duringVerifier)();
                $raw = json_encode($reply); $signature = hash_hmac('sha256', "response\n" . $raw, $key);
                return Http::response($raw, 200, ['X-Keli-Signature' => $this->mode === 'bad_signature' ? str_repeat('0', 64) : $signature]);
            }
            if (str_starts_with($request->url(), 'https://api.cloudflare.com/')) {
                if ($request->method() === 'GET') {
                    if ($this->duringDnsRead) ($this->duringDnsRead)();
                    if ($this->mode === 'dns_read_rate') return Http::response([], 429, ['Retry-After' => '900']);
                    if ($this->mode === 'dns_read_unavailable') return Http::response([], 503);
                    return Http::response(['success' => true, 'result' => array_values($this->records),
                        'result_info' => ['total_pages' => 1, 'total_count' => count($this->records), 'page' => 1, 'per_page' => 50]]);
                }
                $this->dnsWrites++;
                if ($request->method() === 'POST') {
                    $row = $request->data() + ['id' => str_repeat('d', 32)]; $row['locked'] = false;
                    $this->records[$row['id']] = $row;
                } elseif ($request->method() === 'PATCH') {
                    $id = basename(parse_url($request->url(), PHP_URL_PATH)); $row = array_replace($this->records[$id], $request->data()); $this->records[$id] = $row;
                } else throw new \RuntimeException('No DNS deletion expected');
                if ($this->afterDnsWrite) ($this->afterDnsWrite)();
                if ($this->mode === 'dns_timeout') throw new \Illuminate\Http\Client\ConnectionException('synthetic DNS timeout');
                return Http::response(['success' => true, 'result' => $row]);
            }
            throw new \RuntimeException('Unexpected synthetic request');
        });
    }

    public function test_enrollment_is_local_and_exact_but_cannot_run_verification_by_default(): void
    {
        $instance = $this->candidate(); $this->assertSame('candidate', $instance->status);
        $again = $this->service->enroll($this->policy->id, 1, $instance->task_id, $this->release->id, $instance->ip, $this->admin->id);
        $this->assertSame($instance->id, $again['id']);
        $this->code('acceptance_disabled', fn () => $this->service->verify($instance->id, 1, 1, $this->admin->id));
        $this->assertSame(0, MachineCloudResource::query()->count()); Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_workspace_reads_expose_exact_policy_gates_and_release_digests_without_credentials(): void
    {
        $view = $this->service->overview();
        $this->assertFalse($view['policies'][0]['verification_allowed']); $this->assertFalse($view['policies'][0]['dns_allowed']);
        $this->assertFalse($view['policies'][0]['verifier_ready']);
        $this->assertSame($this->release->binary_sha256, $view['releases'][0]->binary_sha256);
        $this->enable(); $view = $this->service->overview();
        $this->assertTrue($view['policies'][0]['verification_allowed']); $this->assertTrue($view['policies'][0]['verifier_ready']);
        foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', str_repeat('k', 32)] as $secret) $this->assertStringNotContainsString($secret, json_encode($view));
        Http::assertNothingSent(); Bus::assertNothingDispatched();
    }

    public function test_signed_tcp_udp_verification_creates_an_owned_resource_without_dns_or_power(): void
    {
        $instance = $this->verified(); $resource = MachineCloudResource::query()->findOrFail($instance->resource_id);
        $this->assertSame($instance->installation_id, $resource->installation_id);
        $this->assertTrue($resource->manual_hold); $this->assertFalse($resource->auto_boot); $this->assertFalse($resource->armed);
        $this->assertSame(0, MachineOwnedDns::query()->count()); $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, $this->cloudWrites);
        $this->assertTrue($instance->verification['cleanup_complete']);
        foreach (['synthetic-machine-secret', 'synthetic-cloud-secret', 'synthetic-dns-secret', 'challenge_id', 'echo_payload', 'root_pass'] as $secret) $this->assertStringNotContainsString($secret, $instance->toJson());
    }

    public static function badReceipts(): array { return [['bad_signature', 'verification_invalid'], ['replay', 'verification_invalid'],
        ['no_cleanup', 'verification_invalid'], ['missing_udp', 'forwarding_failed'], ['bad_bytes', 'forwarding_failed'],
        ['wrong_request', 'verification_invalid'], ['future_receipt', 'verification_invalid'], ['wrong_node', 'forwarding_failed'], ['no_authentication', 'forwarding_failed']]; }

    #[DataProvider('badReceipts')]
    public function test_untrusted_or_incomplete_receipts_never_create_ownership(string $mode, string $reason): void
    {
        $this->enable(); $instance = $this->candidate(); $this->mode = $mode;
        $result = $this->service->verify($instance->id, 1, 1, $this->admin->id);
        $this->assertSame($reason, $result['reason']); $this->assertNull($result['verification']);
        $this->assertSame(0, MachineCloudResource::query()->count()); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_initial_owned_dns_is_created_once_read_back_and_activated(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $this->assertSame('prepared', $intent['state']); $this->assertSame(0, $this->dnsWrites);
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('verified', $result['state']); $this->assertSame(1, $this->dnsWrites);
        $this->assertSame($instance->id, MachineOwnedDns::query()->first()->active_instance_id);
        $this->assertSame('active', $instance->fresh()->status); $this->assertSame('accepted', MachineProvisionTask::query()->find($instance->task_id)->status);
        $this->dns->execute($intent['id'], $this->admin->id); $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $this->cloudWrites);
    }

    public function test_uncertain_creation_is_only_reconciled_never_created_twice(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance); $this->mode = 'dns_timeout';
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['state']); $this->assertSame(1, $this->dnsWrites);
        $this->assertNull(MachineOwnedDns::query()->first()->active_instance_id);
        $this->mode = ''; MachineDnsTransition::query()->whereKey($intent['id'])->update(['next_check_at' => time() - 1]);
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('verified', $result['state']); $this->assertSame(1, $this->dnsWrites);
    }

    public function test_dns_retry_after_survives_service_recreation_and_early_polls_make_no_provider_calls(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $this->afterDnsWrite = function () { $this->mode = 'dns_read_rate'; };
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['state']); $this->assertSame(1, $result['readback_attempts']);
        $this->assertGreaterThanOrEqual(time() + 899, $result['next_check_at']); $this->assertNull($result['confirmed_at']);
        $reads = Http::recorded()->count();
        $service = new MachineOwnedDnsService($this->service, new \App\Services\ServerMachine\Dns\DnsProviderClient());
        $this->assertSame($result, $service->execute($intent['id'], $this->admin->id));
        $this->assertSame($reads, Http::recorded()->count()); $this->assertSame(1, $this->dnsWrites);
        $this->mode = ''; $this->afterDnsWrite = null;
        MachineDnsTransition::query()->whereKey($intent['id'])->update(['next_check_at' => time() - 1]);
        $result = $service->execute($intent['id'], $this->admin->id);
        $this->assertSame('verified', $result['state']); $this->assertSame(2, $result['readback_attempts']);
        $this->assertNotNull($result['confirmed_at']); $this->assertNull($result['next_check_at']); $this->assertSame(1, $this->dnsWrites);
    }

    public function test_preflight_rate_limit_defers_without_setting_a_dispatch_fence_or_creating_dns(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance); $this->mode = 'dns_read_rate';
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('prepared', $result['state']); $this->assertNull($result['dispatched_at']);
        $this->assertSame(0, $result['readback_attempts']); $this->assertGreaterThanOrEqual(time() + 899, $result['next_check_at']);
        $reads = Http::recorded()->count(); $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame($reads, Http::recorded()->count()); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_last_dns_snapshot_rejects_an_external_change_after_the_first_preflight_read(): void
    {
        $first = $this->verified(); $this->dns->execute($this->intent($first)['id'], $this->admin->id);
        $second = $this->verified(); $intent = $this->intent($second); $reads = 0;
        $this->duringDnsRead = function () use (&$reads) { if (++$reads === 2) $this->records[array_key_first($this->records)]['content'] = '1.0.0.1'; };
        $this->code('dns_record_changed', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame(1, $this->dnsWrites); $this->assertNull(MachineDnsTransition::query()->findOrFail($intent['id'])->dispatched_at);
        $this->assertSame('1.0.0.1', $this->records[array_key_first($this->records)]['content']);
        $this->assertSame('active', $first->fresh()->status); $this->assertNotSame('active', $second->fresh()->status);
    }

    public function test_readback_exhaustion_is_durable_and_never_rewrites_or_reverses_dns(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance); $this->mode = 'dns_timeout';
        $this->dns->execute($intent['id'], $this->admin->id); $this->mode = 'dns_read_unavailable';
        MachineDnsTransition::query()->whereKey($intent['id'])->update(['readback_attempts' => MachineOwnedDnsService::READBACK_LIMIT - 1, 'next_check_at' => time() - 1]);
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('dns_readback_exhausted', $result['reason']); $this->assertNull($result['next_check_at']);
        $this->assertSame(MachineOwnedDnsService::READBACK_LIMIT, $result['readback_attempts']); $reads = Http::recorded()->count();
        $this->dns->execute($intent['id'], $this->admin->id); $this->assertSame($reads, Http::recorded()->count());
        $this->assertSame(1, $this->dnsWrites); $this->assertNotSame('active', $instance->fresh()->status);
    }

    public function test_another_administrator_cannot_execute_the_original_administrators_intent(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $other = User::query()->create(['is_admin' => true]); $reads = Http::recorded()->count();
        $this->code('permission_revoked', fn () => $this->dns->execute($intent['id'], $other->id));
        $this->assertSame($reads, Http::recorded()->count()); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_readback_migration_is_required_without_losing_prior_transition_evidence(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $migration = require base_path('database/migrations/2026_11_09_140000_add_machine_dns_readback_state.php'); $migration->down();
        $this->assertFalse($this->dns->available()); $this->code('migration_required', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $view = $this->service->overview(); $this->assertFalse($view['dns_enabled']); $this->assertFalse($view['policies'][0]['dns_allowed']);
        $this->assertSame('prepared', MachineDnsTransition::query()->findOrFail($intent['id'])->state);
        $migration->up(); $this->assertTrue($this->dns->available());
        $this->assertSame(0, MachineDnsTransition::query()->findOrFail($intent['id'])->readback_attempts);
        $this->assertSame(0, $this->dnsWrites);
    }

    public function test_downgrade_refuses_to_discard_used_dns_readback_evidence(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance); $this->dns->execute($intent['id'], $this->admin->id);
        $migration = require base_path('database/migrations/2026_11_09_140000_add_machine_dns_readback_state.php');
        try { $migration->down(); $this->fail('Expected evidence-preserving downgrade refusal'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('downgrade refused', $error->getMessage()); }
        $this->assertTrue($this->dns->available());
        $this->assertNotNull(MachineDnsTransition::query()->findOrFail($intent['id'])->confirmed_at);
    }

    public function test_switch_retains_previous_instance_and_recovery_requires_fresh_verification(): void
    {
        $first = $this->verified(); $start = $this->intent($first); $this->dns->execute($start['id'], $this->admin->id);
        $second = $this->verified(); $switch = $this->intent($second);
        $this->assertSame('verified', $this->dns->execute($switch['id'], $this->admin->id)['state']);
        $this->assertSame('retained', $first->fresh()->status); $this->assertSame('active', $second->fresh()->status);
        $first->refresh()->fill(['verified_until' => time() - 1])->save();
        $this->code('acceptance_expired', fn () => $this->intent($first, $switch['id']));
        $verified = $this->service->verify($first->id, 1, $first->revision, $this->admin->id);
        $this->assertNull($verified['reason']); $recovery = $this->intent($first->fresh(), $switch['id']);
        $this->assertSame('verified', $this->dns->execute($recovery['id'], $this->admin->id)['state']);
        $this->assertSame('active', $first->fresh()->status); $this->assertSame('retained', $second->fresh()->status);
        $this->assertSame(0, $this->cloudWrites); $this->assertSame(2, MachineCloudResource::query()->count());
    }

    public static function wrongProvenance(): array { return [['slot', 'ownership_mismatch'], ['machine', 'ownership_mismatch'],
        ['account', 'ownership_mismatch'], ['source', 'candidate_not_ready'], ['status', 'candidate_not_ready'], ['marker', 'candidate_not_ready']]; }

    #[DataProvider('wrongProvenance')]
    public function test_existing_or_unrelated_tasks_cannot_be_enrolled(string $kind, string $reason): void
    {
        $task = $this->task();
        if ($kind === 'slot') $task->slot = 'another-slot';
        if ($kind === 'machine') $task->machine_id = 999;
        if ($kind === 'account') $task->account_id = 999;
        if ($kind === 'source') { $payload = $task->payload; $payload['plan']['source_ref'] = ['version' => 'v2', 'service_id' => 7]; $task->payload = $payload; }
        if ($kind === 'status') $task->status = 'waiting_agent';
        if ($kind === 'marker') $task->marker = 'not-created-by-this-task';
        $task->save();
        $this->code($reason, fn () => $this->service->enroll($this->policy->id, 1, $task->id, $this->release->id, $task->public_ips[0], $this->admin->id));
        $this->assertSame(0, MachineOwnedInstance::query()->count()); Http::assertNothingSent();
    }

    public function test_reports_from_an_unknown_existing_instance_do_not_authorize_reuse(): void
    {
        $task = $this->task(); $this->reports($task);
        app(MachineAddressInventory::class)->record($this->machine, str_repeat('f', 32), ['public_ipv4' => '1.0.0.1'], time());
        $this->code('machine_in_use', fn () => $this->service->enroll($this->policy->id, 1, $task->id, $this->release->id, $task->public_ips[0], $this->admin->id));
        Http::assertNothingSent();
    }

    public static function revokedDuringCheck(): array { return [['config', 'acceptance_evidence_changed'], ['session', 'acceptance_evidence_changed'],
        ['policy', 'config_changed'], ['admin', 'permission_revoked'], ['gate', 'acceptance_disabled'], ['key', 'verifier_changed']]; }

    #[DataProvider('revokedDuringCheck')]
    public function test_changes_during_verification_invalidate_the_receipt(string $kind, string $reason): void
    {
        $this->enable(); $instance = $this->candidate();
        $this->duringVerifier = function () use ($kind, $instance) {
            if ($kind === 'config') DB::table('v2_server')->where('id', 10)->update(['protocol_settings' => json_encode(['changed' => true])]);
            if ($kind === 'session') {
                $key = 'machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token);
                $rows = Cache::get($key); $rows[$instance->installation_id]['runtime']['observation_session'] = str_repeat('f', 32); Cache::put($key, $rows, 86400);
            }
            if ($kind === 'policy') DB::table('v2_machine_failover_policy')->where('id', $this->policy->id)->increment('revision');
            if ($kind === 'admin') $this->admin->fill(['banned' => true])->save();
            if ($kind === 'gate') config(['machine_acceptance.allow_verification' => false]);
            if ($kind === 'key') config(['machine_acceptance.verifier_key' => str_repeat('z', 32)]);
        };
        $result = $this->service->verify($instance->id, 1, 1, $this->admin->id);
        $this->assertSame($reason, $result['reason']); $this->assertNull($result['verification']);
        $this->assertSame('failed', \App\Models\MachineAcceptanceCheck::query()->first()->state);
        $this->assertSame(0, MachineCloudResource::query()->count()); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_missing_verifier_is_not_a_synthetic_pass(): void
    {
        $this->enable(); config(['machine_acceptance.verifier_url' => '']); $instance = $this->candidate();
        $this->code('verifier_not_configured', fn () => $this->service->verify($instance->id, 1, 1, $this->admin->id));
        $this->assertSame(0, MachineCloudResource::query()->count()); Http::assertNothingSent();
    }

    public function test_wrong_binary_hash_and_configured_ports_cannot_reach_verifier(): void
    {
        $this->enable(); $instance = $this->candidate();
        $key = 'machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token);
        $rows = Cache::get($key); $rows[$instance->installation_id]['runtime']['binary_sha256'] = str_repeat('0', 64); Cache::put($key, $rows, 86400);
        $this->code('runtime_not_verified', fn () => $this->service->verify($instance->id, 1, 1, $this->admin->id));
        $rows[$instance->installation_id]['runtime']['binary_sha256'] = $this->release->binary_sha256;
        $rows[$instance->installation_id]['runtime']['configured_nodes'][0]['port'] = 1; Cache::put($key, $rows, 86400);
        $this->code('configuration_not_verified', fn () => $this->service->verify($instance->id, 1, 1, $this->admin->id));
        $this->assertSame(0, $this->dnsWrites); Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_a_non_owned_existing_dns_record_is_never_adopted_or_overwritten(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $this->records[str_repeat('f', 32)] = ['id' => str_repeat('f', 32), 'name' => 'new.example.test', 'type' => 'A', 'content' => '1.0.0.1', 'ttl' => 60, 'proxied' => false, 'comment' => 'old-controller', 'locked' => false];
        $before = $this->records;
        $this->code('dns_record_conflict', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame($before, $this->records); $this->assertSame(0, $this->dnsWrites);
        $this->assertNull(MachineOwnedDns::query()->first()->record_id);
    }

    public function test_marker_removal_and_unexpected_address_change_block_switching(): void
    {
        $first = $this->verified(); $start = $this->intent($first); $this->dns->execute($start['id'], $this->admin->id);
        $second = $this->verified(); $intent = $this->intent($second); $writes = $this->dnsWrites;
        $id = array_key_first($this->records); $original = $this->records[$id]; $this->records[$id]['comment'] = 'changed-externally';
        $this->code('dns_ownership_changed', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->records[$id] = array_replace($original, ['content' => '1.0.0.1']);
        $this->code('dns_record_changed', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame($writes, $this->dnsWrites); $this->assertSame('active', $first->fresh()->status);
    }

    public function test_prepared_intents_can_be_cancelled_with_all_live_gates_off(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        config(['machine_acceptance.allow_dns' => false, 'machine_acceptance.allow_verification' => false]);
        $this->assertSame('cancelled', $this->dns->cancel($intent['id'], $this->admin->id)['state']);
        $this->assertSame('cancelled', $this->dns->cancel($intent['id'], $this->admin->id)['state']);
        $this->assertSame(0, $this->dnsWrites);
    }

    public function test_dispatched_ambiguous_intents_cannot_be_cancelled_as_if_nothing_happened(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance); $this->mode = 'dns_timeout';
        $this->dns->execute($intent['id'], $this->admin->id);
        $this->code('dns_outcome_requires_review', fn () => $this->dns->cancel($intent['id'], $this->admin->id));
        $this->assertSame('needs_review', MachineDnsTransition::query()->find($intent['id'])->state);
        $this->assertSame(1, $this->dnsWrites);
    }

    public function test_retained_healthy_instance_does_not_mask_failure_of_the_active_instance(): void
    {
        $first = $this->verified(); $start = $this->intent($first); $this->dns->execute($start['id'], $this->admin->id);
        $second = $this->verified(); $intent = $this->intent($second); $this->dns->execute($intent['id'], $this->admin->id);
        (require base_path('database/migrations/2026_11_06_040000_create_machine_failover_observations.php'))->up();
        config(['machine_failover.observe_enabled' => true]);
        $key = 'machine_addresses:' . $this->machine->id . ':' . hash('sha256', $this->machine->token); $rows = Cache::get($key);
        foreach ($rows[$second->installation_id]['entry_probe']['targets'] as &$target) $target['status'] = 'unreachable';
        unset($target); Cache::put($key, $rows, 86400);
        $sample = app(\App\Services\ServerMachine\Cloud\MachineFailoverObserver::class)->observe($this->policy->id);
        $this->assertSame($second->installation_id, $sample['installation_id']); $this->assertSame('failed', $sample['state']);
        $this->assertSame(1, $sample['consecutive_failures']); $this->assertFalse($sample['threshold_reached']);
        $this->assertSame('retained', $first->fresh()->status);
    }

    private function editedPolicy(array $changes = []): array
    {
        return array_replace($this->policy->only(['name', 'machine_id', 'account_id', 'region', 'size', 'image', 'failure_threshold',
            'stale_after_seconds', 'cooldown_seconds', 'monthly_limit_usd', 'daily_limit', 'dns_credential_id', 'dns_hostname', 'isolation_confirmed']),
            ['id' => $this->policy->id, 'expected_revision' => $this->policy->revision], $changes);
    }

    public function test_owned_policy_metadata_is_editable_but_identity_and_removal_are_locked(): void
    {
        $this->candidate(); $service = app(MachineFailoverPolicyService::class);
        $overview = $service->overview();
        $this->assertSame([$this->policy->id], $overview['machines'][0]['owned_policy_ids']->all());
        $this->assertFalse($overview['machines'][0]['candidate_available']);
        $this->code('owned_identity_locked', fn () => $service->save($this->editedPolicy(['dns_hostname' => 'another.example.test']), $this->admin->id));
        $this->code('policy_in_use', fn () => $service->drop($this->policy->id, 1, $this->admin->id));
        $saved = $service->save($this->editedPolicy(['name' => 'Renamed', 'cooldown_seconds' => 3600]), $this->admin->id);
        $this->assertSame(2, $saved['revision']); $this->assertSame('Renamed', $saved['name']);
        $this->assertSame(0, $this->cloudWrites); $this->assertSame(0, $this->dnsWrites);
    }

    public function test_managed_resources_cannot_be_rebound_or_given_a_second_automatic_controller(): void
    {
        $instance = $this->verified(); $resource = MachineCloudResource::query()->findOrFail($instance->resource_id);
        $cloud = app(\App\Services\ServerMachine\Cloud\MachineCloudService::class);
        $this->code('owned_identity_locked', fn () => $cloud->binding($resource->id, $resource->revision, null, null));
        $this->code('owned_identity_locked', fn () => $cloud->policy($resource->id, $resource->revision, true, $this->admin->id));
        $this->assertSame($instance->installation_id, $resource->fresh()->installation_id);
        $this->assertFalse($resource->fresh()->auto_boot); $this->assertSame(0, $this->cloudWrites);
    }

    public function test_prepared_intent_and_fresh_proof_expiry_block_every_dns_write(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        MachineDnsTransition::query()->whereKey($intent['id'])->update(['deadline_at' => time() - 1]);
        $this->code('dns_intent_expired', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->dns->cancel($intent['id'], $this->admin->id);
        $instance->fill(['verified_until' => time() - 1])->save();
        $this->code('acceptance_expired', fn () => $this->intent($instance));
        $this->assertSame(0, $this->dnsWrites); $this->assertSame(0, $this->cloudWrites);
    }

    public function test_invalidated_acceptance_blocks_dns_before_write(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $instance->fill(['verification' => null])->save();
        $this->code('acceptance_expired', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame(0, $this->dnsWrites); $this->assertNull(MachineOwnedDns::query()->first()->active_instance_id);
    }

    public static function dnsRevocations(): array
    {
        return [['proof', 'acceptance_expired'], ['admin', 'permission_revoked'], ['gate', 'acceptance_disabled'], ['ownership', 'dns_ownership_changed']];
    }

    #[DataProvider('dnsRevocations')]
    public function test_changes_during_dns_write_never_commit_an_active_instance(string $change, string $reason): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $this->afterDnsWrite = function () use ($change, $instance) {
            if ($change === 'proof') $instance->fill(['verification' => null])->save();
            if ($change === 'admin') $this->admin->fill(['banned' => true])->save();
            if ($change === 'gate') config(['machine_acceptance.allow_dns' => false]);
            if ($change === 'ownership') MachineOwnedDns::query()->increment('revision');
        };
        $result = $this->dns->execute($intent['id'], $this->admin->id);
        $this->assertSame('needs_review', $result['state']); $this->assertSame($reason, $result['reason']);
        $this->assertSame(1, $this->dnsWrites); $this->assertSame(0, $this->cloudWrites);
        $this->assertNull(MachineOwnedDns::query()->first()->active_instance_id);
        $this->assertNotSame('active', $instance->fresh()->status);
        $this->assertSame('awaiting_acceptance', MachineProvisionTask::query()->find($instance->task_id)->status);
        if ($change === 'ownership') $this->assertSame(2, MachineOwnedDns::query()->first()->revision);
    }

    public function test_dns_preflight_revocation_is_rechecked_before_the_mutation_boundary(): void
    {
        $instance = $this->verified(); $intent = $this->intent($instance);
        $this->duringDnsRead = fn () => config(['machine_acceptance.allow_dns' => false]);
        $this->code('acceptance_disabled', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame(0, $this->dnsWrites); $this->assertNull(MachineDnsTransition::query()->find($intent['id'])->dispatched_at);
        $this->enable(); $this->duringDnsRead = fn () => MachineOwnedDns::query()->increment('revision');
        $this->code('dns_ownership_changed', fn () => $this->dns->execute($intent['id'], $this->admin->id));
        $this->assertSame(0, $this->dnsWrites); $this->assertNull(MachineOwnedDns::query()->first()->active_instance_id);
    }

    public function test_api_rejects_user_posted_verdicts_and_requires_administrator_before_validation(): void
    {
        $controller = new \App\Http\Controllers\V2\Admin\Server\MachineAcceptanceController();
        foreach ([null, (object) ['is_admin' => false, 'banned' => false], (object) ['is_admin' => true, 'banned' => true]] as $actor) {
            $request = \Illuminate\Http\Request::create('/synthetic', 'POST', ['passed' => true]); $request->setUserResolver(fn () => $actor);
            try { $controller->verify($request, $this->service); $this->fail('Admin required.'); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        }
        $request = \Illuminate\Http\Request::create('/synthetic', 'POST', ['passed' => true]); $request->setUserResolver(fn () => $this->admin);
        $response = $controller->verify($request, $this->service);
        $this->assertSame(422, $response->getStatusCode()); $this->assertSame('invalid_acceptance_input', $response->getData(true)['message']);
        Http::assertNothingSent();
    }

    public function test_instance_runtime_retains_only_bounded_nonsensitive_identity_fields(): void
    {
        $normalizer = new \App\Services\ServerMachine\MachineInstanceRuntime();
        $payload = ['runtime' => ['binary_sha256' => str_repeat('b', 64), 'observation' => ['schema' => 1, 'session' => str_repeat('a', 32),
            'pid' => 123, 'started_at' => time(), 'uptime_seconds' => 5], 'node_statuses' => [['node_id' => 10, 'protocol' => 'hysteria2', 'server_port' => 8443, 'status' => 'configured', 'password' => 'synthetic-node-secret']]]];
        $runtime = $normalizer->normalize($payload);
        $this->assertSame([['id' => 10, 'protocol' => 'hysteria2', 'port' => 8443]], $runtime['configured_nodes']);
        $this->assertStringNotContainsString('synthetic-node-secret', json_encode($runtime));
        $payload['runtime']['node_statuses'] = array_fill(0, 129, $payload['runtime']['node_statuses'][0]);
        $this->assertArrayNotHasKey('configured_nodes', $normalizer->normalize($payload));
        $this->assertSame([], $normalizer->normalize([]));
    }

    public function test_schema_rollback_preserves_prior_metadata_and_unavailable_readers_are_safe(): void
    {
        (require base_path('database/migrations/2026_11_06_050000_create_machine_acceptance_tables.php'))->down();
        $this->assertFalse($this->service->overview()['available']); $this->assertFalse($this->dns->overview()['available']);
        $this->code('migration_required', fn () => $this->service->verify(1, 1, 1, $this->admin->id));
        $this->assertTrue(Schema::hasTable('v2_machine_provision_task')); $this->assertTrue(Schema::hasTable('v2_machine_failover_policy'));
    }
}
