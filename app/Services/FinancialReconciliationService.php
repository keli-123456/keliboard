<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialReconciliationService
{
    public function __construct(
        private readonly FinancialReconciliationIssueScanner $issueScanner
    ) {
    }

    public function overview(array $input): array
    {
        $filters = $this->normalizeFilters($input);
        if (!Schema::hasTable('v2_order') || !Schema::hasTable('v2_user')) {
            return $this->emptyPayload($filters);
        }

        $issues = $this->issueScanner->scan($filters);

        return [
            'generated_at' => time(),
            'range' => [
                'days' => $filters['days'],
                'start_at' => $filters['start_at'],
                'end_at' => $filters['end_at'],
            ],
            'filters' => [
                'scope' => $filters['scope'],
                'site_id' => $filters['site_id'],
                'agent_user_id' => $filters['agent_user_id'],
                'category' => $filters['category'],
                'severity' => $filters['severity'],
                'keyword' => $filters['keyword'],
            ],
            'summary' => array_merge($this->summary($filters), [
                'issue_count' => $issues['total'],
                'high_issue_count' => $issues['high_count'],
            ]),
            'scope_breakdown' => $this->scopeBreakdown($filters),
            'issue_breakdown' => $issues['breakdown'],
            'issues' => [
                'data' => $issues['data'],
                'total' => $issues['total'],
                'sampled_count' => count($issues['data']),
                'limited' => $issues['limited'],
                'sample_limit' => FinancialReconciliationIssueScanner::SAMPLE_LIMIT,
            ],
            'sites' => $this->siteOptions(),
            'agents' => $this->agentOptions(),
            'capabilities' => $this->capabilities(),
        ];
    }

    private function normalizeFilters(array $input): array
    {
        $days = max(1, min(365, (int) ($input['days'] ?? 30)));
        $scopeInput = (string) ($input['scope'] ?? 'all');
        $scope = in_array($scopeInput, ['all', 'platform', 'site', 'agent'], true) ? $scopeInput : 'all';
        $categoryInput = (string) ($input['category'] ?? 'all');
        $category = in_array($categoryInput, [
            'all', 'order', 'payment', 'refund', 'commission', 'agent', 'gift_card',
        ], true) ? $categoryInput : 'all';
        $severityInput = (string) ($input['severity'] ?? 'all');
        $severity = in_array($severityInput, ['all', 'high', 'medium', 'low'], true)
            ? $severityInput : 'all';

        return [
            'days' => $days,
            'start_at' => now()->startOfDay()->subDays($days - 1)->timestamp,
            'end_at' => time(),
            'scope' => $scope,
            'site_id' => !empty($input['site_id']) ? (int) $input['site_id'] : null,
            'agent_user_id' => !empty($input['agent_user_id']) ? (int) $input['agent_user_id'] : null,
            'category' => $category,
            'severity' => $severity,
            'keyword' => trim((string) ($input['keyword'] ?? '')),
        ];
    }

    private function summary(array $filters): array
    {
        $selects = [
            'COUNT(o.id) order_count',
            'SUM(CASE WHEN o.status = ' . Order::STATUS_COMPLETED . ' THEN 1 ELSE 0 END) completed_order_count',
            'SUM(CASE WHEN o.status = ' . Order::STATUS_COMPLETED . ' THEN o.total_amount + COALESCE(o.handling_amount, 0) ELSE 0 END) settled_amount',
            'SUM(COALESCE(o.refund_amount, 0)) refund_amount',
            'SUM(CASE WHEN o.type = ' . Order::TYPE_RECHARGE . ' AND o.status = ' . Order::STATUS_COMPLETED . ' THEN 1 ELSE 0 END) recharge_count',
            'SUM(CASE WHEN o.type = ' . Order::TYPE_RECHARGE . ' AND o.status = ' . Order::STATUS_COMPLETED . ' THEN o.total_amount + COALESCE(o.handling_amount, 0) ELSE 0 END) recharge_amount',
            "SUM(CASE WHEN o.callback_no = 'auto_renew_balance' THEN 1 ELSE 0 END) auto_renew_count",
            "SUM(CASE WHEN o.callback_no = 'auto_renew_balance' AND o.status = " . Order::STATUS_COMPLETED . ' THEN 1 ELSE 0 END) auto_renew_success_count',
            "SUM(CASE WHEN o.callback_no = 'auto_renew_balance' AND o.status IN (" . Order::STATUS_CANCELLED . ',' . Order::STATUS_PROCESSING . ') THEN 1 ELSE 0 END) auto_renew_failed_count',
        ];
        if (Schema::hasTable('v2_agent_order_context')) {
            $selects[] = 'SUM(CASE WHEN aoc.id IS NOT NULL AND o.status = ' . Order::STATUS_COMPLETED . ' THEN aoc.sale_amount ELSE 0 END) agent_sales_amount';
            $selects[] = 'SUM(CASE WHEN aoc.id IS NOT NULL AND o.status = ' . Order::STATUS_COMPLETED . ' THEN aoc.cost_amount ELSE 0 END) agent_cost_amount';
        } else {
            $selects[] = '0 agent_sales_amount';
            $selects[] = '0 agent_cost_amount';
        }
        $selects[] = Schema::hasTable('v2_agent_balance_hold')
            ? "SUM(CASE WHEN abh.status = 'pending' THEN abh.amount ELSE 0 END) agent_pending_hold_amount"
            : '0 agent_pending_hold_amount';
        if (Schema::hasTable('v2_commission_log')) {
            $selects[] = 'SUM(COALESCE(comm.amount, 0)) commission_amount';
            $selects[] = 'SUM(COALESCE(comm.reversed_amount, 0)) commission_reversed_amount';
        } else {
            $selects[] = '0 commission_amount';
            $selects[] = '0 commission_reversed_amount';
        }

        $row = $this->orderQuery($filters)->selectRaw(implode(', ', $selects))->first();

        return [
            'order_count' => (int) ($row->order_count ?? 0),
            'completed_order_count' => (int) ($row->completed_order_count ?? 0),
            'settled_amount' => (int) ($row->settled_amount ?? 0),
            'refund_amount' => (int) ($row->refund_amount ?? 0),
            'recharge_count' => (int) ($row->recharge_count ?? 0),
            'recharge_amount' => (int) ($row->recharge_amount ?? 0),
            'commission_amount' => (int) ($row->commission_amount ?? 0),
            'commission_reversed_amount' => (int) ($row->commission_reversed_amount ?? 0),
            'auto_renew_count' => (int) ($row->auto_renew_count ?? 0),
            'auto_renew_success_count' => (int) ($row->auto_renew_success_count ?? 0),
            'auto_renew_failed_count' => (int) ($row->auto_renew_failed_count ?? 0),
            'agent_sales_amount' => (int) ($row->agent_sales_amount ?? 0),
            'agent_cost_amount' => (int) ($row->agent_cost_amount ?? 0),
            'agent_pending_hold_amount' => (int) ($row->agent_pending_hold_amount ?? 0),
            'gift_card_usage_count' => $this->giftCardUsageCount($filters),
        ];
    }

    private function orderQuery(array $filters): Builder
    {
        $query = DB::table('v2_order as o')
            ->leftJoin('v2_user as u', 'u.id', '=', 'o.user_id')
            ->whereBetween('o.created_at', [$filters['start_at'], $filters['end_at']]);
        if (Schema::hasTable('v2_agent_order_context')) {
            $query->leftJoin('v2_agent_order_context as aoc', 'aoc.order_id', '=', 'o.id');
        }
        if (Schema::hasTable('v2_agent_balance_hold')) {
            $query->leftJoin('v2_agent_balance_hold as abh', 'abh.id', '=', 'aoc.hold_id');
        }
        if (Schema::hasTable('v2_commission_log')) {
            $commission = DB::table('v2_commission_log')->groupBy('trade_no')
                ->select('trade_no')->selectRaw('SUM(get_amount) amount');
            $commission->selectRaw(Schema::hasColumn('v2_commission_log', 'reversed_at')
                ? 'SUM(CASE WHEN reversed_at IS NOT NULL THEN get_amount ELSE 0 END) reversed_amount'
                : '0 reversed_amount');
            $query->leftJoinSub($commission, 'comm', 'comm.trade_no', '=', 'o.trade_no');
        }
        $this->applyOrderFilters($query, $filters);
        return $query;
    }

    private function applyOrderFilters(Builder $query, array $filters): void
    {
        $hasAgentContext = Schema::hasTable('v2_agent_order_context');
        if ($filters['scope'] === 'platform') {
            if ($hasAgentContext) $query->whereNull('aoc.id');
            $query->whereNull('o.site_id');
        } elseif ($filters['scope'] === 'site') {
            if ($hasAgentContext) $query->whereNull('aoc.id');
            $query->whereNotNull('o.site_id');
        } elseif ($filters['scope'] === 'agent') {
            $hasAgentContext ? $query->whereNotNull('aoc.id') : $query->whereRaw('1 = 0');
        }
        if ($filters['site_id']) $query->where('o.site_id', $filters['site_id']);
        if ($filters['agent_user_id']) {
            $hasAgentContext ? $query->where('aoc.agent_user_id', $filters['agent_user_id']) : $query->whereRaw('1 = 0');
        }
        if ($filters['keyword'] !== '') {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(function (Builder $nested) use ($keyword): void {
                $nested->where('o.trade_no', 'like', $keyword)->orWhere('u.email', 'like', $keyword);
            });
        }
    }

    private function scopeBreakdown(array $filters): array
    {
        $scopeFilters = array_merge($filters, ['scope' => 'all', 'site_id' => null, 'agent_user_id' => null, 'keyword' => '']);
        $rows = [];
        $base = $this->orderQuery($scopeFilters);
        $platform = clone $base;
        if (Schema::hasTable('v2_agent_order_context')) $platform->whereNull('aoc.id');
        $rows[] = $this->aggregateScope($platform->whereNull('o.site_id'), 'platform', null, '');

        if (Schema::hasTable('v2_site')) {
            $sites = clone $base;
            if (Schema::hasTable('v2_agent_order_context')) $sites->whereNull('aoc.id');
            $siteRows = $sites->join('v2_site as scope_site', 'scope_site.id', '=', 'o.site_id')
                ->groupBy('o.site_id', 'scope_site.name')
                ->selectRaw("'site' scope_type, o.site_id scope_id, scope_site.name scope_name")
                ->selectRaw($this->scopeAggregateSql())->orderByDesc('settled_amount')->get();
            foreach ($siteRows as $row) $rows[] = $this->mapScopeRow($row);
        }
        if (Schema::hasTable('v2_agent_order_context')) {
            $agentRows = (clone $base)->whereNotNull('aoc.id')
                ->leftJoin('v2_user as scope_agent', 'scope_agent.id', '=', 'aoc.agent_user_id')
                ->groupBy('aoc.agent_user_id', 'scope_agent.email')
                ->selectRaw("'agent' scope_type, aoc.agent_user_id scope_id, scope_agent.email scope_name")
                ->selectRaw($this->scopeAggregateSql(true))->orderByDesc('settled_amount')->limit(100)->get();
            foreach ($agentRows as $row) $rows[] = $this->mapScopeRow($row);
        }
        return $rows;
    }

    private function aggregateScope(Builder $query, string $type, ?int $id, string $name): array
    {
        $row = $query->selectRaw($this->scopeAggregateSql())->first();
        $row->scope_type = $type;
        $row->scope_id = $id;
        $row->scope_name = $name;
        return $this->mapScopeRow($row);
    }

    private function scopeAggregateSql(bool $agent = false): string
    {
        $commission = Schema::hasTable('v2_commission_log') ? 'SUM(COALESCE(comm.amount, 0))' : '0';
        $reversed = Schema::hasTable('v2_commission_log') ? 'SUM(COALESCE(comm.reversed_amount, 0))' : '0';
        $agentCost = $agent ? 'SUM(CASE WHEN o.status = ' . Order::STATUS_COMPLETED . ' THEN aoc.cost_amount ELSE 0 END)' : '0';
        $pending = $agent && Schema::hasTable('v2_agent_balance_hold')
            ? "SUM(CASE WHEN abh.status = 'pending' THEN abh.amount ELSE 0 END)" : '0';
        return implode(', ', [
            'COUNT(o.id) order_count',
            'SUM(CASE WHEN o.status = ' . Order::STATUS_COMPLETED . ' THEN 1 ELSE 0 END) completed_order_count',
            'SUM(CASE WHEN o.status = ' . Order::STATUS_COMPLETED . ' THEN o.total_amount + COALESCE(o.handling_amount, 0) ELSE 0 END) settled_amount',
            'SUM(COALESCE(o.refund_amount, 0)) refund_amount',
            $commission . ' commission_amount', $reversed . ' commission_reversed_amount',
            $agentCost . ' agent_cost_amount', $pending . ' agent_pending_hold_amount',
        ]);
    }

    private function mapScopeRow(object $row): array
    {
        return [
            'scope_type' => (string) $row->scope_type,
            'scope_id' => $row->scope_id !== null ? (int) $row->scope_id : null,
            'scope_name' => (string) ($row->scope_name ?? ''),
            'order_count' => (int) ($row->order_count ?? 0),
            'completed_order_count' => (int) ($row->completed_order_count ?? 0),
            'settled_amount' => (int) ($row->settled_amount ?? 0),
            'refund_amount' => (int) ($row->refund_amount ?? 0),
            'commission_amount' => (int) ($row->commission_amount ?? 0),
            'commission_reversed_amount' => (int) ($row->commission_reversed_amount ?? 0),
            'agent_cost_amount' => (int) ($row->agent_cost_amount ?? 0),
            'agent_pending_hold_amount' => (int) ($row->agent_pending_hold_amount ?? 0),
        ];
    }

    private function giftCardUsageCount(array $filters): int
    {
        if (!Schema::hasTable('v2_gift_card_usage')) return 0;
        $query = DB::table('v2_gift_card_usage')->whereBetween('created_at', [$filters['start_at'], $filters['end_at']]);
        if ($filters['site_id'] && Schema::hasColumn('v2_gift_card_usage', 'site_id')) $query->where('site_id', $filters['site_id']);
        if ($filters['agent_user_id'] && Schema::hasColumn('v2_gift_card_usage', 'agent_user_id')) $query->where('agent_user_id', $filters['agent_user_id']);
        return $query->count();
    }

    private function siteOptions(): array
    {
        if (!Schema::hasTable('v2_site')) return [];
        return DB::table('v2_site')->orderBy('name')->get(['id', 'name'])
            ->map(fn (object $site): array => ['id' => (int) $site->id, 'name' => (string) $site->name])->all();
    }

    private function agentOptions(): array
    {
        if (!Schema::hasTable('v2_agent_profile')) return [];
        return DB::table('v2_agent_profile as ap')->join('v2_user as u', 'u.id', '=', 'ap.user_id')
            ->orderBy('u.email')->get(['u.id', 'u.email'])
            ->map(fn (object $agent): array => ['id' => (int) $agent->id, 'email' => (string) $agent->email])->all();
    }

    private function capabilities(): array
    {
        return [
            'site' => Schema::hasTable('v2_site_order_context'),
            'agent' => Schema::hasTable('v2_agent_order_context') && Schema::hasTable('v2_agent_balance_hold'),
            'commission' => Schema::hasTable('v2_commission_log'),
            'gift_card' => Schema::hasTable('v2_gift_card_usage'),
        ];
    }

    private function emptyPayload(array $filters): array
    {
        return [
            'generated_at' => time(),
            'range' => ['days' => $filters['days'], 'start_at' => $filters['start_at'], 'end_at' => $filters['end_at']],
            'filters' => $filters, 'summary' => [], 'scope_breakdown' => [], 'issue_breakdown' => [],
            'issues' => ['data' => [], 'total' => 0, 'sampled_count' => 0, 'limited' => false, 'sample_limit' => FinancialReconciliationIssueScanner::SAMPLE_LIMIT],
            'sites' => [], 'agents' => [], 'capabilities' => $this->capabilities(),
        ];
    }
}
