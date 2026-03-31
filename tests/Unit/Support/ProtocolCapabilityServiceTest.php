<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ProtocolCapabilityService;
use Tests\TestCase;

final class ProtocolCapabilityServiceTest extends TestCase
{
    private ProtocolCapabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProtocolCapabilityService(
            require dirname(__DIR__, 3) . '/config/protocol_capabilities.php'
        );
    }

    public function test_resolve_client_family_maps_legacy_mihomo_aliases(): void
    {
        $this->assertSame('mihomo', $this->service->resolveClientFamily('nekoray'));
        $this->assertSame('mihomo', $this->service->resolveClientFamily('clashx meta'));
    }

    public function test_sing_box_before_1_12_drops_anytls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('sing-box', '1.11.9', $server);

        $this->assertFalse($result->supported);
    }

    public function test_sing_box_1_12_keeps_anytls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_sing_box_keeps_vless_h2_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'h2',
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_sing_box_keeps_vless_quic_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'quic',
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_sing_box_drops_vless_xhttp_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'xhttp',
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_v2node_runtime_drops_vless_kcp_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'kcp',
        ]);

        $result = $this->service->supportsRuntime('v2node', $server);

        $this->assertFalse($result->supported);
    }

    public function test_mihomo_drops_anytls_reality(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 2,
            'reality_settings' => [
                'server_name' => 'example.com',
                'public_key' => 'pk',
                'short_id' => 'aa',
            ],
        ]);

        $result = $this->service->supportsClient('meta', '1.20.0', $server);

        $this->assertFalse($result->supported);
        $this->assertSame('drop', $result->action);
    }

    public function test_sing_box_drops_anytls_custom_transport(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'network' => 'ws',
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_mihomo_drops_anytls_custom_transport(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'network' => 'grpc',
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('meta', '1.20.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_drops_anytls_custom_transport(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'network' => 'httpupgrade',
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_mihomo_drops_vless_xhttp(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'xhttp',
            'flow' => 'xtls-rprx-vision',
        ]);

        $result = $this->service->supportsClient('verge', '1.7.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_clash_verge_before_required_version_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('verge', '1.3.7', $server);

        $this->assertFalse($result->supported);
    }

    public function test_clash_verge_required_version_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('verge', '1.3.8', $server);

        $this->assertTrue($result->supported);
    }

    public function test_nekoray_before_required_version_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('nekoray', '3.23', $server);

        $this->assertFalse($result->supported);
    }

    public function test_clash_meta_android_required_version_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('clashmetaforandroid', '2.9.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_stash_before_2_5_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('stash', '2.4.9', $server);

        $this->assertFalse($result->supported);
    }

    public function test_stash_before_2_6_4_drops_hysteria2_port_hopping(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
            'hop_interval' => 30,
        ], [
            'ports' => '20000-20100',
        ]);

        $result = $this->service->supportsClient('stash', '2.6.3', $server);

        $this->assertFalse($result->supported);
    }

    public function test_stash_2_6_4_keeps_hysteria2_port_hopping(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
            'hop_interval' => 30,
        ], [
            'ports' => '20000-20100',
        ]);

        $result = $this->service->supportsClient('stash', '2.6.4', $server);

        $this->assertTrue($result->supported);
    }

    public function test_stash_before_3_0_drops_shadowsocks_2022_cipher(): void
    {
        $server = $this->makeServer('shadowsocks', [
            'cipher' => '2022-blake3-aes-128-gcm',
        ]);

        $result = $this->service->supportsClient('stash', '2.9.9', $server);

        $this->assertFalse($result->supported);
    }

    public function test_stash_3_0_keeps_shadowsocks_2022_cipher(): void
    {
        $server = $this->makeServer('shadowsocks', [
            'cipher' => '2022-blake3-aes-128-gcm',
        ]);

        $result = $this->service->supportsClient('stash', '3.0.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_v2rayng_before_1_9_5_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('v2rayng', '1.9.4', $server);

        $this->assertFalse($result->supported);
    }

    public function test_v2rayng_1_9_5_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('v2rayng', '1.9.5', $server);

        $this->assertTrue($result->supported);
    }

    public function test_v2rayn_before_6_31_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('v2rayn', '6.30', $server);

        $this->assertFalse($result->supported);
    }

    public function test_v2rayng_drops_anytls_because_general_exporter_does_not_emit_it(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('v2rayng', '1.9.5', $server);

        $this->assertFalse($result->supported);
    }

    public function test_v2rayn_drops_tuic_because_general_exporter_does_not_emit_it(): void
    {
        $server = $this->makeServer('tuic', [
            'version' => 5,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('v2rayn', '6.31', $server);

        $this->assertFalse($result->supported);
    }

    public function test_sagernet_keeps_trojan_via_general_exporter_fallback(): void
    {
        $server = $this->makeServer('trojan', [
            'network' => 'tcp',
        ]);

        $result = $this->service->supportsClient('sagernet', '1.0.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_general_family_drops_hysteria1_because_exporter_only_emits_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('sagernet', '1.0.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_stash_2_5_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('stash', '2.5.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_shadowrocket_before_required_build_drops_anytls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2591', $server);

        $this->assertFalse($result->supported);
    }

    public function test_loon_before_required_build_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('loon', '636', $server);

        $this->assertFalse($result->supported);
    }

    public function test_loon_required_build_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('loon', '637', $server);

        $this->assertTrue($result->supported);
    }

    public function test_loon_drops_vless_because_exporter_does_not_emit_it(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'tcp',
        ]);

        $result = $this->service->supportsClient('loon', '637', $server);

        $this->assertFalse($result->supported);
    }

    public function test_quantumult_x_keeps_vless_ws_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'ws',
        ]);

        $result = $this->service->supportsClient('quantumult-x', '1.0.31', $server);

        $this->assertTrue($result->supported);
    }

    public function test_quantumult_x_drops_vless_grpc_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'grpc',
        ]);

        $result = $this->service->supportsClient('quantumult-x', '1.0.31', $server);

        $this->assertFalse($result->supported);
    }

    public function test_quantumult_x_drops_vmess_grpc_transport(): void
    {
        $server = $this->makeServer('vmess', [
            'tls' => 1,
            'network' => 'grpc',
        ]);

        $result = $this->service->supportsClient('quantumult-x', '1.0.31', $server);

        $this->assertFalse($result->supported);
    }

    public function test_quantumult_x_drops_trojan_grpc_transport(): void
    {
        $server = $this->makeServer('trojan', [
            'network' => 'grpc',
        ]);

        $result = $this->service->supportsClient('quantumult-x', '1.0.31', $server);

        $this->assertFalse($result->supported);
    }

    public function test_quantumult_x_drops_vless_encryption_extension(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'ws',
            'encryption' => 'mlkem768x25519plus',
            'encryption_settings' => [
                'mode' => 'native',
                'private_key' => 'secret',
            ],
        ]);

        $result = $this->service->supportsClient('quantumult-x', '1.0.31', $server);

        $this->assertFalse($result->supported);
    }

    public function test_surge_before_required_build_drops_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('surge', '2397', $server);

        $this->assertFalse($result->supported);
    }

    public function test_surge_required_build_keeps_hysteria2(): void
    {
        $server = $this->makeServer('hysteria', [
            'version' => 2,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('surge', '2398', $server);

        $this->assertTrue($result->supported);
    }

    public function test_surfboard_drops_vless_because_exporter_does_not_emit_it(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'tcp',
        ]);

        $result = $this->service->supportsClient('surfboard', '1.0.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_surfboard_keeps_anytls_via_protocol_whitelist(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('surfboard', '1.0.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_shadowrocket_drops_anytls_reality_because_exporter_only_emits_basic_tls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 2,
            'reality_settings' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_surge_drops_anytls_alpn_because_exporter_ignores_it(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
            'alpn' => ['h2'],
        ]);

        $result = $this->service->supportsClient('surge', '2398', $server);

        $this->assertFalse($result->supported);
    }

    public function test_stash_drops_tuic_zero_rtt_because_exporter_ignores_it(): void
    {
        $server = $this->makeServer('tuic', [
            'version' => 5,
            'zero_rtt_handshake' => true,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('stash', '3.0.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_drops_tuic_zero_rtt_because_exporter_ignores_it(): void
    {
        $server = $this->makeServer('tuic', [
            'version' => 5,
            'zero_rtt_handshake' => true,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_drops_tuic_quic_udp_mode_because_exporter_ignores_it(): void
    {
        $server = $this->makeServer('tuic', [
            'version' => 5,
            'udp_relay_mode' => 'quic',
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_drops_tuic_non_default_congestion_control_because_exporter_ignores_it(): void
    {
        $server = $this->makeServer('tuic', [
            'version' => 5,
            'congestion_control' => 'bbr',
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_surfboard_drops_anytls_idle_session_fields_because_exporter_ignores_them(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
            'idle_session_timeout' => 30,
        ]);

        $result = $this->service->supportsClient('surfboard', '1.0.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_required_build_keeps_anytls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertTrue($result->supported);
    }

    public function test_shadowrocket_drops_vless_splithttp_transport(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'splithttp',
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_drops_vless_splice_flow(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 2,
            'network' => 'tcp',
            'flow' => 'xtls-rprx-splice',
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertFalse($result->supported);
    }

    public function test_shadowrocket_keeps_vless_direct_flow(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 2,
            'network' => 'tcp',
            'flow' => 'xtls-rprx-direct',
        ]);

        $result = $this->service->supportsClient('shadowrocket', '2592', $server);

        $this->assertTrue($result->supported);
    }

    public function test_unknown_client_keeps_only_conservative_vless(): void
    {
        $server = $this->makeServer('vless', [
            'tls' => 1,
            'network' => 'ws',
        ]);

        $result = $this->service->supportsClient(null, null, $server);

        $this->assertTrue($result->supported);
    }

    public function test_unknown_client_drops_anytls(): void
    {
        $server = $this->makeServer('anytls', [
            'tls_mode' => 1,
            'tls' => ['server_name' => 'example.com'],
        ]);

        $result = $this->service->supportsClient(null, null, $server);

        $this->assertFalse($result->supported);
    }

    public function test_unmigrated_protocols_fall_back_to_legacy_exporters(): void
    {
        $server = $this->makeServer('trojan', [
            'network' => 'tcp',
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertTrue($result->supported);
    }

    public function test_sing_box_drops_protocols_not_emitted_by_its_exporter(): void
    {
        $server = $this->makeServer('naive', [
            'tls' => 1,
        ]);

        $result = $this->service->supportsClient('sing-box', '1.12.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_mihomo_drops_protocols_not_emitted_by_its_exporter(): void
    {
        $server = $this->makeServer('naive', [
            'tls' => 1,
        ]);

        $result = $this->service->supportsClient('meta', '1.20.0', $server);

        $this->assertFalse($result->supported);
    }

    public function test_runtime_drops_protocols_not_supported_by_v2node(): void
    {
        $server = $this->makeServer('http', [
            'tls' => 1,
        ]);

        $result = $this->service->supportsRuntime('v2node', $server);

        $this->assertFalse($result->supported);
    }

    private function makeServer(string $type, array $protocolSettings = [], array $extra = []): array
    {
        return array_merge([
            'type' => $type,
            'host' => 'node.example.com',
            'port' => 443,
            'server_port' => 443,
            'protocol_settings' => $protocolSettings,
        ], $extra);
    }
}
