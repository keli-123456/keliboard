<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminOperationTask;
use App\Models\AgentUser;
use App\Models\AiDiagnosticReport;
use App\Models\DomainHealth;
use App\Models\Order;
use App\Models\Server;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AiDiagnosticEvidenceService
{
    public function detail(AiDiagnosticReport $report, string $findingKey): array
    {
        $finding = $this->finding($report, $findingKey);
        $window = (array) data_get($report->metrics, 'window', []);
        $startAt = (int) ($window['current_start'] ?? max(0, (int) $report->generated_at - 86400));
        $endAt = (int) ($window['current_end'] ?? (int) $report->generated_at);
        $subjectId = (int) data_get($finding, 'evidence.subject_id', 0);

        $evidence = match (true) {
            str_starts_with($findingKey, 'referral_') => $this->referralEvidence($report, $startAt, $endAt, $subjectId),
            in_array($findingKey, ['business_income_drop', 'payment_success_low', 'payment_pending_surge'], true)
                => $this->orderEvidence($report, $startAt, $endAt),
            $findingKey === 'business_registration_drop' => $this->userEvidence($report, $startAt, $endAt),
            $findingKey === 'business_traffic_drop' => $this->trafficEvidence($report, $startAt, $endAt),
            $findingKey === 'business_ticket_surge' => $this->ticketEvidence($report, $startAt, $endAt),
            $findingKey === 'infrastructure_nodes_offline' => $this->nodeEvidence(),
            $findingKey === 'infrastructure_domain_unhealthy' => $this->domainEvidence(),
            $findingKey === 'infrastructure_failed_tasks' => $this->taskEvidence($startAt),
            default => ['kind' => 'summary', 'records' => []],
        };

        return [
            'finding' => $finding,
            'window' => ['start_at' => $startAt, 'end_at' => $endAt],
            'trend' => $this->findingTrend($report->scope_key, $findingKey, 30),
            'evidence' => $evidence,
            'limits' => ['records' => 30, 'trend_points' => 30],
            'read_only' => true,
        ];
    }

    public function history(string $scopeKey, int $days = 30): array
    {
        if (!Schema::hasTable('v2_ai_diagnostic_report')) {
            return [];
        }

        return AiDiagnosticReport::query()
            ->where('scope_key', $scopeKey)
            ->where('generated_at', '>=', time() - (max(1, min(90, $days)) * 86400))
            ->orderByDesc('generated_at')
            ->limit(90)
            ->get()
            ->reverse()
            ->values()
            ->map(static fn (AiDiagnosticReport $report): array => [
                'id' => (int) $report->id,
                'generated_at' => (int) $report->generated_at,
                'status' => (string) $report->status,
                'score' => (int) $report->score,
                'critical' => (int) data_get($report->summary, 'critical', 0),
                'warning' => (int) data_get($report->summary, 'warning', 0),
                'finding_count' => (int) data_get($report->summary, 'finding_count', 0),
            ])
            ->all();
    }

    private function finding(AiDiagnosticReport $report, string $findingKey): array
    {
        foreach ((array) $report->findings as $finding) {
            if ((string) ($finding['key'] ?? '') === $findingKey) {
                return (array) $finding;
            }
        }

        throw new RuntimeException('Diagnostic finding was not found in this report');
    }

    private function findingTrend(string $scopeKey, string $findingKey, int $limit): array
    {
        if (!Schema::hasTable('v2_ai_diagnostic_report')) {
            return [];
        }

        return AiDiagnosticReport::query()
            ->where('scope_key', $scopeKey)
            ->orderByDesc('generated_at')
            ->limit(max(1, min(60, $limit)))
            ->get()
            ->reverse()
            ->values()
            ->map(function (AiDiagnosticReport $report) use ($findingKey): array {
                $matched = null;
                foreach ((array) $report->findings as $finding) {
                    if ((string) ($finding['key'] ?? '') === $findingKey) {
                        $matched = (array) $finding;
                        break;
                    }
                }

                return [
                    'report_id' => (int) $report->id,
                    'generated_at' => (int) $report->generated_at,
                    'score' => (int) $report->score,
                    'severity' => $matched['severity'] ?? null,
                    'current' => $matched !== null ? data_get($matched, 'evidence.current') : null,
                    'baseline' => $matched !== null ? data_get($matched, 'evidence.baseline') : null,
                ];
            })
            ->all();
    }

    private function orderEvidence(AiDiagnosticReport $report, int $startAt, int $endAt): array
    {
        $orders = $this->orderQuery($report, $startAt, $endAt)
            ->with('user:id,email')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return [
            'kind' => 'orders',
            'records' => $orders->map(static fn (Order $order): array => [
                'id' => (int) $order->id,
                'trade_no' => (string) $order->trade_no,
                'user_id' => (int) $order->user_id,
                'email' => (string) ($order->user?->email ?? ''),
                'amount' => (int) $order->total_amount,
                'status' => (int) $order->status,
                'commission' => (int) ($order->actual_commission_balance ?? $order->commission_balance ?? 0),
                'created_at' => (int) $order->created_at,
            ])->all(),
        ];
    }

    private function userEvidence(AiDiagnosticReport $report, int $startAt, int $endAt): array
    {
        $users = $this->userQuery($report)
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'email', 'site_id', 'invite_user_id', 'last_login_ip', 'created_at']);

        return [
            'kind' => 'users',
            'records' => $users->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'site_id' => $user->site_id !== null ? (int) $user->site_id : null,
                'invite_user_id' => $user->invite_user_id !== null ? (int) $user->invite_user_id : null,
                'last_login_ip' => $this->formatIp($user->last_login_ip),
                'created_at' => (int) $user->created_at,
            ])->all(),
        ];
    }

    private function trafficEvidence(AiDiagnosticReport $report, int $startAt, int $endAt): array
    {
        $query = StatUser::query()
            ->from('v2_stat_user as stats')
            ->join('v2_user as users', 'users.id', '=', 'stats.user_id')
            ->where('stats.record_type', 'd')
            ->where('stats.record_at', '>=', $startAt)
            ->where('stats.record_at', '<', $endAt);
        $this->applyScope($query, $report, 'users.site_id');
        $this->excludeAgentUsersByAlias($query, 'users.id');

        return [
            'kind' => 'traffic',
            'records' => $query
                ->selectRaw('users.id, users.email, SUM(stats.u) as upload, SUM(stats.d) as download')
                ->groupBy('users.id', 'users.email')
                ->orderByDesc(DB::raw('SUM(stats.u + stats.d)'))
                ->limit(30)
                ->get()
                ->map(static fn ($row): array => [
                    'id' => (int) $row->id,
                    'email' => (string) $row->email,
                    'upload' => (int) $row->upload,
                    'download' => (int) $row->download,
                ])->all(),
        ];
    }

    private function ticketEvidence(AiDiagnosticReport $report, int $startAt, int $endAt): array
    {
        $query = Ticket::query()
            ->with('user:id,email')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $this->applyScope($query, $report);
        if (Schema::hasColumn('v2_ticket', 'agent_user_id')) {
            $query->whereNull('agent_user_id');
        }

        return [
            'kind' => 'tickets',
            'records' => $query->orderByDesc('created_at')->limit(30)->get()->map(static fn (Ticket $ticket): array => [
                'id' => (int) $ticket->id,
                'subject' => (string) $ticket->subject,
                'user_id' => (int) $ticket->user_id,
                'email' => (string) ($ticket->user?->email ?? ''),
                'status' => (int) $ticket->status,
                'created_at' => (int) $ticket->created_at,
            ])->all(),
        ];
    }

    private function referralEvidence(AiDiagnosticReport $report, int $startAt, int $endAt, int $subjectId): array
    {
        $invitees = $this->userQuery($report)
            ->whereNotNull('invite_user_id')
            ->where('created_at', '>=', $startAt)
            ->where('created_at', '<', $endAt);
        $ranking = (clone $invitees)
            ->selectRaw('invite_user_id, COUNT(*) as invite_count')
            ->groupBy('invite_user_id')
            ->orderByDesc('invite_count')
            ->limit(20)
            ->get();
        $inviterIds = $ranking->pluck('invite_user_id')->map(static fn ($id): int => (int) $id)->all();
        $inviters = User::query()->whereIn('id', $inviterIds)->get(['id', 'email'])->keyBy('id');
        $inviteeIds = (clone $invitees)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $paidIds = $inviteeIds === [] ? [] : $this->orderQuery($report, $startAt, $endAt)
            ->whereIn('user_id', $inviteeIds)
            ->whereNotIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
            ->pluck('user_id')->map(static fn ($id): int => (int) $id)->unique()->all();
        $paidLookup = array_fill_keys($paidIds, true);

        $records = $ranking->map(function ($row) use ($inviters, $invitees, $paidLookup): array {
            $inviterId = (int) $row->invite_user_id;
            $ids = (clone $invitees)->where('invite_user_id', $inviterId)->pluck('id')
                ->map(static fn ($id): int => (int) $id)->all();
            $paidCount = count(array_filter($ids, static fn (int $id): bool => isset($paidLookup[$id])));

            return [
                'id' => $inviterId,
                'email' => (string) ($inviters->get($inviterId)?->email ?? ''),
                'invite_count' => (int) $row->invite_count,
                'paid_count' => $paidCount,
                'conversion' => (int) $row->invite_count > 0 ? round($paidCount / (int) $row->invite_count, 4) : 0.0,
            ];
        })->all();

        $focusedInvitees = clone $invitees;
        if ($subjectId > 0) {
            $focusedInvitees->where('invite_user_id', $subjectId);
        }
        $recentInvitees = $focusedInvitees->orderByDesc('created_at')->limit(30)
            ->get(['id', 'email', 'invite_user_id', 'last_login_ip', 'created_at']);
        $ipGroups = (clone $focusedInvitees)
            ->whereNotNull('last_login_ip')
            ->where('last_login_ip', '<>', 0)
            ->selectRaw('last_login_ip, COUNT(*) as user_count')
            ->groupBy('last_login_ip')
            ->orderByDesc('user_count')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'ip' => $this->formatIp($row->last_login_ip),
                'user_count' => (int) $row->user_count,
            ])->all();

        $commissionOrders = $this->orderQuery($report, $startAt, $endAt)
            ->when($subjectId > 0, fn (Builder $query) => $query->where('invite_user_id', $subjectId))
            ->where('commission_status', Order::COMMISSION_STATUS_PENDING)
            ->where(function (Builder $query): void {
                $query->where('commission_balance', '>', 0)->orWhere('actual_commission_balance', '>', 0);
            })
            ->with('user:id,email')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(static fn (Order $order): array => [
                'id' => (int) $order->id,
                'trade_no' => (string) $order->trade_no,
                'email' => (string) ($order->user?->email ?? ''),
                'amount' => (int) $order->total_amount,
                'commission' => (int) ($order->actual_commission_balance ?? $order->commission_balance ?? 0),
                'created_at' => (int) $order->created_at,
            ])->all();

        return [
            'kind' => 'referral',
            'records' => $records,
            'invitees' => $recentInvitees->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'invite_user_id' => (int) $user->invite_user_id,
                'last_login_ip' => $this->formatIp($user->last_login_ip),
                'created_at' => (int) $user->created_at,
                'paid' => isset($paidLookup[(int) $user->id]),
            ])->all(),
            'ip_concentration' => $ipGroups,
            'commission_orders' => $commissionOrders,
            'device_evidence' => ['available' => false, 'reason' => 'device_fingerprint_not_collected'],
            'agent_downlines_excluded' => true,
        ];
    }

    private function nodeEvidence(): array
    {
        $records = Server::query()->where('enabled', true)->whereNull('parent_id')->get()
            ->filter(static fn (Server $server): bool => (int) $server->available_status === Server::STATUS_OFFLINE)
            ->take(30)
            ->map(static fn (Server $server): array => [
                'id' => (int) $server->id,
                'name' => (string) $server->name,
                'type' => (string) $server->type,
                'host' => (string) $server->host,
                'last_check_at' => $server->last_check_at !== null ? (int) $server->last_check_at : null,
            ])->values()->all();

        return ['kind' => 'nodes', 'records' => $records];
    }

    private function domainEvidence(): array
    {
        if (!Schema::hasTable('v2_domain_health')) {
            return ['kind' => 'domains', 'records' => []];
        }

        return [
            'kind' => 'domains',
            'records' => DomainHealth::query()->where('monitored', true)
                ->whereIn('status', [DomainHealth::STATUS_WARNING, DomainHealth::STATUS_DOWN])
                ->orderByDesc('last_failure_at')->limit(30)->get()
                ->map(static fn (DomainHealth $domain): array => [
                    'id' => (int) $domain->id,
                    'domain' => (string) $domain->domain,
                    'source_type' => (string) $domain->source_type,
                    'status' => (string) $domain->status,
                    'reason' => (string) ($domain->reason ?? ''),
                    'http_status' => $domain->http_status !== null ? (int) $domain->http_status : null,
                    'last_checked_at' => $domain->last_checked_at !== null ? (int) $domain->last_checked_at : null,
                ])->all(),
        ];
    }

    private function taskEvidence(int $startAt): array
    {
        if (!Schema::hasTable('v2_admin_operation_task')) {
            return ['kind' => 'tasks', 'records' => []];
        }

        return [
            'kind' => 'tasks',
            'records' => AdminOperationTask::query()->where('created_at', '>=', $startAt)
                ->whereIn('status', [AdminOperationTask::STATUS_FAILED, AdminOperationTask::STATUS_PARTIAL, AdminOperationTask::STATUS_INTERRUPTED])
                ->orderByDesc('created_at')->limit(30)->get()
                ->map(static fn (AdminOperationTask $task): array => [
                    'id' => (string) $task->id,
                    'title' => (string) $task->title,
                    'operation' => (string) $task->operation,
                    'status' => (string) $task->status,
                    'failed' => (int) $task->failed,
                    'last_error' => (string) ($task->last_error ?? ''),
                    'created_at' => (int) $task->created_at,
                ])->all(),
        ];
    }

    private function orderQuery(AiDiagnosticReport $report, int $startAt, int $endAt): Builder
    {
        $query = Order::query()->where('created_at', '>=', $startAt)->where('created_at', '<', $endAt);
        $this->applyScope($query, $report);
        if (Schema::hasTable('v2_agent_order_context')) {
            $query->whereDoesntHave('agentOrderContext');
        }

        return $query;
    }

    private function userQuery(AiDiagnosticReport $report): Builder
    {
        $query = User::query();
        $this->applyScope($query, $report);
        if (Schema::hasTable('v2_agent_user')) {
            $query->whereNotIn('id', AgentUser::query()->select('sub_user_id'));
        }

        return $query;
    }

    private function applyScope(Builder $query, AiDiagnosticReport $report, string $column = 'site_id'): void
    {
        if ($report->scope_type === 'platform') {
            return;
        }

        $report->site_id === null ? $query->whereNull($column) : $query->where($column, $report->site_id);
    }

    private function excludeAgentUsersByAlias(Builder $query, string $column): void
    {
        if (!Schema::hasTable('v2_agent_user')) {
            return;
        }

        $query->whereNotExists(function ($subquery) use ($column): void {
            $subquery->selectRaw('1')->from('v2_agent_user as diagnostic_agent_users')
                ->whereColumn('diagnostic_agent_users.sub_user_id', $column);
        });
    }

    private function formatIp(mixed $value): ?string
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        return long2ip((int) $value) ?: null;
    }
}
