<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\KeliClientDiscoveryService;
use Illuminate\Http\Request;
use Tests\TestCase;

final class KeliClientDiscoveryServiceTest extends TestCase
{
    public function test_payload_defaults_to_current_panel_host(): void
    {
        config()->set('keli_client.discovery.api_base', null);
        config()->set('keli_client.discovery.api_prefix', '/api/v1');
        config()->set('keli_client.discovery.backup_api_bases', '');
        config()->set('keli_client.discovery.bootstrap_urls', '');
        config()->set('keli_client.discovery.ttl', 3600);
        config()->set('keli_client.discovery.ed25519_private_key', null);

        $payload = (new KeliClientDiscoveryService())->payload(
            Request::create('https://panel.example/.well-known/keli-client.json')
        );

        $this->assertSame('https://panel.example', $payload['api_base']);
        $this->assertSame('/api/v1', $payload['api_prefix']);
        $this->assertSame('panel.example', $payload['panel_host']);
        $this->assertArrayNotHasKey('signature', $payload);
    }

    public function test_payload_can_include_ed25519_signature(): void
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            $this->markTestSkipped('PHP sodium extension is not available.');
        }

        config()->set('keli_client.discovery.api_base', 'api.example/');
        config()->set('keli_client.discovery.api_prefix', 'api/v1/');
        config()->set('keli_client.discovery.backup_api_bases', 'https://backup-a.example, backup-b.example');
        config()->set('keli_client.discovery.bootstrap_urls', 'https://panel.example/bootstrap.json');
        config()->set('keli_client.discovery.ttl', 3600);
        config()->set(
            'keli_client.discovery.ed25519_private_key',
            '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'
        );

        $service = new KeliClientDiscoveryService();
        $payload = $service->payload(
            Request::create('https://panel.example/.well-known/keli-client.json')
        );

        $this->assertSame('https://api.example', $payload['api_base']);
        $this->assertSame('/api/v1', $payload['api_prefix']);
        $this->assertSame(['https://backup-a.example', 'https://backup-b.example'], $payload['backup_api_bases']);
        $this->assertStringStartsWith('ed25519:', $payload['signature']);

        $signature = $this->decodeBase64Url(substr($payload['signature'], strlen('ed25519:')));
        $publicKey = $this->decodeBase64Url(substr(
            $service->publicKey('000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f'),
            strlen('ed25519:')
        ));
        $this->assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            $service->signingPayload($payload),
            $publicKey
        ));
    }

    private function decodeBase64Url(string $value): string
    {
        $base64 = strtr($value, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($base64, true) ?: '';
    }
}
