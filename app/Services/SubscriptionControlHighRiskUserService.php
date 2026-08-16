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
    private const BLOCKING_ACTIONS = ['block', 'empty', 'throttle', 'reset_token', 'reset_token_uuid'];
    private const RESET_ACTIONS = ['reset_token', 'reset_token_uuid'];

    /** @return array<string, mixed> */
    public function collect(int $days = 7, int $limit = 20): array
    {
        $days = max(3, min(30, $days));
        $limit = max(1, min(50, $limit));
        if (!Schema::hasTable(self::EVENT_TABLE) || !Schema::hasTable(self::USER_TABLE)) {
            return $this->unavailable($days);
        }

        return Cache::remember(
            sprintf('subscription_control:high_risk_users:%d:%d', $days, $limit),
            60,
            fn(): array => $this->aggregate($days, $limit)
        );
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
            ->selectRaw('COUNT(DISTINCT event.client_ip) as distinct_client_ip_count')
            ->selectRaw('COUNT(DISTINCT event.ua_category) as distinct_ua_count')
            ->selectRaw('COUNT(DISTINCT event.region) as distinct_region_count')
            ->selectRaw('MAX(event.online_ip_count) as max_online_ip_count')
            ->selectRaw('MAX(event.threshold) as max_threshold')
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
                    'distinct_client_ip_count' => (int) $row->distinct_client_ip_count,
                    'distinct_ua_count' => (int) $row->distinct_ua_count,
                    'distinct_region_count' => (int) $row->distinct_region_count,
                    'max_online_ip_count' => $row->max_online_ip_count === null ? null : (int) $row->max_online_ip_count,
                    'max_threshold' => $row->max_threshold === null ? null : (int) $row->max_threshold,
                    'last_trigger_at' => $row->last_trigger_at === null ? null : (int) $row->last_trigger_at,
                ];
                $item['risk_score'] = $this->riskScore($item);

                return $item;
            })
            ->filter(static fn(array $item): bool =>
                (int) $item['reset_count'] > 0 || (int) $item['risk_score'] >= 14
            )
            ->sort(static fn(array $left, array $right): int =>
                ($right['risk_score'] <=> $left['risk_score'])
                ?: ($right['last_trigger_at'] <=> $left['last_trigger_at'])
            )
            ->values();

        $total = $rows->count();
        $items = $this->attachEvidence($rows->take($limit)->all(), $cutoff);

        return [
            'available' => true,
            'window_days' => $days,
            'total' => $total,
            'items' => $items,
            'calculation' => 'deterministic_local_review_queue',
            'sent_to_ai' => false,
            'automatic_enforcement' => false,
        ];
    }

    /** @param array<string, mixed> $item */
    private function riskScore(array $item): int
    {
        $score = (int) $item['event_count'];
        $score += (int) $item['blocking_event_count'] * 3;
        $score += (int) $item['reset_count'] * 4;
        $score += max(0, (int) $item['distinct_client_ip_count'] - 1);
        $score += max(0, (int) $item['distinct_ua_count'] - 1) * 2;
        $score += max(0, (int) $item['distinct_region_count'] - 1) * 3;

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
                'ua_category', 'ua_categories', 'region', 'regions',
            ])
            ->get()
            ->each(function (object $row) use (&$items, $byUser): void {
                $index = $byUser[(int) $row->user_id] ?? null;
                if ($index === null) {
                    return;
                }
                $this->appendUnique($items[$index]['client_ips'], $row->client_ip ?? null, 5);
                $this->appendUnique($items[$index]['proxy_ips'], $row->proxy_ip ?? null, 5);
                $this->appendUnique($items[$index]['ua_categories'], $row->ua_category ?? null, 8);
                $this->appendJsonValues($items[$index]['ua_categories'], $row->ua_categories ?? null, 8);
                $this->appendUnique($items[$index]['regions'], $row->region ?? null, 8);
                $this->appendJsonValues($items[$index]['regions'], $row->regions ?? null, 8);
                $this->appendUnique($items[$index]['event_codes'], $row->code ?? null, 8);
                $this->appendUnique($items[$index]['actions'], $row->action ?? null, 8);
                $this->appendUnique($items[$index]['reasons'], $row->reason ?? null, 3);
            });

        return array_values($items);
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
        $decoded = json_decode((string) ($encoded ?? ''), true);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $value) {
            $this->appendUnique($values, $value, $limit);
        }
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
            'sent_to_ai' => false,
            'automatic_enforcement' => false,
        ];
    }
}
