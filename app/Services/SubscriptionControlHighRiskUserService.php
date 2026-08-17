<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionControlHighRiskUserService
{
    private const EVENT_TABLE = 'v2_subscription_control_event';
    private const USER_TABLE = 'v2_user';
    private const SITE_TABLE = 'v2_site';
    private const CASE_MODEL_VERSION = '1.2.0';
    private const BLOCKING_ACTIONS = ['block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid'];
    private const RESET_ACTIONS = ['reset_token', 'reset_token_uuid'];
    private const STRONG_EVENT_CODES = [
        'subscription_leak_guard',
        'source_batch_pull',
        'source_ip_denylist',
        'online_ip_threshold',
        'multi_ua_pull',
        'multi_region_pull',
    ];
    private const STRONG_SIGNAL_KEYS = [
        'many_pull_ips',
        'many_pull_ua_categories',
        'many_pull_regions',
        'online_region_mismatch',
        'active_plan_very_low_usage',
        'active_plan_low_usage_with_many_ua',
        'active_plan_low_usage_with_many_ips',
        'active_plan_low_usage_with_online_mismatch',
        'ip_intelligence_hosting',
        'ip_intelligence_proxy',
    ];

    /** @return array<string, mixed> */
    public function collect(int $days = 7, int $limit = 20): array
    {
        $days = max(3, min(30, $days));
        $limit = max(1, min(50, $limit));
        if (!Schema::hasTable(self::EVENT_TABLE) || !Schema::hasTable(self::USER_TABLE)) {
            return $this->unavailable($days);
        }

        $overview = Cache::remember(
            sprintf('subscription_control:high_risk_users:%s:%d:%d', self::CASE_MODEL_VERSION, $days, $limit),
            60,
            fn(): array => $this->aggregate($days, $limit)
        );

        $reviewService = new SubscriptionControlCaseReviewService();
        $overview = $reviewService->calibrateOverview($overview, $limit);

        return $reviewService->attachOverview($overview);
    }

    /** @return array<string, mixed> */
    private function aggregate(int $days, int $limit): array
    {
        $cutoff = time() - ($days * 86400);
        $hasSites = Schema::hasTable(self::SITE_TABLE)
            && Schema::hasColumn(self::USER_TABLE, 'site_id');
        $query = DB::table(self::EVENT_TABLE . ' as event')
            ->join(self::USER_TABLE . ' as user', 'user.id', '=', 'event.user_id')
            ->where('event.created_at', '>=', $cutoff)
            ->where('event.user_id', '>', 0);

        if (Schema::hasColumn(self::USER_TABLE, 'is_admin')) {
            $query->where('user.is_admin', false);
        }
        if (Schema::hasColumn(self::USER_TABLE, 'is_staff')) {
            $query->where('user.is_staff', false);
        }
        if ($hasSites) {
            $query->leftJoin(self::SITE_TABLE . ' as site', 'site.id', '=', 'user.site_id');
        }

        $query->select(['event.user_id', 'user.email']);
        $groupBy = ['event.user_id', 'user.email'];
        if ($hasSites) {
            $query->addSelect(['user.site_id', 'site.name as site_name']);
            $groupBy[] = 'user.site_id';
            $groupBy[] = 'site.name';
        }

        $rows = $query
            ->selectRaw('COUNT(*) as event_count')
            ->selectRaw($this->conditionalCountSql('event.action', self::BLOCKING_ACTIONS) . ' as blocking_event_count')
            ->selectRaw($this->conditionalCountSql('event.action', self::RESET_ACTIONS) . ' as reset_count')
            ->selectRaw($this->conditionalCountSql('event.code', ['subscription_leak_guard']) . ' as leak_guard_event_count')
            ->selectRaw($this->conditionalCountSql('event.code', ['source_batch_pull']) . ' as source_batch_event_count')
            ->selectRaw($this->conditionalCountSql('event.code', ['source_ip_denylist']) . ' as source_deny_event_count')
            ->selectRaw("SUM(CASE WHEN event.ip_type IN ('hosting', 'proxy') THEN 1 ELSE 0 END) as infrastructure_source_event_count")
            ->selectRaw('COUNT(DISTINCT event.client_ip) as distinct_client_ip_count')
            ->selectRaw('COUNT(DISTINCT event.ua_category) as distinct_ua_count')
            ->selectRaw('COUNT(DISTINCT event.region) as distinct_region_count')
            ->selectRaw('MAX(event.online_ip_count) as max_online_ip_count')
            ->selectRaw('MAX(event.threshold) as max_threshold')
            ->selectRaw('MAX(event.risk_score) as max_event_risk_score')
            ->selectRaw('MAX(event.source_user_count) as max_source_user_count')
            ->selectRaw('MAX(event.created_at) as last_trigger_at')
            ->groupBy($groupBy)
            ->get()
            ->map(function (object $row) use ($hasSites): array {
                $item = [
                    'user_id' => (int) $row->user_id,
                    'email' => trim((string) ($row->email ?? '')) ?: null,
                    'site_id' => $hasSites && $row->site_id !== null ? (int) $row->site_id : null,
                    'site_name' => $hasSites ? (trim((string) ($row->site_name ?? '')) ?: null) : null,
                    'event_count' => (int) $row->event_count,
                    'blocking_event_count' => (int) $row->blocking_event_count,
                    'reset_count' => (int) $row->reset_count,
                    'leak_guard_event_count' => (int) $row->leak_guard_event_count,
                    'source_batch_event_count' => (int) $row->source_batch_event_count,
                    'source_deny_event_count' => (int) $row->source_deny_event_count,
                    'infrastructure_source_event_count' => (int) $row->infrastructure_source_event_count,
                    'distinct_client_ip_count' => (int) $row->distinct_client_ip_count,
                    'distinct_ua_count' => (int) $row->distinct_ua_count,
                    'distinct_region_count' => (int) $row->distinct_region_count,
                    'max_online_ip_count' => $row->max_online_ip_count === null ? null : (int) $row->max_online_ip_count,
                    'max_threshold' => $row->max_threshold === null ? null : (int) $row->max_threshold,
                    'max_event_risk_score' => $row->max_event_risk_score === null ? null : (int) $row->max_event_risk_score,
                    'max_source_user_count' => $row->max_source_user_count === null ? null : (int) $row->max_source_user_count,
                    'last_trigger_at' => $row->last_trigger_at === null ? null : (int) $row->last_trigger_at,
                ];
                $item['risk_score'] = $this->riskScore($item);

                return $item;
            })
            ->filter(static fn(array $item): bool =>
                (int) $item['reset_count'] > 0
                || (int) $item['risk_score'] >= 14
                || (int) ($item['max_event_risk_score'] ?? 0) >= 60
                || (int) ($item['max_source_user_count'] ?? 0) >= 3
                || (int) ($item['source_deny_event_count'] ?? 0) >= 2
            )
            ->sort(static fn(array $left, array $right): int =>
                ($right['risk_score'] <=> $left['risk_score'])
                ?: ($right['last_trigger_at'] <=> $left['last_trigger_at'])
            )
            ->values();

        $total = $rows->count();
        $candidateLimit = max(100, $limit * 5);
        $items = $this->attachEvidence($rows->take($candidateLimit)->all(), $cutoff);
        usort($items, static fn(array $left, array $right): int =>
            ($right['suspicion_score'] <=> $left['suspicion_score'])
            ?: ($right['last_trigger_at'] <=> $left['last_trigger_at'])
        );
        return [
            'available' => true,
            'window_days' => $days,
            'total' => $total,
            'items' => $items,
            'calculation' => 'deterministic_local_insider_case_queue_with_review_calibration',
            'case_model_version' => self::CASE_MODEL_VERSION,
            'sent_to_ai' => false,
            'automatic_enforcement' => false,
        ];
    }

    /** @param array<string, mixed> $item */
    private function riskScore(array $item): int
    {
        $score = min(10, (int) $item['event_count']);
        $score += min(15, (int) $item['blocking_event_count'] * 2);
        $score += min(12, (int) $item['reset_count'] * 4);
        $score += min(10, max(0, (int) $item['distinct_client_ip_count'] - 1));
        $score += min(8, max(0, (int) $item['distinct_ua_count'] - 1) * 2);
        $score += min(9, max(0, (int) $item['distinct_region_count'] - 1) * 3);
        $score += min(10, intdiv(max(0, (int) ($item['max_event_risk_score'] ?? 0)), 10));
        $score += min(10, max(0, (int) ($item['max_source_user_count'] ?? 0) - 1));

        if ($item['max_online_ip_count'] !== null && $item['max_threshold'] !== null) {
            $overage = (int) $item['max_online_ip_count'] - (int) $item['max_threshold'];
            if ($overage > 0) {
                $score += min(8, $overage + 3);
            }
        }

        return $score;
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, mixed>> */
    private function attachEvidence(array $items, int $cutoff): array
    {
        if ($items === []) {
            return [];
        }

        $byUser = [];
        foreach ($items as $index => $item) {
            $item += [
                'risk_level' => 'high',
                'client_ips' => [],
                'proxy_ips' => [],
                'ua_categories' => [],
                'regions' => [],
                'event_codes' => [],
                'actions' => [],
                'reasons' => [],
                'signals' => [],
                'ip_types' => [],
                'ip_organizations' => [],
                '_case_events' => [],
            ];
            $items[$index] = $item;
            $byUser[(int) $item['user_id']] = $index;
        }

        DB::table(self::EVENT_TABLE)
            ->where('created_at', '>=', $cutoff)
            ->whereIn('user_id', array_keys($byUser))
            ->orderByDesc('created_at')
            ->select([
                'user_id', 'code', 'action', 'reason', 'client_ip', 'proxy_ip',
                'ua_category', 'ua_categories', 'region', 'regions', 'ip_type', 'ip_org',
                'source_user_count', 'risk_score', 'signals', 'active_plan_user',
                'used_traffic', 'transfer_enable', 'created_at',
            ])
            ->get()
            ->each(function (object $row) use (&$items, $byUser): void {
                $index = $byUser[(int) $row->user_id] ?? null;
                if ($index === null) {
                    return;
                }
                $this->appendUnique($items[$index]['client_ips'], $row->client_ip ?? null, 8);
                $this->appendUnique($items[$index]['proxy_ips'], $row->proxy_ip ?? null, 5);
                $this->appendUnique($items[$index]['ua_categories'], $row->ua_category ?? null, 8);
                $this->appendJsonValues($items[$index]['ua_categories'], $row->ua_categories ?? null, 8);
                $this->appendUnique($items[$index]['regions'], $row->region ?? null, 8);
                $this->appendJsonValues($items[$index]['regions'], $row->regions ?? null, 8);
                $this->appendUnique($items[$index]['event_codes'], $row->code ?? null, 8);
                $this->appendUnique($items[$index]['actions'], $row->action ?? null, 8);
                $this->appendUnique($items[$index]['reasons'], $row->reason ?? null, 3);
                $this->appendJsonValues($items[$index]['signals'], $row->signals ?? null, 16);
                $this->appendUnique($items[$index]['ip_types'], $row->ip_type ?? null, 8);
                $this->appendUnique($items[$index]['ip_organizations'], $row->ip_org ?? null, 5);
                $items[$index]['_case_events'][] = [
                    'code' => trim((string) ($row->code ?? '')),
                    'action' => trim((string) ($row->action ?? '')),
                    'created_at' => is_numeric($row->created_at ?? null) ? (int) $row->created_at : null,
                    'risk_score' => is_numeric($row->risk_score ?? null) ? (int) $row->risk_score : null,
                    'source_user_count' => is_numeric($row->source_user_count ?? null) ? (int) $row->source_user_count : null,
                    'ip_type' => trim((string) ($row->ip_type ?? '')),
                    'client_ip' => trim((string) ($row->client_ip ?? '')),
                    'ua_category' => trim((string) ($row->ua_category ?? '')),
                    'region' => trim((string) ($row->region ?? '')),
                    'reason' => mb_substr(trim((string) ($row->reason ?? '')), 0, 240),
                    'signals' => $this->decodeJsonValues($row->signals ?? null),
                ];
            });

        foreach ($items as $index => $item) {
            $items[$index] = $this->analyzeCase($item);
        }

        return array_values($items);
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function analyzeCase(array $item): array
    {
        $events = is_array($item['_case_events'] ?? null) ? $item['_case_events'] : [];
        unset($item['_case_events']);
        usort($events, static fn(array $left, array $right): int =>
            (($left['created_at'] ?? 0) <=> ($right['created_at'] ?? 0))
        );
        $evidenceTimeline = array_map(
            static fn(array $event): array => [
                'code' => (string) ($event['code'] ?? ''),
                'action' => (string) ($event['action'] ?? ''),
                'created_at' => $event['created_at'] ?? null,
                'risk_score' => $event['risk_score'] ?? null,
                'source_user_count' => $event['source_user_count'] ?? null,
                'ip_type' => (string) ($event['ip_type'] ?? ''),
                'client_ip' => (string) ($event['client_ip'] ?? ''),
                'ua_category' => (string) ($event['ua_category'] ?? ''),
                'region' => (string) ($event['region'] ?? ''),
                'reason' => (string) ($event['reason'] ?? ''),
                'signals' => array_values((array) ($event['signals'] ?? [])),
            ],
            array_slice(array_reverse($events), 0, 12)
        );

        $resetTimes = [];
        foreach ($events as $event) {
            if (in_array((string) ($event['action'] ?? ''), self::RESET_ACTIONS, true)
                && is_int($event['created_at'] ?? null)) {
                $resetTimes[] = (int) $event['created_at'];
            }
        }

        $retriggerBuckets = [];
        $lastPostResetTriggerAt = null;
        foreach ($events as $event) {
            $createdAt = $event['created_at'] ?? null;
            if (!is_int($createdAt) || !$this->isSuspiciousCaseEvent($event)) {
                continue;
            }
            foreach ($resetTimes as $resetAt) {
                if ($createdAt > ($resetAt + 60) && $createdAt <= ($resetAt + 259200)) {
                    $retriggerBuckets[intdiv($createdAt, 300)] = true;
                    $lastPostResetTriggerAt = max($lastPostResetTriggerAt ?? 0, $createdAt);
                    break;
                }
            }
        }

        $postResetRetriggerCount = count($retriggerBuckets);
        $signals = array_values(array_unique(array_map('strval', (array) ($item['signals'] ?? []))));
        $strongSignals = array_values(array_intersect($signals, self::STRONG_SIGNAL_KEYS));
        $hasLowUsageDistribution = count(array_intersect($signals, [
            'active_plan_very_low_usage',
            'active_plan_low_usage_with_many_ua',
            'active_plan_low_usage_with_many_ips',
            'active_plan_low_usage_with_online_mismatch',
        ])) > 0;
        $hasInfrastructureSource = (int) ($item['infrastructure_source_event_count'] ?? 0) > 0
            || count(array_intersect((array) ($item['ip_types'] ?? []), ['hosting', 'proxy'])) > 0
            || count(array_intersect($signals, ['ip_intelligence_hosting', 'ip_intelligence_proxy'])) > 0;

        $evidence = [];
        if ($postResetRetriggerCount > 0) {
            $evidence[] = 'post_reset_retrigger';
        }
        if ((int) ($item['leak_guard_event_count'] ?? 0) > 0 || (int) ($item['max_event_risk_score'] ?? 0) >= 70) {
            $evidence[] = 'leak_guard';
        }
        if ((int) ($item['max_source_user_count'] ?? 0) >= 3 || (int) ($item['source_batch_event_count'] ?? 0) > 0) {
            $evidence[] = 'source_sharing';
        }
        if ((int) ($item['distinct_client_ip_count'] ?? 0) >= 4
            || (int) ($item['distinct_ua_count'] ?? 0) >= 3
            || (int) ($item['distinct_region_count'] ?? 0) >= 3) {
            $evidence[] = 'distributed_access';
        }
        if ($hasInfrastructureSource) {
            $evidence[] = 'infrastructure_source';
        }
        if ($hasLowUsageDistribution) {
            $evidence[] = 'low_usage_distribution';
        }
        if ((int) ($item['reset_count'] ?? 0) > 0) {
            $evidence[] = 'credential_resets';
        }
        if ((int) ($item['source_deny_event_count'] ?? 0) >= 2) {
            $evidence[] = 'source_denylist';
        }
        $evidence = array_values(array_unique($evidence));

        $score = min(35, (int) round(max(0, (int) ($item['max_event_risk_score'] ?? 0)) * 0.35));
        $score += min(12, (int) ($item['leak_guard_event_count'] ?? 0) * 3);
        $score += min(10, (int) ($item['reset_count'] ?? 0) * 4);
        $score += min(18, $postResetRetriggerCount * 6);
        $score += (int) ($item['distinct_client_ip_count'] ?? 0) >= 10 ? 12
            : ((int) ($item['distinct_client_ip_count'] ?? 0) >= 6 ? 8
            : ((int) ($item['distinct_client_ip_count'] ?? 0) >= 3 ? 4 : 0));
        $score += (int) ($item['distinct_ua_count'] ?? 0) >= 4 ? 8
            : ((int) ($item['distinct_ua_count'] ?? 0) >= 3 ? 5 : 0);
        $score += (int) ($item['distinct_region_count'] ?? 0) >= 4 ? 8
            : ((int) ($item['distinct_region_count'] ?? 0) >= 3 ? 5 : 0);
        $score += $hasInfrastructureSource ? 8 : 0;
        $score += $hasLowUsageDistribution ? 8 : 0;
        $score += (int) ($item['max_source_user_count'] ?? 0) >= 20 ? 15
            : ((int) ($item['max_source_user_count'] ?? 0) >= 10 ? 10
            : ((int) ($item['max_source_user_count'] ?? 0) >= 3 ? 5 : 0));
        $score += min(6, count($strongSignals) * 2);
        $score = min(100, $score);

        $falsePositiveFactors = [];
        $nonDenyEvidence = array_values(array_diff($evidence, ['source_denylist']));
        if ((int) ($item['source_deny_event_count'] ?? 0) > 0 && count($nonDenyEvidence) === 0) {
            $falsePositiveFactors[] = 'denylist_only';
            $score = min($score, 45);
        }
        if ((int) ($item['max_source_user_count'] ?? 0) >= 3 && !$hasInfrastructureSource) {
            $falsePositiveFactors[] = 'shared_nat_possible';
        }
        if ($item['max_event_risk_score'] === null) {
            $falsePositiveFactors[] = 'unscored_rules';
        }
        if ((int) ($item['event_count'] ?? 0) < 3) {
            $falsePositiveFactors[] = 'limited_evidence';
        }
        if (count($evidence) < 2) {
            $score = min($score, 49);
        }

        $highConfidencePattern = $postResetRetriggerCount > 0
            || ((int) ($item['leak_guard_event_count'] ?? 0) > 0 && $hasInfrastructureSource);
        if ($score >= 75 && count($evidence) >= 3 && $highConfidencePattern) {
            $confidence = 'high';
            $verdict = 'probable_subscription_leak';
            $recommendedAction = 'reset_and_observe';
        } elseif ($score >= 55 && count($evidence) >= 2) {
            $confidence = 'medium';
            $verdict = 'suspected_subscription_leak';
            $recommendedAction = 'observe_and_verify';
        } else {
            $confidence = 'low';
            $verdict = 'watch_required';
            $recommendedAction = 'keep_observing';
        }

        $item['suspicion_score'] = $score;
        $item['confidence'] = $confidence;
        $item['verdict'] = $verdict;
        $item['case_evidence'] = $evidence;
        $item['evidence_strength'] = count($evidence);
        $item['strong_signals'] = $strongSignals;
        $item['post_reset_retrigger_count'] = $postResetRetriggerCount;
        $item['latest_reset_at'] = $resetTimes === [] ? null : max($resetTimes);
        $item['latest_post_reset_trigger_at'] = $lastPostResetTriggerAt;
        $item['false_positive_factors'] = array_values(array_unique($falsePositiveFactors));
        $item['recommended_action'] = $recommendedAction;
        $item['requires_manual_review'] = true;
        $item['automatic_enforcement'] = false;
        $item['evidence_timeline'] = $evidenceTimeline;

        return $item;
    }

    /** @param array<string, mixed> $event */
    private function isSuspiciousCaseEvent(array $event): bool
    {
        if (in_array((string) ($event['action'] ?? ''), self::BLOCKING_ACTIONS, true)) {
            return true;
        }
        if (in_array((string) ($event['code'] ?? ''), self::STRONG_EVENT_CODES, true)) {
            return true;
        }

        return count(array_intersect((array) ($event['signals'] ?? []), self::STRONG_SIGNAL_KEYS)) > 0;
    }

    /** @param array<int, string> $values */
    private function appendUnique(array &$values, mixed $value, int $limit): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value !== '' && count($values) < $limit && !in_array($value, $values, true)) {
            $values[] = $value;
        }
    }

    /** @param array<int, string> $values */
    private function appendJsonValues(array &$values, mixed $encoded, int $limit): void
    {
        foreach ($this->decodeJsonValues($encoded) as $value) {
            $this->appendUnique($values, $value, $limit);
        }
    }

    /** @return array<int, string> */
    private function decodeJsonValues(mixed $encoded): array
    {
        if (is_array($encoded)) {
            return array_values(array_filter(array_map('strval', $encoded)));
        }
        $decoded = json_decode((string) ($encoded ?? ''), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded)));
    }

    /** @param array<int, string> $values */
    private function conditionalCountSql(string $column, array $values): string
    {
        $quoted = array_map(static fn(string $value): string => "'" . str_replace("'", "''", $value) . "'", $values);

        return sprintf('SUM(CASE WHEN %s IN (%s) THEN 1 ELSE 0 END)', $column, implode(', ', $quoted));
    }

    /** @return array<string, mixed> */
    private function unavailable(int $days): array
    {
        return [
            'available' => false,
            'window_days' => $days,
            'total' => 0,
            'items' => [],
            'case_model_version' => self::CASE_MODEL_VERSION,
            'sent_to_ai' => false,
            'automatic_enforcement' => false,
        ];
    }
}
