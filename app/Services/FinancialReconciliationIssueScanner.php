<?php

namespace App\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FinancialReconciliationIssueScanner
{
    private FinancialReconciliationSchema $schema;

    public const SAMPLE_LIMIT = 240;
    private const PER_RULE_LIMIT = 40;

    private array $issues = [];
    private array $breakdown = [];
    private int $total = 0;
    private int $highCount = 0;

    public function scan(array $filters, ?FinancialReconciliationSchema $schema = null): array
    {
        $this->schema = $schema ?? new FinancialReconciliationSchema();
        $this->currentFilters = $filters;
        $this->issues = [];
        $this->breakdown = [];
        $this->total = 0;
        $this->highCount = 0;

        $this->scanOrders($filters);
        $this->scanAgentFinance($filters);
        $this->scanAgentProfit($filters);
        $this->scanCommission($filters);
        $this->scanGiftCards($filters);

        $weights = ['high' => 3, 'medium' => 2, 'low' => 1];
        usort($this->issues, static function (array $left, array $right) use ($weights): int {
            $severity = ($weights[$right['severity']] ?? 0) <=> ($weights[$left['severity']] ?? 0);
            return $severity !== 0 ? $severity : (($right['occurred_at'] ?? 0) <=> ($left['occurred_at'] ?? 0));
        });
        $limited = count($this->issues) > self::SAMPLE_LIMIT;
        $this->issues = array_slice($this->issues, 0, self::SAMPLE_LIMIT);

        return [
            'data' => $this->issues,
            'total' => $this->total,
            'high_count' => $this->highCount,
            'limited' => $limited || $this->total > count($this->issues),
            'breakdown' => array_values($this->breakdown),
        ];
    }

    private function scanOrders(array $filters): void
    {
        $this->orderRule($filters, 'paid_at_status_conflict', 'high', 'order', function (Builder $query): void {
            $query->where('o.status', Order::STATUS_PENDING)->whereNotNull('o.paid_at')->where('o.paid_at', '>', 0);
        });
        $this->orderRule($filters, 'processing_stuck', 'high', 'order', function (Builder $query): void {
            $query->where('o.status', Order::STATUS_PROCESSING)->where('o.updated_at', '<', time() - 1800);
        });
        $this->orderRule($filters, 'completed_without_paid_at', 'medium', 'order', function (Builder $query): void {
            $query->where('o.status', Order::STATUS_COMPLETED)
                ->where(function (Builder $missing): void {
                    $missing->whereNull('o.paid_at')->orWhere('o.paid_at', 0);
                });
        });
        $this->orderRule($filters, 'order_user_missing', 'high', 'order', fn (Builder $query) => $query->whereNull('u.id'));
        $this->orderRule($filters, 'order_plan_missing', 'high', 'order', function (Builder $query): void {
            $query->where('o.type', '<>', Order::TYPE_RECHARGE)->where('o.plan_id', '>', 0)->whereNull('p.id');
        });

        if ($this->schema->hasTable('v2_payment')) {
            $this->orderRule($filters, 'completed_payment_missing', 'high', 'payment', function (Builder $query): void {
                $query->where('o.status', Order::STATUS_COMPLETED)->whereNotNull('o.payment_id')->whereNull('pay.id');
            });
        }
        if ($this->schema->hasColumn('v2_order', 'refund_disposed_at')) {
            $this->orderRule($filters, 'refund_not_disposed', 'high', 'refund', function (Builder $query): void {
                $query->where('o.refund_amount', '>', 0)->whereNull('o.refund_disposed_at');
            });
            $this->orderRule($filters, 'refund_commission_still_valid', 'high', 'refund', function (Builder $query): void {
                $query->where('o.refund_amount', '>', 0)->where('o.commission_status', Order::COMMISSION_STATUS_VALID);
            });
        }
        if ($this->schema->hasTable('v2_site_order_context')) {
            $this->orderRule($filters, 'site_context_missing', 'medium', 'order', function (Builder $query): void {
                $query->whereNotNull('o.site_id')->whereNull('soc.id');
            });
            $this->orderRule($filters, 'site_context_mismatch', 'high', 'order', function (Builder $query): void {
                $query->whereNotNull('soc.id')->whereColumn('soc.site_id', '<>', 'o.site_id');
            });
        }
        if ($this->schema->hasTable('v2_agent_user') && $this->schema->hasTable('v2_agent_order_context')) {
            $this->orderRule($filters, 'agent_order_context_missing', 'high', 'agent', function (Builder $query): void {
                $query->whereNotNull('au.id')->whereNull('aoc.id');
            });
        }
    }

    private function scanAgentFinance(array $filters): void
    {
        if ($filters['scope'] !== 'all' && $filters['scope'] !== 'agent') return;
        if (!$this->schema->hasTable('v2_agent_order_context') || !$this->schema->hasTable('v2_agent_balance_hold')) return;

        $this->orderRule($filters, 'agent_hold_missing', 'high', 'agent', function (Builder $query): void {
            $this->requiringSelfCollectionHold($query);
            $query->whereNotNull('aoc.id')->whereIn('aoc.status', [
                AgentOrderContext::STATUS_PENDING, AgentOrderContext::STATUS_PAID,
            ])->whereNull('abh.id');
        });
        $this->orderRule($filters, 'agent_hold_amount_mismatch', 'high', 'agent', function (Builder $query): void {
            $query->whereNotNull('abh.id')->whereColumn('abh.amount', '<>', 'aoc.cost_amount');
        });
        $this->orderRule($filters, 'cancelled_order_pending_hold', 'high', 'agent', function (Builder $query): void {
            $query->where('o.status', Order::STATUS_CANCELLED)->where('abh.status', AgentBalanceHold::STATUS_PENDING);
        });
        $this->orderRule($filters, 'paid_agent_order_not_captured', 'high', 'agent', function (Builder $query): void {
            $this->requiringSelfCollectionHold($query);
            $query->where('aoc.status', AgentOrderContext::STATUS_PAID)
                ->where(function (Builder $hold): void {
                    $hold->whereNull('abh.id')->orWhere('abh.status', '<>', AgentBalanceHold::STATUS_CAPTURED);
                });
        });
        $this->orderRule($filters, 'expired_agent_hold_pending', 'medium', 'agent', function (Builder $query): void {
            $query->where('abh.status', AgentBalanceHold::STATUS_PENDING)->where('abh.expires_at', '<', time());
        });

        if ($this->schema->hasTable('v2_agent_ledger')) {
            $query = DB::table('v2_agent_ledger as al')
                ->leftJoin('v2_user as agent', 'agent.id', '=', 'al.agent_user_id')
                ->leftJoin('v2_user as target', 'target.id', '=', 'al.target_user_id')
                ->whereBetween('al.created_at', [$filters['start_at'], $filters['end_at']])
                ->whereRaw('al.balance_after <> al.balance_before + al.amount');
            if ($filters['agent_user_id']) $query->where('al.agent_user_id', $filters['agent_user_id']);
            if ($filters['keyword'] !== '') {
                $keyword = '%' . $filters['keyword'] . '%';
                $query->where(fn (Builder $nested) => $nested->where('agent.email', 'like', $keyword)->orWhere('target.email', 'like', $keyword));
            }
            $query->selectRaw('al.id entity_id, NULL order_id, NULL trade_no, al.target_user_id user_id, target.email user_email')
                ->selectRaw('NULL site_id, NULL site_name, al.agent_user_id, agent.email agent_email')
                ->selectRaw('ABS(al.balance_after - (al.balance_before + al.amount)) amount, al.created_at occurred_at')
                ->selectRaw('al.type status, al.balance_before expected_value, al.balance_after actual_value');
            $this->register('agent_ledger_balance_mismatch', 'high', 'agent', $query, 'agent_ledger');
        }
    }

    private function requiringSelfCollectionHold(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('aoc.pricing_snapshot->collection->mode')
            ->orWhere('aoc.pricing_snapshot->collection->mode', '<>', 'platform'))
            ->where(fn (Builder $q) => $q->where('aoc.cost_amount', '>', 0)->orWhereNotNull('aoc.hold_id'));
    }

    private function scanAgentProfit(array $filters): void
    {
        if (!$this->schema->hasTable('v2_agent_order_context') || !$this->schema->hasTable('v2_agent_profit')) return;
        $this->orderRule($filters, 'agent_profit_missing', 'high', 'agent', function (Builder $query): void {
            $query->where('aoc.pricing_snapshot->collection->mode', 'platform')->where('aoc.status', 'paid')
                ->where('o.status', Order::STATUS_COMPLETED)->where('o.paid_at', '>', 0)->where('o.plan_id', '>', 0)
                ->whereNull('o.refund_disposed_at')->where(fn (Builder $q) => $q->whereNull('o.refund_amount')->orWhere('o.refund_amount', 0))
                ->whereNull('apf.id');
        });
        $this->orderRule($filters, 'agent_profit_amount_mismatch', 'high', 'agent', function (Builder $query): void {
            $query->whereNotNull('apf.id')->where(function (Builder $q): void {
                $q->whereRaw('apf.amount + apf.cost_amount + apf.fee_amount <> apf.sale_amount')
                    ->orWhereColumn('apf.sale_amount', '<>', 'aoc.sale_amount')->orWhereColumn('apf.cost_amount', '<>', 'aoc.cost_amount')
                    ->orWhere('apf.amount', '<', 0)->orWhere('apf.fee_amount', '<', 0)->orWhere('apf.cost_amount', '<', 0);
            });
        });
        $this->orderRule($filters, 'agent_profit_source_invalid', 'high', 'agent', function (Builder $query): void {
            $query->whereIn('apf.status', ['pending', 'available'])->where(function (Builder $q): void {
                $q->whereNull('aoc.id')->orWhereNull('aoc.pricing_snapshot->collection->mode')
                    ->orWhere('aoc.pricing_snapshot->collection->mode', '<>', 'platform')
                    ->orWhere('o.plan_id', 0)->orWhere('o.type', Order::TYPE_RECHARGE)
                    ->orWhereNotIn('o.status', [Order::STATUS_COMPLETED, Order::STATUS_DISCOUNTED])
                    ->orWhereNull('o.paid_at')->orWhere('o.paid_at', 0)->orWhereNotNull('o.refund_disposed_at')
                    ->orWhere('o.refund_amount', '>', 0)->orWhere('aoc.status', '<>', 'paid')
                    ->orWhereColumn('apf.agent_user_id', '<>', 'aoc.agent_user_id')->orWhereColumn('apf.trade_no', '<>', 'o.trade_no')
                    ->orWhereRaw('o.total_amount + COALESCE(o.balance_amount, 0) <> aoc.sale_amount');
            });
        });
    }

    private function scanCommission(array $filters): void
    {
        $this->orderRule($filters, 'commission_budget_exceeded', 'high', 'commission', function (Builder $query): void {
            $query->where(fn (Builder $q) => $q->where('o.commission_balance', '<', 0)
                ->orWhereRaw('COALESCE(o.actual_commission_balance, 0) > COALESCE(o.commission_balance, 0)')
                ->orWhereRaw('COALESCE(o.commission_balance, 0) > o.total_amount + COALESCE(o.balance_amount, 0)'));
        });
        if ($this->schema->hasTable('v2_agent_order_context') || $this->schema->hasTable('v2_agent_user')) {
            $this->orderRule($filters, 'agent_regular_commission_conflict', 'high', 'commission', function (Builder $query): void {
                $query->where(function (Builder $q): void {
                    $q->where('o.commission_balance', '>', 0)->orWhere('o.actual_commission_balance', '>', 0);
                    if ($this->schema->hasTable('v2_commission_log')) {
                        $q->orWhereExists(fn (Builder $logs) => $logs->selectRaw('1')->from('v2_commission_log as acl')
                            ->whereColumn('acl.trade_no', 'o.trade_no')->where('acl.get_amount', '>', 0));
                    }
                })->where(function (Builder $q): void {
                    $q->whereRaw('1 = 0');
                    if ($this->schema->hasTable('v2_agent_order_context')) $q->orWhereNotNull('aoc.id');
                    if ($this->schema->hasTable('v2_agent_user')) {
                        $q->orWhereNotNull('au.id')->orWhereIn('o.invite_user_id', DB::table('v2_agent_user')->select('sub_user_id'));
                        if ($this->schema->hasTable('v2_commission_log')) {
                            $q->orWhereExists(fn (Builder $logs) => $logs->selectRaw('1')->from('v2_commission_log as acl')
                                ->join('v2_agent_user as recipient_agent', 'recipient_agent.sub_user_id', '=', 'acl.invite_user_id')
                                ->whereColumn('acl.trade_no', 'o.trade_no')->where('acl.get_amount', '>', 0));
                        }
                    }
                });
            });
        }
        if (!$this->schema->hasTable('v2_commission_log')) return;
        $query = $this->orderBase($filters)
            ->join('v2_commission_log as cl', 'cl.trade_no', '=', 'o.trade_no')
            ->groupBy('o.id', 'o.trade_no', 'o.user_id', 'u.email', 'o.site_id', 'site.name', 'o.actual_commission_balance', 'o.commission_reversed_amount', 'o.updated_at');
        if ($this->schema->hasTable('v2_agent_order_context')) {
            $query->groupBy('aoc.agent_user_id', 'agent.email');
        }

        $mismatch = clone $query;
        $mismatch->havingRaw('SUM(cl.get_amount) <> COALESCE(o.actual_commission_balance, 0)')
            ->selectRaw('o.id entity_id, o.id order_id, o.trade_no, o.user_id, u.email user_email')
            ->selectRaw('o.site_id, site.name site_name, ' . $this->agentSelect())
            ->selectRaw('ABS(SUM(cl.get_amount) - COALESCE(o.actual_commission_balance, 0)) amount, o.updated_at occurred_at')
            ->selectRaw("'commission' status, COALESCE(o.actual_commission_balance, 0) expected_value, SUM(cl.get_amount) actual_value");
        $this->register('commission_amount_mismatch', 'high', 'commission', $mismatch, 'order');

        if ($this->schema->hasColumn('v2_commission_log', 'reversed_at')) {
            $reversal = clone $query;
            $reversal->where('o.refund_amount', '>', 0)
                ->havingRaw('SUM(CASE WHEN cl.reversed_at IS NOT NULL THEN cl.get_amount ELSE 0 END) <> COALESCE(o.commission_reversed_amount, 0)')
                ->selectRaw('o.id entity_id, o.id order_id, o.trade_no, o.user_id, u.email user_email')
                ->selectRaw('o.site_id, site.name site_name, ' . $this->agentSelect())
                ->selectRaw('ABS(SUM(CASE WHEN cl.reversed_at IS NOT NULL THEN cl.get_amount ELSE 0 END) - COALESCE(o.commission_reversed_amount, 0)) amount, o.updated_at occurred_at')
                ->selectRaw("'reversal' status, COALESCE(o.commission_reversed_amount, 0) expected_value, SUM(CASE WHEN cl.reversed_at IS NOT NULL THEN cl.get_amount ELSE 0 END) actual_value");
            $this->register('commission_reversal_mismatch', 'high', 'commission', $reversal, 'order');
        }
    }

    private function scanGiftCards(array $filters): void
    {
        if (!$this->schema->hasTable('v2_gift_card_code') || !$this->schema->hasTable('v2_gift_card_usage')) return;
        $usage = DB::table('v2_gift_card_usage')->groupBy('code_id')->select('code_id')->selectRaw('COUNT(*) actual_usage_count');
        $query = DB::table('v2_gift_card_code as gc')->leftJoinSub($usage, 'usage', 'usage.code_id', '=', 'gc.id')
            ->whereRaw('gc.usage_count <> COALESCE(usage.actual_usage_count, 0)')
            ->selectRaw('gc.id entity_id, NULL order_id, gc.code trade_no, gc.user_id, NULL user_email')
            ->selectRaw($this->giftScopeSelect())
            ->selectRaw('ABS(gc.usage_count - COALESCE(usage.actual_usage_count, 0)) amount, gc.updated_at occurred_at')
            ->selectRaw("'usage_count' status, gc.usage_count expected_value, COALESCE(usage.actual_usage_count, 0) actual_value");
        $this->applyGiftScope($query, $filters, 'gc');
        $this->register('gift_card_usage_count_mismatch', 'medium', 'gift_card', $query, 'gift_card_code');

        $orphan = DB::table('v2_gift_card_usage as gu')
            ->leftJoin('v2_gift_card_code as gc', 'gc.id', '=', 'gu.code_id')
            ->leftJoin('v2_gift_card_template as gt', 'gt.id', '=', 'gu.template_id')
            ->leftJoin('v2_user as u', 'u.id', '=', 'gu.user_id')
            ->whereBetween('gu.created_at', [$filters['start_at'], $filters['end_at']])
            ->where(fn (Builder $missing) => $missing->whereNull('gc.id')->orWhereNull('gt.id')->orWhereNull('u.id'))
            ->selectRaw('gu.id entity_id, NULL order_id, NULL trade_no, gu.user_id, u.email user_email')
            ->selectRaw($this->giftScopeSelect('gu'))
            ->selectRaw('0 amount, gu.created_at occurred_at, NULL status, 1 expected_value, 0 actual_value');
        $this->applyGiftScope($orphan, $filters, 'gu');
        $this->register('gift_card_usage_orphaned', 'high', 'gift_card', $orphan, 'gift_card_usage');
    }

    private function orderRule(array $filters, string $code, string $severity, string $category, callable $condition): void
    {
        if (!$this->ruleEnabled($filters, $severity, $category)) return;
        $query = $this->orderBase($filters);
        $condition($query);
        $query->selectRaw('o.id entity_id, o.id order_id, o.trade_no, o.user_id, u.email user_email')
            ->selectRaw('o.site_id, site.name site_name, ' . $this->agentSelect())
            ->selectRaw('(o.total_amount + COALESCE(o.handling_amount, 0)) amount, o.updated_at occurred_at')
            ->selectRaw('CAST(o.status AS CHAR) status, NULL expected_value, NULL actual_value');
        $this->register($code, $severity, $category, $query, 'order');
    }

    private function orderBase(array $filters): Builder
    {
        $query = DB::table('v2_order as o')
            ->leftJoin('v2_user as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('v2_plan as p', 'p.id', '=', 'o.plan_id')
            ->leftJoin('v2_site as site', 'site.id', '=', 'o.site_id')
            ->whereBetween('o.created_at', [$filters['start_at'], $filters['end_at']]);
        if ($this->schema->hasTable('v2_payment')) $query->leftJoin('v2_payment as pay', 'pay.id', '=', 'o.payment_id');
        if ($this->schema->hasTable('v2_site_order_context')) $query->leftJoin('v2_site_order_context as soc', 'soc.order_id', '=', 'o.id');
        if ($this->schema->hasTable('v2_agent_order_context')) {
            $query->leftJoin('v2_agent_order_context as aoc', 'aoc.order_id', '=', 'o.id')
                ->leftJoin('v2_user as agent', 'agent.id', '=', 'aoc.agent_user_id');
        }
        if ($this->schema->hasTable('v2_agent_order_context') && $this->schema->hasTable('v2_agent_balance_hold')) {
            $query->leftJoin('v2_agent_balance_hold as abh', 'abh.id', '=', 'aoc.hold_id');
        }
        if ($this->schema->hasTable('v2_agent_user')) $query->leftJoin('v2_agent_user as au', 'au.sub_user_id', '=', 'o.user_id');
        if ($this->schema->hasTable('v2_agent_order_context') && $this->schema->hasTable('v2_agent_profit')) {
            $query->leftJoin('v2_agent_profit as apf', 'apf.order_id', '=', 'o.id');
        }
        $this->applyOrderFilters($query, $filters);
        return $query;
    }

    private function applyOrderFilters(Builder $query, array $filters): void
    {
        $hasAgent = $this->schema->hasTable('v2_agent_order_context');
        if ($filters['scope'] === 'platform') {
            if ($hasAgent) $query->whereNull('aoc.id');
            $query->whereNull('o.site_id');
        } elseif ($filters['scope'] === 'site') {
            if ($hasAgent) $query->whereNull('aoc.id');
            $query->whereNotNull('o.site_id');
        } elseif ($filters['scope'] === 'agent') {
            $hasAgent ? $query->whereNotNull('aoc.id') : $query->whereRaw('1 = 0');
        }
        if ($filters['site_id']) $query->where('o.site_id', $filters['site_id']);
        if ($filters['agent_user_id']) $hasAgent ? $query->where('aoc.agent_user_id', $filters['agent_user_id']) : $query->whereRaw('1 = 0');
        if ($filters['keyword'] !== '') {
            $keyword = '%' . $filters['keyword'] . '%';
            $query->where(fn (Builder $nested) => $nested->where('o.trade_no', 'like', $keyword)->orWhere('u.email', 'like', $keyword));
        }
    }

    private function register(string $code, string $severity, string $category, Builder $query, string $entityType): void
    {
        if (!$this->ruleEnabledByCurrentFilters($severity, $category)) return;
        $count = DB::query()->fromSub(clone $query, 'reconciliation_issue_rows')->count();
        if ($count === 0) return;

        $this->total += $count;
        if ($severity === 'high') $this->highCount += $count;
        $key = $category . ':' . $severity;
        if (!isset($this->breakdown[$key])) {
            $this->breakdown[$key] = ['category' => $category, 'severity' => $severity, 'count' => 0];
        }
        $this->breakdown[$key]['count'] += $count;

        foreach ((clone $query)->limit(self::PER_RULE_LIMIT)->get() as $row) {
            $this->issues[] = [
                'id' => $code . ':' . $entityType . ':' . (int) $row->entity_id,
                'code' => $code,
                'severity' => $severity,
                'category' => $category,
                'entity_type' => $entityType,
                'entity_id' => (int) $row->entity_id,
                'order_id' => $row->order_id !== null ? (int) $row->order_id : null,
                'trade_no' => $row->trade_no !== null ? (string) $row->trade_no : null,
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_email' => $row->user_email !== null ? (string) $row->user_email : null,
                'site_id' => $row->site_id !== null ? (int) $row->site_id : null,
                'site_name' => $row->site_name !== null ? (string) $row->site_name : null,
                'agent_user_id' => $row->agent_user_id !== null ? (int) $row->agent_user_id : null,
                'agent_email' => $row->agent_email !== null ? (string) $row->agent_email : null,
                'amount' => (int) ($row->amount ?? 0),
                'occurred_at' => (int) ($row->occurred_at ?? 0),
                'context' => [
                    'status' => $row->status ?? null,
                    'expected' => $row->expected_value ?? null,
                    'actual' => $row->actual_value ?? null,
                ],
            ];
        }
    }

    private array $currentFilters = [];

    private function ruleEnabled(array $filters, string $severity, string $category): bool
    {
        $this->currentFilters = $filters;
        return $this->ruleEnabledByCurrentFilters($severity, $category);
    }

    private function ruleEnabledByCurrentFilters(string $severity, string $category): bool
    {
        if ($this->currentFilters === []) return true;
        return ($this->currentFilters['severity'] === 'all' || $this->currentFilters['severity'] === $severity)
            && ($this->currentFilters['category'] === 'all' || $this->currentFilters['category'] === $category);
    }

    private function agentSelect(): string
    {
        return $this->schema->hasTable('v2_agent_order_context')
            ? 'aoc.agent_user_id, agent.email agent_email'
            : 'NULL agent_user_id, NULL agent_email';
    }

    private function giftScopeSelect(string $alias = 'gc'): string
    {
        $site = $this->schema->hasColumn($alias === 'gc' ? 'v2_gift_card_code' : 'v2_gift_card_usage', 'site_id') ? "{$alias}.site_id" : 'NULL';
        $agent = $this->schema->hasColumn($alias === 'gc' ? 'v2_gift_card_code' : 'v2_gift_card_usage', 'agent_user_id') ? "{$alias}.agent_user_id" : 'NULL';
        return "{$site} site_id, NULL site_name, {$agent} agent_user_id, NULL agent_email";
    }

    private function applyGiftScope(Builder $query, array $filters, string $alias): void
    {
        $table = $alias === 'gc' ? 'v2_gift_card_code' : 'v2_gift_card_usage';
        if ($filters['scope'] === 'platform' && $this->schema->hasColumn($table, 'site_id')) $query->whereNull("{$alias}.site_id");
        if ($filters['scope'] === 'site' && $this->schema->hasColumn($table, 'site_id')) $query->whereNotNull("{$alias}.site_id");
        if ($filters['scope'] === 'agent' && $this->schema->hasColumn($table, 'agent_user_id')) $query->whereNotNull("{$alias}.agent_user_id");
        if ($filters['site_id'] && $this->schema->hasColumn($table, 'site_id')) $query->where("{$alias}.site_id", $filters['site_id']);
        if ($filters['agent_user_id'] && $this->schema->hasColumn($table, 'agent_user_id')) $query->where("{$alias}.agent_user_id", $filters['agent_user_id']);
    }
}
