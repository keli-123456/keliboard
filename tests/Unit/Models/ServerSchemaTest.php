<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Server;
use Tests\TestCase;

final class ServerSchemaTest extends TestCase
{
    public function test_vless_protocol_enums_stay_aligned_with_runtime_safe_networks(): void
    {
        $networks = Server::getProtocolEnums(Server::TYPE_VLESS)['network'] ?? [];

        $this->assertContains('splithttp', $networks);
        $this->assertNotContains('kcp', $networks);
    }

    public function test_vmess_and_trojan_protocol_enums_stay_aligned_with_runtime_safe_networks(): void
    {
        $vmessNetworks = Server::getProtocolEnums(Server::TYPE_VMESS)['network'] ?? [];
        $trojanNetworks = Server::getProtocolEnums(Server::TYPE_TROJAN)['network'] ?? [];
        $anytlsNetworks = Server::getProtocolEnums(Server::TYPE_ANYTLS)['network'] ?? [];
        $naiveNetworks = Server::getProtocolEnums(Server::TYPE_NAIVE)['network'] ?? [];
        $tuicAlpn = Server::getProtocolEnums(Server::TYPE_TUIC)['alpn'] ?? [];

        $this->assertContains('xhttp', $vmessNetworks);
        $this->assertContains('splithttp', $vmessNetworks);
        $this->assertNotContains('kcp', $vmessNetworks);
        $this->assertSame(['tcp', 'ws', 'grpc'], $trojanNetworks);
        $this->assertSame(['tcp'], $anytlsNetworks);
        $this->assertSame(['tcp', 'quic'], $naiveNetworks);
        $this->assertSame(['http/1.1', 'h2', 'h3'], $tuicAlpn);
    }
}
