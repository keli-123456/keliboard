<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderUpgradeQuoteDetail;
use App\Http\Requests\Admin\OrderUpgradeQuoteFetch;
use App\Models\Order;
use App\Models\OrderUpgradeQuote;
use App\Services\PlanService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class OrderUpgradeQuoteController extends Controller
{
    public function fetch(OrderUpgradeQuoteFetch $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(1, min(100, (int) $request->input('pageSize', 10)));

        $query = OrderUpgradeQuote::query()->with([
            'user:id,email',
            'sourcePlan:id,name',
            'targetPlan:id,name',
            'sourceOrder:id,trade_no,user_id,plan_id,period,total_amount,status,created_at,paid_at',
            'upgradeOrder:id,upgrade_quote_id,trade_no,user_id,plan_id,period,total_amount,status,created_at,paid_at',
        ]);

        $this->applyFilters($request, $query);
        $sorted = $this->applySorting($request, $query);
        if (!$sorted) {
            $query->latest('created_at');
        }

        /** @var LengthAwarePaginator $paginatedResults */
        $paginatedResults = $query->paginate(
            perPage: $pageSize,
            page: $current
        );

        $items = $paginatedResults->getCollection()
            ->map(fn(OrderUpgradeQuote $quote): array => $this->transformListItem($quote))
            ->all();

        return $this->paginate($paginatedResults, $items);
    }

    public function detail(OrderUpgradeQuoteDetail $request)
    {
        /** @var OrderUpgradeQuote|null $quote */
        $quote = OrderUpgradeQuote::query()
            ->with([
                'user:id,email',
                'sourcePlan:id,name',
                'targetPlan:id,name',
                'sourceOrder:id,trade_no,user_id,plan_id,period,total_amount,status,created_at,paid_at',
                'sourceOrder.plan:id,name',
                'upgradeOrder:id,upgrade_quote_id,trade_no,user_id,plan_id,period,total_amount,status,created_at,paid_at',
                'upgradeOrder.plan:id,name',
            ])
            ->find((int) $request->input('id'));

        if (!$quote) {
            return $this->fail([400202, '升级报价单不存在']);
        }

        return $this->success($this->transformDetail($quote));
    }

    private function applyFilters(OrderUpgradeQuoteFetch $request, Builder $query): void
    {
        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }

        if ($request->filled('token')) {
            $query->where('token', 'like', '%' . trim((string) $request->input('token')) . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('source_order_id')) {
            $query->where('source_order_id', (int) $request->input('source_order_id'));
        }

        if ($request->filled('source_plan_id')) {
            $query->where('source_plan_id', (int) $request->input('source_plan_id'));
        }

        if ($request->filled('target_plan_id')) {
            $query->where('target_plan_id', (int) $request->input('target_plan_id'));
        }

        if ($request->has('has_upgrade_order') && $request->input('has_upgrade_order') !== '') {
            if ($request->boolean('has_upgrade_order')) {
                $query->whereHas('upgradeOrder');
            } else {
                $query->whereDoesntHave('upgradeOrder');
            }
        }

        $createdFrom = $this->normalizeTimestamp($request->input('created_from'));
        if ($createdFrom !== null) {
            $query->where('created_at', '>=', $createdFrom);
        }

        $createdTo = $this->normalizeTimestamp($request->input('created_to'));
        if ($createdTo !== null) {
            $query->where('created_at', '<=', $createdTo);
        }

        $expiresFrom = $this->normalizeTimestamp($request->input('expires_from'));
        if ($expiresFrom !== null) {
            $query->where('expires_at', '>=', $expiresFrom);
        }

        $expiresTo = $this->normalizeTimestamp($request->input('expires_to'));
        if ($expiresTo !== null) {
            $query->where('expires_at', '<=', $expiresTo);
        }
    }

    private function applySorting(OrderUpgradeQuoteFetch $request, Builder $query): bool
    {
        $sorts = $request->input('sort', []);
        if (!is_array($sorts) || $sorts === []) {
            return false;
        }

        $applied = false;
        foreach ($sorts as $sort) {
            if (!is_array($sort)) {
                continue;
            }

            $field = $sort['id'] ?? null;
            if (!is_string($field) || $field === '') {
                continue;
            }

            $direction = !empty($sort['desc']) ? 'DESC' : 'ASC';
            $query->orderBy($field, $direction);
            $applied = true;
        }

        return $applied;
    }

    private function normalizeTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        try {
            return Carbon::parse((string) $value)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    private function transformListItem(OrderUpgradeQuote $quote): array
    {
        $sourcePlanName = $quote->sourcePlan?->name ?? data_get($quote->snapshot, 'source_plan.name');
        $targetPlanName = $quote->targetPlan?->name ?? data_get($quote->snapshot, 'target_plan.name');
        $upgradeOrder = $quote->upgradeOrder;

        return [
            'id' => (int) $quote->id,
            'user_id' => (int) $quote->user_id,
            'token' => (string) $quote->token,
            'status' => (string) $quote->status,
            'source_order_id' => (int) $quote->source_order_id,
            'source_plan_id' => (int) $quote->source_plan_id,
            'target_plan_id' => (int) $quote->target_plan_id,
            'target_period' => (string) $quote->target_period,
            'target_price' => (int) $quote->target_price,
            'upgrade_credit_amount' => (int) $quote->upgrade_credit_amount,
            'final_pay_amount' => (int) $quote->final_pay_amount,
            'expires_at' => (int) $quote->expires_at,
            'created_at' => (int) $quote->created_at,
            'has_upgrade_order' => $upgradeOrder !== null,
            'upgrade_order_id' => $upgradeOrder?->id ? (int) $upgradeOrder->id : null,
            'user' => [
                'id' => (int) $quote->user_id,
                'email' => $quote->user?->email,
            ],
            'source_plan' => [
                'id' => (int) $quote->source_plan_id,
                'name' => $sourcePlanName,
            ],
            'target_plan' => [
                'id' => (int) $quote->target_plan_id,
                'name' => $targetPlanName,
            ],
            'source_order' => $quote->sourceOrder ? [
                'id' => (int) $quote->sourceOrder->id,
                'trade_no' => $quote->sourceOrder->trade_no,
            ] : null,
            'upgrade_order' => $upgradeOrder ? [
                'order_id' => (int) $upgradeOrder->id,
                'trade_no' => $upgradeOrder->trade_no,
                'payable_amount' => (int) $upgradeOrder->total_amount,
                'status' => (int) $upgradeOrder->status,
            ] : null,
        ];
    }

    private function transformDetail(OrderUpgradeQuote $quote): array
    {
        $detail = $this->transformListItem($quote);
        $sourceOrder = $quote->sourceOrder;
        $upgradeOrder = $quote->upgradeOrder;

        $detail['source_paid_basis'] = (int) $quote->source_paid_basis;
        $detail['time_ratio'] = (float) $quote->time_ratio;
        $detail['traffic_ratio'] = (float) $quote->traffic_ratio;
        $detail['base_credit_coeff'] = (float) $quote->base_credit_coeff;
        $detail['usage_penalty_coeff'] = (float) $quote->usage_penalty_coeff;
        $detail['credit_cap_amount'] = (int) $quote->credit_cap_amount;
        $detail['min_pay_amount'] = (int) $quote->min_pay_amount;
        $detail['snapshot'] = $quote->snapshot;
        $detail['updated_at'] = (int) $quote->updated_at;
        $detail['target_period_label'] = PlanService::getLegacyPeriod((string) $quote->target_period);

        $detail['source_order'] = $sourceOrder ? [
            'id' => (int) $sourceOrder->id,
            'trade_no' => $sourceOrder->trade_no,
            'status' => (int) $sourceOrder->status,
            'total_amount' => (int) $sourceOrder->total_amount,
            'period' => (string) $sourceOrder->period,
            'period_label' => PlanService::getLegacyPeriod((string) $sourceOrder->period),
            'plan_id' => (int) $sourceOrder->plan_id,
            'plan_name' => $sourceOrder->plan?->name ?? $quote->sourcePlan?->name ?? data_get($quote->snapshot, 'source_plan.name'),
            'created_at' => (int) $sourceOrder->created_at,
            'paid_at' => $sourceOrder->paid_at ? (int) $sourceOrder->paid_at : null,
        ] : null;

        $detail['upgrade_order'] = $upgradeOrder ? [
            'order_id' => (int) $upgradeOrder->id,
            'trade_no' => $upgradeOrder->trade_no,
            'payable_amount' => (int) $upgradeOrder->total_amount,
            'status' => (int) $upgradeOrder->status,
            'period' => (string) $upgradeOrder->period,
            'period_label' => PlanService::getLegacyPeriod((string) $upgradeOrder->period),
            'plan_id' => (int) $upgradeOrder->plan_id,
            'plan_name' => $upgradeOrder->plan?->name ?? $quote->targetPlan?->name ?? data_get($quote->snapshot, 'target_plan.name'),
            'created_at' => (int) $upgradeOrder->created_at,
            'paid_at' => $upgradeOrder->paid_at ? (int) $upgradeOrder->paid_at : null,
        ] : null;

        return $detail;
    }
}
