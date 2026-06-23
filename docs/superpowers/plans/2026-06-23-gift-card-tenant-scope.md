# Gift Card Tenant Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add global/site/agent ownership to gift cards and enforce agent balance charging during redemption.

**Architecture:** Scope is stored on templates, generated codes, and usage records. Redemption uses code scope as the source of truth, falling back to the template for legacy rows. Agent costs are calculated before rewards are granted and recorded in `v2_agent_ledger`.

**Tech Stack:** Laravel 12, Eloquent models, PHPUnit unit tests, React + TypeScript admin UI.

---

### Task 1: Backend Scope Schema And Models

**Files:**
- Create: `database/migrations/2026_06_23_000004_scope_gift_cards_by_tenant.php`
- Modify: `app/Models/GiftCardTemplate.php`
- Modify: `app/Models/GiftCardCode.php`
- Modify: `app/Models/GiftCardUsage.php`

- [x] Write the migration adding `scope_type`, `site_id`, `agent_user_id`, and `agent_domain_id` to template/code/usage tables.
- [x] Add model constants and fillable fields.
- [x] Make `GiftCardCode::batchGenerate()` copy the template scope.
- [x] Make `GiftCardUsage::createRecord()` snapshot the code scope.

### Task 2: Redemption Enforcement

**Files:**
- Test: `tests/Unit/Services/GiftCardTenantScopeServiceTest.php`
- Modify: `app/Services/GiftCardService.php`

- [x] Write tests for site scope, agent ownership, agent balance deduction, and rollback.
- [x] Add scope eligibility checks before normal gift-card conditions.
- [x] Add agent cost calculation and ledger creation.
- [x] Ensure insufficient balance throws before rewards are granted.

### Task 3: Admin API

**Files:**
- Modify: `app/Http/Controllers/V2/Admin/GiftCardController.php`
- Modify: `app/Http/Controllers/V2/Admin/ConfigController.php`
- Modify: `app/Http/Requests/Admin/ConfigSave.php`

- [x] Accept scope fields on create/update.
- [x] Return ownership fields in template/code/usage payloads.
- [x] Filter template/code/usage/statistics queries by scope.
- [x] Include scope columns in CSV export.
- [x] Expose agent gift-card traffic/device unit price settings.

### Task 4: Admin UI

**Files:**
- Modify: `src/services/giftcard.ts`
- Modify: `src/pages/subscription/giftCardFormTypes.ts`
- Modify: `src/pages/subscription/giftCardTemplateForm.ts`
- Modify: `src/pages/subscription/GiftCardTemplateEditorDialog.tsx`
- Modify: `src/pages/subscription/GiftCardManage.tsx`
- Modify: `src/pages/subscription/GiftCardTemplatesTab.tsx`
- Modify: `src/pages/subscription/GiftCardCodesTab.tsx`
- Modify: `src/pages/subscription/GiftCardUsagesTab.tsx`
- Modify: `src/pages/system/config/components/AgentCenterSettings.tsx`
- Modify: `src/locales/zh/translation.json`
- Modify: `src/locales/en/translation.json`

- [x] Add scope fields to TypeScript models and form payloads.
- [x] Load site and agent options.
- [x] Add ownership selection to the template editor.
- [x] Show ownership labels in lists.
- [x] Add agent gift-card unit price controls.

### Task 5: Verification And Shipping

**Commands:**
- `php vendor/bin/phpunit tests/Unit/Services/GiftCardTenantScopeServiceTest.php`
- `npm test -- --run src/pages/subscription/giftCardTemplateForm.test.ts`
- `npm run build`
- `git status --short`

- [x] Run available checks.
- [x] Commit and push both repositories.
