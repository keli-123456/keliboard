<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\SubscriptionControl\Services\SubscriptionTrustedEgressResolver;
use Tests\TestCase;

final class SubscriptionTrustedEgressResolverTest extends TestCase
{
    public function test_resolves_node_and_machine_ips_without_dns(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver([
            'enable_auto_trusted_node_dns' => false,
            'enable_auto_trusted_machine_ips' => true,
        ]);

        $entries = $resolver->resolveFromRows(
            [
                [
                    'host' => '1.1.1.1',
                    'ips' => '2.2.2.2,2001:db8::1',
                ],
                [
                    'host' => 'example.com',
                    'ips' => json_encode(['3.3.3.0/24']),
                ],
            ],
            [
                [
                    'load_status' => [
                        'ip' => [
                            'public_ipv4' => '4.4.4.4',
                            'public_ipv6' => '2001:db8::4',
                            'panel_seen' => '5.5.5.5',
                        ],
                    ],
                ],
            ]
        );

        $this->assertContains('1.1.1.1', $entries);
        $this->assertContains('2.2.2.2', $entries);
        $this->assertContains('3.3.3.0/24', $entries);
        $this->assertContains('4.4.4.4', $entries);
        $this->assertContains('5.5.5.5', $entries);
        $this->assertContains('2001:db8::1', $entries);
        $this->assertContains('2001:db8::4', $entries);
        $this->assertNotContains('example.com', $entries);
    }

    public function test_can_disable_machine_reported_ips(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver([
            'enable_auto_trusted_machine_ips' => false,
        ]);

        $entries = $resolver->resolveFromRows(
            [],
            [
                [
                    'load_status' => [
                        'ip' => [
                            'public_ipv4' => '4.4.4.4',
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame([], $entries);
    }
}
