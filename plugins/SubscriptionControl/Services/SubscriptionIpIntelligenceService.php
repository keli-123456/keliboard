<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\Cache;

final class SubscriptionIpIntelligenceService
{
    private const DEFAULT_RESULT = [
        'ip_asn' => null,
        'ip_prefix' => null,
        'ip_country' => null,
        'ip_registry' => null,
        'ip_org' => null,
        'ip_type' => 'unknown',
        'ip_risk_tags' => [],
    ];

    /** @var callable(string): array */
    private $dnsResolver;

    public function __construct(private readonly array $config = [], ?callable $dnsResolver = null)
    {
        $this->dnsResolver = $dnsResolver ?? [$this, 'resolveTxtRecords'];
    }

    public function lookup(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return self::DEFAULT_RESULT;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return array_merge(self::DEFAULT_RESULT, [
                'ip_type' => 'private',
                'ip_risk_tags' => ['private_ip'],
            ]);
        }

        $cacheTtl = $this->configInt('ip_intelligence_cache_ttl_seconds', 604800, 60);
        $cacheKey = 'subscription_control:ip_intelligence:' . hash('sha256', $ip);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return array_merge(self::DEFAULT_RESULT, $cached);
        }

        $result = $this->lookupViaTeamCymru($ip);
        Cache::put($cacheKey, $result, $cacheTtl);

        return $result;
    }

    private function lookupViaTeamCymru(string $ip): array
    {
        $originQuery = $this->originQuery($ip);
        if ($originQuery === null) {
            return self::DEFAULT_RESULT;
        }

        $originRecord = $this->firstTxtRecord($originQuery);
        if ($originRecord === null) {
            return self::DEFAULT_RESULT;
        }

        $origin = $this->parseOriginRecord($originRecord);
        if ($origin['ip_asn'] === null) {
            return self::DEFAULT_RESULT;
        }

        $org = $this->lookupAsnOrg((int) $origin['ip_asn']);
        $classified = $this->classifyOrg($org);

        return array_merge(self::DEFAULT_RESULT, $origin, [
            'ip_org' => $org,
            'ip_type' => $classified['ip_type'],
            'ip_risk_tags' => $classified['ip_risk_tags'],
        ]);
    }

    private function originQuery(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return implode('.', array_reverse(explode('.', $ip))) . '.origin.asn.cymru.com';
        }

        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $hex = bin2hex($packed);
        return implode('.', array_reverse(str_split($hex))) . '.origin6.asn.cymru.com';
    }

    private function lookupAsnOrg(int $asn): ?string
    {
        $record = $this->firstTxtRecord("AS{$asn}.asn.cymru.com");
        if ($record === null) {
            return null;
        }

        $parts = $this->splitPipeRecord($record);
        return $parts[4] ?? null;
    }

    private function firstTxtRecord(string $query): ?string
    {
        try {
            $records = ($this->dnsResolver)($query);
        } catch (\Throwable) {
            return null;
        }

        foreach ($records as $record) {
            $text = trim((string) $record);
            if ($text !== '' && !str_starts_with(strtolower($text), 'as ')) {
                return $text;
            }
        }

        return null;
    }

    private function parseOriginRecord(string $record): array
    {
        $parts = $this->splitPipeRecord($record);

        return [
            'ip_asn' => isset($parts[0]) && is_numeric($parts[0]) ? (int) $parts[0] : null,
            'ip_prefix' => $parts[2] ?? null,
            'ip_country' => $parts[3] ?? null,
            'ip_registry' => $parts[4] ?? null,
        ];
    }

    private function splitPipeRecord(string $record): array
    {
        return array_values(array_map('trim', explode('|', $record)));
    }

    /**
     * @return array{ip_type: string, ip_risk_tags: array}
     */
    private function classifyOrg(?string $org): array
    {
        $name = strtolower((string) $org);
        if ($name === '') {
            return ['ip_type' => 'unknown', 'ip_risk_tags' => []];
        }

        foreach ([
            'vpn',
            'proxy',
            'tor',
            'relay',
        ] as $needle) {
            if (str_contains($name, $needle)) {
                return ['ip_type' => 'proxy', 'ip_risk_tags' => ['proxy_like']];
            }
        }

        foreach ([
            'alibaba',
            'aliyun',
            'tencent',
            'huawei cloud',
            'huaweicloud',
            'baidu cloud',
            'volcengine',
            'ucloud',
            'tianyi',
            'mobile cloud',
            'amazon',
            'aws',
            'azure',
            'microsoft',
            'google cloud',
            'cloudflare',
            'digitalocean',
            'vultr',
            'linode',
            'akamai',
            'hetzner',
            'ovh',
            'oracle',
            'contabo',
            'gcore',
            'leaseweb',
            'colo',
            'hosting',
            'data center',
            'datacenter',
        ] as $needle) {
            if (str_contains($name, $needle)) {
                return ['ip_type' => 'hosting', 'ip_risk_tags' => ['cloud_provider']];
            }
        }

        foreach ([
            'telecom',
            'unicom',
            'mobile',
            'chinanet',
            'broadband',
            'communications',
            'carrier',
        ] as $needle) {
            if (str_contains($name, $needle)) {
                return ['ip_type' => 'residential', 'ip_risk_tags' => []];
            }
        }

        return ['ip_type' => 'unknown', 'ip_risk_tags' => []];
    }

    private function resolveTxtRecords(string $query): array
    {
        $records = @dns_get_record($query, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(array $record): string => trim((string) ($record['txt'] ?? '')),
            $records
        )));
    }

    private function configInt(string $key, int $default, int $min): int
    {
        $value = (int) ($this->config[$key] ?? $default);
        return max($min, $value);
    }
}
