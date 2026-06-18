# Agent Order Balance Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce proxy-agent order balance checks at order creation, checkout, and payment callback, then expose clear user/admin visibility.

**Architecture:** Keep the existing Laravel order pipeline and agent-commerce tables. Add focused guard/failure methods to `AgentCommerceService`, call them from checkout/capture paths, and expose failure state to `keli-user` and `keli-admin`.

**Tech Stack:** Laravel 12 PHP services/controllers/models, PHPUnit unit tests, React/Vite/TypeScript for `keli-user` and `keli-admin`.

---

## File Structure

- `keliboard/app/Models/AgentBalanceHold.php`
  - Add the `failed` hold status constant.
- `keliboard/app/Services/AgentCommerceService.php`
  - Add checkout guard and failure-marking helpers.
  - Make paid capture mark context/hold failed when agent balance is insufficient.
- `keliboard/app/Http/Controllers/V1/User/OrderController.php`
  - Call checkout guard before saving payment/calling payment plugin.
- `keliboard/app/Http/Controllers/V1/Guest/PaymentController.php`
  - Let capture failures return a business failure response instead of looking like success.
- `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
  - Return failure reason and clearer source/status fields for orders/holds.
- `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`
  - Add checkout and callback failure tests.
- `keli-user/src/lib/agentCommerceErrors.ts`
  - Create user-facing error mapping for the canonical site-balance message.
- `keli-user/src/lib/agentCommerceErrors.test.ts`
  - Test error mapping.
- `keli-user/src/pages/PurchasePage.tsx`
  - Use the error mapping and show a friendly no-agent-payment-methods empty state.
- `keli-admin/src/pages/agent/AgentCommercePage.tsx`
  - Show failure reasons and clearer status/source labels.
- `keli-admin/src/lib/agentCommerceDisplay.ts`
  - Create tiny display helpers for source/status/money if the page currently formats inline.
- `keli-admin/src/lib/agentCommerceDisplay.test.ts`
  - Test the new helper if created.

## Task 1: Backend Checkout Balance Guard

**Files:**
- Modify: `keliboard/app/Models/AgentBalanceHold.php`
- Modify: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/app/Http/Controllers/V1/User/OrderController.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Add failing checkout tests**

Add these tests to `tests/Unit/Http/AgentDomainOrderFlowTest.php` after `test_agent_checkout_accepts_owned_agent_payment_method()`:

```php
public function test_agent_checkout_rejects_when_hold_is_not_pending(): void
{
    [$agent, $buyer, $order] = $this->createAgentOrderFixture();
    $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
    $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
    $hold->status = AgentBalanceHold::STATUS_RELEASED;
    $hold->save();

    $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
        'trade_no' => $order->trade_no,
        'method' => $payment->id,
    ]);
    $request->setUserResolver(static fn (): User => $buyer);
    app()->instance('request', $request);

    $response = app(OrderController::class)->checkout($request);
    $payload = $this->responsePayload($response);

    $this->assertSame('fail', $payload['status']);
    $this->assertSame('Agent balance hold is unavailable', $payload['message']);
    $this->assertNull($order->fresh()->payment_id);
}

public function test_agent_checkout_rejects_when_available_balance_no_longer_covers_hold(): void
{
    [$agent, $buyer, $order] = $this->createAgentOrderFixture();
    $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
    $agent->balance = 100;
    $agent->save();

    $request = BaseRequest::create('/api/v1/user/order/checkout', 'POST', [
        'trade_no' => $order->trade_no,
        'method' => $payment->id,
    ]);
    $request->setUserResolver(static fn (): User => $buyer);
    app()->instance('request', $request);

    $response = app(OrderController::class)->checkout($request);
    $payload = $this->responsePayload($response);

    $this->assertSame('fail', $payload['status']);
    $this->assertSame(AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE, $payload['message']);
    $this->assertNull($order->fresh()->payment_id);
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "agent_checkout_rejects"
```

Expected: tests fail because checkout does not yet validate hold state or available balance.

- [ ] **Step 3: Add the checkout guard**

In `app/Models/AgentBalanceHold.php`, add:

```php
public const STATUS_FAILED = 'failed';
```

In `app/Services/AgentCommerceService.php`, add this public method after `assertPaymentAvailableForOrder()`:

```php
public function assertCheckoutBalanceAvailable(Order $order): void
{
    $context = $this->contextForOrder($order);
    if (!$context) {
        return;
    }

    DB::transaction(function () use ($context): void {
        $lockedContext = AgentOrderContext::query()
            ->whereKey($context->id)
            ->lockForUpdate()
            ->first();
        if (!$lockedContext) {
            throw new ApiException('Agent order context is unavailable');
        }

        $hold = AgentBalanceHold::query()
            ->whereKey($lockedContext->hold_id)
            ->lockForUpdate()
            ->first();
        if (!$hold || $hold->status !== AgentBalanceHold::STATUS_PENDING) {
            throw new ApiException('Agent balance hold is unavailable');
        }

        $agent = User::query()
            ->whereKey($lockedContext->agent_user_id)
            ->lockForUpdate()
            ->first();
        if (!$agent) {
            throw new ApiException('Agent user does not exist');
        }

        $pendingOther = AgentBalanceHold::query()
            ->where('agent_user_id', $agent->id)
            ->where('status', AgentBalanceHold::STATUS_PENDING)
            ->where('id', '<>', $hold->id)
            ->sum('amount');

        if (((int) $agent->balance - (int) $pendingOther) < (int) $hold->amount) {
            throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
        }
    });
}
```

This intentionally excludes the current order's pending hold from the remaining-balance calculation. The checkout guard should verify the agent can still afford this order after all other pending holds, without counting this same order twice.

In `app/Http/Controllers/V1/User/OrderController.php`, inside `checkout()` after:

```php
$agentCommerce->assertPaymentAvailableForOrder($order, $payment);
```

add:

```php
$agentCommerce->assertCheckoutBalanceAvailable($order);
```

- [ ] **Step 4: Run focused tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "agent_checkout_rejects|agent_checkout_accepts_owned"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/AgentBalanceHold.php app/Services/AgentCommerceService.php app/Http/Controllers/V1/User/OrderController.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "fix: guard agent checkout balance"
```

## Task 2: Backend Callback Failure State

**Files:**
- Modify: `keliboard/app/Services/AgentCommerceService.php`
- Modify: `keliboard/app/Http/Controllers/V1/Guest/PaymentController.php`
- Test: `keliboard/tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] **Step 1: Add failing callback test**

Add this test after `test_duplicate_payment_callback_does_not_double_deduct_agent_balance()`:

```php
public function test_payment_callback_marks_agent_context_failed_when_agent_balance_is_insufficient(): void
{
    [$agent, , $order] = $this->createAgentOrderFixture();
    $payment = $this->createPayment(Payment::OWNER_AGENT, $agent->id);
    $order->payment_id = $payment->id;
    $order->save();
    $agent->balance = 100;
    $agent->save();

    $handled = $this->invokePaymentHandle([
        'trade_no' => $order->trade_no,
        'callback_no' => 'gateway-low-balance',
        'paid_amount' => 1300,
    ], $this->paymentServiceWithId($payment->id));

    $hold = AgentBalanceHold::query()->where('order_id', $order->id)->first();
    $context = AgentOrderContext::query()->where('order_id', $order->id)->first();

    $this->assertFalse($handled);
    $this->assertSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
    $this->assertSame(100, (int) $agent->fresh()->balance);
    $this->assertSame(AgentBalanceHold::STATUS_FAILED, $hold->fresh()->status);
    $this->assertSame(AgentOrderContext::STATUS_FAILED, $context->fresh()->status);
    $this->assertSame(
        AgentCommerceService::INSUFFICIENT_SITE_BALANCE_MESSAGE,
        $context->fresh()->payment_snapshot['failure_reason'] ?? null
    );
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "callback_marks_agent_context_failed"
```

Expected: FAIL because failed status/failure reason is not marked.

- [ ] **Step 3: Add failure marking helper**

In `app/Services/AgentCommerceService.php`, add these methods before `releaseForOrder()`:

```php
public function failForOrder(Order $order, string $reason): void
{
    if (!DB::connection()->getSchemaBuilder()->hasTable('v2_agent_order_context')) {
        return;
    }

    DB::transaction(function () use ($order, $reason): void {
        $context = AgentOrderContext::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();
        if (!$context || $context->status === AgentOrderContext::STATUS_PAID) {
            return;
        }

        $hold = $context->hold_id ? AgentBalanceHold::query()
            ->whereKey($context->hold_id)
            ->lockForUpdate()
            ->first() : null;

        $this->markAgentOrderFailed($context, $hold, $reason);
    });
}

private function markAgentOrderFailed(AgentOrderContext $context, ?AgentBalanceHold $hold, string $reason): void
{
    $now = time();
    if ($hold && $hold->status === AgentBalanceHold::STATUS_PENDING) {
        $metadata = is_array($hold->metadata) ? $hold->metadata : [];
        $metadata['failure_reason'] = $reason;
        $hold->metadata = $metadata;
        $hold->status = AgentBalanceHold::STATUS_FAILED;
        $hold->updated_at = $now;
        $hold->save();
    }

    $snapshot = is_array($context->payment_snapshot) ? $context->payment_snapshot : [];
    $snapshot['failure_reason'] = $reason;
    $context->payment_snapshot = $snapshot;
    $context->status = AgentOrderContext::STATUS_FAILED;
    $context->updated_at = $now;
    $context->save();
}
```

In `captureForPaidOrder()`, change the insufficient-balance block from:

```php
if ($before < $amount) {
    throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
}
```

to:

```php
if ($before < $amount) {
    $this->markAgentOrderFailed($context, $hold, self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
    throw new ApiException(self::INSUFFICIENT_SITE_BALANCE_MESSAGE);
}
```

Use the private helper inside `captureForPaidOrder()` because that method already holds locked context/hold rows in its transaction. Use public `failForOrder()` only from outside that transaction. Do not mark idempotent paid/captured callbacks as failed.

- [ ] **Step 4: Make payment callback return false on capture exceptions**

In `app/Http/Controllers/V1/Guest/PaymentController.php`, wrap the `paid()` call:

```php
$orderService = new OrderService($order);
try {
    if (!$orderService->paid($callbackNo)) {
        return false;
    }
} catch (\Throwable $e) {
    Log::warning('Payment notify order paid handling failed', [
        'trade_no' => $order->trade_no,
        'message' => $e->getMessage(),
    ]);
    return false;
}
```

This replaces the current direct block:

```php
$orderService = new OrderService($order);
if (!$orderService->paid($callbackNo)) {
    return false;
}
```

- [ ] **Step 5: Run callback tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php --filter "payment_callback"
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/AgentCommerceService.php app/Http/Controllers/V1/Guest/PaymentController.php tests/Unit/Http/AgentDomainOrderFlowTest.php
git commit -m "fix: mark failed agent payment captures"
```

## Task 3: Backend Admin Visibility

**Files:**
- Modify: `keliboard/app/Http/Controllers/V2/Admin/AgentCommerceController.php`
- Test: `keliboard/tests/Unit/Http/AdminAgentCommerceControllerTest.php`

- [ ] **Step 1: Add failing admin visibility test**

In `tests/Unit/Http/AdminAgentCommerceControllerTest.php`, add a test that creates one `AgentOrderContext` with `payment_snapshot['failure_reason']` and one failed hold with `metadata['failure_reason']`, then asserts the controller response includes:

```php
$this->assertSame('failed', $orders[0]['status']);
$this->assertSame('The site balance is insufficient. Please contact site support.', $orders[0]['failure_reason']);
$this->assertSame('failed', $holds[0]['status']);
$this->assertSame('The site balance is insufficient. Please contact site support.', $holds[0]['failure_reason']);
```

Use existing helpers in that test file for response payload extraction and in-memory table setup. If no helper exists, add small private helpers in the test class:

```php
private function payload($response): array
{
    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AdminAgentCommerceControllerTest.php --filter "failure_reason"
```

Expected: FAIL because failure reason is not returned.

- [ ] **Step 3: Return failure reason**

In `app/Http/Controllers/V2/Admin/AgentCommerceController.php`, for `holds()` payload, add:

```php
'failure_reason' => (string) data_get($hold->metadata, 'failure_reason', ''),
```

For `orders()` payload, add:

```php
'failure_reason' => (string) data_get($context->payment_snapshot, 'failure_reason', ''),
'source' => (string) data_get($context->domain_snapshot, 'source', ''),
```

Keep existing `domain` and payment fields.

- [ ] **Step 4: Run admin commerce tests**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AdminAgentCommerceControllerTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/V2/Admin/AgentCommerceController.php tests/Unit/Http/AdminAgentCommerceControllerTest.php
git commit -m "feat: expose agent order failure reasons"
```

## Task 4: User Frontend Error Mapping

**Files:**
- Create: `keli-user/src/lib/agentCommerceErrors.ts`
- Create: `keli-user/src/lib/agentCommerceErrors.test.ts`
- Modify: `keli-user/src/pages/PurchasePage.tsx`
- Modify: `keli-user/src/locales/zh/translation.json`
- Modify: `keli-user/src/locales/en/translation.json`

- [ ] **Step 1: Write helper test**

Create `src/lib/agentCommerceErrors.test.ts`:

```ts
import { describe, expect, it } from 'vitest';

import { AGENT_SITE_BALANCE_ERROR, getAgentCommerceErrorMessage } from './agentCommerceErrors';

describe('agent commerce errors', () => {
  it('maps the canonical site balance error', () => {
    const t = (key: string) => `translated:${key}`;
    expect(getAgentCommerceErrorMessage(AGENT_SITE_BALANCE_ERROR, t)).toBe(
      'translated:agentCommerce.siteBalanceInsufficient'
    );
  });

  it('falls back to the original message', () => {
    const t = (key: string) => `translated:${key}`;
    expect(getAgentCommerceErrorMessage('Payment method is not available', t)).toBe('Payment method is not available');
  });
});
```

- [ ] **Step 2: Run helper test to verify it fails**

Run:

```bash
npm run test -- agentCommerceErrors
```

Expected: FAIL because helper file does not exist.

- [ ] **Step 3: Implement helper**

Create `src/lib/agentCommerceErrors.ts`:

```ts
export const AGENT_SITE_BALANCE_ERROR = 'The site balance is insufficient. Please contact site support.';

export const getAgentCommerceErrorMessage = (
  message: unknown,
  t: (key: string) => string,
): string => {
  const raw = String(message || '').trim();
  if (raw === AGENT_SITE_BALANCE_ERROR) return t('agentCommerce.siteBalanceInsufficient');
  return raw;
};
```

- [ ] **Step 4: Use helper in purchase flow**

In `src/pages/PurchasePage.tsx`, import:

```ts
import { getAgentCommerceErrorMessage } from '@/lib/agentCommerceErrors';
```

Find places where checkout/order errors call `notify.error(...)` or set error text from `err?.response?.data?.message`. Wrap the raw message:

```ts
const message = err?.response?.data?.message || err?.message || t('common.requestFailed');
notify.error(getAgentCommerceErrorMessage(message, t));
```

If the page uses `toast.error`, use the same mapping with `toast.error(getAgentCommerceErrorMessage(message, t))`.

For empty payment methods, if the page currently renders a generic empty state, use:

```tsx
{paymentMethods.length === 0 ? (
  <div className="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">
    {t('agentCommerce.noPaymentMethods')}
  </div>
) : null}
```

- [ ] **Step 5: Add translations**

Add to `src/locales/zh/translation.json`:

```json
"agentCommerce": {
  "siteBalanceInsufficient": "站点余额不足，请联系站点客服。",
  "noPaymentMethods": "当前站点暂未配置可用收款方式，请联系站点客服。"
}
```

If an `agentCommerce` object already exists, merge these keys into it.

Add to `src/locales/en/translation.json`:

```json
"agentCommerce": {
  "siteBalanceInsufficient": "Site balance is insufficient. Please contact site support.",
  "noPaymentMethods": "This site has no available payment method. Please contact site support."
}
```

- [ ] **Step 6: Run tests/build**

Run:

```bash
npm run test -- agentCommerceErrors
npm run build
```

Expected: PASS; build may keep existing Vite chunk-size warnings.

- [ ] **Step 7: Commit**

```bash
git add src/lib/agentCommerceErrors.ts src/lib/agentCommerceErrors.test.ts src/pages/PurchasePage.tsx src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "feat: clarify agent storefront payment errors"
```

## Task 5: Admin Frontend Failure Display

**Files:**
- Modify: `keli-admin/src/pages/agent/AgentCommercePage.tsx`
- Optional create: `keli-admin/src/lib/agentCommerceDisplay.ts`
- Optional create: `keli-admin/src/lib/agentCommerceDisplay.test.ts`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

- [ ] **Step 1: Inspect current page formatting**

Run:

```bash
rg "failure_reason|holds|orders|agent_commerce|source|status" src/pages/agent/AgentCommercePage.tsx src/locales/zh/translation.json src/locales/en/translation.json -n
```

Expected: locate order/hold table render sections and translation keys.

- [ ] **Step 2: Add display helper if formatting is inline**

If the page already has helper functions for source/status, extend them. If not, create `src/lib/agentCommerceDisplay.ts`:

```ts
export const agentOrderSourceLabelKey = (source: unknown): string => {
  const value = String(source || '').trim();
  if (value === 'domain') return 'agent_commerce.source.domain';
  if (value === 'user_binding') return 'agent_commerce.source.user_binding';
  return 'agent_commerce.source.unknown';
};

export const agentHoldStatusTone = (status: unknown): 'success' | 'warning' | 'danger' | 'neutral' => {
  const value = String(status || '').trim();
  if (value === 'captured') return 'success';
  if (value === 'pending') return 'warning';
  if (value === 'failed') return 'danger';
  return 'neutral';
};
```

Add `src/lib/agentCommerceDisplay.test.ts`:

```ts
import { describe, expect, it } from 'vitest';
import { agentHoldStatusTone, agentOrderSourceLabelKey } from './agentCommerceDisplay';

describe('agent commerce display helpers', () => {
  it('maps order source labels', () => {
    expect(agentOrderSourceLabelKey('domain')).toBe('agent_commerce.source.domain');
    expect(agentOrderSourceLabelKey('user_binding')).toBe('agent_commerce.source.user_binding');
  });

  it('maps failed hold status to danger', () => {
    expect(agentHoldStatusTone('failed')).toBe('danger');
  });
});
```

- [ ] **Step 3: Render source and failure reason**

In `src/pages/agent/AgentCommercePage.tsx`, update the order table row to include:

```tsx
<td className="px-4 py-3">
  <StatusBadge tone="neutral">{t(agentOrderSourceLabelKey(row.source))}</StatusBadge>
</td>
```

Render failure reason near context/hold status:

```tsx
{row.failure_reason ? (
  <div className="mt-1 max-w-[260px] truncate text-xs text-rose-600" title={row.failure_reason}>
    {row.failure_reason}
  </div>
) : null}
```

For hold rows, use:

```tsx
<StatusBadge tone={agentHoldStatusTone(row.status)}>{t(`agent_commerce.hold_status.${row.status}`, { defaultValue: row.status })}</StatusBadge>
{row.failure_reason ? (
  <div className="mt-1 max-w-[260px] truncate text-xs text-rose-600" title={row.failure_reason}>
    {row.failure_reason}
  </div>
) : null}
```

- [ ] **Step 4: Add translations**

In `src/locales/zh/translation.json`, under `agent_commerce`, add or merge:

```json
"source": {
  "domain": "代理域名",
  "user_binding": "用户归属",
  "unknown": "未知来源"
},
"failure_reason": "失败原因",
"hold_status": {
  "pending": "待扣款",
  "captured": "已扣款",
  "released": "已释放",
  "failed": "失败"
}
```

In `src/locales/en/translation.json`, add:

```json
"source": {
  "domain": "Agent domain",
  "user_binding": "User binding",
  "unknown": "Unknown source"
},
"failure_reason": "Failure reason",
"hold_status": {
  "pending": "Pending",
  "captured": "Captured",
  "released": "Released",
  "failed": "Failed"
}
```

- [ ] **Step 5: Run tests/build**

Run:

```bash
npm run test -- agentCommerceDisplay
npm run build
```

If no helper file was needed, skip the test command and run only `npm run build`.

Expected: PASS; build may keep existing Vite chunk-size warnings.

- [ ] **Step 6: Commit**

```bash
git add src/pages/agent/AgentCommercePage.tsx src/lib/agentCommerceDisplay.ts src/lib/agentCommerceDisplay.test.ts src/locales/zh/translation.json src/locales/en/translation.json
git commit -m "feat: show agent order failure state"
```

If no helper files were created, omit them from `git add`.

## Task 6: Regression Verification And Push

**Files:**
- No code changes unless verification reveals failures.

- [ ] **Step 1: Run backend focused tests**

Run in `keliboard`:

```bash
php vendor/bin/phpunit tests/Unit/Services/AgentCommerceServiceTest.php
php vendor/bin/phpunit tests/Unit/Http/AgentDomainOrderFlowTest.php
php vendor/bin/phpunit tests/Unit/Http/AdminAgentCommerceControllerTest.php
```

Expected: PASS.

- [ ] **Step 2: Run frontend builds**

Run in `keli-user`:

```bash
npm run test -- agentCommerceErrors
npm run build
```

Run in `keli-admin`:

```bash
npm run build
```

If `agentCommerceDisplay.test.ts` exists, also run:

```bash
npm run test -- agentCommerceDisplay
```

Expected: PASS; existing Vite chunk-size warnings are acceptable.

- [ ] **Step 3: Check git state**

Run:

```bash
git -C keliboard status -sb
git -C keli-user status -sb
git -C keli-admin status -sb
```

Expected: each branch is clean except known untracked local development logs in `keli-user`:

```text
?? design-audits/
?? dev_server.err.log
?? dev_server.out.log
```

- [ ] **Step 4: Push branches**

Run:

```bash
git -C keliboard push origin feature/agent-domain-commerce
git -C keli-user push origin feature/agent-domain-commerce
git -C keli-admin push origin feature/agent-domain-commerce
```

Expected: all pushes succeed.

---

## Self-Review Notes

- Spec coverage:
  - Creation guard: already exists and is covered by existing tests.
  - Checkout guard: Task 1.
  - Callback failure status: Task 2.
  - Admin visibility: Tasks 3 and 5.
  - User-friendly error copy: Task 4.
  - Regression verification: Task 6.
- Placeholder scan: no placeholder markers or open-ended implementation steps are intentionally left.
- Type consistency:
  - Backend uses existing `AgentBalanceHold`, `AgentOrderContext`, `Order`, `Payment`, and `AgentCommerceService` names.
  - Frontend helper names are introduced before they are consumed.
