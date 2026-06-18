# Agent White-Label Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build proxy-agent white-label site settings and make agent-owned users consistently use agent branding, announcements, tickets, pricing, payments, and balance limits even on the main domain.

**Architecture:** Add a small backend source of truth for agent site settings and extend the existing agent context resolver instead of branching logic across controllers. Public config, user storefront, tickets, and admin views consume the same resolved agent context. `keli-user` only adds an agent settings tab and keeps existing guest config consumers intact.

**Tech Stack:** Laravel 12/PHPUnit in `keliboard`, React/Vite/Vitest in `keli-user` and `keli-admin`, existing XBoard API response envelopes.

---

## File Structure

### Backend: `keliboard`

- Create `database/migrations/2026_06_18_000003_create_agent_site_setting_table.php` for `v2_agent_site_setting` and ticket agent columns.
- Create `app/Models/AgentSiteSetting.php` for site setting persistence.
- Create `app/Services/AgentSiteSettingService.php` for validation, saving, resolving, and payload shaping.
- Create `app/Services/AgentPublicConfigService.php` for `/guest/comm/config` overlay.
- Modify `app/Services/AgentCommerceContextResolver.php` to keep the current user-first behavior and expose a stable payload helper.
- Modify `app/Http/Controllers/V1/Guest/CommController.php` to apply agent public config overlays.
- Modify `app/Http/Controllers/V1/User/AgentCommerceController.php` and `app/Http/Routes/V1/UserRoute.php` for site setting endpoints.
- Modify `app/Services/TicketService.php`, `app/Models/Ticket.php`, `app/Http/Resources/TicketResource.php`, and `app/Http/Controllers/V2/Admin/TicketController.php` for ticket attribution and admin filtering.
- Modify `app/Http/Controllers/V2/Admin/AgentCommerceController.php` for admin audit visibility of site settings.
- Modify `tests/Support/InteractsWithInMemoryDatabase.php` for the in-memory table helpers.
- Add backend tests:
  - `tests/Unit/Services/AgentSiteSettingServiceTest.php`
  - `tests/Unit/Http/GuestCommControllerAgentConfigTest.php`
  - `tests/Unit/Http/AgentTicketContextTest.php`
  - extend `tests/Unit/Http/UserAgentCommerceControllerTest.php`
  - extend `tests/Unit/Services/AgentCommerceContextResolverTest.php`

### User Frontend: `keli-user`

- Modify `src/services/agentCommerce.ts` to add site setting types and API methods.
- Modify `src/pages/AgentCenterPage.tsx` to add the “网站设置” tab.
- Modify `src/locales/zh/translation.json` and `src/locales/en/translation.json`.
- Add `src/lib/agentSiteSettings.ts` and `src/lib/agentSiteSettings.test.ts` for local validation, defaults, and payload building.

### Admin Frontend: `keli-admin`

- Modify `src/services/agentCommerce.ts` to include agent site setting fields.
- Modify `src/pages/agent/AgentCommercePage.tsx` to show site setting audit information.
- Modify `src/pages/user/TicketManage.tsx`, `src/services/ticket.ts`, and `src/pages/user/components/TicketToolbar.tsx` to display/filter agent source.
- Modify `src/locales/zh/translation.json` and `src/locales/en/translation.json`.
- Extend `src/pages/agent/agentCommerceDisplay.test.ts` and `src/pages/user/ticketUtils.test.ts`.

---

## Task 1: Backend Schema And Model

**Files:**
- Create: `keliboard/database/migrations/2026_06_18_000003_create_agent_site_setting_table.php`
- Create: `keliboard/app/Models/AgentSiteSetting.php`
- Modify: `keliboard/app/Models/AgentDomain.php`
- Modify: `keliboard/app/Models/Ticket.php`
- Modify: `keliboard/tests/Support/InteractsWithInMemoryDatabase.php`

- [ ] **Step 1: Write the failing model/schema test**

Create `tests/Unit/Services/AgentSiteSettingServiceTest.php` with this first test:

```php
public function test_agent_site_setting_model_casts_enabled_and_links_domain(): void
{
    $this->setUpInMemoryDatabase();
    $this->createUserTable();
    $this->createAgentCenterTables();
    $this->createAgentCommerceTables();
    $this->createAgentSiteSettingTable();

    $agent = User::query()->create([
        'email' => 'agent@example.test',
        'password' => password_hash('secret123', PASSWORD_BCRYPT),
        'uuid' => 'agent-uuid',
        'token' => 'agent-token',
        'balance' => 0,
        'commission_balance' => 0,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $domain = AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop.example.test',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $setting = AgentSiteSetting::query()->create([
        'agent_user_id' => $agent->id,
        'agent_domain_id' => $domain->id,
        'site_name' => '代理站',
        'enabled' => 1,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $this->assertTrue($setting->enabled);
    $this->assertSame('shop.example.test', $setting->domain->domain);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter AgentSiteSettingServiceTest`

Expected: FAIL because `AgentSiteSetting`, the table helper, and relations do not exist.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_06_18_000003_create_agent_site_setting_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_agent_site_setting')) {
            Schema::create('v2_agent_site_setting', function (Blueprint $table): void {
                $table->increments('id');
                $table->unsignedInteger('agent_user_id')->index();
                $table->unsignedInteger('agent_domain_id')->nullable()->index();
                $table->string('site_name', 80)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('landing_theme', 32)->nullable();
                $table->string('accent_color', 16)->nullable();
                $table->string('support_name', 80)->nullable();
                $table->string('support_url', 500)->nullable();
                $table->string('announcement', 500)->nullable();
                $table->string('seo_title', 120)->nullable();
                $table->string('seo_description', 255)->nullable();
                $table->boolean('enabled')->default(true)->index();
                $table->integer('created_at')->nullable();
                $table->integer('updated_at')->nullable();
                $table->unique(['agent_user_id', 'agent_domain_id'], 'uniq_agent_site_setting_domain');
            });
        }

        if (Schema::hasTable('v2_ticket')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                if (!Schema::hasColumn('v2_ticket', 'agent_user_id')) {
                    $table->unsignedInteger('agent_user_id')->nullable()->index()->after('user_id');
                }
                if (!Schema::hasColumn('v2_ticket', 'agent_domain_id')) {
                    $table->unsignedInteger('agent_domain_id')->nullable()->index()->after('agent_user_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_ticket')) {
            Schema::table('v2_ticket', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    Schema::hasColumn('v2_ticket', 'agent_domain_id') ? 'agent_domain_id' : null,
                    Schema::hasColumn('v2_ticket', 'agent_user_id') ? 'agent_user_id' : null,
                ]));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::dropIfExists('v2_agent_site_setting');
    }
};
```

- [ ] **Step 4: Add the model and relations**

Create `app/Models/AgentSiteSetting.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentSiteSetting extends Model
{
    protected $table = 'v2_agent_site_setting';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id', 'id');
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(AgentDomain::class, 'agent_domain_id', 'id');
    }
}
```

Add to `AgentDomain`:

```php
public function siteSetting(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(AgentSiteSetting::class, 'agent_domain_id', 'id');
}
```

Add to `Ticket`:

```php
public function agent(): BelongsTo
{
    return $this->belongsTo(User::class, 'agent_user_id', 'id');
}

public function agentDomain(): BelongsTo
{
    return $this->belongsTo(AgentDomain::class, 'agent_domain_id', 'id');
}
```

- [ ] **Step 5: Add the in-memory test table helper**

In `tests/Support/InteractsWithInMemoryDatabase.php`, add `createAgentSiteSettingTable()` mirroring the migration columns and add `agent_user_id` and `agent_domain_id` to `createTicketTables()`.

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter AgentSiteSettingServiceTest`

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add database/migrations/2026_06_18_000003_create_agent_site_setting_table.php app/Models/AgentSiteSetting.php app/Models/AgentDomain.php app/Models/Ticket.php tests/Support/InteractsWithInMemoryDatabase.php tests/Unit/Services/AgentSiteSettingServiceTest.php
git commit -m "feat: add agent site setting schema"
```

---

## Task 2: Backend Site Setting Service

**Files:**
- Create: `keliboard/app/Services/AgentSiteSettingService.php`
- Modify: `keliboard/tests/Unit/Services/AgentSiteSettingServiceTest.php`

- [ ] **Step 1: Add failing tests for validation and fallback**

Add tests asserting:

```php
public function test_save_rejects_unowned_domain(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $other = $this->createActiveAgent('other@example.test');
    $domain = $this->createDomain($other, 'other.example.test');

    $this->expectException(ApiException::class);
    $this->expectExceptionMessage('Agent domain is not available');

    app(AgentSiteSettingService::class)->save($agent, [
        'agent_domain_id' => $domain->id,
        'site_name' => 'Wrong Domain',
    ]);
}

public function test_resolve_prefers_domain_setting_then_default_setting(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $domain = $this->createDomain($agent, 'shop.example.test');
    app(AgentSiteSettingService::class)->save($agent, [
        'site_name' => 'Default Brand',
        'announcement' => 'Default notice',
        'enabled' => true,
    ]);
    app(AgentSiteSettingService::class)->save($agent, [
        'agent_domain_id' => $domain->id,
        'site_name' => 'Domain Brand',
        'logo_url' => 'https://cdn.example.test/logo.png',
        'enabled' => true,
    ]);

    $resolved = app(AgentSiteSettingService::class)->resolve([
        'agent_user_id' => $agent->id,
        'agent_domain_id' => $domain->id,
    ]);

    $this->assertSame('Domain Brand', $resolved['site_name']);
    $this->assertSame('https://cdn.example.test/logo.png', $resolved['logo_url']);
    $this->assertSame('Default notice', $resolved['announcement']);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AgentSiteSettingServiceTest`

Expected: FAIL because `AgentSiteSettingService` does not exist.

- [ ] **Step 3: Implement `AgentSiteSettingService`**

Create service with these public methods:

```php
public function list(User $agent): array;
public function save(User $agent, array $payload): array;
public function resolve(?array $context): array;
public function payload(AgentSiteSetting $setting): array;
```

Implementation rules:

```php
private const LANDING_THEMES = ['sakura', 'spark', 'blue_cat', 'detective', 'phantom'];

private function cleanString(mixed $value, int $max): string
{
    return mb_substr(trim(strip_tags((string) $value)), 0, $max);
}

private function cleanUrl(mixed $value): string
{
    $url = trim((string) $value);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new ApiException('URL format is invalid');
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new ApiException('URL scheme is not allowed');
    }
    return mb_substr($url, 0, 500);
}

private function cleanColor(mixed $value): string
{
    $color = trim((string) $value);
    if ($color === '') {
        return '';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        throw new ApiException('Accent color is invalid');
    }
    return strtolower($color);
}
```

`resolve()` must merge agent default setting and domain setting so empty domain fields inherit default fields.

- [ ] **Step 4: Run the service tests**

Run: `vendor/bin/phpunit --filter AgentSiteSettingServiceTest`

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```bash
git add app/Services/AgentSiteSettingService.php tests/Unit/Services/AgentSiteSettingServiceTest.php
git commit -m "feat: manage agent site settings"
```

---

## Task 3: User API For Agent Site Settings

**Files:**
- Modify: `keliboard/app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `keliboard/app/Http/Routes/V1/UserRoute.php`
- Modify: `keliboard/tests/Unit/Http/UserAgentCommerceControllerTest.php`

- [ ] **Step 1: Add failing controller tests**

Add tests that call:

```php
$payload = $this->responsePayload(app(AgentCommerceController::class)->siteSettings($request))['data'];
$this->assertSame([], $payload['settings']);

$saveRequest = $this->userRequest($agent, '/api/v1/user/agent/site-settings', 'POST', [
    'site_name' => '代理站',
    'logo_url' => 'https://cdn.example.test/logo.png',
    'landing_theme' => 'sakura',
    'accent_color' => '#ff4f87',
    'support_name' => '客服',
    'support_url' => 'https://t.me/support',
    'announcement' => '今天线路正常',
    'enabled' => true,
]);
$saved = $this->responsePayload(app(AgentCommerceController::class)->saveSiteSetting($saveRequest))['data'];
$this->assertSame('代理站', $saved['site_name']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter UserAgentCommerceControllerTest`

Expected: FAIL because methods/routes are missing.

- [ ] **Step 3: Add controller methods**

Add to `AgentCommerceController`:

```php
public function siteSettings(Request $request)
{
    return $this->success([
        'settings' => app(AgentSiteSettingService::class)->list($request->user()),
    ]);
}

public function saveSiteSetting(Request $request)
{
    $params = $request->validate([
        'agent_domain_id' => 'nullable|integer',
        'site_name' => 'nullable|string|max:80',
        'logo_url' => 'nullable|string|max:500',
        'landing_theme' => 'nullable|string|max:32',
        'accent_color' => 'nullable|string|max:16',
        'support_name' => 'nullable|string|max:80',
        'support_url' => 'nullable|string|max:500',
        'announcement' => 'nullable|string|max:500',
        'seo_title' => 'nullable|string|max:120',
        'seo_description' => 'nullable|string|max:255',
        'enabled' => 'boolean',
    ]);

    return $this->success(app(AgentSiteSettingService::class)->save($request->user(), $params));
}
```

Add the service import.

- [ ] **Step 4: Add routes**

Add to `app/Http/Routes/V1/UserRoute.php`:

```php
$router->get('/agent/site-settings', [AgentCommerceController::class, 'siteSettings']);
$router->post('/agent/site-settings', [AgentCommerceController::class, 'saveSiteSetting']);
```

- [ ] **Step 5: Include site settings in commerce summary**

Add this key inside `commerceSummary()`:

```php
'site_settings' => app(AgentSiteSettingService::class)->list($request->user()),
```

- [ ] **Step 6: Run the controller tests**

Run: `vendor/bin/phpunit --filter UserAgentCommerceControllerTest`

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add app/Http/Controllers/V1/User/AgentCommerceController.php app/Http/Routes/V1/UserRoute.php tests/Unit/Http/UserAgentCommerceControllerTest.php
git commit -m "feat: expose agent site settings api"
```

---

## Task 4: Public Config Overlay By Agent Context

**Files:**
- Create: `keliboard/app/Services/AgentPublicConfigService.php`
- Modify: `keliboard/app/Http/Controllers/V1/Guest/CommController.php`
- Modify: `keliboard/tests/Unit/Services/AgentCommerceContextResolverTest.php`
- Create: `keliboard/tests/Unit/Http/GuestCommControllerAgentConfigTest.php`

- [ ] **Step 1: Add failing guest config tests**

Create tests that bind settings:

```php
$this->bindTestSettings([
    'app_name' => 'Main Site',
    'logo' => 'https://cdn.example.test/main.png',
    'app_url' => 'https://main.example.test',
    'currency_symbol' => '¥',
]);
```

Then assert:

```php
$request = Request::create('https://shop.example.test/api/v1/guest/comm/config', 'GET');
$request->headers->set('host', 'shop.example.test');
$payload = $this->responsePayload(app(CommController::class)->config($request))['data'];

$this->assertSame('代理站', $payload['app_name']);
$this->assertSame('https://cdn.example.test/agent.png', $payload['logo']);
$this->assertSame('sakura', $payload['landing_theme']);
$this->assertSame($agent->id, $payload['agent_context']['agent_user_id']);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter GuestCommControllerAgentConfigTest`

Expected: FAIL because config does not accept a request and no overlay service exists.

- [ ] **Step 3: Implement `AgentPublicConfigService`**

Create service:

```php
public function apply(array $data, Request $request): array
{
    $context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
    if (!$context) {
        return $data;
    }

    $setting = app(AgentSiteSettingService::class)->resolve($context);
    if ($setting === []) {
        return array_merge($data, ['agent_context' => $this->contextPayload($context)]);
    }

    $themeConfig = is_array($data['theme_config'] ?? null) ? $data['theme_config'] : [];
    if (!empty($setting['landing_theme'])) {
        $themeConfig['landing_theme'] = $setting['landing_theme'];
    }
    if (!empty($setting['accent_color'])) {
        $themeConfig['agent_accent_color'] = $setting['accent_color'];
    }
    if (!empty($setting['support_name'])) {
        $themeConfig['customer_service_name'] = $setting['support_name'];
    }
    if (!empty($setting['support_url'])) {
        $themeConfig['customer_service_url'] = $setting['support_url'];
    }

    return array_merge($data, [
        'app_name' => $setting['site_name'] ?: ($data['app_name'] ?? ''),
        'logo' => $setting['logo_url'] ?: ($data['logo'] ?? ''),
        'landing_theme' => $setting['landing_theme'] ?: ($data['landing_theme'] ?? null),
        'agent_announcement' => $setting['announcement'] ?: '',
        'theme_config' => $themeConfig,
        'agent_context' => $this->contextPayload($context),
    ]);
}
```

- [ ] **Step 4: Modify `CommController`**

Change `config()` to accept `Request $request` and call:

```php
$data = app(AgentPublicConfigService::class)->apply($data, $request);
```

before `HookManager::filter('guest_comm_config', $data);`.

- [ ] **Step 5: Run guest config and context tests**

Run:

```bash
vendor/bin/phpunit --filter GuestCommControllerAgentConfigTest
vendor/bin/phpunit --filter AgentCommerceContextResolverTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add app/Services/AgentPublicConfigService.php app/Http/Controllers/V1/Guest/CommController.php tests/Unit/Http/GuestCommControllerAgentConfigTest.php tests/Unit/Services/AgentCommerceContextResolverTest.php
git commit -m "feat: apply agent branding to guest config"
```

---

## Task 5: Ticket Agent Attribution And Admin Filtering

**Files:**
- Modify: `keliboard/app/Services/TicketService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/TicketController.php`
- Modify: `keliboard/app/Http/Controllers/V2/Admin/TicketController.php`
- Modify: `keliboard/app/Http/Resources/TicketResource.php`
- Create: `keliboard/tests/Unit/Http/AgentTicketContextTest.php`

- [ ] **Step 1: Add failing ticket tests**

Write tests for:

```php
public function test_agent_user_ticket_records_agent_context(): void
{
    $agent = $this->createActiveAgent('agent@example.test');
    $buyer = $this->createUser('buyer@example.test');
    AgentUser::query()->create([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $ticket = app(TicketService::class)->createTicket(
        $buyer->id,
        'Help',
        '0',
        'Need support',
        [],
        ['agent_context' => app(AgentCommerceContextResolver::class)->resolveUser($buyer)]
    );

    $this->assertSame($agent->id, (int) $ticket->agent_user_id);
    $this->assertNull($ticket->agent_domain_id);
}
```

And a controller test where admin fetch with `agent_user_id` only returns that agent's tickets.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter AgentTicketContextTest`

Expected: FAIL because ticket service does not persist agent context.

- [ ] **Step 3: Extend `TicketService::createTicket`**

Change signature:

```php
public function createTicket($userId, $subject, $level, $message, array $images = [], array $options = [])
```

Inside ticket creation:

```php
$agentContext = is_array($options['agent_context'] ?? null) ? $options['agent_context'] : null;
$ticket = Ticket::create([
    'user_id' => $userId,
    'agent_user_id' => $agentContext ? (int) $agentContext['agent_user_id'] : null,
    'agent_domain_id' => $agentContext && ($agentContext['agent_domain_id'] ?? null) !== null
        ? (int) $agentContext['agent_domain_id']
        : null,
    'subject' => $subject,
    'level' => $level,
    'status' => Ticket::STATUS_OPENING,
    'reply_status' => Ticket::REPLY_STATUS_WAITING_ADMIN,
]);
```

- [ ] **Step 4: Pass context from user ticket creation**

In `V1\User\TicketController::save()`, call:

```php
$agentContext = app(AgentCommerceContextResolver::class)->resolveRequest($request, $request->user());
$ticket = $ticketService->createTicket(
    $request->user()->id,
    (string) $request->input('subject', ''),
    (string) $request->input('level', ''),
    (string) $request->input('message', ''),
    $images,
    ['agent_context' => $agentContext]
);
```

- [ ] **Step 5: Add admin filters and resource fields**

In `V2\Admin\TicketController`, add `agent_user_id` and `agent_domain_id` to `TICKET_FILTER_FIELDS`, `TICKET_SORT_FIELDS`, and `with(['user', 'agent:id,email', 'agentDomain:id,domain'])`.

Add to mapped ticket data:

```php
$ticketData['agent'] = $ticket->agent ? [
    'id' => (int) $ticket->agent->id,
    'email' => (string) $ticket->agent->email,
] : null;
$ticketData['agent_domain'] = $ticket->agentDomain ? [
    'id' => (int) $ticket->agentDomain->id,
    'domain' => (string) $ticket->agentDomain->domain,
] : null;
```

Add equivalent fields in `TicketResource`.

- [ ] **Step 6: Run ticket tests**

Run: `vendor/bin/phpunit --filter AgentTicketContextTest`

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add app/Services/TicketService.php app/Http/Controllers/V1/User/TicketController.php app/Http/Controllers/V2/Admin/TicketController.php app/Http/Resources/TicketResource.php tests/Unit/Http/AgentTicketContextTest.php
git commit -m "feat: track agent context on tickets"
```

---

## Task 6: User Frontend Agent Site Settings Tab

**Files:**
- Create: `keli-user/src/lib/agentSiteSettings.ts`
- Create: `keli-user/src/lib/agentSiteSettings.test.ts`
- Modify: `keli-user/src/services/agentCommerce.ts`
- Modify: `keli-user/src/pages/AgentCenterPage.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`

- [ ] **Step 1: Add frontend helper tests**

Create `agentSiteSettings.test.ts`:

```ts
import { buildAgentSiteSettingPayload, isValidHexColor, normalizeAgentSiteSettingDraft } from './agentSiteSettings';

describe('agent site settings helpers', () => {
  it('validates hex colors', () => {
    expect(isValidHexColor('#ff4f87')).toBe(true);
    expect(isValidHexColor('ff4f87')).toBe(false);
  });

  it('builds a trimmed payload', () => {
    expect(buildAgentSiteSettingPayload({
      agentDomainId: '12',
      siteName: '  代理站  ',
      logoUrl: ' https://cdn.example.test/logo.png ',
      landingTheme: 'sakura',
      accentColor: '#FF4F87',
      supportName: '客服',
      supportUrl: 'https://t.me/support',
      announcement: '线路正常',
      enabled: true,
    })).toMatchObject({
      agent_domain_id: 12,
      site_name: '代理站',
      accent_color: '#ff4f87',
      enabled: true,
    });
  });

  it('normalizes empty drafts', () => {
    expect(normalizeAgentSiteSettingDraft(null).siteName).toBe('');
  });
});
```

- [ ] **Step 2: Run the helper tests to verify they fail**

Run: `npm run test -- agentSiteSettings`

Expected: FAIL because helper file does not exist.

- [ ] **Step 3: Implement helper and service types**

Create helper exports:

```ts
export const AGENT_LANDING_THEMES = ['sakura', 'spark', 'blue_cat', 'detective', 'phantom'] as const;
export const isValidHexColor = (value: string) => /^#[0-9a-fA-F]{6}$/.test(value.trim());
```

Add `AgentSiteSetting` type and methods to `agentCommerceService`:

```ts
siteSettings() {
  return api.get('/user/agent/site-settings');
},
saveSiteSetting(payload: AgentSiteSettingSavePayload) {
  return api.post('/user/agent/site-settings', payload);
},
```

Include `site_settings?: AgentSiteSetting[]` in `AgentCommerceSummary`.

- [ ] **Step 4: Add the “网站设置” tab**

In `AgentCenterPage.tsx`:

- Add state: `agentSiteSettings`, `siteDraft`, `siteSaving`.
- Load `summary.site_settings`.
- Add `<TabsTrigger value="site">{t('agentCenter.siteSettings')}</TabsTrigger>`.
- Add a tab content card with:
  - domain selector: default setting plus active domains.
  - website name input.
  - logo URL input.
  - landing theme select.
  - accent color input.
  - support name input.
  - support URL input.
  - announcement textarea.
  - enabled switch.
  - compact preview using the entered name, color, and announcement.

Use existing `Input`, `Select`, `Textarea`, `Switch`, `Card`, and `Button`; do not add a new UI library.

- [ ] **Step 5: Add translations**

Add keys under `agentCenter`:

```json
"siteSettings": "网站设置",
"siteSettingsDesc": "设置代理域名展示的网站名称、Logo、主题、客服与公告。",
"defaultSiteSetting": "默认设置",
"siteName": "网站名称",
"logoUrl": "Logo URL",
"landingTheme": "落地页主题",
"accentColor": "主色调",
"supportName": "客服名称",
"supportUrl": "客服链接",
"siteAnnouncement": "网站公告",
"saveSiteSettings": "保存网站设置",
"siteSettingsSaved": "网站设置已保存",
"siteSettingsSaveFailed": "网站设置保存失败"
```

Mirror English strings in `en/translation.json`.

- [ ] **Step 6: Run frontend tests and build**

Run:

```bash
npm run test -- agentSiteSettings agentCommerce
npm run build
```

Expected: PASS.

- [ ] **Step 7: Commit**

Run:

```bash
git add src/lib/agentSiteSettings.ts src/lib/agentSiteSettings.test.ts src/services/agentCommerce.ts src/pages/AgentCenterPage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "feat: add agent site settings tab"
```

---

## Task 7: Agent Announcement Rendering In User Frontend

**Files:**
- Modify: `keli-user/src/components/AnnouncementBanner.tsx`
- Modify: `keli-user/src/App.tsx` or the component that loads guest config for announcements
- Modify: `keli-user/src/services/config.ts`
- Add or extend: `keli-user/src/lib/agentSiteSettings.test.ts`

- [ ] **Step 1: Add a frontend test for agent announcement selection**

Add helper:

```ts
export const resolveAgentAnnouncement = (config: any): string =>
  String(config?.agent_announcement || '').trim();
```

Test:

```ts
expect(resolveAgentAnnouncement({ agent_announcement: '代理公告' })).toBe('代理公告');
expect(resolveAgentAnnouncement({})).toBe('');
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `npm run test -- agentSiteSettings`

Expected: FAIL until the helper exists.

- [ ] **Step 3: Render the agent announcement**

Use the existing guest config fetch path. If `agent_announcement` is non-empty, render it before global notices in `AnnouncementBanner` or in the current layout location where announcements appear.

Use a plain text rendering path, not rich HTML:

```tsx
{agentAnnouncement ? (
  <div className="rounded-lg border border-primary/20 bg-primary/5 px-3 py-2 text-sm text-foreground">
    {agentAnnouncement}
  </div>
) : null}
```

- [ ] **Step 4: Run frontend tests and build**

Run:

```bash
npm run test -- agentSiteSettings
npm run build
```

Expected: PASS.

- [ ] **Step 5: Commit**

Run:

```bash
git add src/components/AnnouncementBanner.tsx src/App.tsx src/services/config.ts src/lib/agentSiteSettings.ts src/lib/agentSiteSettings.test.ts
git commit -m "feat: show agent site announcements"
```

---

## Task 8: Admin Audit And Ticket Source Display

**Files:**
- Modify: `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
- Modify: `keli-admin/src/services/agentCommerce.ts`
- Modify: `keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Modify: `keli-admin/src/services/ticket.ts`
- Modify: `keli-admin/src/pages/user/ticketUtils.ts`
- Modify: `keli-admin/src/pages/user/TicketManage.tsx`
- Modify: `keli-admin/src/pages/user/components/TicketToolbar.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Add admin payload fields**

In backend `AgentCommerceController`, include a `site_setting` payload with each domain row:

```php
'site_setting' => $domain->siteSetting ? app(AgentSiteSettingService::class)->payload($domain->siteSetting) : null,
```

Add the default agent setting in the admin domain response so the audit page can show domain-specific and fallback branding separately:

```php
'default_site_setting' => $defaultSetting ? app(AgentSiteSettingService::class)->payload($defaultSetting) : null,
```

- [ ] **Step 2: Add admin frontend types**

In `keli-admin/src/services/agentCommerce.ts`, add:

```ts
export interface AdminAgentSiteSetting {
  id: number;
  agent_user_id: number;
  agent_domain_id?: number | null;
  site_name?: string | null;
  logo_url?: string | null;
  landing_theme?: string | null;
  accent_color?: string | null;
  support_name?: string | null;
  support_url?: string | null;
  announcement?: string | null;
  enabled: boolean;
}
```

Attach it to `AdminAgentDomain`.

- [ ] **Step 3: Display audit fields**

In `AgentCommercePage.tsx`, add a small read-only section under each domain row:

```tsx
<div className="text-xs text-muted-foreground">
  {domain.site_setting?.site_name || t('agent_commerce.no_site_setting')}
</div>
```

Include enabled/disabled state and theme key.

- [ ] **Step 4: Show ticket source**

Extend `TicketListItem` in `keli-admin/src/services/ticket.ts`:

```ts
agent?: { id: number; email: string } | null;
agent_domain?: { id: number; domain: string } | null;
```

Add an optional `agent_user_id` filter to `TicketFilters` and render a small source line in the ticket list row:

```tsx
{ticket.agent ? (
  <div className="text-xs text-muted-foreground">{ticket.agent.email}</div>
) : null}
```

- [ ] **Step 5: Run admin tests and build**

Run:

```bash
npm run test -- agentCommerceDisplay ticketUtils
npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit**

Run:

```bash
git add app/Http/Controllers/V2/Admin/AgentCommerceController.php ../keli-admin/src/services/agentCommerce.ts ../keli-admin/src/pages/agent/AgentCommercePage.tsx ../keli-admin/src/services/ticket.ts ../keli-admin/src/pages/user/ticketUtils.ts ../keli-admin/src/pages/user/TicketManage.tsx ../keli-admin/src/pages/user/components/TicketToolbar.tsx ../keli-admin/src/locales/zh/translation.json ../keli-admin/src/locales/en/translation.json
git commit -m "feat: audit agent branding and ticket source"
```

---

## Task 9: Regression Verification

**Files:**
- No new files.
- Verify all touched packages.

- [ ] **Step 1: Run backend focused tests**

Run from `keliboard`:

```bash
vendor/bin/phpunit --filter "Agent(SiteSetting|CommerceContext|Storefront|CommerceService)|GuestCommControllerAgentConfig|AgentTicketContext|UserAgentCommerceController"
```

Expected: PASS.

- [ ] **Step 2: Run backend full unit suite**

Run from `keliboard`:

```bash
composer test
```

Expected: PASS.

- [ ] **Step 3: Run user frontend tests and build**

Run from `keli-user`:

```bash
npm run test -- agentSiteSettings agentCommerce landingThemes
npm run build
```

Expected: PASS.

- [ ] **Step 4: Run admin frontend tests and build**

Run from `keli-admin`:

```bash
npm run test -- agentCommerceDisplay ticketUtils
npm run build
```

Expected: PASS.

- [ ] **Step 5: Manual smoke test in dev mode**

Use these flows:

1. Agent opens `/agent-center`, saves default site name, logo URL, theme, support link, and announcement.
2. Guest opens agent domain and sees agent site name from `/guest/comm/config`.
3. Agent subordinate logs in through main domain and still sees agent site name/announcement.
4. Agent subordinate opens store and sees agent prices.
5. Agent subordinate creates a ticket; admin ticket list shows agent source.
6. Agent balance below order cost blocks checkout before payment creation.

- [ ] **Step 6: Confirm no uncommitted verification changes remain**

Run:

```bash
git status --short
```

Expected: no output. If files are listed, return to the task that introduced those files, fix the issue there, rerun that task's tests, and commit that task's concrete file list.
