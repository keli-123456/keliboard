<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Models\Server;
use App\Protocols\QuantumultX;
use App\Protocols\Stash;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use Tests\TestCase;

final class ProtocolExportRegressionTest extends TestCase
{
    public function test_quantumultx_build_vless_reality_exports_required_fields(): void
    {
        $server = [
            'name' => 'QX Reality',
            'host' => 'edge.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'ws',
                'flow' => 'xtls-rprx-vision',
                'reality_settings' => [
                    'allow_insecure' => true,
                    'server_name' => 'reality.example.com',
                    'public_key' => 'pubkey123',
                    'short_id' => 'a1b2c3d4',
                ],
                'network_settings' => [
                    'path' => '/ray',
                    'headers' => [
                        'Host' => 'cdn.example.com',
                    ],
                ],
            ],
        ];

        $uri = QuantumultX::buildVless('user-uuid', $server);

        $this->assertStringContainsString('vless=edge.example.com:443', $uri);
        $this->assertStringContainsString('reality-base64-pubkey=pubkey123', $uri);
        $this->assertStringContainsString('reality-hex-shortid=a1b2c3d4', $uri);
        $this->assertStringContainsString('vless-flow=xtls-rprx-vision', $uri);
        $this->assertStringContainsString('obfs=wss', $uri);
        $this->assertStringContainsString('obfs-host=cdn.example.com', $uri);
        $this->assertStringContainsString('obfs-uri=/ray', $uri);
        $this->assertStringContainsString('tls-verification=false', $uri);
    }

    public function test_quantumultx_skips_unsupported_vless_networks(): void
    {
        $server = [
            'name' => 'QX gRPC',
            'host' => 'grpc.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'grpc',
            ],
        ];

        $this->assertSame('', QuantumultX::buildVless('user-uuid', $server));
    }

    public function test_surge_build_anytls_reads_nested_tls_fields(): void
    {
        $server = [
            'name' => 'AnyTLS',
            'host' => 'anytls.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'secure.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ];

        $uri = Surge::buildAnyTLS('user-password', $server);

        $this->assertStringContainsString('AnyTLS=anytls', $uri);
        $this->assertStringContainsString('password=user-password', $uri);
        $this->assertStringContainsString('skip-cert-verify=true', $uri);
        $this->assertStringContainsString('sni=secure.example.com', $uri);
    }

    public function test_surge_build_hysteria2_exports_version_two_nodes_only(): void
    {
        $v2 = Surge::buildHysteria('secret', [
            'name' => 'Hy2',
            'host' => 'hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'hy-sni.example.com',
                    'allow_insecure' => true,
                ],
                'bandwidth' => [
                    'up' => 100,
                    'down' => 200,
                ],
            ],
        ]);

        $v1 = Surge::buildHysteria('secret', [
            'name' => 'Hy1',
            'host' => 'hy1.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 1,
                'tls' => [
                    'server_name' => 'legacy.example.com',
                ],
            ],
        ]);

        $this->assertStringContainsString('Hy2=hysteria2', $v2);
        $this->assertStringContainsString('sni=hy-sni.example.com', $v2);
        $this->assertStringContainsString('upload-bandwidth=100', $v2);
        $this->assertStringContainsString('download-bandwidth=200', $v2);
        $this->assertSame('', $v1);
    }

    public function test_stash_build_vless_reality_exports_reality_options(): void
    {
        $config = (new Stash([], []))->buildVless('user-uuid', [
            'name' => 'Stash Reality',
            'host' => 'stash.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 2,
                'flow' => 'xtls-rprx-vision',
                'network' => 'ws',
                'reality_settings' => [
                    'server_name' => 'stash-sni.example.com',
                    'public_key' => 'stash-public-key',
                    'short_id' => 'stash-short-id',
                ],
                'network_settings' => [
                    'path' => '/stash',
                    'headers' => [
                        'Host' => 'cdn.stash.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertSame('vless', $config['type']);
        $this->assertTrue($config['tls']);
        $this->assertSame('stash-sni.example.com', $config['servername']);
        $this->assertSame('stash-sni.example.com', $config['sni']);
        $this->assertSame('xtls-rprx-vision', $config['flow']);
        $this->assertSame('stash-public-key', $config['reality-opts']['public-key']);
        $this->assertSame('stash-short-id', $config['reality-opts']['short-id']);
        $this->assertSame('ws', $config['network']);
        $this->assertSame('/stash', $config['ws-opts']['path']);
        $this->assertSame('cdn.stash.example.com', $config['ws-opts']['headers']['Host']);
    }

    public function test_stash_build_hysteria_marks_version_two_as_hysteria2(): void
    {
        $config = Stash::buildHysteria('secret', [
            'name' => 'Stash Hy2',
            'host' => 'hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => [
                    'up' => 50,
                    'down' => 80,
                ],
                'tls' => [
                    'server_name' => 'stash-hy.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertSame('hysteria2', $config['type']);
        $this->assertSame('secret', $config['auth']);
        $this->assertSame('stash-hy.example.com', $config['sni']);
        $this->assertTrue($config['skip-cert-verify']);
    }

    public function test_surfboard_build_anytls_keeps_reuse_disabled_and_sni(): void
    {
        $uri = Surfboard::buildAnyTLS('user-password', [
            'name' => 'Surfboard AnyTLS',
            'host' => 'surf.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'surf-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString('Surfboard AnyTLS=anytls', $uri);
        $this->assertStringContainsString('skip-cert-verify=true', $uri);
        $this->assertStringContainsString('sni=surf-sni.example.com', $uri);
        $this->assertStringContainsString('reuse=false', $uri);
    }

    public function test_surge_filters_out_hysteria2_for_old_client_versions(): void
    {
        $protocol = new class([], [[
            'name' => 'Legacy Hy2',
            'type' => Server::TYPE_HYSTERIA,
            'protocol_settings' => ['version' => 2],
        ]], 'surge', '2390') extends Surge {
            public function exposeServers(): array
            {
                return $this->servers;
            }

            public function handle()
            {
                return $this->servers;
            }
        };

        $this->assertSame([], $protocol->exposeServers());
    }

    public function test_stash_filters_out_hysteria2_for_old_client_versions(): void
    {
        $protocol = new class([], [[
            'name' => 'Legacy Stash Hy2',
            'type' => Server::TYPE_HYSTERIA,
            'protocol_settings' => ['version' => 2],
        ]], 'stash', '2.4.0') extends Stash {
            public function exposeServers(): array
            {
                return $this->servers;
            }

            public function handle()
            {
                return $this->servers;
            }
        };

        $this->assertSame([], $protocol->exposeServers());
    }
}
