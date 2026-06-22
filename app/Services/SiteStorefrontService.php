<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use Illuminate\Http\Request;

class SiteStorefrontService
{
    public function plansForRequest(Request $request, iterable $platformPlans): array
    {
        $context = app(SiteContextService::class)->resolve($request, $request->user());
        $siteId = (int) ($context['site_id'] ?? 0);
        if ($siteId <= 0) {
            return collect($platformPlans)->values()->all();
        }

        $site = Site::query()->find($siteId);
        if (!$site || $site->status !== Site::STATUS_ACTIVE) {
            return [];
        }

        $plans = collect($platformPlans)->values();
        $planIds = $plans->map(fn (Plan $plan): int => (int) $plan->id)->all();
        $prices = SitePlanPrice::query()
            ->where('site_id', $site->id)
            ->whereIn('plan_id', $planIds)
            ->where('enabled', true)
            ->get()
            ->groupBy('plan_id');

        return $plans
            ->map(function (Plan $plan) use ($site, $context, $prices): ?Plan {
                $sitePrices = $prices->get((int) $plan->id, collect());
                $salePricesForResource = [];
                $salePricesInCents = [];

                foreach ($sitePrices as $price) {
                    if (!$this->periodAvailable($plan, (string) $price->period)) {
                        continue;
                    }

                    $salePricesForResource[(string) $price->period] = ((int) $price->sale_price) / 100;
                    $salePricesInCents[(string) $price->period] = (int) $price->sale_price;
                }

                if (empty($salePricesForResource) && (bool) $site->is_default) {
                    foreach ((array) $plan->prices as $period => $platformPrice) {
                        if ($platformPrice === null || $platformPrice === '' || (float) $platformPrice <= 0) {
                            continue;
                        }

                        $salePricesForResource[(string) $period] = (float) $platformPrice;
                        $salePricesInCents[(string) $period] = OrderService::amountToCents($platformPrice);
                    }
                }

                if (empty($salePricesForResource)) {
                    return null;
                }

                $plan->setAttribute('prices', $salePricesForResource);
                $plan->setAttribute('site_context', [
                    'site_id' => (int) $site->id,
                    'site_code' => (string) $site->code,
                    'site_domain_id' => $context['site_domain_id'] !== null ? (int) $context['site_domain_id'] : null,
                    'domain' => (string) ($context['domain'] ?? ''),
                    'source' => (string) ($context['source'] ?? 'default'),
                    'is_default' => (bool) $site->is_default,
                ]);
                $plan->setAttribute('site_sale_periods', $salePricesInCents);

                return $plan;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function resolveSalePrice(int $siteId, int $planId, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $site = Site::query()->find($siteId);
        if (!$site || $site->status !== Site::STATUS_ACTIVE) {
            throw new ApiException('Site is not available');
        }

        $plan = Plan::query()->find($planId);
        if (!$plan || !$this->periodAvailable($plan, $period)) {
            throw new ApiException('Period is not available');
        }

        $platformAmount = OrderService::amountToCents($plan->prices[$period] ?? 0);
        $price = SitePlanPrice::query()
            ->where('site_id', $siteId)
            ->where('plan_id', $planId)
            ->where('period', $period)
            ->where('enabled', true)
            ->first();

        if ($price) {
            return [
                'plan_id' => $planId,
                'period' => $period,
                'sale_amount' => (int) $price->sale_price,
                'platform_plan_price' => $platformAmount,
                'pricing_snapshot' => [
                    'site_plan_price_id' => (int) $price->id,
                    'site_id' => $siteId,
                    'plan_id' => $planId,
                    'sale_price' => (int) $price->sale_price,
                    'platform_plan_price' => $platformAmount,
                    'period' => $period,
                ],
            ];
        }

        if ((bool) $site->is_default) {
            return [
                'plan_id' => $planId,
                'period' => $period,
                'sale_amount' => $platformAmount,
                'platform_plan_price' => $platformAmount,
                'pricing_snapshot' => [
                    'site_plan_price_id' => null,
                    'site_id' => $siteId,
                    'plan_id' => $planId,
                    'sale_price' => $platformAmount,
                    'platform_plan_price' => $platformAmount,
                    'period' => $period,
                    'source' => 'platform_fallback',
                ],
            ];
        }

        throw new ApiException('Site price is not available');
    }

    private function periodAvailable(Plan $plan, string $period): bool
    {
        $price = $plan->prices[$period] ?? null;

        return $price !== null && $price !== '' && (float) $price > 0;
    }
}
