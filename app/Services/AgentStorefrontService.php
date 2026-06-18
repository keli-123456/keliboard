<?php

namespace App\Services;

use App\Exceptions\ApiException;
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

        return Plan::query()
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(function (Plan $plan) use ($prices): array {
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

    public function plansForRequest(Request $request, iterable $platformPlans): array
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
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

        return collect($platformPlans)
            ->map(function (Plan $plan) use ($context, $prices): ?Plan {
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

                $plan->setAttribute('prices', $salePricesForResource);
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

    public function resolveSalePrice(int $agentUserId, int $planId, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $plan = Plan::query()->find($planId);
        if (!$plan || !$this->periodAvailable($plan, $period)) {
            throw new ApiException('Period is not available');
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

        return [
            'plan_id' => $planId,
            'period' => $period,
            'sale_amount' => (int) $price->sale_price,
            'pricing_snapshot' => [
                'agent_plan_price_id' => (int) $price->id,
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
}
