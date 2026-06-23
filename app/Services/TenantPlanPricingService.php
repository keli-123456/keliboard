<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class TenantPlanPricingService
{
    public function resolveForUser(User $user, Plan $plan, string $period): array
    {
        $periodKey = PlanService::getPeriodKey($period);

        $agentContext = app(AgentCommerceContextResolver::class)->resolveUser($user);
        if ($agentContext) {
            return $this->agentPrice($agentContext, $plan, $periodKey);
        }

        $siteContext = app(SiteCommerceService::class)->contextForUser($user);
        if ($siteContext) {
            return $this->sitePrice($user, $siteContext, $plan, $periodKey);
        }

        return $this->platformPrice($user, $plan, $periodKey);
    }

    public function resolveForRequest(User $user, Plan $plan, string $period, Request $request): array
    {
        $periodKey = PlanService::getPeriodKey($period);

        $agentContext = app(AgentCommerceContextResolver::class)->resolveRequest($request, $user);
        if ($agentContext) {
            return $this->agentPrice($agentContext, $plan, $periodKey);
        }

        $siteContext = app(SiteCommerceService::class)->contextForRequest($request, $user);
        if ($siteContext) {
            return $this->sitePrice($user, $siteContext, $plan, $periodKey);
        }

        return $this->platformPrice($user, $plan, $periodKey);
    }

    public function amountForUser(User $user, Plan $plan, string $period): int
    {
        return (int) $this->resolveForUser($user, $plan, $period)['sale_amount'];
    }

    private function agentPrice(array $agentContext, Plan $plan, string $periodKey): array
    {
        $sale = app(AgentStorefrontService::class)->resolveSalePrice(
            (int) $agentContext['agent_user_id'],
            (int) $plan->id,
            $periodKey
        );

        return [
            'source' => 'agent',
            'period' => $periodKey,
            'sale_amount' => max(0, (int) ($sale['sale_amount'] ?? 0)),
            'platform_plan_price' => OrderService::amountToCents($plan->prices[$periodKey] ?? 0),
            'pricing_snapshot' => $sale['pricing_snapshot'] ?? [],
            'agent_context' => $agentContext,
            'site_context' => null,
        ];
    }

    private function sitePrice(User $user, array $siteContext, Plan $plan, string $periodKey): array
    {
        $pricing = app(SiteStorefrontService::class)->resolveSalePrice(
            (int) $siteContext['site_id'],
            (int) $plan->id,
            $periodKey
        );

        $baseAmount = max(0, (int) ($pricing['sale_amount'] ?? 0));
        $discountAmount = $this->userDiscountAmount($user, $baseAmount);
        $snapshot = $pricing['pricing_snapshot'] ?? [];
        $snapshot['user_discount_amount'] = $discountAmount;

        return [
            'source' => 'site',
            'period' => $periodKey,
            'sale_amount' => max(0, $baseAmount - $discountAmount),
            'platform_plan_price' => max(0, (int) ($pricing['platform_plan_price'] ?? $baseAmount)),
            'pricing_snapshot' => $snapshot,
            'agent_context' => null,
            'site_context' => $siteContext,
        ];
    }

    private function platformPrice(User $user, Plan $plan, string $periodKey): array
    {
        $baseAmount = OrderService::amountToCents($plan->prices[$periodKey] ?? 0);
        $discountAmount = $this->userDiscountAmount($user, $baseAmount);

        return [
            'source' => 'platform',
            'period' => $periodKey,
            'sale_amount' => max(0, $baseAmount - $discountAmount),
            'platform_plan_price' => $baseAmount,
            'pricing_snapshot' => [
                'plan_id' => (int) $plan->id,
                'period' => $periodKey,
                'sale_price' => max(0, $baseAmount - $discountAmount),
                'platform_plan_price' => $baseAmount,
                'user_discount_amount' => $discountAmount,
            ],
            'agent_context' => null,
            'site_context' => null,
        ];
    }

    private function userDiscountAmount(User $user, int $baseAmount): int
    {
        if ($baseAmount <= 0 || !$user->discount) {
            return 0;
        }

        return min($baseAmount, OrderService::percentageOfAmount($baseAmount, $user->discount));
    }
}
