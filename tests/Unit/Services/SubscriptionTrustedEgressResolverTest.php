<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\SubscriptionControl\Services\SubscriptionTrustedEgressResolver;
use Tests\TestCase;

final class SubscriptionTrustedEgressResolverTest extends TestCase
{
    use \Tests\Support\InteractsWithInMemoryDatabase;
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
                        'updated_at' => time(),
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

    public function test_subscription_proxy_machine_reported_ips_are_trusted(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver([
            'enable_auto_trusted_machine_ips' => true,
        ]);

        $entries = $resolver->resolveFromRows(
            [],
            [
                [
                    'subproxy_enabled' => true,
                    'load_status' => [
                        'updated_at' => time(),
                        'ip' => [
                            'public_ipv4' => '103.14.76.98',
                        ],
                        'agent' => [
                            'subscription_proxy' => [
                                'public_ipv4' => '103.14.76.99',
                                'panel_seen' => '103.14.76.100',
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertContains('103.14.76.98', $entries);
        $this->assertContains('103.14.76.99', $entries);
        $this->assertContains('103.14.76.100', $entries);
    }

    public function test_direct_node_domain_dns_records_are_trusted_when_dns_is_enabled(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver(
            ['enable_auto_trusted_node_dns' => true],
            static fn(string $host): array => $host === 'direct.example.com' ? ['8.8.8.8'] : []
        );

        $entries = $resolver->resolveFromRows([
            [
                'host' => 'direct.example.com',
                'ips' => '',
            ],
        ]);

        $this->assertContains('8.8.8.8', $entries);
    }

    public function test_cloudflare_proxied_sni_and_host_dns_records_are_not_trusted(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver(
            ['enable_auto_trusted_node_dns' => true],
            static fn(string $host): array => match ($host) {
                'origin.example.com' => ['8.8.4.4'],
                'proxied.example.com' => ['104.16.1.2', '2606:4700::1'],
                default => [],
            }
        );

        $entries = $resolver->resolveFromRows([
            [
                'host' => 'origin.example.com',
                'ips' => '',
                'protocol_settings' => [
                    'tls_settings' => [
                        'server_name' => 'proxied.example.com',
                    ],
                    'network_settings' => [
                        'headers' => [
                            'Host' => 'proxied.example.com',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertContains('8.8.4.4', $entries);
        $this->assertNotContains('104.16.1.2', $entries);
        $this->assertNotContains('2606:4700::1', $entries);
    }

    public function test_unrelated_non_cloudflare_tls_and_transport_names_are_never_trusted_or_resolved(): void
    {
        $resolved = [];
        $resolver = new SubscriptionTrustedEgressResolver([], function (string $host) use (&$resolved): array {
            $resolved[] = $host; return $host === 'own.example.test' ? ['8.8.8.8'] : ['9.9.9.9'];
        });
        $entries = $resolver->resolveFromRows([['host' => 'own.example.test', 'protocol_settings' => [
            'tls_settings' => ['server_name' => 'unrelated.example.test'], 'reality_settings' => ['server_name' => 'other.example.test'],
            'network_settings' => ['headers' => ['Host' => 'thirdparty.example.test']]]]]);
        $this->assertSame(['own.example.test'], $resolved); $this->assertSame(['8.8.8.8'], $entries);
    }

    public function test_only_fresh_enabled_managed_forwarders_receive_proxy_header_authority(): void
    {
        $resolver = new SubscriptionTrustedEgressResolver();
        $make = fn ($enabled, $at, $ip) => ['subproxy_enabled' => $enabled, 'load_status' => ['updated_at' => $at, 'ip' => ['public_ipv4' => $ip]]];
        $this->assertSame(['8.8.8.8'], $resolver->proxyEntriesFromRows([
            $make(true, time(), '8.8.8.8'), $make(false, time(), '9.9.9.9'), $make(true, time() - 1000, '1.1.1.1'),
            $make(true, time() + 60, '4.4.4.4'), $make(true, time(), '10.0.0.1'),
        ]));
        $this->assertSame(['8.8.4.4'], $resolver->proxyEntriesFromRows([
            ['webproxy_enabled' => true, 'load_status' => ['updated_at' => time(), 'ip' => ['panel_seen' => '8.8.4.4']]],
        ]));
    }

    public function test_disabling_automatic_machine_egress_does_not_disable_managed_forwarder_identity(): void
    {
        $this->setUpInMemoryDatabase();
        \Illuminate\Support\Facades\Schema::create('v2_server_machine', function ($table): void {
            $table->id(); $table->text('load_status'); $table->boolean('is_active'); $table->boolean('subproxy_enabled'); $table->boolean('webproxy_enabled');
        });
        \Illuminate\Support\Facades\DB::table('v2_server_machine')->insert(['load_status' => json_encode(['updated_at' => time(), 'ip' => ['public_ipv4' => '8.8.8.8']]),
            'is_active' => true, 'subproxy_enabled' => false, 'webproxy_enabled' => true]);
        $resolver = new SubscriptionTrustedEgressResolver(['enable_auto_trusted_machine_ips' => false]);
        $this->assertSame('', $resolver->resolve()); $this->assertSame(['8.8.8.8'], $resolver->resolveProxyEntries());
    }
}
