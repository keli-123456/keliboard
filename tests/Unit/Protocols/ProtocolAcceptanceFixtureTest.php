<?php

namespace Tests\Unit\Protocols;

use PHPUnit\Framework\TestCase;

class ProtocolAcceptanceFixtureTest extends TestCase
{
    public function test_exports_offline_synthetic_fixtures_from_real_protocol_builders(): void
    {
        require_once dirname(__DIR__, 3) . '/scripts/export-protocol-acceptance.php';
        $fixtures = \protocolAcceptanceFixtures();
        $this->assertTrue($fixtures['synthetic']);
        $this->assertSame(1, $fixtures['schema_version']);
        $this->assertSame('naive', $fixtures['naive_sing_box']['type']);
        $this->assertTrue($fixtures['naive_sing_box']['tls']['enabled']);
        $this->assertSame('localhost', $fixtures['naive_sing_box']['tls']['server_name']);
        $this->assertArrayNotHasKey('insecure', $fixtures['naive_sing_box']['tls']);
        $this->assertArrayNotHasKey('quic', $fixtures['naive_sing_box']);
        $this->assertSame([...$fixtures['naive_sing_box'], 'quic' => true], $fixtures['naive_h3_sing_box']);
        $encoded = base64_encode($fixtures['user_uuid'] . ':' . $fixtures['user_uuid'] . '@127.0.0.1:10443');
        $this->assertSame("http2://{$encoded}?peer=localhost&alpn=h2&padding=0#Naive%20acceptance\r\n", $fixtures['naive_shadowrocket']);
        foreach ($fixtures['mieru_mihomo'] as $level => $outbound) {
            $this->assertSame('TCP', $outbound['transport']);
            $this->assertSame($level, $outbound['multiplexing']);
            $this->assertSame($fixtures['user_uuid'], $outbound['username']);
            $this->assertSame($fixtures['user_uuid'], $outbound['password']);
            $this->assertSame('127.0.0.1', $outbound['server']);
            $this->assertArrayNotHasKey('port-range', $outbound);
            $this->assertStringStartsWith('mierus://', $fixtures['mieru_share'][$level]);
        }
        foreach ($fixtures['source_sha256'] as $file => $hash) {
            $this->assertSame(hash_file('sha256', dirname(__DIR__, 3) . '/' . $file), $hash);
        }
    }
}
