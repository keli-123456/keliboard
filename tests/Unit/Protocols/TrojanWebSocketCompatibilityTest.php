<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TrojanWebSocketCompatibilityTest extends TestCase
{
    public static function hostCases(): array
    {
        return [
            'missing' => [[], 'sni.example.test'],
            'null' => [['Host' => null], 'sni.example.test'],
            'blank' => [['Host' => '  '], 'sni.example.test'],
            'empty-list' => [['Host' => []], 'sni.example.test'],
            'explicit' => [['Host' => 'configured.example.test'], 'configured.example.test'],
            'padded' => [['Host' => ' configured.example.test '], 'configured.example.test'],
            'host-list' => [['Host' => ['', 'configured.example.test', 'other.example.test']], 'configured.example.test'],
        ];
    }

    #[DataProvider('hostCases')]
    public function test_all_supported_trojan_ws_exports_use_the_same_host_policy(array $headers, string $expected): void
    {
        $server = $this->server();
        $server['protocol_settings']['network_settings']['headers'] = $headers;
        $this->assertSame($expected, Helper::resolveTrojanWebSocketHost($server, 'sni.example.test'));
        foreach ([Clash::class, ClashMeta::class, Stash::class] as $exporter) {
            $config = $exporter::buildTrojan('secret', $server);
            $this->assertSame($expected, $config['ws-opts']['headers']['Host'], $exporter);
            $this->assertSame('sni.example.test', $config['sni']);
            $this->assertSame('connect.example.test', $config['server']);
            $this->assertSame('/transport', $config['ws-opts']['path']);
            $this->assertFalse($config['skip-cert-verify']);
        }
        $config = $this->singBox($server);
        $this->assertSame($expected, $config['transport']['headers']['Host']);
        $this->assertSame('sni.example.test', $config['tls']['server_name']);
        $this->assertFalse($config['tls']['insecure']);
        $this->assertSame('connect.example.test', $config['server']);
        $query = [];
        parse_str((string) parse_url(trim(General::buildTrojan('secret', $server)), PHP_URL_QUERY), $query);
        $this->assertSame($expected, $query['host']);
        $this->assertSame('sni.example.test', $query['sni']);
        $this->assertSame('0', $query['allowInsecure']);
        $this->assertSame('/transport', $query['path']);
        parse_str((string) parse_url(trim(Shadowrocket::buildTrojan('secret', $server)), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('obfs-host=' . $expected . ';', $query['plugin']);
        $this->assertStringContainsString('host=' . $expected, Loon::buildTrojan('secret', $server));
        $this->assertStringContainsString('obfs-host=' . $expected, QuantumultX::buildTrojan('secret', $server));
    }

    public function test_missing_sni_does_not_invent_a_host(): void
    {
        $server = $this->server();
        unset($server['protocol_settings']['server_name']);
        $this->assertSame('', Helper::resolveTrojanWebSocketHost($server, ''));
        $this->assertArrayNotHasKey('headers', $this->singBox($server)['transport']);
        $query = [];
        parse_str((string) parse_url(trim(General::buildTrojan('secret', $server)), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('host', $query);
        $this->assertArrayNotHasKey('sni', $query);
    }

    public function test_tcp_trojan_does_not_gain_websocket_parameters(): void
    {
        $server = $this->server();
        $server['protocol_settings']['network'] = 'tcp';
        $this->assertNull($this->singBox($server)['transport']);
        $query = [];
        parse_str((string) parse_url(trim(General::buildTrojan('secret', $server)), PHP_URL_QUERY), $query);
        $this->assertArrayNotHasKey('host', $query);
        $this->assertArrayNotHasKey('path', $query);
        $this->assertSame('sni.example.test', $query['sni']);
    }

    private function server(): array
    {
        return ['name' => 'Trojan WS', 'type' => 'trojan', 'host' => 'connect.example.test', 'port' => 443,
            'protocol_settings' => ['server_name' => 'sni.example.test', 'allow_insecure' => false,
                'network' => 'ws', 'network_settings' => ['path' => '/transport']]];
    }

    private function singBox(array $server): array
    {
        $method = new \ReflectionMethod(SingBox::class, 'buildTrojan');
        $method->setAccessible(true);
        return $method->invoke(new SingBox([], []), 'secret', $server);
    }
}
