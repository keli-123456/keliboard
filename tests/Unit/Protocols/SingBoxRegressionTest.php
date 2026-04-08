<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

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
}
