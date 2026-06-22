# Platform Multi-Site Commerce Phase 2A Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the backend commerce foundation for first-party multi-site storefronts: site settings, site prices, site payments, site-aware auth, site-aware plan display, and site order snapshots.

**Architecture:** Keep first-party site behavior separate from agent commerce. `SiteResolver` resolves the base platform site, `SiteContextService` resolves the effective site for guest/authenticated requests, `SiteStorefrontService` applies site prices/settings/payments, and `SiteCommerceService` creates first-party site order context records while leaving agent-domain orders untouched.

**Tech Stack:** Laravel/PHP 8.2, Eloquent models, integer timestamp migrations, PHPUnit unit tests, existing `PlanResource`, existing agent-commerce service patterns.

---

## Scope

This plan intentionally covers backend Phase 2A only. `keli-user` site provider and `keli-admin` management screens are Phase 2B after these APIs are stable.

Phase 2A must preserve:

- Existing default-site behavior for legacy installs.
- Existing platform plan and order behavior on the default site.
- Existing agent-domain commerce behavior, including agent prices, holds, and payment ownership.
- Existing node/subscription behavior.

## File Structure

- Modify `database/migrations/2026_06_22_000001_create_site_tenant_tables.php`
  - Only if rebasing Phase 1 exposes migration conflicts. Keep it focused on `v2_site`, `v2_site_domain`, and nullable `site_id` columns.
- Create `database/migrations/2026_06_22_000002_create_site_commerce_tables.php`
  - Adds `v2_site_setting`, `v2_site_plan_price`, `v2_site_payment`, `v2_site_order_context`.
- Create `app/Models/SiteSetting.php`
  - Eloquent model for site display/support/landing overrides.
- Create `app/Models/SitePlanPrice.php`
  - Eloquent model for per-site enabled plan period prices stored in cents.
- Create `app/Models/SitePayment.php`
  - Eloquent model for platform payment methods enabled per site.
- Create `app/Models/SiteOrderContext.php`
  - Eloquent model for order-level site snapshots.
- Modify `app/Models/Site.php`
  - Adds relationships: `setting`, `prices`, `payments`, `orderContexts`.
- Modify `app/Models/SiteDomain.php`
  - No behavior change unless rebase requires import cleanup.
- Modify `app/Models/User.php`
  - Adds `site()` relation and `site_id` property compatibility.
- Modify `app/Models/Order.php`
  - Adds `site()` and `siteOrderContext()` relations plus `site_id` property compatibility.
- Modify `app/Services/SiteResolver.php`
  - Preserve Phase 1 host normalization and default fallback.
- Create `app/Services/SiteContextService.php`
  - Resolves effective site for guest/authenticated requests and returns normalized payloads.
- Create `app/Services/SiteStorefrontService.php`
  - Lists/saves site prices, applies site prices to plans, resolves sale price, filters payment methods.
- Create `app/Services/SiteCommerceService.php`
  - Creates first-party site order context, assigns site payments at checkout, and exposes context for orders.
- Modify `app/Services/Auth/RegisterService.php`
  - Registers users under the resolved first-party site.
- Modify `app/Services/Auth/LoginService.php`
  - Finds users by current site and email, with default-site legacy fallback.
- Modify `app/Http/Controllers/V1/Passport/AuthController.php`
  - Passes request context into login/password reset methods.
- Modify `app/Http/Controllers/V1/Guest/CommController.php`
  - Applies first-party site settings before agent public config.
- Modify `app/Http/Controllers/V1/User/CommController.php`
  - Returns authenticated site context fields.
- Modify `app/Http/Controllers/V1/Guest/PlanController.php`
  - Applies site prices before agent storefront prices.
- Modify `app/Http/Controllers/V1/User/PlanController.php`
  - Applies site prices before agent storefront prices.
- Modify `app/Http/Controllers/V1/User/OrderController.php`
  - Creates first-party site orders when the request is not an agent order, and filters site payment methods.
- Modify `app/Http/Controllers/V2/Admin/SiteController.php`
  - Adds settings, prices, and payments payloads/save endpoints.
- Modify `app/Http/Routes/V1/UserRoute.php`
  - Adds `/user/site-context`.
- Modify `app/Http/Routes/V1/GuestRoute.php`
  - Adds `/guest/site-context`.
- Modify `app/Http/Routes/V2/AdminRoute.php`
  - Adds site settings/prices/payments admin routes if not already present after Phase 1 rebase.
- Modify `tests/Support/InteractsWithInMemoryDatabase.php`
  - Adds `site_id` columns and `createSiteTenantTables()` / `createSiteCommerceTables()`.
- Create tests:
  - `tests/Unit/Services/SiteContextServiceTest.php`
  - `tests/Unit/Services/SiteStorefrontServiceTest.php`
  - `tests/Unit/Services/SiteCommerceServiceTest.php`
  - `tests/Unit/Http/SiteAuthContextTest.php`
  - `tests/Unit/Http/SitePlanControllerTest.php`
  - `tests/Unit/Http/SiteOrderFlowTest.php`
  - `tests/Unit/Http/AdminSiteCommerceControllerTest.php`

---

### Task 1: Rebase Phase 1 and Preserve Recent Main Fixes

**Files:**
- Modify only conflicted files from `feature/platform-multisite-phase1`
- Test: existing agent and site tests

- [ ] **Step 1: Move to the existing Phase 1 branch**

Run:

```powershell
git switch feature/platform-multisite-phase1
```

Expected: branch switches cleanly or shows only unrelated untracked files. Do not discard untracked files.

- [ ] **Step 2: Rebase on current main**

Run:

```powershell
git fetch origin
git rebase main
```

Expected: either a clean rebase or conflicts in files touched by both Phase 1 and recent agent-domain fixes.

- [ ] **Step 3: Resolve conflicts by keeping both domains**

For `app/Services/AgentDomainResolver.php`, preserve the current `main` behavior:

```php
$host = (string) ($request->headers->get('x-forwarded-host') ?: $request->headers->get('host', ''));
$host = trim(explode(',', $host, 2)[0]);
```

For `app/Services/AgentCommerceContextResolver.php`, preserve the current `main` behavior that prioritizes an active agent domain over user binding when the request host belongs to the same agent. Do not replace it with Phase 1's older version.

For `app/Http/Requests/User/OrderSave.php`, preserve current `main` order validation. Do not delete `tests/Unit/Http/OrderSaveRequestTest.php`.

For `app/Services/AgentDomainSelfService.php`, preserve the current `main` domain verification behavior. Phase 1 must not delete the recent DoH/fallback/domain changes.

- [ ] **Step 4: Continue the rebase**

Run:

```powershell
git add app tests database docs
git rebase --continue
```

Expected: rebase completes and `git log --oneline --decorate -5` shows Phase 1 commits on top of current `main`.

- [ ] **Step 5: Run regression tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php tests/Unit/Http/AdminSiteControllerTest.php tests/Unit/Services/AgentDomainResolverTest.php tests/Unit/Services/AgentCommerceContextResolverTest.php tests/Unit/Services/AgentDomainSelfServiceTest.php tests/Unit/Http/OrderSaveRequestTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit the rebase resolution only if conflicts created a new merge-style change**

Usually a rebase does not need an extra commit. If conflict resolution produced unstaged changes after rebase, commit:

```powershell
git add app tests database docs
git commit -m "chore: rebase multisite foundation"
```

Expected: no unrelated files are included.

---

### Task 2: Site Commerce Tables and Models

**Files:**
- Create `database/migrations/2026_06_22_000002_create_site_commerce_tables.php`
- Create `app/Models/SiteSetting.php`
- Create `app/Models/SitePlanPrice.php`
- Create `app/Models/SitePayment.php`
- Create `app/Models/SiteOrderContext.php`
- Modify `app/Models/Site.php`
- Modify `app/Models/Order.php`
- Modify `app/Models/User.php`
- Modify `tests/Support/InteractsWithInMemoryDatabase.php`
- Test `tests/Unit/Services/SiteContextServiceTest.php`

- [ ] **Step 1: Write the failing schema/model test**

Create `tests/Unit/Services/SiteContextServiceTest.php` with this initial test:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteSetting;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->createSiteCommerceTables();
    }

    public function test_site_setting_belongs_to_site(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $setting = SiteSetting::query()->create([
            'site_id' => $site->id,
            'site_name' => 'Cheap Cloud',
            'logo_url' => 'https://cdn.example.test/logo.png',
            'landing_theme' => 'sakura',
            'accent_color' => '#f43f5e',
            'support_name' => 'Cheap Support',
            'support_url' => 'https://t.me/support',
            'announcement' => 'Welcome',
            'seo_title' => 'Cheap Cloud',
            'seo_description' => 'Fast access',
            'enabled' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->assertSame($site->id, (int) $setting->site->id);
        $this->assertSame('Cheap Cloud', $site->fresh(['setting'])->setting->site_name);
    }
}
```

- [ ] **Step 2: Run the failing test**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteContextServiceTest.php
```

Expected: FAIL because `createSiteCommerceTables()` and `SiteSetting` do not exist.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_06_22_000002_create_site_commerce_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site_setting')) {
            Schema::create('v2_site_setting', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->unique();
                $table->string('site_name', 120)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('landing_theme', 64)->nullable();
                $table->string('accent_color', 16)->nullable();
                $table->string('support_name', 120)->nullable();
                $table->string('support_url', 500)->nullable();
                $table->string('announcement', 1000)->nullable();
                $table->string('seo_title', 160)->nullable();
                $table->string('seo_description', 255)->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_site_plan_price')) {
            Schema::create('v2_site_plan_price', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('plan_id')->index();
                $table->string('period', 32);
                $table->integer('sale_price')->default(0);
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['site_id', 'plan_id', 'period'], 'uniq_site_plan_period');
            });
        }

        if (!Schema::hasTable('v2_site_payment')) {
            Schema::create('v2_site_payment', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('payment_id')->index();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('sort')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['site_id', 'payment_id'], 'uniq_site_payment');
            });
        }

        if (!Schema::hasTable('v2_site_order_context')) {
            Schema::create('v2_site_order_context', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('order_id')->unique();
                $table->string('trade_no', 64)->unique();
                $table->unsignedInteger('site_id')->index();
                $table->unsignedInteger('site_domain_id')->nullable()->index();
                $table->integer('sale_amount')->default(0);
                $table->integer('platform_plan_price')->default(0);
                $table->json('pricing_snapshot')->nullable();
                $table->json('domain_snapshot')->nullable();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_site_order_context');
        Schema::dropIfExists('v2_site_payment');
        Schema::dropIfExists('v2_site_plan_price');
        Schema::dropIfExists('v2_site_setting');
    }
};
```

- [ ] **Step 4: Add models**

Create `app/Models/SiteSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteSetting extends Model
{
    protected $table = 'v2_site_setting';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }
}
```

Create `app/Models/SitePlanPrice.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePlanPrice extends Model
{
    protected $table = 'v2_site_plan_price';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'sale_price' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }
}
```

Create `app/Models/SitePayment.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePayment extends Model
{
    protected $table = 'v2_site_payment';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'sort' => 'integer',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id', 'id');
    }
}
```

Create `app/Models/SiteOrderContext.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteOrderContext extends Model
{
    protected $table = 'v2_site_order_context';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'sale_amount' => 'integer',
        'platform_plan_price' => 'integer',
        'pricing_snapshot' => 'array',
        'domain_snapshot' => 'array',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(SiteDomain::class, 'site_domain_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }
}
```

- [ ] **Step 5: Add relationships**

In `app/Models/Site.php`, add:

```php
public function setting(): HasOne
{
    return $this->hasOne(SiteSetting::class, 'site_id', 'id');
}

public function prices(): HasMany
{
    return $this->hasMany(SitePlanPrice::class, 'site_id', 'id');
}

public function payments(): HasMany
{
    return $this->hasMany(SitePayment::class, 'site_id', 'id');
}

public function orderContexts(): HasMany
{
    return $this->hasMany(SiteOrderContext::class, 'site_id', 'id');
}
```

Also import `HasOne`.

In `app/Models/User.php`, add:

```php
public function site(): BelongsTo
{
    return $this->belongsTo(Site::class, 'site_id', 'id');
}
```

In `app/Models/Order.php`, add:

```php
public function site(): BelongsTo
{
    return $this->belongsTo(Site::class, 'site_id', 'id');
}

public function siteOrderContext(): HasOne
{
    return $this->hasOne(SiteOrderContext::class, 'order_id', 'id');
}
```

Also import `HasOne`.

- [ ] **Step 6: Extend the in-memory schema helper**

Add `site_id` after `id` in `createUserTable()` and `createOrderTable()`:

```php
$table->integer('site_id')->nullable()->index();
```

Add `createSiteTenantTables()` and `createSiteCommerceTables()` to match the migrations exactly enough for unit tests.

- [ ] **Step 7: Run the model test**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteContextServiceTest.php
```

Expected: PASS for the initial model relationship test.

- [ ] **Step 8: Commit**

Run:

```powershell
git add database/migrations app/Models tests/Support tests/Unit/Services/SiteContextServiceTest.php
git commit -m "feat: add site commerce schema"
```

---

### Task 3: Effective Site Context and Public Config

**Files:**
- Create `app/Services/SiteContextService.php`
- Create `app/Http/Controllers/V1/Guest/SiteContextController.php`
- Create `app/Http/Controllers/V1/User/SiteContextController.php`
- Modify `app/Http/Routes/V1/GuestRoute.php`
- Modify `app/Http/Routes/V1/UserRoute.php`
- Modify `app/Http/Controllers/V1/Guest/CommController.php`
- Modify `app/Http/Controllers/V1/User/CommController.php`
- Test `tests/Unit/Services/SiteContextServiceTest.php`
- Test `tests/Unit/Http/GuestSiteContextControllerTest.php`

- [ ] **Step 1: Add failing context tests**

Append to `tests/Unit/Services/SiteContextServiceTest.php`:

```php
public function test_guest_context_uses_request_host_settings(): void
{
    [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    SiteSetting::query()->create([
        'site_id' => $site->id,
        'site_name' => 'Cheap Cloud',
        'logo_url' => 'https://cdn.example.test/logo.png',
        'landing_theme' => 'sakura',
        'accent_color' => '#f43f5e',
        'support_name' => 'Cheap Support',
        'support_url' => 'https://t.me/cheap',
        'announcement' => 'Cheap announcement',
        'seo_title' => 'Cheap SEO',
        'seo_description' => 'Cheap description',
        'enabled' => true,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $context = app(\App\Services\SiteContextService::class)->resolve(
        \Illuminate\Http\Request::create('/api/v1/guest/site-context', 'GET', [], [], [], ['HTTP_HOST' => 'cheap.example.test'])
    );

    $this->assertSame($site->id, $context['id']);
    $this->assertSame('cheap', $context['site_code']);
    $this->assertSame('Cheap Cloud', $context['site_name']);
    $this->assertSame('sakura', $context['landing_theme']);
    $this->assertSame('cheap.example.test', $context['domain']);
}

public function test_authenticated_context_prefers_user_site_over_request_host(): void
{
    [$cheap] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    [$default] = $this->siteWithDomain('default', 'Default Site', 'main.example.test', true);
    $user = User::query()->create([
        'site_id' => $cheap->id,
        'email' => 'buyer@example.test',
        'password' => password_hash('secret123', PASSWORD_BCRYPT),
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $request = \Illuminate\Http\Request::create('/api/v1/user/site-context', 'GET', [], [], [], ['HTTP_HOST' => 'main.example.test']);
    $request->setUserResolver(fn () => $user);

    $context = app(\App\Services\SiteContextService::class)->resolve($request, $user);

    $this->assertSame($cheap->id, $context['id']);
    $this->assertSame('user', $context['source']);
    $this->assertSame('cheap', $context['site_code']);
}
```

Add private helper:

```php
private function siteWithDomain(string $code, string $name, string $domain, bool $default = false): array
{
    $site = Site::query()->create([
        'code' => $code,
        'name' => $name,
        'status' => Site::STATUS_ACTIVE,
        'is_default' => $default,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $domainRow = \App\Models\SiteDomain::query()->create([
        'site_id' => $site->id,
        'domain' => $domain,
        'status' => \App\Models\SiteDomain::STATUS_ACTIVE,
        'is_primary' => true,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    return [$site, $domainRow];
}
```

- [ ] **Step 2: Run the failing context tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteContextServiceTest.php
```

Expected: FAIL because `SiteContextService` does not exist.

- [ ] **Step 3: Implement `SiteContextService`**

Create `app/Services/SiteContextService.php`:

```php
<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Request;

class SiteContextService
{
    public function resolve(Request $request, ?User $user = null): array
    {
        $user = $user ?: $request->user();
        if ($user instanceof User && $user->site_id) {
            $site = Site::query()
                ->with('setting')
                ->where('id', (int) $user->site_id)
                ->where('status', Site::STATUS_ACTIVE)
                ->first();
            if ($site) {
                return $this->payload($site, null, 'user');
            }
        }

        $context = app(SiteResolver::class)->resolveRequest($request);
        $site = Site::query()
            ->with('setting')
            ->find((int) $context['site_id']);

        if (!$site) {
            $site = app(SiteResolver::class)->defaultSite()->load('setting');
            return $this->payload($site, null, 'default');
        }

        $domain = !empty($context['site_domain_id'])
            ? SiteDomain::query()->find((int) $context['site_domain_id'])
            : null;

        return $this->payload($site, $domain, (string) $context['source']);
    }

    public function applyToConfig(array $config, Request $request, ?User $user = null): array
    {
        $site = $this->resolve($request, $user);
        if (!empty($site['site_name'])) {
            $config['app_name'] = $site['site_name'];
            $config['website_name'] = $site['site_name'];
        }
        if (!empty($site['logo_url'])) {
            $config['logo'] = $site['logo_url'];
        }
        if (!empty($site['landing_theme'])) {
            $config['landing_theme'] = $site['landing_theme'];
        }
        if (!empty($site['support_name'])) {
            $config['customer_service_name'] = $site['support_name'];
        }
        if (!empty($site['support_url'])) {
            $config['customer_service_url'] = $site['support_url'];
        }
        $config['site_context'] = $site;

        return $config;
    }

    private function payload(Site $site, ?SiteDomain $domain, string $source): array
    {
        $setting = $site->relationLoaded('setting') ? $site->setting : $site->setting()->first();
        $setting = $setting instanceof SiteSetting && $setting->enabled ? $setting : null;

        return [
            'id' => (int) $site->id,
            'site_id' => (int) $site->id,
            'site_code' => (string) $site->code,
            'site_name' => (string) ($setting?->site_name ?: $site->name),
            'source' => $source,
            'domain' => $domain ? (string) $domain->domain : null,
            'site_domain_id' => $domain ? (int) $domain->id : null,
            'is_default' => (bool) $site->is_default,
            'logo_url' => (string) ($setting?->logo_url ?? ''),
            'landing_theme' => (string) ($setting?->landing_theme ?? ''),
            'accent_color' => (string) ($setting?->accent_color ?? ''),
            'support_name' => (string) ($setting?->support_name ?? ''),
            'support_url' => (string) ($setting?->support_url ?? ''),
            'announcement' => (string) ($setting?->announcement ?? ''),
            'seo_title' => (string) ($setting?->seo_title ?? ''),
            'seo_description' => (string) ($setting?->seo_description ?? ''),
            'enabled' => $setting ? (bool) $setting->enabled : true,
            'created_at' => $this->timestampValue($site->created_at),
            'updated_at' => $this->timestampValue($setting?->updated_at ?? $site->updated_at),
        ];
    }

    private function timestampValue($value): ?int
    {
        if (!$value) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return (int) $value;
    }
}
```

- [ ] **Step 4: Add guest/user site-context controllers**

Create `app/Http/Controllers/V1/Guest/SiteContextController.php` and `app/Http/Controllers/V1/User/SiteContextController.php`. Each returns:

```php
return $this->success([
    'site' => app(\App\Services\SiteContextService::class)->resolve($request, $request->user()),
]);
```

The guest controller passes only `$request`; the user controller passes `$request->user()`.

- [ ] **Step 5: Wire config endpoints**

In `Guest\CommController::config()`, after `$data` is built and recharge config is merged, call:

```php
$data = app(\App\Services\SiteContextService::class)->applyToConfig($data, $request);
```

Keep the existing line after that:

```php
$data = app(AgentPublicConfigService::class)->apply($data, $request);
```

In `User\CommController::config()`, change signature to `config(Request $request)` and before returning:

```php
$data = app(\App\Services\SiteContextService::class)->applyToConfig($data, $request, $request->user());
```

- [ ] **Step 6: Add routes**

In `GuestRoute`, import guest `SiteContextController` and add:

```php
$router->get('/site-context', [SiteContextController::class, 'show']);
```

In `UserRoute`, import user `SiteContextController` and add:

```php
$router->get('/site-context', [SiteContextController::class, 'show']);
```

- [ ] **Step 7: Run tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteContextServiceTest.php tests/Unit/Http/GuestCommControllerAgentConfigTest.php
```

Expected: all tests pass and existing agent public config behavior is preserved.

- [ ] **Step 8: Commit**

Run:

```powershell
git add app/Services/SiteContextService.php app/Http/Controllers/V1 app/Http/Routes/V1 tests
git commit -m "feat: expose site context config"
```

---

### Task 4: Site-Aware Storefront Prices

**Files:**
- Create `app/Services/SiteStorefrontService.php`
- Modify `app/Http/Controllers/V1/Guest/PlanController.php`
- Modify `app/Http/Controllers/V1/User/PlanController.php`
- Test `tests/Unit/Services/SiteStorefrontServiceTest.php`
- Test `tests/Unit/Http/SitePlanControllerTest.php`

- [ ] **Step 1: Add failing price service tests**

Create `tests/Unit/Services/SiteStorefrontServiceTest.php` with tests equivalent to agent storefront tests:

```php
public function test_non_default_site_uses_enabled_site_price_and_hides_unpriced_periods(): void
{
    [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    $plan = $this->createPlan('Starter', [
        Plan::PERIOD_MONTHLY => 20.00,
        Plan::PERIOD_YEARLY => 120.00,
    ]);
    SitePlanPrice::query()->create([
        'site_id' => $site->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'sale_price' => 1300,
        'enabled' => true,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $plans = app(SiteStorefrontService::class)->plansForRequest(
        $this->requestForHost('cheap.example.test'),
        collect([$plan])
    );

    $this->assertCount(1, $plans);
    $this->assertEquals(13.0, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
    $this->assertArrayNotHasKey(Plan::PERIOD_YEARLY, $plans[0]->prices);
    $this->assertSame(1300, $plans[0]->site_sale_periods[Plan::PERIOD_MONTHLY]);
    $this->assertSame($site->id, $plans[0]->site_context['site_id']);
}
```

Add a second test:

```php
public function test_default_site_falls_back_to_platform_prices(): void
{
    $this->siteWithDomain('default', 'Default Site', 'main.example.test', true);
    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

    $plans = app(SiteStorefrontService::class)->plansForRequest(
        $this->requestForHost('main.example.test'),
        collect([$plan])
    );

    $this->assertCount(1, $plans);
    $this->assertEquals(20.00, $plans[0]->prices[Plan::PERIOD_MONTHLY]);
    $this->assertSame(2000, $plans[0]->site_sale_periods[Plan::PERIOD_MONTHLY]);
}
```

- [ ] **Step 2: Run failing tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteStorefrontServiceTest.php
```

Expected: FAIL because `SiteStorefrontService` does not exist.

- [ ] **Step 3: Implement `SiteStorefrontService`**

Create `app/Services/SiteStorefrontService.php` with these public methods:

```php
public function plansForRequest(Request $request, iterable $platformPlans): array
public function resolveSalePrice(int $siteId, int $planId, string $period): array
public function availablePaymentMethodsForRequest(Request $request)
public function assertPaymentAvailableForOrder(Order $order, Payment $payment): void
```

Implementation rules:

- Call `SiteContextService::resolve($request, $request->user())`.
- For default site, use platform plan prices when no `SitePlanPrice` exists.
- For non-default site, only return enabled `SitePlanPrice` periods.
- Convert cents to yuan before assigning to `$plan->prices`, because `PlanResource` multiplies by 100.
- Also attach cents in `$plan->site_sale_periods`.
- Attach `$plan->site_context` with `site_id`, `site_code`, `domain`, and `source`.
- `resolveSalePrice()` returns `sale_amount` in cents and includes `platform_plan_price` in cents.

Use this conversion pattern:

```php
$salePricesForResource[(string) $price->period] = ((int) $price->sale_price) / 100;
$salePricesInCents[(string) $price->period] = (int) $price->sale_price;
```

- [ ] **Step 4: Wire plan controllers**

In guest and user plan controllers, apply site storefront before agent storefront:

```php
$plans = app(\App\Services\SiteStorefrontService::class)->plansForRequest($request, $plans);
$plans = app(AgentStorefrontService::class)->plansForRequest($request, $plans);
```

This keeps agent-domain prices higher priority when an agent storefront is active.

- [ ] **Step 5: Run tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteStorefrontServiceTest.php tests/Unit/Services/AgentStorefrontServiceTest.php
```

Expected: all tests pass. Existing agent price tests still show agent prices.

- [ ] **Step 6: Commit**

Run:

```powershell
git add app/Services/SiteStorefrontService.php app/Http/Controllers/V1/Guest/PlanController.php app/Http/Controllers/V1/User/PlanController.php tests/Unit/Services/SiteStorefrontServiceTest.php
git commit -m "feat: apply site storefront prices"
```

---

### Task 5: Site-Aware Auth

**Files:**
- Modify `app/Services/Auth/RegisterService.php`
- Modify `app/Services/Auth/LoginService.php`
- Modify `app/Http/Controllers/V1/Passport/AuthController.php`
- Test `tests/Unit/Http/SiteAuthContextTest.php`

- [ ] **Step 1: Add failing auth tests**

Create `tests/Unit/Http/SiteAuthContextTest.php` with:

```php
public function test_same_email_can_register_on_two_sites(): void
{
    [$cheap] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    [$premium] = $this->siteWithDomain('premium', 'Premium Site', 'premium.example.test');

    $cheapRequest = $this->registerRequest('cheap.example.test', 'same@example.test');
    $premiumRequest = $this->registerRequest('premium.example.test', 'same@example.test');

    [$cheapOk, $cheapUser] = app(RegisterService::class)->register($cheapRequest);
    [$premiumOk, $premiumUser] = app(RegisterService::class)->register($premiumRequest);

    $this->assertTrue($cheapOk);
    $this->assertTrue($premiumOk);
    $this->assertNotSame($cheapUser->id, $premiumUser->id);
    $this->assertSame($cheap->id, (int) $cheapUser->site_id);
    $this->assertSame($premium->id, (int) $premiumUser->site_id);
}

public function test_login_uses_current_site_email_scope(): void
{
    [$cheap] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    [$premium] = $this->siteWithDomain('premium', 'Premium Site', 'premium.example.test');
    $cheapUser = $this->createSiteUser($cheap->id, 'same@example.test', 'cheap-secret');
    $this->createSiteUser($premium->id, 'same@example.test', 'premium-secret');

    $request = \Illuminate\Http\Request::create('/api/v1/passport/auth/login', 'POST', [], [], [], ['HTTP_HOST' => 'cheap.example.test']);
    app()->instance('request', $request);

    [$ok, $user] = app(LoginService::class)->login('same@example.test', 'cheap-secret', $request);

    $this->assertTrue($ok);
    $this->assertSame($cheapUser->id, $user->id);
}
```

- [ ] **Step 2: Run failing auth tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Http/SiteAuthContextTest.php
```

Expected: FAIL because register/login are still global email scoped.

- [ ] **Step 3: Update registration validation**

In `RegisterService::validateRegister(Request $request)`, replace global email existence:

```php
$siteContext = app(\App\Services\SiteContextService::class)->resolve($request);
$exist = User::query()
    ->where('email', $email)
    ->where(function ($query) use ($siteContext): void {
        $siteId = (int) $siteContext['site_id'];
        $query->where('site_id', $siteId);
        if (!empty($siteContext['is_default'])) {
            $query->orWhereNull('site_id');
        }
    })
    ->first();
```

In `register()`, before `createUser()`, resolve `$siteContext` and pass:

```php
'site_id' => (int) $siteContext['site_id'],
```

- [ ] **Step 4: Update login**

Change `LoginService::login()` signature:

```php
public function login(string $email, string $password, ?\Illuminate\Http\Request $request = null): array
```

Resolve site:

```php
$request = $request ?: request();
$siteContext = app(\App\Services\SiteContextService::class)->resolve($request);
$siteId = (int) $siteContext['site_id'];
$user = User::query()
    ->where('email', $email)
    ->where(function ($query) use ($siteId, $siteContext): void {
        $query->where('site_id', $siteId);
        if (!empty($siteContext['is_default'])) {
            $query->orWhereNull('site_id');
        }
    })
    ->first();
```

Update password reset similarly, but keep the default-site null fallback.

- [ ] **Step 5: Update controller calls**

In `AuthController::login()`:

```php
[$success, $result] = $this->loginService->login($email, $password, $request);
```

In `AuthController::forget()`:

```php
[$success, $result] = $this->loginService->resetPassword(
    $request->input('email'),
    $request->input('email_code'),
    $request->input('password'),
    $request
);
```

- [ ] **Step 6: Run tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Http/SiteAuthContextTest.php tests/Unit/Http/AuthForgetRequestTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

Run:

```powershell
git add app/Services/Auth app/Http/Controllers/V1/Passport/AuthController.php tests/Unit/Http/SiteAuthContextTest.php
git commit -m "feat: scope storefront auth by site"
```

---

### Task 6: Site Order Context and Payment Filtering

**Files:**
- Create `app/Services/SiteCommerceService.php`
- Modify `app/Http/Controllers/V1/User/OrderController.php`
- Test `tests/Unit/Services/SiteCommerceServiceTest.php`
- Test `tests/Unit/Http/SiteOrderFlowTest.php`

- [ ] **Step 1: Add failing order tests**

Create `tests/Unit/Services/SiteCommerceServiceTest.php` with:

```php
public function test_site_order_uses_site_price_and_creates_context(): void
{
    [$site, $domain] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    $buyer = $this->createSiteUser($site->id, 'buyer@example.test');
    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);
    SitePlanPrice::query()->create([
        'site_id' => $site->id,
        'plan_id' => $plan->id,
        'period' => Plan::PERIOD_MONTHLY,
        'sale_price' => 1300,
        'enabled' => true,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $order = app(SiteCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('cheap.example.test', $buyer)
    );

    $this->assertSame($site->id, (int) $order->site_id);
    $this->assertSame(1300, (int) $order->total_amount);
    $context = SiteOrderContext::query()->where('order_id', $order->id)->first();
    $this->assertSame($site->id, (int) $context->site_id);
    $this->assertSame($domain->id, (int) $context->site_domain_id);
    $this->assertSame(1300, (int) $context->sale_amount);
    $this->assertSame(2000, (int) $context->platform_plan_price);
}
```

Add another test for missing non-default site price:

```php
public function test_non_default_site_order_rejects_missing_site_price(): void
{
    [$site] = $this->siteWithDomain('cheap', 'Cheap Site', 'cheap.example.test');
    $buyer = $this->createSiteUser($site->id, 'buyer@example.test');
    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 20.00]);

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Site price is not available');

    app(SiteCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('cheap.example.test', $buyer)
    );
}
```

- [ ] **Step 2: Run failing order tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteCommerceServiceTest.php
```

Expected: FAIL because `SiteCommerceService` does not exist.

- [ ] **Step 3: Implement `SiteCommerceService`**

Create `app/Services/SiteCommerceService.php` with methods:

```php
public function createOrderFromRequest(User $user, Plan $plan, string $period, ?string $couponCode, Request $request): Order
public function contextForOrder(Order $order): ?SiteOrderContext
public function availablePaymentMethodsForRequest(Request $request)
public function assignPaymentForCheckout(Order $order, Payment $payment, ?int $handlingAmount): Order
public function assertPaymentAvailableForOrder(Order $order, Payment $payment): void
```

Implementation rules:

- Use `SiteContextService::resolve($request, $user)`.
- Use `SiteStorefrontService::resolveSalePrice()`.
- Save `site_id` on `Order`.
- Save `SiteOrderContext` with `pricing_snapshot` and `domain_snapshot`.
- Apply coupons, VIP discount, order type, invite commission, and balance using the same order service calls as platform order creation.
- Do not create site context for agent orders. `OrderController` calls agent commerce first.

- [ ] **Step 4: Wire `OrderController::save()`**

Keep existing agent branch first:

```php
$agentOrder = app(AgentCommerceService::class)->createOrderFromRequest(...);
if ($agentOrder) {
    return $this->success($agentOrder->trade_no);
}
```

Replace direct `OrderService::createFromRequest()` with:

```php
$order = app(\App\Services\SiteCommerceService::class)->createOrderFromRequest(
    $user,
    $plan,
    $request->input('period'),
    $request->input('coupon_code'),
    $request
);
```

- [ ] **Step 5: Wire payment methods and checkout**

In `getPaymentMethod()`, keep agent context priority:

```php
$agentMethods = app(AgentCommerceService::class)->availablePaymentMethodsForRequest($request);
if (app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user())) {
    return $this->success($agentMethods);
}
return $this->success(app(\App\Services\SiteCommerceService::class)->availablePaymentMethodsForRequest($request));
```

In `checkout()`, after loading `$payment`, call agent assignment first as current code does, then call site assignment for non-agent orders:

```php
$order = $agentCommerce->assignPaymentForCheckout($order, $payment, $handlingAmount);
$order = app(\App\Services\SiteCommerceService::class)->assignPaymentForCheckout($order, $payment, $handlingAmount);
```

`SiteCommerceService::assignPaymentForCheckout()` must no-op for agent orders that already have `AgentOrderContext`.

- [ ] **Step 6: Run order tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteCommerceServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php
```

Expected: all tests pass.

- [ ] **Step 7: Commit**

Run:

```powershell
git add app/Services/SiteCommerceService.php app/Http/Controllers/V1/User/OrderController.php tests/Unit/Services/SiteCommerceServiceTest.php tests/Unit/Http/SiteOrderFlowTest.php
git commit -m "feat: create site order contexts"
```

---

### Task 7: Admin APIs for Site Settings, Prices, and Payments

**Files:**
- Modify `app/Http/Controllers/V2/Admin/SiteController.php`
- Modify `app/Http/Routes/V2/AdminRoute.php`
- Test `tests/Unit/Http/AdminSiteCommerceControllerTest.php`

- [ ] **Step 1: Add failing admin tests**

Create tests for:

```php
public function test_admin_can_save_site_setting(): void
public function test_admin_can_save_site_prices_in_cents(): void
public function test_admin_can_save_site_payment_availability(): void
```

Price payload uses cents:

```php
[
    'plan_id' => $plan->id,
    'period' => Plan::PERIOD_MONTHLY,
    'sale_price' => 1300,
    'enabled' => true,
]
```

Expected saved row: `sale_price = 1300`.

- [ ] **Step 2: Run failing admin tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Http/AdminSiteCommerceControllerTest.php
```

Expected: FAIL because endpoints are missing.

- [ ] **Step 3: Add controller methods**

Add to `SiteController`:

```php
public function setting(int $siteId)
public function saveSetting(Request $request, int $siteId)
public function prices(int $siteId)
public function savePrices(Request $request, int $siteId)
public function payments(int $siteId)
public function savePayments(Request $request, int $siteId)
```

Validation:

- `site_name`: nullable string max 120
- `logo_url`: nullable string max 500
- `landing_theme`: nullable string max 64
- `accent_color`: nullable string max 16
- `support_name`: nullable string max 120
- `support_url`: nullable string max 500
- `announcement`: nullable string max 1000
- `seo_title`: nullable string max 160
- `seo_description`: nullable string max 255
- `enabled`: nullable boolean
- prices array: `plan_id`, `period`, `sale_price`, `enabled`
- payments array: `payment_id`, `enabled`, `sort`

Use `PlanService::getPeriodKey()` before storing periods.

- [ ] **Step 4: Add routes**

In the admin `site` route group:

```php
$router->get('/{siteId}/setting', [SiteController::class, 'setting']);
$router->post('/{siteId}/setting', [SiteController::class, 'saveSetting']);
$router->get('/{siteId}/prices', [SiteController::class, 'prices']);
$router->post('/{siteId}/prices', [SiteController::class, 'savePrices']);
$router->get('/{siteId}/payments', [SiteController::class, 'payments']);
$router->post('/{siteId}/payments', [SiteController::class, 'savePayments']);
```

- [ ] **Step 5: Run admin tests**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Http/AdminSiteControllerTest.php tests/Unit/Http/AdminSiteCommerceControllerTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit**

Run:

```powershell
git add app/Http/Controllers/V2/Admin/SiteController.php app/Http/Routes/V2/AdminRoute.php tests/Unit/Http/AdminSiteCommerceControllerTest.php
git commit -m "feat: expose site commerce admin APIs"
```

---

### Task 8: Final Verification for Phase 2A

**Files:**
- No code changes unless verification exposes a bug.

- [ ] **Step 1: Run focused backend suite**

Run:

```powershell
php vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php tests/Unit/Services/SiteContextServiceTest.php tests/Unit/Services/SiteStorefrontServiceTest.php tests/Unit/Services/SiteCommerceServiceTest.php tests/Unit/Services/AgentStorefrontServiceTest.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Services/AgentDomainResolverTest.php tests/Unit/Services/AgentCommerceContextResolverTest.php tests/Unit/Http/AdminSiteControllerTest.php tests/Unit/Http/AdminSiteCommerceControllerTest.php tests/Unit/Http/SiteAuthContextTest.php tests/Unit/Http/SitePlanControllerTest.php tests/Unit/Http/SiteOrderFlowTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php tests/Unit/Http/OrderSaveRequestTest.php
```

Expected: all tests pass.

- [ ] **Step 2: Check git state**

Run:

```powershell
git status --short --branch
```

Expected: clean tracked files. Unrelated untracked files may remain and must not be committed.

- [ ] **Step 3: Push branch**

Run:

```powershell
git push -u origin feature/platform-multisite-phase1
```

Expected: branch updates on GitHub.

---

## Phase 2B Follow-Up

After Phase 2A passes:

- `keli-admin`: add Sites page tabs for domains, settings, prices, and payments.
- `keli-user`: add first-party site provider, apply site context to landing/login/register/store, and keep agent-site overrides higher priority.
- Theme package: rebuild after user-side integration.
