<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SiteOrderContext;
use App\Models\User;
use Illuminate\Http\Request;

class SiteCommerceService
{
    public function createOrderFromRequest(
        User $user,
        Plan $plan,
        string $period,
        ?string $couponCode,
        Request $request
    ): Order {
        $siteContext = $this->contextForRequest($request, $user);
        if (!$siteContext) {
            return OrderService::createFromRequest($user, $plan, $period, $couponCode);
        }

        $period = PlanService::getPeriodKey($period);
        $pricing = app(SiteStorefrontService::class)->resolveSalePrice(
            (int) $siteContext['site_id'],
            (int) $plan->id,
            $period
        );

        return OrderService::createFromRequest($user, $plan, $period, $couponCode, $pricing, $siteContext);
    }

    public function createRechargeOrderFromRequest(
        User $user,
        int $amount,
        int $bonusAmount,
        Request $request
    ): Order {
        $siteContext = $this->contextForRequest($request, $user);

        return OrderService::createRechargeOrder($user, $amount, $bonusAmount, $siteContext);
    }

    public function availablePaymentMethodsForRequest(Request $request)
    {
        return Payment::select([
            'id',
            'name',
            'payment',
            'icon',
            'handling_fee_fixed',
            'handling_fee_percent',
            'owner_type',
            'owner_id',
            'owner_domain_id',
        ])
            ->where('enable', 1)
            ->where('owner_type', Payment::OWNER_PLATFORM)
            ->orderBy('sort', 'ASC')
            ->get();
    }

    public function assertPaymentAvailableForOrder(Order $order, Payment $payment): void
    {
        if ($this->hasAgentOrderContext($order)) {
            return;
        }

        if ($payment->owner_type !== Payment::OWNER_PLATFORM) {
            throw new ApiException('This payment method is unavailable.');
        }

        return;
    }

    public function recordOrderContext(
        Order $order,
        array $siteContext,
        array $pricing,
        ?Plan $plan,
        string $period
    ): void {
        if (!$this->hasSiteOrderContextTable() || empty($siteContext['site_id'])) {
            return;
        }

        $siteId = (int) $siteContext['site_id'];
        $siteDomainId = isset($siteContext['site_domain_id']) && $siteContext['site_domain_id'] !== null
            ? (int) $siteContext['site_domain_id']
            : null;
        $saleAmount = (int) ($pricing['sale_amount'] ?? $order->total_amount);
        $platformAmount = (int) ($pricing['platform_plan_price'] ?? $saleAmount);
        $snapshot = $pricing['pricing_snapshot'] ?? [
            'plan_id' => $plan ? (int) $plan->id : null,
            'period' => $period,
            'sale_amount' => $saleAmount,
            'platform_plan_price' => $platformAmount,
        ];

        SiteOrderContext::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'trade_no' => $order->trade_no,
                'site_id' => $siteId,
                'site_domain_id' => $siteDomainId,
                'sale_amount' => $saleAmount,
                'platform_plan_price' => $platformAmount,
                'pricing_snapshot' => $snapshot,
                'domain_snapshot' => $this->domainSnapshot($siteContext),
                'created_at' => $order->created_at ?: time(),
                'updated_at' => time(),
            ]
        );
    }

    public function contextForRequest(Request $request, ?User $user = null): ?array
    {
        if (!$this->hasSiteTenantTables()) {
            return null;
        }

        $context = app(SiteContextService::class)->resolve($request, $user);
        if (empty($context['site_id'])) {
            return null;
        }

        $domainContext = app(SiteResolver::class)->resolveRequest($request);
        if (
            !empty($domainContext['site_id'])
            && (int) $domainContext['site_id'] === (int) $context['site_id']
            && !empty($domainContext['domain'])
        ) {
            $context['domain'] = (string) $domainContext['domain'];
            $context['site_domain_id'] = $domainContext['site_domain_id'] ?? null;
            $context['source'] = (string) ($domainContext['source'] ?? $context['source']);
        }

        return $context;
    }

    public function contextForOrder(Order $order): ?array
    {
        if ($this->hasSiteOrderContextTable()) {
            $context = SiteOrderContext::query()
                ->where('order_id', $order->id)
                ->first();
            if ($context) {
                return [
                    'site_id' => (int) $context->site_id,
                    'site_domain_id' => $context->site_domain_id !== null ? (int) $context->site_domain_id : null,
                    'is_default' => (bool) data_get($context->domain_snapshot, 'is_default', false),
                    'source' => (string) data_get($context->domain_snapshot, 'source', 'order'),
                ];
            }
        }

        if ($order->site_id) {
            return [
                'site_id' => (int) $order->site_id,
                'site_domain_id' => null,
                'is_default' => false,
                'source' => 'order',
            ];
        }

        return null;
    }

    private function hasAgentOrderContext(Order $order): bool
    {
        try {
            if (!app('db')->connection()->getSchemaBuilder()->hasTable('v2_agent_order_context')) {
                return false;
            }

            return AgentOrderContext::query()->where('order_id', $order->id)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function domainSnapshot(array $context): array
    {
        return [
            'source' => (string) ($context['source'] ?? ''),
            'site_domain_id' => isset($context['site_domain_id']) && $context['site_domain_id'] !== null
                ? (int) $context['site_domain_id']
                : null,
            'domain' => (string) ($context['domain'] ?? ''),
            'is_default' => (bool) ($context['is_default'] ?? false),
        ];
    }

    private function hasSiteTenantTables(): bool
    {
        return $this->hasTable('v2_site') && $this->hasTable('v2_site_domain');
    }

    private function hasSiteOrderContextTable(): bool
    {
        return $this->hasTable('v2_site_order_context');
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
