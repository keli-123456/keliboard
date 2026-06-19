# Agent Lightweight Site Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose and apply the effective agent storefront identity for logged-in users and agent domains, while keeping normal global site behavior unchanged.

**Architecture:** Reuse `AgentCommerceContextResolver` as the ownership source and `AgentSiteSettingService::resolve()` as the settings source. Add a read-only backend payload service and endpoint, merge agent announcements into user notices, then add a small `keli-user` client helper so the frontend can consume the effective site payload without scattering request logic.

**Tech Stack:** Laravel PHP services/controllers/routes with PHPUnit unit tests; React TypeScript frontend helpers with Vitest; existing Xboard response helpers and API wrapper.

---

## File Structure

- Create: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentSiteContextService.php`
  - One responsibility: resolve the current request/user into a safe user-facing agent site payload.
- Create: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Controllers\V1\User\AgentSiteContextController.php`
  - One responsibility: return `{ site: null }` or `{ site: { ... } }` through the existing user API response shape.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Controllers\V1\User\NoticeController.php`
  - Add synthetic agent announcement merging while preserving existing notice pagination behavior.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Routes\V1\UserRoute.php`
  - Register `GET /user/agent/site-context`.
- Create: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentSiteContextServiceTest.php`
  - Covers null context, subordinate default setting, domain setting, disabled domain fallback, and disabled default.
- Create: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\UserNoticeAgentAnnouncementTest.php`
  - Covers synthetic announcement insertion and unchanged global notices.
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\AgentTicketContextTest.php`
  - Add or keep regression coverage proving ticket ownership for domain and subordinate contexts.
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentSiteContext.ts`
  - Defines types and normalizers for the safe agent site context payload.
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentSiteContext.test.ts`
  - Covers payload normalization and null payload behavior.
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\services\agentSiteContext.ts`
  - Wraps `GET /user/agent/site-context`.
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\components\AnnouncementBanner.tsx`
  - Stop duplicating guest-config agent announcements once backend notices include synthetic agent announcements.

---

### Task 1: Backend Site Context Service

**Files:**
- Create: `C:\Users\Administrator\Documents\keli\keliboard\app\Services\AgentSiteContextService.php`
- Test: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Services\AgentSiteContextServiceTest.php`

- [ ] **Step 1: Write the failing service tests**

Create `tests/Unit/Services/AgentSiteContextServiceTest.php` with these tests:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentSiteContextServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
    }

    public function test_returns_null_without_agent_context(): void
    {
        $request = Request::create('https://main.example.test/api/v1/user/agent/site-context', 'GET');

        $this->assertNull(app(AgentSiteContextService::class)->resolve($request, null));
    }

    public function test_returns_default_setting_for_bound_subordinate(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        $this->bindBuyerToAgent($agent, $buyer);
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Agent Default',
            'logo_url' => 'https://assets.example.test/logo.png',
            'landing_theme' => 'sakura',
            'accent_color' => '#ff4f87',
            'support_name' => 'Agent Support',
            'support_url' => 'https://support.example.test',
            'announcement' => 'Welcome from agent',
            'seo_title' => 'Agent SEO',
            'seo_description' => 'Agent description',
        ]);

        $request = Request::create('https://main.example.test/api/v1/user/agent/site-context', 'GET');
        $site = app(AgentSiteContextService::class)->resolve($request, $buyer);

        $this->assertSame($agent->id, $site['agent_user_id']);
        $this->assertNull($site['agent_domain_id']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_USER_BINDING, $site['source']);
        $this->assertSame('', $site['domain']);
        $this->assertSame('Agent Default', $site['site_name']);
        $this->assertSame('https://assets.example.test/logo.png', $site['logo_url']);
        $this->assertSame('sakura', $site['landing_theme']);
        $this->assertSame('#ff4f87', $site['accent_color']);
        $this->assertSame('Agent Support', $site['support_name']);
        $this->assertSame('https://support.example.test', $site['support_url']);
        $this->assertSame('Welcome from agent', $site['announcement']);
        $this->assertSame('Agent SEO', $site['seo_title']);
        $this->assertSame('Agent description', $site['seo_description']);
    }

    public function test_returns_domain_setting_for_agent_domain(): void
    {
        $agent = $this->createActiveAgent('domain-agent@example.test');
        $domain = $this->createActiveDomain($agent, 'shop.example.test');
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Default Site',
            'announcement' => 'Default announcement',
        ]);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Domain Site',
            'announcement' => 'Domain announcement',
        ]);

        $request = Request::create('https://shop.example.test/api/v1/user/agent/site-context', 'GET');
        $request->headers->set('host', 'shop.example.test');
        $site = app(AgentSiteContextService::class)->resolve($request, null);

        $this->assertSame($agent->id, $site['agent_user_id']);
        $this->assertSame($domain->id, $site['agent_domain_id']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $site['source']);
        $this->assertSame('shop.example.test', $site['domain']);
        $this->assertSame('Domain Site', $site['site_name']);
        $this->assertSame('Domain announcement', $site['announcement']);
    }

    public function test_disabled_domain_setting_falls_back_to_default_setting(): void
    {
        $agent = $this->createActiveAgent('fallback-agent@example.test');
        $domain = $this->createActiveDomain($agent, 'fallback.example.test');
        $this->createSiteSetting($agent, null, ['site_name' => 'Default Site']);
        $this->createSiteSetting($agent, $domain, [
            'site_name' => 'Disabled Domain Site',
            'enabled' => false,
        ]);

        $request = Request::create('https://fallback.example.test/api/v1/user/agent/site-context', 'GET');
        $request->headers->set('host', 'fallback.example.test');
        $site = app(AgentSiteContextService::class)->resolve($request, null);

        $this->assertSame('Default Site', $site['site_name']);
        $this->assertNull($site['agent_domain_id']);
        $this->assertSame('fallback.example.test', $site['domain']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $site['source']);
    }

    public function test_disabled_default_setting_returns_null(): void
    {
        $agent = $this->createActiveAgent('disabled-agent@example.test');
        $buyer = $this->createUser('disabled-buyer@example.test');
        $this->bindBuyerToAgent($agent, $buyer);
        $this->createSiteSetting($agent, null, [
            'site_name' => 'Disabled Site',
            'enabled' => false,
        ]);

        $request = Request::create('https://main.example.test/api/v1/user/agent/site-context', 'GET');

        $this->assertNull(app(AgentSiteContextService::class)->resolve($request, $buyer));
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);

        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }

    private function createActiveDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'is_primary' => false,
            'verification_token' => 'verify-' . $domain,
            'verified_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function bindBuyerToAgent(User $agent, User $buyer): void
    {
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'source' => 'manual',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createSiteSetting(User $agent, ?AgentDomain $domain = null, array $attributes = []): AgentSiteSetting
    {
        return AgentSiteSetting::query()->create(array_merge([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => $domain?->id,
            'enabled' => true,
            'setting_scope' => $domain ? AgentSiteSetting::SCOPE_DOMAIN : AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => $domain ? (string) $domain->id : AgentSiteSetting::KEY_DEFAULT,
            'site_name' => '',
            'logo_url' => '',
            'landing_theme' => '',
            'accent_color' => '',
            'support_name' => '',
            'support_url' => '',
            'announcement' => '',
            'seo_title' => '',
            'seo_description' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ], $attributes));
    }
}
```

- [ ] **Step 2: Run the service tests and verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentSiteContextServiceTest.php
```

Expected: FAIL because `App\Services\AgentSiteContextService` does not exist.

- [ ] **Step 3: Implement the service**

Create `app/Services/AgentSiteContextService.php`:

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AgentSiteContextService
{
    public function resolve(Request $request, ?User $user = null): ?array
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $user);
        if (!$context) {
            return null;
        }

        $setting = app(AgentSiteSettingService::class)->resolve($context);
        if (!$setting) {
            return null;
        }

        return [
            'enabled' => (bool) ($setting['enabled'] ?? true),
            'agent_user_id' => (int) ($setting['agent_user_id'] ?? $context['agent_user_id'] ?? 0),
            'agent_domain_id' => $this->nullableInt($setting['agent_domain_id'] ?? null),
            'source' => (string) ($context['source'] ?? ''),
            'domain' => (string) ($context['domain'] ?? ''),
            'site_name' => $this->stringValue($setting['site_name'] ?? ''),
            'logo_url' => $this->stringValue($setting['logo_url'] ?? ''),
            'landing_theme' => $this->stringValue($setting['landing_theme'] ?? ''),
            'accent_color' => $this->stringValue($setting['accent_color'] ?? ''),
            'support_name' => $this->stringValue($setting['support_name'] ?? ''),
            'support_url' => $this->stringValue($setting['support_url'] ?? ''),
            'announcement' => $this->stringValue($setting['announcement'] ?? ''),
            'seo_title' => $this->stringValue($setting['seo_title'] ?? ''),
            'seo_description' => $this->stringValue($setting['seo_description'] ?? ''),
            'created_at' => $this->nullableInt($setting['created_at'] ?? null),
            'updated_at' => $this->nullableInt($setting['updated_at'] ?? null),
        ];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
```

- [ ] **Step 4: Run the service tests and verify they pass**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentSiteContextServiceTest.php
```

Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add app/Services/AgentSiteContextService.php tests/Unit/Services/AgentSiteContextServiceTest.php
git commit -m "feat: resolve user agent site context"
```

---

### Task 2: User Site Context Endpoint

**Files:**
- Create: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Controllers\V1\User\AgentSiteContextController.php`
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Routes\V1\UserRoute.php`
- Test: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\UserAgentSiteContextControllerTest.php`

- [ ] **Step 1: Write the failing endpoint tests**

Create `tests/Unit/Http/UserAgentSiteContextControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\AgentSiteContextController;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserAgentSiteContextControllerTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
    }

    public function test_returns_null_site_for_normal_user(): void
    {
        $user = $this->createUser('normal@example.test');
        $request = $this->userRequest($user);

        $payload = $this->responsePayload(app(AgentSiteContextController::class)->show($request));

        $this->assertSame(0, $payload['status']);
        $this->assertNull($payload['data']['site']);
    }

    public function test_returns_site_for_bound_subordinate(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $user = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $user->id,
            'source' => 'manual',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'enabled' => true,
            'setting_scope' => AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => AgentSiteSetting::KEY_DEFAULT,
            'site_name' => 'Agent Site',
            'logo_url' => '',
            'landing_theme' => 'sakura',
            'accent_color' => '',
            'support_name' => '',
            'support_url' => '',
            'announcement' => 'Agent announcement',
            'seo_title' => '',
            'seo_description' => '',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $payload = $this->responsePayload(app(AgentSiteContextController::class)->show($this->userRequest($user)));

        $this->assertSame('Agent Site', $payload['data']['site']['site_name']);
        $this->assertSame('Agent announcement', $payload['data']['site']['announcement']);
        $this->assertSame($agent->id, $payload['data']['site']['agent_user_id']);
    }

    private function userRequest(User $user): Request
    {
        $request = Request::create('/api/v1/user/agent/site-context', 'GET');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);
        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }
}
```

- [ ] **Step 2: Run the endpoint tests and verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/UserAgentSiteContextControllerTest.php
```

Expected: FAIL because `AgentSiteContextController` does not exist.

- [ ] **Step 3: Implement the controller**

Create `app/Http/Controllers/V1/User/AgentSiteContextController.php`:

```php
<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;

class AgentSiteContextController extends Controller
{
    public function show(Request $request)
    {
        return $this->success([
            'site' => app(AgentSiteContextService::class)->resolve($request, $request->user()),
        ]);
    }
}
```

- [ ] **Step 4: Register the route**

Modify `app/Http/Routes/V1/UserRoute.php`:

```php
use App\Http\Controllers\V1\User\AgentSiteContextController;
```

Add inside the user route group near the other agent routes:

```php
$router->get('/agent/site-context', [AgentSiteContextController::class, 'show']);
```

- [ ] **Step 5: Run the endpoint tests and route registration test group**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/UserAgentSiteContextControllerTest.php tests/Unit/Http/UserAgentCommerceControllerTest.php --filter 'site_context|site_settings|siteSettings|returns_site|returns_null'
```

Expected: OK.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/V1/User/AgentSiteContextController.php app/Http/Routes/V1/UserRoute.php tests/Unit/Http/UserAgentSiteContextControllerTest.php
git commit -m "feat: expose user agent site context"
```

---

### Task 3: Agent Announcement in User Notices

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\app\Http\Controllers\V1\User\NoticeController.php`
- Test: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\UserNoticeAgentAnnouncementTest.php`

- [ ] **Step 1: Write failing notice tests**

Create `tests/Unit/Http/UserNoticeAgentAnnouncementTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\V1\User\NoticeController;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\AgentUser;
use App\Models\Notice;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class UserNoticeAgentAnnouncementTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->bindJsonResponseFactory();
        $this->createUserTable();
        $this->createNoticeTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
        $this->createAgentSiteSettingTable();
    }

    public function test_prepends_agent_announcement_for_bound_subordinate(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        AgentUser::query()->create([
            'agent_user_id' => $agent->id,
            'sub_user_id' => $buyer->id,
            'source' => 'manual',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        AgentSiteSetting::query()->create([
            'agent_user_id' => $agent->id,
            'agent_domain_id' => null,
            'enabled' => true,
            'setting_scope' => AgentSiteSetting::SCOPE_DEFAULT,
            'setting_key' => AgentSiteSetting::KEY_DEFAULT,
            'site_name' => 'Agent Site',
            'logo_url' => '',
            'landing_theme' => '',
            'accent_color' => '',
            'support_name' => '',
            'support_url' => '',
            'announcement' => 'Agent announcement',
            'seo_title' => '',
            'seo_description' => '',
            'created_at' => time() - 60,
            'updated_at' => time() - 30,
        ]);
        $this->createNotice('Global notice');

        $payload = $this->fetchNotices($buyer);

        $this->assertSame('agent-announcement', $payload['data'][0]['id']);
        $this->assertSame('Agent Site', $payload['data'][0]['title']);
        $this->assertSame('Agent announcement', $payload['data'][0]['content']);
        $this->assertTrue($payload['data'][0]['show']);
        $this->assertTrue($payload['data'][0]['agent_context']);
        $this->assertSame('Global notice', $payload['data'][1]['title']);
        $this->assertSame(2, $payload['total']);
    }

    public function test_normal_user_notice_response_is_unchanged(): void
    {
        $user = $this->createUser('normal@example.test');
        $this->createNotice('Global notice');

        $payload = $this->fetchNotices($user);

        $this->assertSame('Global notice', $payload['data'][0]['title']);
        $this->assertArrayNotHasKey('agent_context', $payload['data'][0]);
        $this->assertSame(1, $payload['total']);
    }

    private function fetchNotices(User $user): array
    {
        $request = Request::create('/api/v1/user/notice/fetch?current=1', 'GET');
        $request->setUserResolver(fn () => $user);

        return $this->responsePayload(app(NoticeController::class)->fetch($request));
    }

    private function createNotice(string $title): Notice
    {
        return Notice::query()->create([
            'title' => $title,
            'content' => $title . ' content',
            'show' => true,
            'sort' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash('secret123', PASSWORD_BCRYPT),
            'uuid' => $email . '-uuid',
            'token' => $email . '-token',
            'balance' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createActiveAgent(string $email): User
    {
        $agent = $this->createUser($email);
        AgentProfile::query()->create([
            'user_id' => $agent->id,
            'status' => AgentCenterService::STATUS_ACTIVE,
            'level' => 'default',
            'enabled_at' => time(),
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $agent;
    }
}
```

- [ ] **Step 2: Run notice tests and verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/UserNoticeAgentAnnouncementTest.php
```

Expected: FAIL because `/user/notice/fetch` does not add agent announcements.

- [ ] **Step 3: Update notice controller**

Modify `app/Http/Controllers/V1/User/NoticeController.php`:

```php
<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Services\AgentSiteContextService;
use Illuminate\Http\Request;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $current = max(1, (int) ($request->input('current') ?: 1));
        $pageSize = 5;
        $agentNotice = $current === 1 ? $this->agentAnnouncement($request) : null;
        $agentOffset = $agentNotice ? 1 : 0;
        $globalLimit = max(0, $pageSize - $agentOffset);

        $model = Notice::orderBy('sort', 'ASC')
            ->orderBy('id', 'DESC')
            ->where('show', true);

        $total = $model->count() + $agentOffset;
        $globalNotices = $model->forPage($current, $globalLimit ?: $pageSize)->get();
        $data = $globalNotices->values()->all();
        if ($agentNotice) {
            array_unshift($data, $agentNotice);
        }

        return response([
            'data' => $data,
            'total' => $total,
        ]);
    }

    private function agentAnnouncement(Request $request): ?array
    {
        $site = app(AgentSiteContextService::class)->resolve($request, $request->user());
        $announcement = trim((string) ($site['announcement'] ?? ''));
        if ($announcement === '') {
            return null;
        }

        $title = trim((string) ($site['site_name'] ?? ''));
        if ($title === '') {
            $title = trim((string) ($site['support_name'] ?? ''));
        }
        if ($title === '') {
            $title = 'Site Announcement';
        }

        return [
            'id' => 'agent-announcement',
            'title' => $title,
            'content' => $announcement,
            'show' => true,
            'created_at' => $site['updated_at'] ?? time(),
            'agent_context' => true,
        ];
    }
}
```

- [ ] **Step 4: Run notice tests and service tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/UserNoticeAgentAnnouncementTest.php tests/Unit/Services/AgentSiteContextServiceTest.php
```

Expected: OK.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/V1/User/NoticeController.php tests/Unit/Http/UserNoticeAgentAnnouncementTest.php
git commit -m "feat: include agent announcements in user notices"
```

---

### Task 4: Ticket Ownership Regression

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keliboard\tests\Unit\Http\AgentTicketContextTest.php`

- [ ] **Step 1: Add missing regression tests if not already present**

Open `tests/Unit/Http/AgentTicketContextTest.php`. If it already includes equivalent assertions for user-binding and domain ownership, keep them. If either is missing, add these test methods and reuse the file's existing helper methods:

```php
public function test_ticket_created_from_agent_domain_keeps_domain_ownership(): void
{
    $agent = $this->createActiveAgent('domain-ticket-agent@example.test');
    $buyer = $this->createUser('domain-ticket-buyer@example.test');
    $domain = $this->createActiveDomain($agent, 'ticket-agent.example.test');
    $request = Request::create('https://ticket-agent.example.test/api/v1/user/ticket/save', 'POST');
    $request->headers->set('host', 'ticket-agent.example.test');

    $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $buyer);
    $ticket = app(TicketService::class)->createTicket(
        $buyer->id,
        'Need help',
        0,
        'Message',
        [],
        ['agent_context' => $context]
    );

    $this->assertSame($agent->id, (int) $ticket->agent_user_id);
    $this->assertSame($domain->id, (int) $ticket->agent_domain_id);
}

public function test_ticket_created_by_bound_subordinate_keeps_agent_without_domain(): void
{
    $agent = $this->createActiveAgent('bound-ticket-agent@example.test');
    $buyer = $this->createUser('bound-ticket-buyer@example.test');
    $this->bindBuyerToAgent($agent, $buyer);
    $request = Request::create('https://main.example.test/api/v1/user/ticket/save', 'POST');

    $context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $buyer);
    $ticket = app(TicketService::class)->createTicket(
        $buyer->id,
        'Need help',
        0,
        'Message',
        [],
        ['agent_context' => $context]
    );

    $this->assertSame($agent->id, (int) $ticket->agent_user_id);
    $this->assertNull($ticket->agent_domain_id);
}
```

- [ ] **Step 2: Run ticket regression tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentTicketContextTest.php
```

Expected: OK.

- [ ] **Step 3: Commit only if the test file changed**

```bash
git add tests/Unit/Http/AgentTicketContextTest.php
git commit -m "test: cover agent ticket ownership contexts"
```

If no changes were needed, do not create an empty commit.

---

### Task 5: Frontend Site Context Client

**Files:**
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentSiteContext.ts`
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\lib\agentSiteContext.test.ts`
- Create: `C:\Users\Administrator\Documents\keli\keli-user\src\services\agentSiteContext.ts`

- [ ] **Step 1: Write failing frontend normalization tests**

Create `src/lib/agentSiteContext.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import { normalizeAgentSiteContext } from './agentSiteContext';

describe('agent site context helpers', () => {
  it('normalizes a site payload', () => {
    expect(normalizeAgentSiteContext({
      site: {
        enabled: 1,
        agent_user_id: '12',
        agent_domain_id: '34',
        source: 'domain',
        domain: 'shop.example.test',
        site_name: '  Agent Site  ',
        logo_url: ' https://assets.example.test/logo.png ',
        landing_theme: 'sakura',
        accent_color: '#ff4f87',
        support_name: ' Support ',
        support_url: ' https://support.example.test ',
        announcement: ' Hello ',
        seo_title: ' SEO ',
        seo_description: ' Description ',
      },
    })).toMatchObject({
      enabled: true,
      agentUserId: 12,
      agentDomainId: 34,
      source: 'domain',
      domain: 'shop.example.test',
      siteName: 'Agent Site',
      logoUrl: 'https://assets.example.test/logo.png',
      landingTheme: 'sakura',
      accentColor: '#ff4f87',
      supportName: 'Support',
      supportUrl: 'https://support.example.test',
      announcement: 'Hello',
      seoTitle: 'SEO',
      seoDescription: 'Description',
    });
  });

  it('returns null for missing site payload', () => {
    expect(normalizeAgentSiteContext({ site: null })).toBeNull();
    expect(normalizeAgentSiteContext(null)).toBeNull();
  });
});
```

- [ ] **Step 2: Run frontend test and verify it fails**

Run:

```bash
npm run test -- agentSiteContext
```

Expected: FAIL because `src/lib/agentSiteContext.ts` does not exist.

- [ ] **Step 3: Implement the frontend normalizer**

Create `src/lib/agentSiteContext.ts`:

```ts
export type AgentSiteContext = {
  enabled: boolean;
  agentUserId: number;
  agentDomainId: number | null;
  source: string;
  domain: string;
  siteName: string;
  logoUrl: string;
  landingTheme: string;
  accentColor: string;
  supportName: string;
  supportUrl: string;
  announcement: string;
  seoTitle: string;
  seoDescription: string;
};

const cleanString = (value: unknown): string => String(value ?? '').trim();

const numberOrNull = (value: unknown): number | null => {
  const n = Number(value);
  return Number.isFinite(n) && n > 0 ? n : null;
};

const booleanValue = (value: unknown): boolean => {
  if (value === true) return true;
  if (value === false || value === null || value === undefined) return false;
  if (typeof value === 'number') return Number.isFinite(value) && value !== 0;
  const raw = cleanString(value).toLowerCase();
  return raw === '1' || raw === 'true' || raw === 'yes';
};

export const normalizeAgentSiteContext = (payload: any): AgentSiteContext | null => {
  const site = payload?.site;
  if (!site || typeof site !== 'object') return null;

  return {
    enabled: booleanValue(site.enabled),
    agentUserId: numberOrNull(site.agent_user_id) ?? 0,
    agentDomainId: numberOrNull(site.agent_domain_id),
    source: cleanString(site.source),
    domain: cleanString(site.domain),
    siteName: cleanString(site.site_name),
    logoUrl: cleanString(site.logo_url),
    landingTheme: cleanString(site.landing_theme),
    accentColor: cleanString(site.accent_color).toLowerCase(),
    supportName: cleanString(site.support_name),
    supportUrl: cleanString(site.support_url),
    announcement: cleanString(site.announcement),
    seoTitle: cleanString(site.seo_title),
    seoDescription: cleanString(site.seo_description),
  };
};
```

- [ ] **Step 4: Implement the frontend API service**

Create `src/services/agentSiteContext.ts`:

```ts
import { normalizeAgentSiteContext } from '@/lib/agentSiteContext';
import { unwrapApiData } from '@/lib/apiData';
import { api } from '@/services/api';

export const agentSiteContextService = {
  async fetch() {
    const resp = await api.get('/user/agent/site-context');
    return normalizeAgentSiteContext(unwrapApiData(resp));
  },
};
```

- [ ] **Step 5: Run frontend tests**

Run:

```bash
npm run test -- agentSiteContext apiData
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/lib/agentSiteContext.ts src/lib/agentSiteContext.test.ts src/services/agentSiteContext.ts
git commit -m "feat: add agent site context client"
```

---

### Task 6: Frontend Announcement Deduplication

**Files:**
- Modify: `C:\Users\Administrator\Documents\keli\keli-user\src\components\AnnouncementBanner.tsx`

- [ ] **Step 1: Remove guest-config announcement duplication**

In `AnnouncementBanner.tsx`, remove the helper that builds `__agent_site_announcement__` from guest config:

```ts
const buildAgentConfigAnnouncement = ...
```

Then remove this block from the fetch effect:

```ts
const agentConfigPromise = configService.fetchGuestConfig().catch(() => null);
...
const agentConfigResp = await agentConfigPromise;
const agentConfig = agentConfigResp ? (unwrapApiData(agentConfigResp) as Record<string, any> | null) : null;
const agentAnnouncement = buildAgentConfigAnnouncement(t, agentConfig);
if (agentAnnouncement) all.unshift(agentAnnouncement);
```

Also remove unused imports that become dead after this edit, including `configService` or `unwrapApiData` if no other code in the file uses them.

- [ ] **Step 2: Build the frontend**

Run:

```bash
npm run build
```

Expected: build succeeds with no TypeScript errors.

- [ ] **Step 3: Commit**

```bash
git add src/components/AnnouncementBanner.tsx
git commit -m "fix: avoid duplicate agent announcements"
```

---

### Task 7: Full Verification and Push

**Files:**
- Verify both repositories.

- [ ] **Step 1: Run backend focused PHPUnit suite**

Run in `C:\Users\Administrator\Documents\keli\keliboard`:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentSiteContextServiceTest.php tests/Unit/Http/UserAgentSiteContextControllerTest.php tests/Unit/Http/UserNoticeAgentAnnouncementTest.php tests/Unit/Http/AgentTicketContextTest.php tests/Unit/Services/AgentSiteSettingServiceTest.php tests/Unit/Http/GuestCommControllerAgentConfigTest.php
```

Expected: OK.

- [ ] **Step 2: Run frontend focused tests and build**

Run in `C:\Users\Administrator\Documents\keli\keli-user`:

```bash
npm run test -- agentSiteContext agentSiteSettings
npm run build
```

Expected: tests pass and build succeeds.

- [ ] **Step 3: Check git status**

Run:

```bash
git -C C:\Users\Administrator\Documents\keli\keliboard status --short --branch
git -C C:\Users\Administrator\Documents\keli\keli-user status --short --branch
```

Expected:
- `keliboard` has only intended committed changes and is ahead of origin.
- `keli-user` has only intended committed changes plus the pre-existing untracked `design-audits/`, `dev_server.err.log`, and `dev_server.out.log`.

- [ ] **Step 4: Push branches**

Run:

```bash
git -C C:\Users\Administrator\Documents\keli\keliboard push
git -C C:\Users\Administrator\Documents\keli\keli-user push
```

Expected: both pushes succeed.

---

## Self-Review

- Spec coverage: The plan covers effective site context, user endpoint, announcement merge, ticket ownership regression, frontend client helpers, and frontend duplicate announcement cleanup. It intentionally leaves agent payment customization, pricing, knowledge base, and admin/staff filtering out of scope as specified.
- Placeholder scan: No unresolved placeholder markers or open-ended implementation instructions remain. Each code task includes concrete code or a concrete edit target.
- Type consistency: Backend payload uses snake_case keys (`agent_user_id`, `site_name`); frontend normalizer converts to camelCase (`agentUserId`, `siteName`). `AgentSiteContextService::resolve()` is used by both controller and notice code.
