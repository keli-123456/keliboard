<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use App\Models\User;

class AgentCostService
{
    public function resolveBase(User $agent, Plan $plan, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $platformPrice = $plan->prices[$period] ?? null;
        if ($platformPrice === null || $platformPrice === '' || (float) $platformPrice < 0) {
            throw new ApiException('Period is not available');
        }
        $platformBaseAmount = OrderService::amountToCents($platformPrice);

        $profile = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->first();
        $costSiteId = $profile?->cost_site_id !== null ? (int) $profile->cost_site_id : null;
        if ($costSiteId) {
            $sitePrice = $this->activeSitePlanPrice($costSiteId, $plan, $period);
            if ($sitePrice) {
                return [
                    'period' => $period,
                    'base_amount' => (int) $sitePrice->sale_price,
                    'platform_base_amount' => $platformBaseAmount,
                    'cost_site_id' => $costSiteId,
                    'cost_source' => 'site',
                ];
            }
        }

        return [
            'period' => $period,
            'base_amount' => $platformBaseAmount,
            'platform_base_amount' => $platformBaseAmount,
            'cost_site_id' => null,
            'cost_source' => 'platform',
        ];
    }

    public function resolveDiscounted(User $agent, Plan $plan, string $period): array
    {
        $base = $this->resolveBase($agent, $plan, $period);
        $discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));
        $amount = (int) round((int) $base['base_amount'] * ($discountPercent / 100));

        return [
            'period' => $base['period'],
            'amount' => $amount,
            'base_amount' => (int) $base['base_amount'],
            'platform_base_amount' => (int) $base['platform_base_amount'],
            'discount_percent' => $discountPercent,
            'cost_site_id' => $base['cost_site_id'],
            'cost_source' => $base['cost_source'],
        ];
    }

    private function activeSitePlanPrice(int $siteId, Plan $plan, string $period): ?SitePlanPrice
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('status', Site::STATUS_ACTIVE)
            ->where('is_default', false)
            ->first();
        if (!$site) {
            return null;
        }

        return SitePlanPrice::query()
            ->where('site_id', $site->id)
            ->where('plan_id', $plan->id)
            ->where('period', $period)
            ->where('enabled', true)
            ->first();
    }
}
