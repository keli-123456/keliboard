<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanOverride;
use App\Models\SitePlanPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SiteStorefrontService
{
    public function listPrices(Site $site): array
    {
        $prices = SitePlanPrice::query()
            ->where('site_id', $site->id)
            ->get()
            ->keyBy(fn (SitePlanPrice $price): string => $price->plan_id . ':' . $price->period);
        $overrides = SitePlanOverride::query()
            ->where('site_id', $site->id)
            ->get()
            ->keyBy('plan_id');

        return Plan::query()
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (Plan $plan) use ($prices, $overrides): array {
                $periods = [];
                foreach ((array) $plan->prices as $period => $platformPrice) {
                    if ((float) $platformPrice <= 0) {
                        continue;
                    }

                    $key = $plan->id . ':' . $period;
                    $sitePrice = $prices->get($key);
                    $periods[] = [
                        'period' => (string) $period,
                        'platform_price' => OrderService::amountToCents($platformPrice),
                        'sale_price' => $sitePrice ? (int) $sitePrice->sale_price : null,
                        'enabled' => $sitePrice ? (bool) $sitePrice->enabled : false,
                    ];
                }

                return [
                    'plan_id' => (int) $plan->id,
                    'plan_name' => (string) $plan->name,
                    'display_name' => $this->overrideDisplayName($overrides->get((int) $plan->id), (string) $plan->name),
                    'periods' => $periods,
                ];
            })
            ->values()
            ->all();
    }

    public function savePrices(Site $site, array $items): array
    {
        if ($site->status !== Site::STATUS_ACTIVE) {
            throw new ApiException('Site is not available');
        }

        $now = time();
        foreach ($items as $item) {
            $planId = (int) ($item['plan_id'] ?? 0);
            $period = PlanService::getPeriodKey((string) ($item['period'] ?? ''));
            $salePrice = max(0, (int) ($item['sale_price'] ?? 0));

            $plan = Plan::query()->find($planId);
            if (!$plan || !$plan->sell) {
                throw new ApiException('Plan is not available');
            }
            if (!$this->periodAvailable($plan, $period)) {
                throw new ApiException('Period is not available');
            }

            SitePlanPrice::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'plan_id' => $plan->id,
                    'period' => $period,
                ],
                [
                    'sale_price' => $salePrice,
                    'enabled' => (bool) ($item['enabled'] ?? true),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        return $this->listPrices($site);
    }

    public function saveOverrides(Site $site, array $items): void
    {
        if ($site->status !== Site::STATUS_ACTIVE) {
            throw new ApiException('Site is not available');
        }

        $now = time();
        foreach ($items as $item) {
            $planId = (int) ($item['plan_id'] ?? 0);
            $plan = Plan::query()->find($planId);
            if (!$plan || !$plan->sell) {
                throw new ApiException('Plan is not available');
            }

            $displayName = $this->normalizeDisplayName($item['display_name'] ?? null);
            if ($displayName === null) {
                SitePlanOverride::query()
                    ->where('site_id', $site->id)
                    ->where('plan_id', $plan->id)
                    ->delete();
                continue;
            }

            SitePlanOverride::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'plan_id' => $plan->id,
                ],
                [
                    'display_name' => $displayName,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function plansForRequest(Request $request, iterable $platformPlans): array
    {
        $context = app(SiteContextService::class)->resolve($request, $request->user());
        $siteId = (int) ($context['site_id'] ?? 0);
        if ($siteId <= 0) {
            return collect($platformPlans)->values()->all();
        }

        $site = Site::query()
            ->where('is_default', false)
            ->find($siteId);
        if (!$site || $site->status !== Site::STATUS_ACTIVE) {
            return [];
        }

        $plans = collect($platformPlans)->values();
        $plans = $this->appendSitePricedPlans($site->id, $plans);
        $planIds = $plans->map(fn (Plan $plan): int => (int) $plan->id)->all();
        $prices = SitePlanPrice::query()
            ->where('site_id', $site->id)
            ->whereIn('plan_id', $planIds)
            ->where('enabled', true)
            ->get()
            ->groupBy('plan_id');
        $overrides = SitePlanOverride::query()
            ->where('site_id', $site->id)
            ->whereIn('plan_id', $planIds)
            ->get()
            ->keyBy('plan_id');

        return $plans
            ->map(function (Plan $plan) use ($site, $context, $prices, $overrides): ?Plan {
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

                if (empty($salePricesForResource)) {
                    return null;
                }

                $displayName = $this->overrideDisplayName($overrides->get((int) $plan->id), (string) $plan->name);

                $plan->setAttribute('prices', $salePricesForResource);
                $plan->setAttribute('platform_name', (string) $plan->name);
                $plan->setAttribute('display_name', $displayName);
                $plan->setAttribute('site_display_name', $displayName);
                $plan->setAttribute('site_context', [
                    'site_id' => (int) $site->id,
                    'site_code' => (string) $site->code,
                    'site_domain_id' => $context['site_domain_id'] !== null ? (int) $context['site_domain_id'] : null,
                    'domain' => (string) ($context['domain'] ?? ''),
                    'source' => (string) ($context['source'] ?? 'site'),
                    'is_default' => false,
                ]);
                $plan->setAttribute('site_sale_periods', $salePricesInCents);
                $plan->setAttribute('show', true);

                return $plan;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function appendSitePricedPlans(int $siteId, Collection $plans): Collection
    {
        $existingPlanIds = $plans
            ->map(fn (Plan $plan): int => (int) $plan->id)
            ->filter()
            ->values()
            ->all();

        $extraPlanIds = SitePlanPrice::query()
            ->where('site_id', $siteId)
            ->where('enabled', true)
            ->when(!empty($existingPlanIds), fn ($query) => $query->whereNotIn('plan_id', $existingPlanIds))
            ->distinct()
            ->pluck('plan_id')
            ->map(fn ($planId): int => (int) $planId)
            ->filter()
            ->values()
            ->all();

        if (empty($extraPlanIds)) {
            return $plans;
        }

        $planService = new PlanService(new Plan());
        $extraPlans = Plan::query()
            ->whereIn('id', $extraPlanIds)
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(fn (Plan $plan): bool => $planService->hasCapacity($plan));

        if ($extraPlans->isEmpty()) {
            return $plans;
        }

        return $plans
            ->concat($extraPlans)
            ->sortBy(fn (Plan $plan): string => sprintf('%010d:%010d', (int) $plan->sort, (int) $plan->id))
            ->values();
    }

    public function applyDisplayNameForRequest(Request $request, Plan $plan): Plan
    {
        $context = app(SiteContextService::class)->resolve($request, $request->user());
        $siteId = (int) ($context['site_id'] ?? 0);
        if ($siteId <= 0) {
            return $plan;
        }

        $site = Site::query()
            ->where('is_default', false)
            ->find($siteId);
        if (!$site || $site->status !== Site::STATUS_ACTIVE) {
            return $plan;
        }

        $decorated = clone $plan;
        $displayName = $this->siteDisplayName($siteId, $plan);
        $decorated->setAttribute('platform_name', (string) ($plan->getAttribute('platform_name') ?: $plan->name));
        $decorated->setAttribute('display_name', $displayName);
        $decorated->setAttribute('site_display_name', $displayName);
        $decorated->setAttribute('site_context', [
            'site_id' => (int) $site->id,
            'site_code' => (string) $site->code,
            'site_domain_id' => ($context['site_domain_id'] ?? null) !== null ? (int) $context['site_domain_id'] : null,
            'domain' => (string) ($context['domain'] ?? ''),
            'source' => (string) ($context['source'] ?? 'site'),
            'is_default' => false,
        ]);

        return $decorated;
    }

    public function resolveSalePrice(int $siteId, int $planId, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $site = Site::query()
            ->where('is_default', false)
            ->find($siteId);
        if (!$site || $site->status !== Site::STATUS_ACTIVE) {
            throw new ApiException('Site is not available');
        }

        $plan = Plan::query()->find($planId);
        if (!$plan || !$this->periodAvailable($plan, $period)) {
            throw new ApiException('Period is not available');
        }

        $platformAmount = OrderService::amountToCents($plan->prices[$period] ?? 0);
        $displayName = $this->siteDisplayName($siteId, $plan);
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
                    'display_name' => $displayName,
                    'platform_plan_name' => (string) $plan->name,
                    'sale_price' => (int) $price->sale_price,
                    'platform_plan_price' => $platformAmount,
                    'period' => $period,
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

    private function siteDisplayName(int $siteId, Plan $plan): string
    {
        $override = SitePlanOverride::query()
            ->where('site_id', $siteId)
            ->where('plan_id', $plan->id)
            ->first();

        return $this->overrideDisplayName($override, (string) $plan->name);
    }

    private function overrideDisplayName(?SitePlanOverride $override, string $fallback): string
    {
        return $this->normalizeDisplayName($override?->display_name) ?? $fallback;
    }

    private function normalizeDisplayName(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 120);
    }
}
