# Site Period Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each multi-site storefront able to show or hide individual subscription periods, and prevent agents from selling periods hidden by the site.

**Architecture:** Reuse `v2_site_plan_price.enabled` as the site-level period visibility flag. `TenantPlanCatalogService` should site-filter plan periods before agent pricing is applied, and `TenantPlanPricingService` should validate site availability before returning agent pricing when both contexts exist. The admin UI should expose clear batch controls over the existing per-period `enabled` values.

**Tech Stack:** Laravel services/tests, React + TypeScript admin UI, existing Vite build/sync flow.

---

## File Structure

- Modify `app/Services/TenantPlanCatalogService.php`: use `SiteStorefrontService::plansForRequest()` before agent decoration so agent catalogs cannot reintroduce hidden site periods.
- Modify `app/Services/TenantPlanPricingService.php`: when agent and site contexts both exist, validate the site period before using agent sale price.
- Modify `tests/Unit/Services/TenantPlanCatalogServiceTest.php`: add coverage for agent catalog respecting site-hidden periods.
- Modify `tests/Unit/Services/TenantPlanPricingServiceTest.php`: add coverage for agent pricing rejecting periods hidden by the user's site.
- Modify `src/pages/system/PlatformSites.tsx` in `keli-admin`: add per-plan quick actions and rename the period switch intent.
- Modify `src/locales/zh/translation.json` and `src/locales/en/translation.json` in `keli-admin`: add labels for visibility actions.

---

### Task 1: Backend Catalog Gate

**Files:**
- Modify: `app/Services/TenantPlanCatalogService.php`
- Test: `tests/Unit/Services/TenantPlanCatalogServiceTest.php`

- [ ] **Step 1: Write the failing catalog test**

Add a test that creates a site-bound agent user, site-enables monthly only, agent-enables monthly and yearly, then verifies the catalog only returns monthly.

```php
public function test_agent_catalog_respects_site_visible_periods(): void
{
    [$site] = $this->siteWithDomain('cheap', 'cheap.example.test');
    $agent = $this->createAgent('agent@example.test', $site->id);
    $buyer = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
    $this->bindAgentUser($agent, $buyer);
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 120.00,
    ]);
    $this->setSitePrice($site, $plan, Plan::PERIOD_MONTHLY, 1300, true);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1100, true);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_YEARLY, 9000, true);

    $request = $this->requestForHost('cheap.example.test', $buyer);
    $plans = app(TenantPlanCatalogService::class)->plansForRequest($request, collect([$plan]), $buyer);

    $this->assertCount(1, $plans);
    $this->assertSame([Plan::PERIOD_MONTHLY], array_keys($plans[0]->prices));
    $this->assertSame(1100, $plans[0]->agent_sale_periods[Plan::PERIOD_MONTHLY]);
    $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
}
```

- [ ] **Step 2: Run the failing catalog test**

Run:

```bash
php artisan test --filter=TenantPlanCatalogServiceTest::test_agent_catalog_respects_site_visible_periods
```

Expected before implementation: FAIL because yearly can appear when only agent pricing is applied.

- [ ] **Step 3: Implement catalog site-filtering before agent pricing**

Change `TenantPlanCatalogService::plansForRequest()` so agent catalogs use site-filtered plans, not display-name-only site decoration.

```php
if (app(AgentCommerceContextResolver::class)->resolveRequest($request, $resolvedUser)) {
    $siteDecoratedPlans = app(SiteStorefrontService::class)->plansForRequest($request, $platformPlans);

    return app(AgentStorefrontService::class)->plansForRequest($request, $siteDecoratedPlans);
}
```

- [ ] **Step 4: Re-run the catalog test**

Run:

```bash
php artisan test --filter=TenantPlanCatalogServiceTest::test_agent_catalog_respects_site_visible_periods
```

Expected: PASS.

---

### Task 2: Backend Checkout And Auto-Renew Gate

**Files:**
- Modify: `app/Services/TenantPlanPricingService.php`
- Test: `tests/Unit/Services/TenantPlanPricingServiceTest.php`

- [ ] **Step 1: Write the failing pricing test**

Add a test where an agent-bound user belongs to a site that only enables monthly, while the agent has yearly pricing. Resolving yearly should fail before an order can be created.

```php
public function test_agent_price_rejects_period_hidden_by_site(): void
{
    [$site] = $this->siteWithDomain('cheap', 'cheap.example.test');
    $agent = $this->createAgent('agent@example.test', $site->id);
    $buyer = $this->createUser('buyer@example.test', ['site_id' => $site->id]);
    $this->bindAgentUser($agent, $buyer);
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 120.00,
    ]);
    $this->setSitePrice($site, $plan, Plan::PERIOD_MONTHLY, 1300, true);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_YEARLY, 9000, true);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Site price is not available');

    app(TenantPlanPricingService::class)->resolveForUser($buyer, $plan, Plan::PERIOD_YEARLY);
}
```

- [ ] **Step 2: Run the failing pricing test**

Run:

```bash
php artisan test --filter=TenantPlanPricingServiceTest::test_agent_price_rejects_period_hidden_by_site
```

Expected before implementation: FAIL because agent pricing is returned without validating site visibility.

- [ ] **Step 3: Add site validation before agent pricing**

In `TenantPlanPricingService`, when both `agentContext` and `siteContext` exist, validate the site period first.

```php
private function validateSitePeriodIfPresent(?array $siteContext, Plan $plan, string $periodKey): void
{
    if (!is_array($siteContext) || empty($siteContext['site_id'])) {
        return;
    }

    app(SiteStorefrontService::class)->resolveSalePrice(
        (int) $siteContext['site_id'],
        (int) $plan->id,
        $periodKey
    );
}
```

Call it before `agentPrice()` in `resolveForUser()`, `resolveForRequest()`, and `resolveForContext()` when an agent context exists.

- [ ] **Step 4: Run pricing and auto-renew related tests**

Run:

```bash
php artisan test --filter=TenantPlanPricingServiceTest
php artisan test --filter=AutoRenewOrdersTest
```

Expected: PASS. Auto renew already treats pricing exceptions as unsupported periods.

---

### Task 3: Admin Period Visibility Controls

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\pages\system\PlatformSites.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\locales\zh\translation.json`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src\locales\en\translation.json`

- [ ] **Step 1: Add UI labels**

Add translation keys under `platform_sites.price_table`:

```json
"visibility": "显示并可购买",
"show_all": "全部显示",
"hide_all": "全部隐藏",
"show_month_year": "只显示月付/年付",
"sync_platform": "同步平台周期"
```

English:

```json
"visibility": "Visible and purchasable",
"show_all": "Show all",
"hide_all": "Hide all",
"show_month_year": "Monthly/Yearly only",
"sync_platform": "Sync platform periods"
```

- [ ] **Step 2: Add per-plan batch helper**

In `PlatformSites.tsx`, add:

```tsx
const updatePlanPeriods = (
  planId: number,
  mode: "show_all" | "hide_all" | "month_year" | "sync_platform"
) => {
  setPrices((prev) =>
    prev.map((price) => {
      if (price.plan_id !== planId) return price;
      const isMonthOrYear = price.period === "monthly" || price.period === "yearly";
      if (mode === "hide_all") return { ...price, enabled: false };
      if (mode === "month_year") return { ...price, enabled: isMonthOrYear };
      if (mode === "sync_platform") {
        return {
          ...price,
          enabled: true,
          sale_price: price.sale_price || minorToInput(price.platform_price),
        };
      }
      return { ...price, enabled: true };
    })
  );
};
```

- [ ] **Step 3: Render quick action buttons inside the plan cell**

Inside the first-row plan cell, below the display name input, render four compact buttons:

```tsx
<div className="mt-3 flex flex-wrap gap-1.5">
  <Button type="button" variant="outline" size="sm" onClick={() => updatePlanPeriods(price.plan_id, "show_all")}>
    {t("platform_sites.price_table.show_all")}
  </Button>
  <Button type="button" variant="outline" size="sm" onClick={() => updatePlanPeriods(price.plan_id, "hide_all")}>
    {t("platform_sites.price_table.hide_all")}
  </Button>
  <Button type="button" variant="outline" size="sm" onClick={() => updatePlanPeriods(price.plan_id, "month_year")}>
    {t("platform_sites.price_table.show_month_year")}
  </Button>
  <Button type="button" variant="outline" size="sm" onClick={() => updatePlanPeriods(price.plan_id, "sync_platform")}>
    {t("platform_sites.price_table.sync_platform")}
  </Button>
</div>
```

- [ ] **Step 4: Rename the enabled column**

Change:

```tsx
<DataTableHead className="w-[120px]">{t("platform_sites.price_table.enabled")}</DataTableHead>
```

to:

```tsx
<DataTableHead className="w-[150px]">{t("platform_sites.price_table.visibility")}</DataTableHead>
```

- [ ] **Step 5: Build admin**

Run in `keli-admin`:

```bash
npm run build
```

Expected: PASS.

---

### Task 4: Sync, Verify, And Commit

**Files:**
- Modify generated: `public/assets/admin-xboard/assets/index.js`
- Modify generated: `public/assets/admin-xboard/index.html`

- [ ] **Step 1: Sync admin build into keliboard**

Run in `keli-admin`:

```bash
npm run sync:xboardpro
```

Expected: output includes `Synced ... -> ...\keliboard\public\assets\admin-xboard`.

- [ ] **Step 2: Run available static checks**

Run:

```bash
git diff --check
```

Expected: no whitespace errors.

- [ ] **Step 3: Commit backend and synced assets**

Run in `keliboard`:

```bash
git add app/Services/TenantPlanCatalogService.php app/Services/TenantPlanPricingService.php tests/Unit/Services/TenantPlanCatalogServiceTest.php tests/Unit/Services/TenantPlanPricingServiceTest.php public/assets/admin-xboard/assets/index.js public/assets/admin-xboard/index.html docs/superpowers/plans/2026-06-24-site-period-visibility.md
git commit -m "Enforce site period visibility"
```

- [ ] **Step 4: Commit admin source**

Run in `keli-admin`:

```bash
git add src/pages/system/PlatformSites.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Add site period visibility controls"
```

- [ ] **Step 5: Push both repositories**

Run:

```bash
git -C C:\Users\Administrator\Documents\keli\keliboard push origin main
git -C C:\Users\Administrator\Documents\keli\keli-admin push origin main
```

Expected: both push to `main`.
