<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Plugin\SubscriptionControl\Services\SubscriptionIpIntelligenceService;
use Tests\TestCase;

final class SubscriptionIpIntelligenceServiceTest extends TestCase
{
    public function test_lookup_classifies_cloud_provider_from_team_cymru_dns_records(): void
    {
        $service = new SubscriptionIpIntelligenceService([
            'ip_intelligence_cache_ttl_seconds' => 60,
        ], function (string $query): array {
            return match ($query) {
                '4.3.2.1.origin.asn.cymru.com' => ['45090 | 1.2.3.4 | 1.2.3.0/24 | CN | apnic | 2020-01-01'],
                'AS45090.asn.cymru.com' => ['45090 | CN | apnic | 2011-01-01 | TENCENT-NET-AP Shenzhen Tencent Computer Systems Company Limited, CN'],
                default => [],
            };
        });

        $result = $service->lookup('1.2.3.4');

        $this->assertSame(45090, $result['ip_asn']);
        $this->assertSame('1.2.3.0/24', $result['ip_prefix']);
        $this->assertSame('CN', $result['ip_country']);
        $this->assertSame('apnic', $result['ip_registry']);
        $this->assertSame('TENCENT-NET-AP Shenzhen Tencent Computer Systems Company Limited, CN', $result['ip_org']);
        $this->assertSame('hosting', $result['ip_type']);
        $this->assertContains('cloud_provider', $result['ip_risk_tags']);
    }

    public function test_lookup_parses_team_cymru_origin_record_without_ip_field(): void
    {
        $service = new SubscriptionIpIntelligenceService([], function (string $query): array {
            return match ($query) {
                '8.8.8.8.origin.asn.cymru.com' => ['15169 | 8.8.8.0/24 | US | arin | 1992-12-01'],
                'AS15169.asn.cymru.com' => ['15169 | US | arin | 1992-12-01 | GOOGLE - Google LLC, US'],
                default => [],
            };
        });

        $result = $service->lookup('8.8.8.8');

        $this->assertSame(15169, $result['ip_asn']);
        $this->assertSame('8.8.8.0/24', $result['ip_prefix']);
        $this->assertSame('US', $result['ip_country']);
        $this->assertSame('arin', $result['ip_registry']);
    }

    public function test_lookup_returns_unknown_for_private_or_unresolved_ip(): void
    {
        $service = new SubscriptionIpIntelligenceService([], fn(): array => []);

        $private = $service->lookup('10.0.0.1');
        $unresolved = $service->lookup('8.8.8.8');

        $this->assertSame('private', $private['ip_type']);
        $this->assertSame(['private_ip'], $private['ip_risk_tags']);
        $this->assertSame('unknown', $unresolved['ip_type']);
        $this->assertSame([], $unresolved['ip_risk_tags']);
    }
}
