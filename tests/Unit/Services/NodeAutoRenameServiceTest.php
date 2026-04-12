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

    public function test_trojan_uses_legacy_tls_settings_server_name_for_naming_host(): void
    {
        $service = new NodeAutoRenameService();
        $server = new Server();
        $server->forceFill([
            'type' => Server::TYPE_TROJAN,
            'host' => 'cdn.example.com',
            'protocol_settings' => [
                'tls_settings' => [
                    'server_name' => 'legacy-origin.example.com',
                ],
            ],
        ]);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveNamingHost');
        $method->setAccessible(true);

        $this->assertSame('legacy-origin.example.com', $method->invoke($service, $server));
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

    public function test_trojan_prefers_node_host_for_ip_lookup_before_tls_server_name(): void
    {
        $service = new NodeAutoRenameService();
        $server = new Server();
        $server->forceFill([
            'type' => Server::TYPE_TROJAN,
            'host' => 'node.example.com',
            'protocol_settings' => [
                'server_name' => 'cdn.example.com',
            ],
        ]);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveLookupHostCandidates');
        $method->setAccessible(true);

        $this->assertSame(
            ['node.example.com', 'cdn.example.com'],
            $method->invoke($service, $server, 'cdn.example.com')
        );
    }

    public function test_pick_preferred_ip_prioritizes_public_ipv4(): void
    {
        $service = new NodeAutoRenameService();

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'pickPreferredIp');
        $method->setAccessible(true);

        $this->assertSame(
            '1.1.1.1',
            $method->invoke($service, ['10.0.0.2', '2606:4700:4700::1111', '1.1.1.1'])
        );
    }

    public function test_pick_preferred_ip_falls_back_to_public_ipv6_when_ipv4_not_public(): void
    {
        $service = new NodeAutoRenameService();

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'pickPreferredIp');
        $method->setAccessible(true);

        $this->assertSame(
            '2606:4700:4700::1111',
            $method->invoke($service, ['10.0.0.8', '2606:4700:4700::1111', '192.168.1.9'])
        );
    }

    public function test_geo_provider_defaults_to_auto_for_invalid_value(): void
    {
        $service = new NodeAutoRenameService(['geo_provider' => 'invalid-provider']);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveGeoProvider');
        $method->setAccessible(true);

        $this->assertSame('auto', $method->invoke($service));
    }

    public function test_country_locales_parse_and_append_en(): void
    {
        $service = new NodeAutoRenameService(['country_locales' => 'zh-CN,zh']);

        $method = new \ReflectionMethod(NodeAutoRenameService::class, 'resolveCountryLocales');
        $method->setAccessible(true);

        $this->assertSame(['zh-CN', 'zh', 'en'], $method->invoke($service));
    }
}
