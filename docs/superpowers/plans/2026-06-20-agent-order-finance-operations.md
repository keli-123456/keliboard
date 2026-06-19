# Agent Order Finance Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build agent-facing and admin-facing operations views for storefront orders, balance holds, platform cost, margin, and abnormal order states.

**Architecture:** Add backend read-model services around the existing `AgentOrderContext`, `AgentBalanceHold`, `AgentLedger`, `AgentDomain`, and `Payment` records. Expose scoped user APIs and admin APIs, then wire `keli-user` and `keli-admin` UI surfaces to those stable DTOs. Keep order pricing, payment callbacks, hold capture, and ledger mutation rules unchanged.

**Tech Stack:** Laravel/PHP backend in `keliboard`, PHPUnit 11 tests, React/Vite/TypeScript frontends in `keli-user` and `keli-admin`, Vitest frontend tests.

---

## File Structure

Backend `keliboard`:

- Create `app/Services/AgentOrderStatusResolver.php`
  - Derives hold status, capture status, margin, and abnormal flags from existing order context rows.
- Create `app/Services/AgentOperationsService.php`
  - Builds agent/admin summaries, order lists, detail DTOs, and query filters.
- Create `app/Http/Controllers/V1/User/AgentOperationsController.php`
  - User-scoped operations endpoints for the current active agent.
- Create `app/Http/Controllers/V2/Admin/AgentOperationsController.php`
  - Admin global operations endpoints and safe payment/domain toggles.
- Modify `app/Http/Routes/V1/UserRoute.php`
  - Add `/agent/operations/*` user routes.
- Modify `app/Http/Routes/V2/AdminRoute.php`
  - Add `/agent/operations/*` admin routes.
- Test `tests/Unit/Services/AgentOrderStatusResolverTest.php`
- Test `tests/Unit/Services/AgentOperationsServiceTest.php`
- Test `tests/Unit/Http/UserAgentOperationsControllerTest.php`
- Test `tests/Unit/Http/AdminAgentOperationsControllerTest.php`

User frontend `keli-user`:

- Modify `src/services/agentCommerce.ts`
  - Add operation DTO types and methods.
- Modify `src/pages/AgentCenterPage.tsx`
  - Add `finance` tab with summary, filters, order table, and detail dialog.
- Modify `src/locales/zh/translation.json`
- Modify `src/locales/en/translation.json`
- Create `src/lib/agentOperations.ts`
  - Query param builder, amount display helpers, abnormal label key mapping.
- Test `src/lib/agentOperations.test.ts`

Admin frontend `keli-admin`:

- Modify `src/services/agentCommerce.ts`
  - Add operations DTO types and methods.
- Modify `src/pages/agent/AgentCommercePage.tsx`
  - Add `运营总览` tab or section.
- Modify `src/lib/agentCommerceDisplay.ts`
  - Add abnormal flag labels and operation status tone helpers.
- Modify `src/lib/agentCommerceDisplay.test.ts`
- Modify `src/locales/zh/translation.json`
- Modify `src/locales/en/translation.json`

---

### Task 1: Backend Order Status Resolver

**Files:**
- Create: `app/Services/AgentOrderStatusResolver.php`
- Test: `tests/Unit/Services/AgentOrderStatusResolverTest.php`

- [ ] **Step 1: Write failing resolver tests**

Create `tests/Unit/Services/AgentOrderStatusResolverTest.php` with these test cases:

```php
<?php

namespace Tests\Unit\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AgentOrderStatusResolver;
use Tests\TestCase;

final class AgentOrderStatusResolverTest extends TestCase
{
    public function test_derives_clean_pending_order_state(): void
    {
        $order = new Order();
        $order->id = 11;
        $order->status = 0;
        $order->total_amount = 1300;

        $hold = new AgentBalanceHold();
        $hold->id = 22;
        $hold->status = AgentBalanceHold::STATUS_PENDING;
        $hold->amount = 800;
        $hold->expires_at = time() + 600;

        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->payment_id = 33;
        $context->status = AgentOrderContext::STATUS_PENDING;
        $context->setRelation('order', $order);
        $context->setRelation('hold', $hold);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertSame('pending', $result['hold_status']);
        $this->assertSame('not_captured', $result['capture_status']);
        $this->assertSame(500, $result['margin_amount']);
        $this->assertSame([], $result['abnormal_flags']);
    }

    public function test_flags_missing_hold_for_agent_order(): void
    {
        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->status = AgentOrderContext::STATUS_PENDING;
        $context->setRelation('hold', null);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertSame('missing', $result['hold_status']);
        $this->assertContains('hold_missing', $result['abnormal_flags']);
    }

    public function test_flags_expired_pending_hold(): void
    {
        $hold = new AgentBalanceHold();
        $hold->status = AgentBalanceHold::STATUS_PENDING;
        $hold->amount = 800;
        $hold->expires_at = time() - 60;

        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->status = AgentOrderContext::STATUS_PENDING;
        $context->setRelation('hold', $hold);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertSame('expired', $result['hold_status']);
        $this->assertContains('hold_expired', $result['abnormal_flags']);
    }

    public function test_flags_hold_amount_mismatch(): void
    {
        $hold = new AgentBalanceHold();
        $hold->status = AgentBalanceHold::STATUS_PENDING;
        $hold->amount = 700;
        $hold->expires_at = time() + 600;

        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->status = AgentOrderContext::STATUS_PENDING;
        $context->setRelation('hold', $hold);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertContains('hold_amount_mismatch', $result['abnormal_flags']);
    }

    public function test_flags_disabled_payment_method(): void
    {
        $payment = new Payment();
        $payment->id = 9;
        $payment->enable = false;

        $hold = new AgentBalanceHold();
        $hold->status = AgentBalanceHold::STATUS_PENDING;
        $hold->amount = 800;
        $hold->expires_at = time() + 600;

        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->status = AgentOrderContext::STATUS_PENDING;
        $context->setRelation('hold', $hold);
        $context->setRelation('payment', $payment);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertContains('payment_disabled', $result['abnormal_flags']);
    }

    public function test_marks_captured_when_context_and_hold_are_paid(): void
    {
        $order = new Order();
        $order->status = 3;
        $order->total_amount = 1300;

        $hold = new AgentBalanceHold();
        $hold->status = AgentBalanceHold::STATUS_CAPTURED;
        $hold->amount = 800;

        $context = new AgentOrderContext();
        $context->sale_amount = 1300;
        $context->cost_amount = 800;
        $context->status = AgentOrderContext::STATUS_PAID;
        $context->setRelation('order', $order);
        $context->setRelation('hold', $hold);

        $result = app(AgentOrderStatusResolver::class)->resolve($context);

        $this->assertSame('captured', $result['hold_status']);
        $this->assertSame('captured', $result['capture_status']);
        $this->assertSame([], $result['abnormal_flags']);
    }
}
```

- [ ] **Step 2: Run resolver tests and verify failure**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOrderStatusResolverTest.php
```

Expected: failure because `App\Services\AgentOrderStatusResolver` does not exist.

- [ ] **Step 3: Implement resolver**

Create `app/Services/AgentOrderStatusResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentOrderContext;

class AgentOrderStatusResolver
{
    /**
     * @return array{hold_status:string,capture_status:string,margin_amount:int,abnormal_flags:array<int,string>}
     */
    public function resolve(AgentOrderContext $context): array
    {
        $hold = $context->hold;
        $order = $context->order;
        $payment = $context->payment;
        $flags = [];

        $saleAmount = (int) $context->sale_amount;
        $costAmount = (int) $context->cost_amount;
        $marginAmount = $saleAmount - $costAmount;

        if (!$hold) {
            $holdStatus = 'missing';
            $flags[] = 'hold_missing';
        } else {
            $holdStatus = (string) $hold->status;

            if ($hold->status === AgentBalanceHold::STATUS_PENDING && $this->isExpired($hold->expires_at)) {
                $holdStatus = 'expired';
                $flags[] = 'hold_expired';
            }

            if ((int) $hold->amount !== $costAmount) {
                $flags[] = 'hold_amount_mismatch';
            }
        }

        if ($payment && $payment->enable === false) {
            $flags[] = 'payment_disabled';
        }

        $captureStatus = 'not_captured';
        if ($hold && $hold->status === AgentBalanceHold::STATUS_CAPTURED && $context->status === AgentOrderContext::STATUS_PAID) {
            $captureStatus = 'captured';
        } elseif ($hold && $hold->status === AgentBalanceHold::STATUS_FAILED) {
            $captureStatus = 'failed';
        } elseif ($hold && $hold->status === AgentBalanceHold::STATUS_RELEASED) {
            $captureStatus = 'released';
        }

        if ($order && (int) $order->status === 3 && $captureStatus !== 'captured') {
            $flags[] = 'ledger_missing';
        }

        return [
            'hold_status' => $holdStatus,
            'capture_status' => $captureStatus,
            'margin_amount' => $marginAmount,
            'abnormal_flags' => array_values(array_unique($flags)),
        ];
    }

    private function isExpired($value): bool
    {
        if (!$value) {
            return false;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp() < time();
        }

        return (int) $value < time();
    }
}
```

- [ ] **Step 4: Run resolver tests and commit**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOrderStatusResolverTest.php
```

Expected: `OK`.

Commit:

```powershell
git add app/Services/AgentOrderStatusResolver.php tests/Unit/Services/AgentOrderStatusResolverTest.php
git commit -m "feat: derive agent order operation status"
```

---

### Task 2: Backend Operations Read Model

**Files:**
- Create: `app/Services/AgentOperationsService.php`
- Test: `tests/Unit/Services/AgentOperationsServiceTest.php`

- [ ] **Step 1: Write service tests**

Create `tests/Unit/Services/AgentOperationsServiceTest.php` with test methods that use the project’s existing SQLite test setup and model factories/helpers from nearby agent tests. The important assertions are these exact behaviors:

```php
public function test_agent_summary_reports_balance_holds_sales_cost_and_margin(): void
{
    $agent = $this->createActiveAgent('finance-agent@example.test', ['balance' => 10000]);
    $buyer = $this->createUser('buyer@example.test');
    $domain = $this->createActiveDomain($agent, 'finance.example.test');
    $payment = $this->createAgentPayment($agent, $domain);
    $paidOrder = $this->createAgentOrderContext($agent, $buyer, $domain, $payment, [
        'trade_no' => 'paid-trade',
        'order_status' => 3,
        'context_status' => 'paid',
        'sale_amount' => 1300,
        'cost_amount' => 800,
        'hold_status' => 'captured',
    ]);
    $pendingOrder = $this->createAgentOrderContext($agent, $buyer, $domain, $payment, [
        'trade_no' => 'pending-trade',
        'order_status' => 0,
        'context_status' => 'pending',
        'sale_amount' => 1500,
        'cost_amount' => 900,
        'hold_status' => 'pending',
    ]);

    $summary = app(AgentOperationsService::class)->agentSummary($agent);

    $this->assertSame(10000, $summary['balance']);
    $this->assertSame(9100, $summary['available_balance']);
    $this->assertSame(900, $summary['pending_hold_total']);
    $this->assertSame(1300, $summary['month_sales_total']);
    $this->assertSame(800, $summary['month_cost_total']);
    $this->assertSame(500, $summary['month_margin_total']);
    $this->assertSame(1, $summary['pending_order_count']);
}

public function test_agent_orders_are_scoped_to_current_agent(): void
{
    $agent = $this->createActiveAgent('agent-a@example.test');
    $other = $this->createActiveAgent('agent-b@example.test');
    $buyer = $this->createUser('buyer@example.test');
    $domain = $this->createActiveDomain($agent, 'agent-a.example.test');
    $otherDomain = $this->createActiveDomain($other, 'agent-b.example.test');
    $payment = $this->createAgentPayment($agent, $domain);
    $otherPayment = $this->createAgentPayment($other, $otherDomain);
    $this->createAgentOrderContext($agent, $buyer, $domain, $payment, ['trade_no' => 'own-trade']);
    $this->createAgentOrderContext($other, $buyer, $otherDomain, $otherPayment, ['trade_no' => 'other-trade']);

    $rows = app(AgentOperationsService::class)->agentOrders($agent, [])['data'];

    $this->assertSame(['own-trade'], array_column($rows, 'trade_no'));
}

public function test_order_filters_by_status_abnormal_keyword_domain_and_payment(): void
{
    $agent = $this->createActiveAgent('filter-agent@example.test');
    $buyer = $this->createUser('needle-buyer@example.test');
    $domain = $this->createActiveDomain($agent, 'needle.example.test');
    $payment = $this->createAgentPayment($agent, $domain);
    $this->createAgentOrderContext($agent, $buyer, $domain, $payment, [
        'trade_no' => 'needle-trade',
        'order_status' => 0,
        'context_status' => 'pending',
        'hold_status' => 'pending',
        'hold_expires_at' => time() - 60,
    ]);

    $result = app(AgentOperationsService::class)->agentOrders($agent, [
        'status' => 'pending',
        'abnormal' => '1',
        'keyword' => 'needle',
        'domain_id' => $domain->id,
        'payment_id' => $payment->id,
    ]);

    $this->assertSame(1, $result['total']);
    $this->assertSame('needle-trade', $result['data'][0]['trade_no']);
    $this->assertContains('hold_expired', $result['data'][0]['abnormal_flags']);
}
```

Use helper methods from existing tests where possible. If this test file needs local helpers, create private helpers that insert `User`, `AgentProfile`, `AgentDomain`, `Payment`, `Order`, `AgentBalanceHold`, and `AgentOrderContext` rows directly.

- [ ] **Step 2: Run service tests and verify failure**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOperationsServiceTest.php
```

Expected: failure because `AgentOperationsService` does not exist.

- [ ] **Step 3: Implement service public contract**

Create `app/Services/AgentOperationsService.php` with these public methods:

```php
<?php

namespace App\Services;

use App\Models\AgentBalanceHold;
use App\Models\AgentDomain;
use App\Models\AgentOrderContext;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class AgentOperationsService
{
    public function agentSummary(User $agent): array
    {
        return $this->summaryForAgent((int) $agent->id, (int) $agent->balance);
    }

    public function agentOrders(User $agent, array $filters): array
    {
        return $this->paginateOrders(
            $this->baseOrderQuery()->where('agent_user_id', (int) $agent->id),
            $filters
        );
    }

    public function agentOrderDetail(User $agent, string $tradeNo): array
    {
        $context = $this->baseOrderQuery()
            ->where('agent_user_id', (int) $agent->id)
            ->where('trade_no', $tradeNo)
            ->firstOrFail();

        return $this->orderPayload($context);
    }

    public function adminSummary(): array
    {
        $agentIds = AgentOrderContext::query()->distinct()->pluck('agent_user_id')->map(fn ($id) => (int) $id)->all();

        return [
            'active_agent_count' => count($agentIds),
            'pending_hold_total' => (int) AgentBalanceHold::query()->where('status', AgentBalanceHold::STATUS_PENDING)->sum('amount'),
            'abnormal_order_count' => count(array_filter($this->adminAgents([])['data'], fn (array $row): bool => (int) $row['abnormal_order_count'] > 0)),
            'insufficient_balance_agent_count' => count(array_filter($this->adminAgents([])['data'], fn (array $row): bool => (int) $row['available_balance'] <= 0 && (int) $row['pending_hold_total'] > 0)),
            'no_active_payment_agent_count' => count(array_filter($this->adminAgents([])['data'], fn (array $row): bool => (int) $row['enabled_payment_count'] === 0)),
        ];
    }

    public function adminAgents(array $filters): array
    {
        $agents = User::query()
            ->whereIn('id', AgentOrderContext::query()->select('agent_user_id')->distinct())
            ->orderByDesc('id')
            ->get();

        $rows = $agents->map(function (User $agent): array {
            $summary = $this->summaryForAgent((int) $agent->id, (int) $agent->balance);

            return array_merge($summary, [
                'agent_user_id' => (int) $agent->id,
                'agent_email' => (string) $agent->email,
                'active_domain_count' => AgentDomain::query()->where('agent_user_id', $agent->id)->where('status', AgentDomain::STATUS_ACTIVE)->count(),
                'enabled_payment_count' => Payment::query()->where('owner_type', Payment::OWNER_AGENT)->where('owner_id', $agent->id)->where('enable', true)->count(),
            ]);
        })->values()->all();

        return [
            'data' => $rows,
            'total' => count($rows),
        ];
    }

    public function adminAgentDetail(int $agentUserId): array
    {
        $agent = User::query()->findOrFail($agentUserId);

        return [
            'agent' => [
                'id' => (int) $agent->id,
                'email' => (string) $agent->email,
            ],
            'summary' => $this->summaryForAgent((int) $agent->id, (int) $agent->balance),
            'orders' => $this->agentOrders($agent, ['page_size' => 10])['data'],
        ];
    }

    public function adminOrdersForAgent(int $agentUserId, array $filters): array
    {
        return $this->paginateOrders(
            $this->baseOrderQuery()->where('agent_user_id', $agentUserId),
            $filters
        );
    }
}
```

Then add private helpers in the same file:

```php
private function baseOrderQuery(): Builder
{
    return AgentOrderContext::query()
        ->with(['agent:id,email,balance', 'domain:id,domain,status', 'hold:id,status,amount,expires_at,captured_at,released_at,metadata', 'payment:id,name,payment,enable,owner_type,owner_id,owner_domain_id', 'order.user:id,email'])
        ->orderByDesc('id');
}

private function paginateOrders(Builder $query, array $filters): array
{
    $this->applyFilters($query, $filters);

    $pageSize = max(1, min(100, (int) ($filters['page_size'] ?? 20)));
    $page = max(1, (int) ($filters['page'] ?? 1));
    /** @var LengthAwarePaginator $paginator */
    $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

    $rows = collect($paginator->items())
        ->map(fn (AgentOrderContext $context): array => $this->orderPayload($context))
        ->values()
        ->all();

    if (($filters['abnormal'] ?? '') !== '') {
        $wantAbnormal = in_array((string) $filters['abnormal'], ['1', 'true', 'yes'], true);
        $rows = array_values(array_filter($rows, fn (array $row): bool => (count($row['abnormal_flags']) > 0) === $wantAbnormal));
    }

    return [
        'data' => $rows,
        'total' => $paginator->total(),
        'page' => $paginator->currentPage(),
        'page_size' => $paginator->perPage(),
    ];
}

private function applyFilters(Builder $query, array $filters): void
{
    if (($filters['status'] ?? '') !== '') {
        $query->where('status', (string) $filters['status']);
    }
    if ((int) ($filters['domain_id'] ?? 0) > 0) {
        $query->where('agent_domain_id', (int) $filters['domain_id']);
    }
    if ((int) ($filters['payment_id'] ?? 0) > 0) {
        $query->where('payment_id', (int) $filters['payment_id']);
    }
    if (($filters['keyword'] ?? '') !== '') {
        $keyword = trim((string) $filters['keyword']);
        $query->where(function (Builder $q) use ($keyword): void {
            $q->where('trade_no', 'like', "%{$keyword}%")
                ->orWhereHas('order.user', fn (Builder $userQuery) => $userQuery->where('email', 'like', "%{$keyword}%"))
                ->orWhereHas('domain', fn (Builder $domainQuery) => $domainQuery->where('domain', 'like', "%{$keyword}%"));
        });
    }
}

private function summaryForAgent(int $agentUserId, int $balance): array
{
    $pendingHoldTotal = (int) AgentBalanceHold::query()
        ->where('agent_user_id', $agentUserId)
        ->where('status', AgentBalanceHold::STATUS_PENDING)
        ->sum('amount');

    $paid = AgentOrderContext::query()
        ->where('agent_user_id', $agentUserId)
        ->where('status', AgentOrderContext::STATUS_PAID);

    $monthStart = strtotime(date('Y-m-01 00:00:00')) ?: 0;
    $monthPaid = (clone $paid)->where('created_at', '>=', $monthStart);
    $monthSales = (int) (clone $monthPaid)->sum('sale_amount');
    $monthCost = (int) (clone $monthPaid)->sum('cost_amount');

    $orders = $this->baseOrderQuery()->where('agent_user_id', $agentUserId)->limit(200)->get();
    $abnormalCount = $orders->filter(fn (AgentOrderContext $context): bool => count(app(AgentOrderStatusResolver::class)->resolve($context)['abnormal_flags']) > 0)->count();

    return [
        'balance' => $balance,
        'available_balance' => max(0, $balance - $pendingHoldTotal),
        'pending_hold_total' => $pendingHoldTotal,
        'month_sales_total' => $monthSales,
        'month_cost_total' => $monthCost,
        'month_margin_total' => $monthSales - $monthCost,
        'pending_order_count' => AgentOrderContext::query()->where('agent_user_id', $agentUserId)->where('status', AgentOrderContext::STATUS_PENDING)->count(),
        'abnormal_order_count' => $abnormalCount,
    ];
}

private function orderPayload(AgentOrderContext $context): array
{
    $resolved = app(AgentOrderStatusResolver::class)->resolve($context);

    return [
        'trade_no' => (string) $context->trade_no,
        'buyer_user_id' => $context->order?->user_id !== null ? (int) $context->order->user_id : null,
        'buyer_email' => (string) ($context->order?->user?->email ?? ''),
        'agent_user_id' => (int) $context->agent_user_id,
        'agent_email' => (string) ($context->agent?->email ?? ''),
        'agent_domain_id' => $context->agent_domain_id !== null ? (int) $context->agent_domain_id : null,
        'domain' => (string) ($context->domain?->domain ?? ''),
        'plan_name' => (string) data_get($context->pricing_snapshot, 'plan_name', ''),
        'period' => (string) data_get($context->pricing_snapshot, 'period', $context->order?->period ?? ''),
        'sale_amount' => (int) $context->sale_amount,
        'platform_cost' => (int) $context->cost_amount,
        'margin_amount' => (int) $resolved['margin_amount'],
        'payment_id' => $context->payment_id !== null ? (int) $context->payment_id : null,
        'payment_name' => (string) ($context->payment?->name ?? ''),
        'payment_code' => (string) ($context->payment?->payment ?? ''),
        'order_status' => $context->order?->status !== null ? (int) $context->order->status : null,
        'context_status' => (string) $context->status,
        'hold_status' => (string) $resolved['hold_status'],
        'capture_status' => (string) $resolved['capture_status'],
        'abnormal_flags' => $resolved['abnormal_flags'],
        'created_at' => $context->created_at ? (int) $context->created_at : null,
        'updated_at' => $context->updated_at ? (int) $context->updated_at : null,
    ];
}
```

- [ ] **Step 4: Run service tests and commit**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOrderStatusResolverTest.php tests/Unit/Services/AgentOperationsServiceTest.php
```

Expected: `OK`.

Commit:

```powershell
git add app/Services/AgentOperationsService.php tests/Unit/Services/AgentOperationsServiceTest.php
git commit -m "feat: add agent operations read model"
```

---

### Task 3: Backend User and Admin Operations APIs

**Files:**
- Create: `app/Http/Controllers/V1/User/AgentOperationsController.php`
- Create: `app/Http/Controllers/V2/Admin/AgentOperationsController.php`
- Modify: `app/Http/Routes/V1/UserRoute.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Test: `tests/Unit/Http/UserAgentOperationsControllerTest.php`
- Test: `tests/Unit/Http/AdminAgentOperationsControllerTest.php`

- [ ] **Step 1: Write controller tests**

Create `tests/Unit/Http/UserAgentOperationsControllerTest.php`:

```php
public function test_user_summary_requires_active_agent_and_returns_finance_summary(): void
{
    $agent = $this->createActiveAgent('api-agent@example.test', ['balance' => 10000]);
    $request = $this->userRequest($agent, '/api/v1/user/agent/operations/summary', 'GET');

    $payload = $this->responsePayload(app(\App\Http\Controllers\V1\User\AgentOperationsController::class)->summary($request));

    $this->assertArrayHasKey('balance', $payload['data']);
    $this->assertArrayHasKey('available_balance', $payload['data']);
    $this->assertArrayHasKey('pending_hold_total', $payload['data']);
}

public function test_user_orders_endpoint_scopes_to_current_agent(): void
{
    $agent = $this->createActiveAgent('agent-one@example.test');
    $other = $this->createActiveAgent('agent-two@example.test');
    $this->createOperationsOrder($agent, 'own-trade');
    $this->createOperationsOrder($other, 'other-trade');

    $request = $this->userRequest($agent, '/api/v1/user/agent/operations/orders', 'GET');
    $payload = $this->responsePayload(app(\App\Http\Controllers\V1\User\AgentOperationsController::class)->orders($request));

    $this->assertSame(['own-trade'], array_column($payload['data']['data'], 'trade_no'));
}
```

Create `tests/Unit/Http/AdminAgentOperationsControllerTest.php`:

```php
public function test_admin_summary_and_agent_list_return_operations_rows(): void
{
    $agent = $this->createActiveAgent('admin-visible-agent@example.test', ['balance' => 5000]);
    $this->createOperationsOrder($agent, 'visible-trade');
    $controller = app(\App\Http\Controllers\V2\Admin\AgentOperationsController::class);

    $summary = $this->responsePayload($controller->summary($this->adminRequest('/api/v1/admin/agent/operations/summary')))['data'];
    $agents = $this->responsePayload($controller->agents($this->adminRequest('/api/v1/admin/agent/operations/agents')))['data'];

    $this->assertArrayHasKey('pending_hold_total', $summary);
    $this->assertSame('admin-visible-agent@example.test', $agents['data'][0]['agent_email']);
}

public function test_admin_can_disable_and_enable_agent_payment(): void
{
    $agent = $this->createActiveAgent('payment-toggle-agent@example.test');
    $domain = $this->createActiveDomain($agent, 'toggle.example.test');
    $payment = $this->createAgentPayment($agent, $domain, true);
    $controller = app(\App\Http\Controllers\V2\Admin\AgentOperationsController::class);

    $this->responsePayload($controller->disablePayment((int) $payment->id));
    $this->assertFalse((bool) $payment->fresh()->enable);

    $this->responsePayload($controller->enablePayment((int) $payment->id));
    $this->assertTrue((bool) $payment->fresh()->enable);
}
```

- [ ] **Step 2: Add user controller**

Create `app/Http/Controllers/V1/User/AgentOperationsController.php`:

```php
<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Services\AgentCenterService;
use App\Services\AgentOperationsService;
use Illuminate\Http\Request;

class AgentOperationsController extends Controller
{
    public function summary(Request $request)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success(app(AgentOperationsService::class)->agentSummary($request->user()));
    }

    public function orders(Request $request)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success(app(AgentOperationsService::class)->agentOrders($request->user(), $request->all()));
    }

    public function order(Request $request, string $tradeNo)
    {
        $this->assertActiveAgent((int) $request->user()->id);

        return $this->success(app(AgentOperationsService::class)->agentOrderDetail($request->user(), $tradeNo));
    }

    private function assertActiveAgent(int $agentUserId): void
    {
        $active = AgentProfile::query()
            ->where('user_id', $agentUserId)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->exists();

        if (!$active) {
            throw new ApiException('Agent permission is not active');
        }
    }
}
```

- [ ] **Step 3: Add admin controller**

Create `app/Http/Controllers/V2/Admin/AgentOperationsController.php`:

```php
<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AgentDomain;
use App\Models\Payment;
use App\Services\AgentOperationsService;
use Illuminate\Http\Request;

class AgentOperationsController extends Controller
{
    public function summary(Request $request)
    {
        return $this->success(app(AgentOperationsService::class)->adminSummary());
    }

    public function agents(Request $request)
    {
        return $this->success(app(AgentOperationsService::class)->adminAgents($request->all()));
    }

    public function agent(Request $request, int $agentUserId)
    {
        return $this->success(app(AgentOperationsService::class)->adminAgentDetail($agentUserId));
    }

    public function agentOrders(Request $request, int $agentUserId)
    {
        return $this->success(app(AgentOperationsService::class)->adminOrdersForAgent($agentUserId, $request->all()));
    }

    public function disablePayment(int $paymentId)
    {
        return $this->setPaymentEnabled($paymentId, false);
    }

    public function enablePayment(int $paymentId)
    {
        return $this->setPaymentEnabled($paymentId, true);
    }

    public function disableDomain(int $domainId)
    {
        $domain = AgentDomain::query()->find($domainId);
        if (!$domain) {
            throw new ApiException('Domain does not exist');
        }
        $domain->status = AgentDomain::STATUS_DISABLED;
        $domain->updated_at = time();
        $domain->save();

        return $this->success(true);
    }

    private function setPaymentEnabled(int $paymentId, bool $enabled)
    {
        $payment = Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->find($paymentId);
        if (!$payment) {
            throw new ApiException('Agent payment does not exist');
        }
        $payment->enable = $enabled;
        $payment->updated_at = time();
        $payment->save();

        return $this->success(true);
    }
}
```

- [ ] **Step 4: Add routes**

Modify `app/Http/Routes/V1/UserRoute.php`:

```php
use App\Http\Controllers\V1\User\AgentOperationsController;
```

Inside the authenticated user route group, add:

```php
$router->get('/agent/operations/summary', [AgentOperationsController::class, 'summary']);
$router->get('/agent/operations/orders', [AgentOperationsController::class, 'orders']);
$router->get('/agent/operations/orders/{tradeNo}', [AgentOperationsController::class, 'order']);
```

Modify `app/Http/Routes/V2/AdminRoute.php`:

```php
use App\Http\Controllers\V2\Admin\AgentOperationsController;
```

Inside the admin route group, add:

```php
$router->group(['prefix' => 'agent/operations'], function ($router) {
    $router->get('/summary', [AgentOperationsController::class, 'summary']);
    $router->get('/agents', [AgentOperationsController::class, 'agents']);
    $router->get('/agents/{agentUserId}', [AgentOperationsController::class, 'agent']);
    $router->get('/agents/{agentUserId}/orders', [AgentOperationsController::class, 'agentOrders']);
    $router->post('/payments/{paymentId}/disable', [AgentOperationsController::class, 'disablePayment']);
    $router->post('/payments/{paymentId}/enable', [AgentOperationsController::class, 'enablePayment']);
    $router->post('/domains/{domainId}/disable', [AgentOperationsController::class, 'disableDomain']);
});
```

- [ ] **Step 5: Run API tests and commit**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOrderStatusResolverTest.php tests/Unit/Services/AgentOperationsServiceTest.php tests/Unit/Http/UserAgentOperationsControllerTest.php tests/Unit/Http/AdminAgentOperationsControllerTest.php
```

Expected: `OK`.

Commit:

```powershell
git add app/Http/Controllers/V1/User/AgentOperationsController.php app/Http/Controllers/V2/Admin/AgentOperationsController.php app/Http/Routes/V1/UserRoute.php app/Http/Routes/V2/AdminRoute.php tests/Unit/Http/UserAgentOperationsControllerTest.php tests/Unit/Http/AdminAgentOperationsControllerTest.php
git commit -m "feat: expose agent operations APIs"
```

---

### Task 4: User Frontend Agent Finance Tab

**Files:**
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentOperations.ts`
- Test: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentOperations.test.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\services\agentCommerce.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\AgentCenterPage.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\zh\translation.json`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\en\translation.json`

- [ ] **Step 1: Add frontend helper tests**

Create `src/lib/agentOperations.test.ts`:

```ts
import {
  agentOperationFlagLabelKey,
  agentOperationStatusTone,
  buildAgentOperationOrderParams,
} from './agentOperations';

describe('agent operations helpers', () => {
  it('builds stable order query params', () => {
    expect(buildAgentOperationOrderParams({
      status: 'pending',
      abnormal: true,
      keyword: ' trade ',
      domainId: 12,
      paymentId: 34,
      page: 2,
      pageSize: 30,
    })).toEqual({
      status: 'pending',
      abnormal: '1',
      keyword: 'trade',
      domain_id: 12,
      payment_id: 34,
      page: 2,
      page_size: 30,
    });
  });

  it('drops empty filter values', () => {
    expect(buildAgentOperationOrderParams({
      status: 'all',
      abnormal: false,
      keyword: ' ',
      domainId: 0,
      paymentId: 0,
      page: 1,
      pageSize: 20,
    })).toEqual({
      page: 1,
      page_size: 20,
    });
  });

  it('maps abnormal flags to translation keys', () => {
    expect(agentOperationFlagLabelKey('hold_expired')).toBe('agentCenter.operations.flags.hold_expired');
    expect(agentOperationFlagLabelKey('unknown')).toBe('agentCenter.operations.flags.unknown');
  });

  it('maps operation statuses to stable tones', () => {
    expect(agentOperationStatusTone('paid')).toBe('success');
    expect(agentOperationStatusTone('captured')).toBe('success');
    expect(agentOperationStatusTone('pending')).toBe('warning');
    expect(agentOperationStatusTone('expired')).toBe('danger');
    expect(agentOperationStatusTone('anything')).toBe('neutral');
  });
});
```

- [ ] **Step 2: Implement helpers**

Create `src/lib/agentOperations.ts`:

```ts
export type AgentOperationOrderFilter = {
  status?: string;
  abnormal?: boolean;
  keyword?: string;
  domainId?: number;
  paymentId?: number;
  page?: number;
  pageSize?: number;
};

export const buildAgentOperationOrderParams = (filter: AgentOperationOrderFilter) => {
  const params: Record<string, string | number> = {
    page: Math.max(1, Number(filter.page || 1)),
    page_size: Math.max(1, Math.min(100, Number(filter.pageSize || 20))),
  };

  const status = String(filter.status || '').trim();
  if (status && status !== 'all') params.status = status;
  if (filter.abnormal) params.abnormal = '1';

  const keyword = String(filter.keyword || '').trim();
  if (keyword) params.keyword = keyword;

  if (Number(filter.domainId || 0) > 0) params.domain_id = Number(filter.domainId);
  if (Number(filter.paymentId || 0) > 0) params.payment_id = Number(filter.paymentId);

  return params;
};

export const agentOperationFlagLabelKey = (flag: string) =>
  `agentCenter.operations.flags.${flag || 'unknown'}`;

export const agentOperationStatusTone = (status: string): 'success' | 'warning' | 'danger' | 'neutral' => {
  const normalized = String(status || '').trim().toLowerCase();
  if (['paid', 'captured', 'active'].includes(normalized)) return 'success';
  if (['pending', 'not_captured'].includes(normalized)) return 'warning';
  if (['failed', 'expired', 'missing', 'released', 'cancelled', 'canceled'].includes(normalized)) return 'danger';
  return 'neutral';
};
```

- [ ] **Step 3: Extend `agentCommerceService`**

Add these types to `src/services/agentCommerce.ts`:

```ts
export type AgentOperationSummary = {
  balance: number;
  available_balance: number;
  pending_hold_total: number;
  month_sales_total: number;
  month_cost_total: number;
  month_margin_total: number;
  pending_order_count: number;
  abnormal_order_count: number;
};

export type AgentOperationOrder = {
  trade_no: string;
  buyer_user_id?: number | null;
  buyer_email?: string | null;
  agent_domain_id?: number | null;
  domain?: string | null;
  plan_name?: string | null;
  period?: string | null;
  sale_amount: number;
  platform_cost: number;
  margin_amount: number;
  payment_id?: number | null;
  payment_name?: string | null;
  payment_code?: string | null;
  order_status?: number | null;
  context_status?: string;
  hold_status?: string;
  capture_status?: string;
  abnormal_flags?: string[];
  created_at?: number | null;
  updated_at?: number | null;
};

export type AgentOperationOrderList = {
  data: AgentOperationOrder[];
  total: number;
  page: number;
  page_size: number;
};
```

Add methods:

```ts
operationsSummary() {
  return api.get('/user/agent/operations/summary');
},

operationOrders(params?: Record<string, string | number>) {
  return api.get('/user/agent/operations/orders', params ? { params } : undefined);
},

operationOrder(tradeNo: string) {
  return api.get(`/user/agent/operations/orders/${encodeURIComponent(tradeNo)}`);
},
```

- [ ] **Step 4: Add `finance` tab state and fetches to `AgentCenterPage.tsx`**

Modify `ActiveAgentTab`:

```ts
type ActiveAgentTab = 'users' | 'ledger' | 'finance' | 'domains' | 'site' | 'prices' | 'payments' | 'rules';
```

Add state:

```ts
const [operationSummary, setOperationSummary] = useState<AgentOperationSummary | null>(null);
const [operationOrders, setOperationOrders] = useState<AgentOperationOrder[]>([]);
const [operationTotal, setOperationTotal] = useState(0);
const [operationStatus, setOperationStatus] = useState('all');
const [operationKeyword, setOperationKeyword] = useState('');
const [operationAbnormalOnly, setOperationAbnormalOnly] = useState(false);
const [operationLoading, setOperationLoading] = useState(false);
const [operationDetail, setOperationDetail] = useState<AgentOperationOrder | null>(null);
```

Add loader:

```ts
const loadAgentOperations = useCallback(async () => {
  if (!isActive) return;
  setOperationLoading(true);
  try {
    const [summaryResp, ordersResp] = await Promise.all([
      agentCommerceService.operationsSummary(),
      agentCommerceService.operationOrders(buildAgentOperationOrderParams({
        status: operationStatus,
        abnormal: operationAbnormalOnly,
        keyword: operationKeyword,
        page: 1,
        pageSize: 30,
      })),
    ]);
    setOperationSummary(unwrapApiData(summaryResp) as AgentOperationSummary);
    const list = unwrapApiData(ordersResp) as AgentOperationOrderList;
    setOperationOrders(list?.data || []);
    setOperationTotal(Number(list?.total || 0));
  } catch (err: any) {
    notify.error(errorMessageFrom(err, t('agentCenter.operations.loadFailed')));
  } finally {
    setOperationLoading(false);
  }
}, [isActive, operationAbnormalOnly, operationKeyword, operationStatus, t]);
```

Call `loadAgentOperations()` after the main load completes when the agent is active, and when the finance tab is selected.

- [ ] **Step 5: Render finance tab**

Add tab trigger:

```tsx
<TabsTrigger value="finance">{t('agentCenter.operations.title')}</TabsTrigger>
```

Add tab content:

```tsx
<TabsContent value="finance">
  <Card className="rounded-lg border-border/70">
    <CardHeader className="gap-3 pb-3 md:flex-row md:items-start md:justify-between">
      <div className="min-w-0">
        <CardTitle className="text-base">{t('agentCenter.operations.title')}</CardTitle>
        <CardDescription>{t('agentCenter.operations.desc')}</CardDescription>
      </div>
      <Button type="button" variant="outline" onClick={() => void loadAgentOperations()} disabled={operationLoading}>
        <RefreshCcw className={cn('mr-2 h-4 w-4', operationLoading && 'animate-spin')} />
        {t('common.refresh')}
      </Button>
    </CardHeader>
    <CardContent className="space-y-4">
      <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
        <MetricCard title={t('agentCenter.operations.sales')} value={formatAgentMoney(operationSummary?.month_sales_total || 0, currencySymbol)} description={t('agentCenter.operations.monthScope')} icon={ReceiptText} />
        <MetricCard title={t('agentCenter.operations.cost')} value={formatAgentMoney(operationSummary?.month_cost_total || 0, currencySymbol)} description={t('agentCenter.operations.monthScope')} icon={BadgeDollarSign} />
        <MetricCard title={t('agentCenter.operations.margin')} value={formatAgentMoney(operationSummary?.month_margin_total || 0, currencySymbol)} description={t('agentCenter.operations.monthScope')} icon={Wallet} />
      </div>

      <div className="flex flex-col gap-2 md:flex-row md:items-center">
        <Input value={operationKeyword} onChange={(event) => setOperationKeyword(event.target.value)} placeholder={t('agentCenter.operations.searchPlaceholder')} className="md:w-80" />
        <Select value={operationStatus} onValueChange={setOperationStatus}>
          <SelectTrigger className="md:w-44"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t('agentCenter.operations.statusAll')}</SelectItem>
            <SelectItem value="pending">{t('agentCenter.operations.statusPending')}</SelectItem>
            <SelectItem value="paid">{t('agentCenter.operations.statusPaid')}</SelectItem>
            <SelectItem value="cancelled">{t('agentCenter.operations.statusCanceled')}</SelectItem>
            <SelectItem value="failed">{t('agentCenter.operations.statusFailed')}</SelectItem>
          </SelectContent>
        </Select>
        <Button type="button" variant={operationAbnormalOnly ? 'default' : 'outline'} onClick={() => setOperationAbnormalOnly((value) => !value)}>
          {t('agentCenter.operations.abnormalOnly')}
        </Button>
        <Button type="button" onClick={() => void loadAgentOperations()}>
          {t('agentCenter.searchUsers')}
        </Button>
      </div>

      <div className="overflow-hidden rounded-lg border border-border/70">
        <table className="w-full text-sm">
          <thead className="bg-muted/30 text-xs text-muted-foreground">
            <tr>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.operations.tradeNo')}</th>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.operations.buyer')}</th>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.domain')}</th>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.plan')}</th>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.operations.saleCostMargin')}</th>
              <th className="px-4 py-3 text-left font-medium">{t('agentCenter.statusLabel')}</th>
              <th className="w-[100px] px-4 py-3 text-right font-medium">{t('agentCenter.actions')}</th>
            </tr>
          </thead>
          <tbody className="[&>tr]:border-t [&>tr]:border-border/70">
            {operationOrders.length === 0 ? (
              <tr><td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">{operationLoading ? t('common.loading') : t('agentCenter.operations.empty')}</td></tr>
            ) : operationOrders.map((order) => (
              <tr key={order.trade_no}>
                <td className="px-4 py-3 font-mono text-xs">{order.trade_no}</td>
                <td className="px-4 py-3">{order.buyer_email || order.buyer_user_id || '-'}</td>
                <td className="px-4 py-3">{order.domain || '-'}</td>
                <td className="px-4 py-3">{order.plan_name || '-'} / {periodLabel(order.period || '')}</td>
                <td className="px-4 py-3">
                  <div>{formatAgentMoney(order.sale_amount || 0, currencySymbol)}</div>
                  <div className="text-xs text-muted-foreground">{formatAgentMoney(order.platform_cost || 0, currencySymbol)} / {formatAgentMoney(order.margin_amount || 0, currencySymbol)}</div>
                </td>
                <td className="px-4 py-3">
                  <StatusPill label={order.context_status || '-'} tone={agentOperationStatusTone(order.context_status || '')} />
                </td>
                <td className="px-4 py-3 text-right">
                  <Button type="button" size="sm" variant="outline" onClick={() => setOperationDetail(order)}>{t('order.view')}</Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </CardContent>
  </Card>
</TabsContent>
```

- [ ] **Step 6: Add translations**

Add under `agentCenter` in both locale files:

```json
"operations": {
  "title": "订单财务",
  "desc": "查看代理站订单、售价、平台成本、毛利和异常状态。",
  "loadFailed": "加载代理订单财务失败。",
  "sales": "销售额",
  "cost": "平台成本",
  "margin": "毛利",
  "monthScope": "本月已支付订单",
  "searchPlaceholder": "搜索订单号、用户邮箱、域名",
  "statusAll": "全部状态",
  "statusPending": "待支付",
  "statusPaid": "已支付",
  "statusCanceled": "已取消",
  "statusFailed": "失败",
  "abnormalOnly": "只看异常",
  "tradeNo": "订单号",
  "buyer": "买家",
  "saleCostMargin": "售价 / 成本 / 毛利",
  "empty": "暂无代理订单",
  "flags": {
    "hold_missing": "缺少预占",
    "hold_expired": "预占已过期",
    "hold_amount_mismatch": "预占金额不一致",
    "payment_disabled": "支付已停用",
    "ledger_missing": "缺少扣款流水",
    "unknown": "未知异常"
  }
}
```

Use English equivalents in `en/translation.json`.

- [ ] **Step 7: Run frontend tests/build and commit**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-user diff --check
npm run test -- agentOperations agentCommerce
npm run build
```

Expected: all pass.

Commit:

```powershell
git add src/lib/agentOperations.ts src/lib/agentOperations.test.ts src/services/agentCommerce.ts src/pages/AgentCenterPage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "feat: show agent order finance tab"
```

---

### Task 5: Admin Frontend Operations View

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\services\agentCommerce.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\pages\agent\AgentCommercePage.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\lib\agentCommerceDisplay.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\lib\agentCommerceDisplay.test.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\locales\zh\translation.json`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\locales\en\translation.json`

- [ ] **Step 1: Add display helper tests**

Extend `src/lib/agentCommerceDisplay.test.ts`:

```ts
import { agentOperationFlagLabelKey, agentOperationHealthTone } from './agentCommerceDisplay';

it('maps agent operation abnormal flags', () => {
  expect(agentOperationFlagLabelKey('hold_expired')).toBe('agent_commerce.operations.flags.hold_expired');
  expect(agentOperationFlagLabelKey('')).toBe('agent_commerce.operations.flags.unknown');
});

it('maps operation health tone', () => {
  expect(agentOperationHealthTone({ abnormal_order_count: 0, enabled_payment_count: 1, available_balance: 100 })).toBe('success');
  expect(agentOperationHealthTone({ abnormal_order_count: 1, enabled_payment_count: 1, available_balance: 100 })).toBe('danger');
  expect(agentOperationHealthTone({ abnormal_order_count: 0, enabled_payment_count: 0, available_balance: 100 })).toBe('warning');
  expect(agentOperationHealthTone({ abnormal_order_count: 0, enabled_payment_count: 1, available_balance: 0 })).toBe('warning');
});
```

- [ ] **Step 2: Implement display helpers**

Add to `src/lib/agentCommerceDisplay.ts`:

```ts
export const agentOperationFlagLabelKey = (flag: string) =>
  `agent_commerce.operations.flags.${flag || 'unknown'}`;

export const agentOperationHealthTone = (row: {
  abnormal_order_count?: number;
  enabled_payment_count?: number;
  available_balance?: number;
}): 'success' | 'warning' | 'danger' | 'neutral' => {
  if (Number(row.abnormal_order_count || 0) > 0) return 'danger';
  if (Number(row.enabled_payment_count || 0) <= 0) return 'warning';
  if (Number(row.available_balance || 0) <= 0) return 'warning';
  return 'success';
};
```

- [ ] **Step 3: Extend admin service**

Add DTO types and methods to `src/services/agentCommerce.ts`:

```ts
export type AgentOperationsAdminSummary = {
  active_agent_count: number;
  pending_hold_total: number;
  abnormal_order_count: number;
  insufficient_balance_agent_count: number;
  no_active_payment_agent_count: number;
};

export type AgentOperationsAdminAgent = {
  agent_user_id: number;
  agent_email: string;
  balance: number;
  available_balance: number;
  pending_hold_total: number;
  month_sales_total: number;
  month_cost_total: number;
  month_margin_total: number;
  pending_order_count: number;
  abnormal_order_count: number;
  active_domain_count: number;
  enabled_payment_count: number;
};

export type AgentOperationsAdminAgentList = {
  data: AgentOperationsAdminAgent[];
  total: number;
};
```

Add methods:

```ts
operationsSummary() {
  return api.get('/admin/agent/operations/summary');
},

operationsAgents(params?: Record<string, string | number>) {
  return api.get('/admin/agent/operations/agents', params ? { params } : undefined);
},

disableOperationPayment(paymentId: number) {
  return api.post(`/admin/agent/operations/payments/${paymentId}/disable`);
},

enableOperationPayment(paymentId: number) {
  return api.post(`/admin/agent/operations/payments/${paymentId}/enable`);
},
```

- [ ] **Step 4: Add operations section to `AgentCommercePage.tsx`**

Add state:

```ts
const [operationsSummary, setOperationsSummary] = useState<AgentOperationsAdminSummary | null>(null);
const [operationAgents, setOperationAgents] = useState<AgentOperationsAdminAgent[]>([]);
const [operationsLoading, setOperationsLoading] = useState(false);
```

Add loader:

```ts
const loadOperations = useCallback(async () => {
  setOperationsLoading(true);
  try {
    const [summaryResp, agentsResp] = await Promise.all([
      agentCommerceService.operationsSummary(),
      agentCommerceService.operationsAgents(),
    ]);
    setOperationsSummary(unwrapApiData(summaryResp) as AgentOperationsAdminSummary);
    const list = unwrapApiData(agentsResp) as AgentOperationsAdminAgentList;
    setOperationAgents(list?.data || []);
  } finally {
    setOperationsLoading(false);
  }
}, []);
```

Render an `运营总览` tab or top section:

```tsx
<TabsTrigger value="operations">{t('agent_commerce.operations.title')}</TabsTrigger>
```

```tsx
<TabsContent value="operations">
  <div className="grid gap-3 md:grid-cols-5">
    <SummaryCard title={t('agent_commerce.operations.active_agents')} value={String(operationsSummary?.active_agent_count || 0)} />
    <SummaryCard title={t('agent_commerce.operations.pending_holds')} value={formatMoney(operationsSummary?.pending_hold_total || 0)} />
    <SummaryCard title={t('agent_commerce.operations.abnormal_orders')} value={String(operationsSummary?.abnormal_order_count || 0)} />
    <SummaryCard title={t('agent_commerce.operations.insufficient_balance')} value={String(operationsSummary?.insufficient_balance_agent_count || 0)} />
    <SummaryCard title={t('agent_commerce.operations.no_payment')} value={String(operationsSummary?.no_active_payment_agent_count || 0)} />
  </div>
  <DataTable
    rows={operationAgents}
    columns={[
      { key: 'agent_email', title: t('agent_commerce.operations.agent') },
      { key: 'available_balance', title: t('agent_commerce.operations.available_balance'), render: (row) => formatMoney(row.available_balance) },
      { key: 'pending_hold_total', title: t('agent_commerce.operations.pending_holds'), render: (row) => formatMoney(row.pending_hold_total) },
      { key: 'month_margin_total', title: t('agent_commerce.operations.margin'), render: (row) => formatMoney(row.month_margin_total) },
      { key: 'abnormal_order_count', title: t('agent_commerce.operations.abnormal_orders') },
    ]}
    loading={operationsLoading}
    emptyText={t('agent_commerce.operations.empty_agents')}
  />
</TabsContent>
```

If `AgentCommercePage.tsx` uses plain tables instead of `DataTable`, keep the existing page style and render an equivalent table with the same columns.

- [ ] **Step 5: Add translations**

Add under `agent_commerce`:

```json
"operations": {
  "title": "运营总览",
  "active_agents": "活跃代理",
  "pending_holds": "预占金额",
  "abnormal_orders": "异常订单",
  "insufficient_balance": "余额不足代理",
  "no_payment": "无可用支付",
  "agent": "代理",
  "available_balance": "可用余额",
  "margin": "本月毛利",
  "empty_agents": "暂无代理运营数据",
  "flags": {
    "hold_missing": "缺少预占",
    "hold_expired": "预占已过期",
    "hold_amount_mismatch": "预占金额不一致",
    "payment_disabled": "支付已停用",
    "ledger_missing": "缺少扣款流水",
    "unknown": "未知异常"
  }
}
```

Use English equivalents in `en/translation.json`.

- [ ] **Step 6: Run admin frontend tests/build and commit**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-admin diff --check
npm run test -- agentCommerceDisplay
npm run build
```

Expected: all pass.

Commit:

```powershell
git add src/services/agentCommerce.ts src/pages/agent/AgentCommercePage.tsx src/lib/agentCommerceDisplay.ts src/lib/agentCommerceDisplay.test.ts src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "feat: show admin agent operations overview"
```

---

### Task 6: End-to-End Verification and Push

**Files:**
- No new source files unless tests reveal a defect.

- [ ] **Step 1: Run focused backend verification**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentOrderStatusResolverTest.php tests/Unit/Services/AgentOperationsServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php tests/Unit/Http/UserAgentOperationsControllerTest.php tests/Unit/Http/AdminAgentOperationsControllerTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php
```

Expected: `OK`.

- [ ] **Step 2: Run user frontend verification**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-user diff --check
npm run test -- agentOperations agentCommerce agentCommerceErrors agent
npm run build
```

Expected: all pass.

- [ ] **Step 3: Run admin frontend verification**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-admin diff --check
npm run test -- agentCommerceDisplay
npm run build
```

Expected: all pass.

- [ ] **Step 4: Inspect final git status**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard status --short --branch
git -C C:\Users\Administrator\Documents\keli\keli-user status --short --branch
git -C C:\Users\Administrator\Documents\keli\keli-admin status --short --branch
```

Expected:

- `keliboard` clean or only ahead of origin.
- `keli-user` clean except pre-existing untracked `design-audits/`, `dev_server.err.log`, and `dev_server.out.log`.
- `keli-admin` clean or only ahead of origin.

- [ ] **Step 5: Push branches**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard push
git -C C:\Users\Administrator\Documents\keli\keli-user push
git -C C:\Users\Administrator\Documents\keli\keli-admin push
```

Expected: all push successfully.

---

## Self-Review

- Spec coverage: This plan covers agent order/finance visibility, admin operations overview, abnormal state derivation, safe admin toggles, scoped APIs, frontend display, tests, and final push.
- Scope kept: Withdrawals, commission payout, independent agent site builder, and payment plugin installation are intentionally excluded.
- Type consistency: Backend DTO fields use `sale_amount`, `platform_cost`, `margin_amount`, `hold_status`, `capture_status`, and `abnormal_flags`; frontend types use the same names.
- Execution granularity: Each task can be tested and committed independently.
