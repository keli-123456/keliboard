<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Models\Server;
use App\Protocols\SingBox;
use App\Support\ProtocolManager;
use Illuminate\Container\Container;
use Tests\TestCase;

final class SingBoxRegressionTest extends TestCase
{
    private function makeProtocol(): SingBox
    {
        return new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildHysteriaForTest(string $password, array $server): array
            {
                return $this->buildHysteria($password, $server);
            }

            public function buildTrojanForTest(string $password, array $server): array
            {
                return $this->buildTrojan($password, $server);
            }

            public function buildNaiveForTest(string $password, array $server): array
            {
                return $this->buildNaive($password, $server);
            }

            public function buildAnyTlsForTest(string $password, array $server): array
            {
                return $this->buildAnyTLS($password, $server);
            }
        };
    }

    public function test_protocol_manager_matches_karing_to_singbox_exporter(): void
    {
        $manager = new ProtocolManager(new Container());

        $reflection = new \ReflectionProperty(ProtocolManager::class, 'protocolClasses');
        $reflection->setAccessible(true);
        $reflection->setValue($manager, [SingBox::class]);

        $this->assertSame(SingBox::class, $manager->matchProtocolClassName('Karing/1.2.8.1103'));
    }

    public function test_singbox_build_hysteria2_port_hopping_uses_colon_ranges_and_keeps_base_port(): void
    {
        $protocol = $this->makeProtocol();

        $config = $protocol->buildHysteriaForTest('secret', [
            'name' => 'Hy2 Port Hopping',
            'host' => 'hy2.example.com',
            'port' => 8443,
            'ports' => '2080-3000',
            'protocol_settings' => [
                'version' => '2',
                'hop_interval' => 30,
                'tls' => [
                    'server_name' => 'hy2-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('hysteria2', $config['type']);
        $this->assertSame(8443, $config['server_port']);
        $this->assertSame(['2080:3000'], $config['server_ports']);
        $this->assertSame('30s', $config['hop_interval']);
    }

    public function test_singbox_build_trojan_ws_uses_server_name_as_host_and_does_not_force_early_data(): void
    {
        $protocol = $this->makeProtocol();

        $config = $protocol->buildTrojanForTest('secret', [
            'name' => 'Trojan WS',
            'host' => 'edge.example.com',
            'port' => 443,
            'protocol_settings' => [
                'server_name' => 'sni.example.com',
                'network' => 'ws',
                'network_settings' => [
                    'path' => '/music',
                ],
            ],
        ]);

        $this->assertSame('ws', $config['transport']['type']);
        $this->assertSame('/music', $config['transport']['path']);
        $this->assertSame('sni.example.com', $config['transport']['headers']['Host']);
        $this->assertArrayNotHasKey('max_early_data', $config['transport']);
        $this->assertArrayNotHasKey('early_data_header_name', $config['transport']);
    }

    public function test_singbox_full_app_config_keeps_auto_select_default(): void
    {
        $servers = [
            [
                'name' => 'edge-a',
                'type' => Server::TYPE_SHADOWSOCKS,
                'host' => 'edge-a.example.com',
                'port' => 8388,
                'password' => 'password-a',
                'protocol_settings' => [
                    'cipher' => 'aes-128-gcm',
                ],
            ],
            [
                'name' => 'edge-b',
                'type' => Server::TYPE_SHADOWSOCKS,
                'host' => 'edge-b.example.com',
                'port' => 8389,
                'password' => 'password-b',
                'protocol_settings' => [
                    'cipher' => 'aes-128-gcm',
                ],
            ],
        ];
        $protocol = new class(['uuid' => 'user-uuid'], $servers, 'sing-box', '1.13.11') extends SingBox {
            public function handle()
            {
                return [];
            }
        };

        $config = $protocol->generateConfig();

        $selector = collect($config['outbounds'])->firstWhere('tag', '节点选择');
        $auto = collect($config['outbounds'])->firstWhere('tag', '自动选择');
        $this->assertSame('自动选择', $selector['default']);
        $this->assertContains('edge-a', $auto['outbounds']);
        $this->assertContains('edge-b', $auto['outbounds']);
    }

    public function test_singbox_single_app_config_can_pin_default_node(): void
    {
        $servers = [
            [
                'name' => 'edge-b',
                'type' => Server::TYPE_SHADOWSOCKS,
                'host' => 'edge-b.example.com',
                'port' => 8389,
                'password' => 'password-b',
                'protocol_settings' => [
                    'cipher' => 'aes-128-gcm',
                ],
            ],
        ];
        $protocol = new class(['uuid' => 'user-uuid'], $servers, 'sing-box', '1.13.11') extends SingBox {
            public function handle()
            {
                return [];
            }
        };

        $config = $protocol->generateConfig(defaultOutboundTag: 'edge-b');

        $selector = collect($config['outbounds'])->firstWhere('tag', '节点选择');
        $this->assertSame('edge-b', $selector['default']);
        $this->assertContains('edge-b', $selector['outbounds']);
    }

    public function test_singbox_build_naive_tcp_exports_official_outbound_fields(): void
    {
        $protocol = $this->makeProtocol();

        $config = $protocol->buildNaiveForTest('user-uuid', [
            'name' => 'Naive TCP',
            'host' => 'naive.example.com',
            'port' => 443,
            'protocol_settings' => [
                'network' => 'tcp',
                'tls' => 1,
                'tls_settings' => [
                    'server_name' => 'sni.example.com',
                    'allow_insecure' => true,
                    'alpn' => ['h2', 'http/1.1'],
                ],
            ],
        ]);

        $this->assertSame('naive', $config['type']);
        $this->assertSame('Naive TCP', $config['tag']);
        $this->assertSame('naive.example.com', $config['server']);
        $this->assertSame(443, $config['server_port']);
        $this->assertSame('user-uuid', $config['username']);
        $this->assertSame('user-uuid', $config['password']);
        $this->assertArrayNotHasKey('quic', $config);
        $this->assertSame([
            'server_name' => 'sni.example.com',
        ], $config['tls']);
    }

    public function test_singbox_build_naive_quic_sets_quic_flag(): void
    {
        $protocol = $this->makeProtocol();

        $config = $protocol->buildNaiveForTest('user-uuid', [
            'name' => 'Naive QUIC',
            'host' => 'naive.example.com',
            'port' => 443,
            'protocol_settings' => [
                'network' => 'quic',
                'tls' => 1,
                'tls_settings' => [
                    'server_name' => 'sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('naive', $config['type']);
        $this->assertTrue($config['quic']);
        $this->assertSame('sni.example.com', $config['tls']['server_name']);
    }

    public function test_singbox_full_app_config_includes_naive_outbound(): void
    {
        $servers = [
            [
                'name' => 'Naive TCP',
                'type' => Server::TYPE_NAIVE,
                'host' => 'naive.example.com',
                'port' => 443,
                'protocol_settings' => [
                    'network' => 'tcp',
                    'tls' => 1,
                    'tls_settings' => [
                        'server_name' => 'sni.example.com',
                    ],
                ],
            ],
        ];
        $protocol = new class(['uuid' => 'user-uuid'], $servers, 'sing-box', '1.13.11') extends SingBox {
            public function handle()
            {
                return [];
            }
        };

        $config = $protocol->generateConfig(defaultOutboundTag: 'Naive TCP');

        $outbound = collect($config['outbounds'])->firstWhere('tag', 'Naive TCP');
        $selector = collect($config['outbounds'])->firstWhere('tag', '节点选择');
        $this->assertSame('naive', $outbound['type']);
        $this->assertSame('Naive TCP', $selector['default']);
        $this->assertContains('Naive TCP', $selector['outbounds']);
    }

    public function test_singbox_build_anytls_reality_exports_shared_tls_reality_fields(): void
    {
        $protocol = $this->makeProtocol();

        $config = $protocol->buildAnyTlsForTest('user-password', [
            'name' => 'AnyTLS Reality',
            'host' => 'anytls.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'tls_mode' => 2,
                'reality_settings' => [
                    'server_name' => 'addons.mozilla.org',
                    'public_key' => 'pubkey123',
                    'short_id' => 'a1b2c3d4',
                ],
                'client_fingerprint' => 'chrome',
            ],
        ]);

        $this->assertSame('anytls', $config['type']);
        $this->assertSame('addons.mozilla.org', $config['tls']['server_name']);
        $this->assertSame([
            'enabled' => true,
            'public_key' => 'pubkey123',
            'short_id' => 'a1b2c3d4',
        ], $config['tls']['reality']);
        $this->assertSame('chrome', $config['tls']['utls']['fingerprint']);
    }
}
