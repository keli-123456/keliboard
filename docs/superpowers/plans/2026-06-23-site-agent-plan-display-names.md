# Site and Agent Plan Display Names Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add site-level and agent-level plan display-name overrides while keeping platform `Plan` records as the single source of truth.

**Architecture:** Add two additive override tables and lightweight Eloquent models. Existing site and agent storefront services will merge display-name overrides into plan payloads and order pricing snapshots. Admin and agent UIs will save display names separately from per-period sale prices.

**Tech Stack:** Laravel/PHP, PHPUnit, React/TypeScript, Vite, Vitest, existing `keli-admin` sync script.

---

## File Map

- Create `database/migrations/2026_06_23_000001_create_plan_display_name_override_tables.php`: additive override tables.
- Create `app/Models/SitePlanOverride.php`: site plan name override model.
- Create `app/Models/AgentPlanOverride.php`: agent plan name override model.
- Modify `tests/Support/InteractsWithInMemoryDatabase.php`: in-memory override tables.
- Modify `app/Services/SiteStorefrontService.php`: site name list/save/apply/snapshot logic.
- Modify `app/Services/AgentStorefrontService.php`: agent name list/save/apply/snapshot logic.
- Modify `app/Http/Controllers/V2/Admin/SiteController.php`: accept and return site overrides.
- Modify `app/Http/Controllers/V1/User/AgentCommerceController.php`: accept and return agent overrides.
- Modify `app/Http/Resources/PlanResource.php`: expose resolved display name as `name`, keep `platform_name`.
- Modify backend tests under `tests/Unit/Services` and `tests/Unit/Http`.
- Modify `C:\Users\Administrator\Documents\keli\keli-admin\src/services/site.ts`: site override API types.
- Modify `C:\Users\Administrator\Documents\keli\keli-admin\src/pages/system/PlatformSites.tsx`: display-name input in site pricing UI.
- Modify `C:\Users\Administrator\Documents\keli\keli-user\src/services/agentCommerce.ts`: agent override API types.
- Modify `C:\Users\Administrator\Documents\keli\keli-user\src/pages/AgentCenterPage.tsx`: display-name input in agent pricing UI.
- Modify `C:\Users\Administrator\Documents\keli\keliboard\public/assets/admin-xboard`: synced admin build output.

---

### Task 1: Backend Storage and RED Tests

**Files:**
- Create: `database/migrations/2026_06_23_000001_create_plan_display_name_override_tables.php`
- Create: `app/Models/SitePlanOverride.php`
- Create: `app/Models/AgentPlanOverride.php`
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php`
- Modify: `tests/Unit/Services/SiteStorefrontServiceTest.php`
- Modify: `tests/Unit/Services/AgentStorefrontServiceTest.php`

- [ ] **Step 1: Write failing site storefront tests**

Add tests that create a site override and assert the guest/user plan resource resolves the site display name while retaining platform name.

Expected assertion shape:

```php
$resource = PlanResource::make($plans[0])->toArray($this->requestForHost('cheap.example.test'));
$this->assertSame('光喵入门版', $resource['name']);
$this->assertSame('Starter', $resource['platform_name']);
$this->assertSame('光喵入门版', $resource['display_name']);
```

- [ ] **Step 2: Write failing agent storefront tests**

Add tests that create both a site override and an agent override. Assert agent override wins over the site override, and empty agent override falls back to site/platform name.

Expected assertion shape:

```php
$this->assertSame('代理畅享版', $resource['name']);
$this->assertSame('光喵入门版', $resource['site_display_name']);
$this->assertSame('Starter', $resource['platform_name']);
```

- [ ] **Step 3: Run RED tests**

Run:

```bash
vendor/bin/phpunit tests\Unit\Services\SiteStorefrontServiceTest.php tests\Unit\Services\AgentStorefrontServiceTest.php --filter "display|override|name"
```

Expected: FAIL because override models/tables and display-name resource fields do not exist yet.

- [ ] **Step 4: Add additive migration and models**

Create two tables:

```php
Schema::create('v2_site_plan_override', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('site_id')->index();
    $table->unsignedInteger('plan_id')->index();
    $table->string('display_name', 120)->nullable();
    $table->integer('created_at')->nullable();
    $table->integer('updated_at')->nullable();
    $table->unique(['site_id', 'plan_id'], 'uniq_site_plan_override');
});

Schema::create('v2_agent_plan_override', function (Blueprint $table): void {
    $table->increments('id');
    $table->unsignedInteger('agent_user_id')->index();
    $table->unsignedInteger('plan_id')->index();
    $table->string('display_name', 120)->nullable();
    $table->integer('created_at')->nullable();
    $table->integer('updated_at')->nullable();
    $table->unique(['agent_user_id', 'plan_id'], 'uniq_agent_plan_override');
});
```

Models should use guarded `id`, `dateFormat = 'U'`, casts for timestamps, and `belongsTo` relations to `Site`, `User`, and `Plan`.

- [ ] **Step 5: Update in-memory database helper**

Add the same two tables to `createSiteCommerceTables()` and `createAgentCommerceTables()` so unit tests can create override rows.

- [ ] **Step 6: Run RED tests again**

Run:

```bash
vendor/bin/phpunit tests\Unit\Services\SiteStorefrontServiceTest.php tests\Unit\Services\AgentStorefrontServiceTest.php --filter "display|override|name"
```

Expected: FAIL because services and `PlanResource` still do not apply overrides.

---

### Task 2: Backend Display Name Resolution

**Files:**
- Modify: `app/Services/SiteStorefrontService.php`
- Modify: `app/Services/AgentStorefrontService.php`
- Modify: `app/Http/Resources/PlanResource.php`
- Modify: `tests/Unit/Services/SiteCommerceServiceTest.php`
- Modify: `tests/Unit/Services/AgentCommerceServiceTest.php`

- [ ] **Step 1: Implement site override loading**

In `SiteStorefrontService`, query `SitePlanOverride` by `site_id` and `plan_id`, then return:

```php
'plan_name' => (string) $plan->name,
'display_name' => $siteDisplayName,
```

Apply attributes to returned plans:

```php
$plan->setAttribute('platform_name', (string) $plan->name);
$plan->setAttribute('display_name', $displayName);
$plan->setAttribute('site_display_name', $displayName);
```

- [ ] **Step 2: Implement site override save**

Add `saveOverrides(Site $site, array $items): void`. For each item, validate a sellable plan. Trim `display_name`; if empty, delete the override row for that site/plan. Otherwise `updateOrCreate` the override row.

- [ ] **Step 3: Implement agent override loading**

In `AgentStorefrontService`, query `AgentPlanOverride` by `agent_user_id` and `plan_id`. Fallback should read the site-resolved value already on the plan:

```php
$fallback = (string) ($plan->getAttribute('display_name') ?: $plan->name);
$displayName = $agentOverride ?: $fallback;
```

Set:

```php
$plan->setAttribute('platform_name', (string) ($plan->getAttribute('platform_name') ?: $plan->name));
$plan->setAttribute('display_name', $displayName);
$plan->setAttribute('agent_display_name', $agentOverride ?: null);
```

- [ ] **Step 4: Implement agent override save**

Add `saveOverrides(User $agent, array $items): void`. Validate active agent profile and allowed plan. Empty display names delete the override row.

- [ ] **Step 5: Update `PlanResource`**

Return resolved names:

```php
'name' => $this->getResourceValue('display_name', $this->getResourceValue('name')),
'display_name' => $this->getResourceValue('display_name', $this->getResourceValue('name')),
'platform_name' => $this->getResourceValue('platform_name', $this->getResourceValue('name')),
'site_display_name' => $this->getResourceValue('site_display_name'),
'agent_display_name' => $this->getResourceValue('agent_display_name'),
```

- [ ] **Step 6: Add order snapshot tests**

Add tests asserting site and agent order snapshots include `display_name` and `platform_plan_name`.

Expected site snapshot shape:

```php
$this->assertSame('光喵入门版', $context->pricing_snapshot['display_name']);
$this->assertSame('Starter', $context->pricing_snapshot['platform_plan_name']);
```

Expected agent snapshot shape:

```php
$this->assertSame('代理畅享版', $context->pricing_snapshot['display_name']);
$this->assertSame('Starter', $context->pricing_snapshot['platform_plan_name']);
```

- [ ] **Step 7: Implement order snapshot fields**

Add `display_name` and `platform_plan_name` to `resolveSalePrice()` return snapshots for both site and agent storefront services.

- [ ] **Step 8: Run backend focused tests**

Run:

```bash
vendor/bin/phpunit tests\Unit\Services\SiteStorefrontServiceTest.php tests\Unit\Services\AgentStorefrontServiceTest.php tests\Unit\Services\SiteCommerceServiceTest.php tests\Unit\Services\AgentCommerceServiceTest.php
```

Expected: PASS.

---

### Task 3: Backend API Payloads

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/SiteController.php`
- Modify: `app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `tests/Unit/Http/AdminSiteControllerTest.php`
- Modify: `tests/Unit/Http/UserAgentCommerceControllerTest.php`

- [ ] **Step 1: Write failing API tests**

Site API test should POST:

```php
'overrides' => [
    ['plan_id' => $plan->id, 'display_name' => '光喵入门版'],
],
```

Then assert `commerce` returns `display_name`.

Agent API test should POST:

```php
'overrides' => [
    ['plan_id' => $plan->id, 'display_name' => '代理畅享版'],
],
```

Then assert `prices` returns `display_name`.

- [ ] **Step 2: Run RED API tests**

Run:

```bash
vendor/bin/phpunit tests\Unit\Http\AdminSiteControllerTest.php tests\Unit\Http\UserAgentCommerceControllerTest.php --filter "display|override|price"
```

Expected: FAIL because controllers ignore `overrides`.

- [ ] **Step 3: Accept and save `overrides`**

Add validation:

```php
'overrides' => 'nullable|array',
'overrides.*.plan_id' => 'required|integer|min:1',
'overrides.*.display_name' => 'nullable|string|max:120',
```

Call the new `saveOverrides` methods inside existing transactions.

- [ ] **Step 4: Run API tests**

Run:

```bash
vendor/bin/phpunit tests\Unit\Http\AdminSiteControllerTest.php tests\Unit\Http\UserAgentCommerceControllerTest.php --filter "display|override|price"
```

Expected: PASS.

---

### Task 4: `keli-admin` Multi-Site UI

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src/services/site.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src/pages/system/PlatformSites.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src/locales/zh/translation.json`
- Modify: `C:\Users\Administrator\Documents\keli\keli-admin\src/locales/en/translation.json`

- [ ] **Step 1: Update service types**

Add `display_name` to `SitePlanPriceGroup` and add optional `overrides` to `SiteCommerceSavePayload`.

- [ ] **Step 2: Add display-name draft state**

Add:

```ts
const [displayNameDrafts, setDisplayNameDrafts] = useState<Record<number, string>>({});
```

Build it from `payload.prices`:

```ts
Object.fromEntries(payload.prices.map((plan) => [plan.plan_id, plan.display_name || '']))
```

- [ ] **Step 3: Save overrides with commerce payload**

Send:

```ts
overrides: Object.entries(displayNameDrafts).map(([planId, displayName]) => ({
  plan_id: Number(planId),
  display_name: displayName,
}))
```

- [ ] **Step 4: Add compact input to the pricing table**

In the plan cell, show platform plan name, plan ID, and an input with label text "站点展示名". Empty value inherits the platform name.

- [ ] **Step 5: Run admin build**

Run:

```bash
npm run build
```

Expected: PASS with only existing Vite chunk-size warnings.

---

### Task 5: `keli-user` Agent UI and Storefront

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src/services\agentCommerce.ts`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src/pages\AgentCenterPage.tsx`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src/locales\zh-CN\translation.json` or current locale path found by `rg "agentCenter"`
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src/locales\en\translation.json` or current locale path found by `rg "agentCenter"`

- [ ] **Step 1: Update agent service types**

Add `display_name` to `AgentPricePlan` and add `AgentPriceOverrideSaveItem`.

- [ ] **Step 2: Update price save API**

Change `savePrices` to send:

```ts
return api.post('/user/agent/prices', { items, overrides });
```

Keep compatibility by defaulting `overrides` to `[]`.

- [ ] **Step 3: Add display-name drafts in AgentCenter**

Build drafts from `agentPricePlans`, keyed by `plan_id`. Save them with price items.

- [ ] **Step 4: Add compact input to agent price table**

In the plan cell, show upstream plan name and an input with label text "代理展示名". Empty value inherits upstream name.

- [ ] **Step 5: Verify storefront display**

Because `PlanResource.name` now returns the resolved name, `StorePage` should keep working without custom mapping. Run a build to catch type errors.

Run:

```bash
npm run build
```

Expected: PASS with only existing Vite warnings.

---

### Task 6: Sync, Regression, and Commits

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\public\assets\admin-xboard`

- [ ] **Step 1: Run backend syntax checks**

Run:

```bash
php -l app\Services\SiteStorefrontService.php
php -l app\Services\AgentStorefrontService.php
php -l app\Http\Resources\PlanResource.php
php -l app\Http\Controllers\V2\Admin\SiteController.php
php -l app\Http\Controllers\V1\User\AgentCommerceController.php
```

Expected: all report `No syntax errors detected`.

- [ ] **Step 2: Run backend regression tests**

Run:

```bash
vendor/bin/phpunit tests\Unit\Services\SiteStorefrontServiceTest.php tests\Unit\Services\AgentStorefrontServiceTest.php tests\Unit\Services\SiteCommerceServiceTest.php tests\Unit\Services\AgentCommerceServiceTest.php tests\Unit\Http\AdminSiteControllerTest.php tests\Unit\Http\UserAgentCommerceControllerTest.php
```

Expected: PASS.

- [ ] **Step 3: Sync admin build to keliboard**

Run from `C:\Users\Administrator\Documents\keli\keli-admin`:

```bash
npm run sync:xboardpro
```

Expected: `keliboard/public/assets/admin-xboard/index.html` references a new JS hash.

- [ ] **Step 4: Run diff checks**

Run in each repository touched:

```bash
git diff --check
```

Expected: no whitespace errors.

- [ ] **Step 5: Commit and push**

Commit in `keliboard`:

```bash
git add app database tests public/assets/admin-xboard docs/superpowers
git commit -m "feat: add site and agent plan display names"
git push origin feature/platform-multisite-phase1
```

Commit in `keli-admin`:

```bash
git add src
git commit -m "feat: edit site plan display names"
git push origin main
```

Commit in `keli-user`:

```bash
git add src
git commit -m "feat: edit agent plan display names"
git push origin main
```

Expected: all pushed successfully.
