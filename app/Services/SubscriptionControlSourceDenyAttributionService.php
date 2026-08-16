<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionControlSourceDenyAttributionService
{
    private const EVENT_TABLE = 'v2_subscription_control_event';

    private const SOURCE_PROVIDER_PATTERNS = [
        'aws' => ['amazon', 'aws'],
        'azure' => ['microsoft', 'azure'],
        'google_cloud' => ['google cloud', 'google llc'],
        'alibaba_cloud' => ['alibaba', 'aliyun'],
        'tencent_cloud' => ['tencent'],
        'huawei_cloud' => ['huawei'],
        'ucloud' => ['ucloud'],
        'oracle_cloud' => ['oracle'],
        'digitalocean' => ['digitalocean'],
        'vultr' => ['vultr', 'choopa'],
        'linode_akamai' => ['linode', 'akamai'],
        'hetzner' => ['hetzner'],
        'ovh' => ['ovh'],
        'cloudflare' => ['cloudflare'],
    ];

    /** @return array<string, mixed> */
    public function collect(int $cutoff): array
    {
        if (!Schema::hasTable(self::EVENT_TABLE)) {
            return $this->unavailable();
        }
        foreach (['source_ip_deny_match_type', 'source_ip_deny_match'] as $column) {
            if (!Schema::hasColumn(self::EVENT_TABLE, $column)) {
                return $this->unavailable();
            }
        }

        $events = DB::table(self::EVENT_TABLE)->where('created_at', '>=', $cutoff);
        $columns = [
            'user_id',
            'created_at',
            'source_ip_deny_match_type',
            'source_ip_deny_match',
        ];
        foreach (['ip_org', 'ip_asn'] as $column) {
            if (Schema::hasColumn(self::EVENT_TABLE, $column)) {
                $columns[] = $column;
            }
        }

        $rows = (clone $events)
            ->where('code', 'source_ip_denylist')
            ->select($columns)
            ->orderBy('created_at')
            ->get();
        $autoLearnedIps = $this->autoLearnedIpFirstSeen($events);
        $sourceClasses = [];
        $matchTypes = [];
        $providers = [];
        $prefixScopes = [];
        $rules = [];

        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $createdAt = (int) ($row->created_at ?? 0);
            $type = $this->normalizeMatchType((string) ($row->source_ip_deny_match_type ?? ''));
            $match = trim((string) ($row->source_ip_deny_match ?? ''));
            if ($type === 'unknown' && $match === '') {
                $type = 'legacy_unattributed';
            }
            $sourceClass = $this->sourceClass($type, $match, $createdAt, $autoLearnedIps);
            $provider = $this->provider($match, (string) ($row->ip_org ?? ''));
            $prefixScope = $this->prefixScope($type, $match);

            $this->addObservation($sourceClasses, $sourceClass, $userId);
            $this->addObservation($matchTypes, $type, $userId);
            $this->addObservation($providers, $provider, $userId);
            if ($prefixScope !== null) {
                $this->addObservation($prefixScopes, $prefixScope, $userId);
            }

            if ($match !== '') {
                $fingerprint = $this->ruleFingerprint($type, $match);
                $ruleKey = $type . ':' . $fingerprint;
                if (!isset($rules[$ruleKey])) {
                    $rules[$ruleKey] = [
                        'rule_fingerprint' => $fingerprint,
                        'match_type' => $type,
                        'source_class' => $sourceClass,
                        'provider' => $provider,
                        'prefix_scope' => $prefixScope,
                        'event_count' => 0,
                        'users' => [],
                        'user_hits' => [],
                    ];
                }
                $rules[$ruleKey]['event_count']++;
                if ($userId > 0) {
                    $rules[$ruleKey]['users'][$userId] = true;
                    $rules[$ruleKey]['user_hits'][$userId] = ($rules[$ruleKey]['user_hits'][$userId] ?? 0) + 1;
                }
            }
        }

        $anonymousRules = [];
        foreach ($rules as $rule) {
            $rule['affected_users'] = count($rule['users']);
            $rule['repeat_affected_users'] = count(array_filter(
                $rule['user_hits'],
                static fn(int $count): bool => $count > 1
            ));
            unset($rule['users'], $rule['user_hits']);
            $anonymousRules[] = $rule;
        }
        usort($anonymousRules, static fn(array $left, array $right): int =>
            ($right['event_count'] <=> $left['event_count'])
            ?: ($right['affected_users'] <=> $left['affected_users'])
        );

        $totalEventCount = $rows->count();
        $attributedEventCount = $rows->filter(static fn($row): bool =>
            trim((string) ($row->source_ip_deny_match ?? '')) !== ''
        )->count();
        $legacyUnattributedEventCount = (int) ($sourceClasses['legacy_unattributed']['event_count'] ?? 0);

        return [
            'available' => true,
            'scope' => 'source_ip_denylist_events_only',
            'total_event_count' => $totalEventCount,
            'attributed_event_count' => $attributedEventCount,
            'attribution_coverage_rate' => $totalEventCount > 0
                ? round($attributedEventCount / $totalEventCount, 6)
                : 0.0,
            'legacy_unattributed_event_count' => $legacyUnattributedEventCount,
            'source_class_counts' => $this->finalizeBuckets($sourceClasses),
            'match_type_counts' => $this->finalizeBuckets($matchTypes),
            'provider_counts' => $this->finalizeBuckets($providers),
            'prefix_scope_counts' => $this->finalizeBuckets($prefixScopes),
            'top_anonymous_rules' => array_slice($anonymousRules, 0, 12),
            'automatic_ua_ip_detection' => 'best_effort_within_retained_event_window',
            'legacy_unattributed_interpretation' => 'pre_attribution_history_not_unknown_origin',
            'source_class_and_provider_are_parallel_dimensions' => true,
            'exact_rule_values_included' => false,
            'personal_data_included' => false,
        ];
    }

    /** @return array<string, int> */
    private function autoLearnedIpFirstSeen(Builder $events): array
    {
        if (!Schema::hasColumn(self::EVENT_TABLE, 'client_ip')) {
            return [];
        }

        $result = [];
        $rows = (clone $events)
            ->where('code', 'ua_blacklist')
            ->whereNotNull('client_ip')
            ->select(['client_ip', 'created_at'])
            ->orderBy('created_at')
            ->get();
        foreach ($rows as $row) {
            $ip = trim((string) ($row->client_ip ?? ''));
            $createdAt = (int) ($row->created_at ?? 0);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false && !isset($result[$ip])) {
                $result[$ip] = $createdAt;
            }
        }

        return $result;
    }

    /** @param array<string, int> $autoLearnedIps */
    private function sourceClass(string $type, string $match, int $createdAt, array $autoLearnedIps): string
    {
        if ($type === 'legacy_unattributed') {
            return 'legacy_unattributed';
        }

        if ($type === 'cidr') {
            if (filter_var($match, FILTER_VALIDATE_IP) !== false) {
                $learnedAt = $autoLearnedIps[$match] ?? null;

                return $learnedAt !== null && $learnedAt <= $createdAt
                    ? 'automatic_ua_ip'
                    : 'configured_ip';
            }

            return str_contains($match, '/') ? 'configured_cidr' : 'configured_ip_or_cidr';
        }

        return match ($type) {
            'asn' => 'configured_asn',
            'organization' => 'configured_organization',
            default => 'unknown',
        };
    }

    private function normalizeMatchType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'cidr', 'ip' => 'cidr',
            'asn' => 'asn',
            'org', 'organization' => 'organization',
            default => 'unknown',
        };
    }

    private function provider(string $match, string $organization): string
    {
        $haystack = strtolower(trim($match . ' ' . $organization));
        foreach (self::SOURCE_PROVIDER_PATTERNS as $provider => $patterns) {
            foreach ($patterns as $pattern) {
                if ($haystack !== '' && str_contains($haystack, $pattern)) {
                    return $provider;
                }
            }
        }

        return 'other_or_unknown';
    }

    private function prefixScope(string $type, string $match): ?string
    {
        if ($type !== 'cidr') {
            return null;
        }
        if (filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 'exact_ipv4';
        }
        if (filter_var($match, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return 'exact_ipv6';
        }
        if (!preg_match('/^(.+)\/(\d{1,3})$/', $match, $parts)) {
            return 'invalid_or_unknown';
        }

        $prefix = (int) $parts[2];
        if (filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return match (true) {
                $prefix >= 32 => 'exact_ipv4',
                $prefix >= 24 => 'ipv4_prefix_24_31',
                $prefix >= 16 => 'ipv4_prefix_16_23',
                default => 'ipv4_prefix_0_15',
            };
        }
        if (filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return match (true) {
                $prefix >= 128 => 'exact_ipv6',
                $prefix >= 64 => 'ipv6_prefix_64_127',
                $prefix >= 32 => 'ipv6_prefix_32_63',
                default => 'ipv6_prefix_0_31',
            };
        }

        return 'invalid_or_unknown';
    }

    /** @param array<string, array<string, mixed>> $buckets */
    private function addObservation(array &$buckets, string $key, int $userId): void
    {
        if (!isset($buckets[$key])) {
            $buckets[$key] = ['event_count' => 0, 'users' => [], 'user_hits' => []];
        }
        $buckets[$key]['event_count']++;
        if ($userId > 0) {
            $buckets[$key]['users'][$userId] = true;
            $buckets[$key]['user_hits'][$userId] = ($buckets[$key]['user_hits'][$userId] ?? 0) + 1;
        }
    }

    /** @param array<string, array<string, mixed>> $buckets @return array<string, array<string, int>> */
    private function finalizeBuckets(array $buckets): array
    {
        $result = [];
        foreach ($buckets as $key => $bucket) {
            $result[$key] = [
                'event_count' => (int) ($bucket['event_count'] ?? 0),
                'affected_users' => count($bucket['users'] ?? []),
                'repeat_affected_users' => count(array_filter(
                    $bucket['user_hits'] ?? [],
                    static fn(int $count): bool => $count > 1
                )),
            ];
        }
        uasort($result, static fn(array $left, array $right): int =>
            ($right['event_count'] <=> $left['event_count'])
            ?: ($right['affected_users'] <=> $left['affected_users'])
        );

        return $result;
    }

    private function ruleFingerprint(string $type, string $match): string
    {
        $secret = (string) config('app.key', 'subscription-control');
        if (str_starts_with($secret, 'base64:')) {
            $decoded = base64_decode(substr($secret, 7), true);
            if (is_string($decoded) && $decoded !== '') {
                $secret = $decoded;
            }
        }
        if ($secret === '') {
            $secret = 'subscription-control';
        }

        return substr(hash_hmac('sha256', strtolower($type . ':' . $match), $secret), 0, 16);
    }

    /** @return array<string, mixed> */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'scope' => 'source_ip_denylist_events_only',
            'exact_rule_values_included' => false,
            'personal_data_included' => false,
        ];
    }
}