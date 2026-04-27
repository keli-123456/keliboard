<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Node\NodeConfigService;
use Tests\TestCase;

final class NodeConfigServiceTest extends TestCase
{
    public function test_build_v2node_tls_settings_includes_top_level_tuic_alpn(): void
    {
        $service = new NodeConfigService();

        $settings = $service->buildV2NodeTlsSettings(
            (object) ['host' => 'tuic.example.com'],
            'tuic',
            [
                'tls' => [
                    'server_name' => 'edge.example.com',
                ],
                'tls_settings' => [
                    'cert_mode' => 'file',
                    'cert_file' => '/tmp/node.crt',
                    'key_file' => '/tmp/node.key',
                ],
                'alpn' => ['h3', 'h2', 'h3', ''],
            ],
            1
        );

        $this->assertSame('edge.example.com', $settings['server_name']);
        $this->assertSame(['h3', 'h2'], $settings['alpn']);
        $this->assertSame('file', $settings['cert_mode']);
    }

    public function test_build_v2node_tls_settings_includes_top_level_anytls_alpn(): void
    {
        $service = new NodeConfigService();

        $settings = $service->buildV2NodeTlsSettings(
            (object) ['host' => 'anytls.example.com'],
            'anytls',
            [
                'tls' => [
                    'server_name' => 'secure.example.com',
                ],
                'tls_settings' => [
                    'cert_mode' => 'dns',
                    'provider' => 'cloudflare',
                ],
                'alpn' => ['h2', 'http/1.1'],
            ],
            1
        );

        $this->assertSame('secure.example.com', $settings['server_name']);
        $this->assertSame(['h2', 'http/1.1'], $settings['alpn']);
        $this->assertSame('dns', $settings['cert_mode']);
        $this->assertSame('cloudflare', $settings['provider']);
    }

    public function test_hysteria_v2node_response_includes_external_port_range(): void
    {
        $service = new NodeConfigService();

        $response = $service->buildResponse((object) [
            'type' => 'hysteria',
            'port' => '30000-30100',
            'server_port' => 443,
            'host' => 'hy.example.com',
            'route_ids' => [],
            'protocol_settings' => [
                'version' => 2,
                'bandwidth' => [
                    'up' => 0,
                    'down' => 0,
                ],
                'obfs' => [
                    'open' => true,
                    'type' => 'salamander',
                    'password' => 'mask',
                ],
                'tls' => [
                    'server_name' => 'sni.example.com',
                ],
                'tls_settings' => [
                    'cert_mode' => 'file',
                    'cert_file' => '/tmp/hy.crt',
                    'key_file' => '/tmp/hy.key',
                ],
            ],
        ], true);

        $this->assertSame('hysteria2', $response['protocol']);
        $this->assertSame('30000-30100', $response['port']);
        $this->assertSame(443, $response['server_port']);
    }
}
