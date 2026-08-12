<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminOperationTask;
use App\Models\AgentUser;
use App\Models\DomainHealth;
use App\Models\Order;
use App\Models\Server;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class AiDiagnosticMetricsService
{
    public function collect(?int $siteId, int $lookbackDays, bool $allSites = false): array
    {
        $now = time();
        $currentStart = $now - 86400;
        $baselineStart = $currentStart - ($lookbackDays * 86400);

        $currentBusiness = $this->businessWindow($siteId, $currentStart, $now, $allSites);
        $baselineBusiness = $this->businessWindow($siteId, $baselineStart, $currentStart, $allSites);
        $currentPayment = $this->paymentWindow($siteId, $currentStart, $now, $allSites);
        $baselinePayment = $this->paymentWindow($siteId, $baselineStart, $currentStart, $allSites);
        $currentReferral = $this->referralWindow($siteId, $currentStart, $now, $allSites);
        $baselineReferral = $this->referralWindow($siteId, $baselineStart, $currentStart, $allSites);

        $metrics = [
            'window' => [
                'current_start' => $currentStart,
                'current_end' => $now,
                'baseline_start' => $baselineStart,
                'baseline_end' => $currentStart,
                'lookback_days' => $lookbackDays,
            ],
            'business' => [
                'income_current' => $currentBusiness['income'],
                'income_baseline' => $this->dailyAverage($baselineBusiness['income'], $lookbackDays),
                'new_users_current' => $currentBusiness['new_users'],
                'new_users_baseline' => $this->dailyAverage($baselineBusiness['new_users'], $lookbackDays),
                'traffic_bytes_current' => $currentBusiness['traffic_bytes'],
                'traffic_bytes_baseline' => $this->dailyAverage($baselineBusiness['traffic_bytes'], $lookbackDays),
                'tickets_current' => $currentBusiness['tickets'],
                'tickets_baseline' => $this->dailyAverage($baselineBusiness['tickets'], $lookbackDays),
            ],
            'payment' => [
                'orders_current' => $currentPayment['orders'],
                'paid_current' => $currentPayment['paid'],
                'pending_current' => $currentPayment['pending'],
                'cancelled_current' => $currentPayment['cancelled'],
                'success_rate_current' => $this->ratio($currentPayment['paid'], $currentPayment['orders']),
                'success_rate_baseline' => $this->ratio($baselinePayment['paid'], $baselinePayment['orders']),
                'pending_rate_current' => $this->ratio($currentPayment['pending'], $currentPayment['orders']),
                'pending_rate_baseline' => $this->ratio($baselinePayment['pending'], $baselinePayment['orders']),
                'cancel_rate_current' => $this->ratio($currentPayment['cancelled'], $currentPayment['orders']),
                'cancel_rate_baseline' => $this->ratio($baselinePayment['cancelled'], $baselinePayment['orders']),
            ],
            'referral' => [
                'invites_current' => $currentReferral['invites'],
                'invites_baseline' => $this->dailyAverage($baselineReferral['invites'], $lookbackDays),
                'conversion_current' => $this->ratio($currentReferral['paid_invitees'], $currentReferral['invites']),
                'conversion_baseline' => $this->ratio($baselineReferral['paid_invitees'], $baselineReferral['invites']),
                'top_inviter_id' => $currentReferral['top_inviter_id'],
                'top_inviter_count' => $currentReferral['top_inviter_count'],
                'top_inviter_share' => $this->ratio($currentReferral['top_inviter_count'], $currentReferral['invites']),
                'pending_commission_amount' => $currentReferral['pending_commission_amount'],
                'pending_commission_count' => $currentReferral['pending_commission_count'],
                'agent_downlines_excluded' => true,
            ],
        ];

        if ($allSites) {
            $metrics['infrastructure'] = $this->infrastructure($currentStart);
        }

        return $metrics;
    }

    private function businessWindow(?int $siteId, int $startAt, int $endAt, bool $allSites): array
    {
        $paidOrders = $this->platformOrderQuery($siteId, $startAt, $endAt, $allSites)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED]);

        $newUsers = User::query();
        $this->applySiteScope($newUsers, $siteId, 'site_id', $allSites);
        $newUsers = $this->excludeAgentDownlines($newUsers)
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $tickets = Ticket::query();
        $this->applySiteScope($tickets, $siteId, 'site_id', $allSites);
        if (Schema::hasColumn('v2_ticket', 'agent_user_id')) {
            $tickets->whereNull('agent_user_id');
        }
        $tickets = $tickets->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->count();

        $traffic = StatUser::query()
            ->from('v2_stat_user as stats')
            ->join('v2_user as users', 'users.id', '=', 'stats.user_id')
            ->where('stats.record_type', 'd')
            ->where('stats.record_at', '>=', $startAt)
            ->where('stats.record_at', '<', $endAt);
        $this->applySiteScope($traffic, $siteId, 'users.site_id', $allSites);
        if (Schema::hasTable('v2_agent_user')) {
            $traffic->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('v2_agent_user as agent_users')
                    ->whereColumn('agent_users.sub_user_id', 'users.id');
            });
        }

        return [
            'income' => (int) (clone $paidOrders)->sum('total_amount'),
            'new_users' => (int) $newUsers,
            'traffic_bytes' => (int) $traffic->sum(DB::raw('stats.u + stats.d')),
            'tickets' => (int) $tickets,
        ];
    }

    private function paymentWindow(?int $siteId, int $startAt, int $endAt, bool $allSites): array
    {
        $query = $this->platformOrderQuery($siteId, $startAt, $endAt, $allSites);
        $row = $query->selectRaw(
            'COUNT(*) as orders, '
            . 'SUM(CASE WHEN status NOT IN (?, ?) THEN 1 ELSE 0 END) as paid, '
            . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending, '
            . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled',
            [Order::STATUS_PENDING, Order::STATUS_CANCELLED, Order::STATUS_PENDING, Order::STATUS_CANCELLED]
        )->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'paid' => (int) ($row->paid ?? 0),
            'pending' => (int) ($row->pending ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
        ];
    }

    private function referralWindow(?int $siteId, int $startAt, int $endAt, bool $allSites): array
    {
        $users = User::query()
            ->whereNotNull('invite_user_id')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $this->applySiteScope($users, $siteId, 'site_id', $allSites);
        $this->excludeAgentDownlines($users);

        $inviteRows = (clone $users)
            ->selectRaw('invite_user_id, COUNT(*) as invite_count')
            ->groupBy('invite_user_id')
            ->orderByDesc('invite_count')
            ->get();
        $inviteeIds = (clone $users)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $paidInvitees = 0;
        if ($inviteeIds !== []) {
            $paidInvitees = $this->platformOrderQuery($siteId, $startAt, $endAt, $allSites)
                ->whereIn('user_id', $inviteeIds)
                ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
                ->distinct('user_id')
                ->count('user_id');
        }

        $commissionQuery = $this->platformOrderQuery($siteId, $startAt, $endAt, $allSites)
            ->where('commission_status', Order::COMMISSION_STATUS_PENDING);
        $commissionRow = $commissionQuery
            ->selectRaw('COUNT(*) as commission_count, SUM(COALESCE(actual_commission_balance, commission_balance, 0)) as commission_amount')
            ->first();
        $top = $inviteRows->first();

        return [
            'invites' => (int) $inviteRows->sum('invite_count'),
            'paid_invitees' => (int) $paidInvitees,
            'top_inviter_id' => (int) ($top->invite_user_id ?? 0),
            'top_inviter_count' => (int) ($top->invite_count ?? 0),
            'pending_commission_amount' => (int) ($commissionRow->commission_amount ?? 0),
            'pending_commission_count' => (int) ($commissionRow->commission_count ?? 0),
        ];
    }

    private function infrastructure(int $since): array
    {
        $enabledNodes = Server::query()->where('enabled', true)->whereNull('parent_id')->get();
        $offlineNodes = $enabledNodes->filter(fn (Server $server): bool => (int) $server->available_status === Server::STATUS_OFFLINE)->count();

        $domainCounts = ['down' => 0, 'warning' => 0];
        if (Schema::hasTable('v2_domain_health')) {
            $domainCounts['down'] = DomainHealth::query()->where('monitored', true)->where('status', DomainHealth::STATUS_DOWN)->count();
            $domainCounts['warning'] = DomainHealth::query()->where('monitored', true)->where('status', DomainHealth::STATUS_WARNING)->count();
        }

        $failedTasks = 0;
        if (Schema::hasTable('v2_admin_operation_task')) {
            $failedTasks = AdminOperationTask::query()
                ->where('created_at', '>=', $since)
                ->whereIn('status', [AdminOperationTask::STATUS_FAILED, AdminOperationTask::STATUS_PARTIAL, AdminOperationTask::STATUS_INTERRUPTED])
                ->count();
        }

        return [
            'enabled_nodes' => $enabledNodes->count(),
            'offline_nodes' => $offlineNodes,
            'down_domains' => $domainCounts['down'],
            'warning_domains' => $domainCounts['warning'],
            'failed_tasks' => $failedTasks,
        ];
    }

    private function platformOrderQuery(?int $siteId, int $startAt, int $endAt, bool $allSites): Builder
    {
        $query = Order::query()
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $this->applySiteScope($query, $siteId, 'site_id', $allSites);
        if (Schema::hasTable('v2_agent_order_context')) {
            $query->whereDoesntHave('agentOrderContext');
        }

        return $query;
    }

    private function excludeAgentDownlines(Builder $query): Builder
    {
        if (Schema::hasTable((new AgentUser())->getTable())) {
            $query->whereNotIn('id', AgentUser::query()->select('sub_user_id'));
        }

        return $query;
    }

    private function applySiteScope(Builder $query, ?int $siteId, string $column = 'site_id', bool $allSites = false): void
    {
        if ($allSites) {
            return;
        }

        $siteId === null ? $query->whereNull($column) : $query->where($column, $siteId);
    }

    private function dailyAverage(float|int $value, int $days): float
    {
        return round($value / max(1, $days), 2);
    }

    private function ratio(float|int $part, float|int $whole): float
    {
        return $whole > 0 ? round($part / $whole, 4) : 0.0;
    }
}
