<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\Loon;
use Tests\TestCase;

final class LoonRegressionTest extends TestCase
{
    public function test_trojan_websocket_exports_transport_path_and_host(): void
    {
        $uri = Loon::buildTrojan('secret', [
            'name' => 'Loon Trojan WS',
            'host' => 'edge.example.com',
            'port' => 443,
            'protocol_settings' => [
                'server_name' => 'sni.example.com',
                'network' => 'ws',
                'network_settings' => [
                    'path' => '/trojan-ws',
                    'headers' => [
                        'Host' => 'cdn.example.com',
                    ],
                ],
            ],
        ]);

        $this->assertStringContainsString('transport=ws', $uri);
        $this->assertStringContainsString('path=/trojan-ws', $uri);
        $this->assertStringContainsString('host=cdn.example.com', $uri);
        $this->assertStringContainsString('tls-name=sni.example.com', $uri);
    }

    public function test_trojan_websocket_does_not_synthesize_host_from_sni(): void
    {
        $uri = Loon::buildTrojan('secret', [
            'name' => 'Loon Trojan WS',
            'host' => 'edge.example.com',
            'port' => 443,
            'protocol_settings' => [
                'server_name' => 'sni.example.com',
                'network' => 'ws',
                'network_settings' => [
                    'path' => '/trojan-ws',
                ],
            ],
        ]);

        $this->assertStringContainsString('transport=ws', $uri);
        $this->assertStringContainsString('path=/trojan-ws', $uri);
        $this->assertStringNotContainsString('host=sni.example.com', $uri);
    }
}
