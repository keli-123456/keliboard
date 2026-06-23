# Marketing Tenant Template Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make marketing automation render tenant-scoped templates for agent and site users while preserving global fallback.

**Architecture:** Keep rules as global automation definitions. Resolve the effective template at dispatch queue time from the user's notification context and the rule's base template. Relax the marketing template code uniqueness so scoped overrides can share the same semantic code.

**Tech Stack:** Laravel services, Eloquent models, Laravel migrations, PHPUnit unit tests.

---

### Task 1: Add Failing Coverage

**Files:**
- Modify: `tests/Unit/Http/AdminMarketingSiteScopeTest.php`

- [ ] **Step 1: Write failing tests**

Add tests for site override, agent override, and global fallback around the private `queueForUserRule` method.

- [ ] **Step 2: Verify red**

Run: `php vendor/bin/phpunit tests/Unit/Http/AdminMarketingSiteScopeTest.php`

Expected before implementation: tests fail because the base global template is still used.

### Task 2: Enable Scoped Template Codes

**Files:**
- Create: `database/migrations/2026_06_23_000006_allow_scoped_marketing_template_codes.php`

- [ ] **Step 1: Add migration**

Drop the original unique code index if it exists, then add a non-unique code index and a lookup index for scoped resolution.

### Task 3: Resolve Effective Template

**Files:**
- Modify: `app/Models/MarketingTemplate.php`
- Modify: `app/Services/MarketingAutomationService.php`

- [ ] **Step 1: Add model scope helpers**

Add constants and helpers for `global`, `site`, and `agent` scope normalization.

- [ ] **Step 2: Resolve templates in queue flow**

Use the notification context to select agent, site, or global template before rendering email and Telegram tasks.

### Task 4: Verify And Commit

**Files:**
- Test: `tests/Unit/Http/AdminMarketingSiteScopeTest.php`

- [ ] **Step 1: Run verification**

Run:

```bash
php vendor/bin/phpunit tests/Unit/Http/AdminMarketingSiteScopeTest.php
git diff --check
```

- [ ] **Step 2: Commit and push**

Commit with:

```bash
git add app/Models/MarketingTemplate.php app/Services/MarketingAutomationService.php database/migrations/2026_06_23_000006_allow_scoped_marketing_template_codes.php tests/Unit/Http/AdminMarketingSiteScopeTest.php docs/superpowers/specs/2026-06-23-marketing-tenant-template-selection-design.md docs/superpowers/plans/2026-06-23-marketing-tenant-template-selection.md
git commit -m "feat: resolve scoped marketing templates"
git push origin main
```
