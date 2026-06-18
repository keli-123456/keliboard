# Agent Domain Commerce Phase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first usable agent storefront loop: admin assigns domains, agents set sale prices and payment methods, users buy through an agent domain, and the platform freezes then captures agent balance before opening the subscription.

**Architecture:** Add a separate agent commerce layer beside the existing Agent Center. Domain resolution, storefront pricing, agent payment ownership, order balance holds, and callback capture live in focused backend services. Existing order, payment, registration, store, and purchase flows call those services only at well-defined boundaries.

**Tech Stack:** Laravel/PHP backend in `keliboard`, MySQL-compatible migrations, PHPUnit unit tests, React/Vite/TypeScript user frontend in `keli-user`, React/Vite/TypeScript admin frontend in `keli-admin`.

---

## File Structure

### `keliboard`

- Create: `database/migrations/2026_06_18_000001_create_agent_commerce_tables.php`
  - Adds `v2_agent_domain`, `v2_agent_plan_price`, `v2_agent_balance_hold`, `v2_agent_order_context`.
  - Extends `v2_payment` with `owner_type`, `owner_id`, `owner_domain_id`.
- Create: `app/Models/AgentDomain.php`
  - Owns assigned domain rows.
- Create: `app/Models/AgentPlanPrice.php`
  - Owns agent sale prices in cents.
- Create: `app/Models/AgentBalanceHold.php`
  - Owns pending/captured/released balance holds.
- Create: `app/Models/AgentOrderContext.php`
  - Owns immutable order commerce snapshots.
- Modify: `app/Models/Payment.php`
  - Adds owner constants and casts for agent-owned payment rows.
- Create: `app/Services/AgentDomainResolver.php`
  - Resolves request host to an active agent domain. Phase 1 uses the HTTP `Host` header as the source of truth; reverse proxies must preserve it.
- Create: `app/Services/AgentStorefrontService.php`
  - Returns agent-priced plans and validates agent sale price availability.
- Create: `app/Services/AgentPaymentService.php`
  - Provides agent payment method CRUD and plugin validation.
- Create: `app/Services/AgentCommerceService.php`
  - Creates agent orders, freezes balance, captures/release holds, writes agent ledgers.
- Modify: `app/Services/Auth/RegisterService.php`
  - Binds new registrations to the resolved agent domain.
- Modify: `app/Services/OrderService.php`
  - Exposes safe hooks for agent order context during order creation and payment completion.
- Modify: `app/Http/Controllers/V1/User/OrderController.php`
  - Routes agent-domain order creation, payment method filtering, and checkout ownership checks.
- Modify: `app/Http/Controllers/V1/Guest/PaymentController.php`
  - Captures agent balance hold before completing an agent order.
- Modify: `app/Http/Controllers/V1/User/PlanController.php`
  - Returns agent storefront prices under agent domains.
- Modify: `app/Http/Controllers/V1/Guest/PlanController.php`
  - Returns agent storefront prices for public landing/store data under agent domains.
- Create: `app/Http/Controllers/V1/User/AgentCommerceController.php`
  - Agent-facing domains, payments, prices, and commerce summary APIs.
- Create: `app/Http/Controllers/V2/Admin/AgentCommerceController.php`
  - Admin-facing domain assignment and commerce oversight APIs.
- Modify: `app/Http/Routes/V1/UserRoute.php`
  - Adds `/agent/domains`, `/agent/payments`, `/agent/prices`, `/agent/commerce/summary`.
- Modify: `app/Http/Routes/V2/AdminRoute.php`
  - Adds `/agent-commerce/*`.
- Test: `tests/Unit/Services/AgentDomainResolverTest.php`
- Test: `tests/Unit/Services/AgentPaymentServiceTest.php`
- Test: `tests/Unit/Services/AgentStorefrontServiceTest.php`
- Test: `tests/Unit/Services/AgentCommerceServiceTest.php`
- Test: `tests/Unit/Http/AgentDomainOrderFlowTest.php`
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php`
  - Adds in-memory tables/columns for new tests.

### `keli-user`

- Create: `src/services/agentCommerce.ts`
  - Agent domains, payment methods, price settings, and commerce summary API wrapper.
- Create: `src/lib/agentReverseProxy.ts`
  - Generates copyable Nginx reverse proxy snippets that preserve `Host`.
- Modify: `src/services/plan.ts`
  - Accepts agent price metadata from backend responses.
- Modify: `src/services/order.ts`
  - Accepts agent-domain payment method responses and customer-safe insufficient balance errors.
- Modify: `src/services/user.ts`
  - Keeps normal register request shape; backend handles host attribution.
- Modify: `src/pages/AgentCenterPage.tsx`
  - Adds tabs for domains, storefront prices, and agent payment methods. The domains tab shows an Nginx reverse proxy example with `proxy_set_header Host $host;`.
- Modify: `src/pages/StorePage.tsx`
  - Shows agent sale prices under agent domain context.
- Modify: `src/pages/PurchasePage.tsx`
  - Lists only agent-owned payment methods for agent orders and handles unavailable states.
- Modify: `src/pages/RegisterPage.tsx`
  - Shows no special agent wording in phase 1; registration remains clean.
- Modify: `src/locales/zh/translation.json`
- Modify: `src/locales/en/translation.json`
- Test: `src/lib/agentCommerce.test.ts`

### `keli-admin`

- Create: `src/services/agentCommerce.ts`
  - Admin API wrapper for domain assignment, agent payments, holds, and order contexts.
- Create: `src/pages/agent/AgentCommercePage.tsx`
  - Admin domain assignment and commerce oversight page.
- Modify: `src/App.tsx`
  - Adds route `/dashboard/agent/commerce`.
- Modify: `src/locales/zh/translation.json`
- Modify: `src/locales/en/translation.json`

---

## Task 1: Backend Data Model

**Files:**
- Create: `keliboard/database/migrations/2026_06_18_000001_create_agent_commerce_tables.php`
- Create: `keliboard/app/Models/AgentDomain.php`
- Create: `keliboard/app/Models/AgentPlanPrice.php`
- Create: `keliboard/app/Models/AgentBalanceHold.php`
- Create: `keliboard/app/Models/AgentOrderContext.php`
- Modify: `keliboard/app/Models/Payment.php`
- Modify: `keliboard/tests/Support/InteractsWithInMemoryDatabase.php`

- [ ] **Step 1: Write the migration**

Create the migration with these exact table contracts:

```php
Schema::create('v2_agent_domain', function (Blueprint $table) {
    $table->integer('id', true);
    $table->integer('agent_user_id');
    $table->string('domain', 255)->unique();
    $table->string('status', 20)->default('active');
    $table->boolean('is_primary')->default(false);
    $table->string('remark', 255)->nullable();
    $table->integer('created_by_admin_id')->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
    $table->index(['agent_user_id', 'status']);
});

Schema::create('v2_agent_plan_price', function (Blueprint $table) {
    $table->integer('id', true);
    $table->integer('agent_user_id');
    $table->integer('plan_id');
    $table->string('period', 32);
    $table->integer('sale_price');
    $table->boolean('enabled')->default(true);
    $table->integer('created_at');
    $table->integer('updated_at');
    $table->unique(['agent_user_id', 'plan_id', 'period'], 'uniq_agent_plan_period');
});

Schema::create('v2_agent_balance_hold', function (Blueprint $table) {
    $table->integer('id', true);
    $table->integer('agent_user_id');
    $table->integer('order_id');
    $table->string('trade_no', 64);
    $table->integer('amount');
    $table->string('status', 20)->default('pending');
    $table->integer('expires_at')->nullable();
    $table->integer('captured_at')->nullable();
    $table->integer('released_at')->nullable();
    $table->json('metadata')->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
    $table->unique('order_id');
    $table->unique('trade_no');
    $table->index(['agent_user_id', 'status']);
});

Schema::create('v2_agent_order_context', function (Blueprint $table) {
    $table->integer('id', true);
    $table->integer('order_id');
    $table->string('trade_no', 64)->unique();
    $table->integer('agent_user_id');
    $table->integer('agent_domain_id')->nullable();
    $table->integer('payment_id')->nullable();
    $table->integer('sale_amount');
    $table->integer('cost_amount');
    $table->integer('hold_id')->nullable();
    $table->string('status', 20)->default('pending');
    $table->json('pricing_snapshot')->nullable();
    $table->json('domain_snapshot')->nullable();
    $table->json('payment_snapshot')->nullable();
    $table->integer('created_at');
    $table->integer('updated_at');
    $table->unique('order_id');
    $table->index(['agent_user_id', 'status']);
});

Schema::table('v2_payment', function (Blueprint $table) {
    $table->string('owner_type', 20)->default('platform')->after('id');
    $table->integer('owner_id')->nullable()->after('owner_type');
    $table->integer('owner_domain_id')->nullable()->after('owner_id');
    $table->index(['owner_type', 'owner_id']);
});
```

- [ ] **Step 2: Add model constants and casts**

Use these model constants:

```php
final class AgentDomain extends Model
{
    protected $table = 'v2_agent_domain';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
}

final class AgentBalanceHold extends Model
{
    protected $table = 'v2_agent_balance_hold';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CAPTURED = 'captured';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    protected $casts = ['metadata' => 'array'];
}

final class AgentOrderContext extends Model
{
    protected $table = 'v2_agent_order_context';
    protected $guarded = ['id'];
    protected $dateFormat = 'U';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $casts = [
        'pricing_snapshot' => 'array',
        'domain_snapshot' => 'array',
        'payment_snapshot' => 'array',
    ];
}
```

Add to `Payment`:

```php
public const OWNER_PLATFORM = 'platform';
public const OWNER_AGENT = 'agent';
```

- [ ] **Step 3: Run migration-related tests**

Run:

```powershell
composer test -- --filter Agent
```

Expected: tests fail at first because services are not implemented yet, but database bootstrapping should not fail with missing table or column errors.

- [ ] **Step 4: Commit**

```powershell
git add database/migrations/2026_06_18_000001_create_agent_commerce_tables.php app/Models/AgentDomain.php app/Models/AgentPlanPrice.php app/Models/AgentBalanceHold.php app/Models/AgentOrderContext.php app/Models/Payment.php tests/Support/InteractsWithInMemoryDatabase.php
git commit -m "Add agent commerce data model"
```

---

## Task 2: Domain Resolver And Admin Domain APIs

**Files:**
- Create: `keliboard/app/Services/AgentDomainResolver.php`
- Create: `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
- Modify: `keliboard/app/Http/Routes/V2/AdminRoute.php`
- Test: `keliboard/tests/Unit/Services/AgentDomainResolverTest.php`

- [ ] **Step 1: Write resolver tests**

Test these cases:

```php
public function test_resolves_active_domain_ignoring_port_and_case(): void
{
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop.example.com',
        'status' => AgentDomain::STATUS_ACTIVE,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $context = app(AgentDomainResolver::class)->resolveHost('SHOP.EXAMPLE.COM:443');

    $this->assertSame($agent->id, $context['agent_user_id']);
    $this->assertSame('shop.example.com', $context['domain']);
}

public function test_disabled_domain_does_not_resolve(): void
{
    $agent = $this->createActiveAgent('agent@example.test', 5000);
    AgentDomain::query()->create([
        'agent_user_id' => $agent->id,
        'domain' => 'shop.example.com',
        'status' => AgentDomain::STATUS_DISABLED,
        'created_at' => time(),
        'updated_at' => time(),
    ]);

    $this->assertNull(app(AgentDomainResolver::class)->resolveHost('shop.example.com'));
}
```

- [ ] **Step 2: Implement resolver**

Expose these methods:

```php
public function resolveRequest(Request $request): ?array
{
    return $this->resolveHost((string) $request->headers->get('host', ''));
}

public function resolveHost(string $host): ?array
{
    $domain = $this->normalizeHost($host);
    if ($domain === '') {
        return null;
    }

    $row = AgentDomain::query()
        ->where('domain', $domain)
        ->where('status', AgentDomain::STATUS_ACTIVE)
        ->first();

    if (!$row) {
        return null;
    }

    return [
        'agent_user_id' => (int) $row->agent_user_id,
        'agent_domain_id' => (int) $row->id,
        'domain' => $row->domain,
        'is_primary' => (bool) $row->is_primary,
    ];
}
```

Do not trust arbitrary `X-Forwarded-Host` in phase 1. If a future deployment needs that fallback, add a separate trusted-proxy setting first so public clients cannot spoof an agent domain by sending their own forwarded host header.

- [ ] **Step 3: Add admin domain APIs**

In `AgentCommerceController`, implement:

```php
public function domains(Request $request)
public function saveDomain(Request $request)
public function enableDomain(Request $request, int $id)
public function disableDomain(Request $request, int $id)
public function deleteDomain(Request $request, int $id)
```

Validation:

```php
'agent_user_id' => 'required|integer|exists:v2_user,id',
'domain' => 'required|string|max:255',
'remark' => 'nullable|string|max:255',
'is_primary' => 'boolean',
```

Normalize domains with the same resolver host normalization before saving.

- [ ] **Step 4: Add admin routes**

Under admin routes:

```php
$router->group(['prefix' => 'agent-commerce'], function ($router) {
    $router->get('/domains', [AgentCommerceController::class, 'domains']);
    $router->post('/domains', [AgentCommerceController::class, 'saveDomain']);
    $router->post('/domains/{id}/enable', [AgentCommerceController::class, 'enableDomain']);
    $router->post('/domains/{id}/disable', [AgentCommerceController::class, 'disableDomain']);
    $router->post('/domains/{id}/delete', [AgentCommerceController::class, 'deleteDomain']);
});
```

- [ ] **Step 5: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentDomainResolverTest
```

Expected: all resolver tests pass.

Commit:

```powershell
git add app/Services/AgentDomainResolver.php app/Http/Controllers/V2/Admin/AgentCommerceController.php app/Http/Routes/V2/AdminRoute.php tests/Unit/Services/AgentDomainResolverTest.php
git commit -m "Add agent domain resolution"
```

---

## Task 3: Registration Attribution

**Files:**
- Modify: `keliboard/app/Services/Auth/RegisterService.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Write registration attribution test**

The test should call the existing register route with host `agent.example.test` and assert:

```php
$this->assertDatabaseHas('v2_agent_user', [
    'agent_user_id' => $agent->id,
    'sub_user_id' => $registeredUser->id,
]);
$this->assertSame($agent->id, (int) $registeredUser->fresh()->invite_user_id);
```

- [ ] **Step 2: Implement attribution**

At the end of successful registration, resolve the request host. If an active agent domain exists and no existing agent ownership exists for the new user, create `v2_agent_user` and set `invite_user_id` to the agent id.

Use a single transaction around user creation and agent ownership creation so a partially registered user is not left behind.

- [ ] **Step 3: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentDomainOrderFlowTest
```

Expected: registration attribution test passes.

Commit:

```powershell
git add app/Services/Auth/RegisterService.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "Bind registrations from agent domains"
```

---

## Task 4: Agent Payment Methods

**Files:**
- Create: `keliboard/app/Services/AgentPaymentService.php`
- Create: `keliboard/app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `keliboard/app/Http/Routes/V1/UserRoute.php`
- Test: `keliboard/tests/Unit/Services/AgentPaymentServiceTest.php`

- [ ] **Step 1: Write agent payment tests**

Cover:

```php
public function test_agent_can_create_payment_for_enabled_plugin(): void
public function test_agent_cannot_edit_another_agent_payment(): void
public function test_agent_payment_requires_enabled_platform_plugin(): void
public function test_agent_payment_notify_url_uses_payment_uuid(): void
```

- [ ] **Step 2: Implement service API**

Expose:

```php
public function availableMethods(): array
public function list(User $agent): array
public function form(User $agent, string $payment, ?int $paymentId = null): array
public function save(User $agent, array $payload): Payment
public function toggle(User $agent, int $id): Payment
public function delete(User $agent, int $id): bool
public function assertOwnedByAgent(Payment $payment, int $agentUserId): void
```

Create payment rows with:

```php
'owner_type' => Payment::OWNER_AGENT,
'owner_id' => $agent->id,
'owner_domain_id' => $payload['owner_domain_id'] ?? null,
```

- [ ] **Step 3: Add user agent payment APIs**

In `AgentCommerceController`, implement:

```php
public function domains(Request $request)
public function availablePaymentMethods(Request $request)
public function payments(Request $request)
public function paymentForm(Request $request)
public function savePayment(Request $request)
public function togglePayment(Request $request, int $id)
public function deletePayment(Request $request, int $id)
```

- [ ] **Step 4: Add routes**

```php
$router->get('/agent/domains', [AgentCommerceController::class, 'domains']);
$router->get('/agent/payment-methods/available', [AgentCommerceController::class, 'availablePaymentMethods']);
$router->get('/agent/payments', [AgentCommerceController::class, 'payments']);
$router->post('/agent/payments/form', [AgentCommerceController::class, 'paymentForm']);
$router->post('/agent/payments', [AgentCommerceController::class, 'savePayment']);
$router->post('/agent/payments/{id}', [AgentCommerceController::class, 'savePayment']);
$router->post('/agent/payments/{id}/toggle', [AgentCommerceController::class, 'togglePayment']);
$router->post('/agent/payments/{id}/delete', [AgentCommerceController::class, 'deletePayment']);
```

- [ ] **Step 5: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentPaymentServiceTest
```

Expected: all agent payment tests pass.

Commit:

```powershell
git add app/Services/AgentPaymentService.php app/Http/Controllers/V1/User/AgentCommerceController.php app/Http/Routes/V1/UserRoute.php tests/Unit/Services/AgentPaymentServiceTest.php
git commit -m "Add agent payment methods"
```

---

## Task 5: Agent Storefront Prices

**Files:**
- Create: `keliboard/app/Services/AgentStorefrontService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/AgentCommerceController.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/PlanController.php`
- Modify: `keliboard/app/Http/Controllers/V1/Guest/PlanController.php`
- Test: `keliboard/tests/Unit/Services/AgentStorefrontServiceTest.php`

- [ ] **Step 1: Write storefront pricing tests**

Cover:

```php
public function test_agent_price_is_returned_for_enabled_agent_period(): void
public function test_unpriced_agent_period_is_hidden(): void
public function test_price_save_rejects_plan_not_allowed_for_agents(): void
public function test_price_save_rejects_period_missing_on_plan(): void
```

- [ ] **Step 2: Implement price service**

Expose:

```php
public function listPrices(User $agent): array
public function savePrices(User $agent, array $items): array
public function plansForRequest(Request $request, array $platformPlans): array
public function resolveSalePrice(int $agentUserId, int $planId, string $period): array
```

`resolveSalePrice` returns:

```php
[
    'plan_id' => $planId,
    'period' => $period,
    'sale_amount' => $price->sale_price,
    'pricing_snapshot' => [
        'agent_plan_price_id' => $price->id,
        'sale_price' => $price->sale_price,
        'period' => $period,
    ],
]
```

- [ ] **Step 3: Add price APIs**

In `AgentCommerceController`, implement:

```php
public function prices(Request $request)
public function savePrices(Request $request)
public function commerceSummary(Request $request)
```

- [ ] **Step 4: Adapt plan controllers**

If `AgentDomainResolver::resolveRequest($request)` returns context, map plans through `AgentStorefrontService::plansForRequest`. If no context exists, keep existing platform plan response unchanged.

- [ ] **Step 5: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentStorefrontServiceTest
```

Expected: all storefront pricing tests pass.

Commit:

```powershell
git add app/Services/AgentStorefrontService.php app/Http/Controllers/V1/User/AgentCommerceController.php app/Http/Controllers/V1/User/PlanController.php app/Http/Controllers/V1/Guest/PlanController.php tests/Unit/Services/AgentStorefrontServiceTest.php
git commit -m "Add agent storefront prices"
```

---

## Task 6: Agent Order Creation And Balance Holds

**Files:**
- Create: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/OrderController.php`
- Modify: `keliboard/app/Services/OrderService.php`
- Test: `keliboard/tests/Unit/Services/AgentCommerceServiceTest.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Write order creation tests**

Cover:

```php
public function test_agent_order_creation_fails_when_available_balance_is_insufficient(): void
public function test_agent_order_creation_creates_order_hold_and_context(): void
public function test_pending_holds_reduce_available_agent_balance(): void
public function test_existing_owned_user_is_not_reassigned_by_another_agent_domain(): void
```

- [ ] **Step 2: Implement available balance calculation**

```php
public function availableBalance(User $agent): int
{
    $pending = AgentBalanceHold::query()
        ->where('agent_user_id', $agent->id)
        ->where('status', AgentBalanceHold::STATUS_PENDING)
        ->sum('amount');

    return max(0, (int) $agent->balance - (int) $pending);
}
```

- [ ] **Step 3: Implement agent order creation**

Expose:

```php
public function createOrderFromRequest(User $user, Plan $plan, string $period, ?string $couponCode, Request $request): ?Order
public function calculatePlatformCost(User $agent, Plan $plan, string $period): array
```

Return `null` when the request is not under an agent domain so existing order flow continues.

Inside the agent branch:

```php
$context = app(AgentDomainResolver::class)->resolveRequest($request);
$sale = app(AgentStorefrontService::class)->resolveSalePrice($context['agent_user_id'], $plan->id, $period);
$cost = $this->calculatePlatformCost($agent, $plan, $period)['amount'];
```

`calculatePlatformCost` must not require the buyer to already be a subordinate because first purchases through an agent domain can create the ownership. It should reuse the current agent deduction rule: platform plan period price in cents multiplied by `agent_center_discount_percent`. Bonus-day cost is not part of phase 1 storefront orders.

Then transaction:

1. Lock agent user.
2. Check available balance.
3. Create normal order with `total_amount = sale_amount`.
4. Create pending hold with `amount = cost`.
5. Create order context snapshot.
6. Bind user to agent if no ownership exists.

- [ ] **Step 4: Wire `OrderController::save`**

Before calling normal `OrderService::createFromRequest`, call `AgentCommerceService::createOrderFromRequest`. If it returns an order, return that trade number.

- [ ] **Step 5: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentCommerceServiceTest
composer test -- --filter AgentDomainOrderFlowTest
```

Expected: insufficient balance produces no order; sufficient balance creates order, context, and hold.

Commit:

```powershell
git add app/Services/AgentCommerceService.php app/Http/Controllers/V1/User/OrderController.php app/Services/OrderService.php tests/Unit/Services/AgentCommerceServiceTest.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "Create agent orders with balance holds"
```

---

## Task 7: Checkout Ownership And Payment Callback Capture

**Files:**
- Modify: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/OrderController.php`
- Modify: `keliboard/app/Http/Controllers/V1/Guest/PaymentController.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Write checkout and callback tests**

Cover:

```php
public function test_agent_checkout_rejects_platform_payment_method(): void
public function test_agent_checkout_accepts_owned_agent_payment_method(): void
public function test_payment_callback_captures_hold_and_deducts_agent_once(): void
public function test_duplicate_payment_callback_does_not_double_deduct_agent_balance(): void
```

- [ ] **Step 2: Filter payment methods**

In `OrderController::getPaymentMethod`, if the current request or current pending order has agent context, return only:

```php
Payment::query()
    ->where('owner_type', Payment::OWNER_AGENT)
    ->where('owner_id', $agentUserId)
    ->where('enable', true)
    ->orderBy('sort')
    ->get();
```

Normal platform requests keep existing behavior and return platform-owned enabled methods.

- [ ] **Step 3: Enforce checkout payment ownership**

Before constructing `PaymentService`, if the order has `AgentOrderContext`, require:

```php
$payment->owner_type === Payment::OWNER_AGENT && (int) $payment->owner_id === (int) $context->agent_user_id
```

If not, return "This payment method is unavailable."

- [ ] **Step 4: Capture hold before opening order**

Expose:

```php
public function captureForPaidOrder(Order $order, PaymentService $paymentService): void
```

It must:

1. Lock context, hold, agent user, and order.
2. Return without action if context is already paid and hold captured.
3. Fail if hold is missing or not pending.
4. Deduct hold amount from agent balance.
5. Mark hold captured.
6. Mark context paid.
7. Write `AgentCenterService::LEDGER_ASSIGN_PLAN` or new `agent_order_cost` ledger row.

Call it from the same successful payment path as `OrderService::paid`. The implementation must avoid a state where the agent balance is deducted but the subscription is not opened. If direct reuse of `OrderService::paid` makes one transaction difficult, move the agent capture call inside `OrderService::paid` after the order row is locked and before user subscription changes are saved.

- [ ] **Step 5: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentDomainOrderFlowTest
```

Expected: checkout ownership and callback capture tests pass.

Commit:

```powershell
git add app/Services/AgentCommerceService.php app/Http/Controllers/V1/User/OrderController.php app/Http/Controllers/V1/Guest/PaymentController.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "Capture agent order holds on payment"
```

---

## Task 8: Hold Release On Cancel

**Files:**
- Modify: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/OrderController.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Write cancel release test**

Create a pending agent order, cancel it through the existing cancel endpoint, and assert:

```php
$this->assertSame(AgentBalanceHold::STATUS_RELEASED, $hold->fresh()->status);
$this->assertSame(AgentOrderContext::STATUS_CANCELLED, $context->fresh()->status);
```

- [ ] **Step 2: Implement release**

Expose:

```php
public function releaseForOrder(Order $order, string $status = AgentBalanceHold::STATUS_RELEASED): void
```

It must be idempotent: if hold is not pending, do nothing.

- [ ] **Step 3: Wire cancel endpoint**

After normal order cancel succeeds, call `releaseForOrder($order)`.

- [ ] **Step 4: Run tests and commit**

Run:

```powershell
composer test -- --filter AgentDomainOrderFlowTest
```

Expected: cancel release test passes and previous agent order tests still pass.

Commit:

```powershell
git add app/Services/AgentCommerceService.php app/Http/Controllers/V1/User/OrderController.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "Release agent holds on order cancel"
```

---

## Task 9: User Frontend Agent Center Commerce UI

**Files:**
- Create: `keli-user/src/services/agentCommerce.ts`
- Create: `keli-user/src/lib/agentReverseProxy.ts`
- Modify: `keli-user/src/pages/AgentCenterPage.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`
- Test: `keli-user/src/lib/agentCommerce.test.ts`

- [ ] **Step 1: Add service wrapper**

Create functions:

```ts
export const agentCommerceService = {
  domains: () => api.get('/user/agent/domains'),
  availablePaymentMethods: () => api.get('/user/agent/payment-methods/available'),
  payments: () => api.get('/user/agent/payments'),
  paymentForm: (payload: { payment: string; id?: number }) => api.post('/user/agent/payments/form', payload),
  savePayment: (payload: Record<string, unknown>, id?: number) =>
    api.post(id ? `/user/agent/payments/${id}` : '/user/agent/payments', payload),
  togglePayment: (id: number) => api.post(`/user/agent/payments/${id}/toggle`),
  deletePayment: (id: number) => api.post(`/user/agent/payments/${id}/delete`),
  prices: () => api.get('/user/agent/prices'),
  savePrices: (items: unknown[]) => api.post('/user/agent/prices', { items }),
  summary: () => api.get('/user/agent/commerce/summary'),
};
```

- [ ] **Step 2: Add Agent Center tabs**

Add tabs:

```tsx
<TabsTrigger value="domains">{t('agentCommerce.domains')}</TabsTrigger>
<TabsTrigger value="prices">{t('agentCommerce.prices')}</TabsTrigger>
<TabsTrigger value="payments">{t('agentCommerce.payments')}</TabsTrigger>
```

Keep existing subordinate user management unchanged.

- [ ] **Step 3: Add reverse proxy helper**

Create `src/lib/agentReverseProxy.ts`:

```ts
export const buildAgentNginxProxySnippet = (domain: string, targetOrigin: string) => `server {
    listen 80;
    server_name ${domain};

    location / {
        proxy_pass ${targetOrigin};
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}`;
```

Show the snippet in the domains tab with a copy button. The explanatory copy should say that the platform identifies the agent by the original `Host`, so `proxy_set_header Host $host;` must remain unchanged.

- [ ] **Step 4: Build payment form dialog**

Use backend form schema returned by `paymentForm`. Render string, password, select, and textarea fields. Save values back through `savePayment`.

Mask existing secret values as `********` and only send a secret field when the agent typed a non-empty replacement.

- [ ] **Step 5: Build price editor**

List allowed plans and periods. Store agent sale price in yuan in the input, convert to cents before saving:

```ts
const cents = Math.round(Number(value || 0) * 100);
```

Disable saving when `cents < 0`.

- [ ] **Step 6: Run tests and build**

Run:

```powershell
npm run test -- agentCommerce
npm run build
```

Expected: tests and production build pass.

- [ ] **Step 7: Commit**

```powershell
git add src/services/agentCommerce.ts src/lib/agentReverseProxy.ts src/pages/AgentCenterPage.tsx src/locales/zh/translation.json src/locales/en/translation.json src/lib/agentCommerce.test.ts
git commit -m "Add agent commerce controls"
```

---

## Task 10: User Store And Purchase Agent Domain Flow

**Files:**
- Modify: `keli-user/src/services/plan.ts`
- Modify: `keli-user/src/services/order.ts`
- Modify: `keli-user/src/pages/StorePage.tsx`
- Modify: `keli-user/src/pages/PurchasePage.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`

- [ ] **Step 1: Update plan display types**

Add optional metadata:

```ts
agent_context?: {
  agent_user_id: number;
  domain: string;
};
agent_sale_price?: number;
agent_sale_periods?: Record<string, number>;
```

- [ ] **Step 2: Store page uses backend prices**

Do not calculate agent sale prices in the browser. Display whatever backend returns for available periods. If a period is absent, hide that period.

- [ ] **Step 3: Purchase page handles agent payment methods**

When `getPaymentMethods` returns an empty list for an agent order, show:

```ts
t('purchase.agentPaymentUnavailable')
```

Do not allow checkout without a payment method.

- [ ] **Step 4: Customer-safe insufficient balance error**

Map backend message "The site balance is insufficient. Please contact site support." to:

```json
"agentSiteBalanceInsufficient": "站点余额不足，请联系网站客服。"
```

- [ ] **Step 5: Run build and commit**

Run:

```powershell
npm run build
```

Expected: build passes.

Commit:

```powershell
git add src/services/plan.ts src/services/order.ts src/pages/StorePage.tsx src/pages/PurchasePage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Use agent storefront prices in purchase flow"
```

---

## Task 11: Admin Commerce Oversight UI

**Files:**
- Create: `keli-admin/src/services/agentCommerce.ts`
- Create: `keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Modify: `keli-admin/src/App.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Add admin service wrapper**

Create:

```ts
export const agentCommerceService = {
  domains: () => api.get('/agent-commerce/domains'),
  saveDomain: (payload: Record<string, unknown>) => api.post('/agent-commerce/domains', payload),
  enableDomain: (id: number) => api.post(`/agent-commerce/domains/${id}/enable`),
  disableDomain: (id: number) => api.post(`/agent-commerce/domains/${id}/disable`),
  deleteDomain: (id: number) => api.post(`/agent-commerce/domains/${id}/delete`),
  orders: () => api.get('/agent-commerce/orders'),
  holds: () => api.get('/agent-commerce/holds'),
  payments: () => api.get('/agent-commerce/payments'),
};
```

- [ ] **Step 2: Build admin page**

Sections:

1. Domain assignment table.
2. Add/edit domain dialog with agent user id, domain, primary flag, remark.
3. Agent payments table, read-only with disable action.
4. Pending holds table.
5. Agent order contexts table.

- [ ] **Step 3: Add route**

In `App.tsx`:

```tsx
const AgentCommercePage = lazy(() => import('@/pages/agent/AgentCommercePage'));
<Route path="agent/commerce" element={<AgentCommercePage />} />
```

- [ ] **Step 4: Run build and commit**

Run:

```powershell
npm run build
```

Expected: build passes.

Commit:

```powershell
git add src/services/agentCommerce.ts src/pages/agent/AgentCommercePage.tsx src/App.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "Add agent commerce admin page"
```

---

## Task 12: End-To-End Verification And Theme Package

**Files:**
- Modify only if verification finds defects.

- [ ] **Step 1: Backend regression**

Run:

```powershell
composer test -- --filter Agent
composer test -- --filter Order
composer test -- --filter Payment
```

Expected: all selected backend tests pass.

- [ ] **Step 2: Frontend builds**

Run:

```powershell
cd C:\Users\Administrator\Documents\keli\keli-user
npm run build
npm run package
cd C:\Users\Administrator\Documents\keli\keli-admin
npm run build
```

Expected: both builds pass and `keli-user` produces a fresh theme zip.

- [ ] **Step 3: Manual browser verification**

Use local dev mode to verify:

1. Admin binds `agent.local.test` to an agent.
2. Agent sees assigned domain in Agent Center.
3. Agent creates one payment method using an enabled plugin.
4. Agent sets one plan period sale price.
5. New user registers through `agent.local.test`.
6. User appears under that agent.
7. Store page shows agent sale price.
8. Purchase page shows only agent-owned payment method.
9. Insufficient agent balance prevents order creation.
10. Sufficient agent balance creates order and hold.
11. Payment success captures hold, deducts platform cost, and opens subscription.
12. Duplicate payment callback does not double deduct.

- [ ] **Step 4: Push all repos**

For each changed repo:

```powershell
git status --short
git push
```

Expected: only intentional changes are present and pushed.

---

## Self-Review Checklist

- Spec coverage:
  - Domain assignment: Task 2 and Task 11.
  - Host attribution: Task 2 and Task 3.
  - Agent payment methods: Task 4 and Task 9.
  - Agent storefront prices: Task 5 and Task 10.
  - Balance holds: Task 6, Task 7, Task 8.
  - Payment callback capture: Task 7.
  - User storefront adaptation: Task 10.
  - Admin oversight: Task 11.
- Placeholder scan:
  - No unresolved placeholder markers.
  - No deferred implementation sections.
  - Each task has concrete files, commands, and expected behavior.
- Type consistency:
  - `agent_user_id`, `agent_domain_id`, `sale_amount`, `cost_amount`, and `hold_id` are used consistently across hold/context/service tasks.
  - Payment ownership uses `owner_type`, `owner_id`, and `owner_domain_id`.
  - Money values are cents in backend and converted from yuan only in frontend form inputs.
