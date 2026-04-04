<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\SingBox;
use App\Support\ProtocolManager;
use Illuminate\Container\Container;
use Tests\TestCase;

final class SingBoxRegressionTest extends TestCase
{
    public function test_protocol_manager_matches_karing_to_singbox_exporter(): void
    {
        $manager = new ProtocolManager(new Container());

        $reflection = new \ReflectionProperty(ProtocolManager::class, 'protocolClasses');
        $reflection->setAccessible(true);
        $reflection->setValue($manager, [SingBox::class]);

        $this->assertSame(SingBox::class, $manager->matchProtocolClassName('Karing/1.2.8.1103'));
    }

    public function test_singbox_build_hysteria2_port_hopping_uses_dash_ranges(): void
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
        $this->assertSame(['2080-3000'], $config['server_ports']);
        $this->assertSame('30s', $config['hop_interval']);
        $this->assertArrayNotHasKey('server_port', $config);
    }
}
