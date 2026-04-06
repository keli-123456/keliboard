<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\Server\UniProxyController;
use App\Services\UserOnlineService;
use Tests\TestCase;

final class UniProxyControllerTest extends TestCase
{
    public function test_build_v2node_tls_settings_includes_top_level_tuic_alpn(): void
    {
        $controller = new UniProxyController(new UserOnlineService());
        $method = new \ReflectionMethod(UniProxyController::class, 'buildV2NodeTlsSettings');
        $method->setAccessible(true);

        $settings = $method->invoke(
            $controller,
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
        $controller = new UniProxyController(new UserOnlineService());
        $method = new \ReflectionMethod(UniProxyController::class, 'buildV2NodeTlsSettings');
        $method->setAccessible(true);

        $settings = $method->invoke(
            $controller,
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
}
