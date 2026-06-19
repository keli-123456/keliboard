# Agent Commerce Diagnostics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only diagnostics panel that tells agents whether domains, payments, prices, and balance are ready for storefront orders.

**Architecture:** Add a backend diagnostics service and endpoint under the existing user agent commerce controller. Add small frontend helpers and a compact diagnostics section in the existing `AgentCenterPage`, consuming the backend response without changing checkout or payment behavior.

**Tech Stack:** Laravel/PHPUnit for `keliboard`; React/Vite/Vitest/TypeScript for `keli-user`.

---

## File Structure

### `keliboard`

- Create: `app/Services/AgentCommerceDiagnosticsService.php`
  - Computes read-only diagnostics for one active agent.
  - Uses existing `AgentCommerceService::availableBalance()` and existing models.
- Modify: `app/Http/Controllers/V1/User/AgentCommerceController.php`
  - Adds `diagnostics(Request $request)`.
- Modify: `app/Http/Routes/V1/UserRoute.php`
  - Adds `GET /user/agent/commerce/diagnostics`.
- Test: `tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php`
  - Covers payment, price, balance, and overall status rules.
- Test: `tests/Unit/Http/UserAgentCommerceControllerTest.php`
  - Covers endpoint wiring.

### `keli-user`

- Modify: `src/services/agentCommerce.ts`
  - Adds diagnostics response types and API call.
- Create: `src/lib/agentDiagnostics.ts`
  - Maps diagnostic statuses to tones and extracts actionable issue summaries.
- Test: `src/lib/agentDiagnostics.test.ts`
  - Covers status aggregation/tone/issue label helpers.
- Modify: `src/pages/AgentCenterPage.tsx`
  - Fetches diagnostics with the existing agent center data.
  - Renders compact diagnostics cards and issue actions.
- Modify: `src/locales/zh/translation.json`
  - Adds diagnostics copy.

---

## Task 1: Backend Diagnostics Service

**Files:**
- Create: `app/Services/AgentCommerceDiagnosticsService.php`
- Test: `tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php`

- [ ] **Step 1: Create backend service tests**

Create `tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceDiagnosticsService;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceDiagnosticsServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createPaymentTable();
        $this->createAgentCommerceTables();
        $this->createPlanTable();
        $this->bindTestSettings([
            'agent_center_discount_percent' => 50,
            'agent_center_allowed_plan_ids' => '',
        ]);
    }

    public function test_no_enabled_payments_blocks_payment_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, 1, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['payments']['status']);
        $this->assertSame('blocked', $diagnostics['overall_status']);
    }

    public function test_payment_bound_to_inactive_domain_warns(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $domain = $this->createDomain($agent, 'pending.example.test', AgentDomain::STATUS_PENDING);
        $this->createPayment($agent, $domain->id, true);
        $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, 1, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['payments']['status']);
        $this->assertSame('warning', $diagnostics['overall_status']);
        $this->assertSame(0, $diagnostics['domains'][0]['available_payment_count']);
    }

    public function test_no_enabled_prices_blocks_price_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['prices']['status']);
        $this->assertSame('blocked', $diagnostics['overall_status']);
    }

    public function test_partially_configured_prices_warn(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('warning', $diagnostics['checks']['prices']['status']);
        $this->assertSame([Plan::PERIOD_YEARLY], $diagnostics['plans'][0]['missing_periods']);
    }

    public function test_available_balance_lower_than_minimum_cost_blocks_balance_check(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 499);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('blocked', $diagnostics['checks']['balance']['status']);
        $this->assertSame(500, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(499, $diagnostics['summary']['available_balance']);
    }

    public function test_healthy_configuration_is_ok(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame('ok', $diagnostics['overall_status']);
        $this->assertSame('ok', $diagnostics['checks']['domains']['status']);
        $this->assertSame('ok', $diagnostics['checks']['payments']['status']);
        $this->assertSame('ok', $diagnostics['checks']['prices']['status']);
        $this->assertSame('ok', $diagnostics['checks']['balance']['status']);
    }

    private function createActiveAgent(string $email, int $balance): User
    {
        $agent = User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => $balance,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createDomain(User $agent, string $domain, string $status): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => $status,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPayment(User $agent, ?int $ownerDomainId, bool $enable): Payment
    {
        return Payment::query()->create([
            'uuid' => substr(md5($agent->email . ':' . (string) $ownerDomainId), 0, 8),
            'name' => 'Agent Payment',
            'payment' => 'fake',
            'icon' => '',
            'config' => [],
            'notify_domain' => '',
            'handling_fee_fixed' => 0,
            'handling_fee_percent' => 0,
            'enable' => $enable,
            'sort' => 0,
            'owner_type' => Payment::OWNER_AGENT,
            'owner_id' => $agent->id,
            'owner_domain_id' => $ownerDomainId,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createPlan(string $name, array $prices): Plan
    {
        return Plan::query()->create([
            'name' => $name,
            'prices' => $prices,
            'transfer_enable' => 100,
            'group_id' => 1,
            'speed_limit' => 100,
            'device_limit' => 3,
            'sell' => true,
            'show' => true,
            'renew' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createAgentPrice(User $agent, int $planId, string $period, int $salePrice): AgentPlanPrice
    {
        return AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $planId,
            'period' => $period,
            'sale_price' => $salePrice,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
```

- [ ] **Step 2: Run the red backend service tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php
```

Expected: fail because `AgentCommerceDiagnosticsService` does not exist.

- [ ] **Step 3: Create `AgentCommerceDiagnosticsService`**

Create `app/Services/AgentCommerceDiagnosticsService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentPlanPrice;
use App\Models\AgentProfile;
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
        $plans = Plan::query()
            ->where('sell', true)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
        $prices = AgentPlanPrice::query()
            ->where('agent_user_id', $agent->id)
            ->where('enabled', true)
            ->get()
            ->groupBy('plan_id');

        $activeDomainIds = $domains
            ->filter(fn (AgentDomain $domain): bool => $domain->status === AgentDomain::STATUS_ACTIVE)
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

        $planDiagnostics = $this->planDiagnostics($agent, $plans, $prices);
        $minimumCost = $this->minimumCost($planDiagnostics);
        $maximumCost = $this->maximumCost($planDiagnostics);
        $availableBalance = app(AgentCommerceService::class)->availableBalance($agent);

        $checks = [
            'domains' => $this->domainCheck($domains),
            'payments' => $this->paymentCheck($enabledPayments->count(), $availablePayments->count()),
            'prices' => $this->priceCheck($planDiagnostics),
            'balance' => $this->balanceCheck($availableBalance, $minimumCost, $maximumCost),
        ];

        return [
            'overall_status' => $this->worstStatus(array_column($checks, 'status')),
            'summary' => [
                'domains_total' => $domains->count(),
                'active_domains' => count($activeDomainIds),
                'enabled_payments' => $enabledPayments->count(),
                'available_payments' => $availablePayments->count(),
                'priced_periods' => array_sum(array_map(fn (array $plan): int => count($plan['configured_periods']), $planDiagnostics)),
                'missing_price_periods' => array_sum(array_map(fn (array $plan): int => count($plan['missing_periods']), $planDiagnostics)),
                'balance' => (int) $agent->balance,
                'available_balance' => $availableBalance,
                'minimum_cost' => $minimumCost,
                'maximum_cost' => $maximumCost,
            ],
            'checks' => $checks,
            'domains' => $domains->map(fn (AgentDomain $domain): array => [
                'id' => (int) $domain->id,
                'domain' => (string) $domain->domain,
                'status' => (string) $domain->status,
                'available_payment_count' => $availablePayments
                    ->filter(fn (Payment $payment): bool => $payment->owner_domain_id === null || (int) $payment->owner_domain_id === (int) $domain->id)
                    ->count(),
                'issues' => $domain->status === AgentDomain::STATUS_ACTIVE ? [] : ['domain_not_active'],
            ])->values()->all(),
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

    private function planDiagnostics(User $agent, $plans, $prices): array
    {
        $result = [];
        foreach ($plans as $plan) {
            $agentPrices = $prices->get((int) $plan->id, collect())->keyBy('period');
            $configured = [];
            $missing = [];
            $costs = [];

            foreach ((array) $plan->prices as $period => $platformPrice) {
                if ((float) $platformPrice <= 0) {
                    continue;
                }

                $price = $agentPrices->get((string) $period);
                if ($price) {
                    $configured[] = (string) $period;
                    $costs[] = app(AgentCommerceService::class)->calculatePlatformCost($agent, $plan, (string) $period)['amount'];
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

    private function paymentCheck(int $enabledCount, int $availableCount): array
    {
        if ($enabledCount <= 0) {
            return $this->check(self::STATUS_BLOCKED, 'payments', '暂无启用收款方式', '请添加并启用至少一个代理收款方式。');
        }
        if ($availableCount <= 0) {
            return $this->check(self::STATUS_WARNING, 'payments', '收款方式不可用于当前域名', '启用的收款方式绑定到了未启用域名。');
        }

        return $this->check(self::STATUS_OK, 'payments', '收款方式正常', '当前有可用于代理站的收款方式。');
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

    private function balanceCheck(int $availableBalance, int $minimumCost, int $maximumCost): array
    {
        if ($minimumCost <= 0) {
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
        $costs = array_filter(array_map(fn (array $plan): int => (int) $plan['minimum_cost'], $plans));
        return $costs ? min($costs) : 0;
    }

    private function maximumCost(array $plans): int
    {
        $costs = array_filter(array_map(fn (array $plan): int => (int) $plan['maximum_cost'], $plans));
        return $costs ? max($costs) : 0;
    }
}
```

- [ ] **Step 4: Run backend service tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit backend service**

```bash
git add app/Services/AgentCommerceDiagnosticsService.php tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php
git commit -m "feat: add agent commerce diagnostics service"
```

---

## Task 2: Backend Endpoint

**Files:**
- Modify: `app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `app/Http/Routes/V1/UserRoute.php`
- Test: `tests/Unit/Http/UserAgentCommerceControllerTest.php`

- [ ] **Step 1: Add controller endpoint test**

Add to `tests/Unit/Http/UserAgentCommerceControllerTest.php`:

```php
public function test_commerce_diagnostics_endpoint_returns_agent_readiness(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop.example.test',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $request = $this->userRequest($agent, '/api/v1/user/agent/commerce/diagnostics', 'GET');

    $payload = $this->responsePayload(app(AgentCommerceController::class)->diagnostics($request));

    $this->assertSame('success', $payload['status']);
    $this->assertArrayHasKey('overall_status', $payload['data']);
    $this->assertArrayHasKey('checks', $payload['data']);
    $this->assertArrayHasKey('summary', $payload['data']);
}
```

- [ ] **Step 2: Run the red endpoint test**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/UserAgentCommerceControllerTest.php --filter commerce_diagnostics_endpoint
```

Expected: fail because `diagnostics()` is not defined.

- [ ] **Step 3: Add controller method**

In `app/Http/Controllers/V1/User/AgentCommerceController.php`, import:

```php
use App\Services\AgentCommerceDiagnosticsService;
```

Add:

```php
public function diagnostics(Request $request)
{
    return $this->success(app(AgentCommerceDiagnosticsService::class)->diagnose($request->user()));
}
```

- [ ] **Step 4: Add route**

In `app/Http/Routes/V1/UserRoute.php`, add near commerce summary:

```php
$router->get('/agent/commerce/diagnostics', [AgentCommerceController::class, 'diagnostics']);
```

- [ ] **Step 5: Run endpoint and service tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php tests/Unit/Http/UserAgentCommerceControllerTest.php --filter "CommerceDiagnostics|commerce_diagnostics_endpoint"
```

Expected: diagnostics service and endpoint tests pass.

- [ ] **Step 6: Commit endpoint**

```bash
git add app/Http/Controllers/V1/User/AgentCommerceController.php app/Http/Routes/V1/UserRoute.php tests/Unit/Http/UserAgentCommerceControllerTest.php
git commit -m "feat: expose agent commerce diagnostics"
```

---

## Task 3: User Frontend Diagnostics API And Helpers

**Files:**
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/services/agentCommerce.ts`
- Create: `C:/Users/Administrator/Documents/keli/keli-user/src/lib/agentDiagnostics.ts`
- Test: `C:/Users/Administrator/Documents/keli/keli-user/src/lib/agentDiagnostics.test.ts`

- [ ] **Step 1: Add diagnostics helper tests**

Create `src/lib/agentDiagnostics.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import {
  agentDiagnosticStatusTone,
  getAgentDiagnosticActionLabelKey,
  getWorstAgentDiagnosticStatus,
} from './agentDiagnostics';

describe('agent diagnostics helpers', () => {
  it('aggregates worst diagnostic status', () => {
    expect(getWorstAgentDiagnosticStatus(['ok', 'warning'])).toBe('warning');
    expect(getWorstAgentDiagnosticStatus(['ok', 'blocked'])).toBe('blocked');
    expect(getWorstAgentDiagnosticStatus([])).toBe('ok');
  });

  it('maps diagnostic statuses to tones', () => {
    expect(agentDiagnosticStatusTone('ok')).toBe('success');
    expect(agentDiagnosticStatusTone('warning')).toBe('warning');
    expect(agentDiagnosticStatusTone('blocked')).toBe('danger');
    expect(agentDiagnosticStatusTone('unknown' as any)).toBe('neutral');
  });

  it('maps issue actions to translation keys', () => {
    expect(getAgentDiagnosticActionLabelKey('domains')).toBe('agentCenter.diagnostics.actionDomains');
    expect(getAgentDiagnosticActionLabelKey('payments')).toBe('agentCenter.diagnostics.actionPayments');
    expect(getAgentDiagnosticActionLabelKey('prices')).toBe('agentCenter.diagnostics.actionPrices');
    expect(getAgentDiagnosticActionLabelKey('balance')).toBe('agentCenter.diagnostics.actionBalance');
    expect(getAgentDiagnosticActionLabelKey('other')).toBe('agentCenter.diagnostics.actionReview');
  });
});
```

- [ ] **Step 2: Run the red helper test**

Run in `C:/Users/Administrator/Documents/keli/keli-user`:

```bash
npm run test -- agentDiagnostics
```

Expected: fail because `agentDiagnostics.ts` does not exist.

- [ ] **Step 3: Add diagnostics service types and API call**

In `src/services/agentCommerce.ts`, add types:

```ts
export type AgentDiagnosticStatus = 'ok' | 'warning' | 'blocked';

export type AgentDiagnosticCheck = {
  status: AgentDiagnosticStatus;
  title: string;
  message: string;
  action: string;
};

export type AgentCommerceDiagnostics = {
  overall_status: AgentDiagnosticStatus;
  summary: {
    domains_total: number;
    active_domains: number;
    enabled_payments: number;
    available_payments: number;
    priced_periods: number;
    missing_price_periods: number;
    balance: number;
    available_balance: number;
    minimum_cost: number;
    maximum_cost: number;
  };
  checks: Record<string, AgentDiagnosticCheck>;
  domains: Array<{
    id: number;
    domain: string;
    status: string;
    available_payment_count: number;
    issues: string[];
  }>;
  plans: Array<{
    plan_id: number;
    plan_name: string;
    configured_periods: string[];
    missing_periods: string[];
    minimum_cost: number;
    maximum_cost: number;
    issues: string[];
  }>;
};
```

Add service method:

```ts
diagnostics() {
  return api.get('/user/agent/commerce/diagnostics');
}
```

- [ ] **Step 4: Add diagnostics helpers**

Create `src/lib/agentDiagnostics.ts`:

```ts
import type { AgentDiagnosticStatus } from '@/services/agentCommerce';

export const getWorstAgentDiagnosticStatus = (statuses: Array<AgentDiagnosticStatus | string | null | undefined>): AgentDiagnosticStatus => {
  if (statuses.some((status) => status === 'blocked')) return 'blocked';
  if (statuses.some((status) => status === 'warning')) return 'warning';
  return 'ok';
};

export const agentDiagnosticStatusTone = (status: AgentDiagnosticStatus | string | null | undefined): 'success' | 'warning' | 'danger' | 'neutral' => {
  if (status === 'ok') return 'success';
  if (status === 'warning') return 'warning';
  if (status === 'blocked') return 'danger';
  return 'neutral';
};

export const getAgentDiagnosticActionLabelKey = (action: string | null | undefined): string => {
  if (action === 'domains') return 'agentCenter.diagnostics.actionDomains';
  if (action === 'payments') return 'agentCenter.diagnostics.actionPayments';
  if (action === 'prices') return 'agentCenter.diagnostics.actionPrices';
  if (action === 'balance') return 'agentCenter.diagnostics.actionBalance';
  return 'agentCenter.diagnostics.actionReview';
};
```

- [ ] **Step 5: Run frontend helper tests**

Run:

```bash
npm run test -- agentDiagnostics
```

Expected: tests pass.

- [ ] **Step 6: Commit frontend API/helpers**

```bash
git add src/services/agentCommerce.ts src/lib/agentDiagnostics.ts src/lib/agentDiagnostics.test.ts
git commit -m "feat: add agent diagnostics client helpers"
```

---

## Task 4: User Frontend Diagnostics Panel

**Files:**
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/pages/AgentCenterPage.tsx`
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/locales/zh/translation.json`

- [ ] **Step 1: Add diagnostics state and load call**

In `src/pages/AgentCenterPage.tsx`, import:

```ts
import { agentDiagnosticStatusTone, getAgentDiagnosticActionLabelKey } from '@/lib/agentDiagnostics';
```

Add type import:

```ts
type AgentCommerceDiagnostics,
```

from `@/services/agentCommerce`.

Add state:

```ts
const [agentDiagnostics, setAgentDiagnostics] = useState<AgentCommerceDiagnostics | null>(null);
const [diagnosticsLoadError, setDiagnosticsLoadError] = useState('');
```

In `loadData`, add diagnostics request to the existing active-agent `Promise.allSettled` group:

```ts
agentCommerceService.diagnostics(),
```

When fulfilled:

```ts
setAgentDiagnostics((unwrapApiData(diagnosticsResp.value) || null) as AgentCommerceDiagnostics | null);
setDiagnosticsLoadError('');
```

When rejected:

```ts
setAgentDiagnostics(null);
setDiagnosticsLoadError(errorMessageFrom(diagnosticsResp.reason, t('agentCenter.diagnostics.loadFailed')));
```

- [ ] **Step 2: Add diagnostics renderer**

Add a small render block before the detailed commerce tabs:

```tsx
const renderDiagnostics = () => {
  if (!isActive) return null;

  if (diagnosticsLoadError) {
    return (
      <Card className="rounded-lg border-amber-500/30 bg-amber-500/5">
        <CardContent className="p-4 text-sm text-amber-700 dark:text-amber-300">
          {diagnosticsLoadError}
        </CardContent>
      </Card>
    );
  }

  if (!agentDiagnostics) return null;

  const checks = Object.entries(agentDiagnostics.checks || {});

  return (
    <Card className="rounded-lg border-border/70">
      <CardHeader>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <CardTitle>{t('agentCenter.diagnostics.title')}</CardTitle>
            <CardDescription>{t('agentCenter.diagnostics.description')}</CardDescription>
          </div>
          <StatusPill
            label={t(`agentCenter.diagnostics.status.${agentDiagnostics.overall_status}`)}
            tone={agentDiagnosticStatusTone(agentDiagnostics.overall_status)}
          />
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          {checks.map(([key, check]) => (
            <div key={key} className="rounded-lg border border-border/70 p-3">
              <div className="flex items-center justify-between gap-2">
                <div className="font-medium text-foreground">{check.title}</div>
                <StatusPill
                  label={t(`agentCenter.diagnostics.status.${check.status}`)}
                  tone={agentDiagnosticStatusTone(check.status)}
                />
              </div>
              <div className="mt-2 text-sm text-muted-foreground">{check.message}</div>
            </div>
          ))}
        </div>

        <div className="grid gap-3 md:grid-cols-4">
          <MetricCard
            title={t('agentCenter.diagnostics.activeDomains')}
            value={`${agentDiagnostics.summary.active_domains}/${agentDiagnostics.summary.domains_total}`}
            description={t('agentCenter.diagnostics.activeDomainsDesc')}
            icon={Globe2}
          />
          <MetricCard
            title={t('agentCenter.diagnostics.availablePayments')}
            value={`${agentDiagnostics.summary.available_payments}`}
            description={t('agentCenter.diagnostics.availablePaymentsDesc')}
            icon={CreditCard}
          />
          <MetricCard
            title={t('agentCenter.diagnostics.pricedPeriods')}
            value={`${agentDiagnostics.summary.priced_periods}`}
            description={t('agentCenter.diagnostics.pricedPeriodsDesc', {
              missing: agentDiagnostics.summary.missing_price_periods,
            })}
            icon={ReceiptText}
          />
          <MetricCard
            title={t('agentCenter.diagnostics.availableBalance')}
            value={formatAgentMoney(agentDiagnostics.summary.available_balance, currencySymbol)}
            description={t('agentCenter.diagnostics.minimumCost', {
              amount: formatAgentMoney(agentDiagnostics.summary.minimum_cost, currencySymbol),
            })}
            icon={Wallet}
          />
        </div>

        <div className="flex flex-wrap gap-2">
          {checks
            .filter(([, check]) => check.status !== 'ok')
            .map(([key, check]) => (
              <Button key={key} type="button" variant="outline" size="sm" onClick={() => setActiveCommerceTab(check.action)}>
                {t(getAgentDiagnosticActionLabelKey(check.action))}
              </Button>
            ))}
        </div>
      </CardContent>
    </Card>
  );
};
```

If the component uses a different commerce tab state name, adapt `setActiveCommerceTab(check.action)` to the existing state.

- [ ] **Step 3: Place diagnostics panel**

Place `{renderDiagnostics()}` above the commerce configuration tabs or at the top of the active agent commerce section.

- [ ] **Step 4: Add Chinese translations**

In `src/locales/zh/translation.json`, under `agentCenter`, add:

```json
"diagnostics": {
  "title": "代理诊断",
  "description": "检查域名、收款、售价和余额是否能支撑正常下单。",
  "loadFailed": "加载代理诊断失败。",
  "activeDomains": "可用域名",
  "activeDomainsDesc": "已验证并启用的代理域名",
  "availablePayments": "可用收款",
  "availablePaymentsDesc": "可在代理站下单页出现",
  "pricedPeriods": "已设售价",
  "pricedPeriodsDesc": "还有 {{missing}} 个周期未设置",
  "availableBalance": "可用余额",
  "minimumCost": "最低成本 {{amount}}",
  "actionDomains": "检查域名",
  "actionPayments": "检查收款",
  "actionPrices": "检查售价",
  "actionBalance": "查看余额",
  "actionReview": "查看配置",
  "status": {
    "ok": "正常",
    "warning": "需完善",
    "blocked": "已阻断"
  }
}
```

- [ ] **Step 5: Run frontend tests and build**

Run:

```bash
npm run test -- agentDiagnostics
npm run build
```

Expected: tests and build pass.

- [ ] **Step 6: Commit diagnostics panel**

```bash
git add src/pages/AgentCenterPage.tsx src/locales/zh/translation.json
git commit -m "feat: show agent commerce diagnostics"
```

---

## Task 5: Final Verification And Push

**Files:**
- Verification only.

- [ ] **Step 1: Run backend diagnostics tests**

If local PHP is available:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php tests/Unit/Http/UserAgentCommerceControllerTest.php --filter "CommerceDiagnostics|commerce_diagnostics_endpoint"
```

If local PHP is unavailable, run on the Linux test machine:

```bash
ssh -i ~/.ssh/codex_keli_ed25519 root@165.232.158.117 'cd /root/keliboard-test && git fetch origin feature/agent-domain-commerce && git worktree add -f /tmp/keliboard-agent-diagnostics origin/feature/agent-domain-commerce && cp -a vendor /tmp/keliboard-agent-diagnostics/vendor && cd /tmp/keliboard-agent-diagnostics && php vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php tests/Unit/Http/UserAgentCommerceControllerTest.php --filter "CommerceDiagnostics|commerce_diagnostics_endpoint"'
```

Expected: tests pass.

- [ ] **Step 2: Clean remote worktree if used**

```bash
ssh -i ~/.ssh/codex_keli_ed25519 root@165.232.158.117 'cd /root/keliboard-test && git worktree remove --force /tmp/keliboard-agent-diagnostics'
```

- [ ] **Step 3: Run frontend diagnostics tests/build**

Run in `C:/Users/Administrator/Documents/keli/keli-user`:

```bash
npm run test -- agentDiagnostics
npm run build
```

Expected: tests and build pass.

- [ ] **Step 4: Push repositories**

Run:

```bash
cd C:/Users/Administrator/Documents/keli/keliboard
git push origin feature/agent-domain-commerce
cd C:/Users/Administrator/Documents/keli/keli-user
git push origin feature/agent-domain-commerce
```

Expected: both pushes succeed.

---

## Self-Review

### Spec Coverage

- Backend read-only diagnostics endpoint is covered by Tasks 1 and 2.
- Domain, payment, price, and balance checks are covered by Task 1 tests.
- Frontend diagnostics section and action buttons are covered by Task 4.
- Frontend helper status mapping is covered by Task 3.
- No checkout, callback, or order mutation behavior is changed.

### Placeholder Scan

This plan contains concrete file paths, code snippets, commands, and expected outcomes. It avoids placeholder wording and open-ended implementation instructions.

### Type Consistency

- Backend statuses are `ok`, `warning`, and `blocked`.
- Frontend `AgentDiagnosticStatus` uses the same status strings.
- Backend actions are `domains`, `payments`, `prices`, and `balance`; frontend action labels use the same keys.
