# Agent Storefront Purchase Guardrails Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tighten the agent storefront purchase path so agent-visible plans obey admin allow-list rules and agent-priced purchases do not expose coupon controls that the backend rejects.

**Architecture:** Keep the current `AgentStorefrontService` and `PurchasePage` flow. Add failing tests first around the two exposed contracts, then implement the smallest backend and frontend changes needed to pass without changing normal platform-domain purchases.

**Tech Stack:** Laravel/PHPUnit for `keliboard`; React + TypeScript + Vitest for `keli-user`.

---

## File Structure

- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentStorefrontServiceTest.php`
  - Adds tests proving disabled admin allow-list plans are hidden from agent storefronts and cannot be purchased through stale agent prices.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentStorefrontService.php`
  - Applies `agent_center_allowed_plan_ids` in both `plansForRequest()` and `resolveSalePrice()`.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.test.ts`
  - Adds a helper contract for coupon availability on platform vs agent-priced plans.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.ts`
  - Exposes `canUseCouponForPlan()`.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\PurchasePage.tsx`
  - Uses `canUseCouponForPlan()` to hide coupon inputs for agent-priced plans and avoid sending coupon codes.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\zh\translation.json`
  - Adds the Chinese hint for agent storefront coupon behavior.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\en\translation.json`
  - Adds the English hint for agent storefront coupon behavior.

---

### Task 1: Backend Agent Plan Allow-List Contract

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentStorefrontServiceTest.php`
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentStorefrontService.php`

- [ ] **Step 1: Write failing tests**

Add these tests before the private helper methods in `AgentStorefrontServiceTest.php`:

```php
    public function test_agent_storefront_hides_agent_price_when_plan_is_no_longer_allowed(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $this->assignDomain($agent, 'agent.example.test');
        $blockedPlan = $this->createPlan('Blocked', [Plan::PERIOD_MONTHLY => 20.00]);
        $allowedPlan = $this->createPlan('Allowed', [Plan::PERIOD_MONTHLY => 30.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $blockedPlan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1500,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $allowedPlan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 2500,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->bindTestSettings(['agent_center_allowed_plan_ids' => (string) $allowedPlan->id]);

        $plans = app(AgentStorefrontService::class)->plansForRequest(
            $this->requestForHost('agent.example.test'),
            collect([$blockedPlan, $allowedPlan])
        );

        $this->assertCount(1, $plans);
        $this->assertSame($allowedPlan->id, (int) $plans[0]->id);
        $this->assertEquals(25.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
    }

    public function test_agent_order_rejects_stale_agent_price_when_plan_is_no_longer_allowed(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $plan = $this->createPlan('Blocked', [Plan::PERIOD_MONTHLY => 20.00]);
        AgentPlanPrice::query()->create([
            'agent_user_id' => $agent->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'sale_price' => 1500,
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $this->bindTestSettings(['agent_center_allowed_plan_ids' => '999']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Plan is not allowed for agents');

        app(AgentStorefrontService::class)->resolveSalePrice($agent->id, $plan->id, Plan::PERIOD_MONTHLY);
    }
```

- [ ] **Step 2: Run the focused backend test and verify RED**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentStorefrontServiceTest.php
```

Expected: FAIL because `plansForRequest()` and `resolveSalePrice()` currently do not both enforce the admin allow-list.

- [ ] **Step 3: Implement the minimal backend fix**

In `AgentStorefrontService::plansForRequest()`, return `null` before reading agent prices when `planAllowed($plan)` is false:

```php
                if (!$this->planAllowed($plan)) {
                    return null;
                }
```

In `AgentStorefrontService::resolveSalePrice()`, reject disallowed plans immediately after checking period availability:

```php
        if (!$this->planAllowed($plan)) {
            throw new ApiException('Plan is not allowed for agents');
        }
```

- [ ] **Step 4: Re-run backend test and verify GREEN**

Run:

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentStorefrontServiceTest.php
```

Expected: PASS.

---

### Task 2: Frontend Agent Coupon Visibility Contract

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.test.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentPlanPricing.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\pages\PurchasePage.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\zh\translation.json`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\locales\en\translation.json`

- [ ] **Step 1: Write failing frontend helper tests**

Create or update `src/lib/agentPlanPricing.test.ts` with:

```ts
import { canUseCouponForPlan } from './agentPlanPricing';

describe('agent plan pricing coupon rules', () => {
  it('allows coupons for normal platform plans', () => {
    expect(canUseCouponForPlan({ id: 1, prices: { monthly: 10 } })).toBe(true);
  });

  it('disables coupons for agent-priced storefront plans', () => {
    expect(canUseCouponForPlan({
      id: 2,
      prices: { monthly: 13 },
      agent_context: { agent_user_id: 10, source: 'domain' },
    })).toBe(false);
  });
});
```

- [ ] **Step 2: Run the focused frontend test and verify RED**

Run:

```powershell
npm run test -- src/lib/agentPlanPricing.test.ts
```

Expected: FAIL because `canUseCouponForPlan()` does not exist yet.

- [ ] **Step 3: Implement the helper**

Append this export to `src/lib/agentPlanPricing.ts`:

```ts
export const canUseCouponForPlan = (plan: any): boolean => !isAgentPricedPlan(plan);
```

- [ ] **Step 4: Wire PurchasePage to the helper**

Import `canUseCouponForPlan`, derive `canUseCoupon`, clear coupon state when it becomes unavailable, skip `coupon_code` in `createOrder()` when coupons are disabled, and replace the coupon input with a small hint:

```tsx
const canUseCoupon = useMemo(() => canUseCouponForPlan(selectedPlan), [selectedPlan]);
```

```tsx
...(canUseCoupon && couponValidated && couponCode.trim() ? { coupon_code: couponCode.trim() } : {}),
```

```tsx
{canUseCoupon ? (
  <div className="space-y-2">...</div>
) : (
  <div className="rounded-md border border-sky-500/25 bg-sky-500/10 px-3 py-2 text-sm text-sky-800 dark:text-sky-200">
    {t('purchase.agentCouponUnavailable')}
  </div>
)}
```

- [ ] **Step 5: Add translations**

Add:

```json
"agentCouponUnavailable": "代理站价格不叠加优惠券，已按当前站点售价结算。"
```

and:

```json
"agentCouponUnavailable": "Agent storefront prices do not stack with coupons. The current site price is used."
```

- [ ] **Step 6: Run frontend test and verify GREEN**

Run:

```powershell
npm run test -- src/lib/agentPlanPricing.test.ts
```

Expected: PASS.

---

### Task 3: Final Verification and Commit

**Files:**
- Verify the touched backend and frontend files.

- [ ] **Step 1: Run backend focused tests**

```powershell
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/AgentStorefrontServiceTest.php
```

Expected: OK.

- [ ] **Step 2: Run frontend focused tests**

```powershell
npm run test -- src/lib/agentPlanPricing.test.ts
```

Expected: PASS.

- [ ] **Step 3: Run frontend build**

```powershell
npm run build
```

Expected: build completes successfully.

- [ ] **Step 4: Commit and push**

```powershell
git -C C:\Users\Administrator\Documents\keli\keliboard add docs/superpowers/plans/2026-06-20-agent-storefront-purchase-guardrails.md tests/Unit/Services/AgentStorefrontServiceTest.php app/Services/AgentStorefrontService.php
git -C C:\Users\Administrator\Documents\keli\keliboard commit -m "fix: enforce agent storefront purchase guardrails"
git -C C:\Users\Administrator\Documents\keli\keliboard push
git -C C:\Users\Administrator\Documents\keli\keli-user add src/lib/agentPlanPricing.test.ts src/lib/agentPlanPricing.ts src/pages/PurchasePage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git -C C:\Users\Administrator\Documents\keli\keli-user commit -m "fix: clarify agent storefront coupon handling"
git -C C:\Users\Administrator\Documents\keli\keli-user push
```

Expected: both branches are pushed to GitHub.
