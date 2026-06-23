# Subscription Client Capability Filter Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure subscription exports only include nodes a detected client can import, so unsupported protocols or transports do not make the whole subscription fail.

**Architecture:** Keep all client compatibility decisions inside `ProtocolCapabilityService`. `ClientController` should always pass the filtered server list through that service; wrapper app-version exceptions remain limited to core-version checks inside the capability service.

**Tech Stack:** Laravel/PHP, PHPUnit, existing protocol capability config.

---

### Task 1: Regression Coverage

**Files:**
- Modify: `tests/Unit/Http/ClientControllerTest.php`

- [ ] **Step 1: Write the failing test**

Update the controller regression so `hiddify` four-segment wrapper versions still call the capability filter in `doSubscribe()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Http/ClientControllerTest.php`

Expected before implementation: the updated test fails because the controller bypasses `ProtocolCapabilityService::filterServersForClient()` for wrapper versions.

### Task 2: Keep Capability Filtering Enabled

**Files:**
- Modify: `app/Http/Controllers/V1/Client/ClientController.php`

- [ ] **Step 1: Write minimal implementation**

Remove the controller-level bypass so the subscription entrypoint always calls `ProtocolCapabilityService::filterServersForClient()`.

- [ ] **Step 2: Run focused tests**

Run: `php vendor/bin/phpunit tests/Unit/Http/ClientControllerTest.php tests/Unit/Support/ProtocolCapabilityServiceTest.php`

Expected after implementation: wrapper app versions still keep supported AnyTLS/Naive nodes through `ProtocolCapabilityService`, while unsupported transports are dropped.

### Task 3: Verify And Ship

**Files:**
- Review: `git diff`

- [ ] **Step 1: Static diff check**

Run: `git diff --check`

- [ ] **Step 2: Commit and push**

Commit the regression and controller fix, then push `main` to GitHub.
