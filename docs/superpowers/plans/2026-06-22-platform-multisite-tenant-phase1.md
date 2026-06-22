# Platform Multi-Site Tenant Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the backend foundation for first-party multi-site tenants: tables, models, Host resolver, default-site fallback, and admin APIs.

**Architecture:** Add a focused site domain model beside the existing agent-domain system, without reusing agent balances or agent commerce flows. `SiteResolver` becomes the single backend service for resolving an effective site from `Host` or legacy default state. Admin APIs expose CRUD-style site/domain management so later phases can attach registration, pricing, and UI branding to the same model.

**Tech Stack:** Laravel/PHP 8.2, Eloquent models, timestamp integer migrations, PHPUnit unit tests with the existing in-memory database helper.

---

## File Structure

- Create `database/migrations/2026_06_22_000001_create_site_tenant_tables.php`
  - Creates `v2_site` and `v2_site_domain`.
  - Adds nullable `site_id` columns to `v2_user` and `v2_order` when the tables exist.
- Create `app/Models/Site.php`
  - First-party site registry model with status constants and relationships.
- Create `app/Models/SiteDomain.php`
  - Domain model with active/pending/disabled constants.
- Create `app/Services/SiteResolver.php`
  - Normalizes hosts, resolves active domains, finds/creates default site, and returns context arrays.
- Create `app/Http/Controllers/V2/Admin/SiteController.php`
  - Admin APIs for listing/saving sites and domains, toggling status, and payload formatting.
- Modify `app/Http/Routes/V2/AdminRoute.php`
  - Adds `site` admin route group.
- Modify `tests/Support/InteractsWithInMemoryDatabase.php`
  - Adds `createSiteTenantTables()` and extends user/order helper tables with nullable `site_id` when needed for tests.
- Create `tests/Unit/Services/SiteResolverTest.php`
  - Covers host normalization, active-domain resolution, disabled-domain fallback, and default-site fallback.
- Create `tests/Unit/Http/AdminSiteControllerTest.php`
  - Covers admin site/domain list and save behavior.

---

### Task 1: Site Tenant Tables and Models

**Files:**
- Create: `database/migrations/2026_06_22_000001_create_site_tenant_tables.php`
- Create: `app/Models/Site.php`
- Create: `app/Models/SiteDomain.php`
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php`

- [ ] **Step 1: Write the failing model test scaffolding dependency**

Create `tests/Unit/Services/SiteResolverTest.php` with only the setup and one test that references the missing tables/models:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
    }

    public function test_resolves_active_site_domain_ignoring_port_and_case(): void
    {
        $site = Site::query()->create([
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => false,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'cheap.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(SiteResolver::class)->resolveHost('CHEAP.EXAMPLE.TEST:443');

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('cheap', $context['site_code']);
        $this->assertSame('cheap.example.test', $context['domain']);
        $this->assertSame('domain', $context['source']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php
```

Expected: FAIL because `createSiteTenantTables()`, `App\Models\Site`, `App\Models\SiteDomain`, or `App\Services\SiteResolver` does not exist.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_06_22_000001_create_site_tenant_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_site')) {
            Schema::create('v2_site', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('code', 64)->unique();
                $table->string('name', 120);
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_default')->default(false)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
            });
        }

        if (!Schema::hasTable('v2_site_domain')) {
            Schema::create('v2_site_domain', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('site_id')->index();
                $table->string('domain', 255)->unique();
                $table->string('status', 20)->default('active')->index();
                $table->boolean('is_primary')->default(false);
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->index(['site_id', 'status']);
            });
        }

        if (Schema::hasTable('v2_user') && !Schema::hasColumn('v2_user', 'site_id')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->index()->after('id');
            });
        }

        if (Schema::hasTable('v2_order') && !Schema::hasColumn('v2_order', 'site_id')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->unsignedInteger('site_id')->nullable()->index()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_order') && Schema::hasColumn('v2_order', 'site_id')) {
            Schema::table('v2_order', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }

        if (Schema::hasTable('v2_user') && Schema::hasColumn('v2_user', 'site_id')) {
            Schema::table('v2_user', function (Blueprint $table): void {
                $table->dropColumn('site_id');
            });
        }

        Schema::dropIfExists('v2_site_domain');
        Schema::dropIfExists('v2_site');
    }
};
```

- [ ] **Step 4: Add models**

Create `app/Models/Site.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $table = 'v2_site';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'is_default' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function domains(): HasMany
    {
        return $this->hasMany(SiteDomain::class, 'site_id', 'id');
    }
}
```

Create `app/Models/SiteDomain.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDomain extends Model
{
    protected $table = 'v2_site_domain';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $casts = [
        'is_primary' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id', 'id');
    }
}
```

- [ ] **Step 5: Add in-memory test schema helper**

Modify `tests/Support/InteractsWithInMemoryDatabase.php`:

Add `site_id` to `createUserTable()` immediately after `id`:

```php
$table->integer('site_id')->nullable();
```

Add `site_id` to `createOrderTable()` immediately after `id`:

```php
$table->integer('site_id')->nullable();
```

Add a new helper method:

```php
protected function createSiteTenantTables(): void
{
    $this->database->schema()->create('v2_site', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('code', 64)->unique();
        $table->string('name', 120);
        $table->string('status', 20)->default('active');
        $table->boolean('is_default')->default(false);
        $table->integer('created_at')->nullable();
        $table->integer('updated_at')->nullable();
    });

    $this->database->schema()->create('v2_site_domain', function (Blueprint $table): void {
        $table->increments('id');
        $table->unsignedInteger('site_id')->index();
        $table->string('domain', 255)->unique();
        $table->string('status', 20)->default('active');
        $table->boolean('is_primary')->default(false);
        $table->integer('created_at')->nullable();
        $table->integer('updated_at')->nullable();
    });
}
```

- [ ] **Step 6: Commit tables and models after tests can reach the missing resolver**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php
```

Expected: FAIL because `App\Services\SiteResolver` does not exist.

Commit:

```bash
git add database/migrations/2026_06_22_000001_create_site_tenant_tables.php app/Models/Site.php app/Models/SiteDomain.php tests/Support/InteractsWithInMemoryDatabase.php tests/Unit/Services/SiteResolverTest.php
git commit -m "feat: add site tenant schema"
```

---

### Task 2: Site Resolver Service

**Files:**
- Create: `app/Services/SiteResolver.php`
- Modify: `tests/Unit/Services/SiteResolverTest.php`

- [ ] **Step 1: Expand failing resolver tests**

Replace `tests/Unit/Services/SiteResolverTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class SiteResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
    }

    public function test_resolves_active_site_domain_ignoring_port_and_case(): void
    {
        $site = $this->createSite('cheap', 'Cheap Site');
        $this->createDomain($site, 'cheap.example.test');

        $context = app(SiteResolver::class)->resolveHost('CHEAP.EXAMPLE.TEST:443');

        $this->assertSame($site->id, $context['site_id']);
        $this->assertSame('cheap', $context['site_code']);
        $this->assertSame('cheap.example.test', $context['domain']);
        $this->assertSame('domain', $context['source']);
    }

    public function test_disabled_domain_falls_back_to_default_site(): void
    {
        $default = $this->createSite('default', 'Default Site', true);
        $site = $this->createSite('disabled', 'Disabled Site');
        $this->createDomain($site, 'disabled.example.test', SiteDomain::STATUS_DISABLED);

        $context = app(SiteResolver::class)->resolveHost('disabled.example.test');

        $this->assertSame($default->id, $context['site_id']);
        $this->assertSame('default', $context['site_code']);
        $this->assertSame('', $context['domain']);
        $this->assertSame('default', $context['source']);
    }

    public function test_default_site_is_created_when_missing(): void
    {
        $context = app(SiteResolver::class)->resolveHost('unknown.example.test');

        $this->assertSame('default', $context['site_code']);
        $this->assertSame('Default Site', $context['site_name']);
        $this->assertSame('default', $context['source']);
        $this->assertTrue(Site::query()->where('code', 'default')->where('is_default', true)->exists());
    }

    public function test_normalize_host_strips_scheme_path_ipv6_brackets_port_and_trailing_dot(): void
    {
        $resolver = app(SiteResolver::class);

        $this->assertSame('site.example.com', $resolver->normalizeHost('https://Site.Example.COM:8443/path?x=1'));
        $this->assertSame('site.example.com', $resolver->normalizeHost('site.example.com.'));
        $this->assertSame('::1', $resolver->normalizeHost('[::1]:5174'));
    }

    private function createSite(string $code, string $name, bool $default = false): Site
    {
        return Site::query()->create([
            'code' => $code,
            'name' => $name,
            'status' => Site::STATUS_ACTIVE,
            'is_default' => $default,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createDomain(Site $site, string $domain, string $status = SiteDomain::STATUS_ACTIVE): SiteDomain
    {
        return SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => $domain,
            'status' => $status,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php
```

Expected: FAIL because `App\Services\SiteResolver` does not exist.

- [ ] **Step 3: Implement resolver**

Create `app/Services/SiteResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteResolver
{
    public function resolveRequest(Request $request): array
    {
        $host = (string) ($request->headers->get('x-forwarded-host') ?: $request->headers->get('host', ''));

        return $this->resolveHost($host);
    }

    public function resolveHost(string $host): array
    {
        $domain = $this->normalizeHost($host);
        if ($domain !== '') {
            $row = SiteDomain::query()
                ->with('site')
                ->where('domain', $domain)
                ->where('status', SiteDomain::STATUS_ACTIVE)
                ->first();

            if ($row && $row->site && (string) $row->site->status === Site::STATUS_ACTIVE) {
                return $this->context($row->site, $row, 'domain');
            }
        }

        return $this->context($this->defaultSite(), null, 'default');
    }

    public function defaultSite(): Site
    {
        $site = Site::query()
            ->where('is_default', true)
            ->where('status', Site::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();

        if ($site) {
            return $site;
        }

        return DB::transaction(function (): Site {
            $existing = Site::query()
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ((string) $existing->status !== Site::STATUS_ACTIVE) {
                    $existing->status = Site::STATUS_ACTIVE;
                    $existing->updated_at = time();
                    $existing->save();
                }

                return $existing;
            }

            return Site::query()->create([
                'code' => 'default',
                'name' => 'Default Site',
                'status' => Site::STATUS_ACTIVE,
                'is_default' => true,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        });
    }

    public function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : $host;
        }

        $host = preg_split('/[\/?#]/', $host, 2)[0] ?? '';
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } else {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($host, 0, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    private function context(Site $site, ?SiteDomain $domain, string $source): array
    {
        return [
            'site_id' => (int) $site->id,
            'site_code' => (string) $site->code,
            'site_name' => (string) $site->name,
            'site_domain_id' => $domain ? (int) $domain->id : null,
            'domain' => $domain ? (string) $domain->domain : '',
            'is_default' => (bool) $site->is_default,
            'source' => $source,
        ];
    }
}
```

- [ ] **Step 4: Run resolver tests**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit resolver**

```bash
git add app/Services/SiteResolver.php tests/Unit/Services/SiteResolverTest.php
git commit -m "feat: resolve first-party site context"
```

---

### Task 3: Admin Site Controller and Routes

**Files:**
- Create: `app/Http/Controllers/V2/Admin/SiteController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Create: `tests/Unit/Http/AdminSiteControllerTest.php`

- [ ] **Step 1: Write failing admin controller tests**

Create `tests/Unit/Http/AdminSiteControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\ApiException;
use App\Http\Controllers\V2\Admin\SiteController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\Site;
use App\Models\SiteDomain;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AdminSiteControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->bindRequestValidateMacro();
        $this->createUserTable();
        $this->createOrderTable();
        $this->createSiteTenantTables();
        $this->bindTestSettings([
            'secure_path' => 'admin',
        ]);
    }

    public function test_admin_can_create_site_and_primary_domain(): void
    {
        $request = Request::create('/admin/site/save', 'POST', [
            'code' => 'cheap',
            'name' => 'Cheap Site',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'domains' => [
                [
                    'domain' => 'Cheap.Example.Test:443',
                    'status' => SiteDomain::STATUS_ACTIVE,
                    'is_primary' => true,
                ],
            ],
        ]);

        $payload = $this->responsePayload(app(SiteController::class)->save($request));

        $this->assertSame('success', $payload['status']);
        $this->assertSame('cheap', $payload['data']['code']);
        $this->assertTrue($payload['data']['is_default']);
        $this->assertSame('cheap.example.test', $payload['data']['domains'][0]['domain']);
        $this->assertTrue(Site::query()->where('code', 'cheap')->where('is_default', true)->exists());
    }

    public function test_duplicate_site_domain_is_rejected(): void
    {
        $site = Site::query()->create([
            'code' => 'one',
            'name' => 'One',
            'status' => Site::STATUS_ACTIVE,
            'is_default' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        SiteDomain::query()->create([
            'site_id' => $site->id,
            'domain' => 'same.example.test',
            'status' => SiteDomain::STATUS_ACTIVE,
            'is_primary' => true,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Domain already assigned');

        app(SiteController::class)->save(Request::create('/admin/site/save', 'POST', [
            'code' => 'two',
            'name' => 'Two',
            'domains' => [
                ['domain' => 'same.example.test'],
            ],
        ]));
    }

    public function test_admin_route_registers_site_endpoints(): void
    {
        $registrar = new AdminSiteRouteRegistrar();

        (new AdminRoute())->map($registrar);

        $this->assertContains([
            'method' => 'GET',
            'uri' => '/admin/site/fetch',
            'action' => [SiteController::class, 'fetch'],
        ], $registrar->routes);
        $this->assertContains([
            'method' => 'POST',
            'uri' => '/admin/site/save',
            'action' => [SiteController::class, 'save'],
        ], $registrar->routes);
    }

    private function responsePayload($response): array
    {
        return $response->getData(true);
    }
}

final class AdminSiteRouteRegistrar implements Registrar
{
    public array $routes = [];
    private array $prefixes = [];

    public function get($uri, $action) { return $this->record('GET', $uri, $action); }
    public function post($uri, $action) { return $this->record('POST', $uri, $action); }
    public function put($uri, $action) { return $this->record('PUT', $uri, $action); }
    public function delete($uri, $action) { return $this->record('DELETE', $uri, $action); }
    public function patch($uri, $action) { return $this->record('PATCH', $uri, $action); }
    public function options($uri, $action) { return $this->record('OPTIONS', $uri, $action); }
    public function match($methods, $uri, $action) { foreach ((array) $methods as $method) $this->record(strtoupper((string) $method), $uri, $action); }
    public function resource($name, $controller, array $options = []) { return null; }
    public function substituteBindings($route) { return $route; }
    public function substituteImplicitBindings($route) { return null; }

    public function group(array $attributes, $routes)
    {
        $this->prefixes[] = (string) ($attributes['prefix'] ?? '');
        $routes($this);
        array_pop($this->prefixes);
    }

    private function record(string $method, string $uri, $action): void
    {
        $this->routes[] = [
            'method' => $method,
            'uri' => '/' . trim(implode('/', array_filter($this->prefixes)) . '/' . ltrim($uri, '/'), '/'),
            'action' => $action,
        ];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Http/AdminSiteControllerTest.php
```

Expected: FAIL because `SiteController` and routes do not exist.

- [ ] **Step 3: Implement controller**

Create `app/Http/Controllers/V2/Admin/SiteController.php`:

```php
<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\SiteResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SiteController extends Controller
{
    public function fetch()
    {
        app(SiteResolver::class)->defaultSite();

        $sites = Site::query()
            ->with('domains')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get()
            ->map(fn (Site $site): array => $this->sitePayload($site));

        return $this->success($sites);
    }

    public function save(Request $request)
    {
        $params = $request->validate([
            'id' => 'nullable|integer',
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:120',
            'status' => 'nullable|in:active,disabled',
            'is_default' => 'boolean',
            'domains' => 'nullable|array',
            'domains.*.id' => 'nullable|integer',
            'domains.*.domain' => 'required_with:domains|string|max:255',
            'domains.*.status' => 'nullable|in:active,pending,disabled',
            'domains.*.is_primary' => 'boolean',
        ]);

        $id = (int) ($params['id'] ?? 0);
        $code = $this->normalizeCode((string) $params['code']);
        if ($code === '') {
            throw new ApiException('Invalid site code');
        }

        $codeExists = Site::query()
            ->where('code', $code)
            ->when($id > 0, fn ($query) => $query->where('id', '<>', $id))
            ->exists();
        if ($codeExists) {
            throw new ApiException('Site code already exists');
        }

        $resolver = app(SiteResolver::class);
        $domainPayloads = [];
        foreach ((array) ($params['domains'] ?? []) as $domainParams) {
            $normalizedDomain = $resolver->normalizeHost((string) ($domainParams['domain'] ?? ''));
            if ($normalizedDomain === '') {
                throw new ApiException('Invalid domain');
            }
            $domainPayloads[] = [
                'id' => (int) ($domainParams['id'] ?? 0),
                'domain' => $normalizedDomain,
                'status' => (string) ($domainParams['status'] ?? SiteDomain::STATUS_ACTIVE),
                'is_primary' => (bool) ($domainParams['is_primary'] ?? false),
            ];
        }

        $site = DB::transaction(function () use ($params, $id, $code, $domainPayloads): Site {
            $site = $id > 0 ? Site::query()->find($id) : new Site();
            if (!$site) {
                throw new ApiException('Site does not exist');
            }

            $site->code = $code;
            $site->name = trim((string) $params['name']);
            $site->status = (string) ($params['status'] ?? Site::STATUS_ACTIVE);
            $site->is_default = (bool) ($params['is_default'] ?? false);
            if (!$site->exists) {
                $site->created_at = time();
            }
            $site->updated_at = time();
            $site->save();

            if ($site->is_default) {
                Site::query()
                    ->where('id', '<>', $site->id)
                    ->update(['is_default' => false, 'updated_at' => time()]);
            }

            $seenDomains = [];
            foreach ($domainPayloads as $domainParams) {
                if (in_array($domainParams['domain'], $seenDomains, true)) {
                    throw new ApiException('Domain already assigned');
                }
                $seenDomains[] = $domainParams['domain'];

                $domainExists = SiteDomain::query()
                    ->where('domain', $domainParams['domain'])
                    ->when($domainParams['id'] > 0, fn ($query) => $query->where('id', '<>', $domainParams['id']))
                    ->exists();
                if ($domainExists) {
                    throw new ApiException('Domain already assigned');
                }

                if ($domainParams['is_primary']) {
                    SiteDomain::query()
                        ->where('site_id', $site->id)
                        ->when($domainParams['id'] > 0, fn ($query) => $query->where('id', '<>', $domainParams['id']))
                        ->update(['is_primary' => false, 'updated_at' => time()]);
                }

                $domain = $domainParams['id'] > 0
                    ? SiteDomain::query()->where('site_id', $site->id)->where('id', $domainParams['id'])->first()
                    : new SiteDomain();
                if (!$domain) {
                    throw new ApiException('Domain does not exist');
                }

                $domain->site_id = $site->id;
                $domain->domain = $domainParams['domain'];
                $domain->status = $domainParams['status'];
                $domain->is_primary = $domainParams['is_primary'];
                if (!$domain->exists) {
                    $domain->created_at = time();
                }
                $domain->updated_at = time();
                $domain->save();
            }

            return $site;
        });

        return $this->success($this->sitePayload($site->fresh('domains') ?: $site));
    }

    private function sitePayload(Site $site): array
    {
        return [
            'id' => (int) $site->id,
            'code' => (string) $site->code,
            'name' => (string) $site->name,
            'status' => (string) $site->status,
            'is_default' => (bool) $site->is_default,
            'domains' => $site->domains
                ? $site->domains->sortByDesc('is_primary')->values()->map(fn (SiteDomain $domain): array => [
                    'id' => (int) $domain->id,
                    'site_id' => (int) $domain->site_id,
                    'domain' => (string) $domain->domain,
                    'status' => (string) $domain->status,
                    'is_primary' => (bool) $domain->is_primary,
                    'created_at' => $domain->created_at ? (int) $domain->created_at : null,
                    'updated_at' => $domain->updated_at ? (int) $domain->updated_at : null,
                ])->all()
                : [],
            'created_at' => $site->created_at ? (int) $site->created_at : null,
            'updated_at' => $site->updated_at ? (int) $site->updated_at : null,
        ];
    }

    private function normalizeCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_-]+/', '-', $code) ?? '';
        $code = trim($code, '-_');

        return $code;
    }
}
```

- [ ] **Step 4: Register admin routes**

Modify `app/Http/Routes/V2/AdminRoute.php`:

Add import:

```php
use App\Http\Controllers\V2\Admin\SiteController;
```

Inside the authenticated admin group, add:

```php
// Site
$router->group([
    'prefix' => 'site'
], function ($router) {
    $router->get('/fetch', [SiteController::class, 'fetch']);
    $router->post('/save', [SiteController::class, 'save']);
});
```

Place it near Config/Plan so admin consumers can discover it early.

- [ ] **Step 5: Run admin tests**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Http/AdminSiteControllerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit admin API**

```bash
git add app/Http/Controllers/V2/Admin/SiteController.php app/Http/Routes/V2/AdminRoute.php tests/Unit/Http/AdminSiteControllerTest.php
git commit -m "feat: expose admin site tenants"
```

---

### Task 4: Regression Verification

**Files:**
- No code files expected unless tests reveal a focused defect.

- [ ] **Step 1: Run Phase 1 targeted backend tests**

Run:

```bash
C:\Users\Administrator\.cache\codex-runtimes\php-8.2.31\php.exe vendor/bin/phpunit tests/Unit/Services/SiteResolverTest.php tests/Unit/Http/AdminSiteControllerTest.php tests/Unit/Services/AgentDomainResolverTest.php tests/Unit/Http/AdminAgentCommerceControllerTest.php
```

Expected: PASS. Agent-domain tests are included to prove the new Site domain resolver did not break reseller-domain behavior.

- [ ] **Step 2: Run syntax/whitespace check**

Run:

```bash
git diff --check
```

Expected: no output except possible line-ending warnings on Windows.

- [ ] **Step 3: Review final diff**

Run:

```bash
git diff --stat HEAD~3..HEAD
git status --short
```

Expected:

- only the planned Phase 1 files changed;
- worktree is clean after commits;
- no generated assets or unrelated files are staged.

- [ ] **Step 4: Push branch for review**

Run:

```bash
git push origin feature/platform-multisite-phase1
```

Expected: branch pushed successfully.

---

## Self-Review

Spec coverage:

- Backend site model and resolver: Tasks 1 and 2.
- Host-based domain resolution and default-site fallback: Task 2.
- Admin read/write APIs: Task 3.
- Login, pricing, order flow, frontend branding, and migration tooling: intentionally deferred to later phases from the spec.

Placeholder scan:

- Executable steps contain concrete paths, commands, and code.
- Deferred scope is explicitly listed as future phases rather than hidden in this plan.

Type consistency:

- Models use `Site`, `SiteDomain`, `SiteResolver`.
- Payload keys use `site_id`, `site_code`, `site_name`, `site_domain_id`, `domain`, `is_default`, `source`.
- Status constants are `active`, `pending`, and `disabled` where applicable.
