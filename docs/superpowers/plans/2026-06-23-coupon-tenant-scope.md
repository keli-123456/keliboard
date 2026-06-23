# Coupon Tenant Scope Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add global/site/agent ownership to coupons and enforce that ownership when users check or apply coupon codes.

**Architecture:** Coupon scope fields live on `v2_coupon` and are normalized by the `Coupon` model. `CouponService` remains the single validation point used by user checks and order creation, so both preview and purchase paths share the same tenant enforcement. The admin UI follows the existing coupon editor patterns and loads site/agent options for scoped coupons.

**Tech Stack:** Laravel 12, Eloquent models, PHPUnit unit tests, React + TypeScript admin UI.

---

### Task 1: Backend Schema And Model

**Files:**
- Create: `database/migrations/2026_06_23_000005_scope_coupons_by_tenant.php`
- Modify: `app/Models/Coupon.php`

- [x] Add `scope_type`, `site_id`, `agent_user_id`, and `agent_domain_id` to `v2_coupon`.
- [x] Add scope constants, casts, `normalizeScopeType()`, and `scopePayload()` to `Coupon`.

### Task 2: Coupon Validation

**Files:**
- Test: `tests/Unit/Services/CouponTenantScopeServiceTest.php`
- Modify: `app/Services/CouponService.php`

- [x] Write tests for global, site mismatch, same-code site priority, agent mismatch, and owned agent use.
- [x] Add scope eligibility checks before current coupon checks.
- [x] Keep legacy global coupons compatible.

### Task 3: Admin API

**Files:**
- Modify: `app/Http/Requests/Admin/CouponGenerate.php`
- Modify: `app/Http/Controllers/V2/Admin/CouponController.php`

- [x] Accept scope fields on create/update.
- [x] Return scope fields in fetch results.
- [x] Include scope fields in bulk generation and CSV export.

### Task 4: Admin UI

**Files:**
- Modify: `src/services/coupon.ts`
- Modify: `src/pages/subscription/CouponManage.tsx`
- Modify: `src/locales/zh/translation.json`
- Modify: `src/locales/en/translation.json`

- [x] Add scope fields to the coupon TypeScript model and editor form.
- [x] Load site and agent options.
- [x] Add ownership selection to the coupon dialog.
- [x] Show ownership labels in the table.

### Task 5: Verification And Shipping

**Commands:**
- `php vendor/bin/phpunit tests/Unit/Services/CouponTenantScopeServiceTest.php`
- `npm run lint`
- `npm run build`
- `npm run sync:xboardpro`
- `git status --short`

- [x] Run available checks.
- [x] Commit and push `keliboard`.
- [x] Commit and push `keli-admin`.
- [x] Sync admin build into `keliboard`, commit, and push.
