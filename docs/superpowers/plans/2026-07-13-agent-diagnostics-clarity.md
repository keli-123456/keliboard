# Agent Diagnostics Clarity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make agent diagnostics mode-aware, remove historical payment false positives, and restore green tests for agent/site source diagnostics.

**Architecture:** Keep all mode detection derived from existing agent commerce rows. Adjust the shared order status resolver at the source, expose the derived mode through the existing diagnostics response, and make the user UI render a mode-appropriate summary without changing financial workflows.

**Tech Stack:** PHP 8.2, Laravel/Eloquent, PHPUnit 11, React, TypeScript, Vitest, i18next.

## Global Constraints

- No database migration or stored mode flag.
- No changes to order creation, payment callback, balance hold, capture, or release behavior.
- Preserve existing diagnostics fields and API compatibility.
- Use test-first changes for every behavior modification.

---

### Task 1: Remove historical payment false positives

**Files:**
- Modify: `tests/Unit/Services/AgentOrderStatusResolverTest.php`
- Modify: `app/Services/AgentOrderStatusResolver.php`

**Interfaces:**
- Consumes: `AgentOrderStatusResolver::resolve(AgentOrderContext): array`
- Produces: `payment_disabled` only for pending or processing orders.

- [ ] Add tests proving cancelled and completed orders do not receive `payment_disabled` after their payment method is disabled.
- [ ] Run the resolver test and confirm the new assertions fail for `payment_disabled`.
- [ ] Restrict the payment availability check to actionable order states.
- [ ] Run resolver and operations tests and confirm they pass.

### Task 2: Restore diagnostics and Telegram test fidelity

**Files:**
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php`
- Modify: `tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php`

**Interfaces:**
- Consumes: Laravel `Schema` facade and the shared in-memory database helper.
- Produces: tests whose facade and Eloquent models use the same SQLite connection.

- [ ] Use the existing failing diagnostics and Telegram test classes as the red baseline.
- [ ] Rebind the Schema facade after installing the in-memory database manager.
- [ ] Create `v2_agent_site_setting` during diagnostics test setup.
- [ ] Run both classes and confirm all tests pass.

### Task 3: Add derived agent mode diagnostics

**Files:**
- Modify: `tests/Unit/Services/AgentCommerceDiagnosticsServiceTest.php`
- Modify: `app/Services/AgentCommerceDiagnosticsService.php`

**Interfaces:**
- Produces: top-level `mode: basic|storefront` and summary `storefront_configured: bool`.

- [ ] Add a failing test for an empty-commerce active agent returning basic mode with an `ok` overall status.
- [ ] Add a failing test that any commerce artifact switches the agent to storefront mode.
- [ ] Implement artifact-based mode detection and mode-aware overall status.
- [ ] Run diagnostics and controller tests.

### Task 4: Present the mode clearly in the user agent center

**Files:**
- Modify: `keli-user/src/services/agentCommerce.ts`
- Modify: `keli-user/src/pages/AgentCenterPage.tsx`
- Modify: the existing `keli-user` translation resources containing `agentCenter.diagnostics`.
- Test: `keli-user/src/lib/agentDiagnostics.test.ts`

**Interfaces:**
- Consumes: diagnostics `mode` and `summary.storefront_configured`.
- Produces: a basic-agent summary or the existing storefront readiness grid.

- [ ] Add helper assertions for mode labels and whether detailed diagnostics should display.
- [ ] Run the helper test and confirm it fails for the new mode behavior.
- [ ] Extend the API types and render a compact mode badge and description.
- [ ] Keep detailed checks and action buttons visible only for storefront mode.
- [ ] Run user tests and build the theme package.

### Task 5: Regression, sync, and delivery

**Files:**
- Sync generated `keli-admin` assets into `keliboard` only if admin source changes.

**Interfaces:**
- Produces: committed and pushed backend/user changes with a rebuilt theme package.

- [ ] Run backend targeted tests and the 338-test agent/multisite selection.
- [ ] Run `npm run test` and `npm run build` for `keli-user`.
- [ ] Run `git diff --check` in each touched repository.
- [ ] Commit and push each touched repository, then verify remote branch hashes.

