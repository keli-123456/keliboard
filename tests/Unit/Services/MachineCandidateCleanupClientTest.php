<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount as Account;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\CloudRequestFailure;
use App\Services\ServerMachine\Cloud\MachineCandidateCleanupClient;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

class MachineCandidateCleanupClientTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    private MachineCandidateCleanupClient $client;
    private Account $account;
    private array $rows = [];
    private array $direct = [];
    private array $calls = [];
    private string $collection;
    private string $resource;
    private string $faultStage = '';
    private string $fault = '';
    private ?\Closure $afterInspect = null;
    private ?string $expectedIdentityLock = null;

    protected function setUp(): void
    {
        parent::setUp(); $this->setUpInMemoryDatabase();
        app()->instance('encrypter', new Encrypter(str_repeat('c', 32), 'AES-256-CBC'));
        Schema::create('v2_machine_cloud_account', function (Blueprint $table) {
            $table->id(); $table->string('provider'); $table->text('credentials'); $table->integer('revision');
            $table->integer('created_at'); $table->integer('updated_at');
        });
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
        $this->client = app(MachineCandidateCleanupClient::class);
    }

    public static function providers(): array { return [['linode'], ['digitalocean'], ['vultr']]; }

    private function fixture(string $provider): Task
    {
        $this->account = Account::query()->create(['provider' => $provider, 'revision' => 1,
            'credentials' => ['api_token' => 'synthetic-cleanup-secret']]);
        $plan = ['account_id' => $this->account->id, 'provider' => $provider, 'authorized_keys' => [],
            'region' => match ($provider) { 'linode' => 'sg-sin-2', 'digitalocean' => 'sgp1', default => '40' },
            'size' => match ($provider) { 'linode' => 'g6-standard-1', 'digitalocean' => 's-1vcpu-1gb', default => 'vc2-1c-1gb' },
            'image' => match ($provider) { 'linode' => 'linode/debian12', 'digitalocean' => 'debian-12-x64', default => '2136' }];
        $id = '10000000-0000-4000-8000-000000000001'; $marker = 'keli-create-' . str_repeat('a', 24);
        $request = app(MachineProvisionProviders::class)->client($this->account, $plan)->buildRequest($plan, $id, $marker,
            "#cloud-config\nruncmd:\n  - [true]\n");
        $at = time() - 60; $created = gmdate('Y-m-d\TH:i:s\Z', $at);
        $external = $provider === 'vultr' ? '20000000-0000-4000-8000-000000000001' : '987';
        $task = new Task(['id' => $id, 'account_id' => $this->account->id, 'account_revision' => 1,
            'marker' => $marker, 'dispatched_at' => $at, 'provider_created_at' => $created, 'external_id' => $external,
            'public_ips' => ['8.8.4.4', '2606:4700:4700::1111'], 'payload' => ['plan' => $plan, 'request' => $request]]);
        $common = ['tags' => [$marker]];
        $this->direct = $common + match ($provider) {
            'linode' => ['id' => 987, 'label' => $request['label'], 'region' => $plan['region'], 'type' => $plan['size'],
                'image' => $plan['image'], 'created' => $created, 'status' => 'running', 'ipv4' => ['8.8.4.4'], 'ipv6' => '2606:4700:4700::1111/128'],
            'digitalocean' => ['id' => 987, 'name' => $request['name'], 'region' => ['slug' => $plan['region']],
                'size_slug' => $plan['size'], 'image' => ['slug' => $plan['image']], 'created_at' => $created,
                'status' => 'active', 'locked' => false, 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '8.8.4.4']],
                    'v6' => [['type' => 'public', 'ip_address' => '2606:4700:4700::1111']]]],
            'vultr' => ['id' => $external, 'label' => $request['label'], 'region' => $plan['region'], 'plan' => $plan['size'],
                'os_id' => (int) $plan['image'], 'date_created' => $created, 'status' => 'active', 'power_status' => 'running',
                'server_status' => 'ok', 'main_ip' => '8.8.4.4', 'v6_main_ip' => '2606:4700:4700::1111', 'default_password' => 'never-return-this'],
        };
        $this->rows = [$this->direct];
        $this->collection = match ($provider) {
            'linode' => 'https://api.linode.com/v4/linode/instances',
            'digitalocean' => 'https://api.digitalocean.com/v2/droplets',
            'vultr' => 'https://api.vultr.com/v2/instances',
        };
        $this->resource = $this->collection . '/' . $external;
        Http::fake(function ($request, $options) use ($provider) {
            $url = explode('?', $request->url(), 2)[0];
            $stage = $request->method() === 'DELETE' ? 'delete' : ($url === $this->collection ? 'reconcile' : 'inspect');
            $this->calls[] = $stage;
            if ($this->expectedIdentityLock !== null) $this->assertIdentityLocked($this->expectedIdentityLock);
            $this->assertSame($stage === 'delete' ? 'DELETE' : 'GET', $request->method());
            $this->assertSame($stage === 'reconcile' ? $this->collection : $this->resource, $url);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer synthetic-cleanup-secret'));
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(3, $options['connect_timeout']); $this->assertSame(5, $options['timeout']);
            if ($stage === 'delete') { $this->assertSame($this->resource, $request->url()); $this->assertSame('', $request->body()); }
            if ($this->faultStage === $stage) {
                if ($this->fault === 'timeout') throw new ConnectionException('private-provider-detail');
                if ($this->fault === 'malformed') return Http::response('invalid-json', 200);
                if ($this->fault === 'oversized') return Http::response(str_repeat('x', 524289), 200);
                if ($this->fault === '204') return Http::response('', 204);
                if ($this->fault === 'incomplete') return Http::response($provider === 'linode'
                    ? ['data' => $this->rows, 'pages' => 4]
                    : [$provider === 'digitalocean' ? 'droplets' : 'instances' => $this->rows, 'meta' => ['total' => 301]]);
                return Http::response(['message' => 'private-provider-detail'], (int) $this->fault,
                    ['Retry-After' => '900', 'Location' => 'https://untrusted.example.test/']);
            }
            if ($stage === 'delete') return Http::response($provider === 'linode' ? '{}' : '', $provider === 'linode' ? 200 : 204);
            if ($stage === 'reconcile') return Http::response($provider === 'linode'
                ? ['data' => $this->rows, 'pages' => 1]
                : [$provider === 'digitalocean' ? 'droplets' : 'instances' => $this->rows, 'meta' => ['total' => count($this->rows)]]);
            if ($this->afterInspect) ($this->afterInspect)();
            return Http::response($provider === 'linode' ? $this->direct : [$provider === 'digitalocean' ? 'droplet' : 'instance' => $this->direct]);
        });
        return $task;
    }

    private function failure(callable $work): CloudException
    {
        try { $work(); $this->fail('Expected cleanup refusal'); }
        catch (CloudException $error) {
            $this->assertStringNotContainsString('private-provider-detail', $error->getMessage());
            if ($error instanceof CloudRequestFailure) $this->assertStringNotContainsString('private-provider-detail', json_encode($error->decision));
            return $error;
        }
    }

    private function assertIdentityLocked(string $key): void
    {
        $contender = Cache::lock($key, 120); $acquired = $contender->get();
        if ($acquired) $contender->release();
        $this->assertFalse($acquired);
    }

    #[DataProvider('providers')]
    public function test_resource_identity_key_is_shared_by_account_aliases_but_not_other_resources(string $provider): void
    {
        $task = $this->fixture($provider); $alias = clone $task; $alias->account_id++;
        $payload = $alias->payload; $payload['plan']['account_id'] = $alias->account_id; $alias->payload = $payload;
        $key = MachineCandidateCleanupClient::lockKey($provider, $task->external_id);
        $this->assertNotSame($task->account_id, $alias->account_id);
        $this->assertSame($key, MachineCandidateCleanupClient::lockKey($alias->payload['plan']['provider'], $alias->external_id));
        $this->assertNotSame($key, MachineCandidateCleanupClient::lockKey($provider === 'linode' ? 'digitalocean' : 'linode', $task->external_id));
        $this->assertNotSame($key, MachineCandidateCleanupClient::lockKey($provider, $provider === 'vultr'
            ? '20000000-0000-4000-8000-000000000002' : '988'));
        $this->assertSame(MachineCandidateCleanupClient::lockKey('vultr', 'aaaaaaaa-0000-4000-8000-000000000001'),
            MachineCandidateCleanupClient::lockKey('vultr', 'AAAAAAAA-0000-4000-8000-000000000001'));
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_identity_lock_covers_reconciliation_guard_and_delete_and_is_released(string $provider): void
    {
        $task = $this->fixture($provider);
        $key = $this->expectedIdentityLock = MachineCandidateCleanupClient::lockKey($provider, $task->external_id);
        $this->client->delete($task, fn () => $this->assertIdentityLocked($key));
        $this->assertSame(['reconcile', 'inspect', 'delete'], $this->calls);
        $contender = Cache::lock($key, 120);
        try { $this->assertTrue($contender->get()); } finally { $contender->release(); }
    }

    #[DataProvider('providers')]
    public function test_inspect_is_read_only_and_delete_verifies_both_receipts_before_one_scoped_request(string $provider): void
    {
        $task = $this->fixture($provider);
        $this->assertStringNotContainsString('synthetic-cleanup-secret', $this->account->getRawOriginal('credentials'));
        $this->assertStringNotContainsString($task->marker, $task->getAttributes()['payload']);
        $task->public_ips = array_reverse($task->public_ips); $before = $task->getAttributes();
        $snapshot = $this->client->inspect($task);
        $this->assertSame($task->external_id, $snapshot['external_id']);
        $this->assertArrayNotHasKey('default_password', $snapshot);
        $this->assertSame(['inspect'], $this->calls);
        $this->client->delete($task);
        $this->assertSame(['inspect', 'reconcile', 'inspect', 'delete'], $this->calls);
        $this->assertSame($before, $task->getAttributes());
        $this->assertNotNull($this->client->inspect($task)); // Acknowledgement does not imply absence.
        $this->faultStage = 'inspect'; $this->fault = '404';
        $this->assertNull($this->client->inspect($task));
        $this->assertSame(1, count(array_filter($this->calls, fn ($stage) => $stage === 'delete')));
    }

    public static function inspectFailures(): array
    {
        $cases = [];
        foreach (self::providers() as [$provider]) foreach (['401', '403', '429', '500', '302', '204', 'malformed', 'oversized', 'timeout'] as $fault)
            $cases[] = [$provider, $fault];
        return $cases;
    }

    #[DataProvider('inspectFailures')]
    public function test_inspect_never_treats_ambiguous_or_forbidden_reads_as_absence(string $provider, string $fault): void
    {
        $task = $this->fixture($provider); $this->faultStage = 'inspect'; $this->fault = $fault;
        $error = $this->failure(fn () => $this->client->inspect($task));
        $this->assertInstanceOf(CloudRequestFailure::class, $error);
        if ($fault === '429') $this->assertSame(900, $error->decision['cooldown_seconds']);
        $this->assertSame(['inspect'], $this->calls);
    }

    public static function deleteFailures(): array
    {
        $cases = [];
        foreach (self::providers() as [$provider]) foreach (['401', '403', '404', '429', '500', '302', '409', 'timeout'] as $fault)
            $cases[] = [$provider, $fault];
        return $cases;
    }

    #[DataProvider('deleteFailures')]
    public function test_delete_failures_are_typed_never_retried_and_never_confirm_cleanup(string $provider, string $fault): void
    {
        $task = $this->fixture($provider); $this->faultStage = 'delete'; $this->fault = $fault;
        $error = $this->failure(fn () => $this->client->delete($task));
        $this->assertInstanceOf(CloudRequestFailure::class, $error);
        if (in_array($fault, ['500', '302', '409', 'timeout'], true)) $this->assertSame('unknown', $error->decision['outcome']);
        if ($fault === '429') $this->assertSame(900, $error->decision['cooldown_seconds']);
        $this->assertSame(['reconcile', 'inspect', 'delete'], $this->calls);
    }

    public static function ownershipFaults(): array
    {
        $cases = [];
        foreach (self::providers() as [$provider]) foreach (['empty', 'marker', 'label', 'region', 'size', 'image', 'old',
            'id', 'created', 'ips', 'duplicate', 'direct_id', 'direct_created', 'direct_ips'] as $fault) $cases[] = [$provider, $fault];
        return $cases;
    }

    #[DataProvider('ownershipFaults')]
    public function test_delete_requires_unique_original_creation_and_exact_id_time_and_ip_set(string $provider, string $fault): void
    {
        $task = $this->fixture($provider);
        $createdKey = match ($provider) { 'linode' => 'created', 'digitalocean' => 'created_at', default => 'date_created' };
        $labelKey = $provider === 'digitalocean' ? 'name' : 'label';
        $sizeKey = match ($provider) { 'linode' => 'type', 'digitalocean' => 'size_slug', default => 'plan' };
        $otherId = $provider === 'vultr' ? '20000000-0000-4000-8000-000000000002' : 988;
        if ($fault === 'empty') $this->rows = [];
        if ($fault === 'marker') $this->rows[0]['tags'] = ['foreign-marker'];
        if ($fault === 'label') $this->rows[0][$labelKey] = 'foreign-label';
        if ($fault === 'region') $this->rows[0]['region'] = $provider === 'digitalocean' ? ['slug' => 'foreign'] : 'foreign';
        if ($fault === 'size') $this->rows[0][$sizeKey] = 'foreign';
        if ($fault === 'image') $this->rows[0][$provider === 'vultr' ? 'os_id' : 'image'] = match ($provider) {
            'digitalocean' => ['slug' => 'foreign'], 'vultr' => 999, default => 'foreign',
        };
        if ($fault === 'old') $this->rows[0][$createdKey] = gmdate('Y-m-d\TH:i:s\Z', $task->dispatched_at - 31);
        if ($fault === 'id') $this->rows[0]['id'] = $otherId;
        if ($fault === 'created') $this->rows[0][$createdKey] = gmdate('Y-m-d\TH:i:s\Z', $task->dispatched_at + 1);
        if ($fault === 'ips') $task->public_ips = ['1.1.1.1'];
        if ($fault === 'duplicate') { $this->rows[] = $this->rows[0]; $this->rows[1]['id'] = $otherId; }
        if ($fault === 'direct_id') $this->direct['id'] = $otherId;
        if ($fault === 'direct_created') $this->direct[$createdKey] = gmdate('Y-m-d\TH:i:s\Z', $task->dispatched_at + 1);
        if ($fault === 'direct_ips') {
            if ($provider === 'linode') $this->direct['ipv4'] = ['1.1.1.1'];
            elseif ($provider === 'digitalocean') $this->direct['networks']['v4'][0]['ip_address'] = '1.1.1.1';
            else $this->direct['main_ip'] = '1.1.1.1';
        }
        $this->failure(fn () => $this->client->delete($task));
        $this->assertNotContains('delete', $this->calls);
    }

    public static function localFaults(): array
    {
        $cases = [];
        foreach (self::providers() as [$provider]) foreach (['revision', 'account', 'provider', 'unsupported', 'undispatched', 'missing_id',
            'path', 'created', 'empty_ips', 'private_ip', 'marker', 'request_label', 'plan'] as $fault) $cases[] = [$provider, $fault];
        return $cases;
    }

    #[DataProvider('localFaults')]
    public function test_incomplete_or_changed_local_identity_never_reaches_provider(string $provider, string $fault): void
    {
        $task = $this->fixture($provider); $payload = $task->payload;
        if ($fault === 'revision') $this->account->fill(['revision' => 2])->save();
        if ($fault === 'account') $task->account_id = 999;
        if ($fault === 'provider') $this->account->fill(['provider' => $provider === 'linode' ? 'vultr' : 'linode'])->save();
        if ($fault === 'unsupported') { $this->account->fill(['provider' => 'other'])->save(); $payload['plan']['provider'] = 'other'; }
        if ($fault === 'undispatched') $task->dispatched_at = null;
        if ($fault === 'missing_id') $task->external_id = null;
        if ($fault === 'path') $task->external_id = '../987?all=true';
        if ($fault === 'created') $task->provider_created_at = null;
        if ($fault === 'empty_ips') $task->public_ips = [];
        if ($fault === 'private_ip') $task->public_ips = ['127.0.0.1'];
        if ($fault === 'marker') $task->marker = 'keli-create-' . str_repeat('b', 24);
        if ($fault === 'request_label') $payload['request'][$provider === 'digitalocean' ? 'name' : 'label'] = 'keli-' . str_repeat('b', 32);
        if ($fault === 'plan') $payload['plan']['region'] = 'foreign';
        $task->payload = $payload;
        $this->failure(fn () => $this->client->inspect($task));
        $this->failure(fn () => $this->client->delete($task));
        $this->assertSame([], $this->calls); Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_reconcile_404_and_predelete_read_404_are_not_successful_deletion(string $provider): void
    {
        $task = $this->fixture($provider); $this->fault = '404';
        foreach (['reconcile', 'inspect'] as $stage) {
            $this->faultStage = $stage;
            $error = $this->failure(fn () => $this->client->delete($task));
            $this->assertInstanceOf(CloudRequestFailure::class, $error);
            $this->assertSame(404, $error->decision['http_status']);
        }
        $this->assertNotContains('delete', $this->calls);
    }

    #[DataProvider('providers')]
    public function test_account_rotation_during_remote_reads_prevents_delete(string $provider): void
    {
        $task = $this->fixture($provider);
        $this->afterInspect = fn () => $this->account->fill(['revision' => 2])->save();
        $this->assertSame('account_changed', $this->failure(fn () => $this->client->delete($task))->getMessage());
        $this->assertSame(['reconcile', 'inspect'], $this->calls);
    }

    #[DataProvider('providers')]
    public function test_mutation_boundary_guard_runs_after_readbacks_and_preserves_revocation_without_delete(string $provider): void
    {
        $task = $this->fixture($provider); $revoked = new CloudException('native_lease_lost'); $guards = 0;
        $guard = function () use ($revoked, &$guards) {
            $guards++;
            $this->assertSame(['reconcile', 'inspect'], $this->calls);
            throw $revoked;
        };
        $error = $this->failure(fn () => $this->client->delete($task, $guard));
        $this->assertSame($revoked, $error);
        $this->assertSame(1, $guards);
        $this->assertSame(['reconcile', 'inspect'], $this->calls);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $contender = Cache::lock(MachineCandidateCleanupClient::lockKey($provider, $task->external_id), 120);
        try { $this->assertTrue($contender->get()); } finally { $contender->release(); }
    }

    #[DataProvider('providers')]
    public function test_incomplete_inventory_is_not_ownership_or_absence_proof(string $provider): void
    {
        $task = $this->fixture($provider); $this->faultStage = 'reconcile'; $this->fault = 'incomplete';
        $this->assertSame('reconcile_incomplete', $this->failure(fn () => $this->client->delete($task))->getMessage());
        $this->assertSame(['reconcile'], $this->calls);
    }

    #[DataProvider('providers')]
    public function test_inspect_rejects_changed_receipt_and_does_not_infer_absence_from_power_state(string $provider): void
    {
        $task = $this->fixture($provider);
        if ($provider === 'linode') $this->direct['status'] = 'offline';
        elseif ($provider === 'digitalocean') $this->direct['status'] = 'off';
        else $this->direct['power_status'] = 'stopped';
        $this->assertSame('offline', $this->client->inspect($task)['status']);
        $task->provider_created_at = gmdate('Y-m-d\TH:i:s\Z', $task->dispatched_at + 1);
        $this->assertSame('ownership_mismatch', $this->failure(fn () => $this->client->inspect($task))->getMessage());
        $this->assertSame(['inspect', 'inspect'], $this->calls);
    }
}
