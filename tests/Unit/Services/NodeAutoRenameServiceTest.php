<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use Plugin\NodeAutoRename\Services\NodeAutoRenameService;
use Tests\TestCase;

final class NodeAutoRenameServiceTest extends TestCase
{
    public function test_trojan_uses_tls_server_name_for_naming_host(): void
    {
        $service = new NodeAutoRenameService();
        $server = new Server();
        $server->forceFill([
            'type' => Server::TYPE_TROJAN,
            'host' => 'cdn.example.com',
            'protocol_settings' => [
                'server_name' => 'origin.example.com',
            ],
        ]);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveNamingHost');
        $method->setAccessible(true);

        $this->assertSame('origin.example.com', $method->invoke($service, $server));
    }

    public function test_trojan_falls_back_to_node_host_when_server_name_missing(): void
    {
        $service = new NodeAutoRenameService();
        $server = new Server();
        $server->forceFill([
            'type' => Server::TYPE_TROJAN,
            'host' => 'cdn.example.com',
            'protocol_settings' => [],
        ]);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveNamingHost');
        $method->setAccessible(true);

        $this->assertSame('cdn.example.com', $method->invoke($service, $server));
    }

    public function test_non_trojan_keeps_original_host_for_naming(): void
    {
        $service = new NodeAutoRenameService();
        $server = new Server();
        $server->forceFill([
            'type' => Server::TYPE_VLESS,
            'host' => 'edge.example.com',
            'protocol_settings' => [
                'tls_settings' => [
                    'server_name' => 'sni.example.com',
                ],
            ],
        ]);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveNamingHost');
        $method->setAccessible(true);

        $this->assertSame('edge.example.com', $method->invoke($service, $server));
    }
}
