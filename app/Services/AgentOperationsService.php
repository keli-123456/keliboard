<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AgentOperationsService
{
    public function __construct(private AgentOrderStatusResolver $statusResolver) {}

    /**
     * @return array<string, int>
     */
    public function agentSummary(User $agent): array
    {
        return $this->summaryForAgent($agent);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function agentOrders(User $agent, array $filters): array
    {
        return $this->paginatedOrders($this->orderQuery($agent->id), $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function agentOrderDetail(User $agent, string $tradeNo): array
    {
        $context = $this->orderQuery($agent->id)
            ->where('trade_no', $tradeNo)
            ->first();

        if ($context === null) {
            throw new ApiException('Agent order not found');
        }

        return $this->orderRow($context);
    }

    /**
     * @return array<string, int>
     */
    public function adminSummary(): array
    {
        $agents = $this->adminAgents(['page_size' => 100])['data'];

        return [
            'active_agent_count' => count($agents),
            'pending_hold_total' => array_sum(array_map(
                static fn (array $row): int => (int) $row['pending_hold_total'],
                $agents
            )),
            'abnormal_order_count' => array_sum(array_map(
                static fn (array $row): int => (int) $row['abnormal_order_count'],
                $agents
            )),
            'insufficient_balance_agent_count' => count(array_filter(
                $agents,
                static fn (array $row): bool => (int) $row['available_balance'] <= 0
                    && (int) $row['pending_hold_total'] > 0
            )),
            'no_active_payment_agent_count' => count(array_filter(
                $agents,
                static fn (array $row): bool => (int) $row['enabled_payment_count'] === 0
            )),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function adminAgents(array $filters): array
    {
        $page = $this->positiveInt($filters['page'] ?? 1, 1);
        $pageSize = min(100, $this->positiveInt($filters['page_size'] ?? 20, 20));
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        $query = User::query()
            ->whereIn('id', AgentOrderContext::query()->select('agent_user_id')->distinct())
            ->orderBy('id');

        if ($keyword !== '') {
            $query->where('email', 'like', '%' . $keyword . '%');
        }

        $total = (int) $query->count();
        $agents = $query->forPage($page, $pageSize)->get();
        $data = [];

        foreach ($agents as $agent) {
            $data[] = array_merge([
                'agent_user_id' => (int) $agent->id,
                'agent_email' => $agent->email,
            ], $this->summaryForAgent($agent), [
                'active_domain_count' => $this->activeDomainCount((int) $agent->id),
                'enabled_payment_count' => $this->enabledPaymentCount((int) $agent->id),
            ]);
        }

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminAgentDetail(int $agentUserId): array
    {
        $agent = User::query()->find($agentUserId);
        if ($agent === null) {
            throw new ApiException('Agent not found');
        }

        return array_merge([
            'agent_user_id' => (int) $agent->id,
            'agent_email' => $agent->email,
        ], $this->summaryForAgent($agent), [
            'active_domain_count' => $this->activeDomainCount((int) $agent->id),
            'enabled_payment_count' => $this->enabledPaymentCount((int) $agent->id),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
     */
    public function adminOrdersForAgent(int $agentUserId, array $filters): array
    {
        return $this->paginatedOrders($this->orderQuery($agentUserId), $filters);
    }

    /**
     * @return array<string, int>
     */
    private function summaryForAgent(User $agent): array
    {
        $agentUserId = (int) $agent->id;
        $pendingHoldTotal = app(AgentCommerceService::class)->activePendingHoldTotal($agentUserId);

        $paidContexts = $this->contextsForMonth($agentUserId)->get();
        $monthSalesTotal = 0;
        $monthCostTotal = 0;
        $monthMarginTotal = 0;

        foreach ($paidContexts as $context) {
            $resolved = $this->statusResolver->resolve($context);
            $monthSalesTotal += (int) $context->sale_amount;
            $monthCostTotal += (int) $context->cost_amount;
            $monthMarginTotal += (int) $resolved['margin_amount'];
        }

        return [
            'balance' => (int) ($agent->balance ?? 0),
            'available_balance' => max(0, (int) ($agent->balance ?? 0) - $pendingHoldTotal),
            'pending_hold_total' => $pendingHoldTotal,
            'month_sales_total' => $monthSalesTotal,
            'month_cost_total' => $monthCostTotal,
            'month_margin_total' => $monthMarginTotal,
            'pending_order_count' => (int) AgentOrderContext::query()
                ->where('agent_user_id', $agentUserId)
                ->where('status', AgentOrderContext::STATUS_PENDING)
                ->whereHas('order', function (Builder $query): void {
                    $query->whereIn('status', [
                        Order::STATUS_PENDING,
                        Order::STATUS_PROCESSING,
                    ]);
                })
                ->count(),
            'abnormal_order_count' => $this->abnormalOrderCount($agentUserId),
        ];
    }

    private function contextsForMonth(int $agentUserId): Builder
    {
        $start = strtotime(date('Y-m-01 00:00:00')) ?: time();
        $end = strtotime(date('Y-m-t 23:59:59')) ?: time();

        return $this->orderQuery($agentUserId)
            ->where('status', AgentOrderContext::STATUS_PAID)
            ->whereBetween('created_at', [$start, $end]);
    }

    private function abnormalOrderCount(int $agentUserId): int
    {
        return $this->orderQuery($agentUserId)
            ->get()
            ->filter(fn (AgentOrderContext $context): bool => $this->hasAbnormalFlag($context))
            ->count();
    }

    private function orderQuery(int $agentUserId): Builder
    {
        return AgentOrderContext::query()
            ->with(['agent', 'order.user', 'order.plan', 'domain', 'hold', 'payment'])
            ->where('agent_user_id', $agentUserId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param Builder<AgentOrderContext> $query
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, page_size: int}
     */
    private function paginatedOrders(Builder $query, array $filters): array
    {
        $page = $this->positiveInt($filters['page'] ?? 1, 1);
        $pageSize = min(100, $this->positiveInt($filters['page_size'] ?? 20, 20));
        $this->applyOrderFilters($query, $filters);

        $rows = $query->get()
            ->map(fn (AgentOrderContext $context): array => $this->orderRow($context));

        if (array_key_exists('abnormal', $filters)) {
            $expectAbnormal = filter_var($filters['abnormal'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($expectAbnormal !== null) {
                $rows = $rows->filter(
                    static fn (array $row): bool => (count($row['abnormal_flags']) > 0) === $expectAbnormal
                );
            }
        }

        $rows = $rows->values();
        $total = $rows->count();

        return [
            'data' => $rows->forPage($page, $pageSize)->values()->all(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param Builder<AgentOrderContext> $query
     * @param array<string, mixed> $filters
     */
    private function applyOrderFilters(Builder $query, array $filters): void
    {
        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['domain_id']) && $filters['domain_id'] !== '') {
            $query->where('agent_domain_id', (int) $filters['domain_id']);
        }

        if (isset($filters['payment_id']) && $filters['payment_id'] !== '') {
            $query->where('payment_id', (int) $filters['payment_id']);
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function (Builder $keywordQuery) use ($keyword): void {
                $like = '%' . $keyword . '%';
                $keywordQuery->where('trade_no', 'like', $like)
                    ->orWhereHas('order.user', static function (Builder $userQuery) use ($like): void {
                        $userQuery->where('email', 'like', $like);
                    })
                    ->orWhereHas('domain', static function (Builder $domainQuery) use ($like): void {
                        $domainQuery->where('domain', 'like', $like);
                    });
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function orderRow(AgentOrderContext $context): array
    {
        $resolved = $this->statusResolver->resolve($context);
        $order = $context->order;
        $buyer = $order?->user;
        $agent = $context->agent;
        $domain = $context->domain;
        $payment = $context->payment;

        return [
            'trade_no' => $context->trade_no,
            'buyer_user_id' => $buyer?->id === null ? null : (int) $buyer->id,
            'buyer_email' => $buyer?->email,
            'agent_user_id' => (int) $context->agent_user_id,
            'agent_email' => $agent?->email,
            'agent_domain_id' => $context->agent_domain_id === null ? null : (int) $context->agent_domain_id,
            'domain' => $domain?->domain ?? ($context->domain_snapshot['domain'] ?? null),
            'plan_name' => $order?->plan?->name ?? ($context->pricing_snapshot['plan_name'] ?? null),
            'period' => $context->pricing_snapshot['period'] ?? $order?->period,
            'sale_amount' => (int) $context->sale_amount,
            'platform_cost' => (int) $context->cost_amount,
            'margin_amount' => (int) $resolved['margin_amount'],
            'payment_id' => $context->payment_id === null ? null : (int) $context->payment_id,
            'payment_name' => $payment?->name ?? ($context->payment_snapshot['name'] ?? null),
            'payment_code' => $payment?->payment ?? ($context->payment_snapshot['payment'] ?? null),
            'order_status' => $order?->status === null ? null : (int) $order->status,
            'context_status' => $context->status,
            'hold_status' => $resolved['hold_status'],
            'capture_status' => $resolved['capture_status'],
            'abnormal_flags' => $resolved['abnormal_flags'],
            'created_at' => $context->created_at,
            'updated_at' => $context->updated_at,
        ];
    }

    private function hasAbnormalFlag(AgentOrderContext $context): bool
    {
        return count($this->statusResolver->resolve($context)['abnormal_flags']) > 0;
    }

    private function activeDomainCount(int $agentUserId): int
    {
        return (int) AgentDomain::query()
            ->where('agent_user_id', $agentUserId)
            ->where('status', AgentDomain::STATUS_ACTIVE)
            ->count();
    }

    private function enabledPaymentCount(int $agentUserId): int
    {
        return (int) Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_id', $agentUserId)
            ->where('enable', true)
            ->count();
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $value = (int) $value;

        return $value > 0 ? $value : $default;
    }
}
