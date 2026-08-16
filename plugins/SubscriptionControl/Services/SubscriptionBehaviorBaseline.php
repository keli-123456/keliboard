<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\Cache;

final class SubscriptionBehaviorBaseline
{
    private const STATE_VERSION = 1;
    private const MAX_DIMENSIONS = 24;

    public function __construct(private readonly array $config = [])
    {
    }

    public function observe(
        int $userId,
        string $token,
        string $ipFingerprint,
        string $uaCategory,
        ?string $region,
        array $onlineRegions = [],
        bool $trustedEgress = false,
        bool $riskyClient = false,
        bool $trustedClient = false
    ): ?array {
        $now = time();
        $window = $this->configInt('behavior_baseline_window_seconds', 2592000, 86400);
        $minimumObservations = $this->configInt('behavior_baseline_min_observations', 8, 3);
        $scoreThreshold = $this->configInt('behavior_baseline_score_threshold', 45, 1);
        $stateKey = $this->stateKey($userId, $token);
        $state = $this->normalizeState(Cache::get($stateKey), $now);
        $priorObservations = (int) $state['observations'];
        $mature = $priorObservations >= $minimumObservations;

        $uaCategory = trim($uaCategory) !== '' ? trim($uaCategory) : 'unknown';
        $region = $this->normalizeRegion($region);
        $onlineRegions = $this->normalizeRegions($onlineRegions);
        $ipFingerprint = trim($ipFingerprint) !== '' ? trim($ipFingerprint) : 'invalid';

        $newUa = !isset($state['ua_counts'][$uaCategory]);
        $newRegion = $region !== null && !isset($state['region_counts'][$region]);
        $newIp = !$trustedEgress && !isset($state['ip_counts'][$ipFingerprint]);
        $stableUa = $this->dominantShare($state['ua_counts']) >= 0.60;
        $stableRegion = $this->dominantShare($state['region_counts']) >= 0.70;
        $stableIp = $this->dominantShare($state['ip_counts']) >= 0.50;

        $interval = $state['last_seen_at'] > 0 ? max(0, $now - (int) $state['last_seen_at']) : null;
        $averageInterval = is_numeric($state['average_interval_seconds'])
            ? (float) $state['average_interval_seconds']
            : null;

        $score = 0;
        $signals = [];
        if ($mature && $newUa && $stableUa) {
            $score += ($riskyClient || !$trustedClient) ? 35 : 10;
            $signals[] = ($riskyClient || !$trustedClient)
                ? 'behavior_new_risky_ua'
                : 'behavior_new_ua';
        }
        if ($mature && $newRegion && $stableRegion) {
            $score += 30;
            $signals[] = 'behavior_new_region';
        }
        if ($mature && $newIp && $stableIp) {
            $score += 10;
            $signals[] = 'behavior_new_ip';
        }
        if (
            $mature
            && $interval !== null
            && $averageInterval !== null
            && $averageInterval >= 120
            && $interval <= min(15, max(5, (int) floor($averageInterval * 0.15)))
        ) {
            $score += 25;
            $signals[] = 'behavior_pull_burst';
        }
        if ($mature && $region !== null && $onlineRegions !== [] && !in_array($region, $onlineRegions, true)) {
            $score += 20;
            $signals[] = 'behavior_online_region_mismatch';
        }
        if (count($signals) >= 2) {
            $score += 15;
            $signals[] = 'behavior_combined_deviation';
        }

        $state = $this->updateState($state, $now, $ipFingerprint, $uaCategory, $region, $interval);
        Cache::put($stateKey, $state, $window);

        if (!$mature || $score < $scoreThreshold || $signals === []) {
            return null;
        }

        $cooldown = $this->configInt('behavior_baseline_event_cooldown_seconds', 1800, 60);
        $eventKey = $this->eventKey($userId, $token, $signals);
        if (!Cache::add($eventKey, $now, $cooldown)) {
            return null;
        }

        return [
            'risk_score' => $score,
            'score_threshold' => $scoreThreshold,
            'signals' => $signals,
            'hit_count' => $priorObservations + 1,
            'ua_category' => $uaCategory,
            'ua_categories' => array_keys($state['ua_counts']),
            'region' => $region,
            'regions' => array_keys($state['region_counts']),
            'online_regions' => $onlineRegions,
            'ip_count' => count($state['ip_counts']),
            'threshold' => $scoreThreshold,
        ];
    }

    private function normalizeState(mixed $raw, int $now): array
    {
        if (!is_array($raw) || (int) ($raw['version'] ?? 0) !== self::STATE_VERSION) {
            return $this->emptyState($now);
        }

        $state = $this->emptyState($now);
        $state['observations'] = max(0, (int) ($raw['observations'] ?? 0));
        $state['first_seen_at'] = max(0, (int) ($raw['first_seen_at'] ?? $now));
        $state['last_seen_at'] = max(0, (int) ($raw['last_seen_at'] ?? 0));
        $state['average_interval_seconds'] = is_numeric($raw['average_interval_seconds'] ?? null)
            ? max(0.0, (float) $raw['average_interval_seconds'])
            : null;
        foreach (['ua_counts', 'region_counts', 'ip_counts'] as $field) {
            $state[$field] = $this->normalizeCounts($raw[$field] ?? []);
        }

        return $state;
    }

    private function emptyState(int $now): array
    {
        return [
            'version' => self::STATE_VERSION,
            'observations' => 0,
            'first_seen_at' => $now,
            'last_seen_at' => 0,
            'average_interval_seconds' => null,
            'ua_counts' => [],
            'region_counts' => [],
            'ip_counts' => [],
        ];
    }

    private function updateState(
        array $state,
        int $now,
        string $ipFingerprint,
        string $uaCategory,
        ?string $region,
        ?int $interval
    ): array {
        $state['observations'] = min(PHP_INT_MAX, (int) $state['observations'] + 1);
        $state['last_seen_at'] = $now;
        $state['ua_counts'] = $this->incrementCount($state['ua_counts'], $uaCategory);
        $state['ip_counts'] = $this->incrementCount($state['ip_counts'], $ipFingerprint);
        if ($region !== null) {
            $state['region_counts'] = $this->incrementCount($state['region_counts'], $region);
        }

        if ($interval !== null && $interval > 0) {
            $previous = is_numeric($state['average_interval_seconds'])
                ? (float) $state['average_interval_seconds']
                : null;
            $state['average_interval_seconds'] = $previous === null
                ? (float) $interval
                : round(($previous * 0.85) + ($interval * 0.15), 2);
        }

        return $state;
    }

    private function incrementCount(array $counts, string $value): array
    {
        $counts[$value] = min(PHP_INT_MAX, (int) ($counts[$value] ?? 0) + 1);
        arsort($counts, SORT_NUMERIC);

        return array_slice($counts, 0, self::MAX_DIMENSIONS, true);
    }

    private function normalizeCounts(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $counts = [];
        foreach ($raw as $key => $count) {
            $key = trim((string) $key);
            if ($key !== '' && is_numeric($count) && (int) $count > 0) {
                $counts[$key] = (int) $count;
            }
        }
        arsort($counts, SORT_NUMERIC);

        return array_slice($counts, 0, self::MAX_DIMENSIONS, true);
    }

    private function dominantShare(array $counts): float
    {
        if ($counts === []) {
            return 0.0;
        }

        $total = array_sum($counts);
        return $total > 0 ? max($counts) / $total : 0.0;
    }

    private function normalizeRegion(?string $region): ?string
    {
        $region = trim((string) $region);
        return $region === '' || in_array($region, ['private', 'unknown'], true) ? null : $region;
    }

    private function normalizeRegions(array $regions): array
    {
        $values = [];
        foreach ($regions as $region) {
            $normalized = $this->normalizeRegion((string) $region);
            if ($normalized !== null) {
                $values[$normalized] = true;
            }
        }
        $values = array_keys($values);
        sort($values, SORT_STRING);

        return $values;
    }

    private function stateKey(int $userId, string $token): string
    {
        return sprintf('subscription_control:behavior_baseline:%d:%s', $userId, hash('sha256', $token));
    }

    private function eventKey(int $userId, string $token, array $signals): string
    {
        sort($signals, SORT_STRING);
        return sprintf(
            'subscription_control:behavior_baseline_event:%d:%s:%s',
            $userId,
            hash('sha256', $token),
            hash('sha256', implode('|', $signals))
        );
    }

    private function configInt(string $key, int $default, int $min): int
    {
        return max($min, (int) ($this->config[$key] ?? $default));
    }
}
