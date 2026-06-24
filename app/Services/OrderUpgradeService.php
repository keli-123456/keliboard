<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\AgentUser;
use App\Models\Order;
use App\Models\OrderUpgradeQuote;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

    public function previewUpgrade(User $user, Plan $targetPlan, string $period, ?Request $request = null): array
    {
        $periodKey = PlanService::getPeriodKey($period);
        $preview = $this->buildPreview($user, $targetPlan, $periodKey, null, $request);

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
                'tenant_context' => $preview['_tenant_context'],
                'target_pricing' => $preview['_target_pricing'],
            ],
            'expires_at' => time() + $this->getQuoteTtlSeconds(),
        ]);

        unset($preview['_source_order'], $preview['_source_plan'], $preview['_tenant_context'], $preview['_target_pricing']);
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

            $tenantContext = $this->tenantContextFromQuote($quote);
            $preview = $this->buildPreview($user, $targetPlan, (string) $quote->target_period, $sourceOrder, null, $tenantContext);
            if (!$preview['allow_upgrade']) {
                throw new ApiException($preview['reason'] ?? __('Upgrade is not available'));
            }

            $quotedPayable = (int) $quote->final_pay_amount;
            $livePayable = (int) $preview['payable_amount'];
            $payableAmount = max($quotedPayable, $livePayable);
            if ($payableAmount <= 0) {
                throw new ApiException(__('Upgrade payable amount must be greater than 0'));
            }

            $targetPrice = (int) $preview['pricing_detail']['target_price'];
            $upgradeCreditAmount = max(0, $targetPrice - $payableAmount);
            $pricingSnapshot = $preview['pricing_detail'];
            $pricingSnapshot['upgrade_credit_amount'] = $upgradeCreditAmount;
            $pricingSnapshot['final_pay_amount'] = $payableAmount;
            $tenantContext = $preview['_tenant_context'];
            $targetPricing = $preview['_target_pricing'];
            $tenantSource = (string) ($tenantContext['source'] ?? 'platform');

            $order = new Order([
                'user_id' => $user->id,
                'site_id' => $this->siteIdForOrder($user, $tenantContext),
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
                    'tenant_context' => $tenantContext,
                    'target_pricing' => $targetPricing,
                ],
            ]);

            $orderService = new OrderService($order);
            if ($tenantSource !== 'agent') {
                $orderService->setInvite($user);
            }

            if (!$order->save()) {
                throw new ApiException(__('Failed to create order'));
            }

            if ($tenantSource === 'agent') {
                $this->recordAgentUpgradeContext(
                    $order,
                    $user,
                    $preview['_source_plan'],
                    $sourceOrder,
                    $targetPlan,
                    (string) $quote->target_period,
                    $tenantContext,
                    $targetPricing,
                    $payableAmount,
                    $pricingSnapshot
                );
            } elseif ($tenantSource === 'site') {
                $this->recordSiteUpgradeContext(
                    $order,
                    $targetPlan,
                    (string) $quote->target_period,
                    $tenantContext,
                    $targetPricing,
                    $targetPrice,
                    $pricingSnapshot
                );
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

    private function buildPreview(
        User $user,
        Plan $targetPlan,
        string $periodKey,
        ?Order $sourceOrder = null,
        ?Request $request = null,
        ?array $tenantContext = null
    ): array
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

        try {
            $targetPricing = $this->resolveTargetPricing($user, $targetPlan, $periodKey, $request, $tenantContext);
        } catch (ApiException $exception) {
            return $this->deny($exception->getMessage());
        }

        $targetPrice = max(0, (int) ($targetPricing['sale_amount'] ?? 0));
        if ($targetPrice <= 0) {
            return $this->deny(__('This payment period cannot be purchased, please choose another period'));
        }

        if (!$this->isHigherPricedTarget($sourceOrder, $targetPrice, $periodKey)) {
            return $this->deny(__('Target subscription must be a higher priced recurring plan'));
        }

        if ($this->hasUsedSourceOrder($user, $sourceOrder->id)) {
            return $this->deny(__('Current subscription has already been used for discount upgrade'));
        }

        $pricing = $this->calculatePricing($user, $sourcePlan, $sourceOrder, $periodKey, $targetPrice);
        $pricing['tenant_source'] = (string) ($targetPricing['source'] ?? 'platform');
        $pricing['platform_plan_price'] = max(0, (int) ($targetPricing['platform_plan_price'] ?? $targetPrice));
        $pricing['target_pricing_snapshot'] = $targetPricing['pricing_snapshot'] ?? [];

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
            '_tenant_context' => $this->tenantContextFromPricing($targetPricing),
            '_target_pricing' => $this->targetPricingSnapshot($targetPricing),
        ];
    }

    private function calculatePricing(
        User $user,
        Plan $sourcePlan,
        Order $sourceOrder,
        string $targetPeriod,
        int $targetPrice,
        ?int $sourcePaidBasisOverride = null
    ): array
    {
        $sourcePaidBasis = $sourcePaidBasisOverride !== null
            ? max(0, $sourcePaidBasisOverride)
            : max(0, (int) $sourceOrder->total_amount + (int) $sourceOrder->balance_amount);
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

    private function resolveTargetPricing(
        User $user,
        Plan $targetPlan,
        string $periodKey,
        ?Request $request,
        ?array $tenantContext
    ): array
    {
        $pricing = app(TenantPlanPricingService::class);

        if ($tenantContext !== null) {
            return $pricing->resolveForContext($user, $targetPlan, $periodKey, $tenantContext);
        }

        if ($request !== null) {
            return $pricing->resolveForRequest($user, $targetPlan, $periodKey, $request);
        }

        return $pricing->resolveForUser($user, $targetPlan, $periodKey);
    }

    private function tenantContextFromPricing(array $pricing): array
    {
        return [
            'source' => (string) ($pricing['source'] ?? 'platform'),
            'agent_context' => is_array($pricing['agent_context'] ?? null) ? $pricing['agent_context'] : null,
            'site_context' => is_array($pricing['site_context'] ?? null) ? $pricing['site_context'] : null,
        ];
    }

    private function targetPricingSnapshot(array $pricing): array
    {
        return [
            'source' => (string) ($pricing['source'] ?? 'platform'),
            'period' => (string) ($pricing['period'] ?? ''),
            'sale_amount' => max(0, (int) ($pricing['sale_amount'] ?? 0)),
            'platform_plan_price' => max(0, (int) ($pricing['platform_plan_price'] ?? 0)),
            'pricing_snapshot' => is_array($pricing['pricing_snapshot'] ?? null) ? $pricing['pricing_snapshot'] : [],
        ];
    }

    private function tenantContextFromQuote(OrderUpgradeQuote $quote): ?array
    {
        $snapshot = is_array($quote->snapshot) ? $quote->snapshot : [];
        $context = $snapshot['tenant_context'] ?? null;

        return is_array($context) ? $context : null;
    }

    private function siteIdForOrder(User $user, array $tenantContext): ?int
    {
        $siteContext = $tenantContext['site_context'] ?? null;
        if (is_array($siteContext) && !empty($siteContext['site_id'])) {
            return (int) $siteContext['site_id'];
        }

        return $user->site_id ? (int) $user->site_id : null;
    }

    private function recordSiteUpgradeContext(
        Order $order,
        Plan $targetPlan,
        string $period,
        array $tenantContext,
        array $targetPricing,
        int $targetPrice,
        array $pricingSnapshot
    ): void
    {
        $siteContext = $tenantContext['site_context'] ?? null;
        if (!is_array($siteContext) || empty($siteContext['site_id'])) {
            return;
        }

        $snapshot = array_merge(is_array($targetPricing['pricing_snapshot'] ?? null) ? $targetPricing['pricing_snapshot'] : [], [
            'order_type' => 'discount_upgrade',
            'target_sale_amount' => $targetPrice,
            'upgrade_pricing_detail' => $pricingSnapshot,
        ]);

        app(SiteCommerceService::class)->recordOrderContext(
            $order,
            $siteContext,
            [
                'sale_amount' => $targetPrice,
                'platform_plan_price' => max(0, (int) ($targetPricing['platform_plan_price'] ?? $targetPrice)),
                'pricing_snapshot' => $snapshot,
            ],
            $targetPlan,
            $period
        );
    }

    private function recordAgentUpgradeContext(
        Order $order,
        User $user,
        Plan $sourcePlan,
        Order $sourceOrder,
        Plan $targetPlan,
        string $period,
        array $tenantContext,
        array $targetPricing,
        int $payableAmount,
        array $salePricingSnapshot
    ): void
    {
        $agentContext = $tenantContext['agent_context'] ?? null;
        if (!is_array($agentContext) || empty($agentContext['agent_user_id'])) {
            throw new ApiException('Agent user does not exist');
        }

        $agent = User::query()
            ->whereKey((int) $agentContext['agent_user_id'])
            ->lockForUpdate()
            ->first();
        if (!$agent) {
            throw new ApiException('Agent user does not exist');
        }

        $agentCommerce = app(AgentCommerceService::class);
        $targetCost = $agentCommerce->calculatePlatformCost($agent, $targetPlan, $period);
        $sourceCostBasis = $this->sourceCostBasisForAgent($sourceOrder, (int) $agent->id);
        $costPricing = $this->calculatePricing(
            $user,
            $sourcePlan,
            $sourceOrder,
            $period,
            max(0, (int) $targetCost['amount']),
            $sourceCostBasis
        );
        $costAmount = max(0, (int) $costPricing['final_pay_amount']);

        if ($agentCommerce->availableBalance($agent) < $costAmount) {
            throw new ApiException(AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE);
        }

        $lockedUser = User::query()
            ->whereKey($user->id)
            ->lockForUpdate()
            ->first();
        if (!$lockedUser) {
            throw new ApiException(__('User does not exist'));
        }

        $this->syncAgentOwnership($agent, $lockedUser);

        $now = time();
        $order->invite_user_id = $lockedUser->invite_user_id;
        $order->commission_balance = 0;
        $order->updated_at = $now;
        if (!$order->save()) {
            throw new ApiException(__('Failed to create order'));
        }

        $domainSnapshot = $this->agentDomainSnapshot($agentContext);
        $pricingSnapshot = array_merge(is_array($targetPricing['pricing_snapshot'] ?? null) ? $targetPricing['pricing_snapshot'] : [], [
            'order_type' => 'discount_upgrade',
            'sale_amount' => $payableAmount,
            'target_sale_amount' => max(0, (int) ($targetPricing['sale_amount'] ?? $payableAmount)),
            'platform_base_amount' => max(0, (int) ($targetCost['platform_base_amount'] ?? 0)),
            'cost_base_amount' => max(0, (int) ($targetCost['base_amount'] ?? 0)),
            'full_cost_amount' => max(0, (int) ($targetCost['amount'] ?? 0)),
            'cost_amount' => $costAmount,
            'discount_percent' => (float) ($targetCost['discount_percent'] ?? 100),
            'cost_site_id' => ($targetCost['cost_site_id'] ?? null) !== null ? (int) $targetCost['cost_site_id'] : null,
            'cost_source' => (string) ($targetCost['cost_source'] ?? 'platform'),
            'source_cost_basis' => $sourceCostBasis,
            'upgrade_pricing_detail' => $salePricingSnapshot,
            'cost_pricing_detail' => $costPricing,
        ]);

        $hold = AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'amount' => $costAmount,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'metadata' => [
                'buyer_user_id' => (int) $lockedUser->id,
                'plan_id' => (int) $targetPlan->id,
                'period' => $period,
                'source_order_id' => (int) $sourceOrder->id,
                'pricing_snapshot' => $pricingSnapshot,
                'domain_snapshot' => $domainSnapshot,
            ],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        AgentOrderContext::query()->create([
            'order_id' => $order->id,
            'trade_no' => $order->trade_no,
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domainSnapshot['agent_domain_id'],
            'payment_id' => null,
            'sale_amount' => $payableAmount,
            'cost_amount' => $costAmount,
            'hold_id' => $hold->id,
            'status' => AgentOrderContext::STATUS_PENDING,
            'pricing_snapshot' => $pricingSnapshot,
            'domain_snapshot' => $domainSnapshot,
            'payment_snapshot' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function sourceCostBasisForAgent(Order $sourceOrder, int $agentUserId): int
    {
        try {
            $context = AgentOrderContext::query()
                ->where('order_id', $sourceOrder->id)
                ->where('agent_user_id', $agentUserId)
                ->first();
            if ($context && (int) $context->cost_amount > 0) {
                return (int) $context->cost_amount;
            }
        } catch (\Throwable) {
            // Older installations may not have agent context rows for historical source orders.
        }

        return max(0, (int) $sourceOrder->total_amount + (int) $sourceOrder->balance_amount);
    }

    private function syncAgentOwnership(User $agent, User $user): void
    {
        $now = time();
        $ownership = AgentUser::query()
            ->where('sub_user_id', $user->id)
            ->first();

        if (!$ownership) {
            AgentUser::query()->create([
                'agent_user_id' => $agent->id,
                'sub_user_id' => $user->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $user->invite_user_id = $agent->id;
            $user->updated_at = $now;
            $user->save();
            return;
        }

        if ((int) $user->invite_user_id !== (int) $ownership->agent_user_id) {
            $user->invite_user_id = (int) $ownership->agent_user_id;
            $user->updated_at = $now;
            $user->save();
        }
    }

    private function agentDomainSnapshot(array $agentContext): array
    {
        $agentDomainId = $agentContext['agent_domain_id'] ?? null;

        return [
            'source' => (string) ($agentContext['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN),
            'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
            'domain' => (string) ($agentContext['domain'] ?? ''),
            'is_primary' => (bool) ($agentContext['is_primary'] ?? false),
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

    private function isHigherPricedTarget(Order $sourceOrder, int $targetPrice, string $targetPeriod): bool
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
