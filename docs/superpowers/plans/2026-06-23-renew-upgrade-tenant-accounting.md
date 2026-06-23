# Renew And Upgrade Tenant Accounting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Protect tenant-aware pricing and agent balance handling for auto-renewal and discount-upgrade orders.

**Architecture:** Keep the existing shared flow: `TenantPlanPricingService` resolves prices, `AutoRenewOrders` and `OrderUpgradeService` create tenant-aware orders, and `OrderService` captures or releases agent holds. Add focused regression coverage around the paths that can bypass the storefront UI.

**Tech Stack:** Laravel/PHP, PHPUnit, in-memory SQLite test helpers.

---

### Task 1: Auto-renewal agent balance guard

**Files:**
- Modify: `tests/Unit/Console/AutoRenewOrdersTest.php`

- [ ] Add a test that creates an agent-bound renewing user with enough user balance but insufficient agent balance.
- [ ] Run `php vendor/bin/phpunit --filter AutoRenewOrdersTest`.
- [ ] Confirm no order, no hold, and no agent context are created.

### Task 2: Discount-upgrade paid capture

**Files:**
- Modify: `tests/Unit/Services/OrderUpgradeServiceTenantPricingTest.php`

- [ ] Add a test that creates an agent discount-upgrade order.
- [ ] Pay it through `OrderService::paid('upgrade-gateway')`.
- [ ] Assert the order completes, the agent hold is captured, the agent context is paid, and agent balance is reduced by the hold amount.

### Task 3: Discount-upgrade cancellation release

**Files:**
- Modify: `tests/Unit/Services/OrderUpgradeServiceTenantPricingTest.php`

- [ ] Add a test that creates an agent discount-upgrade order.
- [ ] Cancel it through `OrderService::cancel()`.
- [ ] Assert the order is cancelled, the hold is released, and agent balance is unchanged.

### Task 4: Verification

- [ ] Run `git diff --check`.
- [ ] Run the targeted PHPUnit filters when PHP is available:
  - `php vendor/bin/phpunit --filter AutoRenewOrdersTest`
  - `php vendor/bin/phpunit --filter OrderUpgradeServiceTenantPricingTest`
- [ ] Commit and push the focused change.
