<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DomainHealth;
use App\Services\DomainHealthProbeService;
use Tests\TestCase;

final class DomainHealthProbeServiceTest extends TestCase
{
    public function test_public_https_domain_is_reported_healthy(): void
    {
        $service = new DomainHealthProbeService(
            fn (string $domain): array => ['8.8.8.8'],
            fn (string $domain, array $addresses, int $timeout): array => [
                'tls_valid' => true,
                'http_status' => 200,
                'response_ms' => 46,
                'certificate_expires_at' => time() + (90 * 86400),
                'certificate_issuer' => 'Test CA',
                'certificate_sha256' => str_repeat('a', 64),
            ],
        );

        $result = $service->check('https://example.com/path');

        $this->assertSame(DomainHealth::STATUS_HEALTHY, $result['status']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame(200, $result['http_status']);
        $this->assertSame(['8.8.8.8'], $result['dns_addresses']);
    }

    public function test_private_or_reserved_dns_result_is_rejected_before_https_probe(): void
    {
        $probeCalled = false;
        $service = new DomainHealthProbeService(
            fn (string $domain): array => ['127.0.0.1'],
            function () use (&$probeCalled): array {
                $probeCalled = true;

                return [];
            },
        );

        $result = $service->check('internal.example.com');

        $this->assertSame(DomainHealth::STATUS_DOWN, $result['status']);
        $this->assertSame('unsafe_address', $result['reason']);
        $this->assertFalse($probeCalled);
    }

    public function test_http_client_error_is_warning_instead_of_hard_failure(): void
    {
        $service = new DomainHealthProbeService(
            fn (string $domain): array => ['1.1.1.1'],
            fn (): array => [
                'tls_valid' => true,
                'http_status' => 403,
                'response_ms' => 20,
                'certificate_expires_at' => time() + (90 * 86400),
            ],
        );

        $result = $service->check('blocked.example.com');

        $this->assertSame(DomainHealth::STATUS_WARNING, $result['status']);
        $this->assertSame('http_client_error', $result['reason']);
        $this->assertSame('HTTP 403', $result['last_error']);
    }
}
