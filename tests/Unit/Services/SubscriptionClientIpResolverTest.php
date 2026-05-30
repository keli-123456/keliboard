<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Illuminate\Http\Request;
use Plugin\SubscriptionControl\Services\SubscriptionClientIpResolver;
use Tests\TestCase;

final class SubscriptionClientIpResolverTest extends TestCase
{
    public function test_uses_cf_connecting_ip_only_for_trusted_proxy(): void
    {
        $request = Request::create('/s/token', 'GET', [], [], [], [
            'REMOTE_ADDR' => '103.21.244.8',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 103.21.244.8',
            'HTTP_CF_RAY' => 'abc-HKG',
        ]);

        $result = (new SubscriptionClientIpResolver())->resolve($request);

        $this->assertSame('203.0.113.9', $result['client_ip']);
        $this->assertSame('103.21.244.8', $result['proxy_ip']);
        $this->assertSame('cf_connecting_ip', $result['client_ip_source']);
        $this->assertTrue($result['trusted_proxy']);
        $this->assertSame('abc-HKG', $result['cf_ray']);
    }

    public function test_ignores_spoofed_real_ip_headers_from_untrusted_remote(): void
    {
        $request = Request::create('/s/token', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.8',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        $result = (new SubscriptionClientIpResolver())->resolve($request);

        $this->assertSame('198.51.100.8', $result['client_ip']);
        $this->assertSame('198.51.100.8', $result['proxy_ip']);
        $this->assertSame('remote_addr', $result['client_ip_source']);
        $this->assertFalse($result['trusted_proxy']);
    }

    public function test_falls_back_to_first_forwarded_ip_when_cf_header_is_missing(): void
    {
        $request = Request::create('/s/token', 'GET', [], [], [], [
            'REMOTE_ADDR' => '103.21.244.8',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 103.21.244.8',
        ]);

        $result = (new SubscriptionClientIpResolver())->resolve($request);

        $this->assertSame('203.0.113.9', $result['client_ip']);
        $this->assertSame('103.21.244.8', $result['proxy_ip']);
        $this->assertSame('x_forwarded_for', $result['client_ip_source']);
        $this->assertTrue($result['trusted_proxy']);
    }

    public function test_uses_forwarded_ip_from_trusted_subscription_proxy_node(): void
    {
        $request = Request::create('/s/token', 'GET', [], [], [], [
            'REMOTE_ADDR' => '2.56.116.39',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 2.56.116.39',
        ]);

        $result = (new SubscriptionClientIpResolver([
            'trusted_egress_ips' => '2.56.116.39',
        ]))->resolve($request);

        $this->assertSame('203.0.113.9', $result['client_ip']);
        $this->assertSame('2.56.116.39', $result['proxy_ip']);
        $this->assertSame('x_forwarded_for', $result['client_ip_source']);
        $this->assertTrue($result['trusted_proxy']);
    }
}
