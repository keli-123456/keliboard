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
        $pending = AgentBalanceHold::query()
            ->where('agent_user_id', $agent->id)
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->sum('amount');

        return max(0, (int) $agent->balance - (int) $pending);
    }

    public function calculatePlatformCost(User $agent, Plan $plan, string $period): array
    {
        $this->activeProfile($agent);
        $period = PlanService::getPeriodKey($period);
        $price = $plan->prices[$period] ?? null;
        if ($price === null || $price === '' || (float) $price < 0) {
            throw new ApiException('Period is not available');
        }

        $baseAmount = OrderService::amountToCents($price);
        $discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));
        $amount = (int) round($baseAmount * ($discountPercent / 100));

        return [
            'period' => $period,
            'amount' => $amount,
            'base_amount' => $baseAmount,
            'discount_percent' => $discountPercent,
        ];
    }

    public function createOrderFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode,
        Request $request
    ): ?Order {
        $context = app(AgentDomainResolver::class)->resolveRequest($request);
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

        $period = PlanService::getPeriodKey($period);
        $sale = app(AgentStorefrontService::class)->resolveSalePrice($agent->id, $plan->id, $period);
        $cost = $this->calculatePlatformCost($agent, $plan, $period);

        HookManager::call('order.create.before', [$user, $plan, $period, $couponCode]);

        return DB::transaction(function () use ($user, $plan, $period, $context, $sale, $cost): Order {
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

            $now = time();
            $order = new Order([
                'user_id' => $lockedUser->id,
                'plan_id' => $plan->id,
                'period' => $period,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => (int) $sale['sale_amount'],
                'discount_amount' => 0,
                'balance_amount' => 0,
            ]);
            $orderService = new OrderService($order);
            $orderService->setOrderType($lockedUser);

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
            }

            $order->invite_user_id = $lockedUser->invite_user_id;
            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            $pricingSnapshot = array_merge($sale['pricing_snapshot'], [
                'platform_base_amount' => (int) $cost['base_amount'],
                'cost_amount' => (int) $cost['amount'],
                'discount_percent' => (float) $cost['discount_percent'],
            ]);
            $domainSnapshot = [
                'agent_domain_id' => (int) $context['agent_domain_id'],
                'domain' => (string) $context['domain'],
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
                'agent_domain_id' => (int) $context['agent_domain_id'],
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
        $tradeNo = trim((string) $request->input('trade_no', ''));
        if ($tradeNo !== '' && $request->user()) {
            $order = Order::query()
                ->where('trade_no', $tradeNo)
                ->where('user_id', $request->user()->id)
                ->first();
            if ($order) {
                $context = $this->contextForOrder($order);
                if ($context) {
                    return (int) $context->agent_user_id;
                }
            }
        }

        $context = app(AgentDomainResolver::class)->resolveRequest($request);

        return $context ? (int) $context['agent_user_id'] : null;
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
