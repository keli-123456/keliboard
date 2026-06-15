# Agent Center MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first-level Agent Center MVP across `keliboard` and `keli-user`.

**Architecture:** `keliboard` is the source of truth for eligibility, pricing, balance deduction, subordinate ownership, plan assignment, traffic reset, and ledger. `keli-user` adds the `/agent-center` route, service client, navigation entry, and operational UI. Sensitive operations are performed only by backend transactions.

**Tech Stack:** Laravel 12/PHPUnit for `keliboard`; React/Vite/Vitest/Tailwind/shadcn-style components for `keli-user`.

---

## File Map

### Backend: `keliboard`

- Create `database/migrations/2026_06_15_000001_create_agent_center_tables.php`
  - Creates `v2_agent_profile`, `v2_agent_user`, and `v2_agent_ledger`.
- Create `app/Models/AgentProfile.php`
  - Eloquent model for agent status.
- Create `app/Models/AgentUser.php`
  - Eloquent model for agent-owned subordinate users.
- Create `app/Models/AgentLedger.php`
  - Append-only ledger model.
- Create `app/Services/AgentCenterService.php`
  - Central business logic and transaction boundary.
- Create `app/Http/Controllers/V1/User/AgentController.php`
  - Authenticated user API controller.
- Modify `app/Http/Routes/V1/UserRoute.php`
  - Adds `/user/agent/*` routes.
- Create `tests/Unit/Services/AgentCenterServiceTest.php`
  - TDD coverage for eligibility, ownership, balance deduction, assignment, reset, and ledger.
- Create `tests/Unit/Http/AgentControllerTest.php`
  - Thin controller tests for response shape and route-facing validation.

### Frontend: `keli-user`

- Create `src/services/agent.ts`
  - Typed API client for `/user/agent/*`.
- Create `src/lib/agent.ts`
  - Pure formatting and status helpers with Vitest coverage.
- Create `src/lib/agent.test.ts`
  - Tests for helper behavior.
- Create `src/pages/AgentCenterPage.tsx`
  - Page shell, overview, subordinate users, ledger, dialogs.
- Modify `src/App.tsx`
  - Adds `/agent-center` protected route.
- Modify `src/components/NavigationBar.tsx`
  - Adds `代理中心` nav entry.
- Modify `src/locales/zh/translation.json`
  - Adds Chinese copy.
- Modify `src/locales/en/translation.json`
  - Adds English copy fallback.

---

## Task 1: Backend RED Tests For Agent Service

**Files:**
- Create: `keliboard/tests/Unit/Services/AgentCenterServiceTest.php`

- [ ] **Step 1: Write the failing service tests**

Create tests that define the expected backend behavior before production code exists:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Services\AgentCenterService;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCenterServiceTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createPlanTable();
        $this->createAgentTables();
        $this->bindAgentSettings();
    }

    public function test_unlock_creates_active_profile_when_balance_meets_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 10000);

        $result = app(AgentCenterService::class)->unlock($agent);

        $this->assertSame('active', $result['profile']['status']);
        $this->assertSame(1, $this->tableCount('v2_agent_profile'));
        $this->assertSame(0, $this->ledgerCount('unlock'));
    }

    public function test_unlock_rejects_user_below_balance_threshold(): void
    {
        $agent = $this->createUser('agent@example.test', 100);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Agent unlock threshold has not been reached');

        app(AgentCenterService::class)->unlock($agent);
    }

    public function test_create_subordinate_assigns_unique_agent_ownership(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);

        $created = app(AgentCenterService::class)->createSubordinate($agent, [
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'remark' => 'first customer',
        ]);

        $this->assertSame('buyer@example.test', $created['user']['email']);
        $this->assertSame('first customer', $created['user']['remark']);
        $this->assertSame(1, $this->tableCount('v2_agent_user'));
    }

    public function test_assign_plan_deducts_agent_balance_updates_subordinate_and_writes_ledger(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        $result = app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
            'plan_id' => $plan->id,
            'period' => 'monthly',
        ]);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(8000, (int) $agent->balance);
        $this->assertSame($plan->id, (int) $subordinate->plan_id);
        $this->assertSame($plan->group_id, (int) $subordinate->group_id);
        $this->assertSame(128 * 1073741824, (int) $subordinate->transfer_enable);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-2000, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('assign_plan'));
    }

    public function test_assign_plan_rolls_back_when_balance_is_insufficient(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 100);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test');
        $plan = $this->createPlan('Starter', ['monthly' => 20.00], 128, 2);

        try {
            app(AgentCenterService::class)->assignPlan($agent, $subordinate->id, [
                'plan_id' => $plan->id,
                'period' => 'monthly',
            ]);
            $this->fail('Expected insufficient balance exception.');
        } catch (ApiException $exception) {
            $this->assertSame('Insufficient balance', $exception->getMessage());
        }

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(100, (int) $agent->balance);
        $this->assertNull($subordinate->plan_id);
        $this->assertSame(0, $this->ledgerCount('assign_plan'));
    }

    public function test_reset_traffic_deducts_reset_price_and_clears_usage(): void
    {
        $agent = $this->createActiveAgent('agent@example.test', 10000);
        $plan = $this->createPlan('Starter', ['monthly' => 20.00, 'reset_traffic' => 3.50], 128, 2);
        $subordinate = $this->createOwnedSubordinate($agent, 'buyer@example.test', [
            'plan_id' => $plan->id,
            'u' => 1024,
            'd' => 2048,
        ]);

        $result = app(AgentCenterService::class)->resetTraffic($agent, $subordinate->id);

        $agent->refresh();
        $subordinate->refresh();

        $this->assertSame(9650, (int) $agent->balance);
        $this->assertSame(0, (int) $subordinate->u);
        $this->assertSame(0, (int) $subordinate->d);
        $this->assertSame(-350, (int) $result['ledger']['amount']);
        $this->assertSame(1, $this->ledgerCount('reset_traffic'));
    }
}
```

Include private helpers in the test file for `createPlanTable`, `createAgentTables`, `bindAgentSettings`, `createUser`, `createActiveAgent`, `createOwnedSubordinate`, `createPlan`, `ledgerCount`, and `tableCount`.

- [ ] **Step 2: Run the service test and verify RED**

Run:

```powershell
vendor\bin\phpunit tests\Unit\Services\AgentCenterServiceTest.php
```

Expected: FAIL because `App\Services\AgentCenterService` and agent models do not exist yet.

---

## Task 2: Backend GREEN Service, Models, And Migrations

**Files:**
- Create: `keliboard/database/migrations/2026_06_15_000001_create_agent_center_tables.php`
- Create: `keliboard/app/Models/AgentProfile.php`
- Create: `keliboard/app/Models/AgentUser.php`
- Create: `keliboard/app/Models/AgentLedger.php`
- Create: `keliboard/app/Services/AgentCenterService.php`

- [ ] **Step 1: Add the migration**

Create all three tables using guarded `Schema::hasTable(...)` checks. Use integer cents for all money columns and unique indexes on `v2_agent_profile.user_id` and `v2_agent_user.sub_user_id`.

- [ ] **Step 2: Add Eloquent models**

Add `$table`, `$dateFormat = 'U'`, `$fillable`, and casts for each model. `AgentLedger.metadata` casts to array.

- [ ] **Step 3: Implement `AgentCenterService` minimally**

Implement these public methods:

```php
overview(User $agent): array
unlock(User $agent): array
createSubordinate(User $agent, array $payload): array
previewAssignPlan(User $agent, int $subUserId, array $payload): array
assignPlan(User $agent, int $subUserId, array $payload): array
previewResetTraffic(User $agent, int $subUserId): array
resetTraffic(User $agent, int $subUserId): array
ledger(User $agent, int $limit = 50): array
```

Keep helper methods private: settings, active profile resolution, subordinate ownership lookup, price calculation, balance deduction, plan application, user snapshot, overview snapshot, and ledger creation.

- [ ] **Step 4: Run the service test and verify GREEN**

Run:

```powershell
vendor\bin\phpunit tests\Unit\Services\AgentCenterServiceTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit backend service layer**

Run:

```powershell
git add app\Models\AgentProfile.php app\Models\AgentUser.php app\Models\AgentLedger.php app\Services\AgentCenterService.php database\migrations\2026_06_15_000001_create_agent_center_tables.php tests\Unit\Services\AgentCenterServiceTest.php
git commit -m "Add agent center service"
```

---

## Task 3: Backend User API Controller And Routes

**Files:**
- Create: `keliboard/app/Http/Controllers/V1/User/AgentController.php`
- Modify: `keliboard/app/Http/Routes/V1/UserRoute.php`
- Create: `keliboard/tests/Unit/Http/AgentControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Test that `overview`, `unlock`, `users`, and `assignPlan` return `$this->success(...)` payloads by invoking controller methods with request objects carrying an authenticated user resolver.

- [ ] **Step 2: Run controller tests and verify RED**

Run:

```powershell
vendor\bin\phpunit tests\Unit\Http\AgentControllerTest.php
```

Expected: FAIL because `AgentController` does not exist.

- [ ] **Step 3: Implement controller**

Controller methods:

```php
overview(Request $request)
unlock(Request $request)
users(Request $request)
createUser(Request $request)
assignPlanPreview(Request $request, int $id)
assignPlan(Request $request, int $id)
resetTrafficPreview(Request $request, int $id)
resetTraffic(Request $request, int $id)
ledger(Request $request)
```

Validate email/password/remark, plan id, period, and pagination limit. Delegate business logic to `AgentCenterService`.

- [ ] **Step 4: Register routes**

Under the existing `prefix => user` group, add:

```php
$router->get('/agent/overview', [AgentController::class, 'overview']);
$router->post('/agent/unlock', [AgentController::class, 'unlock']);
$router->get('/agent/users', [AgentController::class, 'users']);
$router->post('/agent/users', [AgentController::class, 'createUser']);
$router->post('/agent/users/{id}/assign-plan/preview', [AgentController::class, 'assignPlanPreview']);
$router->post('/agent/users/{id}/assign-plan', [AgentController::class, 'assignPlan']);
$router->post('/agent/users/{id}/reset-traffic/preview', [AgentController::class, 'resetTrafficPreview']);
$router->post('/agent/users/{id}/reset-traffic', [AgentController::class, 'resetTraffic']);
$router->get('/agent/ledger', [AgentController::class, 'ledger']);
```

- [ ] **Step 5: Run controller tests and service tests**

Run:

```powershell
vendor\bin\phpunit tests\Unit\Http\AgentControllerTest.php tests\Unit\Services\AgentCenterServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit backend API**

Run:

```powershell
git add app\Http\Controllers\V1\User\AgentController.php app\Http\Routes\V1\UserRoute.php tests\Unit\Http\AgentControllerTest.php
git commit -m "Add agent center user API"
```

---

## Task 4: Frontend Service And Pure Helpers

**Files:**
- Create: `keli-user/src/services/agent.ts`
- Create: `keli-user/src/lib/agent.ts`
- Create: `keli-user/src/lib/agent.test.ts`

- [ ] **Step 1: Write failing helper tests**

Test money formatting inputs, status labels, and subordinate traffic percentage helpers.

- [ ] **Step 2: Run frontend helper tests and verify RED**

Run:

```powershell
npm test -- src/lib/agent.test.ts
```

Expected: FAIL because helper file does not exist.

- [ ] **Step 3: Implement typed API client and helpers**

`agentService` methods:

```ts
overview()
unlock()
fetchUsers()
createUser(payload)
previewAssignPlan(userId, payload)
assignPlan(userId, payload)
previewResetTraffic(userId)
resetTraffic(userId)
fetchLedger()
```

Use existing `api` and paths under `/user/agent/*`.

- [ ] **Step 4: Run frontend helper tests and verify GREEN**

Run:

```powershell
npm test -- src/lib/agent.test.ts
```

Expected: PASS.

---

## Task 5: Frontend Agent Center Page

**Files:**
- Create: `keli-user/src/pages/AgentCenterPage.tsx`
- Modify: `keli-user/src/App.tsx`
- Modify: `keli-user/src/components/NavigationBar.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`

- [ ] **Step 1: Add page, route, and navigation**

Add `AgentCenterPage` lazy import or normal import consistent with `App.tsx`, route `/agent-center`, and a `BriefcaseBusiness` or `BadgeDollarSign` icon entry in `NavigationBar`.

- [ ] **Step 2: Build the page shell**

Create operational layout:

- locked/eligible state with unlock CTA;
- overview cards;
- subordinate users table;
- ledger table;
- create user dialog;
- assign plan dialog;
- reset traffic confirmation dialog.

- [ ] **Step 3: Wire API flows**

Use `agentService` and `unwrapApiData`/`unwrapApiList` patterns. Refresh overview and user list after create, assign, reset, and unlock.

- [ ] **Step 4: Add translations**

Add keys for `menu.agentCenter` and `agentCenter.*` in Chinese and English.

- [ ] **Step 5: Run frontend build**

Run:

```powershell
npm run build
```

Expected: PASS.

- [ ] **Step 6: Commit frontend work**

Run in `keli-user`:

```powershell
git add src\services\agent.ts src\lib\agent.ts src\lib\agent.test.ts src\pages\AgentCenterPage.tsx src\App.tsx src\components\NavigationBar.tsx src\locales\zh\translation.json src\locales\en\translation.json
git commit -m "Add user agent center"
```

---

## Task 6: Final Verification

**Files:**
- Inspect both repositories.

- [ ] **Step 1: Run backend focused tests**

Run in `keliboard`:

```powershell
vendor\bin\phpunit tests\Unit\Services\AgentCenterServiceTest.php tests\Unit\Http\AgentControllerTest.php
```

Expected: PASS.

- [ ] **Step 2: Run frontend focused tests and build**

Run in `keli-user`:

```powershell
npm test -- src/lib/agent.test.ts
npm run build
```

Expected: PASS.

- [ ] **Step 3: Inspect git status**

Run:

```powershell
git status --short
```

in both `keliboard` and `keli-user`. Expected: clean after commits.

- [ ] **Step 4: Summarize remaining follow-ups**

Mention that a polished `keli-admin` settings page is a follow-up if only `admin_setting` defaults are shipped in the MVP.
