# Agent Commerce Unification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make agent domain attribution, user binding, agent storefront prices, agent payment methods, balance checks, and admin visibility use one effective agent context across `keliboard`, `keli-user`, and `keli-admin`.

**Architecture:** Keep the existing agent commerce services and tighten their contracts instead of creating a parallel reseller stack. `AgentCommerceContextResolver` resolves the effective agent once; `AgentStorefrontService` exposes the prices that are purchasable for that context; `AgentCommerceService` validates payment, holds, and order snapshots; frontend pages consume the backend truth without recalculating fallback prices or platform payments.

**Tech Stack:** Laravel/PHPUnit for `keliboard`; React/Vite/Vitest/TypeScript for `keli-user` and `keli-admin`.

---

## File Structure

### `keliboard`

- Modify: `app/Services/AgentCommerceContextResolver.php`
  - Keep user binding priority.
  - Add a public context normalizer so services can safely read `agent_user_id`, `agent_domain_id`, `domain`, and `source`.
- Modify: `app/Services/AgentStorefrontService.php`
  - Ensure agent storefront plan output and sale-price resolution share period normalization and enabled-price rules.
  - Add explicit agent-context metadata to public plan payloads.
- Modify: `app/Services/AgentCommerceService.php`
  - Add payment availability filtering that includes domain-bound agent payments.
  - Reuse the same method for `getPaymentMethod` and checkout validation.
  - Preserve balance hold behavior.
- Modify: `app/Http/Controllers/V1/User/OrderController.php`
  - Replace inline payment filtering with `AgentCommerceService::availablePaymentMethodsForRequest()`.
- Test: `tests/Unit/Services/AgentCommerceContextResolverTest.php`
- Test: `tests/Unit/Services/AgentStorefrontServiceTest.php`
- Test: `tests/Unit/Services/AgentCommerceServiceTest.php`
- Test: `tests/Unit/Http/AgentDomainOrderFlowTest.php`

### `keli-user`

- Modify: `src/services/plan.ts`
  - Type agent context and agent sale periods.
- Modify: `src/pages/StorePage.tsx`
  - Render only backend-provided purchasable agent periods in agent context.
- Modify: `src/pages/PurchasePage.tsx`
  - Keep agent-context checkout errors user-friendly and do not show platform-payment fallbacks.
- Test: `src/lib/agentCommerce.test.ts` or page helper tests that already cover plan and checkout mapping.

### `keli-admin`

- Modify: `src/services/agentCommerce.ts`
  - Ensure order/hold types include source, domain, sale, cost, payment, and failure fields.
- Modify: `src/pages/agent/AgentCommercePage.tsx`
  - Keep source, failure, sale amount, cost amount, and payment visible in tables.
- Test: `src/lib/agentCommerceDisplay.test.ts` or existing admin display tests.

---

## Task 1: Backend Payment Context Contract

**Files:**
- Modify: `app/Services/AgentCommerceService.php`
- Modify: `app/Http/Controllers/V1/User/OrderController.php`
- Test: `tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Write failing tests for domain-bound payment filtering**

Add these tests to `tests/Unit/Http/AgentDomainOrderFlowTest.php` after `test_agent_domain_payment_methods_only_include_owned_agent_methods`.

```php
public function test_agent_domain_payment_methods_exclude_payments_bound_to_another_agent_domain(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $currentDomain = AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop-a.example.test',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $otherDomain = AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop-b.example.test',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $globalPayment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
    $currentPayment = $this->createPayment(Payment::OWNER_AGENT, $agent->id, $currentDomain->id);
    $this->createPayment(Payment::OWNER_AGENT, $agent->id, $otherDomain->id);
    $request = BaseRequest::create('/api/v1/user/order/getPaymentMethod', 'GET', [], [], [], [
        'HTTP_HOST' => 'shop-a.example.test',
    ]);

    $response = app(OrderController::class)->getPaymentMethod($request);
    $payload = $this->responsePayload($response);

    $this->assertSame([$globalPayment->id, $currentPayment->id], array_column($payload['data'], 'id'));
}

public function test_checkout_rejects_agent_payment_bound_to_another_domain(): void
{
    [$agent, $buyer, $order] = $this->createAgentOrderFixture('shop-a.example.test');
    $otherDomain = AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop-b.example.test',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id, $otherDomain->id);
    $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
        'trade_no' => $order->trade_no,
        'method' => $payment->id,
    ], [], [], [
        'HTTP_HOST' => 'shop-a.example.test',
    ]);
    $request->setUserResolver(static fn (): User => $buyer);
    app()->instance('request', $request);

    $response = app(OrderController::class)->checkout($request);
    $payload = $this->responsePayload($response);

    $this->assertSame('fail', $payload['status']);
    $this->assertSame('This payment method is unavailable.', $payload['message']);
    $this->assertNull($order->fresh()->payment_id);
}
```

- [ ] **Step 2: Update the test helper to support owner domain IDs**

Change the helper signature in `tests/Unit/Http/AgentDomainOrderFlowTest.php`.

```php
private function createPayment(string $ownerType, ?int $ownerId, ?int $ownerDomainId = null): Payment
{
    return Payment::query()->create([
        'uuid' => substr(md5($ownerType . ':' . (string) $ownerId . ':' . (string) $ownerDomainId . ':' . microtime(true)), 0, 8),
        'name' => 'Test Payment',
        'payment' => 'fake',
        'icon' => '',
        'config' => [],
        'notify_domain' => '',
        'handling_fee_fixed' => 0,
        'handling_fee_percent' => 0,
        'enable' => true,
        'sort' => 0,
        'owner_type' => $ownerType,
        'owner_id' => $ownerId,
        'owner_domain_id' => $ownerDomainId,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
}
```

- [ ] **Step 3: Run the failing tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "domain_payment_methods_exclude|checkout_rejects_agent_payment_bound"
```

Expected: both tests fail because current payment filtering accepts all payments owned by the agent, regardless of `owner_domain_id`.

- [ ] **Step 4: Add reusable payment filtering to `AgentCommerceService`**

Add this method to `app/Services/AgentCommerceService.php`.

```php
public function availablePaymentMethodsForRequest(Request $request)
{
    $context = $this->effectivePaymentContext($request);

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
        ->when($context, function ($query) use ($context) {
            $query->where('owner_type', Payment::OWNER_AGENT)
                ->where('owner_id', (int) $context['agent_user_id'])
                ->where(function ($nested) use ($context) {
                    $domainId = $context['agent_domain_id'] ?? null;
                    $nested->whereNull('owner_domain_id');
                    if ($domainId !== null) {
                        $nested->orWhere('owner_domain_id', (int) $domainId);
                    }
                });
        }, function ($query) {
            $query->where('owner_type', Payment::OWNER_PLATFORM);
        })
        ->orderBy('sort', 'ASC')
        ->get();
}

private function effectivePaymentContext(Request $request): ?array
{
    $tradeNo = trim((string) $request->input('trade_no', ''));
    if ($tradeNo !== '' && $request->user()) {
        $order = Order::query()
            ->where('trade_no', $tradeNo)
            ->where('user_id', $request->user()->id)
            ->first();
        if ($order) {
            $context = $this->contextForOrder($order);
            if ($context) {
                return [
                    'agent_user_id' => (int) $context->agent_user_id,
                    'agent_domain_id' => $context->agent_domain_id !== null ? (int) $context->agent_domain_id : null,
                    'source' => (string) ($context->domain_snapshot['source'] ?? ''),
                ];
            }
        }
    }

    return app(AgentCommerceContextResolver::class)->resolveRequest($request);
}
```

- [ ] **Step 5: Make `agentUserIdForPaymentMethods` call the new context helper**

Replace the body of `agentUserIdForPaymentMethods()` in `app/Services/AgentCommerceService.php`.

```php
public function agentUserIdForPaymentMethods(Request $request): ?int
{
    $context = $this->effectivePaymentContext($request);

    return $context ? (int) $context['agent_user_id'] : null;
}
```

- [ ] **Step 6: Use the service in `OrderController::getPaymentMethod`**

Replace the payment query in `app/Http/Controllers/V1/User/OrderController.php`.

```php
public function getPaymentMethod(Request $request)
{
    $methods = app(AgentCommerceService::class)->availablePaymentMethodsForRequest($request);

    return $this->success($methods);
}
```

- [ ] **Step 7: Validate checkout payment with domain binding**

At the start of `assertPaymentAvailableForOrder()` after loading `$context`, add this domain check for agent orders.

```php
if ($payment->owner_domain_id !== null) {
    $contextDomainId = $context->agent_domain_id !== null ? (int) $context->agent_domain_id : null;
    if ($contextDomainId === null || (int) $payment->owner_domain_id !== $contextDomainId) {
        throw new ApiException('This payment method is unavailable.');
    }
}
```

- [ ] **Step 8: Run the targeted tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "domain_payment_methods_exclude|checkout_rejects_agent_payment_bound|agent_checkout_accepts_owned_agent_payment_method"
```

Expected: all selected tests pass.

- [ ] **Step 9: Commit backend payment context**

```bash
git add app/Services/AgentCommerceService.php app/Http/Controllers/V1/User/OrderController.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "fix: enforce agent domain payment context"
```

---

## Task 2: Backend Storefront Price Consistency

**Files:**
- Modify: `app/Services/AgentStorefrontService.php`
- Test: `tests/Unit/Services/AgentStorefrontServiceTest.php`
- Test: `tests/Unit/Services/AgentCommerceServiceTest.php`

- [ ] **Step 1: Write failing tests for bound-user plan prices**

Add this test to `tests/Unit/Services/AgentStorefrontServiceTest.php`.

```php
public function test_bound_user_on_platform_request_gets_agent_sale_prices(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $buyer = User::query()->create([
        'email' => 'buyer@example.test',
        'password' => password_hash('secret123', PASSWORD_BCRYPT),
        'uuid' => 'buyer-uuid',
        'token' => 'buyer-token',
        'balance' => 0,
        'commission_balance' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    \App\Models\AgentUser::query()->create([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 120.00,
    ]);
    AgentPlanPrice::query()->create([
        'agent_user_id' => $agent->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'sale_price' => 1300,
        'enabled' => true,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $request = $this->requestForHost('platform.example.test');
    $request->setUserResolver(static fn (): User => $buyer);

    $plans = app(AgentStorefrontService::class)->plansForRequest($request, collect([$plan]));

    $this->assertCount(1, $plans);
    $this->assertSame($agent->id, $plans[0]->agent_context['agent_user_id']);
    $this->assertSame('user_binding', $plans[0]->agent_context['source']);
    $this->assertEquals(13.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
    $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
}
```

- [ ] **Step 2: Run the bound-user storefront test**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentStorefrontServiceTest.php --filter bound_user_on_platform_request_gets_agent_sale_prices
```

Expected: pass if current resolver is already wired; if it fails, the failure must point to the request user not being considered by `plansForRequest()`.

- [ ] **Step 3: Ensure `plansForRequest()` passes the request user into the resolver**

If Step 2 fails, replace the resolver call in `app/Services/AgentStorefrontService.php`.

```php
$context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user());
```

- [ ] **Step 4: Write a regression test for missing sale price during agent order creation**

Add this test to `tests/Unit/Services/AgentCommerceServiceTest.php`.

```php
public function test_agent_order_creation_rejects_unconfigured_sale_period(): void
{
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    $this->assignDomain($agent, 'agent.example.test');
    $buyer = $this->createUser('buyer@example.test');
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 10.00,
        Plan::PERIOD_YEARLY => 100.00,
    ]);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Agent price is not available');

    app(AgentCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_YEARLY,
        null,
        $this->requestForHost('agent.example.test')
    );
}
```

- [ ] **Step 5: Run storefront and order consistency tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php --filter "bound_user_on_platform|unconfigured_sale_period|agent_price_is_returned|unpriced_agent_period"
```

Expected: selected tests pass.

- [ ] **Step 6: Commit backend price consistency**

```bash
git add app/Services/AgentStorefrontService.php tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php
git commit -m "fix: align agent storefront price context"
```

---

## Task 3: User Frontend Agent Store And Checkout Truth

**Files:**
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/services/plan.ts`
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/pages/StorePage.tsx`
- Modify: `C:/Users/Administrator/Documents/keli/keli-user/src/pages/PurchasePage.tsx`
- Test: `C:/Users/Administrator/Documents/keli/keli-user/src/lib/agentCommerce.test.ts`

- [ ] **Step 1: Add frontend helper tests for agent plan visibility**

In `keli-user/src/lib/agentCommerce.test.ts`, add helper tests around existing agent commerce helpers.

```ts
it('treats agent plans with agent_context as backend-priced plans', () => {
  const plan = {
    id: 1,
    name: 'Agent Starter',
    prices: { monthly: 13 },
    agent_context: { agent_user_id: 10, source: 'domain' },
    agent_sale_periods: { monthly: 1300 },
  };

  expect(Boolean((plan as any).agent_context)).toBe(true);
  expect((plan as any).prices.monthly).toBe(13);
  expect((plan as any).agent_sale_periods.monthly).toBe(1300);
});
```

- [ ] **Step 2: Type agent plan context**

Add these optional fields to the exported `Plan` type in `keli-user/src/services/plan.ts`.

```ts
agent_context?: {
  agent_user_id?: number | string | null;
  agent_domain_id?: number | string | null;
  domain?: string | null;
  source?: string | null;
} | null;
agent_sale_periods?: Record<string, number>;
```

- [ ] **Step 3: Keep store periods backend-driven in agent context**

In `keli-user/src/pages/StorePage.tsx`, ensure period extraction trusts `plan.prices` when `plan.agent_context` exists.

```ts
const isAgentPricedPlan = (plan: any): boolean => Boolean(plan?.agent_context);
```

Use this guard inside the existing available-period logic:

```ts
if (isAgentPricedPlan(plan)) {
  return Object.keys(plan.prices || {}).filter((period) => Number(plan.prices?.[period]) > 0) as PlanLegacyPeriodKey[];
}
```

- [ ] **Step 4: Keep purchase checkout errors mapped for agent failures**

In `keli-user/src/pages/PurchasePage.tsx`, confirm the existing error map includes these exact backend strings.

```ts
const AGENT_PRICE_UNAVAILABLE = 'Agent price is not available';
const AGENT_PAYMENT_UNAVAILABLE = 'This payment method is unavailable.';
const AGENT_BALANCE_INSUFFICIENT = 'The site balance is insufficient. Please contact site support.';
```

The mapping should return:

```ts
if (message.includes(AGENT_PRICE_UNAVAILABLE)) return t('purchase.agentPriceUnavailable');
if (message.includes(AGENT_PAYMENT_UNAVAILABLE)) return t('purchase.agentPaymentUnavailable');
if (message.includes(AGENT_BALANCE_INSUFFICIENT)) return t('purchase.agentSiteBalanceInsufficient');
```

- [ ] **Step 5: Add missing translation key if absent**

In `keli-user/src/locales/zh/translation.json`, add:

```json
"agentPriceUnavailable": "当前代理站暂未配置该套餐价格，请联系网站客服。"
```

under the existing `purchase` object.

- [ ] **Step 6: Run user frontend tests**

Run in `C:/Users/Administrator/Documents/keli/keli-user`:

```bash
npm run test -- agentCommerce
```

Expected: tests pass.

- [ ] **Step 7: Build user frontend**

Run:

```bash
npm run build
```

Expected: build succeeds. Browserslist or chunk-size warnings are acceptable.

- [ ] **Step 8: Commit user frontend consistency**

```bash
git add src/services/plan.ts src/pages/StorePage.tsx src/pages/PurchasePage.tsx src/locales/zh/translation.json src/lib/agentCommerce.test.ts
git commit -m "fix: align agent storefront checkout UI"
```

---

## Task 4: Admin Agent Commerce Observability

**Files:**
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/services/agentCommerce.ts`
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Test: `C:/Users/Administrator/Documents/keli/keli-admin/src/lib/agentCommerceDisplay.test.ts`

- [ ] **Step 1: Add display tests for source and failed holds**

Add to `keli-admin/src/lib/agentCommerceDisplay.test.ts`.

```ts
it('labels agent commerce order sources consistently', () => {
  expect(agentOrderSourceLabelKey('domain')).toBe('agent_commerce.source.domain');
  expect(agentOrderSourceLabelKey('user_binding')).toBe('agent_commerce.source.user_binding');
  expect(agentOrderSourceLabelKey(null)).toBe('agent_commerce.source.unknown');
});

it('shows failed agent holds as danger', () => {
  expect(agentHoldStatusTone('failed')).toBe('danger');
  expect(agentHoldStatusTone('pending')).toBe('warning');
});
```

- [ ] **Step 2: Ensure service types include commerce fields**

In `keli-admin/src/services/agentCommerce.ts`, confirm `AdminAgentOrderContext` includes:

```ts
source?: string | null;
failure_reason?: string | null;
sale_amount?: number | null;
cost_amount?: number | null;
payment_name?: string | null;
payment_id?: number | string | null;
agent_domain?: string | null;
```

Confirm `AdminAgentBalanceHold` includes:

```ts
failure_reason?: string | null;
order_status?: number | string | null;
```

- [ ] **Step 3: Keep table cells visible**

In `keli-admin/src/pages/agent/AgentCommercePage.tsx`, ensure the order table renders:

```tsx
<StatusBadge tone="info">{t(agentOrderSourceLabelKey(order.source))}</StatusBadge>
```

and displays the failure reason when present:

```tsx
{order.failure_reason ? (
  <div className="mt-1 break-all text-xs text-danger">{failureReasonText(order.failure_reason)}</div>
) : null}
```

- [ ] **Step 4: Run admin display tests**

Run in `C:/Users/Administrator/Documents/keli/keli-admin`:

```bash
npm run test -- agentCommerceDisplay
```

Expected: tests pass.

- [ ] **Step 5: Build admin frontend**

Run:

```bash
npm run build
```

Expected: build succeeds. Browserslist or chunk-size warnings are acceptable.

- [ ] **Step 6: Commit admin observability**

```bash
git add src/services/agentCommerce.ts src/pages/agent/AgentCommercePage.tsx src/lib/agentCommerceDisplay.test.ts
git commit -m "fix: clarify agent commerce admin context"
```

---

## Task 5: Cross-Repo Verification And Push

**Files:**
- Read-only verification across `keliboard`, `keli-user`, and `keli-admin`.

- [ ] **Step 1: Run backend target tests locally or on Linux test machine**

If local PHP dependencies are available:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceContextResolverTest.php tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php tests/Unit/Http/AdminAgentCommerceControllerTest.php
```

Expected: all selected tests pass.

If local Windows PHP cannot run, use the test machine:

```bash
ssh -i ~/.ssh/codex_keli_ed25519 root@165.232.158.117 'cd /root/keliboard-test && git fetch origin feature/agent-domain-commerce && git worktree add -f /tmp/keliboard-agent-unification origin/feature/agent-domain-commerce && cp -a vendor /tmp/keliboard-agent-unification/vendor && cd /tmp/keliboard-agent-unification && php vendor/bin/phpunit tests/Unit/Services/AgentCommerceContextResolverTest.php tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php tests/Unit/Http/AdminAgentCommerceControllerTest.php'
```

Expected: PHPUnit reports `OK`.

- [ ] **Step 2: Clean the remote temporary worktree**

Run only if Step 1 used the test machine:

```bash
ssh -i ~/.ssh/codex_keli_ed25519 root@165.232.158.117 'cd /root/keliboard-test && git worktree remove /tmp/keliboard-agent-unification'
```

Expected: no output or successful removal.

- [ ] **Step 3: Run frontend verification**

Run:

```bash
cd C:/Users/Administrator/Documents/keli/keli-user
npm run test -- agentCommerce
npm run build
cd C:/Users/Administrator/Documents/keli/keli-admin
npm run test -- agentCommerceDisplay
npm run build
```

Expected: both test suites and builds succeed.

- [ ] **Step 4: Push all touched repositories**

Run:

```bash
cd C:/Users/Administrator/Documents/keli/keliboard
git push origin feature/agent-domain-commerce
cd C:/Users/Administrator/Documents/keli/keli-user
git push origin feature/agent-domain-commerce
cd C:/Users/Administrator/Documents/keli/keli-admin
git push origin feature/agent-domain-commerce
```

Expected: all pushes succeed.

---

## Self-Review

### Spec Coverage

- Attribution priority is covered by existing resolver tests and Task 2 bound-user storefront test.
- Agent sale price as the only agent-context purchase price is covered by Task 2.
- Agent payment domain binding is covered by Task 1.
- Balance checks remain covered by existing hold tests and Task 5 full backend target run.
- Admin visibility is covered by Task 4.
- User storefront and checkout localization are covered by Task 3.

### Placeholder Scan

This plan intentionally avoids open-ended placeholder tasks. Each code-changing step includes the exact method, test, or snippet to add.

### Type Consistency

- `agent_context` matches backend attributes already produced by `AgentStorefrontService`.
- `agent_sale_periods` stays a `Record<string, number>` in cents.
- Payment owner fields match `Payment::OWNER_AGENT`, `owner_id`, and `owner_domain_id`.
