<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentLedger;
use App\Models\AgentOrderContext;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgentCommerceService
{
    public const INSUFFICIENT_SITE_BALANCE_MESSAGE = 'The site balance is insufficient. Please contact site support.';
    public const LEDGER_AGENT_ORDER_COST = 'agent_order_cost';

    public function availableBalance(User $agent): int
    {
        $pending = $this->activePendingHoldTotal((int) $agent->id);

        return max(0, (int) $agent->balance - (int) $pending);
    }

    public function activePendingHoldTotal(int $agentUserId): int
    {
        if (!$this->hasTable('v2_agent_balance_hold')) {
            return 0;
        }

        $query = AgentBalanceHold::query()
            ->where('agent_user_id', $agentUserId)
            ->where('status', AgentBalanceHold::STATUS_PENDING);

        if ($this->hasTable('v2_order')) {
            $query->whereHas('order', function ($orderQuery): void {
                $orderQuery->whereIn('status', [
                    Order::STATUS_PENDING,
                    Order::STATUS_PROCESSING,
                ]);
            });
        }

        return (int) $query->sum('amount');
    }

    public function calculatePlatformCost(User $agent, Plan $plan, string $period): array
    {
        $this->activeProfile($agent);

        return app(AgentCostService::class)->resolveDiscounted($agent, $plan, $period);
    }

    public function createOrderFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode,
        Request $request
    ): ?Order {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $user);
        if (!$context) {
            return null;
        }
        if ($couponCode !== null && trim($couponCode) !== '') {
            throw new ApiException('Coupon is not available for agent storefront orders');
        }

        $agent = User::query()->find((int) $context['agent_user_id']);
        if (!$agent) {
            throw new ApiException('Agent user does not exist');
        }

        return $this->createOrderForContext($user, $plan, $period, $context, false, $couponCode);
    }

    public function createAutoRenewOrder(User $user, Plan $plan, string $period): ?Order
    {
        $context = app(AgentCommerceContextResolver::class)->resolveUser($user);
        if (!$context) {
            return null;
        }

        return $this->createOrderForContext($user, $plan, $period, $context, true);
    }

    public function createRechargeOrderFromRequest(
        User $user,
        int $amount,
        int $bonusAmount,
        Request $request
    ): ?Order {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $user);
        if (!$context) {
            return null;
        }

        $agent = User::query()->find((int) $context['agent_user_id']);
        if (!$agent) {
            throw new ApiException('Agent user does not exist');
        }
        $this->activeProfile($agent);

        return DB::transaction(function () use ($user, $amount, $bonusAmount, $context): Order {
            $lockedAgent = User::query()
                ->whereKey((int) $context['agent_user_id'])
                ->lockForUpdate()
                ->first();
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }
            $this->activeProfile($lockedAgent);

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedUser) {
                throw new ApiException(__('User does not exist'));
            }

            OrderService::assertNoIncompleteOrder($lockedUser->id);

            $now = time();
            $ownership = AgentUser::query()
                ->where('sub_user_id', $lockedUser->id)
                ->first();
            if (!$ownership) {
                AgentUser::query()->create([
                    'agent_user_id' => $lockedAgent->id,
                    'sub_user_id' => $lockedUser->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $lockedUser->invite_user_id = $lockedAgent->id;
                $lockedUser->updated_at = $now;
                $lockedUser->save();
            } elseif ((int) $lockedUser->invite_user_id !== (int) $ownership->agent_user_id) {
                $lockedUser->invite_user_id = (int) $ownership->agent_user_id;
                $lockedUser->updated_at = $now;
                $lockedUser->save();
            }

            $order = OrderService::createRechargeOrder($lockedUser, $amount, $bonusAmount);
            $order->invite_user_id = $lockedUser->invite_user_id;
            $order->updated_at = $now;
            $order->save();
            $contextSource = (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN);
            $agentDomainId = $context['agent_domain_id'] ?? null;
            $domainSnapshot = [
                'source' => $contextSource,
                'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
                'domain' => (string) ($context['domain'] ?? ''),
                'is_primary' => (bool) ($context['is_primary'] ?? false),
            ];
            $pricingSnapshot = [
                'type' => 'recharge',
                'period' => 'recharge',
                'sale_amount' => max(0, $amount),
                'bonus_amount' => max(0, $bonusAmount),
                'cost_amount' => 0,
            ];

            AgentOrderContext::query()->create([
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'agent_user_id' => $lockedAgent->id,
                'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
                'payment_id' => null,
                'sale_amount' => max(0, $amount),
                'cost_amount' => 0,
                'hold_id' => null,
                'status' => AgentOrderContext::STATUS_PENDING,
                'pricing_snapshot' => $pricingSnapshot,
                'domain_snapshot' => $domainSnapshot,
                'payment_snapshot' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $order;
        });
    }

    private function createOrderForContext(
        User $user,
        Plan $plan,
        string $period,
        array $context,
        bool $useUserBalance,
        ?string $couponCode = null
    ): Order {
        $agent = User::query()->find((int) $context['agent_user_id']);
        if (!$agent) {
            throw new ApiException('Agent user does not exist');
        }

        $period = PlanService::getPeriodKey($period);
        $sale = app(AgentStorefrontService::class)->resolveSalePrice($agent->id, $plan->id, $period);
        $cost = $this->calculatePlatformCost($agent, $plan, $period);

        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $context, $sale, $cost, $useUserBalance): Order {
            $lockedAgent = User::query()
                ->whereKey((int) $context['agent_user_id'])
                ->lockForUpdate()
                ->first();
            if (!$lockedAgent) {
                throw new ApiException('Agent user does not exist');
            }
            $this->activeProfile($lockedAgent);

            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedUser) {
                throw new ApiException(__('User does not exist'));
            }

            OrderService::assertNoIncompleteOrder($lockedUser->id);

            if ($this->availableBalance($lockedAgent) < (int) $cost['amount']) {
                throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
            }

            $saleAmount = (int) $sale['sale_amount'];
            if ($useUserBalance && (int) $lockedUser->balance < $saleAmount) {
                throw new ApiException(__('Insufficient balance'));
            }

            $now = time();
            $order = new Order([
                'user_id' => $lockedUser->id,
                'site_id' => $lockedUser->site_id,
                'plan_id' => $plan->id,
                'period' => $period,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => $useUserBalance ? 0 : $saleAmount,
                'discount_amount' => 0,
                'balance_amount' => $useUserBalance ? $saleAmount : 0,
            ]);
            $orderService = new OrderService($order);
            $orderService->setOrderType($lockedUser);

            if ($useUserBalance && $saleAmount > 0) {
                if (!app(UserService::class)->addBalance($lockedUser->id, -$saleAmount)) {
                    throw new ApiException(__('Insufficient balance'));
                }
                $lockedUser->balance = (int) $lockedUser->balance - $saleAmount;
            }

            $ownership = AgentUser::query()
                ->where('sub_user_id', $lockedUser->id)
                ->first();
            if (!$ownership) {
                AgentUser::query()->create([
                    'agent_user_id' => $lockedAgent->id,
                    'sub_user_id' => $lockedUser->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $lockedUser->invite_user_id = $lockedAgent->id;
                $lockedUser->updated_at = $now;
                $lockedUser->save();
            } elseif ((int) $lockedUser->invite_user_id !== (int) $ownership->agent_user_id) {
                $lockedUser->invite_user_id = (int) $ownership->agent_user_id;
                $lockedUser->updated_at = $now;
                $lockedUser->save();
            }

            $order->invite_user_id = $lockedUser->invite_user_id;
            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            $pricingSnapshot = array_merge($sale['pricing_snapshot'], [
                'platform_base_amount' => (int) $cost['platform_base_amount'],
                'cost_base_amount' => (int) $cost['base_amount'],
                'cost_amount' => (int) $cost['amount'],
                'discount_percent' => (float) $cost['discount_percent'],
                'cost_site_id' => $cost['cost_site_id'] !== null ? (int) $cost['cost_site_id'] : null,
                'cost_source' => (string) $cost['cost_source'],
            ]);
            $contextSource = (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN);
            $agentDomainId = $context['agent_domain_id'] ?? null;
            $domainSnapshot = [
                'source' => $contextSource,
                'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
                'domain' => (string) ($context['domain'] ?? ''),
                'is_primary' => (bool) ($context['is_primary'] ?? false),
            ];

            $hold = AgentBalanceHold::query()->create([
                'agent_user_id' => $lockedAgent->id,
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'amount' => (int) $cost['amount'],
                'status' => AgentBalanceHold::STATUS_PENDING,
                'metadata' => [
                    'buyer_user_id' => (int) $lockedUser->id,
                    'plan_id' => (int) $plan->id,
                    'period' => $period,
                    'pricing_snapshot' => $pricingSnapshot,
                    'domain_snapshot' => $domainSnapshot,
                ],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            AgentOrderContext::query()->create([
                'order_id' => $order->id,
                'trade_no' => $order->trade_no,
                'agent_user_id' => $lockedAgent->id,
                'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
                'payment_id' => null,
                'sale_amount' => (int) $sale['sale_amount'],
                'cost_amount' => (int) $cost['amount'],
                'hold_id' => $hold->id,
                'status' => AgentOrderContext::STATUS_PENDING,
                'pricing_snapshot' => $pricingSnapshot,
                'domain_snapshot' => $domainSnapshot,
                'payment_snapshot' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            HookManager::call('order.create.after', $order);
            HookManager::call('order.after_create', $order);

            return $order;
        });
    }

    public function agentUserIdForPaymentMethods(Request $request): ?int
    {
        $context = $this->effectivePaymentContext($request);

        return $context ? (int) $context['agent_user_id'] : null;
    }

    public function availablePaymentMethodsForRequest(Request $request)
    {
        $context = $this->effectivePaymentContext($request);

        return Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent',
            'owner_type',
            'owner_id',
            'owner_domain_id',
        ])
            ->where('enable', 1)
            ->when($context, function ($query) use ($context): void {
                $agentDomainId = $context['agent_domain_id'] ?? null;

                $query->where('owner_type', Payment::OWNER_AGENT)
                    ->where('owner_id', (int) $context['agent_user_id'])
                    ->where(function ($query) use ($agentDomainId): void {
                        $query->whereNull('owner_domain_id');
                        if ($agentDomainId !== null) {
                            $query->orWhere('owner_domain_id', (int) $agentDomainId);
                        }
                    });
            }, function ($query): void {
                $query->where('owner_type', Payment::OWNER_PLATFORM);
            })
            ->orderBy('sort', 'ASC')
            ->get();
    }

    public function contextForOrder(Order $order): ?AgentOrderContext
    {
        return AgentOrderContext::query()
            ->where('order_id', $order->id)
            ->first();
    }

    public function assertPaymentAvailableForOrder(Order $order, Payment $payment): void
    {
        $context = $this->contextForOrder($order);
        if (!$context) {
            if ($payment->owner_type !== Payment::OWNER_PLATFORM) {
                throw new ApiException('This payment method is unavailable.');
            }
            return;
        }

        if (
            $payment->owner_type !== Payment::OWNER_AGENT
            || (int) $payment->owner_id !== (int) $context->agent_user_id
        ) {
            throw new ApiException('This payment method is unavailable.');
        }

        if ($payment->owner_domain_id !== null && (int) $payment->owner_domain_id !== (int) $context->agent_domain_id) {
            throw new ApiException('This payment method is unavailable.');
        }
    }

    public function assignPaymentForCheckout(Order $order, Payment $payment, ?int $handlingAmount): Order
    {
        return DB::transaction(function () use ($order, $payment, $handlingAmount): Order {
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();
            if (!$lockedOrder || (int) $lockedOrder->status !== 0) {
                throw new ApiException(__('Order does not exist or has been paid'));
            }

            $context = AgentOrderContext::query()
                ->where('order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->first();
            if (!$context) {
                if ($payment->owner_type !== Payment::OWNER_PLATFORM) {
                    throw new ApiException('This payment method is unavailable.');
                }
            } else {
                if (
                    $payment->owner_type !== Payment::OWNER_AGENT
                    || (int) $payment->owner_id !== (int) $context->agent_user_id
                ) {
                    throw new ApiException('This payment method is unavailable.');
                }
                if ($payment->owner_domain_id !== null && (int) $payment->owner_domain_id !== (int) $context->agent_domain_id) {
                    throw new ApiException('This payment method is unavailable.');
                }

                if ((int) $context->cost_amount > 0 || $context->hold_id !== null) {
                    $hold = AgentBalanceHold::query()
                        ->whereKey($context->hold_id)
                        ->lockForUpdate()
                        ->first();
                    if (!$hold || $hold->status !== AgentBalanceHold::STATUS_PENDING) {
                        throw new ApiException('Agent balance hold is unavailable');
                    }

                    $agent = User::query()
                        ->whereKey($context->agent_user_id)
                        ->lockForUpdate()
                        ->first();
                    if (!$agent) {
                        throw new ApiException('Agent user does not exist');
                    }

                    $pendingOther = max(0, $this->activePendingHoldTotal((int) $agent->id) - (int) $hold->amount);

                    if (((int) $agent->balance - (int) $pendingOther) < (int) $hold->amount) {
                        throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
                    }
                }

                $context->payment_id = $payment->id;
                $context->payment_snapshot = [
                    'id' => (int) $payment->id,
                    'name' => (string) $payment->name,
                    'payment' => (string) $payment->payment,
                    'owner_type' => (string) $payment->owner_type,
                    'owner_id' => $payment->owner_id ? (int) $payment->owner_id : null,
                    'owner_domain_id' => $payment->owner_domain_id !== null ? (int) $payment->owner_domain_id : null,
                ];
                $context->updated_at = time();
                $context->save();
            }

            $lockedOrder->handling_amount = $handlingAmount;
            $lockedOrder->payment_id = $payment->id;
            if (!$lockedOrder->save()) {
                throw new ApiException(__('Request failed, please try again later'));
            }

            return $lockedOrder;
        });
    }

    private function effectivePaymentContext(Request $request): ?array
    {
        $tradeNo = trim((string) $request->input('trade_no', ''));
        if ($tradeNo !== '' && $request->user()) {
            $order = Order::query()
                ->where('trade_no', $tradeNo)
                ->where('user_id', $request->user()->id)
                ->first();
            if ($order) {
                $context = $this->contextForOrder($order);

                return $context ? [
                    'agent_user_id' => (int) $context->agent_user_id,
                    'agent_domain_id' => $context->agent_domain_id !== null ? (int) $context->agent_domain_id : null,
                ] : null;
            }
        }

        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
        if (!$context) {
            return null;
        }

        return [
            'agent_user_id' => (int) $context['agent_user_id'],
            'agent_domain_id' => isset($context['agent_domain_id']) && $context['agent_domain_id'] !== null
                ? (int) $context['agent_domain_id']
                : null,
        ];
    }

    public function attachPayment(Order $order, Payment $payment): void
    {
        $context = $this->contextForOrder($order);
        if (!$context) {
            return;
        }

        $context->payment_id = $payment->id;
        $context->payment_snapshot = [
            'id' => (int) $payment->id,
            'name' => (string) $payment->name,
            'payment' => (string) $payment->payment,
            'owner_type' => (string) $payment->owner_type,
            'owner_id' => $payment->owner_id ? (int) $payment->owner_id : null,
            'owner_domain_id' => $payment->owner_domain_id !== null ? (int) $payment->owner_domain_id : null,
        ];
        $context->updated_at = time();
        $context->save();
    }

    public function captureForPaidOrder(Order $order): void
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('v2_agent_order_context')) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $context = AgentOrderContext::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            if (!$context) {
                return;
            }

            if ((int) $context->cost_amount <= 0 && $context->hold_id === null) {
                $context->status = AgentOrderContext::STATUS_PAID;
                $context->updated_at = time();
                $context->save();
                return;
            }

            $hold = AgentBalanceHold::query()
                ->whereKey($context->hold_id)
                ->lockForUpdate()
                ->first();
            if (
                $context->status === AgentOrderContext::STATUS_PAID
                && $hold
                && $hold->status === AgentBalanceHold::STATUS_CAPTURED
            ) {
                return;
            }
            if (!$hold || $hold->status !== AgentBalanceHold::STATUS_PENDING) {
                throw new ApiException('Agent balance hold is unavailable');
            }

            $agent = User::query()
                ->whereKey($context->agent_user_id)
                ->lockForUpdate()
                ->first();
            if (!$agent) {
                throw new ApiException('Agent user does not exist');
            }

            $before = (int) $agent->balance;
            $amount = (int) $hold->amount;
            if ($before < $amount) {
                throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
            }

            $now = time();
            $agent->balance = $before - $amount;
            $agent->updated_at = $now;
            $agent->save();

            $hold->status = AgentBalanceHold::STATUS_CAPTURED;
            $hold->captured_at = $now;
            $hold->updated_at = $now;
            $hold->save();

            $context->status = AgentOrderContext::STATUS_PAID;
            $context->updated_at = $now;
            $context->save();

            AgentLedger::query()->create([
                'agent_user_id' => $agent->id,
                'target_user_id' => $order->user_id,
                'type' => self::LEDGER_AGENT_ORDER_COST,
                'amount' => -$amount,
                'balance_before' => $before,
                'balance_after' => (int) $agent->balance,
                'plan_id' => $order->plan_id,
                'period' => $order->period,
                'metadata' => [
                    'trade_no' => $order->trade_no,
                    'hold_id' => (int) $hold->id,
                    'context_id' => (int) $context->id,
                    'sale_amount' => (int) $context->sale_amount,
                    'cost_amount' => (int) $context->cost_amount,
                ],
                'created_at' => $now,
            ]);
        });
    }

    public function failForOrder(Order $order, string $reason): void
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('v2_agent_order_context')) {
            return;
        }

        DB::transaction(function () use ($order, $reason): void {
            $context = AgentOrderContext::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            if (!$context || !$this->canMarkAgentOrderFailed($context)) {
                return;
            }

            $hold = $context->hold_id ? AgentBalanceHold::query()
                ->whereKey($context->hold_id)
                ->lockForUpdate()
                ->first() : null;

            $this->markAgentOrderFailed($context, $hold, $reason);
        });
    }

    public function failForOrderIfBalanceInsufficient(Order $order): void
    {
        if (!DB::connection()->getSchemaBuilder()->hasTable('v2_agent_order_context')) {
            return;
        }

        DB::transaction(function () use ($order): void {
            $context = AgentOrderContext::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();
            if (!$context || !$this->canMarkAgentOrderFailed($context) || !$context->hold_id) {
                return;
            }

            $hold = AgentBalanceHold::query()
                ->whereKey($context->hold_id)
                ->lockForUpdate()
                ->first();
            if (!$hold || $hold->status !== AgentBalanceHold::STATUS_PENDING) {
                return;
            }

            $agent = User::query()
                ->whereKey($context->agent_user_id)
                ->lockForUpdate()
                ->first();
            if (!$agent || (int) $agent->balance >= (int) $hold->amount) {
                return;
            }

            $this->markAgentOrderFailed($context, $hold, self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
        });
    }

    private function markAgentOrderFailed(AgentOrderContext $context, ?AgentBalanceHold $hold, string $reason): void
    {
        $now = time();
        $snapshot = is_array($context->payment_snapshot) ? $context->payment_snapshot : [];
        if ($context->status === AgentOrderContext::STATUS_FAILED && array_key_exists('failure_reason', $snapshot)) {
            return;
        }

        if ($hold && $hold->status === AgentBalanceHold::STATUS_PENDING) {
            $metadata = is_array($hold->metadata) ? $hold->metadata : [];
            $metadata['failure_reason'] = $reason;
            $hold->metadata = $metadata;
            $hold->status = AgentBalanceHold::STATUS_FAILED;
            $hold->updated_at = $now;
            $hold->save();
        }

        $snapshot['failure_reason'] = $reason;
        $context->payment_snapshot = $snapshot;
        $context->status = AgentOrderContext::STATUS_FAILED;
        $context->updated_at = $now;
        $context->save();
    }

    private function canMarkAgentOrderFailed(AgentOrderContext $context): bool
    {
        return in_array($context->status, [
            AgentOrderContext::STATUS_PENDING,
            AgentOrderContext::STATUS_FAILED,
        ], true);
    }

    public function releaseForOrder(Order $order, string $status = AgentBalanceHold::STATUS_RELEASED): void
    {
        if (!$this->hasTable('v2_agent_balance_hold')) {
            return;
        }

        $hasContextTable = $this->hasTable('v2_agent_order_context');

        DB::transaction(function () use ($order, $status, $hasContextTable): void {
            $context = $hasContextTable
                ? AgentOrderContext::query()
                    ->where('order_id', $order->id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $hold = $this->pendingHoldForOrder($order, $context);
            if (!$hold) {
                if ($context) {
                    $context->status = AgentOrderContext::STATUS_CANCELLED;
                    $context->updated_at = time();
                    $context->save();
                }
                return;
            }

            $now = time();
            $hold->status = $status;
            if ($status === AgentBalanceHold::STATUS_RELEASED) {
                $hold->released_at = $now;
            }
            $hold->updated_at = $now;
            $hold->save();

            if ($context) {
                if ((int) ($context->hold_id ?? 0) !== (int) $hold->id) {
                    $context->hold_id = $hold->id;
                }
                $context->status = $status === AgentBalanceHold::STATUS_RELEASED
                    ? AgentOrderContext::STATUS_CANCELLED
                    : AgentOrderContext::STATUS_FAILED;
                $context->updated_at = $now;
                $context->save();
            }
        });
    }

    public function releaseCancelledPendingHolds(?int $agentUserId = null, int $limit = 500): int
    {
        if (!$this->hasTable('v2_agent_balance_hold') || !$this->hasTable('v2_order')) {
            return 0;
        }

        $limit = max(1, min(5000, $limit));
        $holds = AgentBalanceHold::query()
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->when($agentUserId !== null, fn ($query) => $query->where('agent_user_id', $agentUserId))
            ->whereHas('order', fn ($query) => $query->where('status', Order::STATUS_CANCELLED))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'order_id']);

        $released = 0;
        foreach ($holds as $hold) {
            $order = Order::query()->find($hold->order_id);
            if (!$order) {
                continue;
            }

            $this->releaseForOrder($order);

            $releasedHold = AgentBalanceHold::query()->find($hold->id);
            if ($releasedHold && $releasedHold->status === AgentBalanceHold::STATUS_RELEASED) {
                $released++;
            }
        }

        return $released;
    }

    private function pendingHoldForOrder(Order $order, ?AgentOrderContext $context): ?AgentBalanceHold
    {
        $hold = AgentBalanceHold::query()
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->where(function ($query) use ($order): void {
                $query->where('order_id', (int) $order->id)
                    ->orWhere('trade_no', (string) $order->trade_no);
            })
            ->lockForUpdate()
            ->first();

        if ($hold || !$context || !$context->hold_id) {
            return $hold;
        }

        return AgentBalanceHold::query()
            ->whereKey((int) $context->hold_id)
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->lockForUpdate()
            ->first();
    }

    private function hasTable(string $table): bool
    {
        try {
            return DB::connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function activeProfile(User $agent): AgentProfile
    {
        $profile = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->first();
        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }

        return $profile;
    }
}
