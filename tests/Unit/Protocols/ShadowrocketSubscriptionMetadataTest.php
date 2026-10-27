<?php

declare(strict_types=1);

namespace Tests\Unit\Protocols;

use App\Protocols\Shadowrocket;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class ShadowrocketSubscriptionMetadataTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
    }

    public function test_status_keeps_navigation_and_reverse_website_together_without_changing_connections(): void
    {
        $user = ['u' => 0, 'd' => 0, 'transfer_enable' => 1073741824, 'expired_at' => 1823566071];
        $servers = [['type' => 'trojan', 'name' => 'Real node', 'host' => 'edge.example.test',
            'port' => 443, 'password' => 'test-only', 'protocol_settings' => ['network' => 'tcp',
                'server_name' => 'tls.example.test', 'allow_insecure' => false]]];
        $before = base64_decode((new Shadowrocket($user, $servers, 'shadowrocket', '2698'))->handle()->getContent(), true);
        $after = base64_decode((new Shadowrocket($user, $servers, 'shadowrocket', '2698', [
            'navigation_url' => 'https://nav.example.test', 'website_url' => 'https://192.0.2.10:8444',
        ]))->handle()->getContent(), true);

        [$oldStatus, $oldConnections] = explode("\r\n", $before, 2);
        [$status, $connections] = explode("\r\n", $after, 2);
        $this->assertStringStartsWith($oldStatus . ' | ', $status);
        $this->assertStringContainsString('官网导航：https://nav.example.test', $status);
        $this->assertStringContainsString('官网/续费入口：https://192.0.2.10:8444', $status);
        $this->assertSame($oldConnections, $connections);
    }

    public static function metadataCases(): array
    {
        return [
            'no-links' => [[], []],
            'navigation-only' => [['navigation_url' => 'https://nav.example.test'], ['https://nav.example.test']],
            'branch-website-only' => [['navigation_url' => null, 'website_url' => 'https://192.0.2.10:8444'], ['https://192.0.2.10:8444']],
            'duplicate-link' => [['navigation_url' => 'https://nav.example.test', 'website_url' => 'https://nav.example.test/'], ['https://nav.example.test']],
            'unsafe-scheme' => [['navigation_url' => 'javascript:alert(1)', 'website_url' => 'http://plain.example.test'], []],
            'line-injection' => [['navigation_url' => "https://nav.example.test\r\ntrojan://injected"], []],
            'credential-url' => [['website_url' => 'https://user:secret@example.test'], []],
            'non-string' => [['navigation_url' => ['https://nav.example.test'], 'website_url' => 123], []],
        ];
    }

    #[DataProvider('metadataCases')]
    public function test_status_handles_missing_duplicate_and_unsafe_metadata(array $metadata, array $expected): void
    {
        $user = ['u' => 0, 'd' => 0, 'transfer_enable' => 1073741824, 'expired_at' => 1823566071];
        $raw = base64_decode((new Shadowrocket($user, [], 'shadowrocket', '2698', $metadata))->handle()->getContent(), true);
        $this->assertStringStartsWith('STATUS=', $raw);
        $this->assertSame(1, substr_count($raw, "\r\n"));
        $this->assertSame(count($expected), substr_count($raw, 'https://'));
        foreach ($expected as $url) {
            $this->assertStringContainsString($url, $raw);
        }
        $this->assertStringNotContainsString('javascript:', $raw);
        $this->assertStringNotContainsString('trojan://', $raw);
        $this->assertStringNotContainsString('secret', $raw);
    }
}
