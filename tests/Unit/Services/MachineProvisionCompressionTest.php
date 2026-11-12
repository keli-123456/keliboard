<?php
namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\LinodeProvisionClient;
use App\Services\ServerMachine\Cloud\ProvisionBootstrap;
use App\Services\ServerMachine\MachineInstallScript;
use App\Support\Setting;
use Illuminate\Support\Facades\Http;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MachineProvisionCompressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(Setting::class, new class {
            public function get(string $key, mixed $default = null): mixed {
                return $key === 'server_machine_distribution_source' ? 'panel' : $default;
            }
        });
        Http::swap(new \Illuminate\Http\Client\Factory()); Http::preventStrayRequests();
    }

    private function yaml(array $data): string
    {
        return "#cloud-config\n" . Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    private function decode(array $file): string
    {
        if (($file['encoding'] ?? 'text/plain') === 'text/plain') return $file['content'];
        $this->assertSame('gz+b64', $file['encoding']);
        $decoded = gzdecode(base64_decode($file['content'], true));
        $this->assertIsString($decoded); return $decoded;
    }

    public function test_real_shared_installer_is_preserved_without_new_endpoints_or_callbacks(): void
    {
        $machine = new ServerMachine(); $machine->forceFill(['id' => 17, 'name' => 'Synthetic isolated source', 'token' => str_repeat('d', 64)]);
        $origin = 'https://control-keli-accept-20261111-r2-fixture-1234.example.test';
        $bootstrap = (new ProvisionBootstrap())->build($machine, $origin, '10000000-0000-4000-8000-000000000001', str_repeat('a', 32), str_repeat('c', 64));
        $data = Yaml::parse($bootstrap);
        $this->assertSame('gz+b64', $data['write_files'][0]['encoding']);
        $expected = str_replace(["\r\n", "\r"], "\n", (new MachineInstallScript())->render($origin, $machine, 'kelinode-rs'));
        $this->assertSame(hash('sha256', $expected), hash('sha256', $this->decode($data['write_files'][0])));
        $this->assertLessThanOrEqual(LinodeProvisionClient::MAX_USER_DATA_BYTES, strlen($bootstrap));
        $this->assertSame('0600', $data['write_files'][0]['permissions']);
        $this->assertSame('root:root', $data['write_files'][0]['owner']);
        $this->assertStringNotContainsString('encoding:', $this->decode($data['write_files'][0]));
        $this->assertStringContainsString('/api/v2/server/machine/provision/result', $data['write_files'][2]['content']);
        $this->assertStringContainsString('Bearer ' . str_repeat('c', 64), $data['write_files'][2]['content']);
        $this->assertArrayNotHasKey('source', $data['write_files'][0]);
        Http::assertNothingSent();
    }

    public static function extendedPayloads(): array
    {
        return ['bounded envelope fixture' => [2048, true], 'oversized opaque fixture' => [4096, false]];
    }

    #[DataProvider('extendedPayloads')]
    public function test_extended_cloud_init_with_real_synthetic_host_keys_fits_and_roundtrips_every_file(int $opaqueBytes, bool $fits): void
    {
        $machine = new ServerMachine(); $machine->forceFill(['id' => 17, 'name' => 'Synthetic isolated source', 'token' => str_repeat('d', 64)]);
        $origin = 'https://control-keli-accept-20261111-r2-fixture-1234.example.test';
        $data = Yaml::parse((new ProvisionBootstrap())->build($machine, $origin, '10000000-0000-4000-8000-000000000001', str_repeat('a', 32), str_repeat('c', 64)));
        $host = EC::createKey('Ed25519');
        $data['ssh_keys'] = ['ed25519_private' => $host->toString('OpenSSH'), 'ed25519_public' => $host->getPublicKey()->toString('OpenSSH')];
        $data['ssh_pwauth'] = false; $data['ssh_deletekeys'] = true;
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'hy2-isolated.test'], $key);
        $this->assertNotFalse($csr); $certificate = openssl_csr_sign($csr, null, $key, 1);
        $this->assertNotFalse($certificate);
        $this->assertTrue(openssl_x509_export($certificate, $pem)); $this->assertTrue(openssl_pkey_export($key, $private));
        foreach (['/etc/kelinode/acceptance-cert.pem' => $pem, '/etc/kelinode/acceptance-key.pem' => $private,
            // Larger synthetic opaque inputs are tested separately as rejected payloads.
            '/root/opaque-test-input' => base64_encode(random_bytes($opaqueBytes)),
            '/root/isolation.sh' => "#!/bin/bash\n" . str_repeat("# Bounded synthetic fixture setup; never run by this test\n", 30)] as $path => $content) {
            $data['write_files'][] = ['path' => $path, 'content' => $content, 'permissions' => '0600', 'owner' => 'root:root'];
        }
        $plain = $data;
        foreach ($plain['write_files'] as &$file) { $file['content'] = $this->decode($file); unset($file['encoding']); } unset($file);
        $this->assertGreaterThan(27373, strlen($this->yaml($plain)));
        $compact = ProvisionBootstrap::compactFiles($this->yaml($data));
        if (!$fits) {
            $this->assertGreaterThan(LinodeProvisionClient::MAX_USER_DATA_BYTES, strlen($compact));
            try {
                (new LinodeProvisionClient())->buildRequest(['region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'authorized_keys' => []],
                    '10000000-0000-4000-8000-000000000001', 'synthetic-marker', $this->yaml($data));
                $this->fail('Expected provider payload limit');
            } catch (CloudException $error) {
                $this->assertSame('bootstrap_too_large', $error->getMessage());
            }
            Http::assertNothingSent();
            return;
        }
        $this->assertLessThanOrEqual(LinodeProvisionClient::MAX_USER_DATA_BYTES, strlen($compact), 'Decoded fixture bytes: ' . strlen($compact));
        $this->assertLessThanOrEqual(LinodeProvisionClient::MAX_METADATA_BYTES, strlen(base64_encode($compact)), 'Encoded fixture bytes: ' . strlen(base64_encode($compact)));
        $request = (new LinodeProvisionClient())->buildRequest(['region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'authorized_keys' => []],
            '10000000-0000-4000-8000-000000000001', 'synthetic-marker', $this->yaml($data));
        $wire = $request['metadata']['user_data']; $decoded = base64_decode($wire, true);
        $this->assertLessThanOrEqual(LinodeProvisionClient::MAX_METADATA_BYTES, strlen($wire));
        $this->assertLessThanOrEqual(LinodeProvisionClient::MAX_USER_DATA_BYTES, strlen($decoded));
        $after = Yaml::parse($decoded);
        $this->assertSame(hash('sha256', json_encode($data['ssh_keys'])), hash('sha256', json_encode($after['ssh_keys'])));
        $this->assertSame($data['runcmd'], $after['runcmd']);
        foreach ($data['write_files'] as $index => $before) {
            $file = $after['write_files'][$index];
            $this->assertSame(hash('sha256', $this->decode($before)), hash('sha256', $this->decode($file)));
            unset($before['content'], $before['encoding'], $file['content'], $file['encoding']);
            $this->assertSame($before, $file);
        }
        Http::assertNothingSent();
    }

    public function test_compaction_is_idempotent_and_retains_file_attributes(): void
    {
        $data = ['write_files' => [['path' => '/var/lib/synthetic/file', 'content' => str_repeat("# preserved\n", 200),
            'owner' => 'root:root', 'permissions' => '0600', 'defer' => true, 'append' => true]], 'runcmd' => [['bash', '/var/lib/synthetic/file']]];
        $compact = ProvisionBootstrap::compactFiles($this->yaml($data));
        $this->assertSame($compact, ProvisionBootstrap::compactFiles($compact));
        $after = Yaml::parse($compact);
        $this->assertSame($data['write_files'][0]['content'], $this->decode($after['write_files'][0]));
        unset($after['write_files'][0]['content'], $after['write_files'][0]['encoding'], $data['write_files'][0]['content']);
        $this->assertSame($data, $after);
    }

    public function test_small_already_encoded_and_incompressible_files_are_left_unchanged(): void
    {
        $data = ['write_files' => [['path' => '/root/small', 'content' => 'tiny'],
            ['path' => '/root/base64', 'encoding' => 'b64', 'content' => str_repeat('A', 2048)],
            ['path' => '/root/random', 'content' => base64_encode(random_bytes(3072))]]];
        $original = $this->yaml($data);
        $this->assertSame($original, ProvisionBootstrap::compactFiles($original));
    }

    public static function malformed(): array
    {
        return [['synthetic-private-input'], ["#cloud-config\nwrite_files: bad"], ["#cloud-config\nwrite_files: [bad]"],
            ["#cloud-config\nwrite_files: [synthetic-private-input"], ["#cloud-config\nnull\n"]];
    }

    #[DataProvider('malformed')]
    public function test_invalid_cloud_config_fails_without_echoing_its_content(string $body): void
    {
        try { ProvisionBootstrap::compactFiles($body); $this->fail('Expected local validation'); }
        catch (CloudException $error) { $this->assertSame('invalid_bootstrap_identity', $error->getMessage()); }
        Http::assertNothingSent();
    }

    public function test_parsing_work_is_bounded_even_for_highly_compressible_input(): void
    {
        $body = "#cloud-config\n#" . str_repeat('x', 65536);
        try { ProvisionBootstrap::compactFiles($body); $this->fail('Expected local size rejection'); }
        catch (CloudException $error) { $this->assertSame('bootstrap_too_large', $error->getMessage()); }
        Http::assertNothingSent();
    }
}
