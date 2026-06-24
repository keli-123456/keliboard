# Agent Cost Site Boundary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make agent management platform-scoped while allowing each agent's platform cost to come from a dedicated source site.

**Architecture:** Add `cost_site_id` to `v2_agent_profile` and route all agent cost calculations through one resolver. Agent-created users stop inheriting the agent user's `site_id`; agent storefront sale prices remain per-agent; normal multi-site checkout remains unchanged.

**Tech Stack:** Laravel/PHP services and PHPUnit tests in `keliboard`; React/TypeScript admin UI in `keli-admin`; built admin assets synced into `keliboard/public/admin`.

---

## File Structure

- Modify `database/migrations/2026_06_15_000001_create_agent_center_tables.php` so fresh installs include `cost_site_id`.
- Create `database/migrations/2026_06_24_000002_add_cost_site_id_to_agent_profile.php` for upgrades.
- Modify `app/Models/AgentProfile.php` to expose the cost site relation.
- Create `app/Services/AgentCostService.php` as the single resolver for agent platform cost.
- Modify `app/Services/AgentCenterService.php` so application/unlock initializes `cost_site_id`, created sub-users are platform-scoped, and plan/reset costs use `AgentCostService`.
- Modify `app/Services/AgentCommerceService.php` so paid agent orders use `AgentCostService` and snapshots include the cost source.
- Modify `app/Services/TenantPlanCatalogService.php` and `app/Services/TenantPlanPricingService.php` so agent context is not filtered by site period visibility.
- Modify `app/Services/AgentOperationsService.php` and `app/Http/Controllers/V2/Admin/AgentOperationsController.php` so admins can see and update cost source.
- Modify `app/Http/Routes/V2/AdminRoute.php` to add the cost-site update route.
- Modify `tests/Support/InteractsWithInMemoryDatabase.php` and focused unit tests.
- Modify `C:/Users/Administrator/Documents/keli/keli-admin/src/services/agentOperations.ts` and `C:/Users/Administrator/Documents/keli/keli-admin/src/pages/agent/AgentOperationsPage.tsx` for the admin selector.
- Modify `C:/Users/Administrator/Documents/keli/keli-admin/src/locales/zh/translation.json` and `C:/Users/Administrator/Documents/keli/keli-admin/src/locales/en/translation.json`.

---

### Task 1: Add Agent Cost Site Schema

**Files:**
- Modify: `database/migrations/2026_06_15_000001_create_agent_center_tables.php`
- Create: `database/migrations/2026_06_24_000002_add_cost_site_id_to_agent_profile.php`
- Modify: `app/Models/AgentProfile.php`
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php`

- [ ] **Step 1: Update test schema helper first**

In `tests/Support/InteractsWithInMemoryDatabase.php`, add `cost_site_id` after `user_id` in `createAgentCenterTables()`:

```php
$table->integer('cost_site_id')->nullable()->index();
```

- [ ] **Step 2: Update fresh install migration**

In `database/migrations/2026_06_15_000001_create_agent_center_tables.php`, add:

```php
$table->unsignedInteger('cost_site_id')->nullable()->after('user_id')->index();
```

- [ ] **Step 3: Add upgrade migration**

Create `database/migrations/2026_06_24_000002_add_cost_site_id_to_agent_profile.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_profile')) {
            return;
        }

        if (!Schema::hasColumn('v2_agent_profile', 'cost_site_id')) {
            Schema::table('v2_agent_profile', function (Blueprint $table): void {
                $table->unsignedInteger('cost_site_id')->nullable()->after('user_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_agent_profile') || !Schema::hasColumn('v2_agent_profile', 'cost_site_id')) {
            return;
        }

        Schema::table('v2_agent_profile', function (Blueprint $table): void {
            $table->dropColumn('cost_site_id');
        });
    }
};
```

- [ ] **Step 4: Add model relation**

In `app/Models/AgentProfile.php`, import `App\Models\Site` and add:

```php
public function costSite(): BelongsTo
{
    return $this->belongsTo(Site::class, 'cost_site_id', 'id');
}
```

- [ ] **Step 5: Commit schema changes**

Run:

```bash
git add database/migrations/2026_06_15_000001_create_agent_center_tables.php database/migrations/2026_06_24_000002_add_cost_site_id_to_agent_profile.php app/Models/AgentProfile.php tests/Support/InteractsWithInMemoryDatabase.php
git commit -m "Add agent cost site schema"
```

Expected: commit succeeds.

---

### Task 2: Resolve Agent Costs From Cost Site

**Files:**
- Create: `app/Services/AgentCostService.php`
- Modify: `app/Services/AgentCommerceService.php`
- Modify: `app/Services/AgentCenterService.php`
- Test: `tests/Unit/Services/AgentCommerceServiceTest.php`
- Test: `tests/Unit/Services/AgentCenterServiceTest.php`

- [ ] **Step 1: Write failing commerce cost test**

Add to `tests/Unit/Services/AgentCommerceServiceTest.php`:

```php
public function test_agent_order_cost_uses_agent_cost_site_price(): void
{
    $this->createSiteTenantTables();
    $this->createSiteCommerceTables();

    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    AgentProfile::query()->where('user_id', $agent->id)->update(['cost_site_id' => $site->id]);
    $this->assignDomain($agent, 'agent.example.test');

    $buyer = $this->createUser('buyer@example.test');
    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
    DB::table('v2_site_plan_price')->insert([
        'site_id' => $site->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'sale_price' => 1300,
        'enabled' => 1,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

    $order = app(AgentCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('agent.example.test')
    );

    $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
    $this->assertSame(650, (int) $context->cost_amount);
    $this->assertSame($site->id, (int) $context->pricing_snapshot['cost_site_id']);
    $this->assertSame('site', $context->pricing_snapshot['cost_source']);
    $this->assertSame(1300, (int) $context->pricing_snapshot['cost_base_amount']);
}
```

- [ ] **Step 2: Write failing fallback cost test**

Add to `tests/Unit/Services/AgentCommerceServiceTest.php`:

```php
public function test_agent_order_cost_falls_back_to_platform_price_when_cost_site_period_is_missing(): void
{
    $this->createSiteTenantTables();
    $this->createSiteCommerceTables();

    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    AgentProfile::query()->where('user_id', $agent->id)->update(['cost_site_id' => $site->id]);
    $this->assignDomain($agent, 'agent.example.test');

    $buyer = $this->createUser('buyer@example.test');
    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
    $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 2500);

    $order = app(AgentCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('agent.example.test')
    );

    $context = AgentOrderContext::query()->where('order_id', $order->id)->firstOrFail();
    $this->assertSame(1000, (int) $context->cost_amount);
    $this->assertNull($context->pricing_snapshot['cost_site_id']);
    $this->assertSame('platform', $context->pricing_snapshot['cost_source']);
    $this->assertSame(2000, (int) $context->pricing_snapshot['cost_base_amount']);
}
```

- [ ] **Step 3: Run failing tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php --filter 'agent_order_cost_(uses_agent_cost_site_price|falls_back_to_platform_price)'
```

Expected before implementation: fail because `cost_site_id` is ignored and snapshot fields are missing.

- [ ] **Step 4: Create cost resolver service**

Create `app/Services/AgentCostService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentProfile;
use App\Models\Plan;
use App\Models\Site;
use App\Models\SitePlanPrice;
use App\Models\User;

class AgentCostService
{
    /**
     * @return array{period: string, base_amount: int, cost_site_id: ?int, cost_source: string}
     */
    public function resolveBase(User $agent, Plan $plan, string $period): array
    {
        $period = PlanService::getPeriodKey($period);
        $platformPrice = $plan->prices[$period] ?? null;
        if ($platformPrice === null || $platformPrice === '' || (float) $platformPrice < 0) {
            throw new ApiException('Period is not available');
        }

        $platformAmount = OrderService::amountToCents($platformPrice);
        $profile = AgentProfile::query()->where('user_id', $agent->id)->first();
        $costSiteId = $profile && $profile->cost_site_id ? (int) $profile->cost_site_id : null;

        if ($costSiteId !== null) {
            $site = Site::query()
                ->where('is_default', false)
                ->where('status', Site::STATUS_ACTIVE)
                ->find($costSiteId);
            $sitePrice = $site
                ? SitePlanPrice::query()
                    ->where('site_id', $costSiteId)
                    ->where('plan_id', $plan->id)
                    ->where('period', $period)
                    ->where('enabled', true)
                    ->first()
                : null;

            if ($sitePrice && (int) $sitePrice->sale_price >= 0) {
                return [
                    'period' => $period,
                    'base_amount' => (int) $sitePrice->sale_price,
                    'cost_site_id' => $costSiteId,
                    'cost_source' => 'site',
                ];
            }
        }

        return [
            'period' => $period,
            'base_amount' => $platformAmount,
            'cost_site_id' => null,
            'cost_source' => 'platform',
        ];
    }

    /**
     * @return array{period: string, amount: int, base_amount: int, discount_percent: float, cost_site_id: ?int, cost_source: string}
     */
    public function resolveDiscounted(User $agent, Plan $plan, string $period): array
    {
        $base = $this->resolveBase($agent, $plan, $period);
        $discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));

        return $base + [
            'amount' => (int) round($base['base_amount'] * ($discountPercent / 100)),
            'discount_percent' => $discountPercent,
        ];
    }
}
```

- [ ] **Step 5: Use resolver in `AgentCommerceService`**

Replace the body of `calculatePlatformCost()` in `app/Services/AgentCommerceService.php` with:

```php
public function calculatePlatformCost(User $agent, Plan $plan, string $period): array
{
    $this->activeProfile($agent);

    return app(AgentCostService::class)->resolveDiscounted($agent, $plan, $period);
}
```

In the pricing snapshot merge, change:

```php
'platform_base_amount' => (int) $cost['base_amount'],
'cost_amount' => (int) $cost['amount'],
'discount_percent' => (float) $cost['discount_percent'],
```

to:

```php
'platform_base_amount' => (int) $cost['base_amount'],
'cost_base_amount' => (int) $cost['base_amount'],
'cost_amount' => (int) $cost['amount'],
'discount_percent' => (float) $cost['discount_percent'],
'cost_site_id' => $cost['cost_site_id'],
'cost_source' => (string) $cost['cost_source'],
```

- [ ] **Step 6: Use resolver in `AgentCenterService`**

In `resolvePlanPrice()`, replace the platform-price base amount block:

```php
$baseAmount = OrderService::amountToCents($price);
$discountPercent = max(0, min(100, (float) admin_setting('agent_center_discount_percent', 100)));
$discountedAmount = (int) round($baseAmount * ($discountPercent / 100));
```

with:

```php
$cost = app(AgentCostService::class)->resolveDiscounted($this->currentPricingAgent(), $plan, $period);
$baseAmount = (int) $cost['base_amount'];
$discountPercent = (float) $cost['discount_percent'];
$discountedAmount = (int) $cost['amount'];
```

Add a private nullable property and helper to `AgentCenterService`:

```php
private ?User $pricingAgent = null;

private function currentPricingAgent(): User
{
    if (!$this->pricingAgent) {
        throw new ApiException('Agent user does not exist');
    }

    return $this->pricingAgent;
}
```

Wrap calls that resolve assignment pricing with:

```php
$this->pricingAgent = $agent;
try {
    $assignment = $this->resolveOptionalPlanPrice($payload);
} finally {
    $this->pricingAgent = null;
}
```

Do the same for preview/assign/reset pricing methods that call `resolvePlanPrice()` or `resetPrice()`.

- [ ] **Step 7: Run cost tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/AgentCenterServiceTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit cost resolver**

Run:

```bash
git add app/Services/AgentCostService.php app/Services/AgentCommerceService.php app/Services/AgentCenterService.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/AgentCenterServiceTest.php
git commit -m "Resolve agent costs from cost site"
```

Expected: commit succeeds.

---

### Task 3: Initialize Cost Site And Stop Sub-User Site Inheritance

**Files:**
- Modify: `app/Http/Controllers/V1/User/AgentController.php`
- Modify: `app/Services/AgentCenterService.php`
- Test: `tests/Unit/Services/AgentCenterServiceTest.php`

- [ ] **Step 1: Write failing profile initialization test**

Add:

```php
public function test_apply_from_site_initializes_agent_cost_site(): void
{
    $this->createSiteTenantTables();
    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $user = $this->createUser('agent@example.test', 0, ['site_id' => $site->id]);

    app(AgentCenterService::class)->apply($user, '申请代理');

    $profile = AgentProfile::query()->where('user_id', $user->id)->firstOrFail();
    $this->assertSame($site->id, (int) $profile->cost_site_id);
}
```

- [ ] **Step 2: Replace old sub-user inheritance test**

Replace `test_create_subordinate_scopes_duplicate_email_to_agent_site()` with:

```php
public function test_create_subordinate_uses_platform_scope_even_when_agent_has_site(): void
{
    $this->createSiteTenantTables();
    $defaultSite = $this->siteWithDomain('default', 'main.example.test', true);
    $secondSite = $this->siteWithDomain('second', 'second.example.test', false);
    $this->createUser('buyer@example.test', 0, ['site_id' => $defaultSite->id]);
    $agent = $this->createActiveAgent('agent@example.test', 10000);
    $agent->site_id = $secondSite->id;
    $agent->save();

    $created = app(AgentCenterService::class)->createSubordinate($agent, [
        'email' => 'buyer@example.test',
        'password' => 'secret123',
    ]);

    $subordinate = User::query()->findOrFail($created['user']['id']);
    $this->assertNull($subordinate->site_id);
    $this->assertSame(2, User::query()->where('email', 'buyer@example.test')->count());
}
```

- [ ] **Step 3: Run failing tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentCenterServiceTest.php --filter 'cost_site|platform_scope'
```

Expected before implementation: initialization test fails and subordinate still inherits agent `site_id`.

- [ ] **Step 4: Pass request to service**

In `app/Http/Controllers/V1/User/AgentController.php`, change:

```php
return $this->success($this->service()->unlock($request->user()));
```

to:

```php
return $this->success($this->service()->unlock($request->user(), $request));
```

and:

```php
return $this->success($this->service()->apply($request->user(), $params['message'] ?? null));
```

to:

```php
return $this->success($this->service()->apply($request->user(), $params['message'] ?? null, $request));
```

- [ ] **Step 5: Initialize `cost_site_id` in service**

Change signatures in `AgentCenterService`:

```php
public function unlock(User $agent, ?Request $request = null): array
public function apply(User $user, ?string $message = null, ?Request $request = null): array
```

Import `Illuminate\Http\Request`, `App\Models\Site`, and add:

```php
private function resolveInitialCostSiteId(User $user, ?Request $request = null): ?int
{
    $siteId = null;
    if ($request) {
        $context = app(SiteContextService::class)->resolve($request, $user);
        $siteId = isset($context['site_id']) ? (int) $context['site_id'] : null;
    }
    if (!$siteId && $user->site_id) {
        $siteId = (int) $user->site_id;
    }
    if (!$siteId) {
        return null;
    }

    return Site::query()
        ->where('is_default', false)
        ->where('status', Site::STATUS_ACTIVE)
        ->whereKey($siteId)
        ->exists()
        ? $siteId
        : null;
}
```

When `unlock()` or `apply()` calls `AgentProfile::query()->updateOrCreate()`, include:

```php
'cost_site_id' => $profile?->cost_site_id ?? $this->resolveInitialCostSiteId($agent, $request),
```

Use `$user` instead of `$agent` inside `apply()`.

- [ ] **Step 6: Stop inheriting site for created users**

In `createSubordinate()`, change duplicate email query to platform scope:

```php
app(SiteUserScopeService::class)
    ->scopeUserQueryForSiteId(User::query(), null)
    ->where('email', $email)
    ->exists()
```

Change created user payload:

```php
'site_id' => null,
```

- [ ] **Step 7: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentCenterServiceTest.php
```

Expected: PASS.

- [ ] **Step 8: Commit ownership boundary**

Run:

```bash
git add app/Http/Controllers/V1/User/AgentController.php app/Services/AgentCenterService.php tests/Unit/Services/AgentCenterServiceTest.php
git commit -m "Separate agent cost site from user site ownership"
```

Expected: commit succeeds.

---

### Task 4: Remove Site Filtering From Agent Catalog And Pricing

**Files:**
- Modify: `app/Services/TenantPlanCatalogService.php`
- Modify: `app/Services/TenantPlanPricingService.php`
- Test: `tests/Unit/Services/TenantPlanCatalogServiceTest.php`
- Test: `tests/Unit/Services/TenantPlanPricingServiceTest.php`

- [ ] **Step 1: Update catalog test expectation**

Replace `test_agent_bound_user_catalog_respects_site_visible_periods()` with:

```php
public function test_agent_bound_user_catalog_uses_agent_prices_without_site_period_filtering(): void
{
    $this->createSiteTenantTables();
    $this->createSiteCommerceTables();
    $this->createAgentCenterTables();
    $this->createAgentCommerceTables();

    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $agent = $this->createUser('agent@example.test', 0, ['site_id' => $site->id]);
    $buyer = $this->createUser('buyer@example.test', 0, ['site_id' => $site->id]);
    DB::table('v2_agent_user')->insert([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 200.00,
    ]);
    DB::table('v2_site_plan_price')->insert([
        'site_id' => $site->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'sale_price' => 1300,
        'enabled' => 1,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    DB::table('v2_agent_plan_price')->insert([
        'agent_user_id' => $agent->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_YEARLY,
        'sale_price' => 9900,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $request = Request::create('/api/v1/user/plan/fetch', 'GET');
    $request->setUserResolver(fn () => $buyer);

    $plans = app(TenantPlanCatalogService::class)->plansForRequest($request, collect([$plan]), $buyer);

    $this->assertCount(1, $plans);
    $this->assertArrayHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
    $this->assertArrayNotHasKey(Plan::PERIOD_MONTHLY, $plans[0]->prices);
}
```

- [ ] **Step 2: Update pricing test expectation**

Replace `test_agent_bound_user_price_rejects_period_hidden_by_site()` with:

```php
public function test_agent_bound_user_price_ignores_site_period_visibility(): void
{
    $this->createSiteTenantTables();
    $this->createSiteCommerceTables();
    $this->createAgentCenterTables();
    $this->createAgentCommerceTables();

    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $agent = $this->createUser('agent@example.test');
    $buyer = $this->createUser('buyer@example.test', 0, ['site_id' => $site->id]);
    DB::table('v2_agent_user')->insert([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 200.00,
    ]);
    DB::table('v2_agent_plan_price')->insert([
        'agent_user_id' => $agent->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_YEARLY,
        'sale_price' => 9900,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $result = app(TenantPlanPricingService::class)->resolveForUser($buyer, $plan, Plan::PERIOD_YEARLY);

    $this->assertSame('agent', $result['source']);
    $this->assertSame(9900, $result['sale_amount']);
    $this->assertNull($result['site_context']);
}
```

- [ ] **Step 3: Run failing tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/TenantPlanCatalogServiceTest.php tests/Unit/Services/TenantPlanPricingServiceTest.php
```

Expected before implementation: old site filtering blocks yearly agent price.

- [ ] **Step 4: Simplify catalog agent branch**

In `TenantPlanCatalogService::plansForRequest()`, replace:

```php
$siteDecoratedPlans = app(SiteStorefrontService::class)->plansForRequest($request, $platformPlans);

return app(AgentStorefrontService::class)->plansForRequest($request, $siteDecoratedPlans);
```

with:

```php
return app(AgentStorefrontService::class)->plansForRequest($request, $platformPlans);
```

- [ ] **Step 5: Remove site validation for agent pricing**

In `TenantPlanPricingService`, remove all calls to `validateSitePeriodIfPresent()` inside agent-context branches and delete the private method.

- [ ] **Step 6: Run tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/TenantPlanCatalogServiceTest.php tests/Unit/Services/TenantPlanPricingServiceTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit pricing boundary**

Run:

```bash
git add app/Services/TenantPlanCatalogService.php app/Services/TenantPlanPricingService.php tests/Unit/Services/TenantPlanCatalogServiceTest.php tests/Unit/Services/TenantPlanPricingServiceTest.php
git commit -m "Keep agent pricing independent from site storefront rules"
```

Expected: commit succeeds.

---

### Task 5: Add Admin Cost Source API

**Files:**
- Modify: `app/Services/AgentOperationsService.php`
- Modify: `app/Http/Controllers/V2/Admin/AgentOperationsController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Test: `tests/Unit/Services/AgentOperationsServiceTest.php`
- Test: add or update admin route/controller test if one exists

- [ ] **Step 1: Add failing service test**

Add to `tests/Unit/Services/AgentOperationsServiceTest.php`:

```php
public function test_admin_can_update_agent_cost_site(): void
{
    $this->createSiteTenantTables();
    $site = $this->siteWithDomain('gm', 'gm.example.test', false);
    $agent = $this->createActiveAgent('agent@example.test', 10000);

    $detail = app(AgentOperationsService::class)->updateAgentCostSite($agent->id, $site->id);

    $this->assertSame($site->id, $detail['cost_site']['id']);
    $this->assertSame('gm', $detail['cost_site']['code']);
    $this->assertSame($site->id, (int) AgentProfile::query()->where('user_id', $agent->id)->value('cost_site_id'));

    $detail = app(AgentOperationsService::class)->updateAgentCostSite($agent->id, null);

    $this->assertNull($detail['cost_site']);
    $this->assertNull(AgentProfile::query()->where('user_id', $agent->id)->value('cost_site_id'));
}
```

- [ ] **Step 2: Run failing service test**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentOperationsServiceTest.php --filter update_agent_cost_site
```

Expected before implementation: method does not exist.

- [ ] **Step 3: Add service methods**

In `app/Services/AgentOperationsService.php`, import `App\Models\AgentProfile` and `App\Models\Site`. Add cost site payload into `adminAgents()` and `adminAgentDetail()` row merges:

```php
'cost_site' => $this->costSitePayload($agent),
```

Add:

```php
public function updateAgentCostSite(int $agentUserId, ?int $siteId): array
{
    $agent = User::query()->find($agentUserId);
    if ($agent === null) {
        throw new ApiException('Agent not found');
    }

    if ($siteId !== null) {
        $site = Site::query()
            ->where('is_default', false)
            ->where('status', Site::STATUS_ACTIVE)
            ->find($siteId);
        if (!$site) {
            throw new ApiException('Site is not available');
        }
    }

    AgentProfile::query()->updateOrCreate(
        ['user_id' => $agent->id],
        [
            'cost_site_id' => $siteId,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'updated_at' => time(),
        ]
    );

    return $this->adminAgentDetail((int) $agent->id);
}

private function costSitePayload(User $agent): ?array
{
    $profile = AgentProfile::query()->where('user_id', $agent->id)->first();
    $siteId = $profile && $profile->cost_site_id ? (int) $profile->cost_site_id : null;
    if (!$siteId) {
        return null;
    }

    $site = Site::query()->find($siteId);
    if (!$site) {
        return ['id' => $siteId, 'code' => '', 'name' => '', 'status' => 'missing'];
    }

    return [
        'id' => (int) $site->id,
        'code' => (string) $site->code,
        'name' => (string) $site->name,
        'status' => (string) $site->status,
    ];
}
```

- [ ] **Step 4: Add controller and route**

In `AgentOperationsController`, add:

```php
public function updateAgentCostSite(Request $request, int $agentUserId)
{
    $params = $request->validate([
        'cost_site_id' => 'nullable|integer|min:1',
    ]);

    $siteId = array_key_exists('cost_site_id', $params) && $params['cost_site_id']
        ? (int) $params['cost_site_id']
        : null;

    return $this->success($this->service()->updateAgentCostSite($agentUserId, $siteId));
}
```

In `AdminRoute.php`, add:

```php
$router->post('/agents/{agentUserId}/cost-site', [AgentOperationsController::class, 'updateAgentCostSite']);
```

- [ ] **Step 5: Run service tests**

Run:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentOperationsServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit API**

Run:

```bash
git add app/Services/AgentOperationsService.php app/Http/Controllers/V2/Admin/AgentOperationsController.php app/Http/Routes/V2/AdminRoute.php tests/Unit/Services/AgentOperationsServiceTest.php
git commit -m "Expose agent cost site management"
```

Expected: commit succeeds.

---

### Task 6: Add Admin UI Selector

**Files:**
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/services/agentOperations.ts`
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/pages/agent/AgentOperationsPage.tsx`
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/locales/zh/translation.json`
- Modify: `C:/Users/Administrator/Documents/keli/keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Extend service types**

In `src/services/agentOperations.ts`, add:

```ts
export interface AgentCostSite {
  id: number;
  code?: string;
  name?: string;
  status?: string;
}
```

Add `cost_site?: AgentCostSite | null;` to `AgentOperationsAgentRow` and `AgentOperationsAgentDetail`.

Add API method:

```ts
updateCostSite: (agentUserId: number, costSiteId: number | null) =>
  adminApi.post<ApiSuccess<AgentOperationsAgentDetail>>(`/agent-operations/agents/${agentUserId}/cost-site`, {
    cost_site_id: costSiteId,
  }),
```

- [ ] **Step 2: Load sites in page**

In `AgentOperationsPage.tsx`, import `siteService`:

```ts
import { siteService, type SiteItem } from "@/services/site";
```

Add state:

```ts
const [sites, setSites] = useState<SiteItem[]>([]);
const [costSiteSaving, setCostSiteSaving] = useState(false);
```

In `loadOverview()`, include `siteService.fetch()` in the `Promise.all()` and set only non-default active sites:

```ts
const [summaryResp, agentsResp, sitesResp] = await Promise.all([
  agentOperationsService.summary(),
  agentOperationsService.agents({ page: 1, page_size: 30, keyword: query.trim(), abnormal: abnormalOnly }),
  siteService.fetch(),
]);
setSites((requireEnvelopeData(sitesResp.data, "site.fetch") || []).filter((site) => !site.is_default && site.status === "active"));
```

- [ ] **Step 3: Add save handler**

Add:

```ts
const updateCostSite = async (value: string) => {
  if (!selectedAgentId) return;
  const costSiteId = value ? Number(value) : null;
  setCostSiteSaving(true);
  try {
    const resp = await agentOperationsService.updateCostSite(selectedAgentId, costSiteId);
    setSelectedAgent(requireEnvelopeData(resp.data, "agent-operations.cost-site") || null);
    toast.success(t("agent_operations.cost_site_saved"));
    await loadOverview();
  } catch (e) {
    toast.error(getErrorMessage(e, t("agent_operations.action_failed")));
  } finally {
    setCostSiteSaving(false);
  }
};
```

- [ ] **Step 4: Render selector in detail card**

Above the domains/payments grid, add:

```tsx
<div className="rounded-lg border border-border/70 p-3">
  <div className="text-sm font-semibold">{t("agent_operations.cost_site")}</div>
  <div className="mt-1 text-xs text-muted-foreground">{t("agent_operations.cost_site_desc")}</div>
  <select
    className="mt-3 h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
    value={selectedAgent?.cost_site?.id ? String(selectedAgent.cost_site.id) : ""}
    disabled={costSiteSaving}
    onChange={(event) => void updateCostSite(event.target.value)}
  >
    <option value="">{t("agent_operations.cost_site_platform")}</option>
    {sites.map((site) => (
      <option key={site.id} value={site.id}>
        {site.name} / {site.code}
      </option>
    ))}
  </select>
</div>
```

- [ ] **Step 5: Add translations**

In zh:

```json
"cost_site": "成本来源",
"cost_site_desc": "代理订单扣款按这里的套餐成本计算；代理管理仍由主站统一控制。",
"cost_site_platform": "主站套餐价格",
"cost_site_saved": "成本来源已更新"
```

In en:

```json
"cost_site": "Cost source",
"cost_site_desc": "Agent order cost is calculated from this source; agent management stays platform-scoped.",
"cost_site_platform": "Platform plan price",
"cost_site_saved": "Cost source updated"
```

- [ ] **Step 6: Run admin verification**

Run:

```bash
npm run lint
npm run build
```

Expected: both PASS.

- [ ] **Step 7: Commit admin UI**

Run in `C:/Users/Administrator/Documents/keli/keli-admin`:

```bash
git add src/services/agentOperations.ts src/pages/agent/AgentOperationsPage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Add agent cost source selector"
```

Expected: commit succeeds.

---

### Task 7: Full Verification, Sync Admin, And Push

**Files:**
- Modify built assets under `C:/Users/Administrator/Documents/keli/keliboard/public/admin` after admin build sync.

- [ ] **Step 1: Run backend targeted tests**

Run in `keliboard`:

```bash
vendor/bin/phpunit tests/Unit/Services/AgentCenterServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/TenantPlanCatalogServiceTest.php tests/Unit/Services/TenantPlanPricingServiceTest.php tests/Unit/Services/AgentOperationsServiceTest.php
```

Expected: PASS. If local `php` is unavailable, record that and rely on CI/server verification.

- [ ] **Step 2: Run syntax check on modified PHP files**

Run:

```bash
php -l app/Services/AgentCostService.php
php -l app/Services/AgentCenterService.php
php -l app/Services/AgentCommerceService.php
php -l app/Services/TenantPlanCatalogService.php
php -l app/Services/TenantPlanPricingService.php
php -l app/Services/AgentOperationsService.php
php -l app/Http/Controllers/V2/Admin/AgentOperationsController.php
php -l database/migrations/2026_06_24_000002_add_cost_site_id_to_agent_profile.php
```

Expected: each reports `No syntax errors detected`. If local `php` is unavailable, record the limitation.

- [ ] **Step 3: Build and sync admin assets**

Run in `keli-admin`:

```bash
npm run build
```

Then sync built assets into `keliboard` using the repo's existing sync command. If the repo already has a sync script, use it; otherwise copy the build output exactly as existing project workflow expects and include the changed `public/admin` files in the backend commit.

- [ ] **Step 4: Run diff checks**

Run in both repos:

```bash
git diff --check
git status --short
```

Expected: no whitespace errors; only intended files changed.

- [ ] **Step 5: Commit synced backend assets**

Run in `keliboard`:

```bash
git add public/admin
git commit -m "Sync admin cost site selector assets"
```

Expected: commit succeeds if built assets changed. If no asset changes are present because sync is unnecessary, skip this commit and note it.

- [ ] **Step 6: Push both repos**

Run:

```bash
git -C C:/Users/Administrator/Documents/keli/keli-admin push
git -C C:/Users/Administrator/Documents/keli/keliboard push
```

Expected: both pushes succeed.

---

## Self-Review Notes

- Spec coverage: schema, initialization, cost resolution, user ownership, order snapshots, admin UX, fallback behavior, and tests are covered.
- Boundary check: normal non-agent site checkout remains in `SiteStorefrontService`; agent context bypasses site period filtering but uses `cost_site_id` for cost only.
- Scope check: this plan does not add per-agent custom wholesale tables; it only implements the approved `cost_site_id` boundary.
