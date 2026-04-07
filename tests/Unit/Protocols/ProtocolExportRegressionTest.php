<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Models\Server;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\Stash;
use App\Protocols\SingBox;
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

    public function test_quantumultx_skips_vless_encryption_extension(): void
    {
        $server = [
            'name' => 'QX Enc',
            'host' => 'enc.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'encryption' => 'mlkem768x25519plus',
                'encryption_settings' => [
                    'mode' => 'native',
                    'private_key' => 'secret',
                ],
            ],
        ];

        $this->assertSame('', QuantumultX::buildVless('user-uuid', $server));
    }

    public function test_quantumultx_skips_unsupported_vmess_and_trojan_networks(): void
    {
        $vmess = [
            'name' => 'QX VMess gRPC',
            'host' => 'vmess-grpc.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'grpc',
            ],
        ];
        $trojan = [
            'name' => 'QX Trojan gRPC',
            'host' => 'trojan-grpc.example.com',
            'port' => 443,
            'protocol_settings' => [
                'network' => 'grpc',
            ],
        ];

        $this->assertSame('', QuantumultX::buildVmess('user-uuid', $vmess));
        $this->assertSame('', QuantumultX::buildTrojan('secret', $trojan));
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

    public function test_stash_build_vless_h2_exports_h2_opts(): void
    {
        $config = (new Stash([], []))->buildVless('user-uuid', [
            'name' => 'Stash H2',
            'host' => 'stash-h2.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'h2',
                'tls_settings' => [
                    'server_name' => 'stash-h2-sni.example.com',
                ],
                'network_settings' => [
                    'host' => 'cdn.stash-h2.example.com',
                    'path' => '/h2',
                ],
            ],
        ]);

        $this->assertSame('vless', $config['type']);
        $this->assertTrue($config['tls']);
        $this->assertSame('stash-h2-sni.example.com', $config['servername']);
        $this->assertSame('h2', $config['network']);
        $this->assertSame(['cdn.stash-h2.example.com'], $config['h2-opts']['host']);
        $this->assertSame('/h2', $config['h2-opts']['path']);
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

    public function test_stash_build_anytls_defaults_client_fingerprint_when_not_configured(): void
    {
        $config = Stash::buildAnyTLS('secret', [
            'name' => 'Stash AnyTLS',
            'host' => 'stash-anytls.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'stash-anytls-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('anytls', $config['type']);
        $this->assertSame('firefox', $config['client-fingerprint']);
        $this->assertSame('stash-anytls-sni.example.com', $config['sni']);
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
        $this->assertStringContainsString(',user-password,', $uri);
        $this->assertStringNotContainsString('password=user-password', $uri);
        $this->assertStringContainsString('skip-cert-verify=true', $uri);
        $this->assertStringContainsString('sni=surf-sni.example.com', $uri);
        $this->assertStringContainsString('reuse=false', $uri);
    }

    public function test_surfboard_build_anytls_reads_legacy_tls_fields_and_skips_unsupported_modes(): void
    {
        $legacy = Surfboard::buildAnyTLS('user-password', [
            'name' => 'Surfboard Legacy AnyTLS',
            'host' => 'legacy.example.com',
            'port' => 9443,
            'server_name' => 'legacy-sni.example.com',
            'allow_insecure' => true,
            'protocol_settings' => [
                'tls_mode' => 1,
                'network' => 'tcp',
            ],
        ]);

        $reality = Surfboard::buildAnyTLS('user-password', [
            'name' => 'Surfboard Reality',
            'host' => 'reality.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls_mode' => 2,
            ],
        ]);

        $ws = Surfboard::buildAnyTLS('user-password', [
            'name' => 'Surfboard WS',
            'host' => 'ws.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls_mode' => 1,
                'network' => 'ws',
            ],
        ]);

        $this->assertStringContainsString('skip-cert-verify=true', $legacy);
        $this->assertStringContainsString('sni=legacy-sni.example.com', $legacy);
        $this->assertSame('', $reality);
        $this->assertSame('', $ws);
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

    public function test_loon_build_hysteria2_exports_download_bandwidth_and_sni(): void
    {
        $uri = Loon::buildHysteria('secret', [
            'name' => 'Loon Hy2',
            'host' => 'loon.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'loon-sni.example.com',
                    'allow_insecure' => true,
                ],
                'bandwidth' => [
                    'download_bandwidth' => 88,
                ],
            ],
        ], [
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 0,
            'expired_at' => 0,
        ]);

        $this->assertStringContainsString('Loon Hy2=Hysteria2', $uri);
        $this->assertStringContainsString('sni=loon-sni.example.com', $uri);
        $this->assertStringContainsString('skip-cert-verify=true', $uri);
        $this->assertStringContainsString('download-bandwidth=88', $uri);
    }

    public function test_loon_filters_out_hysteria2_for_old_client_versions(): void
    {
        $protocol = new class([], [[
            'name' => 'Legacy Loon Hy2',
            'type' => Server::TYPE_HYSTERIA,
            'protocol_settings' => ['version' => 2],
        ]], 'loon', '636') extends Loon {
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

    public function test_shadowrocket_build_vless_reality_exports_expected_query_fields(): void
    {
        $uri = Shadowrocket::buildVless('user-uuid', [
            'name' => 'SR Reality',
            'host' => 'sr.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'ws',
                'reality_settings' => [
                    'server_name' => 'sr-sni.example.com',
                    'public_key' => 'sr-public-key',
                    'short_id' => 'sr-short-id',
                ],
                'network_settings' => [
                    'path' => '/sr',
                    'headers' => [
                        'Host' => 'cdn.sr.example.com',
                    ],
                ],
                'client_fingerprint' => 'chrome',
            ],
        ]);

        $this->assertStringContainsString('vless://', $uri);
        $this->assertStringContainsString('sni=sr-sni.example.com', $uri);
        $this->assertStringContainsString('pbk=sr-public-key', $uri);
        $this->assertStringContainsString('sid=sr-short-id', $uri);
        $this->assertStringContainsString('fp=chrome', $uri);
        $this->assertStringContainsString('obfs=websocket', $uri);
        $this->assertStringContainsString('path=%2Fsr', $uri);
        $this->assertStringContainsString('obfsParam=cdn.sr.example.com', $uri);
    }

    public function test_shadowrocket_build_vless_tls_exports_client_fingerprint_when_configured(): void
    {
        $uri = Shadowrocket::buildVless('user-uuid', [
            'name' => 'SR TLS',
            'host' => 'sr-tls.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'server_name' => 'sr-tls-sni.example.com',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/sr-tls',
                    'headers' => [
                        'Host' => 'cdn.sr-tls.example.com',
                    ],
                ],
                'client_fingerprint' => 'chrome',
            ],
        ]);

        $this->assertStringContainsString('peer=sr-tls-sni.example.com', $uri);
        $this->assertStringContainsString('fp=chrome', $uri);
    }

    public function test_shadowrocket_build_vless_tls_defaults_client_fingerprint_when_not_configured(): void
    {
        $uri = Shadowrocket::buildVless('user-uuid', [
            'name' => 'SR TLS Default FP',
            'host' => 'sr-tls-default.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'server_name' => 'sr-tls-default-sni.example.com',
                    'allow_insecure' => false,
                ],
                'network_settings' => [
                    'path' => '/sr-default',
                    'headers' => [
                        'Host' => 'cdn.sr-default.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('peer=sr-tls-default-sni.example.com', $uri);
        $this->assertStringContainsString('fp=firefox', $uri);
    }

    public function test_shadowrocket_build_hysteria2_and_anytls_export_current_fields(): void
    {
        $hysteria = Shadowrocket::buildHysteria('secret', [
            'name' => 'SR Hy2',
            'host' => 'sr-hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'sr-hy-sni.example.com',
                    'allow_insecure' => true,
                ],
                'obfs' => [
                    'open' => true,
                    'type' => 'salamander',
                    'password' => 'mask-pass',
                ],
                'hop_interval' => 30,
            ],
            'ports' => '30000-30100',
        ]);
        $anytls = Shadowrocket::buildAnyTLS('secret', [
            'name' => 'SR AnyTLS',
            'host' => 'sr-anytls.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'sr-anytls-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString('hysteria2://secret@sr-hy.example.com:8443', $hysteria);
        $this->assertStringContainsString('peer=sr-hy-sni.example.com', $hysteria);
        $this->assertStringContainsString('obfs=salamander', $hysteria);
        $this->assertStringContainsString('obfs-password=mask-pass', $hysteria);
        $this->assertStringContainsString('keepalive=30', $hysteria);
        $this->assertStringContainsString('mport=30000-30100', $hysteria);
        $this->assertStringContainsString('anytls://secret@sr-anytls.example.com:9443', $anytls);
        $this->assertStringContainsString('sni=sr-anytls-sni.example.com', $anytls);
        $this->assertStringContainsString('insecure=1', $anytls);
    }

    public function test_clashmeta_build_vless_reality_exports_meta_specific_fields(): void
    {
        $config = ClashMeta::buildVless('user-uuid', [
            'name' => 'Meta Reality',
            'host' => 'meta.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'ws',
                'reality_settings' => [
                    'allow_insecure' => true,
                    'server_name' => 'meta-sni.example.com',
                    'public_key' => 'meta-public-key',
                    'short_id' => 'meta-short-id',
                ],
                'client_fingerprint' => 'chrome',
                'network_settings' => [
                    'path' => '/meta',
                    'headers' => [
                        'Host' => 'cdn.meta.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertSame('vless', $config['type']);
        $this->assertTrue($config['tls']);
        $this->assertTrue($config['skip-cert-verify']);
        $this->assertSame('meta-sni.example.com', $config['servername']);
        $this->assertSame('meta-public-key', $config['reality-opts']['public-key']);
        $this->assertSame('meta-short-id', $config['reality-opts']['short-id']);
        $this->assertSame('chrome', $config['client-fingerprint']);
        $this->assertSame('ws', $config['network']);
        $this->assertSame('/meta', $config['ws-opts']['path']);
        $this->assertSame('cdn.meta.example.com', $config['ws-opts']['headers']['Host']);
    }

    public function test_clashmeta_build_vless_tls_exports_client_fingerprint_when_configured(): void
    {
        $config = ClashMeta::buildVless('user-uuid', [
            'name' => 'Meta TLS',
            'host' => 'meta-tls.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'allow_insecure' => false,
                    'server_name' => 'meta-tls-sni.example.com',
                ],
                'client_fingerprint' => 'chrome',
                'network_settings' => [
                    'path' => '/meta-tls',
                    'headers' => [
                        'Host' => 'cdn.meta-tls.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config['tls']);
        $this->assertSame('meta-tls-sni.example.com', $config['servername']);
        $this->assertSame('chrome', $config['client-fingerprint']);
    }

    public function test_clashmeta_build_vless_tls_defaults_client_fingerprint_when_not_configured(): void
    {
        $config = ClashMeta::buildVless('user-uuid', [
            'name' => 'Meta TLS Default FP',
            'host' => 'meta-tls-default.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'allow_insecure' => false,
                    'server_name' => 'meta-tls-default-sni.example.com',
                ],
                'network_settings' => [
                    'path' => '/meta-default',
                    'headers' => [
                        'Host' => 'cdn.meta-default.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertTrue($config['tls']);
        $this->assertSame('meta-tls-default-sni.example.com', $config['servername']);
        $this->assertSame('firefox', $config['client-fingerprint']);
    }

    public function test_general_build_vless_tls_uses_configured_client_fingerprint(): void
    {
        $uri = General::buildVless('user-uuid', [
            'name' => 'General TLS',
            'host' => 'general.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'ws',
                'tls_settings' => [
                    'server_name' => 'general-sni.example.com',
                ],
                'network_settings' => [
                    'path' => '/general',
                    'headers' => [
                        'Host' => 'cdn.general.example.com',
                    ],
                ],
                'client_fingerprint' => 'chrome',
            ],
        ]);

        $this->assertStringContainsString('security=tls', $uri);
        $this->assertStringContainsString('fp=chrome', $uri);
        $this->assertStringContainsString('sni=general-sni.example.com', $uri);
    }

    public function test_clashmeta_build_vless_http_and_h2_export_transport_specific_options(): void
    {
        $http = ClashMeta::buildVless('user-uuid', [
            'name' => 'Meta HTTP',
            'host' => 'meta-http.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'http',
                'tls_settings' => [
                    'server_name' => 'meta-http-sni.example.com',
                ],
                'network_settings' => [
                    'path' => '/http',
                    'headers' => [
                        'Host' => 'cdn.meta-http.example.com',
                        'X-Test' => '1',
                    ],
                ],
            ],
        ]);

        $h2 = ClashMeta::buildVless('user-uuid', [
            'name' => 'Meta H2',
            'host' => 'meta-h2.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'h2',
                'tls_settings' => [
                    'server_name' => 'meta-h2-sni.example.com',
                ],
                'network_settings' => [
                    'host' => 'cdn.meta-h2.example.com',
                    'path' => '/h2',
                ],
            ],
        ]);

        $this->assertSame('http', $http['network']);
        $this->assertSame('/http', $http['http-opts']['path']);
        $this->assertSame('cdn.meta-http.example.com', $http['http-opts']['headers']['Host']);
        $this->assertSame('1', $http['http-opts']['headers']['X-Test']);

        $this->assertSame('h2', $h2['network']);
        $this->assertSame(['cdn.meta-h2.example.com'], $h2['h2-opts']['host']);
        $this->assertSame('/h2', $h2['h2-opts']['path']);
    }

    public function test_clashmeta_build_hysteria2_and_anytls_export_current_shape(): void
    {
        $hysteria = ClashMeta::buildHysteria('secret', [
            'name' => 'Meta Hy2',
            'host' => 'meta-hy.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 2,
                'tls' => [
                    'server_name' => 'meta-hy-sni.example.com',
                    'allow_insecure' => true,
                ],
                'bandwidth' => [
                    'up' => 120,
                    'down' => 240,
                ],
                'obfs' => [
                    'open' => true,
                    'type' => 'salamander',
                    'password' => 'meta-mask',
                ],
            ],
        ], []);
        $anytls = ClashMeta::buildAnyTLS('secret', [
            'name' => 'Meta AnyTLS',
            'host' => 'meta-anytls.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'client_fingerprint' => 'chrome',
                'alpn' => ['h2', 'http/1.1'],
                'idle_session_check_interval' => 45,
                'idle_session_timeout' => 60,
                'min_idle_session' => 2,
                'tls' => [
                    'server_name' => 'meta-anytls-sni.example.com',
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertSame('hysteria2', $hysteria['type']);
        $this->assertSame('secret', $hysteria['password']);
        $this->assertSame('meta-hy-sni.example.com', $hysteria['sni']);
        $this->assertSame('salamander', $hysteria['obfs']);
        $this->assertSame('meta-mask', $hysteria['obfs-password']);
        $this->assertSame('anytls', $anytls['type']);
        $this->assertSame('chrome', $anytls['client-fingerprint']);
        $this->assertSame(['h2', 'http/1.1'], $anytls['alpn']);
        $this->assertSame(45, $anytls['idle-session-check-interval']);
        $this->assertSame(60, $anytls['idle-session-timeout']);
        $this->assertSame(2, $anytls['min-idle-session']);
        $this->assertSame('meta-anytls-sni.example.com', $anytls['sni']);
        $this->assertTrue($anytls['skip-cert-verify']);
    }

    public function test_clashmeta_build_anytls_defaults_client_fingerprint_when_not_configured(): void
    {
        $config = ClashMeta::buildAnyTLS('secret', [
            'name' => 'Meta AnyTLS Default FP',
            'host' => 'meta-anytls-default.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'meta-anytls-default-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('anytls', $config['type']);
        $this->assertSame('firefox', $config['client-fingerprint']);
        $this->assertSame('meta-anytls-default-sni.example.com', $config['sni']);
    }

    public function test_clashmeta_filters_out_hysteria2_for_old_clients(): void
    {
        $protocol = new class([], [[
            'name' => 'Legacy Meta Hy2',
            'type' => Server::TYPE_HYSTERIA,
            'protocol_settings' => ['version' => 2],
        ]], 'clashmetaforandroid', '2.8.9') extends ClashMeta {
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

    public function test_singbox_build_vless_reality_and_anytls_export_expected_fields(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildVlessForTest(string $password, array $server): array
            {
                return $this->buildVless($password, $server);
            }

            public function buildAnyTlsForTest(string $password, array $server): array
            {
                return $this->buildAnyTLS($password, $server);
            }
        };

        $vless = $protocol->buildVlessForTest('user-uuid', [
            'name' => 'SingBox Reality',
            'host' => 'sb.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'ws',
                'flow' => 'xtls-rprx-vision',
                'client_fingerprint' => 'chrome',
                'reality_settings' => [
                    'server_name' => 'sb-sni.example.com',
                    'public_key' => 'sb-public-key',
                    'short_id' => 'sb-short-id',
                ],
                'network_settings' => [
                    'path' => '/sb',
                    'headers' => [
                        'Host' => 'cdn.sb.example.com',
                    ],
                ],
            ],
        ]);
        $anytls = $protocol->buildAnyTlsForTest('secret', [
            'name' => 'SingBox AnyTLS',
            'host' => 'sb-anytls.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'client_fingerprint' => 'chrome',
                'idle_session_check_interval' => 45,
                'idle_session_timeout' => 60,
                'min_idle_session' => 2,
                'tls' => [
                    'server_name' => 'sb-anytls-sni.example.com',
                    'allow_insecure' => true,
                ],
                'alpn' => ['h2', 'http/1.1'],
            ],
        ]);

        $this->assertSame('vless', $vless['type']);
        $this->assertSame('sb-sni.example.com', $vless['tls']['server_name']);
        $this->assertSame('sb-public-key', $vless['tls']['reality']['public_key']);
        $this->assertSame('sb-short-id', $vless['tls']['reality']['short_id']);
        $this->assertSame('chrome', $vless['tls']['utls']['fingerprint']);
        $this->assertSame('ws', $vless['transport']['type']);
        $this->assertSame('/sb', $vless['transport']['path']);
        $this->assertSame('cdn.sb.example.com', $vless['transport']['headers']['Host']);
        $this->assertSame('anytls', $anytls['type']);
        $this->assertSame('sb-anytls-sni.example.com', $anytls['tls']['server_name']);
        $this->assertTrue($anytls['tls']['insecure']);
        $this->assertSame(['h2', 'http/1.1'], $anytls['tls']['alpn']);
        $this->assertTrue($anytls['tls']['utls']['enabled']);
        $this->assertSame('chrome', $anytls['tls']['utls']['fingerprint']);
        $this->assertSame('45s', $anytls['idle_session_check_interval']);
        $this->assertSame('60s', $anytls['idle_session_timeout']);
        $this->assertSame(2, $anytls['min_idle_session']);
    }

    public function test_singbox_build_anytls_defaults_client_fingerprint_when_not_configured(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildAnyTlsForTest(string $password, array $server): array
            {
                return $this->buildAnyTLS($password, $server);
            }
        };

        $config = $protocol->buildAnyTlsForTest('secret', [
            'name' => 'SingBox AnyTLS Default FP',
            'host' => 'sb-anytls-default.example.com',
            'port' => 9443,
            'protocol_settings' => [
                'tls' => [
                    'server_name' => 'sb-anytls-default-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('anytls', $config['type']);
        $this->assertSame('sb-anytls-default-sni.example.com', $config['tls']['server_name']);
        $this->assertTrue($config['tls']['utls']['enabled']);
        $this->assertSame('firefox', $config['tls']['utls']['fingerprint']);
    }

    public function test_singbox_build_hysteria2_port_hopping_uses_colon_range_and_keeps_base_port(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildHysteriaForTest(string $password, array $server): array
            {
                return $this->buildHysteria($password, $server);
            }
        };

        $hysteria = $protocol->buildHysteriaForTest('user-uuid', [
            'name' => 'SingBox HY2 Hop',
            'host' => 'hy2.example.com',
            'port' => 34456,
            'ports' => '34000-35000',
            'protocol_settings' => [
                'version' => 2,
                'hop_interval' => 10,
                'bandwidth' => [
                    'up' => 1000,
                    'down' => 1000,
                ],
                'tls' => [
                    'allow_insecure' => true,
                    'server_name' => 'sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('hysteria2', $hysteria['type']);
        $this->assertSame(34456, $hysteria['server_port']);
        $this->assertSame(['34000:35000'], $hysteria['server_ports']);
        $this->assertSame('10s', $hysteria['hop_interval']);
        $this->assertSame('sni.example.com', $hysteria['tls']['server_name']);
    }

    public function test_singbox_build_vless_h2_and_quic_transport_export_expected_fields(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildVlessForTest(string $password, array $server): array
            {
                return $this->buildVless($password, $server);
            }
        };

        $h2 = $protocol->buildVlessForTest('user-uuid', [
            'name' => 'SingBox H2',
            'host' => 'sb-h2.example.com',
            'port' => 443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'h2',
                'tls_settings' => [
                    'server_name' => 'sb-h2-sni.example.com',
                ],
                'network_settings' => [
                    'host' => 'cdn.sb-h2.example.com',
                    'path' => '/h2',
                    'headers' => [
                        'X-Test' => '1',
                    ],
                ],
            ],
        ]);

        $quic = $protocol->buildVlessForTest('user-uuid', [
            'name' => 'SingBox QUIC',
            'host' => 'sb-quic.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'quic',
                'tls_settings' => [
                    'server_name' => 'sb-quic-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('http', $h2['transport']['type']);
        $this->assertSame(['cdn.sb-h2.example.com'], $h2['transport']['host']);
        $this->assertSame('/h2', $h2['transport']['path']);
        $this->assertSame(['X-Test' => '1'], $h2['transport']['headers']);
        $this->assertSame('quic', $quic['transport']['type']);
    }

    public function test_singbox_build_outbounds_keeps_h2_and_quic_but_skips_xhttp(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildOutboundsForTest(array $servers): array
            {
                $this->servers = $servers;
                $this->user = ['uuid' => 'user-uuid'];
                $config = [
                    'outbounds' => [
                        [
                            'type' => 'selector',
                            'tag' => '节点选择',
                            'outbounds' => [],
                        ],
                    ],
                    'route' => [
                        'rules' => [],
                    ],
                ];

                $reflection = new \ReflectionProperty(SingBox::class, 'config');
                $reflection->setAccessible(true);
                $reflection->setValue($this, $config);
                $this->buildOutbounds();

                return $reflection->getValue($this)['outbounds'];
            }
        };

        $outbounds = $protocol->buildOutboundsForTest([
            [
                'name' => 'H2 Node',
                'type' => Server::TYPE_VLESS,
                'host' => 'h2.example.com',
                'port' => 443,
                'protocol_settings' => [
                    'tls' => 1,
                    'network' => 'h2',
                    'tls_settings' => [
                        'server_name' => 'h2-sni.example.com',
                    ],
                    'network_settings' => [
                        'host' => 'cdn.h2.example.com',
                        'path' => '/h2',
                    ],
                ],
            ],
            [
                'name' => 'QUIC Node',
                'type' => Server::TYPE_VLESS,
                'host' => 'quic.example.com',
                'port' => 8443,
                'protocol_settings' => [
                    'tls' => 1,
                    'network' => 'quic',
                    'tls_settings' => [
                        'server_name' => 'quic-sni.example.com',
                    ],
                ],
            ],
            [
                'name' => 'XHTTP Node',
                'type' => Server::TYPE_VLESS,
                'host' => 'xhttp.example.com',
                'port' => 9443,
                'protocol_settings' => [
                    'tls' => 1,
                    'network' => 'xhttp',
                    'tls_settings' => [
                        'server_name' => 'xhttp-sni.example.com',
                    ],
                ],
            ],
        ]);

        $tags = array_values(array_filter(array_map(
            static fn (array $outbound): ?string => $outbound['tag'] ?? null,
            $outbounds
        )));

        $this->assertContains('H2 Node', $tags);
        $this->assertContains('QUIC Node', $tags);
        $this->assertNotContains('XHTTP Node', $tags);
    }

    public function test_singbox_build_tuic_respects_zero_rtt_and_version_fields(): void
    {
        $protocol = new class([], []) extends SingBox {
            public function handle()
            {
                return [];
            }

            public function buildTuicForTest(string $password, array $server): array
            {
                return $this->buildTuic($password, $server);
            }
        };

        $v5 = $protocol->buildTuicForTest('secret', [
            'name' => 'SingBox TUIC v5',
            'host' => 'tuic-v5.example.com',
            'port' => 443,
            'protocol_settings' => [
                'version' => 5,
                'congestion_control' => 'bbr',
                'udp_relay_mode' => 'quic',
                'zero_rtt_handshake' => false,
                'tls' => [
                    'server_name' => 'tuic-v5-sni.example.com',
                    'allow_insecure' => true,
                ],
                'alpn' => ['h3', 'h2'],
            ],
        ]);

        $v4 = $protocol->buildTuicForTest('legacy-secret', [
            'name' => 'SingBox TUIC v4',
            'host' => 'tuic-v4.example.com',
            'port' => 8443,
            'protocol_settings' => [
                'version' => 4,
                'zero_rtt_handshake' => true,
                'tls' => [
                    'server_name' => 'tuic-v4-sni.example.com',
                ],
            ],
        ]);

        $this->assertSame('tuic', $v5['type']);
        $this->assertSame('secret', $v5['uuid']);
        $this->assertSame('secret', $v5['password']);
        $this->assertFalse($v5['zero_rtt_handshake']);
        $this->assertSame('bbr', $v5['congestion_control']);
        $this->assertSame('quic', $v5['udp_relay_mode']);
        $this->assertSame(['h3', 'h2'], $v5['tls']['alpn']);
        $this->assertTrue($v5['tls']['insecure']);

        $this->assertSame('tuic', $v4['type']);
        $this->assertSame('legacy-secret', $v4['token']);
        $this->assertArrayNotHasKey('uuid', $v4);
        $this->assertArrayNotHasKey('password', $v4);
        $this->assertTrue($v4['zero_rtt_handshake']);
    }

    public function test_clash_build_trojan_ws_and_socks_tls_export_expected_shape(): void
    {
        $trojan = Clash::buildTrojan('secret', [
            'name' => 'Clash Trojan',
            'host' => 'clash.example.com',
            'port' => 443,
            'protocol_settings' => [
                'server_name' => 'clash-sni.example.com',
                'allow_insecure' => true,
                'network' => 'ws',
                'network_settings' => [
                    'path' => '/clash',
                    'headers' => [
                        'Host' => 'cdn.clash.example.com',
                    ],
                ],
            ],
        ]);
        $socks = Clash::buildSocks5('secret', [
            'name' => 'Clash Socks',
            'host' => 'socks.example.com',
            'port' => 1080,
            'protocol_settings' => [
                'tls' => true,
                'tls_settings' => [
                    'allow_insecure' => true,
                ],
            ],
        ]);

        $this->assertSame('trojan', $trojan['type']);
        $this->assertSame('ws', $trojan['network']);
        $this->assertSame('/clash', $trojan['ws-opts']['path']);
        $this->assertSame('cdn.clash.example.com', $trojan['ws-opts']['headers']['Host']);
        $this->assertSame('clash-sni.example.com', $trojan['sni']);
        $this->assertTrue($trojan['skip-cert-verify']);
        $this->assertSame('socks5', $socks['type']);
        $this->assertTrue($socks['tls']);
        $this->assertTrue($socks['skip-cert-verify']);
    }

    public function test_trojan_dynamic_sni_placeholder_is_replaced_in_subscription_exports(): void
    {
        $server = [
            'name' => 'Dynamic Trojan',
            'host' => 'trojan.example.com',
            'port' => 443,
            'protocol_settings' => [
                'server_name' => 'null.baidu.com',
                'allow_insecure' => false,
                'network' => 'tcp',
            ],
        ];

        $generalUri = General::buildTrojan('secret', $server);
        $generalQuery = [];
        parse_str((string) parse_url(trim($generalUri), PHP_URL_QUERY), $generalQuery);

        $clash = Clash::buildTrojan('secret', $server);
        $singBox = (new SingBox([], []))->buildTrojan('secret', $server);

        $this->assertMatchesRegularExpression('/^[1-9]\.baidu\.com$/', (string) ($generalQuery['sni'] ?? ''));
        $this->assertMatchesRegularExpression('/^[1-9]\.baidu\.com$/', (string) ($generalQuery['peer'] ?? ''));
        $this->assertMatchesRegularExpression('/^[1-9]\.baidu\.com$/', (string) ($clash['sni'] ?? ''));
        $this->assertMatchesRegularExpression('/^[1-9]\.baidu\.com$/', (string) data_get($singBox, 'tls.server_name', ''));

        $this->assertStringNotContainsString('null.baidu.com', $generalUri);
    }
}
