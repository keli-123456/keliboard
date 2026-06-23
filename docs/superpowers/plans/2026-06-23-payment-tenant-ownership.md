# Payment Tenant Ownership Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure payment method lookup, checkout binding, and callback handling preserve tenant ownership for platform, site, and agent orders.

**Architecture:** Keep the existing checkout and callback flow. Add regression tests around `AgentCommerceService::effectivePaymentContext()`, `OrderController::checkout()`, and `PaymentController::handle()`. Add `owner_domain_id` to agent payment snapshots so diagnostics retain the selected domain-specific payment ownership.

**Tech Stack:** Laravel/PHP, PHPUnit, in-memory SQLite test helpers.

---

### Task 1: Order-context payment lookup

**Files:**
- Modify: `tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] Add a test that creates an agent order on `shop-a.example.test`, then requests payment methods for that trade number while the current Host is `shop-b.example.test`.
- [ ] Assert the result includes only the agent's global payment and the `shop-a` domain-bound payment.

### Task 2: Callback ownership mismatch

**Files:**
- Modify: `tests/Unit/Http/AgentDomainOrderFlowTest.php`

- [ ] Add a test that binds an agent order to payment A and invokes the callback with payment B.
- [ ] Assert the callback returns false, the order remains pending, the hold remains pending, and agent balance is unchanged.

### Task 3: Payment snapshot ownership

**Files:**
- Modify: `tests/Unit/Http/AgentDomainOrderFlowTest.php`
- Modify: `app/Services/AgentCommerceService.php`

- [ ] Add a test that checks out with a domain-bound agent payment and expects `payment_snapshot.owner_domain_id`.
- [ ] Add `owner_domain_id` to snapshots written by `assignPaymentForCheckout()` and `attachPayment()`.

### Task 4: Verification

- [ ] Run `git diff --check`.
- [ ] Run `php vendor/bin/phpunit --filter AgentDomainOrderFlowTest` when PHP is available.
- [ ] Commit and push the focused change.
