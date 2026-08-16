<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionControlOutcomeMetricsService
{
    private const EVENT_TABLE = 'v2_subscription_control_event';
    private const TICKET_TABLE = 'v2_ticket';
    private const TICKET_MESSAGE_TABLE = 'v2_ticket_message';
    private const DISTRIBUTION_SAMPLE_LIMIT = 50000;
    private const OUTCOME_HORIZON_SECONDS = 86400;
    private const ENFORCEMENT_ACTIONS = ['reset_token', 'reset_token_uuid', 'block', 'empty', 'throttle'];

    private const FIELD_SPECS = [
        'subscription_leak_guard' => [
            'risk_score' => 'number',
            'ip_count' => 'number',
            'ua_categories' => 'json_count',
            'regions' => 'json_count',
        ],
        'source_batch_pull' => ['source_user_count' => 'number'],
        'multi_ua_pull' => ['ua_categories' => 'json_count'],
        'multi_region_pull' => ['regions' => 'json_count'],
        'multi_region_online' => ['online_regions' => 'json_count'],
        'online_ip_threshold' => ['online_ip_count' => 'number'],
    ];

    private const APPEAL_KEYWORDS = [
        '风控', '误封', '误判', '被封', '封禁', '拉黑',
        '重置订阅', '订阅被重置', '订阅链接失效', '订阅失效',
    ];

    /** @return array<string, mixed> */
    public function collect(int $cutoff): array
    {
        if (!Schema::hasTable(self::EVENT_TABLE)) {
            return [
                'field_distributions' => [],
                'post_action_outcomes' => $this->unavailableOutcome(),
                'appeal_signals' => $this->unavailableAppeals(),
            ];
        }

        $events = DB::table(self::EVENT_TABLE)->where('created_at', '>=', $cutoff);

        return [
            'field_distributions' => $this->fieldDistributions($events),
            'post_action_outcomes' => $this->postActionOutcomes($events),
            'appeal_signals' => $this->appealSignals($cutoff),
        ];
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    private function fieldDistributions(Builder $events): array
    {
        $columns = ['code', 'created_at'];
        foreach (self::FIELD_SPECS as $specs) {
            foreach (array_keys($specs) as $column) {
                if (Schema::hasColumn(self::EVENT_TABLE, $column)) {
                    $columns[] = $column;
                }
            }
        }
        $columns = array_values(array_unique($columns));
        $rows = (clone $events)
            ->select($columns)
            ->orderByDesc('created_at')
            ->limit(self::DISTRIBUTION_SAMPLE_LIMIT + 1)
            ->get();
        $sampled = $rows->count() > self::DISTRIBUTION_SAMPLE_LIMIT;
        if ($sampled) {
            $rows = $rows->take(self::DISTRIBUTION_SAMPLE_LIMIT);
        }

        $values = [];
        foreach ($rows as $row) {
            $code = (string) ($row->code ?? '');
            foreach (self::FIELD_SPECS[$code] ?? [] as $field => $kind) {
                if (!property_exists($row, $field) || $row->{$field} === null) {
                    continue;
                }
                $value = $this->observedValue($row->{$field}, $kind);
                if ($value !== null) {
                    $values[$code][$field][] = $value;
                }
            }
        }

        $result = [];
        foreach ($values as $code => $fields) {
            foreach ($fields as $field => $observations) {
                $result[$code][$field] = $this->distribution($observations, $sampled);
            }
        }

        return $result;
    }

    private function observedValue(mixed $value, string $kind): ?int
    {
        if ($kind === 'number') {
            return is_numeric($value) ? (int) $value : null;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        return is_array($decoded) ? count($decoded) : null;
    }

    /** @param array<int, int> $values @return array<string, mixed> */
    private function distribution(array $values, bool $sampled): array
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);

        return [
            'sample_count' => $count,
            'sampled' => $sampled,
            'minimum' => $count > 0 ? $values[0] : null,
            'maximum' => $count > 0 ? $values[$count - 1] : null,
            'average' => $count > 0 ? round(array_sum($values) / $count, 2) : null,
            'p50' => $this->percentile($values, 0.50),
            'p90' => $this->percentile($values, 0.90),
            'p95' => $this->percentile($values, 0.95),
            'scope' => 'triggered_events_only',
        ];
    }

    /** @param array<int, int> $values */
    private function percentile(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }

        $index = max(0, min(count($values) - 1, (int) ceil($percentile * count($values)) - 1));

        return $values[$index];
    }

    /** @return array<string, mixed> */
    private function postActionOutcomes(Builder $events): array
    {
        $now = time();
        $rows = (clone $events)
            ->whereNotNull('user_id')
            ->where('user_id', '>', 0)
            ->whereIn('action', self::ENFORCEMENT_ACTIONS)
            ->select(['user_id', 'code', 'created_at'])
            ->orderBy('user_id')
            ->orderBy('code')
            ->orderBy('created_at')
            ->get();
        $groups = [];
        foreach ($rows as $row) {
            $code = trim((string) ($row->code ?? ''));
            $userId = (int) ($row->user_id ?? 0);
            if ($code !== '' && $userId > 0) {
                $groups[$code . ':' . $userId][] = (int) ($row->created_at ?? 0);
            }
        }

        $eligible = 0;
        $repeat = 0;
        $immature = 0;
        $byCode = [];
        foreach ($groups as $key => $timestamps) {
            [$code] = explode(':', $key, 2);
            $anchor = $timestamps[0] ?? 0;
            if ($anchor <= 0 || $anchor > $now - self::OUTCOME_HORIZON_SECONDS) {
                $immature++;
                continue;
            }
            $eligible++;
            $byCode[$code]['eligible_pairs'] = ($byCode[$code]['eligible_pairs'] ?? 0) + 1;
            $repeated = false;
            foreach (array_slice($timestamps, 1) as $timestamp) {
                if ($timestamp > $anchor && $timestamp <= $anchor + self::OUTCOME_HORIZON_SECONDS) {
                    $repeated = true;
                    break;
                }
            }
            if ($repeated) {
                $repeat++;
                $byCode[$code]['repeat_within_horizon_pairs'] = ($byCode[$code]['repeat_within_horizon_pairs'] ?? 0) + 1;
            }
        }

        foreach ($byCode as $code => $stats) {
            $codeEligible = (int) ($stats['eligible_pairs'] ?? 0);
            $codeRepeat = (int) ($stats['repeat_within_horizon_pairs'] ?? 0);
            $byCode[$code] = [
                'eligible_pairs' => $codeEligible,
                'repeat_within_horizon_pairs' => $codeRepeat,
                'quiet_after_horizon_pairs' => max(0, $codeEligible - $codeRepeat),
                'repeat_within_horizon_rate' => $this->ratio($codeRepeat, $codeEligible),
                'quiet_after_horizon_rate' => $this->ratio($codeEligible - $codeRepeat, $codeEligible),
            ];
        }

        return [
            'available' => true,
            'horizon_hours' => 24,
            'eligible_user_rule_pairs' => $eligible,
            'immature_user_rule_pairs' => $immature,
            'repeat_within_horizon_pairs' => $repeat,
            'quiet_after_horizon_pairs' => max(0, $eligible - $repeat),
            'repeat_within_horizon_rate' => $this->ratio($repeat, $eligible),
            'quiet_after_horizon_rate' => $this->ratio($eligible - $repeat, $eligible),
            'by_code' => $byCode,
            'interpretation' => 'absence_of_repeat_is_not_confirmed_recovery',
        ];
    }

    /** @return array<string, mixed> */
    private function appealSignals(int $cutoff): array
    {
        if (!Schema::hasTable(self::TICKET_TABLE)) {
            return $this->unavailableAppeals();
        }

        $related = DB::table(self::TICKET_TABLE . ' as tickets')
            ->where('tickets.created_at', '>=', $cutoff)
            ->whereExists(function (Builder $query) use ($cutoff): void {
                $query->selectRaw('1')
                    ->from(self::EVENT_TABLE . ' as risk_events')
                    ->whereColumn('risk_events.user_id', 'tickets.user_id')
                    ->where('risk_events.created_at', '>=', $cutoff)
                    ->whereColumn('risk_events.created_at', '<=', 'tickets.created_at');
            });
        $matched = (clone $related)->where(function (Builder $query): void {
            $query->where(function (Builder $subject): void {
                foreach (self::APPEAL_KEYWORDS as $index => $keyword) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $subject->{$method}('tickets.subject', 'like', '%' . $keyword . '%');
                }
            });
            if (Schema::hasTable(self::TICKET_MESSAGE_TABLE)) {
                $query->orWhereExists(function (Builder $messages): void {
                    $messages->selectRaw('1')
                        ->from(self::TICKET_MESSAGE_TABLE . ' as ticket_messages')
                        ->whereColumn('ticket_messages.ticket_id', 'tickets.id')
                        ->where(function (Builder $body): void {
                            foreach (self::APPEAL_KEYWORDS as $index => $keyword) {
                                $method = $index === 0 ? 'where' : 'orWhere';
                                $body->{$method}('ticket_messages.message', 'like', '%' . $keyword . '%');
                            }
                        });
                });
            }
        });

        $relatedTickets = (int) (clone $related)->distinct()->count('tickets.id');
        $matchingTickets = (int) (clone $matched)->distinct()->count('tickets.id');
        $matchingUsers = (int) (clone $matched)->distinct()->count('tickets.user_id');
        $affectedUsers = (int) DB::table(self::EVENT_TABLE)
            ->where('created_at', '>=', $cutoff)
            ->whereNotNull('user_id')
            ->distinct()
            ->count('user_id');

        return [
            'available' => true,
            'method' => 'keyword_matched_tickets_after_risk_event',
            'related_ticket_count' => $relatedTickets,
            'matching_ticket_count' => $matchingTickets,
            'matching_user_count' => $matchingUsers,
            'matching_ticket_rate' => $this->ratio($matchingTickets, $relatedTickets),
            'affected_user_signal_rate' => $this->ratio($matchingUsers, $affectedUsers),
            'confirmed_false_positive' => false,
            'personal_data_included' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailableOutcome(): array
    {
        return ['available' => false, 'interpretation' => 'absence_of_repeat_is_not_confirmed_recovery'];
    }

    /** @return array<string, mixed> */
    private function unavailableAppeals(): array
    {
        return [
            'available' => false,
            'method' => 'keyword_matched_tickets_after_risk_event',
            'confirmed_false_positive' => false,
            'personal_data_included' => false,
        ];
    }

    private function ratio(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole, 6) : 0.0;
    }
}
