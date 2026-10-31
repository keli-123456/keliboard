<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Services\Node\NodeConfigService;
use App\Support\ProtocolCapabilityService;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Tests\TestCase;

final class TrustTunnelIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['protocol_capabilities' => require dirname(__DIR__, 3) . '/config/protocol_capabilities.php']);
    }
    private function payload(): array
    {
        return ['type' => 'trusttunnel', 'runtime' => 'v2node', 'name' => 'TT test', 'host' => 'vpn.example.test',
            'port' => 443, 'server_port' => 8443, 'rate' => 1,
            'protocol_settings' => ['network' => 'tcp', 'tls' => 1, 'tls_settings' => [
                'server_name' => 'vpn.example.test', 'alpn' => ['h2'], 'allow_insecure' => false,
                'cert_mode' => 'file', 'cert_file' => '/test/cert.pem', 'key_file' => '/test/key.pem',
            ]]];
    }

    private function valid(array $input): bool
    {
        $request = ServerSave::create('/test', 'POST', $input);
        $validator = (new Factory(new Translator(new ArrayLoader(), 'en')))->make($request->all(), $request->rules());
        $request->withValidator($validator);
        return $validator->passes();
    }

    public function test_normal_configuration_validates_tls_and_rejects_unsupported_features(): void
    {
        $this->assertTrue($this->valid($this->payload()));
        foreach (['runtime' => 'generic', 'port' => '443-445', 'protocol_settings.tls' => 0,
            'protocol_settings.network' => 'quic', 'protocol_settings.tls_settings.allow_insecure' => true,
            'protocol_settings.tls_settings.alpn' => ['http/1.1'], 'protocol_settings.ech' => ['enabled' => true]] as $key => $value) {
            $input = $this->payload();
            data_set($input, $key, $value);
            $this->assertFalse($this->valid($input), $key);
        }
        foreach (['protocol_settings.tls_settings.cert_mode' => 'self', 'protocol_settings.tls_settings.cert_file' => '',
            'protocol_settings.tls_settings.ech' => 'unsupported'] as $key => $value) {
            $input = $this->payload();
            data_set($input, $key, $value);
            $this->assertFalse($this->valid($input), $key);
        }
    }

    public function test_h3_and_automatic_ech_preserve_transport_and_wait_for_node_activation(): void
    {
        foreach ([['h3'], ['h2', 'h3']] as $alpn) {
            $input = $this->payload();
            $input['protocol_settings']['tls_settings']['alpn'] = $alpn;
            $input['protocol_settings']['ech'] = ['enabled' => true, 'mode' => 'automatic'];
            $this->assertTrue($this->valid($input));
            $settings = \App\Services\Node\HysteriaEchProvisioningService::normalize($input['protocol_settings'], null, 'tls_settings');
            $this->assertSame('pending', $settings['ech']['state']);
            $input['protocol_settings'] = $settings;
            $server = (new Server())->forceFill($input);
            $node = (new NodeConfigService())->buildResponse($server, true, true);
            $this->assertSame($alpn, $node['tls_settings']['alpn']);
            $this->assertSame('vpn.example.test', $node['automatic_ech']['public_name']);
            $this->assertArrayNotHasKey('ech_key_file', $node['networkSettings'] ?? []);
            $export = ClashMeta::buildTrustTunnel('synthetic-uuid', $server->toArray());
            $this->assertSame($alpn === ['h3'], $export['quic']);
            $this->assertArrayNotHasKey('ech-opts', $export);
            $input['protocol_settings']['ech']['private_key'] = 'must-not-be-accepted';
            $this->assertFalse($this->valid($input));
        }
    }

    public function test_model_node_payload_and_mihomo_export_preserve_identity_and_tls(): void
    {
        $server = new Server();
        $server->forceFill($this->payload());
        $settings = $server->protocol_settings;
        $this->assertSame(['h2'], $settings['tls_settings']['alpn']);
        $node = (new NodeConfigService())->buildResponse($server, true);
        $this->assertSame('trusttunnel', $node['protocol']);
        $this->assertSame('tcp', $node['network']);
        $this->assertSame(1, $node['tls']);
        $export = ClashMeta::buildTrustTunnel('synthetic-uuid', $server->toArray());
        $this->assertSame('synthetic-uuid', $export['username']);
        $this->assertSame($export['username'], $export['password']);
        $this->assertFalse($export['quic']);
        $this->assertFalse($export['skip-cert-verify']);
        $this->assertTrue($export['udp']);
        $this->assertSame('vpn.example.test', $export['sni']);
        $this->assertArrayNotHasKey('tls_settings', $export);
    }

    public function test_unverified_clients_and_wrapper_versions_are_not_exported(): void
    {
        $capabilities = new ProtocolCapabilityService(config('protocol_capabilities'));
        $server = $this->payload();
        $this->assertTrue($capabilities->supportsRuntime('v2node', $server)->supported);
        $this->assertFalse($capabilities->supportsRuntime('xray', $server)->supported);
        $this->assertTrue($capabilities->supportsClient('mihomo', '1.19.33', $server)->supported);
        foreach ([['mihomo', '1.19.32'], ['mihomo', null], ['verge', '99.0.0'], ['sing-box', '1.14.0'], ['shadowrocket', '2.2.92'], ['v2rayn', '7.25.6']] as [$name, $version]) {
            $this->assertFalse($capabilities->supportsClient($name, $version, $server)->supported, $name);
        }
    }

    public function test_normal_subscription_keeps_legacy_nodes_and_ignores_the_retired_experiment_flag(): void
    {
        $server = $this->payload();
        $legacy = ['id' => 2, 'type' => 'trojan', 'protocol_settings' => []];
        foreach ([null, false, true] as $retiredFlag) {
            $configuration = config('protocol_capabilities');
            if ($retiredFlag !== null) data_set($configuration, 'experimental.trusttunnel', $retiredFlag);
            config(['protocol_capabilities' => $configuration]);
            $capabilities = new ProtocolCapabilityService($configuration);
            $this->assertTrue($this->valid($server));
            $this->assertTrue($capabilities->supportsRuntime('v2node', $server)->supported);
            $this->assertSame([$server, $legacy], $capabilities->filterServersForClient([$server, $legacy], 'mihomo', '1.19.33'));
            foreach ([[null, null], ['unrecognized-app', '99.0.0']] as [$name, $version]) {
                $this->assertSame([$legacy], $capabilities->filterServersForClient([$server, $legacy], $name, $version));
                $this->assertSame('block', $capabilities->assessClientSupport($name, $server)['status']);
            }
        }
    }

    public function test_unknown_clients_and_unverified_core_builds_do_not_receive_trusttunnel(): void
    {
        $capabilities = new ProtocolCapabilityService(config('protocol_capabilities'));
        foreach ([[null, null], ['unknown-app', '99.0.0'], ['mihomo', null], ['mihomo', '1.20.0-alpha'],
            ['mihomo', '1.19.33.999'], ['meta', '99.0.0'], ['clashmeta', '99.0.0'], ['sparkle', '99.0.0.1']] as [$name, $version]) {
            $this->assertFalse($capabilities->supportsClient($name, $version, $this->payload())->supported, (string) $name . '/' . (string) $version);
        }
        $this->assertTrue($capabilities->supportsClient('mihomo', '1.19.33', $this->payload())->supported);
    }

    public function test_h2_is_preferred_for_dual_transport_and_h3_requires_explicit_selection(): void
    {
        foreach ([[['h2'], false], [['h2', 'h3'], false], [['h3', 'h2'], false], [['h3'], true]] as [$alpn, $quic]) {
            $server = $this->payload();
            $server['protocol_settings']['tls_settings']['alpn'] = $alpn;
            $this->assertSame($quic, ClashMeta::buildTrustTunnel('test-only', $server)['quic']);
        }
    }

    public function test_pending_ech_stays_out_of_subscription_and_ready_keeps_the_protocol_floor(): void
    {
        $capabilities = new ProtocolCapabilityService(config('protocol_capabilities'));
        $server = $this->payload();
        $server['protocol_settings']['ech'] = ['enabled' => true, 'mode' => 'automatic', 'state' => 'prepared'];
        $this->assertFalse($capabilities->supportsClient('mihomo', '1.19.33', $server)->supported);
        $this->assertSame('block', $capabilities->assessClientSupport('mihomo', $server)['status']);
        $server['protocol_settings']['ech']['state'] = 'ready';
        $server['protocol_settings']['ech']['config'] = 'synthetic-public-config';
        $this->assertTrue($capabilities->extractFacts($server)['ech']);
        $this->assertFalse($capabilities->supportsClient('mihomo', '1.19.31', $server)->supported);
        $this->assertTrue($capabilities->supportsClient('mihomo', '1.19.33', $server)->supported);
        $this->assertSame('1.19.33', $capabilities->assessClientSupport('mihomo', $server)['matched_rule']['min_version']);
    }
}
