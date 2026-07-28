<?php

declare(strict_types=1);

namespace App\Services;

use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;

final class SubscriptionRiskContextService
{
    /** @return array<string, mixed> */
    public function build(int $userId, string $email): array
    {
        $empty = $this->emptyContext();
        if ($userId <= 0) {
            return $empty;
        }

        try {
            $store = new SubscriptionControlEventStore();
            if (!$store->available()) {
                return $empty;
            }

            $events = $store->recent(100, trim($email));
        } catch (\Throwable) {
            return $empty;
        }

        $events = array_values(array_filter($events, static function (array $event) use ($userId): bool {
            return (int) ($event['user_id'] ?? 0) === $userId;
        }));

        if ($events === []) {
            return array_merge($empty, ['available' => true]);
        }

        $maxScore = 0;
        foreach ($events as $event) {
            $maxScore = max($maxScore, (int) ($event['risk_score'] ?? 0));
        }

        $riskLevel = $maxScore >= 60 ? 'high' : ($maxScore >= 20 ? 'medium' : 'low');
        $resetCount = count(array_filter($events, static fn (array $event): bool => in_array(
            (string) ($event['action'] ?? ''),
            ['reset_token', 'reset_token_uuid'],
            true
        )));

        return [
            'available' => true,
            'risk_level' => $riskLevel,
            'risk_score' => $maxScore,
            'event_count' => count($events),
            'reset_count' => $resetCount,
            'last_trigger_at' => (int) ($events[0]['created_at'] ?? 0) ?: null,
            'client_ips' => $this->unique(array_column($events, 'client_ip')),
            'ua_categories' => $this->unique(array_merge(array_column($events, 'ua_category'), array_column($events, 'ua_categories'))),
            'regions' => $this->unique(array_merge(array_column($events, 'region'), array_column($events, 'regions'))),
            'ip_types' => $this->unique(array_column($events, 'ip_type')),
            'latest_events' => array_map(static function (array $event): array {
                return [
                    'id' => (string) ($event['id'] ?? ''),
                    'code' => (string) ($event['code'] ?? ''),
                    'reason' => (string) ($event['reason'] ?? ''),
                    'action' => (string) ($event['action'] ?? ''),
                    'client_ip' => $event['client_ip'] ?? null,
                    'ua_category' => $event['ua_category'] ?? null,
                    'region' => $event['region'] ?? null,
                    'created_at' => $event['created_at'] ?? null,
                ];
            }, array_slice($events, 0, 5)),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyContext(): array
    {
        return [
            'available' => false,
            'risk_level' => 'none',
            'risk_score' => 0,
            'event_count' => 0,
            'reset_count' => 0,
            'last_trigger_at' => null,
            'client_ips' => [],
            'ua_categories' => [],
            'regions' => [],
            'ip_types' => [],
            'latest_events' => [],
        ];
    }

    /** @return list<string> */
    private function unique(array $values, int $limit = 6): array
    {
        $result = [];
        foreach ($values as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $item = trim((string) ($item ?? ''));
                if ($item !== '' && !in_array($item, $result, true)) {
                    $result[] = $item;
                }
            }
        }

        return array_slice($result, 0, $limit);
    }
}
