<?php

namespace Tests\Unit\Protocols;

use App\Protocols\KeliCore;
use App\Services\SubscriptionClientProfileService;
use App\Support\KeliCoreCapabilities;
use App\Support\ProtocolCapabilityService;
use App\Support\ProtocolManager;
use Illuminate\Http\Request;
use Tests\TestCase;

final class KeliCoreTest extends TestCase
{
    private function node(string $type, array $settings = []): array
    {
        return ['id' => 1, 'name' => "test-$type", 'type' => $type, 'host' => 'node.example.test',
            'port' => 443, 'password' => '00112233-4455-6677-8899-aabbccddeeff', 'protocol_settings' => $settings];
    }

    private function config(array $nodes): array
    {
        return (new KeliCore(['uuid' => 'unused'], $nodes, 'kelicore', KeliCoreCapabilities::MIN_VERSION))->generateConfig();
    }

    public function test_mixed_subscription_keeps_supported_nodes_and_explains_skips(): void
    {
        $nodes = [$this->node('hysteria', ['version' => 2]), $this->node('mieru'), $this->node('trusttunnel'),
            $this->node('shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm'])];
        $config = $this->config($nodes);
        $this->assertSame(['hysteria2', 'mieru'], array_column($config['outbounds'], 'type'));
        $this->assertSame(['test-trusttunnel', 'test-shadowsocks'], array_column($config['keli']['skipped'], 'name'));
        $this->assertSame('test-hysteria', $config['keli']['default_outbound']);
        $this->assertNotEmpty($config['rules']);
        $this->assertStringNotContainsString($nodes[0]['password'], json_encode($config['keli']['skipped']));
    }

    public function test_all_unsupported_nodes_produce_a_diagnostic_profile_instead_of_an_export_exception(): void
    {
        $config = $this->config([$this->node('trusttunnel')]);
        $this->assertSame([], $config['outbounds']);
        $this->assertCount(1, $config['keli']['skipped']);
        $this->assertNull($config['keli']['default_outbound']);
    }

    public function test_native_version_identity_is_not_a_mihomo_version(): void
    {
        $manager = new ProtocolManager(app());
        app()->instance('protocols.manager', $manager);
        $request = Request::create('/subscription?flag=kelicore');
        $request->headers->set('User-Agent', 'keli-native-client/0.1.425');
        $profile = (new SubscriptionClientProfileService())->resolve($request);
        $this->assertSame('kelicore', $profile['name']);
        $this->assertSame('0.1.425', $profile['version']);
        $this->assertSame(KeliCore::class, $manager->matchProtocolClassName('keli-native-client/0.1.425'));
        $service = new ProtocolCapabilityService(require dirname(__DIR__, 3) . '/config/protocol_capabilities.php');
        $this->assertTrue($service->supportsClient('kelicore', '0.1.425', $this->node('anytls'))->supported);
        $this->assertFalse($service->supportsClient('kelicore', null, $this->node('anytls'))->supported);
        $this->assertFalse($service->supportsClient('kelicore', '0.1.424', $this->node('anytls'))->supported);
    }

    public function test_naive_h3_and_mieru_range_reach_native_profile_fields(): void
    {
        $mieru = $this->node('mieru');
        $mieru['ports'] = '12000-12002';
        $config = $this->config([$this->node('naive', ['network' => 'quic']), $mieru]);
        $this->assertSame(['type' => 'quic'], $config['outbounds'][0]['transport']);
        $this->assertFalse($config['outbounds'][0]['tls']['insecure']);
        $this->assertSame(12000, $config['outbounds'][1]['server_port']);
    }

    public function test_native_negotiation_overrides_old_format_links_but_not_other_clients(): void
    {
        app()->instance('protocols.manager', new ProtocolManager(app()));
        $request = Request::create('/subscription?flag=clashmeta');
        $request->headers->set('User-Agent', 'keli-native-client/0.1.425');
        $request->headers->set('X-Keli-Profile', 'kelicore-v1');
        $service = new SubscriptionClientProfileService();
        $this->assertSame('kelicore', $service->resolve($request)['name']);
        $request->headers->set('User-Agent', 'mihomo/1.19.33');
        $this->assertNotSame('kelicore', $service->resolve($request)['name']);
    }

    public function test_transports_and_reality_are_preserved(): void
    {
        $settings = ['tls' => 1, 'network' => 'httpupgrade', 'network_settings' => ['path' => '/tunnel', 'host' => 'edge.example.test']];
        $config = $this->config([$this->node('vmess', $settings), $this->node('trojan', $settings),
            $this->node('vless', ['tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'reality_settings' => ['server_name' => 'cover.example.test', 'public_key' => 'public', 'short_id' => 'abcd']])]);
        $this->assertSame('httpupgrade', $config['outbounds'][0]['transport']['type']);
        $this->assertSame('/tunnel', $config['outbounds'][1]['transport']['path']);
        $this->assertTrue($config['outbounds'][2]['tls']['reality']['enabled']);
    }

    public function test_ech_gecko_and_hopping_survive_native_export(): void
    {
        require_once dirname(__DIR__, 3) . '/scripts/export-kelicore-acceptance.php';
        $node = $this->node('hysteria', ['version' => 2, 'hop_interval' => 15,
            'ech' => ['enabled' => true, 'config' => \keliCoreAcceptanceEchConfig()],
            'obfs' => ['open' => true, 'type' => 'gecko', 'password' => 'obfs-test-password'],
            'network_settings' => ['gecko_min_packet_size' => 64, 'gecko_max_packet_size' => 1200]]);
        $node['ports'] = '20000-20002';
        $config = $this->config([$node]);
        $outbound = $config['outbounds'][0];
        $this->assertSame(['20000:20002'], $outbound['server_ports']);
        $this->assertSame('15s', $outbound['hop_interval']);
        $this->assertTrue($outbound['tls']['ech']['enabled']);
        $this->assertSame(64, $outbound['obfs']['min_packet_size']);
    }

    public function test_unsupported_options_and_pending_ech_do_not_silently_downgrade(): void
    {
        $nodes = [$this->node('vless', ['network' => 'xhttp']), $this->node('tuic', ['version' => 5, 'zero_rtt_handshake' => true]),
            $this->node('anytls', ['tls_mode' => 2]), $this->node('hysteria', ['version' => 2,
                'ech' => ['enabled' => true, 'mode' => 'automatic', 'state' => 'pending']])];
        $config = $this->config($nodes);
        $this->assertSame([], $config['outbounds']);
        $this->assertCount(4, $config['keli']['skipped']);
    }

    public function test_missing_hysteria_version_keeps_the_legacy_hy1_default(): void
    {
        $config = $this->config([$this->node('hysteria')]);
        $this->assertSame([], $config['outbounds']);
        $this->assertStringContainsString('Hysteria 2', $config['keli']['skipped'][0]['reason']);
    }

    public function test_extended_native_export_is_version_gated_and_preserves_security(): void
    {
        require_once dirname(__DIR__, 3) . '/scripts/export-kelicore-acceptance.php';
        $config = \keliCoreAcceptanceProfile(true);
        $this->assertCount(17, $config['outbounds']);
        $this->assertCount(2, $config['keli']['skipped']);
        $trust = array_values(array_filter($config['outbounds'], fn ($node) => $node['type'] === 'trusttunnel'));
        $this->assertCount(3, $trust);
        $this->assertFalse($trust[0]['tls']['insecure']);
        $this->assertSame('quic', $trust[2]['transport']['type']);
        foreach ([$this->node('trusttunnel'), $this->node('shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm'])] as $node) {
            $this->assertFalse(KeliCoreCapabilities::supports($node, '0.1.425')->supported);
        }
        $ech = $this->node('trusttunnel', ['ech' => ['enabled' => true, 'config' => \keliCoreAcceptanceEchConfig()]]);
        $this->assertFalse(KeliCoreCapabilities::supports($ech, '0.1.426')->supported);
    }

    public function test_native_trusttunnel_self_signed_nodes_require_their_bound_pin(): void
    {
        $resolver = new class extends \App\Services\ServerTlsCertificateResolver {
            public ?string $pin = null;
            public function resolveTrustTunnelPinSha256(array|\App\Models\Server $server): ?string
            {
                return $this->pin;
            }
        };
        app()->instance(\App\Services\ServerTlsCertificateResolver::class, $resolver);
        $node = $this->node('trusttunnel', ['tls_settings' => ['cert_mode' => 'self']]);
        $this->assertFalse(KeliCoreCapabilities::supports($node, '0.1.426')->supported);
        $resolver->pin = str_repeat('ab', 32);
        $config = (new KeliCore(['uuid' => 'test'], [$node], 'kelicore', '0.1.426'))->generateConfig();
        $this->assertSame($resolver->pin, $config['outbounds'][0]['tls']['certificate_sha256']);
        $this->assertFalse($config['outbounds'][0]['tls']['insecure']);
    }
}
