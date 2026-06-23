<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;

class AgentCommerceDiagnosticsService
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_BLOCKED = 'blocked';

    public function diagnose(User $agent): array
    {
        $this->assertActiveAgent($agent);

        $domains = AgentDomain::query()
            ->where('agent_user_id', $agent->id)
            ->orderByDesc('is_primary')
            ->orderBy('domain')
            ->get();
        $payments = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_id', $agent->id)
            ->get();
        $plans = (new PlanService(new Plan()))->getAvailablePlans();
        $prices = AgentPlanPrice::query()
            ->where('agent_user_id', $agent->id)
            ->where('enabled', true)
            ->get()
            ->groupBy('plan_id');
        $siteSettings = AgentSiteSetting::query()
            ->where('agent_user_id', $agent->id)
            ->get();
        $enabledSiteSettings = $siteSettings->filter(fn (AgentSiteSetting $setting): bool => (bool) $setting->enabled);
        $defaultSiteSettingEnabled = $enabledSiteSettings->contains(
            fn (AgentSiteSetting $setting): bool => $setting->agent_domain_id === null
                && $setting->setting_scope === AgentSiteSetting::SCOPE_DEFAULT
        );

        $activeDomains = $domains
            ->filter(fn (AgentDomain $domain): bool => $domain->status === AgentDomain::STATUS_ACTIVE);
        $activeDomainIds = $activeDomains
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $enabledPayments = $payments->filter(fn (Payment $payment): bool => (bool) $payment->enable);
        $availablePayments = $enabledPayments->filter(function (Payment $payment) use ($activeDomainIds): bool {
            if ($payment->owner_domain_id === null) {
                return true;
            }

            return in_array((int) $payment->owner_domain_id, $activeDomainIds, true);
        });
        $paymentContexts = $this->paymentContextDiagnostics($activeDomains, $enabledPayments);
        $availablePaymentContextCount = count(array_filter(
            $paymentContexts,
            fn (array $context): bool => $context['available_payment_count'] > 0
        ));

        $planDiagnostics = $this->planDiagnostics($plans, $prices);
        $minimumCost = $this->minimumCost($planDiagnostics);
        $maximumCost = $this->maximumCost($planDiagnostics);
        $hasConfiguredCost = $this->hasConfiguredCost($planDiagnostics);
        $pendingHoldTotal = (int) AgentBalanceHold::query()
            ->where('agent_user_id', $agent->id)
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->sum('amount');
        $availableBalance = app(AgentCommerceService::class)->availableBalance($agent);

        $checks = [
            'domains' => $this->domainCheck($domains),
            'site_settings' => $this->siteSettingCheck(
                $siteSettings->count(),
                $enabledSiteSettings->count(),
                $defaultSiteSettingEnabled
            ),
            'payments' => $this->paymentCheck(
                $enabledPayments->count(),
                count($paymentContexts),
                $availablePaymentContextCount
            ),
            'prices' => $this->priceCheck($planDiagnostics),
            'balance' => $this->balanceCheck($availableBalance, $minimumCost, $maximumCost, $hasConfiguredCost),
        ];

        return [
            'overall_status' => $this->worstStatus(array_column($checks, 'status')),
            'summary' => [
                'domains_total' => $domains->count(),
                'active_domains' => count($activeDomainIds),
                'site_settings_total' => $siteSettings->count(),
                'enabled_site_settings' => $enabledSiteSettings->count(),
                'default_site_setting_enabled' => $defaultSiteSettingEnabled,
                'enabled_payments' => $enabledPayments->count(),
                'available_payments' => $availablePayments->count(),
                'payment_contexts_total' => count($paymentContexts),
                'payment_contexts_available' => $availablePaymentContextCount,
                'priced_periods' => array_sum(array_map(
                    fn (array $plan): int => count($plan['configured_periods']),
                    $planDiagnostics
                )),
                'missing_price_periods' => array_sum(array_map(
                    fn (array $plan): int => count($plan['missing_periods']),
                    $planDiagnostics
                )),
                'balance' => (int) $agent->balance,
                'pending_hold_total' => $pendingHoldTotal,
                'available_balance' => $availableBalance,
                'minimum_cost' => $minimumCost,
                'maximum_cost' => $maximumCost,
            ],
            'checks' => $checks,
            'payment_contexts' => $paymentContexts,
            'domains' => $domains->map(function (AgentDomain $domain) use ($enabledPayments): array {
                $availablePaymentCount = $domain->status === AgentDomain::STATUS_ACTIVE
                    ? $this->availablePaymentCountForContext($enabledPayments, (int) $domain->id)
                    : 0;

                return [
                    'id' => (int) $domain->id,
                    'domain' => (string) $domain->domain,
                    'status' => (string) $domain->status,
                    'available_payment_count' => $availablePaymentCount,
                    'issues' => $this->domainIssues($domain, $availablePaymentCount),
                ];
            })->values()->all(),
            'plans' => array_values($planDiagnostics),
        ];
    }

    private function assertActiveAgent(User $agent): void
    {
        $active = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->exists();

        if (!$active) {
            throw new ApiException('Agent permission is not active');
        }
    }

    private function planDiagnostics($plans, $prices): array
    {
        $result = [];
        foreach ($plans as $plan) {
            $agentPrices = $prices->get((int) $plan->id, collect())->keyBy('period');
            $configured = [];
            $missing = [];
            $costs = [];

            foreach ((array) $plan->prices as $period => $platformPrice) {
                $period = PlanService::getPeriodKey((string) $period);
                if ((float) $platformPrice <= 0) {
                    continue;
                }

                $price = $agentPrices->get((string) $period);
                if ($price) {
                    $configured[] = (string) $period;
                    $costs[] = $this->platformCost($plan, (string) $period);
                } else {
                    $missing[] = (string) $period;
                }
            }

            $issues = [];
            if (!$configured) {
                $issues[] = 'missing_prices';
            } elseif ($missing) {
                $issues[] = 'partial_prices';
            }

            $result[(int) $plan->id] = [
                'plan_id' => (int) $plan->id,
                'plan_name' => (string) $plan->name,
                'configured_periods' => $configured,
                'missing_periods' => $missing,
                'minimum_cost' => $costs ? min($costs) : 0,
                'maximum_cost' => $costs ? max($costs) : 0,
                'issues' => $issues,
            ];
        }

        return $result;
    }

    private function platformCost(Plan $plan, string $period): int
    {
        $period = PlanService::getPeriodKey($period);
        $price = $plan->prices[$period] ?? null;
        if ($price === null || $price === '' || (float) $price < 0) {
            throw new ApiException('Period is not available');
        }

        $baseAmount = OrderService::amountToCents($price);
        $discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));

        return (int) round($baseAmount * ($discountPercent / 100));
    }

    private function domainCheck($domains): array
    {
        $active = $domains->filter(fn (AgentDomain $domain): bool => $domain->status === AgentDomain::STATUS_ACTIVE)->count();
        if ($active <= 0) {
            return $this->check(self::STATUS_BLOCKED, 'domains', '暂无可用代理域名', '请添加并验证至少一个代理域名。');
        }
        if ($active < $domains->count()) {
            return $this->check(self::STATUS_WARNING, 'domains', '存在未启用域名', '部分域名尚未验证或已停用。');
        }

        return $this->check(self::STATUS_OK, 'domains', '代理域名正常', '当前有可用代理域名。');
    }

    private function siteSettingCheck(int $total, int $enabled, bool $defaultEnabled): array
    {
        if ($enabled <= 0) {
            return $this->check(self::STATUS_WARNING, 'site_settings', '暂无启用网站设置', '请配置并启用默认网站设置，否则代理站会回退到平台默认展示。');
        }
        if (!$defaultEnabled) {
            return $this->check(self::STATUS_WARNING, 'site_settings', '缺少默认网站设置', '未单独配置的代理域名和主站访问会回退到平台默认展示。');
        }
        if ($enabled < $total) {
            return $this->check(self::STATUS_WARNING, 'site_settings', '部分网站设置未启用', '关闭的网站设置不会应用到对应代理域名。');
        }

        return $this->check(self::STATUS_OK, 'site_settings', '网站设置正常', '默认网站设置已启用，代理站展示可正常覆盖。');
    }

    private function paymentCheck(int $enabledCount, int $contextCount, int $availableContextCount): array
    {
        if ($enabledCount <= 0) {
            return $this->check(self::STATUS_BLOCKED, 'payments', '暂无启用收款方式', '请添加并启用至少一个代理收款方式。');
        }
        if ($availableContextCount <= 0) {
            return $this->check(self::STATUS_WARNING, 'payments', '收款方式不可用于当前域名', '启用的收款方式绑定到了未启用域名。');
        }
        if ($availableContextCount < $contextCount) {
            return $this->check(self::STATUS_WARNING, 'payments', '部分域名缺少收款方式', '主域名或部分代理域名缺少可用于下单的收款方式。');
        }

        return $this->check(self::STATUS_OK, 'payments', '收款方式正常', '当前有可用于代理站的收款方式。');
    }

    private function paymentContextDiagnostics($activeDomains, $enabledPayments): array
    {
        $contexts = [$this->paymentContextDiagnostic('primary', null, null, $enabledPayments)];

        foreach ($activeDomains as $domain) {
            $contexts[] = $this->paymentContextDiagnostic(
                'agent_domain',
                (int) $domain->id,
                (string) $domain->domain,
                $enabledPayments
            );
        }

        return $contexts;
    }

    private function paymentContextDiagnostic(
        string $type,
        ?int $domainId,
        ?string $domain,
        $enabledPayments
    ): array {
        $availablePaymentCount = $this->availablePaymentCountForContext($enabledPayments, $domainId);

        return [
            'type' => $type,
            'domain_id' => $domainId,
            'domain' => $domain,
            'available_payment_count' => $availablePaymentCount,
            'issues' => $availablePaymentCount > 0 ? [] : ['payment_unavailable'],
        ];
    }

    private function availablePaymentCountForContext($enabledPayments, ?int $domainId): int
    {
        return $enabledPayments
            ->filter(fn (Payment $payment): bool => $this->paymentAvailableForContext($payment, $domainId))
            ->count();
    }

    private function paymentAvailableForContext(Payment $payment, ?int $domainId): bool
    {
        if ($payment->owner_domain_id === null) {
            return true;
        }

        return $domainId !== null && (int) $payment->owner_domain_id === $domainId;
    }

    private function domainIssues(AgentDomain $domain, int $availablePaymentCount): array
    {
        $issues = $domain->status === AgentDomain::STATUS_ACTIVE ? [] : ['domain_not_active'];
        if ($domain->status === AgentDomain::STATUS_ACTIVE && $availablePaymentCount <= 0) {
            $issues[] = 'payment_unavailable';
        }

        return $issues;
    }

    private function priceCheck(array $plans): array
    {
        $configured = array_sum(array_map(fn (array $plan): int => count($plan['configured_periods']), $plans));
        $missing = array_sum(array_map(fn (array $plan): int => count($plan['missing_periods']), $plans));
        if ($configured <= 0) {
            return $this->check(self::STATUS_BLOCKED, 'prices', '暂无代理售价', '请至少为一个套餐周期设置代理售价。');
        }
        if ($missing > 0) {
            return $this->check(self::STATUS_WARNING, 'prices', '部分周期未设置售价', '未设置售价的周期不会在代理站出售。');
        }

        return $this->check(self::STATUS_OK, 'prices', '代理售价正常', '可售套餐周期均已设置代理售价。');
    }

    private function balanceCheck(
        int $availableBalance,
        int $minimumCost,
        int $maximumCost,
        bool $hasConfiguredCost
    ): array {
        if (!$hasConfiguredCost) {
            return $this->check(self::STATUS_WARNING, 'balance', '暂无可估算成本', '设置代理售价后会显示余额是否足够。');
        }
        if ($availableBalance < $minimumCost) {
            return $this->check(self::STATUS_BLOCKED, 'balance', '可用余额不足', '可用余额不足以覆盖最低套餐成本。');
        }
        if ($maximumCost > 0 && $availableBalance < $maximumCost) {
            return $this->check(self::STATUS_WARNING, 'balance', '余额只能覆盖部分套餐', '部分高价套餐可能因代理余额不足无法开通。');
        }

        return $this->check(self::STATUS_OK, 'balance', '代理余额充足', '可用余额可覆盖当前配置的代理套餐成本。');
    }

    private function check(string $status, string $action, string $title, string $message): array
    {
        return [
            'status' => $status,
            'title' => $title,
            'message' => $message,
            'action' => $action,
        ];
    }

    private function worstStatus(array $statuses): string
    {
        if (in_array(self::STATUS_BLOCKED, $statuses, true)) {
            return self::STATUS_BLOCKED;
        }
        if (in_array(self::STATUS_WARNING, $statuses, true)) {
            return self::STATUS_WARNING;
        }

        return self::STATUS_OK;
    }

    private function minimumCost(array $plans): int
    {
        $costs = array_map(
            fn (array $plan): int => (int) $plan['minimum_cost'],
            array_filter($plans, fn (array $plan): bool => count($plan['configured_periods']) > 0)
        );

        return $costs ? min($costs) : 0;
    }

    private function maximumCost(array $plans): int
    {
        $costs = array_map(
            fn (array $plan): int => (int) $plan['maximum_cost'],
            array_filter($plans, fn (array $plan): bool => count($plan['configured_periods']) > 0)
        );

        return $costs ? max($costs) : 0;
    }

    private function hasConfiguredCost(array $plans): bool
    {
        return array_sum(array_map(fn (array $plan): int => count($plan['configured_periods']), $plans)) > 0;
    }
}
