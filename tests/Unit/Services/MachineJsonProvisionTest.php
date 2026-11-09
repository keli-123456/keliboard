<?php
namespace Tests\Unit\Services;

use App\Models\MachineCloudAccount as Account;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\CloudRequestFailure;
use App\Services\ServerMachine\Cloud\MachineCloudCredentialHealth;
use App\Services\ServerMachine\Cloud\MachineProvisionProviders;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MachineJsonProvisionTest extends TestCase
{
    private array $rows = [];
    private array $catalog = [];
    private array $blocks = [];
    private string $fault = '';
    private int $posts = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('encrypter', new Encrypter(str_repeat('j', 32), 'AES-256-CBC'));
        config(['machine_provision' => require base_path('config/machine_provision.php')]);
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    }
    public static function providers(): array { return [['digitalocean'], ['vultr']]; }

    private function fixture(string $provider): array
    {
        $account = (new Account())->forceFill(['provider' => $provider, 'credentials' => ['api_token' => 'synthetic-secret']]);
        $plan = ['provider' => $provider, 'region' => $provider === 'digitalocean' ? 'sgp1' : '40',
            'size' => $provider === 'digitalocean' ? 's-1vcpu-1gb' : 'vc2-1c-1gb',
            'image' => $provider === 'digitalocean' ? 'debian-12-x64' : '2136', 'authorized_keys' => ['synthetic-public-key'], 'monthly_limit_usd' => 10];
        foreach (['regions' => 'region', 'sizes' => 'size', 'images' => 'image'] as $plural => $field)
            config(['machine_provision.providers.' . $provider . '.' . $plural => [$plan[$field]]]);
        $client = app(MachineProvisionProviders::class)->client($account, $plan);
        $request = $client->buildRequest($plan, '10000000-0000-4000-8000-000000000001', 'keli-create-' . str_repeat('a', 24), "#cloud-config\nruncmd:\n  - [true]\n");
        $this->catalog = $provider === 'digitalocean' ? [
            'regions' => [['slug' => $plan['region'], 'name' => 'Fixture', 'available' => true]],
            'sizes' => [['slug' => $plan['size'], 'available' => true, 'regions' => [$plan['region']], 'disk' => 25, 'memory' => 1024, 'vcpus' => 1, 'price_monthly' => 6]],
            'images' => [['slug' => $plan['image'], 'name' => 'Debian', 'public' => true, 'status' => 'available', 'type' => 'base', 'regions' => [$plan['region']], 'min_disk_size' => 4]],
        ] : [
            'regions' => [['id' => $plan['region'], 'city' => 'Fixture']],
            'plans' => [['id' => $plan['size'], 'type' => 'vc2', 'locations' => [$plan['region']], 'disk' => 25, 'ram' => 1024, 'vcpu_count' => 1, 'monthly_cost' => 5]],
            'os' => [['id' => (int) $plan['image'], 'name' => 'Debian', 'arch' => 'x64', 'family' => 'debian']],
        ];
        $this->rows = [$this->remote($provider, $request)]; $this->fake($provider);
        return [$account, $plan, $client, $request];
    }
    private function remote(string $provider, array $request, int $n = 1): array
    {
        $identity = MachineProvisionProviders::requestIdentity($provider, $request);
        $common = ['tags' => $request['tags'], 'features' => ['ipv6']];
        return $common + ($provider === 'digitalocean' ? ['id' => 9000 + $n, 'name' => $identity['label'], 'region' => ['slug' => $identity['region']],
            'size_slug' => $identity['size'], 'image' => ['slug' => $identity['image']], 'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'status' => 'active', 'locked' => false, 'volume_ids' => [], 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '8.8.4.4']]]]
            : ['id' => sprintf('10000000-0000-4000-8000-%012d', $n), 'label' => $identity['label'], 'region' => $identity['region'],
                'plan' => $identity['size'], 'os_id' => (int) $identity['image'], 'date_created' => gmdate('Y-m-d H:i:s'),
                'status' => 'active', 'power_status' => 'running', 'server_status' => 'ok', 'main_ip' => '8.8.4.4', 'default_password' => 'never-export-this']);
    }
    private function fake(string $provider): void
    {
        Http::fake(function ($request) use ($provider) {
            $this->assertStringStartsWith('https://api.' . $provider . '.com/v2/', $request->url());
            $path = substr(parse_url($request->url(), PHP_URL_PATH), 3);
            if ($request->method() === 'POST') {
                $this->posts++; $this->assertSame($provider === 'digitalocean' ? '/droplets' : '/instances', $path);
                if ($this->fault === 'timeout') throw new ConnectionException('private-provider-detail');
                if (is_numeric($this->fault)) return Http::response(['message' => 'private-provider-detail'], (int) $this->fault, ['Retry-After' => '900']);
                return Http::response($this->fault === 'malformed' ? [] : [$provider === 'digitalocean' ? 'droplet' : 'instance' => $this->rows[0]], 202);
            }
            $this->assertSame('GET', $request->method());
            if (isset($this->catalog[ltrim($path, '/')])) {
                $key = ltrim($path, '/'); $rows = $this->catalog[$key];
                return Http::response([$key => $rows, 'meta' => ['total' => count($rows)]]);
            }
            if ($path === '/account') return Http::response(['account' => $provider === 'digitalocean'
                ? ['status' => $this->fault === 'locked' ? 'locked' : 'active', 'uuid' => 'synthetic-account-0001'] : ['balance' => 20]]);
            if (str_ends_with($path, '/availability')) return Http::response(['available_plans' => $this->fault === 'unavailable' ? [] : [$this->catalog['plans'][0]['id']]]);
            if ($path === '/blocks') return Http::response(['blocks' => $this->blocks, 'meta' => ['total' => count($this->blocks)]]);
            if (in_array($path, ['/droplets', '/instances'], true)) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
                $offset = $provider === 'digitalocean' ? ((int) $query['page'] - 1) * 100 : (isset($query['cursor']) ? 100 : 0);
                $body = [$provider === 'digitalocean' ? 'droplets' : 'instances' => array_slice($this->rows, $offset, 100), 'meta' => ['total' => count($this->rows)]];
                if ($offset + 100 < count($this->rows) || $this->fault === 'cursor') {
                    if ($provider === 'vultr') $body['meta']['links']['next'] = $this->fault === 'cursor' ? 'https://untrusted.example.test/' : 'safe_cursor';
                    else $body['links']['pages']['next'] = 'https://untrusted.example.test/never-follow';
                }
                return Http::response($body);
            }
            if (str_starts_with($path, $provider === 'digitalocean' ? '/droplets/' : '/instances/'))
                return Http::response([$provider === 'digitalocean' ? 'droplet' : 'instance' => $this->rows[0]]);
            throw new \RuntimeException('Unexpected fixture route');
        });
    }
    private function code(string $reason, callable $work): void
    {
        try { $work(); $this->fail('Expected ' . $reason); }
        catch (CloudException $error) { $this->assertSame($reason, $error->getMessage()); }
    }

    #[DataProvider('providers')]
    public function test_payload_uses_native_fields_and_vm_local_keys_not_provider_key_imports(string $provider): void
    {
        [, $plan, , $request] = $this->fixture($provider);
        $data = Yaml::parse($provider === 'digitalocean' ? $request['user_data'] : base64_decode($request['user_data'], true));
        $this->assertSame($plan['authorized_keys'], $data['ssh_authorized_keys']); $this->assertFalse($data['ssh_pwauth']);
        $this->assertSame([[true]], $data['runcmd']);
        foreach (['ssh_keys', 'sshkey_id', 'root_pass', 'snapshot_id', 'vpc_uuid'] as $key) $this->assertArrayNotHasKey($key, $request);
        if ($provider === 'digitalocean') { $this->assertFalse($request['backups']); $this->assertFalse($request['monitoring']); }
        else { $this->assertSame('disabled', $request['backups']); $this->assertFalse($request['activation_email']); $this->assertFalse($request['ddos_protection']); $this->assertIsInt($request['os_id']); }
        Http::assertNothingSent();
    }

    #[DataProvider('providers')]
    public function test_preflight_checks_native_catalog_and_price_before_create_and_readonly_reconcile(string $provider): void
    {
        [$account, $plan, $client, $request] = $this->fixture($provider);
        $client->preflight($account, $plan); $this->assertSame(0, $this->posts);
        $this->code('provisioning_disabled', fn () => $client->create($account, $request, time()));
        config(['machine_provision.allow_live' => true]);
        $created = $client->create($account, $request, time()); $this->assertSame(1, $this->posts);
        $this->assertSame($plan['image'], $created['image']); $this->assertSame(['8.8.4.4'], $created['public_ips']);
        $this->assertArrayNotHasKey('default_password', $created);
        config(['machine_provision.allow_live' => false, 'machine_provision.providers.' . $provider . '.images' => []]);
        $this->assertSame($created['external_id'], $client->reconcile($account, $request, time())['external_id']);
        $this->assertSame($created['external_id'], $client->instance($account, $created['external_id'])['external_id']);
        $this->assertSame(1, $this->posts);
    }

    public static function incompatible(): array
    {
        $cases = [];
        foreach (['digitalocean', 'vultr'] as $provider) foreach (['unapproved', 'region', 'size', 'image', 'disk', 'price'] as $fault) $cases[] = [$provider, $fault];
        return $cases;
    }
    #[DataProvider('incompatible')]
    public function test_incompatible_templates_or_prices_never_post(string $provider, string $fault): void
    {
        [$account, $plan, $client] = $this->fixture($provider);
        if ($fault === 'unapproved') config(['machine_provision.providers.' . $provider . '.regions' => []]);
        $size = $provider === 'digitalocean' ? 'sizes' : 'plans'; $image = $provider === 'digitalocean' ? 'images' : 'os';
        if ($fault === 'region') $plan['region'] = 'other-region';
        if ($fault === 'size') $this->catalog[$size][0][$provider === 'digitalocean' ? 'regions' : 'locations'] = ['other-region'];
        if ($fault === 'image') $this->catalog[$image][0][$provider === 'digitalocean' ? 'status' : 'arch'] = 'unsupported';
        if ($fault === 'disk') $this->catalog[$image][0]['min_disk_size'] = 80;
        if ($fault === 'price') $this->catalog[$size][0][$provider === 'digitalocean' ? 'price_monthly' : 'monthly_cost'] = 20;
        try { $client->preflight($account, $plan); $this->fail('Expected preflight rejection'); }
        catch (CloudException $e) { $this->assertContains($e->getMessage(), ['bootstrap_unsupported', 'catalog_empty', 'budget_exceeded']); }
        $this->assertSame(0, $this->posts);
    }

    #[DataProvider('providers')]
    public function test_provider_identity_cannot_send_a_credential_to_another_origin(string $provider): void
    {
        [$account, $plan, $client, $request] = $this->fixture($provider);
        $account->provider = $provider === 'digitalocean' ? 'vultr' : 'digitalocean';
        foreach ([fn () => $client->catalog($account), fn () => $client->preflight($account, $plan), fn () => $client->create($account, $request, time()),
            fn () => $client->reconcile($account, $request, time()), fn () => $client->instance($account, '1'),
            fn () => app(MachineProvisionProviders::class)->client($account, $plan)] as $work) $this->code('provider_mismatch', $work);
        Http::assertNothingSent();
    }

    public static function failures(): array
    {
        $cases = [];
        foreach (['digitalocean', 'vultr'] as $provider) foreach (['malformed', 'timeout', '503', '429', '401', '403', '402', '422'] as $fault) $cases[] = [$provider, $fault];
        return $cases;
    }
    #[DataProvider('failures')]
    public function test_create_failures_are_typed_and_never_retried_or_leaked(string $provider, string $fault): void
    {
        [$account, , $client, $request] = $this->fixture($provider); $this->fault = $fault;
        config(['machine_provision.allow_live' => true]);
        try { $client->create($account, $request, time()); $this->fail('Expected create failure'); }
        catch (CloudRequestFailure $e) {
            $this->assertSame(in_array($fault, ['malformed', 'timeout', '503'], true) ? 'unknown' : 'rejected', $e->decision['outcome']);
            if ($fault === '429') $this->assertSame(900, $e->decision['cooldown_seconds']);
            $this->assertStringNotContainsString('private-provider-detail', json_encode($e->decision));
        }
        $this->assertSame(1, $this->posts);
    }

    #[DataProvider('providers')]
    public function test_forbidden_request_addons_never_reach_create(string $provider): void
    {
        [$account, , $client, $request] = $this->fixture($provider); config(['machine_provision.allow_live' => true]);
        foreach (['extra' => 'value', 'backups' => true, 'user_data' => 'untrusted shell'] as $field => $value)
            $this->code('invalid_plan', fn () => $client->create($account, array_replace($request, [$field => $value]), time()));
        Http::assertNothingSent();
    }

    public static function identityFaults(): array
    {
        $cases = []; foreach (['digitalocean', 'vultr'] as $provider) foreach (['label', 'region', 'image', 'created', 'duplicate', 'repeated_id'] as $fault) $cases[] = [$provider, $fault];
        return $cases;
    }
    #[DataProvider('identityFaults')]
    public function test_marker_alone_never_adopts_a_changed_or_duplicate_resource(string $provider, string $fault): void
    {
        [$account, , $client, $request] = $this->fixture($provider);
        $row = &$this->rows[0];
        if ($fault === 'label') $row[$provider === 'digitalocean' ? 'name' : 'label'] = 'changed';
        if ($fault === 'region') $row['region'] = $provider === 'digitalocean' ? ['slug' => 'other-region'] : 'other-region';
        if ($fault === 'image') $row[$provider === 'digitalocean' ? 'image' : 'os_id'] = $provider === 'digitalocean' ? ['slug' => 'other-x64'] : 999;
        if ($fault === 'created') $row[$provider === 'digitalocean' ? 'created_at' : 'date_created'] = gmdate('Y-m-d\TH:i:s\Z', time() - 3000);
        if ($fault === 'duplicate') $this->rows[] = $this->remote($provider, $request, 2);
        if ($fault === 'repeated_id') $this->rows[] = $row;
        $this->code(match ($fault) { 'duplicate' => 'duplicate_candidates', 'repeated_id' => 'reconcile_incomplete', default => 'identity_changed' },
            fn () => $client->reconcile($account, $request, time()));
        $this->assertSame(0, $this->posts);
    }

    #[DataProvider('providers')]
    public function test_bounded_pagination_finds_original_marker_without_following_links(string $provider): void
    {
        [$account, , $client, $request] = $this->fixture($provider); $match = $this->rows[0]; $this->rows = [];
        for ($i = 2; $i <= 101; $i++) { $row = $this->remote($provider, $request, $i); $row['tags'] = []; $this->rows[] = $row; }
        $this->rows[] = $match;
        $this->assertSame((string) $match['id'], $client->reconcile($account, $request, time())['external_id']);
        Http::assertSentCount(2); $this->assertSame(0, $this->posts);
        $this->rows = []; $this->assertNull($client->reconcile($account, $request, time()));
    }

    #[DataProvider('providers')]
    public function test_incomplete_pagination_waits_on_original_intent_without_dispatch(string $provider): void
    {
        [$account, , $client, $request] = $this->fixture($provider);
        $this->rows = array_fill(0, 301, $this->rows[0]);
        $this->code('reconcile_incomplete', fn () => $client->reconcile($account, $request, time()));
        $this->assertSame(0, $this->posts);
        if ($provider === 'vultr') {
            $this->rows = []; $this->fault = 'cursor';
            $this->code('reconcile_incomplete', fn () => $client->reconcile($account, $request, time()));
        }
    }

    #[DataProvider('providers')]
    public function test_provider_permission_or_capacity_preflight_does_not_create(string $provider): void
    {
        [$account, $plan, $client] = $this->fixture($provider);
        $this->fault = $provider === 'digitalocean' ? 'locked' : 'unavailable';
        $this->code($provider === 'digitalocean' ? 'provider_forbidden' : 'bootstrap_unsupported', fn () => $client->preflight($account, $plan));
        $this->assertSame(0, $this->posts);
    }

    #[DataProvider('providers')]
    public function test_costs_are_positive_evidence_only_and_reject_unverified_addons(string $provider): void
    {
        [$account] = $this->fixture($provider);
        $health = \Mockery::mock(MachineCloudCredentialHealth::class); $health->shouldReceive('assertAccountIdentity')->andReturnNull();
        app()->instance(MachineCloudCredentialHealth::class, $health);
        $cost = app(MachineProvisionProviders::class)->cost($account);
        $this->assertFalse($cost->authority($account)); $row = array_values($cost->completeInventory($account))[0];
        $this->assertSame($provider === 'digitalocean' ? 600 : 500, $cost->quote($account, $row));
        $this->code('cost_addons_unverified', fn () => $cost->quote($account, array_replace($row, ['features' => ['backups']])));
        if ($provider === 'digitalocean') $row['volume_ids'] = ['paid-volume'];
        else $this->blocks = [['attached_to_instance' => $row['external_id']]];
        $this->code('cost_addons_unverified', fn () => $cost->quote($account, $row));
        $this->rows = []; $this->assertSame([], $cost->completeInventory($account)); $this->assertFalse($cost->authority($account));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }
}
