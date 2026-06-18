# Agent Domain Commerce Phase 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every user already bound to an agent buy through that agent's storefront prices, payment methods, and balance guard on any domain.

**Architecture:** Add a single backend context resolver that prioritizes `v2_agent_user` ownership over agent-domain ownership. Rewire plan listing, order creation, and payment method listing to use this resolver, then surface the context source in admin monitoring. Frontend changes are intentionally small because backend returned data remains the source of truth.

**Tech Stack:** Laravel PHP services/controllers/resources, PHPUnit in-memory database tests, React TypeScript for `keli-user` and `keli-admin`, Vitest for helper tests, Vite builds.

---

## Files And Responsibilities

- Create `keliboard/app/Services/AgentCommerceContextResolver.php`: resolves agent commerce context from authenticated user binding first, then current request domain.
- Modify `keliboard/app/Services/AgentCommerceService.php`: use the unified context for order creation and payment method listing; store `source` snapshots.
- Modify `keliboard/app/Services/AgentStorefrontService.php`: use the unified context for plan listing and expose `agent_context.source`.
- Modify `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`: include order source in admin order rows.
- Modify `keliboard/tests/Unit/Services/AgentCommerceServiceTest.php`: add sticky binding order and payment-method coverage.
- Create `keliboard/tests/Unit/Services/AgentCommerceContextResolverTest.php`: focused resolver priority coverage.
- Modify `keli-user/src/services/plan.ts`: add `source` to `agent_context` type.
- Modify `keli-admin/src/services/agentCommerce.ts`: add `source` to `AdminAgentOrderContext`.
- Modify `keli-admin/src/pages/agent/agentCommerceDisplay.ts`: add a source-label helper.
- Modify `keli-admin/src/pages/agent/agentCommerceDisplay.test.ts`: cover source-label helper.
- Modify `keli-admin/src/pages/agent/AgentCommercePage.tsx`: show the source next to domain in order context rows.
- Modify `keli-admin/src/locales/zh/translation.json` and `keli-admin/src/locales/en/translation.json`: add source labels.

---

## Task 1: Backend Context Resolver

**Files:**
- Create: `keliboard/app/Services/AgentCommerceContextResolver.php`
- Create: `keliboard/tests/Unit/Services/AgentCommerceContextResolverTest.php`

- [ ] **Step 1: Write resolver priority tests**

Create `tests/Unit/Services/AgentCommerceContextResolverTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentUser;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\AgentCommerceContextResolver;
use Illuminate\Http\Request;
use Tests\Support\InteractsWithInMemoryDatabase;
use Tests\TestCase;

final class AgentCommerceContextResolverTest extends TestCase
{
    use InteractsWithInMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInMemoryDatabase();
        $this->createUserTable();
        $this->createAgentCenterTables();
        $this->createAgentCommerceTables();
    }

    public function test_user_binding_takes_priority_over_current_domain(): void
    {
        $firstAgent = $this->createActiveAgent('first-agent@example.test');
        $secondAgent = $this->createActiveAgent('second-agent@example.test');
        $buyer = $this->createUser('buyer@example.test');
        $domain = $this->assignDomain($secondAgent, 'second.example.test');
        AgentUser::query()->create([
            'agent_user_id' => $firstAgent->id,
            'sub_user_id' => $buyer->id,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('second.example.test', $buyer)
        );

        $this->assertSame($firstAgent->id, $context['agent_user_id']);
        $this->assertNull($context['agent_domain_id']);
        $this->assertSame('', $context['domain']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_USER_BINDING, $context['source']);
        $this->assertNotSame($domain->id, $context['agent_domain_id']);
    }

    public function test_guest_request_uses_agent_domain(): void
    {
        $agent = $this->createActiveAgent('agent@example.test');
        $domain = $this->assignDomain($agent, 'agent.example.test');

        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('agent.example.test')
        );

        $this->assertSame($agent->id, $context['agent_user_id']);
        $this->assertSame($domain->id, $context['agent_domain_id']);
        $this->assertSame('agent.example.test', $context['domain']);
        $this->assertSame(AgentCommerceContextResolver::SOURCE_DOMAIN, $context['source']);
    }

    public function test_normal_request_returns_null(): void
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest(
            $this->requestForHost('platform.example.test')
        );

        $this->assertNull($context);
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

    private function assignDomain(User $agent, string $domain): AgentDomain
    {
        return AgentDomain::query()->create([
            'agent_user_id' => $agent->id,
            'domain' => $domain,
            'status' => AgentDomain::STATUS_ACTIVE,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function requestForHost(string $host, ?User $user = null): Request
    {
        $request = Request::create('https://' . $host . '/user/plan/fetch', 'GET');
        $request->headers->set('host', $host);
        if ($user) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }
}
```

- [ ] **Step 2: Run the new resolver test and verify it fails**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceContextResolverTest
```

Expected: FAIL because `App\Services\AgentCommerceContextResolver` does not exist.

- [ ] **Step 3: Implement the resolver**

Create `app/Services/AgentCommerceContextResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\AgentUser;
use App\Models\User;
use Illuminate\Http\Request;

class AgentCommerceContextResolver
{
    public const SOURCE_USER_BINDING = 'user_binding';
    public const SOURCE_DOMAIN = 'domain';

    public function resolveRequest(Request $request, ?User $user = null): ?array
    {
        $resolvedUser = $user ?: $request->user();
        if ($resolvedUser instanceof User) {
            $context = $this->resolveUser($resolvedUser);
            if ($context) {
                return $context;
            }
        }

        $domainContext = app(AgentDomainResolver::class)->resolveRequest($request);
        if (!$domainContext) {
            return null;
        }

        return array_merge($domainContext, [
            'source' => self::SOURCE_DOMAIN,
        ]);
    }

    public function resolveUser(User $user): ?array
    {
        $ownership = AgentUser::query()
            ->where('sub_user_id', $user->id)
            ->first();

        if (!$ownership) {
            return null;
        }

        return [
            'agent_user_id' => (int) $ownership->agent_user_id,
            'agent_domain_id' => null,
            'domain' => '',
            'is_primary' => false,
            'source' => self::SOURCE_USER_BINDING,
        ];
    }
}
```

- [ ] **Step 4: Run resolver tests and verify they pass**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceContextResolverTest
```

Expected: PASS.

- [ ] **Step 5: Commit backend resolver**

Commit only these files:

```bash
git add app/Services/AgentCommerceContextResolver.php tests/Unit/Services/AgentCommerceContextResolverTest.php
git commit -m "Add agent commerce context resolver"
```

---

## Task 2: Rewire Plans, Orders, And Payment Methods

**Files:**
- Modify: `keliboard/app/Services/AgentStorefrontService.php`
- Modify: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/tests/Unit/Services/AgentCommerceServiceTest.php`

- [ ] **Step 1: Add failing service tests for bound users**

Append these tests to `tests/Unit/Services/AgentCommerceServiceTest.php` before helper methods:

```php
public function test_bound_user_on_main_domain_creates_agent_order_from_user_binding(): void
{
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    $buyer = $this->createUser('buyer@example.test');
    AgentUser::query()->create([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $buyer->invite_user_id = $agent->id;
    $buyer->save();

    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
    $price = $this->setAgentPrice($agent, $plan, Plan::PERIOD_MONTHLY, 1300);

    $order = app(AgentCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('platform.example.test')
    );

    $this->assertInstanceOf(Order::class, $order);
    $this->assertSame(1300, (int) $order->total_amount);
    $this->assertSame($agent->id, (int) $order->invite_user_id);

    $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
    $this->assertNotNull($context);
    $this->assertSame($agent->id, (int) $context->agent_user_id);
    $this->assertNull($context->agent_domain_id);
    $this->assertSame('user_binding', $context->domain_snapshot['source']);
    $this->assertSame('', $context->domain_snapshot['domain']);
    $this->assertSame($price->id, (int) $context->pricing_snapshot['agent_plan_price_id']);
}

public function test_bound_user_on_main_domain_uses_agent_for_payment_methods(): void
{
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    $buyer = $this->createUser('buyer@example.test');
    AgentUser::query()->create([
        'agent_user_id' => $agent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $agentUserId = app(AgentCommerceService::class)->agentUserIdForPaymentMethods(
        $this->requestForHost('platform.example.test', $buyer)
    );

    $this->assertSame($agent->id, $agentUserId);
}

public function test_bound_user_on_another_agent_domain_keeps_original_agent(): void
{
    $firstAgent = $this->createActiveAgent('first@example.test', 5000);
    $secondAgent = $this->createActiveAgent('second@example.test', 5000);
    $this->assignDomain($secondAgent, 'second.example.test');
    $buyer = $this->createUser('buyer@example.test');
    AgentUser::query()->create([
        'agent_user_id' => $firstAgent->id,
        'sub_user_id' => $buyer->id,
        'created_at' => time(),
        'updated_at' => time(),
    ]);
    $buyer->invite_user_id = $firstAgent->id;
    $buyer->save();

    $plan = $this->createPlan('Starter', [Plan::PERIOD_MONTHLY => 10.00]);
    $this->setAgentPrice($firstAgent, $plan, Plan::PERIOD_MONTHLY, 1200);
    $this->setAgentPrice($secondAgent, $plan, Plan::PERIOD_MONTHLY, 1800);

    $order = app(AgentCommerceService::class)->createOrderFromRequest(
        $buyer,
        $plan,
        Plan::PERIOD_MONTHLY,
        null,
        $this->requestForHost('second.example.test')
    );

    $context = AgentOrderContext::query()->where('order_id', $order->id)->first();
    $this->assertSame($firstAgent->id, (int) $context->agent_user_id);
    $this->assertSame(1200, (int) $order->total_amount);
    $this->assertSame(1, AgentUser::query()->where('sub_user_id', $buyer->id)->count());
}
```

Update the existing private `requestForHost()` helper in that same file so it accepts an optional user:

```php
private function requestForHost(string $host, ?User $user = null): Request
{
    $request = Request::create('https://' . $host . '/user/order/save', 'POST');
    $request->headers->set('host', $host);
    if ($user) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}
```

- [ ] **Step 2: Run the service tests and verify the new tests fail**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceServiceTest
```

Expected: FAIL for the new bound-user cases because order creation and payment method resolution still use domain-only resolution.

- [ ] **Step 3: Rewire `AgentStorefrontService::plansForRequest()`**

In `app/Services/AgentStorefrontService.php`, replace:

```php
$context = app(AgentDomainResolver::class)->resolveRequest($request);
```

with:

```php
$context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
```

In the `agent_context` attribute, add `source`:

```php
$plan->setAttribute('agent_context', [
    'agent_user_id' => (int) $context['agent_user_id'],
    'agent_domain_id' => $context['agent_domain_id'] !== null ? (int) $context['agent_domain_id'] : null,
    'domain' => (string) ($context['domain'] ?? ''),
    'source' => (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN),
]);
```

- [ ] **Step 4: Rewire `AgentCommerceService::createOrderFromRequest()`**

In `app/Services/AgentCommerceService.php`, replace:

```php
$context = app(AgentDomainResolver::class)->resolveRequest($request);
```

with:

```php
$context = app(AgentCommerceContextResolver::class)->resolveRequest($request, $user);
```

Replace the domain snapshot creation with:

```php
$contextSource = (string) ($context['source'] ?? AgentCommerceContextResolver::SOURCE_DOMAIN);
$agentDomainId = $context['agent_domain_id'] ?? null;
$domainSnapshot = [
    'source' => $contextSource,
    'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
    'domain' => (string) ($context['domain'] ?? ''),
    'is_primary' => (bool) ($context['is_primary'] ?? false),
];
```

When creating `AgentOrderContext`, set the nullable domain id:

```php
'agent_domain_id' => $agentDomainId !== null ? (int) $agentDomainId : null,
```

Keep the ownership creation block as-is: it only creates `AgentUser` when no existing ownership row exists.

- [ ] **Step 5: Rewire `agentUserIdForPaymentMethods()`**

In `app/Services/AgentCommerceService.php`, replace:

```php
$context = app(AgentDomainResolver::class)->resolveRequest($request);
```

with:

```php
$context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
```

Return the same agent id:

```php
return $context ? (int) $context['agent_user_id'] : null;
```

- [ ] **Step 6: Run backend service tests**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceContextResolverTest
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceServiceTest
```

Expected: PASS.

- [ ] **Step 7: Commit backend purchase flow**

Commit only these files:

```bash
git add app/Services/AgentStorefrontService.php app/Services/AgentCommerceService.php tests/Unit/Services/AgentCommerceServiceTest.php
git commit -m "Apply agent commerce to bound users"
```

---

## Task 3: Admin Source Visibility

**Files:**
- Modify: `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
- Modify: `keliboard/tests/Unit/Http/AdminAgentCommerceControllerTest.php`
- Modify: `keli-admin/src/services/agentCommerce.ts`
- Modify: `keli-admin/src/pages/agent/agentCommerceDisplay.ts`
- Modify: `keli-admin/src/pages/agent/agentCommerceDisplay.test.ts`
- Modify: `keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Add backend admin test assertion**

In `tests/Unit/Http/AdminAgentCommerceControllerTest.php`, extend the order fixture to include:

```php
'domain_snapshot' => [
    'source' => 'user_binding',
    'agent_domain_id' => null,
    'domain' => '',
    'is_primary' => false,
],
```

Then assert the response row includes:

```php
$this->assertSame('user_binding', $orderRows[0]['source']);
```

- [ ] **Step 2: Run admin backend test and verify it fails**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
```

Expected: FAIL because `source` is not returned yet.

- [ ] **Step 3: Return `source` from admin orders**

In `app/Http/Controllers/V2/Admin/AgentCommerceController.php`, add this to each order context row:

```php
'source' => (string) data_get(
    $context->domain_snapshot,
    'source',
    $context->agent_domain_id ? 'domain' : 'user_binding'
),
```

- [ ] **Step 4: Add frontend source type and label helper**

In `keli-admin/src/services/agentCommerce.ts`, add:

```ts
source?: "domain" | "user_binding" | string;
```

to `AdminAgentOrderContext`.

In `keli-admin/src/pages/agent/agentCommerceDisplay.ts`, add:

```ts
export const getAgentCommerceSourceLabelKey = (source?: string | null) => {
  const normalized = String(source || "").toLowerCase();
  if (normalized === "user_binding") return "agent_commerce.source.user_binding";
  if (normalized === "domain") return "agent_commerce.source.domain";
  return "agent_commerce.source.unknown";
};
```

In `keli-admin/src/pages/agent/agentCommerceDisplay.test.ts`, add:

```ts
import { getAgentCommerceSourceLabelKey } from "./agentCommerceDisplay";

it("maps agent commerce source labels", () => {
  expect(getAgentCommerceSourceLabelKey("user_binding")).toBe("agent_commerce.source.user_binding");
  expect(getAgentCommerceSourceLabelKey("domain")).toBe("agent_commerce.source.domain");
  expect(getAgentCommerceSourceLabelKey("")).toBe("agent_commerce.source.unknown");
});
```

- [ ] **Step 5: Show source on the admin order table**

In `keli-admin/src/pages/agent/AgentCommercePage.tsx`, import `getAgentCommerceSourceLabelKey` from `agentCommerceDisplay`.

In the orders filter, include `item.source`:

```ts
[item.trade_no, item.agent_email, item.buyer_email, item.agent_domain, item.payment_name, item.status, item.hold_status, item.source]
```

In the domain cell for order rows, replace:

```tsx
<td className="px-4 py-3 align-middle">{order.agent_domain || "-"}</td>
```

with:

```tsx
<td className="px-4 py-3 align-middle">
  <div>{order.agent_domain || "-"}</div>
  <div className="text-xs text-muted-foreground">{t(getAgentCommerceSourceLabelKey(order.source))}</div>
</td>
```

- [ ] **Step 6: Add translations**

In `keli-admin/src/locales/zh/translation.json`, under `agent_commerce`, add:

```json
"source": {
  "domain": "域名来源",
  "user_binding": "用户绑定",
  "unknown": "未知来源"
}
```

In `keli-admin/src/locales/en/translation.json`, under `agent_commerce`, add:

```json
"source": {
  "domain": "Domain source",
  "user_binding": "User binding",
  "unknown": "Unknown source"
}
```

- [ ] **Step 7: Run admin backend and frontend tests**

Run:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
```

from `keliboard`.

Run:

```bash
npm run test -- agentCommerceDisplay
npm run build
```

from `keli-admin`.

Expected: all pass. Build may keep existing bundle-size or Browserslist warnings.

- [ ] **Step 8: Commit admin visibility**

Commit backend admin files in `keliboard`:

```bash
git add app/Http/Controllers/V2/Admin/AgentCommerceController.php tests/Unit/Http/AdminAgentCommerceControllerTest.php
git commit -m "Show agent commerce context source"
```

Commit frontend admin files in `keli-admin`:

```bash
git add src/services/agentCommerce.ts src/pages/agent/agentCommerceDisplay.ts src/pages/agent/agentCommerceDisplay.test.ts src/pages/agent/AgentCommercePage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Display agent commerce source"
```

---

## Task 4: User Frontend Type Alignment

**Files:**
- Modify: `keli-user/src/services/plan.ts`

- [ ] **Step 1: Update `agent_context` type**

In `keli-user/src/services/plan.ts`, change:

```ts
agent_context?: {
  agent_user_id?: number;
  agent_domain_id?: number;
  domain?: string;
};
```

to:

```ts
agent_context?: {
  agent_user_id?: number;
  agent_domain_id?: number | null;
  domain?: string;
  source?: "domain" | "user_binding" | string;
};
```

- [ ] **Step 2: Build user frontend**

Run:

```bash
npm run build
```

from `keli-user`.

Expected: PASS. Existing warnings about chunk size or browser data are acceptable.

- [ ] **Step 3: Commit user type alignment**

Commit only the type file:

```bash
git add src/services/plan.ts
git commit -m "Type agent commerce source on plans"
```

---

## Task 5: Full Verification, Remote Smoke, And Push

**Files:**
- No new files unless verification reveals defects.

- [ ] **Step 1: Check git status in all repos**

Run:

```bash
git status --short --branch
```

from each repo:

- `keliboard`
- `keli-admin`
- `keli-user`

Expected:

- `keliboard` only has planned commits or is clean.
- `keli-admin` only has planned commits or is clean.
- `keli-user` may still show unrelated untracked `design-audits/`, `dev_server.err.log`, and `dev_server.out.log`; do not add them.

- [ ] **Step 2: Run full targeted backend tests**

Run from `keliboard`:

```bash
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceContextResolverTest
./vendor/bin/phpunit --testsuite Unit --filter AgentCommerceServiceTest
./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest
```

Expected: PASS.

- [ ] **Step 3: Run frontend verification**

Run from `keli-admin`:

```bash
npm run test -- agentCommerceDisplay
npm run build
```

Run from `keli-user`:

```bash
npm run build
```

Expected: PASS.

- [ ] **Step 4: Remote backend smoke on the test machine**

Copy changed backend files to `root@165.232.158.117:/root/keliboard-test`:

```powershell
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 app/Services/AgentCommerceContextResolver.php root@165.232.158.117:/root/keliboard-test/app/Services/AgentCommerceContextResolver.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 app/Services/AgentCommerceService.php root@165.232.158.117:/root/keliboard-test/app/Services/AgentCommerceService.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 app/Services/AgentStorefrontService.php root@165.232.158.117:/root/keliboard-test/app/Services/AgentStorefrontService.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 app/Http/Controllers/V2/Admin/AgentCommerceController.php root@165.232.158.117:/root/keliboard-test/app/Http/Controllers/V2/Admin/AgentCommerceController.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 tests/Unit/Services/AgentCommerceContextResolverTest.php root@165.232.158.117:/root/keliboard-test/tests/Unit/Services/AgentCommerceContextResolverTest.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 tests/Unit/Services/AgentCommerceServiceTest.php root@165.232.158.117:/root/keliboard-test/tests/Unit/Services/AgentCommerceServiceTest.php
scp -i C:\Users\Administrator\.ssh\codex_keli_ed25519 tests/Unit/Http/AdminAgentCommerceControllerTest.php root@165.232.158.117:/root/keliboard-test/tests/Unit/Http/AdminAgentCommerceControllerTest.php
```

Then run:

```powershell
ssh -i C:\Users\Administrator\.ssh\codex_keli_ed25519 root@165.232.158.117 "cd /root/keliboard-test && ./vendor/bin/phpunit --testsuite Unit --filter AgentCommerce"
ssh -i C:\Users\Administrator\.ssh\codex_keli_ed25519 root@165.232.158.117 "cd /root/keliboard-test && ./vendor/bin/phpunit --testsuite Unit --filter AdminAgentCommerceControllerTest"
```

Expected: PASS.

- [ ] **Step 5: Push all repo branches**

Run:

```bash
git push
```

from `keliboard`, `keli-admin`, and `keli-user` if each repo has new commits.

Expected: each repo's `feature/agent-domain-commerce` branch is pushed.

- [ ] **Step 6: Final status report**

Report:

- commit hashes for each repo touched
- exact tests/builds run
- whether remote smoke passed
- any known warnings
