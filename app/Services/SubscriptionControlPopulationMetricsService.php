<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SubscriptionControlPopulationMetricsService
{
    private const USER_TABLE = 'v2_user';
    private const EVENT_TABLE = 'v2_subscription_control_event';
    private const AGENT_USER_TABLE = 'v2_agent_user';
    private const SITE_TABLE = 'v2_site';

    /** @return array{population:array<string,mixed>,event_evidence:array<string,mixed>} */
    public function collect(int $days): array
    {
        $days = max(3, min(30, $days));
        $cutoff = time() - ($days * 86400);

        return [
            'population' => $this->population($cutoff),
            'event_evidence' => $this->eventEvidence($cutoff),
        ];
    }

    /** @return array<string, mixed> */
    private function population(int $cutoff): array
    {
        if (!Schema::hasTable(self::USER_TABLE)) {
            return [
                'available' => false,
                'scope' => 'all_consumer_users',
                'total_users' => 0,
            ];
        }

        $now = time();
        $users = $this->consumerUsers();
        $active = $this->activeCondition('', $now);
        $used = '(COALESCE(u, 0) + COALESCE(d, 0))';

        $row = (clone $users)->selectRaw(
            "COUNT(*) AS total_users, "
            . "SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) AS active_users, "
            . "SUM(CASE WHEN plan_id IS NOT NULL THEN 1 ELSE 0 END) AS subscribed_users, "
            . "SUM(CASE WHEN COALESCE(banned, 0) = 1 THEN 1 ELSE 0 END) AS banned_users, "
            . "SUM(CASE WHEN plan_id IS NULL THEN 1 ELSE 0 END) AS users_without_plan, "
            . "SUM(CASE WHEN plan_id IS NOT NULL AND COALESCE(expired_at, 0) > 0 AND expired_at <= {$now} THEN 1 ELSE 0 END) AS expired_users, "
            . "SUM(CASE WHEN plan_id IS NOT NULL AND COALESCE(transfer_enable, 0) > 0 AND {$used} >= transfer_enable THEN 1 ELSE 0 END) AS exhausted_users, "
            . "SUM(CASE WHEN plan_id IS NOT NULL AND COALESCE(transfer_enable, 0) <= 0 THEN 1 ELSE 0 END) AS zero_quota_users, "
            . "SUM(CASE WHEN created_at >= {$cutoff} THEN 1 ELSE 0 END) AS new_users_window, "
            . "SUM(CASE WHEN COALESCE(last_login_at, 0) >= {$cutoff} THEN 1 ELSE 0 END) AS recently_logged_in_users, "
            . "SUM(CASE WHEN {$active} AND {$used} <= (transfer_enable * 0.05) THEN 1 ELSE 0 END) AS active_low_usage_users, "
            . "SUM(CASE WHEN {$active} AND {$used} >= (transfer_enable * 0.80) THEN 1 ELSE 0 END) AS active_high_usage_users"
        )->first();

        $totalUsers = (int) ($row->total_users ?? 0);
        $activeUsers = (int) ($row->active_users ?? 0);

        return [
            'available' => true,
            'scope' => 'all_consumer_users',
            'total_users' => $totalUsers,
            'active_users' => $activeUsers,
            'subscribed_users' => (int) ($row->subscribed_users ?? 0),
            'banned_users' => (int) ($row->banned_users ?? 0),
            'users_without_plan' => (int) ($row->users_without_plan ?? 0),
            'expired_users' => (int) ($row->expired_users ?? 0),
            'exhausted_users' => (int) ($row->exhausted_users ?? 0),
            'zero_quota_users' => (int) ($row->zero_quota_users ?? 0),
            'new_users_window' => (int) ($row->new_users_window ?? 0),
            'recently_logged_in_users' => (int) ($row->recently_logged_in_users ?? 0),
            'active_low_usage_users' => (int) ($row->active_low_usage_users ?? 0),
            'active_high_usage_users' => (int) ($row->active_high_usage_users ?? 0),
            'active_user_rate' => $this->ratio($activeUsers, $totalUsers),
            'agent_downline_users' => $this->agentDownlineCount(),
            'site_segments' => $this->siteSegments($cutoff, $now),
            'invitation_baseline' => $this->invitationBaseline($users, $cutoff),
            'shared_login_ip_baseline' => $this->sharedLoginIpBaseline($users),
            'excluded_accounts' => 'administrators_and_staff',
        ];
    }

    /** @return array<string, mixed> */
    private function eventEvidence(int $cutoff): array
    {
        if (!Schema::hasTable(self::EVENT_TABLE)) {
            return [
                'available' => false,
                'total_event_count' => 0,
                'unique_affected_users' => 0,
            ];
        }

        $events = DB::table(self::EVENT_TABLE)->where('created_at', '>=', $cutoff);
        $row = (clone $events)->selectRaw(
            "COUNT(*) AS total_event_count, "
            . "COUNT(DISTINCT user_id) AS unique_affected_users, "
            . "SUM(CASE WHEN action IN ('reset_token', 'reset_token_uuid', 'block', 'empty', 'throttle') THEN 1 ELSE 0 END) AS enforcement_event_count, "
            . "COUNT(DISTINCT CASE WHEN action IN ('reset_token', 'reset_token_uuid') THEN user_id END) AS credential_reset_users, "
            . "SUM(CASE WHEN risk_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_event_count, "
            . "AVG(risk_score) AS average_risk_score, "
            . "MAX(risk_score) AS maximum_risk_score, "
            . "SUM(CASE WHEN LOWER(COALESCE(ip_type, '')) = 'hosting' THEN 1 ELSE 0 END) AS hosting_source_count, "
            . "SUM(CASE WHEN LOWER(COALESCE(ip_type, '')) = 'proxy' THEN 1 ELSE 0 END) AS proxy_source_count"
        )->first();

        $repeatUsers = DB::query()->fromSub(
            (clone $events)
                ->whereNotNull('user_id')
                ->where('user_id', '>', 0)
                ->selectRaw('user_id, COUNT(*) AS hit_count')
                ->groupBy('user_id')
                ->havingRaw('COUNT(*) > 1'),
            'repeated_users'
        )->count();

        $total = (int) ($row->total_event_count ?? 0);
        $unique = (int) ($row->unique_affected_users ?? 0);
        $enforced = (int) ($row->enforcement_event_count ?? 0);
        $scored = (int) ($row->scored_event_count ?? 0);
        $codeBreakdown = $this->codeBreakdown($events);
        $supporting = (new SubscriptionControlOutcomeMetricsService())->collect($cutoff);
        $distributions = is_array($supporting['field_distributions'] ?? null)
            ? $supporting['field_distributions']
            : [];
        $outcomes = is_array($supporting['post_action_outcomes'] ?? null)
            ? $supporting['post_action_outcomes']
            : [];
        $sourceDenyAttribution = is_array($supporting['source_ip_deny_attribution'] ?? null)
            ? $supporting['source_ip_deny_attribution']
            : [];
        $outcomesByCode = is_array($outcomes['by_code'] ?? null) ? $outcomes['by_code'] : [];
        foreach ($codeBreakdown as $code => &$stats) {
            $codeDistributions = is_array($distributions[$code] ?? null) ? $distributions[$code] : [];
            foreach ($codeDistributions as $field => &$distribution) {
                $evidenceCount = (int) ($stats['field_event_counts'][$field] ?? 0);
                $distribution['evidence_count'] = $evidenceCount;
                $distribution['sampled'] = (bool) ($distribution['sampled'] ?? false)
                    || (int) ($distribution['sample_count'] ?? 0) < $evidenceCount;
            }
            unset($distribution);
            $stats['field_distributions'] = $codeDistributions;
            $stats['post_action_outcome'] = is_array($outcomesByCode[$code] ?? null)
                ? $outcomesByCode[$code]
                : [];
            if ($code === 'source_ip_denylist') {
                $stats['source_attribution'] = $sourceDenyAttribution;
            }
        }
        unset($stats);

        return [
            'available' => true,
            'total_event_count' => $total,
            'unique_affected_users' => $unique,
            'repeat_affected_users' => (int) $repeatUsers,
            'enforcement_event_count' => $enforced,
            'credential_reset_users' => (int) ($row->credential_reset_users ?? 0),
            'enforcement_rate' => $this->ratio($enforced, $total),
            'scored_event_count' => $scored,
            'scored_event_rate' => $this->ratio($scored, $total),
            'average_risk_score' => $scored > 0 ? round((float) ($row->average_risk_score ?? 0), 2) : null,
            'maximum_risk_score' => $scored > 0 ? (int) ($row->maximum_risk_score ?? 0) : null,
            'hosting_source_count' => (int) ($row->hosting_source_count ?? 0),
            'proxy_source_count' => (int) ($row->proxy_source_count ?? 0),
            'agent_affected_users' => $this->agentAffectedUsers($cutoff),
            'code_counts' => $this->groupedCounts($events, 'code', 16),
            'action_counts' => $this->groupedCounts($events, 'action', 12),
            'code_breakdown' => $codeBreakdown,
            'post_action_outcomes' => $outcomes,
            'appeal_signals' => is_array($supporting['appeal_signals'] ?? null)
                ? $supporting['appeal_signals']
                : [],
            'source_ip_deny_attribution' => $sourceDenyAttribution,
            'full_window_aggregated' => true,
        ];
    }

    private function consumerUsers(): Builder
    {
        $query = DB::table(self::USER_TABLE)
            ->whereRaw('COALESCE(is_admin, 0) = 0');

        if (Schema::hasColumn(self::USER_TABLE, 'is_staff')) {
            $query->whereRaw('COALESCE(is_staff, 0) = 0');
        }

        return $query;
    }

    /** @return array<int, array<string, mixed>> */
    private function siteSegments(int $cutoff, int $now): array
    {
        if (!Schema::hasColumn(self::USER_TABLE, 'site_id')) {
            return [];
        }

        $active = $this->activeCondition('', $now);
        $rows = $this->consumerUsers()
            ->selectRaw(
                "site_id, COUNT(*) AS total_users, "
                . "SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) AS active_users, "
                . "SUM(CASE WHEN created_at >= {$cutoff} THEN 1 ELSE 0 END) AS new_users_window"
            )
            ->groupBy('site_id')
            ->orderByDesc('total_users')
            ->limit(50)
            ->get();

        $siteNames = Schema::hasTable(self::SITE_TABLE)
            ? DB::table(self::SITE_TABLE)->pluck('name', 'id')->all()
            : [];

        return $rows->map(static function ($row) use ($siteNames): array {
            $siteId = $row->site_id === null ? null : (int) $row->site_id;

            return [
                'site_id' => $siteId,
                'site_name' => $siteId === null ? 'main' : (string) ($siteNames[$siteId] ?? ('site_' . $siteId)),
                'total_users' => (int) ($row->total_users ?? 0),
                'active_users' => (int) ($row->active_users ?? 0),
                'new_users_window' => (int) ($row->new_users_window ?? 0),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    private function invitationBaseline(Builder $users, int $cutoff): array
    {
        $invitees = (clone $users)->whereNotNull('invite_user_id')->where('invite_user_id', '>', 0);
        $grouped = (clone $invitees)
            ->selectRaw('invite_user_id, COUNT(*) AS invite_count')
            ->groupBy('invite_user_id');

        $aggregate = DB::query()->fromSub($grouped, 'inviter_totals')->selectRaw(
            'COUNT(*) AS inviter_count, '
            . 'MAX(invite_count) AS maximum_invitees, '
            . 'SUM(CASE WHEN invite_count >= 10 THEN 1 ELSE 0 END) AS inviters_ge_10, '
            . 'SUM(CASE WHEN invite_count >= 50 THEN 1 ELSE 0 END) AS inviters_ge_50, '
            . 'SUM(CASE WHEN invite_count >= 100 THEN 1 ELSE 0 END) AS inviters_ge_100'
        )->first();

        $topCounts = (clone $grouped)
            ->orderByDesc('invite_count')
            ->limit(10)
            ->pluck('invite_count')
            ->map(static fn ($count): int => (int) $count)
            ->values()
            ->all();

        return [
            'invited_users' => (int) (clone $invitees)->count(),
            'recent_invited_users' => (int) (clone $invitees)->where('created_at', '>=', $cutoff)->count(),
            'distinct_inviters' => (int) ($aggregate->inviter_count ?? 0),
            'maximum_invitees_per_inviter' => (int) ($aggregate->maximum_invitees ?? 0),
            'inviters_with_10_or_more' => (int) ($aggregate->inviters_ge_10 ?? 0),
            'inviters_with_50_or_more' => (int) ($aggregate->inviters_ge_50 ?? 0),
            'inviters_with_100_or_more' => (int) ($aggregate->inviters_ge_100 ?? 0),
            'top_inviter_counts' => $topCounts,
            'personal_identifiers_included' => false,
        ];
    }

    /** @return array<string, int|bool> */
    private function sharedLoginIpBaseline(Builder $users): array
    {
        if (!Schema::hasColumn(self::USER_TABLE, 'last_login_ip')) {
            return [
                'available' => false,
                'largest_cluster' => 0,
                'clusters_ge_5' => 0,
                'clusters_ge_20' => 0,
                'clusters_ge_100' => 0,
            ];
        }

        $grouped = (clone $users)
            ->whereNotNull('last_login_ip')
            ->where('last_login_ip', '<>', 0)
            ->selectRaw('last_login_ip, COUNT(*) AS user_count')
            ->groupBy('last_login_ip');
        $row = DB::query()->fromSub($grouped, 'login_ip_clusters')->selectRaw(
            'MAX(user_count) AS largest_cluster, '
            . 'SUM(CASE WHEN user_count >= 5 THEN 1 ELSE 0 END) AS clusters_ge_5, '
            . 'SUM(CASE WHEN user_count >= 20 THEN 1 ELSE 0 END) AS clusters_ge_20, '
            . 'SUM(CASE WHEN user_count >= 100 THEN 1 ELSE 0 END) AS clusters_ge_100'
        )->first();

        return [
            'available' => true,
            'largest_cluster' => (int) ($row->largest_cluster ?? 0),
            'clusters_ge_5' => (int) ($row->clusters_ge_5 ?? 0),
            'clusters_ge_20' => (int) ($row->clusters_ge_20 ?? 0),
            'clusters_ge_100' => (int) ($row->clusters_ge_100 ?? 0),
        ];
    }

    private function agentDownlineCount(): int
    {
        if (!Schema::hasTable(self::AGENT_USER_TABLE)) {
            return 0;
        }

        return (int) DB::table(self::AGENT_USER_TABLE)->distinct()->count('sub_user_id');
    }

    private function agentAffectedUsers(int $cutoff): int
    {
        if (!Schema::hasTable(self::AGENT_USER_TABLE)) {
            return 0;
        }

        return (int) DB::table(self::EVENT_TABLE . ' as events')
            ->join(self::AGENT_USER_TABLE . ' as agent_users', 'agent_users.sub_user_id', '=', 'events.user_id')
            ->where('events.created_at', '>=', $cutoff)
            ->distinct()
            ->count('events.user_id');
    }

    /** @return array<string, array<string, mixed>> */
    private function codeBreakdown(Builder $events): array
    {
        $rows = (clone $events)
            ->whereNotNull('code')
            ->selectRaw(
                "code, COUNT(*) AS event_count, "
                . "COUNT(DISTINCT user_id) AS affected_users, "
                . "SUM(CASE WHEN action IN ('reset_token', 'reset_token_uuid', 'block', 'empty', 'throttle') THEN 1 ELSE 0 END) AS enforcement_event_count, "
                . "SUM(CASE WHEN action IN ('reset_token', 'reset_token_uuid') THEN 1 ELSE 0 END) AS credential_reset_event_count, "
                . "SUM(CASE WHEN risk_score IS NOT NULL THEN 1 ELSE 0 END) AS risk_score_event_count, "
                . "AVG(risk_score) AS average_risk_score, "
                . "MAX(risk_score) AS maximum_risk_score, "
                . "SUM(CASE WHEN source_user_count IS NOT NULL THEN 1 ELSE 0 END) AS source_user_count_event_count, "
                . "SUM(CASE WHEN online_ip_count IS NOT NULL THEN 1 ELSE 0 END) AS online_ip_count_event_count, "
                . "SUM(CASE WHEN ip_count IS NOT NULL THEN 1 ELSE 0 END) AS ip_count_event_count, "
                . "SUM(CASE WHEN ua_categories IS NOT NULL THEN 1 ELSE 0 END) AS ua_categories_event_count, "
                . "SUM(CASE WHEN regions IS NOT NULL THEN 1 ELSE 0 END) AS regions_event_count, "
                . "SUM(CASE WHEN online_regions IS NOT NULL THEN 1 ELSE 0 END) AS online_regions_event_count, "
                . "SUM(CASE WHEN signals IS NOT NULL THEN 1 ELSE 0 END) AS signals_event_count"
            )
            ->groupBy('code')
            ->orderByDesc('event_count')
            ->limit(20)
            ->get();

        $repeatUsers = DB::query()->fromSub(
            (clone $events)
                ->whereNotNull('code')
                ->whereNotNull('user_id')
                ->where('user_id', '>', 0)
                ->selectRaw('code, user_id, COUNT(*) AS hit_count')
                ->groupBy('code', 'user_id')
                ->havingRaw('COUNT(*) > 1'),
            'repeat_code_users'
        )
            ->selectRaw('code, COUNT(*) AS repeat_affected_users')
            ->groupBy('code')
            ->pluck('repeat_affected_users', 'code')
            ->all();

        return $rows->mapWithKeys(static function ($row) use ($repeatUsers): array {
            $code = (string) $row->code;
            $eventCount = (int) ($row->event_count ?? 0);
            $scoredCount = (int) ($row->risk_score_event_count ?? 0);

            return [$code => [
                'event_count' => $eventCount,
                'affected_users' => (int) ($row->affected_users ?? 0),
                'repeat_affected_users' => (int) ($repeatUsers[$code] ?? 0),
                'enforcement_event_count' => (int) ($row->enforcement_event_count ?? 0),
                'credential_reset_event_count' => (int) ($row->credential_reset_event_count ?? 0),
                'average_risk_score' => $scoredCount > 0 ? round((float) ($row->average_risk_score ?? 0), 2) : null,
                'maximum_risk_score' => $scoredCount > 0 ? (int) ($row->maximum_risk_score ?? 0) : null,
                'field_event_counts' => [
                    'risk_score' => $scoredCount,
                    'source_user_count' => (int) ($row->source_user_count_event_count ?? 0),
                    'online_ip_count' => (int) ($row->online_ip_count_event_count ?? 0),
                    'ip_count' => (int) ($row->ip_count_event_count ?? 0),
                    'ua_categories' => (int) ($row->ua_categories_event_count ?? 0),
                    'regions' => (int) ($row->regions_event_count ?? 0),
                    'online_regions' => (int) ($row->online_regions_event_count ?? 0),
                    'signals' => (int) ($row->signals_event_count ?? 0),
                ],
            ]];
        })->all();
    }

    /** @return array<string, int> */
    private function groupedCounts(Builder $query, string $column, int $limit): array
    {
        return (clone $query)
            ->selectRaw("{$column} AS group_key, COUNT(*) AS aggregate_count")
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('aggregate_count')
            ->limit($limit)
            ->get()
            ->mapWithKeys(static fn ($row): array => [
                (string) ($row->group_key ?? 'unknown') => (int) ($row->aggregate_count ?? 0),
            ])
            ->all();
    }

    private function activeCondition(string $alias, int $now): string
    {
        $prefix = $alias === '' ? '' : rtrim($alias, '.') . '.';
        $used = "(COALESCE({$prefix}u, 0) + COALESCE({$prefix}d, 0))";

        return "COALESCE({$prefix}banned, 0) = 0 "
            . "AND {$prefix}plan_id IS NOT NULL "
            . "AND ({$prefix}expired_at IS NULL OR {$prefix}expired_at > {$now}) "
            . "AND COALESCE({$prefix}transfer_enable, 0) > {$used}";
    }

    private function ratio(int $part, int $whole): float
    {
        return $whole > 0 ? round($part / $whole, 6) : 0.0;
    }
}
