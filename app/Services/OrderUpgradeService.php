<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\OrderUpgradeQuote;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderUpgradeService
{
    private const DEFAULT_QUOTE_TTL_SECONDS = 300;
    private const DEFAULT_MIN_PAY_RATIO = 0.20;
    private const DEFAULT_MAX_CREDIT_CAP_RATIO = 0.70;
    private const DEFAULT_CREDIT_COEFFICIENTS = [
        Plan::PERIOD_MONTHLY => 0.35,
        Plan::PERIOD_QUARTERLY => 0.45,
        Plan::PERIOD_HALF_YEARLY => 0.55,
        Plan::PERIOD_YEARLY => 0.68,
        Plan::PERIOD_TWO_YEARLY => 0.70,
        Plan::PERIOD_THREE_YEARLY => 0.70,
    ];
    private const DEFAULT_USAGE_PENALTY_RULES = [
        ['max_usage_percentage' => 20, 'coefficient' => 0.95],
        ['max_usage_percentage' => 40, 'coefficient' => 0.85],
        ['max_usage_percentage' => 60, 'coefficient' => 0.70],
        ['max_usage_percentage' => 80, 'coefficient' => 0.50],
        ['max_usage_percentage' => 95, 'coefficient' => 0.30],
        ['max_usage_percentage' => 100, 'coefficient' => 0.10],
    ];

    public function previewUpgrade(User $user, Plan $targetPlan, string $period): array
    {
        $periodKey = PlanService::getPeriodKey($period);
        $preview = $this->buildPreview($user, $targetPlan, $periodKey);

        if (!$preview['allow_upgrade']) {
            return $preview;
        }

        $quote = OrderUpgradeQuote::create([
            'user_id' => $user->id,
            'source_order_id' => $preview['_source_order']->id,
            'source_plan_id' => $preview['_source_plan']->id,
            'target_plan_id' => $targetPlan->id,
            'target_period' => $periodKey,
            'target_price' => $preview['pricing_detail']['target_price'],
            'source_paid_basis' => $preview['pricing_detail']['source_paid_basis'],
            'time_ratio' => $preview['pricing_detail']['time_ratio'],
            'traffic_ratio' => $preview['pricing_detail']['traffic_ratio'],
            'base_credit_coeff' => $preview['pricing_detail']['base_credit_coeff'],
            'usage_penalty_coeff' => $preview['pricing_detail']['usage_penalty_coeff'],
            'credit_cap_amount' => $preview['pricing_detail']['credit_cap_amount'],
            'min_pay_amount' => $preview['pricing_detail']['min_pay_amount'],
            'upgrade_credit_amount' => $preview['pricing_detail']['upgrade_credit_amount'],
            'final_pay_amount' => $preview['payable_amount'],
            'token' => Helper::guid(),
            'status' => OrderUpgradeQuote::STATUS_PENDING,
            'snapshot' => [
                'source_plan' => $preview['source_plan'],
                'target_plan' => $preview['target_plan'],
                'pricing_detail' => $preview['pricing_detail'],
                'payable_amount' => $preview['payable_amount'],
            ],
            'expires_at' => time() + $this->getQuoteTtlSeconds(),
        ]);

        unset($preview['_source_order'], $preview['_source_plan']);
        $preview['quote_token'] = $quote->token;
        $preview['expires_at'] = (int) $quote->expires_at;

        return $preview;
    }

    public function confirmUpgrade(User $user, string $quoteToken): Order
    {
        return DB::transaction(function () use ($user, $quoteToken): Order {
            /** @var OrderUpgradeQuote|null $quote */
            $quote = OrderUpgradeQuote::query()
                ->where('user_id', $user->id)
                ->where('token', $quoteToken)
                ->lockForUpdate()
                ->first();

            if (!$quote) {
                throw new ApiException(__('Upgrade quote does not exist'));
            }

            if ($quote->status !== OrderUpgradeQuote::STATUS_PENDING) {
                throw new ApiException(__('Upgrade quote is no longer available'));
            }

            if ((int) $quote->expires_at <= time()) {
                $quote->status = OrderUpgradeQuote::STATUS_EXPIRED;
                $quote->save();
                throw new ApiException(__('Upgrade quote has expired'));
            }

            if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) {
                throw new ApiException(__('You have an unpaid or pending order, please try again later or cancel it'));
            }

            $sourceOrder = Order::query()
                ->where('id', $quote->source_order_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$sourceOrder) {
                throw new ApiException(__('Source order does not exist'));
            }

            $targetPlan = Plan::query()->find($quote->target_plan_id);
            if (!$targetPlan) {
                throw new ApiException(__('Subscription plan does not exist'));
            }

            $preview = $this->buildPreview($user, $targetPlan, (string) $quote->target_period, $sourceOrder);
            if (!$preview['allow_upgrade']) {
                throw new ApiException($preview['reason'] ?? __('Upgrade is not available'));
            }

            $quotedPayable = (int) $quote->final_pay_amount;
            $livePayable = (int) $preview['payable_amount'];
            $payableAmount = max($quotedPayable, $livePayable);
            if ($payableAmount <= 0) {
                throw new ApiException(__('Upgrade payable amount must be greater than 0'));
            }

            $targetPrice = OrderService::amountToCents($targetPlan->prices[(string) $quote->target_period] ?? 0);
            $upgradeCreditAmount = max(0, $targetPrice - $payableAmount);
            $pricingSnapshot = $preview['pricing_detail'];
            $pricingSnapshot['upgrade_credit_amount'] = $upgradeCreditAmount;
            $pricingSnapshot['final_pay_amount'] = $payableAmount;

            $order = new Order([
                'user_id' => $user->id,
                'plan_id' => $targetPlan->id,
                'period' => (string) $quote->target_period,
                'trade_no' => Helper::generateOrderNo(),
                'total_amount' => $payableAmount,
                'type' => Order::TYPE_DISCOUNT_UPGRADE,
                'upgrade_quote_id' => $quote->id,
                'upgrade_credit_amount' => $upgradeCreditAmount,
                'upgrade_source_order_ids' => [$sourceOrder->id],
                'upgrade_pricing_snapshot' => [
                    'source_plan' => $preview['source_plan'],
                    'target_plan' => $preview['target_plan'],
                    'pricing_detail' => $pricingSnapshot,
                    'quoted_payable_amount' => $quotedPayable,
                    'confirmed_payable_amount' => $payableAmount,
                ],
            ]);

            $orderService = new OrderService($order);
            $orderService->setInvite($user);

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            $quote->status = OrderUpgradeQuote::STATUS_CONSUMED;
            if (!$quote->save()) {
                throw new ApiException(__('Failed to update upgrade quote'));
            }

            return $order;
        });
    }

    public static function getDefaultCreditCoefficients(): array
    {
        return self::DEFAULT_CREDIT_COEFFICIENTS;
    }

    public static function normalizeCreditCoefficients(mixed $value): array
    {
        $default = self::getDefaultCreditCoefficients();
        if (!is_array($value)) {
            return $default;
        }

        $normalized = [];
        foreach ($default as $period => $coefficient) {
            $configured = $value[$period] ?? null;
            $normalized[$period] = is_numeric($configured)
                ? max(0, min(1, (float) $configured))
                : $coefficient;
        }

        return $normalized;
    }

    public static function getDefaultUsagePenaltyRules(): array
    {
        return self::DEFAULT_USAGE_PENALTY_RULES;
    }

    public static function normalizeUsagePenaltyRules(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return self::getDefaultUsagePenaltyRules();
        }

        $normalized = [];
        foreach ($value as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $maxUsage = $rule['max_usage_percentage'] ?? null;
            $coefficient = $rule['coefficient'] ?? null;
            if (!is_numeric($maxUsage) || !is_numeric($coefficient)) {
                continue;
            }

            $normalized[] = [
                'max_usage_percentage' => max(0, min(100, (float) $maxUsage)),
                'coefficient' => max(0, min(1, (float) $coefficient)),
            ];
        }

        if ($normalized === []) {
            return self::getDefaultUsagePenaltyRules();
        }

        usort($normalized, fn(array $a, array $b): int => $a['max_usage_percentage'] <=> $b['max_usage_percentage']);

        return $normalized;
    }

    private function buildPreview(User $user, Plan $targetPlan, string $periodKey, ?Order $sourceOrder = null): array
    {
        if (!(bool) admin_setting('upgrade_v2_enable', false)) {
            return $this->deny(__('Upgrade is currently disabled'));
        }

        if (!(bool) admin_setting('plan_change_enable', true)) {
            return $this->deny(__('Changing subscription is currently disabled'));
        }

        if (app(UserService::class)->isNotCompleteOrderByUserId($user->id)) {
            return $this->deny(__('You have an unpaid or pending order, please try again later or cancel it'));
        }

        if (!$this->hasActiveSubscription($user)) {
            return $this->deny(__('No active subscription available for upgrade'));
        }

        $sourcePlan = Plan::query()->find($user->plan_id);
        if (!$sourcePlan) {
            return $this->deny(__('Current subscription plan does not exist'));
        }

        if ($sourcePlan->id === $targetPlan->id) {
            return $this->deny(__('Please use renewal for the same subscription'));
        }

        if (!$this->isRecurringPeriod($periodKey)) {
            return $this->deny(__('Only recurring subscription upgrades are supported'));
        }

        if ($sourceOrder === null) {
            $sourceOrder = $this->resolveSourceOrder($user, $sourcePlan);
        }

        if (!$sourceOrder) {
            return $this->deny(__('No valid source order available for upgrade'));
        }

        if ((int) $sourceOrder->user_id !== (int) $user->id || (int) $sourceOrder->plan_id !== (int) $sourcePlan->id) {
            return $this->deny(__('Source order does not match current subscription'));
        }

        if (!$this->isRecurringPeriod((string) $sourceOrder->period)) {
            return $this->deny(__('One-time subscriptions are not supported for upgrade'));
        }

        if (!$this->isPlanAllowedForUpgrade($sourcePlan, $targetPlan)) {
            return $this->deny(__('Target subscription is not allowed for upgrade'));
        }

        if (!$targetPlan->sell || !(new PlanService($targetPlan))->hasCapacity($targetPlan)) {
            return $this->deny(__('Current product is sold out'));
        }

        if (!array_key_exists($periodKey, $targetPlan->prices ?? [])) {
            return $this->deny(__('This payment period cannot be purchased, please choose another period'));
        }

        if (!$this->isHigherPricedTarget($sourceOrder, $targetPlan, $periodKey)) {
            return $this->deny(__('Target subscription must be a higher priced recurring plan'));
        }

        if ($this->hasUsedSourceOrder($user, $sourceOrder->id)) {
            return $this->deny(__('Current subscription has already been used for discount upgrade'));
        }

        $pricing = $this->calculatePricing($user, $sourcePlan, $sourceOrder, $targetPlan, $periodKey);

        return [
            'allow_upgrade' => true,
            'reason' => null,
            'quote_token' => null,
            'expires_at' => null,
            'source_plan' => [
                'id' => $sourcePlan->id,
                'name' => $sourcePlan->name,
                'period' => PlanService::getLegacyPeriod((string) $sourceOrder->period),
                'order_id' => $sourceOrder->id,
                'expired_at' => $user->expired_at,
            ],
            'target_plan' => [
                'id' => $targetPlan->id,
                'name' => $targetPlan->name,
                'period' => PlanService::getLegacyPeriod($periodKey),
            ],
            'pricing_detail' => $pricing,
            'payable_amount' => $pricing['final_pay_amount'],
            '_source_order' => $sourceOrder,
            '_source_plan' => $sourcePlan,
        ];
    }

    private function calculatePricing(User $user, Plan $sourcePlan, Order $sourceOrder, Plan $targetPlan, string $targetPeriod): array
    {
        $sourcePaidBasis = max(0, (int) $sourceOrder->total_amount + (int) $sourceOrder->balance_amount);
        $sourceMonths = OrderService::STR_TO_TIME[(string) $sourceOrder->period] ?? 0;
        $targetMonths = OrderService::STR_TO_TIME[$targetPeriod] ?? 0;
        if ($sourceMonths <= 0 || $targetMonths <= 0) {
            throw new ApiException(__('Invalid upgrade pricing configuration'));
        }

        $cycleStart = Carbon::createFromTimestamp((int) $sourceOrder->created_at);
        $cycleEnd = $cycleStart->copy()->addMonths($sourceMonths);
        $totalSeconds = max(1, $cycleEnd->timestamp - $cycleStart->timestamp);
        $remainSeconds = max(0, $cycleEnd->timestamp - time());
        $timeRatio = min(1, $remainSeconds / $totalSeconds);

        $totalTraffic = (int) ($user->transfer_enable ?: ($sourcePlan->transfer_enable * 1073741824));
        $remainTraffic = max(0, $totalTraffic - $user->getTotalUsedTraffic());
        $trafficRatio = $totalTraffic > 0 ? min(1, $remainTraffic / $totalTraffic) : 0;

        $baseCreditCoeff = $this->getBaseCreditCoeff((string) $sourceOrder->period);
        $usagePenaltyCoeff = $this->getUsagePenaltyCoeff($user->getTrafficUsagePercentage());
        $trafficFactorEnabled = $this->shouldApplyTrafficFactor($sourcePlan, (string) $sourceOrder->period);
        $timeDominantMode = !$trafficFactorEnabled;
        $effectiveTrafficRatio = $trafficFactorEnabled ? $trafficRatio : 1.0;
        $effectiveUsagePenaltyCoeff = $trafficFactorEnabled ? $usagePenaltyCoeff : 1.0;

        $rawCredit = (int) floor($sourcePaidBasis * min($timeRatio, $effectiveTrafficRatio));
        $proposedCredit = (int) floor($rawCredit * $baseCreditCoeff * $effectiveUsagePenaltyCoeff);

        $targetPrice = OrderService::amountToCents($targetPlan->prices[$targetPeriod] ?? 0);
        $minPayAmount = $this->getMinPayAmount($targetPrice);
        $creditCapAmount = $this->getCreditCapAmount($targetPrice, $minPayAmount);
        $upgradeCreditAmount = max(0, min($proposedCredit, $creditCapAmount));
        $finalPayAmount = max($minPayAmount, $targetPrice - $upgradeCreditAmount);

        return [
            'target_price' => $targetPrice,
            'source_paid_basis' => $sourcePaidBasis,
            'time_ratio' => round($timeRatio, 4),
            'traffic_ratio' => round($trafficRatio, 4),
            'traffic_factor_enabled' => $trafficFactorEnabled,
            'time_dominant_mode' => $timeDominantMode,
            'effective_traffic_ratio' => round($effectiveTrafficRatio, 4),
            'base_credit_coeff' => round($baseCreditCoeff, 4),
            'usage_penalty_coeff' => round($usagePenaltyCoeff, 4),
            'effective_usage_penalty_coeff' => round($effectiveUsagePenaltyCoeff, 4),
            'credit_cap_amount' => $creditCapAmount,
            'min_pay_amount' => $minPayAmount,
            'upgrade_credit_amount' => $upgradeCreditAmount,
            'final_pay_amount' => $finalPayAmount,
        ];
    }

    private function deny(string $reason): array
    {
        return [
            'allow_upgrade' => false,
            'reason' => $reason,
            'quote_token' => null,
            'expires_at' => null,
            'source_plan' => null,
            'target_plan' => null,
            'pricing_detail' => null,
            'payable_amount' => null,
        ];
    }

    private function hasActiveSubscription(User $user): bool
    {
        return $user->plan_id !== null && ($user->expired_at === null || $user->expired_at > time());
    }

    private function resolveSourceOrder(User $user, Plan $sourcePlan): ?Order
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('plan_id', $sourcePlan->id)
            ->where('status', Order::STATUS_COMPLETED)
            ->whereIn('type', [
                Order::TYPE_NEW_PURCHASE,
                Order::TYPE_RENEWAL,
                Order::TYPE_UPGRADE,
                Order::TYPE_DISCOUNT_UPGRADE,
            ])
            ->whereIn('period', array_keys(OrderService::STR_TO_TIME))
            ->orderByDesc('id')
            ->first();
    }

    private function isRecurringPeriod(string $period): bool
    {
        return array_key_exists($period, OrderService::STR_TO_TIME);
    }

    private function isPlanAllowedForUpgrade(Plan $sourcePlan, Plan $targetPlan): bool
    {
        $allowedIds = array_map('intval', $sourcePlan->upgrade_to_plan_ids ?? []);
        return in_array((int) $targetPlan->id, $allowedIds, true);
    }

    private function hasUsedSourceOrder(User $user, int $sourceOrderId): bool
    {
        return Order::query()
            ->where('user_id', $user->id)
            ->where('type', Order::TYPE_DISCOUNT_UPGRADE)
            ->where('status', Order::STATUS_COMPLETED)
            ->get()
            ->contains(function (Order $order) use ($sourceOrderId): bool {
                $sourceIds = array_map('intval', $order->upgrade_source_order_ids ?? []);
                return in_array($sourceOrderId, $sourceIds, true);
            });
    }

    private function isHigherPricedTarget(Order $sourceOrder, Plan $targetPlan, string $targetPeriod): bool
    {
        $sourceMonths = OrderService::STR_TO_TIME[(string) $sourceOrder->period] ?? 0;
        $targetMonths = OrderService::STR_TO_TIME[$targetPeriod] ?? 0;
        if ($sourceMonths <= 0 || $targetMonths <= 0) {
            return false;
        }

        $sourcePaidBasis = max(0, (int) $sourceOrder->total_amount + (int) $sourceOrder->balance_amount);
        if ($sourcePaidBasis <= 0) {
            $sourcePlan = Plan::query()->find((int) $sourceOrder->plan_id);
            if (!$sourcePlan) {
                return false;
            }
            $sourcePaidBasis = OrderService::amountToCents($sourcePlan->prices[(string) $sourceOrder->period] ?? 0);
        }
        $targetPrice = OrderService::amountToCents($targetPlan->prices[$targetPeriod] ?? 0);
        if ($sourcePaidBasis <= 0 || $targetPrice <= 0) {
            return false;
        }

        $sourceMonthly = $sourcePaidBasis / $sourceMonths;
        $targetMonthly = $targetPrice / $targetMonths;

        return $targetMonthly > $sourceMonthly;
    }

    private function shouldApplyTrafficFactor(Plan $sourcePlan, string $sourcePeriod): bool
    {
        $sourceMonths = OrderService::STR_TO_TIME[$sourcePeriod] ?? 0;
        if ($sourceMonths < 12) {
            return false;
        }

        $resetMethod = $sourcePlan->reset_traffic_method;
        if ($resetMethod === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $resetMethod = (int) admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }

        return $resetMethod === Plan::RESET_TRAFFIC_NEVER;
    }

    private function getBaseCreditCoeff(string $sourcePeriod): float
    {
        $coeffs = self::normalizeCreditCoefficients(
            admin_setting('upgrade_credit_coeffs', self::getDefaultCreditCoefficients())
        );

        return (float) ($coeffs[$sourcePeriod] ?? self::DEFAULT_CREDIT_COEFFICIENTS[Plan::PERIOD_MONTHLY]);
    }

    private function getUsagePenaltyCoeff(float $usagePercentage): float
    {
        $rules = self::normalizeUsagePenaltyRules(
            admin_setting('upgrade_usage_penalty_rules', self::getDefaultUsagePenaltyRules())
        );

        foreach ($rules as $rule) {
            if ($usagePercentage <= $rule['max_usage_percentage']) {
                return (float) $rule['coefficient'];
            }
        }

        return 0.0;
    }

    private function getMinPayAmount(int $targetPrice): int
    {
        $fixedMinPay = max(1, (int) admin_setting('upgrade_min_pay_amount', 300));
        $ratio = max(0, min(1, (float) admin_setting('upgrade_min_pay_ratio', self::DEFAULT_MIN_PAY_RATIO)));
        $ratioMinPay = (int) ceil($targetPrice * $ratio);

        return min($targetPrice, max($fixedMinPay, $ratioMinPay, 1));
    }

    private function getCreditCapAmount(int $targetPrice, int $minPayAmount): int
    {
        $capRatio = max(0, min(1, (float) admin_setting('upgrade_max_credit_cap_ratio', self::DEFAULT_MAX_CREDIT_CAP_RATIO)));
        $capByRatio = (int) floor($targetPrice * $capRatio);
        $capByMinPay = max(0, $targetPrice - $minPayAmount);

        return max(0, min($capByRatio, $capByMinPay));
    }

    private function getQuoteTtlSeconds(): int
    {
        return max(60, (int) admin_setting('upgrade_quote_ttl_seconds', self::DEFAULT_QUOTE_TTL_SECONDS));
    }
}
