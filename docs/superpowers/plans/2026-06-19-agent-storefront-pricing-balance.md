# Agent Storefront Pricing Balance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete and verify the agent storefront flow where agents set per-plan, per-period sale prices and orders only open after agent balance covers platform settlement cost.

**Architecture:** Keep the existing `AgentStorefrontService`, `AgentCommerceService`, `AgentBalanceHold`, and `AgentOrderContext` architecture. Add focused backend tests first, patch missing snapshot/diagnostic/API fields only where tests expose gaps, then tighten the `keli-user` pricing and balance summary UI without changing platform-domain checkout behavior.

**Tech Stack:** Laravel/PHPUnit with the existing in-memory test helpers for `keliboard`; React + TypeScript + Vitest + Vite for `keli-user`.

---

## File Structure

- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentCommerceServiceTest.php`
  - Adds service-level assertions for pricing snapshots, sale/cost independence, and zero-cost agent orders.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\AgentDomainOrderFlowTest.php`
  - Adds end-to-end-ish assertions for checkout/callback/cancel behavior already routed through controllers.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentCommerceDiagnosticsServiceTest.php`
  - Adds diagnostics assertions for pending holds and available balance summary fields.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentCommerceDiagnosticsService.php`
  - Adds missing summary fields only if tests show they are absent or calculated incorrectly.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentCommerceService.php`
  - Fixes any discovered balance, snapshot, hold, or failure-state gaps.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentStorefrontService.php`
  - Fixes any discovered price list or snapshot gaps.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentCommerce.test.ts`
  - Extends frontend unit tests for cents/yuan conversion and agent-aware price display helpers.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.ts`
  - Fixes helper behavior only if tests expose platform/agent price leakage.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\services\agentCommerce.ts`
  - Adds typed summary fields if backend exposes them.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\AgentCenterPage.tsx`
  - Tightens price table display and adds compact commerce health summary.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\StorePage.tsx`
  - Ensures agent storefront prices are displayed without platform price leakage.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\PurchasePage.tsx`
  - Ensures agent checkout errors map to existing localized messages.

---

### Task 1: Backend Snapshot and Balance Contract Tests

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentCommerceServiceTest.php`

- [ ] **Step 1: Add service tests for snapshot completeness and sale/cost independence**

Append these tests before the private helper methods in `AgentCommerceServiceTest.php`:

```php
    public function test_agent_order_pricing_snapshot_contains_sale_and_platform_cost_contract(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 5000);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [
            Plan::PERIOD_MONTHLY => 10.00,
            Plan::PERIOD_YEARLY => 100.00,
        ]);
        $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($context);
        $snapshot = $context->pricing_snapshot;

        $this->assertSame(1300, (int) $order->total_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(500, (int) $context->cost_amount);
        $this->assertSame($price->id, (int) $snapshot['agent_plan_price_id']);
        $this->assertSame($plan->id, (int) $snapshot['plan_id']);
        $this->assertSame(Plan::PERIOD_MONTHLY, $snapshot['period']);
        $this->assertSame(1300, (int) $snapshot['sale_price']);
        $this->assertSame(1000, (int) $snapshot['platform_base_amount']);
        $this->assertSame(500, (int) $snapshot['cost_amount']);
        $this->assertSame(50.0, (float) $snapshot['discount_percent']);
    }

    public function test_zero_discount_agent_order_creates_zero_amount_hold_without_requiring_balance(): void
    {
        $this->bindTestSettings([
            'agent_center_discount_percent' => 0,
            'invite_commission' => 10,
            'commission_first_time_enable' => 1,
            'plan_change_enable' => 1,
        ]);

        $agent = $this->createActiveAgent('agent@example.test', 0);
        $this->assignDomain($agent, 'agent.example.test');
        $buyer = $this->createUser('buyer@example.test');
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

        $order = app(AgentCommerceService::class)->createOrderFromRequest(
            $buyer,
            $plan,
            Plan::PERIOD_MONTHLY,
            null,
            $this->requestForHost('agent.example.test')
        );

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertNotNull($hold);
        $this->assertNotNull($context);
        $this->assertSame(0, (int) $hold->amount);
        $this->assertSame(0, (int) $context->cost_amount);
        $this->assertSame(1300, (int) $context->sale_amount);
        $this->assertSame(0, (int) $context->pricing_snapshot['cost_amount']);
    }
```

- [ ] **Step 2: Run the focused service test**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php
```

Expected: either PASS if current implementation already satisfies the contract, or FAIL with missing snapshot keys / zero-cost handling.

- [ ] **Step 3: Patch snapshot generation if the test fails**

If `plan_id` is missing from `pricing_snapshot`, update `AgentStorefrontService::resolveSalePrice()` so the returned snapshot includes it:

```php
        return [
            'plan_id' => $planId,
            'period' => $period,
            'sale_amount' => (int) $price->sale_price,
            'pricing_snapshot' => [
                'agent_plan_price_id' => (int) $price->id,
                'plan_id' => (int) $planId,
                'sale_price' => (int) $price->sale_price,
                'period' => $period,
            ],
        ];
```

If zero-cost holds fail, keep `AgentCommerceService::createOrderFromRequest()` using `availableBalance($lockedAgent) < cost_amount`; do not change the comparator to `<=`.

- [ ] **Step 4: Re-run the focused service test**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit backend contract test/fix**

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard add tests/Unit/Services/AgentCommerceServiceTest.php app/Services/AgentStorefrontService.php app/Services/AgentCommerceService.php
git -C C:\Users\Administrator\Documents\keli\keliboard commit -m "test: cover agent storefront pricing snapshots"
```

---

### Task 2: Backend Checkout, Callback, and Hold Lifecycle Tests

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\AgentDomainOrderFlowTest.php`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentCommerceService.php`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Controllers\V1\Guest\PaymentController.php`

- [ ] **Step 1: Add a test that callback writes agent cost ledger**

In `AgentDomainOrderFlowTest.php`, add `use App\Models\AgentLedger;` near the other model imports.

Append this test before the private helper methods:

```php
    public function test_payment_callback_writes_agent_cost_ledger_with_snapshot_amounts(): void
    {
        [$agent, , $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $order->payment_id = $payment->id;
        $order->save();

        $this->assertTrue($this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-ledger',
            'paid_amount' => 1300,
        ], $this->paymentServiceWithId($payment->id)));

        $ledger = AgentLedger::query()
            ->where('agent_user_id', $agent->id)
            ->where('type', AgentCommerceService::LEDGER_AGENT_ORDER_COST)
            ->first();

        $this->assertNotNull($ledger);
        $this->assertSame(-500, (int) $ledger->amount);
        $this->assertSame(10000, (int) $ledger->balance_before);
        $this->assertSame(9500, (int) $ledger->balance_after);
        $this->assertSame($order->trade_no, $ledger->metadata['trade_no']);
        $this->assertSame(1300, (int) $ledger->metadata['sale_amount']);
        $this->assertSame(500, (int) $ledger->metadata['cost_amount']);
    }
```

- [ ] **Step 2: Add a test that amount mismatch leaves hold pending**

Append this test before the private helper methods:

```php
    public function test_payment_callback_amount_mismatch_does_not_capture_or_fail_hold(): void
    {
        [$agent, , $order] = $this->createAgentOrderFixture();
        $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
        $order->payment_id = $payment->id;
        $order->save();

        $handled = $this->invokePaymentHandle([
            'trade_no' => $order->trade_no,
            'callback_no' => 'gateway-wrong-amount',
            'paid_amount' => 1299,
        ], $this->paymentServiceWithId($payment->id));

        $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
        $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

        $this->assertFalse($handled);
        $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $this->assertSame(10000, (int) $agent->fresh()->balance);
        $this->assertSame(AgentBalanceHold::STATUS_PENDING, $hold->fresh()->status);
        $this->assertSame(AgentOrderContext::STATUS_PENDING, $context->fresh()->status);
    }
```

- [ ] **Step 3: Run the focused HTTP flow test**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php
```

Expected: either PASS if current implementation already satisfies the flow, or FAIL with missing ledger table/helper setup or lifecycle behavior.

- [ ] **Step 4: Patch lifecycle behavior only if tests fail**

If the ledger table is missing in the test database, add the table setup by extending `InteractsWithInMemoryDatabase` usage only if an existing helper already exists. If no helper exists, add a private method to `AgentDomainOrderFlowTest.php`:

```php
    private function createAgentLedgerTable(): void
    {
        DB::connection()->getSchemaBuilder()->create('v2_agent_ledger', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('agent_user_id');
            $table->unsignedInteger('target_user_id')->nullable();
            $table->string('type', 64);
            $table->integer('amount')->default(0);
            $table->integer('balance_before')->default(0);
            $table->integer('balance_after')->default(0);
            $table->unsignedInteger('plan_id')->nullable();
            $table->string('period', 32)->nullable();
            $table->json('metadata')->nullable();
            $table->integer('created_at')->nullable();
        });
    }
```

Call it in `setUp()` after `createAgentCommerceTables()` only if the shared helper does not already create it.

Do not mark holds failed for amount mismatch; keep them pending so the order can be cancelled or retried.

- [ ] **Step 5: Re-run the focused HTTP flow test**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit checkout/callback coverage**

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard add tests/Unit/Http/AgentDomainOrderFlowTest.php app/Services/AgentCommerceService.php app/Http/Controllers/V1/Guest/PaymentController.php tests/Support/InteractsWithInMemoryDatabase.php
git -C C:\Users\Administrator\Documents\keli\keliboard commit -m "test: cover agent storefront payment lifecycle"
```

---

### Task 3: Backend Commerce Summary Fields

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentCommerceDiagnosticsServiceTest.php`
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentCommerceDiagnosticsService.php`

- [ ] **Step 1: Add diagnostics test for pending hold total**

Add imports if missing:

```php
use App\Models\AgentBalanceHold;
```

Append this test before private helper methods:

```php
    public function test_diagnostics_summary_reports_pending_holds_and_available_balance(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 2000);
        $this->createDomain($agent, 'shop.example.test', AgentDomain::STATUS_ACTIVE);
        $this->createPayment($agent, null, true);
        $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
        $this->createAgentPrice($agent, $plan->id, Plan::PERIOD_MONTHLY, 1300);

        AgentBalanceHold::query()->create([
            'agent_user_id' => $agent->id,
            'order_id' => 123,
            'trade_no' => 'pending-hold',
            'amount' => 700,
            'status' => AgentBalanceHold::STATUS_PENDING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $diagnostics = app(AgentCommerceDiagnosticsService::class)->diagnose($agent);

        $this->assertSame(2000, $diagnostics['summary']['balance']);
        $this->assertSame(700, $diagnostics['summary']['pending_hold_total']);
        $this->assertSame(1300, $diagnostics['summary']['available_balance']);
        $this->assertSame(500, $diagnostics['summary']['minimum_cost']);
        $this->assertSame(500, $diagnostics['summary']['maximum_cost']);
    }
```

- [ ] **Step 2: Run diagnostics test to verify behavior**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php
```

Expected: FAIL if `pending_hold_total` is missing, otherwise PASS.

- [ ] **Step 3: Add `pending_hold_total` to diagnostics summary if missing**

In `AgentCommerceDiagnosticsService::diagnose()`, compute:

```php
$pendingHoldTotal = (int) AgentBalanceHold::query()
    ->where('agent_user_id', $agent->id)
    ->where('status', AgentBalanceHold::STATUS_PENDING)
    ->sum('amount');
```

Ensure the returned `summary` includes:

```php
'balance' => (int) $agent->balance,
'pending_hold_total' => $pendingHoldTotal,
'available_balance' => max(0, (int) $agent->balance - $pendingHoldTotal),
```

If `available_balance` already uses `AgentCommerceService::availableBalance($agent)`, keep that as the source and add only `pending_hold_total`.

- [ ] **Step 4: Re-run diagnostics test**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit diagnostics summary**

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard add tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php app/Services/AgentCommerceDiagnosticsService.php
git -C C:\Users\Administrator\Documents\keli\keliboard commit -m "feat: expose agent storefront balance summary"
```

---

### Task 4: Frontend Price Conversion and Agent Plan Tests

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentCommerce.test.ts`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentReverseProxy.ts`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.ts`

- [ ] **Step 1: Add tests for exact cents conversion and disabled periods**

In `src/lib/agentCommerce.test.ts`, add these cases inside the existing `describe('agent commerce helpers', ...)`:

```ts
  it('keeps yuan to cents conversion exact for common storefront inputs', () => {
    expect(yuanInputToCents('0')).toBe(0);
    expect(yuanInputToCents('0.01')).toBe(1);
    expect(yuanInputToCents('13')).toBe(1300);
    expect(yuanInputToCents('13.4')).toBe(1340);
    expect(yuanInputToCents('13.45')).toBe(1345);
    expect(yuanInputToCents('13.456')).toBe(1346);
    expect(yuanInputToCents(' 13.45 ')).toBe(1345);
  });

  it('hides agent periods that are absent from backend prices even when legacy platform fields exist', () => {
    const plan = {
      id: 1,
      name: 'Agent Starter',
      prices: { monthly: 13 },
      month_price: 20,
      year_price: 120,
      agent_context: { agent_user_id: 10, source: 'domain' },
    };

    expect(getAgentAwarePlanAvailablePeriods(plan)).toEqual(['monthly']);
    expect(getAgentAwarePlanPeriodPrice(plan, 'monthly')).toBe(13);
    expect(getAgentAwarePlanPeriodPrice(plan, 'year_price')).toBe(0);
    expect(getAgentAwarePlanPeriodPrice(plan, 'yearly')).toBe(0);
  });
```

- [ ] **Step 2: Run frontend helper tests**

Run:

```powershell
npm run test -- agentCommerce
```

from `C:\Users\Administrator\Documents\keli\keli-user`.

Expected: PASS unless a helper still leaks platform prices for agent plans.

- [ ] **Step 3: Patch helper behavior if tests fail**

If agent plans still fall back to legacy prices, update `getAgentAwarePlanPeriodPrice()` in `agentPlanPricing.ts` so when a plan has `agent_context`, it only reads `plan.prices[modernPeriod]` and returns `0` for absent periods.

The branch should follow this shape:

```ts
if (isAgentPurchasePlan(plan)) {
  const modernPeriod = normalizeLegacyPeriodKey(period);
  const price = Number((plan as any)?.prices?.[modernPeriod] ?? 0);
  return Number.isFinite(price) && price > 0 ? price : 0;
}
```

- [ ] **Step 4: Re-run frontend helper tests**

Run:

```powershell
npm run test -- agentCommerce
```

Expected: PASS.

- [ ] **Step 5: Commit frontend helper coverage**

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-user add src/lib/agentCommerce.test.ts src/lib/agentReverseProxy.ts src/lib/agentPlanPricing.ts
git -C C:\Users\Administrator\Documents\keli\keli-user commit -m "test: cover agent storefront price helpers"
```

---

### Task 5: Frontend Agent Center Commerce Summary

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\services\agentCommerce.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\AgentCenterPage.tsx`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\zh\translation.json`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\en\translation.json`

- [ ] **Step 1: Extend diagnostics summary type**

In `src/services/agentCommerce.ts`, ensure `AgentCommerceDiagnostics['summary']` includes:

```ts
pending_hold_total: number;
```

Keep existing `balance`, `available_balance`, `minimum_cost`, and `maximum_cost` fields.

- [ ] **Step 2: Locate current diagnostics rendering**

Run:

```powershell
rg -n "diagnostics|available_balance|minimum_cost|maximum_cost|commerceSummary|summary" src/pages/AgentCenterPage.tsx
```

Use the existing diagnostics state and rendering location. Do not create a second API call if `diagnostics()` is already called on page load.

- [ ] **Step 3: Add compact commerce health summary UI**

In the agent commerce/settings area of `AgentCenterPage.tsx`, render a compact four-item summary from diagnostics:

```tsx
const commerceSummaryItems = diagnostics
  ? [
      { key: 'balance', label: t('agentCenter.balance'), value: formatCurrency(diagnostics.summary.balance) },
      { key: 'available', label: t('agentCenter.availableBalance'), value: formatCurrency(diagnostics.summary.available_balance) },
      { key: 'holds', label: t('agentCenter.pendingHolds'), value: formatCurrency(diagnostics.summary.pending_hold_total || 0) },
      { key: 'cost', label: t('agentCenter.enabledCostRange'), value: `${formatCurrency(diagnostics.summary.minimum_cost)} - ${formatCurrency(diagnostics.summary.maximum_cost)}` },
    ]
  : [];
```

Use the existing local currency formatter in `AgentCenterPage.tsx`. If the page only has `formatMoney` or `formatAgentAmount`, use that function instead of creating a new formatter.

Render with the existing page visual language:

```tsx
{commerceSummaryItems.length > 0 ? (
  <div className="grid gap-3 md:grid-cols-4">
    {commerceSummaryItems.map((item) => (
      <div key={item.key} className="rounded-md border border-border/70 bg-card px-4 py-3">
        <div className="text-xs font-medium text-muted-foreground">{item.label}</div>
        <div className="mt-1 text-base font-semibold text-foreground">{item.value}</div>
      </div>
    ))}
  </div>
) : null}
```

- [ ] **Step 4: Add missing translations**

If missing, add:

```json
"availableBalance": "可用余额",
"pendingHolds": "已预占",
"enabledCostRange": "启用成本区间"
```

and English:

```json
"availableBalance": "Available balance",
"pendingHolds": "Pending holds",
"enabledCostRange": "Enabled cost range"
```

- [ ] **Step 5: Run frontend build**

Run:

```powershell
npm run build
```

from `C:\Users\Administrator\Documents\keli\keli-user`.

Expected: PASS with only existing Browserslist/chunk warnings.

- [ ] **Step 6: Commit summary UI**

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-user add src/services/agentCommerce.ts src/pages/AgentCenterPage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git -C C:\Users\Administrator\Documents\keli\keli-user commit -m "feat: show agent storefront balance summary"
```

---

### Task 6: Buyer Storefront and Checkout Error QA

**Files:**
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\StorePage.tsx`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\PurchasePage.tsx`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentCommerceErrors.ts`
- Modify if needed: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentCommerceErrors.test.ts`

- [ ] **Step 1: Verify error mapping tests**

Open `agentCommerceErrors.test.ts` and ensure it contains the balance message:

```ts
expect(mapAgentCommerceError('The site balance is insufficient. Please contact site support.')).toBe(
  'purchase.agentSiteBalanceInsufficient'
);
```

If the helper name differs, use the existing exported mapper function.

- [ ] **Step 2: Run error mapping test**

Run:

```powershell
npm run test -- agentCommerceErrors
```

Expected: PASS.

- [ ] **Step 3: Inspect store price display**

Run:

```powershell
rg -n "getAgentAwarePlanPeriodPrice|getAgentAwarePlanAvailablePeriods|formatPrice|platform_price|agent_context" src/pages/StorePage.tsx src/pages/PurchasePage.tsx
```

Confirm:

- Store page uses `getAgentAwarePlanAvailablePeriods(plan)` for selectable periods.
- Store page uses `getAgentAwarePlanPeriodPrice(plan, period)` for displayed prices.
- Purchase page normalizes selected period with `normalizeAgentAwarePeriodForPurchase()` before order creation.
- Agent checkout errors use the mapped localized messages.

- [ ] **Step 4: Patch display only if inspection finds leakage**

If `StorePage.tsx` still reads legacy `month_price` / `year_price` directly for agent plans, route those reads through `getAgentAwarePlanPeriodPrice()`.

The safe pattern is:

```tsx
const price = getAgentAwarePlanPeriodPrice(plan, period);
```

For agent plans, do not display platform legacy period fields as fallbacks.

- [ ] **Step 5: Run focused frontend tests and build**

Run:

```powershell
npm run test -- agentCommerce agentCommerceErrors
npm run build
```

from `C:\Users\Administrator\Documents\keli\keli-user`.

Expected: tests PASS; build PASS with only existing warnings.

- [ ] **Step 6: Commit buyer storefront QA fixes**

```powershell
git -C C:\Users\Administrator\Documents\keli\keli-user add src/pages/StorePage.tsx src/pages/PurchasePage.tsx src/lib/agentCommerceErrors.ts src/lib/agentCommerceErrors.test.ts
git -C C:\Users\Administrator\Documents\keli\keli-user commit -m "fix: keep agent storefront prices isolated"
```

If no files changed after inspection and tests, skip this commit and record that no buyer storefront patch was needed in the task review.

---

### Task 7: Final Verification and Push

**Files:**
- Verify both repositories:
  - `C:\Users\Administrator\Documents\keli\keliboard`
  - `C:\Users\Administrator\Documents\keli\keli-user`

- [ ] **Step 1: Run backend focused test suite**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php tests/Unit/Http/UserAgentCommerceControllerTest.php
```

from `C:\Users\Administrator\Documents\keli\keliboard`.

Expected: PASS.

- [ ] **Step 2: Run frontend focused tests**

Run:

```powershell
npm run test -- agentCommerce agentCommerceErrors agent agentSiteContext
```

from `C:\Users\Administrator\Documents\keli\keli-user`.

Expected: PASS.

- [ ] **Step 3: Run frontend production build**

Run:

```powershell
npm run build
```

from `C:\Users\Administrator\Documents\keli\keli-user`.

Expected: PASS with only existing Browserslist/chunk warnings.

- [ ] **Step 4: Check git status in both repos**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard status --short --branch
git -C C:\Users\Administrator\Documents\keli\keli-user status --short --branch
```

Expected:

- `keliboard` is ahead of origin with intended commits and no unstaged tracked changes.
- `keli-user` is ahead of origin with intended commits and no unstaged tracked changes.
- Existing untracked `design-audits/`, `dev_server.err.log`, and `dev_server.out.log` in `keli-user` may remain uncommitted.

- [ ] **Step 5: Push both repos**

Run:

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard push
git -C C:\Users\Administrator\Documents\keli\keli-user push
```

Expected: both current branches push successfully to GitHub.

---

## Self-Review

- Spec coverage: Tasks cover per-plan/per-period price contracts, immutable order snapshots, balance holds, checkout payment ownership, callback capture, cancellation/failure lifecycle, diagnostics summary, frontend amount conversion, buyer storefront isolation, error mapping, and final push.
- Placeholder scan: No TBD/TODO placeholders are present. Each task has concrete files, commands, expected results, and patch guidance.
- Type consistency: Backend names match current models/services (`AgentBalanceHold`, `AgentOrderContext`, `AgentPlanPrice`, `AgentCommerceService`). Frontend names match current files (`agentCommerce.ts`, `agentPlanPricing.ts`, `AgentCenterPage.tsx`).
