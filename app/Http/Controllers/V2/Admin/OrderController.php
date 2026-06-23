<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCommerceService;
use App\Services\AgentOrderStatusResolver;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    private const ORDER_FILTER_FIELDS = [
        'id' => 'id',
        'site_id' => 'site_id',
        'trade_no' => 'trade_no',
        'callback_no' => 'callback_no',
        'user_id' => 'user_id',
        'plan_id' => 'plan_id',
        'payment_id' => 'payment_id',
        'invite_user_id' => 'invite_user_id',
        'period' => 'period',
        'type' => 'type',
        'status' => 'status',
        'commission_status' => 'commission_status',
        'commission_balance' => 'commission_balance',
        'actual_commission_balance' => 'actual_commission_balance',
        'total_amount' => 'total_amount',
        'balance_amount' => 'balance_amount',
        'discount_amount' => 'discount_amount',
        'bonus_amount' => 'bonus_amount',
        'refund_amount' => 'refund_amount',
        'surplus_amount' => 'surplus_amount',
        'upgrade_quote_id' => 'upgrade_quote_id',
        'upgrade_credit_amount' => 'upgrade_credit_amount',
        'coupon_id' => 'coupon_id',
        'paid_at' => 'paid_at',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private const ORDER_SORT_FIELDS = [
        'id' => 'id',
        'site_id' => 'site_id',
        'trade_no' => 'trade_no',
        'user_id' => 'user_id',
        'plan_id' => 'plan_id',
        'invite_user_id' => 'invite_user_id',
        'type' => 'type',
        'status' => 'status',
        'commission_status' => 'commission_status',
        'commission_balance' => 'commission_balance',
        'actual_commission_balance' => 'actual_commission_balance',
        'total_amount' => 'total_amount',
        'paid_at' => 'paid_at',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function detail(Request $request)
    {
        $order = Order::with($this->detailRelations())->find($request->input('id'));
        if (!$order)
            return $this->fail([400202, '订单不存在']);
        return $this->success($this->orderPayload($order));
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);
        $orderModel = Order::with($this->fetchRelations());

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSorts($request, $orderModel);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
        $paginatedResults = $orderModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        $paginatedResults->getCollection()->transform(function ($order) {
            return $this->orderPayload($order);
        });

        return $this->paginate($paginatedResults);
    }

    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        $filters = $request->input('filter');
        if (!is_array($filters)) {
            return;
        }

        collect($filters)->each(function ($filter) use ($builder) {
            if (!is_array($filter) || !array_key_exists('id', $filter)) {
                return;
            }

            $value = $filter['value'] ?? null;
            if ($this->applySpecialFilter($builder, trim((string) $filter['id']), $value)) {
                return;
            }

            $field = $this->resolveOrderFilterField(trim((string) $filter['id']));
            if ($field === null) {
                return;
            }

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    private function applySpecialFilter(Builder $builder, string $field, mixed $value): bool
    {
        if ($field === 'tenant_source') {
            if (is_scalar($value)) {
                $this->applyTenantSourceFilter($builder, trim((string) $value));
            }
            return true;
        }

        if ($field === 'agent_order_issue') {
            if (is_scalar($value)) {
                $this->applyAgentOrderIssueFilter($builder, trim((string) $value));
            }
            return true;
        }

        return false;
    }

    private function applyTenantSourceFilter(Builder $builder, string $source): void
    {
        $hasAgentContext = $this->hasTable('v2_agent_order_context');
        $hasSiteContext = $this->hasTable('v2_site_order_context');

        if ($source === 'agent') {
            if (!$hasAgentContext) {
                $builder->whereRaw('1 = 0');
                return;
            }

            $builder->whereHas('agentOrderContext');
            return;
        }

        if ($source === 'site') {
            if ($hasAgentContext) {
                $builder->whereDoesntHave('agentOrderContext');
            }

            $builder->where(function (Builder $query) use ($hasSiteContext): void {
                $query->whereNotNull('site_id');
                if ($hasSiteContext) {
                    $query->orWhereHas('siteOrderContext');
                }
            });
            return;
        }

        if ($source === 'platform') {
            if ($hasAgentContext) {
                $builder->whereDoesntHave('agentOrderContext');
            }
            if ($hasSiteContext) {
                $builder->whereDoesntHave('siteOrderContext');
            }
            $builder->whereNull('site_id');
        }
    }

    private function applyAgentOrderIssueFilter(Builder $builder, string $issue): void
    {
        if (!$this->hasTable('v2_agent_order_context')) {
            $builder->whereRaw('1 = 0');
            return;
        }
        $hasAgentHold = $this->hasTable('v2_agent_balance_hold');

        if ($issue === 'failed') {
            $builder->whereHas('agentOrderContext', function (Builder $query) use ($hasAgentHold): void {
                $query->where('status', AgentOrderContext::STATUS_FAILED);
                if ($hasAgentHold) {
                    $query->orWhereHas('hold', function (Builder $holdQuery): void {
                        $holdQuery->where('status', AgentBalanceHold::STATUS_FAILED);
                    });
                }
            });
            return;
        }

        if ($issue === 'pending_hold') {
            if (!$hasAgentHold) {
                $builder->whereRaw('1 = 0');
                return;
            }
            $builder->whereHas('agentOrderContext.hold', function (Builder $query): void {
                $query->where('status', AgentBalanceHold::STATUS_PENDING);
            });
            return;
        }

        if ($issue === 'cancelled_with_hold') {
            if (!$hasAgentHold) {
                $builder->whereRaw('1 = 0');
                return;
            }
            $builder->where('status', Order::STATUS_CANCELLED)
                ->whereHas('agentOrderContext.hold', function (Builder $query): void {
                    $query->where('status', AgentBalanceHold::STATUS_PENDING);
                });
            return;
        }

        if ($issue === 'paid_without_completed') {
            $builder->where('status', '!=', Order::STATUS_COMPLETED)
                ->whereHas('agentOrderContext', function (Builder $query): void {
                    $query->where('status', AgentOrderContext::STATUS_PAID);
                });
        }
    }

    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        if ($field === 'site_id') {
            if (is_array($value)) {
                $siteIds = array_values(array_filter(
                    array_map(fn ($item) => is_scalar($item) && trim((string) $item) !== '' ? (int) $item : null, $value),
                    fn ($item) => $item !== null && $item > 0
                ));
                if ($siteIds !== []) {
                    $query->whereIn($field, array_values(array_unique($siteIds)));
                }
                return;
            }

            if (is_scalar($value) && trim((string) $value) !== '') {
                $query->where($field, (int) $value);
            }
            return;
        }

        // Handle array values for 'in' operations
        if (is_array($value)) {
            $query->whereIn($field, $value);
            return;
        }

        // Handle operator-based filtering
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // Convert numeric strings to appropriate type
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // Apply operator
        $operator = strtolower($operator);
        if ($operator === 'null') {
            $query->whereNull($field);
            return;
        }
        if ($operator === 'notnull') {
            $query->whereNotNull($field);
            return;
        }

        $query->where($field, match ($operator) {
            'eq' => '=',
            'gt' => '>',
            'gte' => '>=',
            'lt' => '<',
            'lte' => '<=',
            'like' => 'like',
            'notlike' => 'not like',
            default => 'like'
        }, match ($operator) {
            'like', 'notlike' => "%{$filterValue}%",
            default => $filterValue
        });
    }

    private function applySorting(Request $request, Builder $builder): void
    {
        $sorts = $request->input('sort');
        if (!is_array($sorts)) {
            return;
        }

        collect($sorts)->each(function ($sort) use ($builder) {
            if (!is_array($sort) || !array_key_exists('id', $sort)) {
                return;
            }

            $field = $this->resolveOrderSortField(trim((string) $sort['id']));
            if ($field === null) {
                return;
            }

            $direction = !empty($sort['desc']) ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    private function resolveOrderFilterField(string $field): ?string
    {
        return self::ORDER_FILTER_FIELDS[$field] ?? null;
    }

    private function resolveOrderSortField(string $field): ?string
    {
        return self::ORDER_SORT_FIELDS[$field] ?? null;
    }

    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->fail([500, '更新失败']);
        }
        return $this->success(true);
    }

    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }
        if ($order->status !== 0)
            return $this->fail([400, '只能对待支付的订单进行操作']);

        $orderService = new OrderService($order);
        if (!$orderService->cancel()) {
            return $this->fail([400, '更新失败']);
        }
        return $this->success(true);
    }

    public function releaseAgentHold(Request $request)
    {
        $tradeNo = trim((string) $request->input('trade_no', ''));
        if ($tradeNo === '') {
            return $this->fail([400, '订单号不能为空']);
        }

        $order = Order::with($this->detailRelations())->where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        $context = $order->agentOrderContext;
        if (!$context) {
            return $this->fail([400, '该订单不是代理订单']);
        }

        if ((int) $order->status !== Order::STATUS_CANCELLED) {
            return $this->fail([400, '只能释放已取消订单的代理余额预占']);
        }

        $hold = $context->hold;
        if (!$hold || $hold->status !== AgentBalanceHold::STATUS_PENDING) {
            return $this->fail([400, '当前订单没有可释放的代理余额预占']);
        }

        app(AgentCommerceService::class)->releaseForOrder($order);

        $freshOrder = Order::with($this->detailRelations())->find($order->id);
        return $this->success($this->orderPayload($freshOrder ?: $order->fresh($this->detailRelations())));
    }

    public function update(OrderUpdate $request)
    {
        $params = $request->only([
            'commission_status'
        ]);

        $commissionStatus = $params['commission_status'] ?? null;

        $order = Order::where('trade_no', $request->input('trade_no'))
            ->first();
        if (!$order) {
            return $this->fail([400202, '订单不存在']);
        }

        if ($commissionStatus !== null) {
            if ($order->commission_status === null || (int) $order->commission_status !== 0) {
                return $this->fail([400, '只能对待确认的佣金进行操作']);
            }
            if (!in_array((int) $commissionStatus, [1, 3], true)) {
                return $this->fail([400, '佣金状态格式不正确']);
            }
        }

        try {
            $order->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '更新失败']);
        }

        return $this->success(true);
    }

    public function assign(OrderAssign $request)
    {
        $plan = Plan::find($request->input('plan_id'));
        $user = User::where('email', $request->input('email'))->first();

        if (!$user) {
            return $this->fail([400202, '该用户不存在']);
        }

        if (!$plan) {
            return $this->fail([400202, '该订阅不存在']);
        }

        $userService = new UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->fail([400, '该用户还有待支付的订单，无法分配']);
        }

        try {
            DB::beginTransaction();
            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $period = $request->input('period');
            $order->period = PlanService::getPeriodKey((string) $period);
            $order->trade_no = Helper::guid();
            $order->total_amount = $request->input('total_amount');

            if (PlanService::getPeriodKey((string) $order->period) === Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } else if ($user->plan_id !== NULL && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } else if ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                DB::rollBack();
                return $this->fail([500, '订单创建失败']);
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $this->success($order->trade_no);
    }

    /**
     * @return array<int, string>
     */
    private function fetchRelations(): array
    {
        return array_merge(['plan:id,name'], $this->tenantContextRelations());
    }

    /**
     * @return array<int, string>
     */
    private function detailRelations(): array
    {
        return array_merge(['user', 'plan', 'commission_log', 'invite_user'], $this->tenantContextRelations());
    }

    /**
     * @return array<int, string>
     */
    private function tenantContextRelations(): array
    {
        $relations = [];
        if ($this->hasTable('v2_site_order_context')) {
            $relations[] = 'siteOrderContext.site:id,name,code';
            $relations[] = 'siteOrderContext.domain:id,domain';
        }
        if ($this->hasTable('v2_agent_order_context')) {
            $relations[] = 'agentOrderContext.agent:id,email';
            $relations[] = 'agentOrderContext.domain:id,domain';
            $relations[] = 'agentOrderContext.hold:id,status,amount,expires_at';
            $relations[] = 'agentOrderContext.payment:id,name,payment,enable';
        }

        return $relations;
    }

    private function orderPayload(Order $order): array
    {
        $orderArray = $order->toArray();
        $orderArray['period'] = PlanService::getLegacyPeriod((string) $order->period);
        $orderArray['tenant_context'] = $this->tenantContextPayload($order);
        unset($orderArray['site_order_context'], $orderArray['agent_order_context']);

        return $orderArray;
    }

    private function tenantContextPayload(Order $order): array
    {
        $agentContext = $order->relationLoaded('agentOrderContext') ? $order->agentOrderContext : null;
        if ($agentContext) {
            $diagnostics = app(AgentOrderStatusResolver::class)->resolve($agentContext);
            $canReleaseHold = in_array('cancelled_with_pending_hold', $diagnostics['abnormal_flags'], true)
                && (string) ($agentContext->hold?->status ?? '') === AgentBalanceHold::STATUS_PENDING;

            return [
                'source' => 'agent',
                'agent_user_id' => (int) $agentContext->agent_user_id,
                'agent_email' => (string) ($agentContext->agent?->email ?? ''),
                'agent_domain_id' => $agentContext->agent_domain_id !== null ? (int) $agentContext->agent_domain_id : null,
                'agent_domain' => (string) ($agentContext->domain?->domain ?? $this->snapshotStringValue($agentContext->domain_snapshot, 'domain')),
                'source_detail' => $this->snapshotStringValue($agentContext->domain_snapshot, 'source'),
                'payment_id' => $agentContext->payment_id !== null ? (int) $agentContext->payment_id : null,
                'payment_name' => (string) ($agentContext->payment?->name ?? ''),
                'payment_code' => (string) ($agentContext->payment?->payment ?? ''),
                'sale_amount' => (int) $agentContext->sale_amount,
                'cost_amount' => (int) $agentContext->cost_amount,
                'hold_id' => $agentContext->hold_id !== null ? (int) $agentContext->hold_id : null,
                'hold_status' => (string) ($agentContext->hold?->status ?? ''),
                'status' => (string) $agentContext->status,
                'failure_reason' => $this->snapshotStringValue($agentContext->payment_snapshot, 'failure_reason'),
                'capture_status' => (string) $diagnostics['capture_status'],
                'margin_amount' => (int) $diagnostics['margin_amount'],
                'abnormal_flags' => $diagnostics['abnormal_flags'],
                'can_release_hold' => $canReleaseHold,
                'recommended_action' => $canReleaseHold ? 'release_agent_hold' : '',
            ];
        }

        $siteContext = $order->relationLoaded('siteOrderContext') ? $order->siteOrderContext : null;
        if ($siteContext) {
            return [
                'source' => 'site',
                'site_id' => (int) $siteContext->site_id,
                'site_name' => (string) ($siteContext->site?->name ?? ''),
                'site_code' => (string) ($siteContext->site?->code ?? ''),
                'site_domain_id' => $siteContext->site_domain_id !== null ? (int) $siteContext->site_domain_id : null,
                'domain' => (string) ($siteContext->domain?->domain ?? $this->snapshotStringValue($siteContext->domain_snapshot, 'domain')),
                'source_detail' => $this->snapshotStringValue($siteContext->domain_snapshot, 'source'),
                'sale_amount' => (int) $siteContext->sale_amount,
                'platform_plan_price' => (int) $siteContext->platform_plan_price,
            ];
        }

        return [
            'source' => $order->site_id ? 'site' : 'platform',
            'site_id' => $order->site_id ? (int) $order->site_id : null,
        ];
    }

    private function snapshotStringValue($snapshot, string $key): string
    {
        $value = data_get($snapshot, $key, '');
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
