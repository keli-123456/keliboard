# Order Issue Loop Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the first admin order anomaly loop so agent order issues are visible and stuck pending holds can be safely released from the order detail page.

**Architecture:** Reuse existing `AgentOrderStatusResolver` for diagnostics and `AgentCommerceService::releaseForOrder()` for state transition. The admin order detail payload exposes read-only diagnostics, while a dedicated admin action performs the single safe repair: release a pending hold on a cancelled order. The admin UI shows the diagnostics and only enables the repair button when the backend says it is allowed.

**Tech Stack:** Laravel controllers/services/models/tests in `keliboard`; React, TypeScript, Vitest, and existing shadcn-style UI in `keli-admin`.

---

### Task 1: Backend diagnostics and repair action

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/OrderController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Test: `tests/Unit/Http/AdminOrderTenantContextTest.php`

- [ ] **Step 1: Write failing tests**

Add assertions that agent detail payload includes `abnormal_flags`, `capture_status`, `can_release_hold`, and `recommended_action`, and that posting to `/api/v2/admin/order/release-agent-hold` releases a cancelled order's pending hold.

- [ ] **Step 2: Run backend test**

Run: `php vendor/bin/phpunit tests/Unit/Http/AdminOrderTenantContextTest.php`

Expected: fails because the diagnostic payload and action do not exist yet.

- [ ] **Step 3: Implement minimal backend**

Use `AgentOrderStatusResolver` in `tenantContextPayload()` when an agent context is loaded. Add `releaseAgentHold(Request $request)` to find an order by `trade_no`, reject non-agent or non-cancelled orders, call `AgentCommerceService::releaseForOrder($order)`, and return the refreshed order payload. Register the route as `POST /api/v2/admin/order/release-agent-hold`.

- [ ] **Step 4: Run backend test again**

Run: `php vendor/bin/phpunit tests/Unit/Http/AdminOrderTenantContextTest.php`

Expected: pass in an environment with PHP available.

### Task 2: Admin UI diagnostics and action

**Files:**
- Modify: `src/services/order.ts`
- Modify: `src/pages/subscription/orderUtils.ts`
- Modify: `src/pages/subscription/orderUtils.test.ts`
- Modify: `src/pages/subscription/OrderManage.tsx`
- Modify: `src/locales/zh/translation.json`
- Modify: `src/locales/en/translation.json`

- [ ] **Step 1: Write failing frontend utility test**

Add a test for `getAgentIssueDisplay()` mapping known flags such as `hold_expired`, `ledger_missing`, and `cancelled_with_pending_hold` into translation keys and danger/warning tones.

- [ ] **Step 2: Run frontend test**

Run: `npm test -- src/pages/subscription/orderUtils.test.ts`

Expected: fails because the helper does not exist yet.

- [ ] **Step 3: Implement types, helper, and UI**

Extend `OrderTenantContext` with diagnostic fields. Add `releaseAgentHold(trade_no)` service method. Render a compact diagnostics block in order detail for agent orders and show a destructive repair button only when `can_release_hold` is true.

- [ ] **Step 4: Verify frontend**

Run:
- `npm test -- src/pages/subscription/orderUtils.test.ts`
- `npx tsc --noEmit`
- `npm run build`

Expected: all pass, except Vite may print the existing chunk-size warning.

### Task 3: Sync, verify, and publish

**Files:**
- Modify: `public/assets/admin-xboard/index.html`
- Modify: `public/assets/admin-xboard/assets/index.js`
- Modify: `public/assets/admin-xboard/assets/index.css`

- [ ] **Step 1: Sync built admin assets**

Run: `npm run sync:xboardpro` from `keli-admin`.

- [ ] **Step 2: Verify diffs**

Run:
- `git diff --check` in `keliboard`
- `git diff --check` in `keli-admin`
- `git status --short --branch` in both repositories

- [ ] **Step 3: Commit and push**

Commit `keli-admin` source, commit `keliboard` backend/assets/plan, then push both `main` branches.
