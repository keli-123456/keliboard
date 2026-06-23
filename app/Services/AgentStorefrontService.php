<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentPlanOverride;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class AgentStorefrontService
{
    public function listPrices(User $agent): array
    {
        $this->activeProfile($agent);
        $prices = AgentPlanPrice::query()
            ->where('agent_user_id', $agent->id)
            ->get()
            ->keyBy(fn (AgentPlanPrice $price): string => $price->plan_id . ':' . $price->period);
        $overrides = AgentPlanOverride::query()
            ->where('agent_user_id', $agent->id)
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
                    $agentPrice = $prices->get($key);
                    $periods[] = [
                        'period' => (string) $period,
                        'platform_price' => OrderService::amountToCents($platformPrice),
                        'sale_price' => $agentPrice ? (int) $agentPrice->sale_price : null,
                        'enabled' => $agentPrice ? (bool) $agentPrice->enabled : false,
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

    public function savePrices(User $agent, array $items): array
    {
        $this->activeProfile($agent);
        $now = time();

        foreach ($items as $item) {
            $planId = (int) ($item['plan_id'] ?? 0);
            $period = PlanService::getPeriodKey((string) ($item['period'] ?? ''));
            $salePrice = max(0, (int) ($item['sale_price'] ?? 0));

            $plan = Plan::query()->find($planId);
            if (!$plan || !$plan->sell) {
                throw new ApiException('Plan is not available');
            }
            if (!$this->planAllowed($plan)) {
                throw new ApiException('Plan is not allowed for agents');
            }
            if (!$this->periodAvailable($plan, $period)) {
                throw new ApiException('Period is not available');
            }

            AgentPlanPrice::query()->updateOrCreate(
                [
                    'agent_user_id' => $agent->id,
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

        return $this->listPrices($agent);
    }

    public function saveOverrides(User $agent, array $items): void
    {
        $this->activeProfile($agent);
        $now = time();

        foreach ($items as $item) {
            $planId = (int) ($item['plan_id'] ?? 0);
            $plan = Plan::query()->find($planId);
            if (!$plan || !$plan->sell) {
                throw new ApiException('Plan is not available');
            }
            if (!$this->planAllowed($plan)) {
                throw new ApiException('Plan is not allowed for agents');
            }

            $displayName = $this->normalizeDisplayName($item['display_name'] ?? null);
            if ($displayName === null) {
                AgentPlanOverride::query()
                    ->where('agent_user_id', $agent->id)
                    ->where('plan_id', $plan->id)
                    ->delete();
                continue;
            }

            AgentPlanOverride::query()->updateOrCreate(
                [
                    'agent_user_id' => $agent->id,
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
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user());
        if (!$context) {
            return collect($platformPlans)->values()->all();
        }

        $agentUserId = (int) $context['agent_user_id'];
        $planIds = collect($platformPlans)->map(fn (Plan $plan): int => (int) $plan->id)->all();
        $prices = AgentPlanPrice::query()
            ->where('agent_user_id', $agentUserId)
            ->whereIn('plan_id', $planIds)
            ->where('enabled', true)
            ->get()
            ->groupBy('plan_id');
        $overrides = AgentPlanOverride::query()
            ->where('agent_user_id', $agentUserId)
            ->whereIn('plan_id', $planIds)
            ->get()
            ->keyBy('plan_id');

        return collect($platformPlans)
            ->map(function (Plan $plan) use ($context, $prices, $overrides): ?Plan {
                if (!$this->planAllowed($plan)) {
                    return null;
                }

                $agentPrices = $prices->get((int) $plan->id, collect());
                $salePricesForResource = [];
                $salePricesInCents = [];

                foreach ($agentPrices as $price) {
                    if (!$this->periodAvailable($plan, (string) $price->period)) {
                        continue;
                    }
                    $salePricesForResource[(string) $price->period] = ((int) $price->sale_price) / 100;
                    $salePricesInCents[(string) $price->period] = (int) $price->sale_price;
                }

                if (empty($salePricesForResource)) {
                    return null;
                }

                $platformName = (string) ($plan->getAttribute('platform_name') ?: $plan->name);
                $siteDisplayName = $this->normalizeDisplayName($plan->getAttribute('site_display_name'));
                $fallbackDisplayName = $this->normalizeDisplayName($plan->getAttribute('display_name')) ?? (string) $plan->name;
                $agentDisplayName = $this->normalizeDisplayName($overrides->get((int) $plan->id)?->display_name);
                $displayName = $agentDisplayName ?? $fallbackDisplayName;

                $plan->setAttribute('prices', $salePricesForResource);
                $plan->setAttribute('platform_name', $platformName);
                $plan->setAttribute('display_name', $displayName);
                $plan->setAttribute('site_display_name', $siteDisplayName);
                $plan->setAttribute('agent_display_name', $agentDisplayName);
                $plan->setAttribute('agent_context', [
                    'agent_user_id' => (int) $context['agent_user_id'],
                    'agent_domain_id' => $context['agent_domain_id'] !== null ? (int) $context['agent_domain_id'] : null,
                    'domain' => (string) ($context['domain'] ?? ''),
                    'source' => (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN),
                ]);
                $plan->setAttribute('agent_sale_periods', $salePricesInCents);

                return $plan;
            })
            ->filter()
            ->values()
            ->all();
    }

    public function applyDisplayNameForRequest(Request $request, Plan $plan): Plan
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user());
        if (!$context) {
            return $plan;
        }

        $agentUserId = (int) ($context['agent_user_id'] ?? 0);
        if ($agentUserId <= 0) {
            return $plan;
        }

        $agentDisplayName = $this->normalizeDisplayName(
            AgentPlanOverride::query()
                ->where('agent_user_id', $agentUserId)
                ->where('plan_id', $plan->id)
                ->value('display_name')
        );
        $siteDisplayName = $this->normalizeDisplayName($plan->getAttribute('site_display_name'));
        $fallbackDisplayName = $this->normalizeDisplayName($plan->getAttribute('display_name')) ?? (string) $plan->name;
        $displayName = $agentDisplayName ?? $fallbackDisplayName;

        $decorated = clone $plan;
        $decorated->setAttribute('platform_name', (string) ($plan->getAttribute('platform_name') ?: $plan->name));
        $decorated->setAttribute('display_name', $displayName);
        $decorated->setAttribute('site_display_name', $siteDisplayName);
        $decorated->setAttribute('agent_display_name', $agentDisplayName);
        $decorated->setAttribute('agent_context', [
            'agent_user_id' => $agentUserId,
            'agent_domain_id' => ($context['agent_domain_id'] ?? null) !== null ? (int) $context['agent_domain_id'] : null,
            'domain' => (string) ($context['domain'] ?? ''),
            'source' => (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN),
        ]);

        return $decorated;
    }

    public function resolveSalePrice(int $agentUserId, int $planId, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $plan = Plan::query()->find($planId);
        if (!$plan || !$this->periodAvailable($plan, $period)) {
            throw new ApiException('Period is not available');
        }
        if (!$this->planAllowed($plan)) {
            throw new ApiException('Plan is not allowed for agents');
        }

        $price = AgentPlanPrice::query()
            ->where('agent_user_id', $agentUserId)
            ->where('plan_id', $planId)
            ->where('period', $period)
            ->where('enabled', true)
            ->first();
        if (!$price) {
            throw new ApiException('Agent price is not available');
        }

        $displayName = $this->agentDisplayName($agentUserId, $plan);
        return [
            'plan_id' => $planId,
            'period' => $period,
            'sale_amount' => (int) $price->sale_price,
            'pricing_snapshot' => [
                'agent_plan_price_id' => (int) $price->id,
                'plan_id' => (int) $planId,
                'display_name' => $displayName,
                'platform_plan_name' => (string) $plan->name,
                'sale_price' => (int) $price->sale_price,
                'period' => $period,
            ],
        ];
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

    private function periodAvailable(Plan $plan, string $period): bool
    {
        $price = $plan->prices[$period] ?? null;

        return $price !== null && $price !== '' && (float) $price > 0;
    }

    private function planAllowed(Plan $plan): bool
    {
        $raw = trim((string) admin_setting('agent_center_allowed_plan_ids', ''));
        if ($raw === '') {
            return true;
        }
        $ids = array_filter(array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', $raw)
        ));

        return in_array((int) $plan->id, $ids, true);
    }

    private function agentDisplayName(int $agentUserId, Plan $plan): string
    {
        $override = AgentPlanOverride::query()
            ->where('agent_user_id', $agentUserId)
            ->where('plan_id', $plan->id)
            ->first();

        return $this->overrideDisplayName($override, (string) $plan->name);
    }

    private function overrideDisplayName(?AgentPlanOverride $override, string $fallback): string
    {
        return $this->normalizeDisplayName($override?->display_name) ?? $fallback;
    }

    private function normalizeDisplayName(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, 120);
    }
}
