<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

class TenantPlanCatalogService
{
    public function plansForRequest(Request $request, iterable $platformPlans, ?User $user = null): array
    {
        $resolvedUser = $user ?: $request->user();
        $resolvedUser = $resolvedUser instanceof User ? $resolvedUser : null;

        if (app(AgentCommerceContextResolver::class)->resolveRequest($request, $resolvedUser)) {
            $siteDecoratedPlans = app(SiteStorefrontService::class)->plansForRequest($request, $platformPlans);

            return app(AgentStorefrontService::class)->plansForRequest($request, $siteDecoratedPlans);
        }

        return app(SiteStorefrontService::class)->plansForRequest($request, $platformPlans);
    }

    public function decorateCurrentPlan(Request $request, Plan $plan, ?User $user = null): Plan
    {
        $plans = $this->plansForRequest($request, collect([$plan]), $user);
        if (!empty($plans) && $plans[0] instanceof Plan) {
            return $plans[0];
        }

        $plan = app(SiteStorefrontService::class)->applyDisplayNameForRequest($request, $plan);
        if (app(AgentCommerceContextResolver::class)->resolveRequest($request, $user)) {
            return app(AgentStorefrontService::class)->applyDisplayNameForRequest($request, $plan);
        }

        return $plan;
    }
}
