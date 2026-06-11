# Server Machine Bulk Bind Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add visual batch node binding for server machines.

**Architecture:** The backend adds one transactional batch endpoint that reuses the current `machine_id` ownership model. The admin UI adds a bulk dialog and keeps filtering, payload building, and preview summaries in small testable helper functions.

**Tech Stack:** Laravel 12, Eloquent, PHPUnit, React, TypeScript, Vitest.

---

### Task 1: Backend Batch Bind Endpoint

**Files:**

- Test: `tests/Unit/Http/ServerMachineBatchBindNodesTest.php`
- Modify: `app/Http/Controllers/V2/Admin/Server/MachineController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`

Steps:

- [ ] Write PHPUnit tests for replace, append, conflict skip, conflict transfer, and duplicate node submission.
- [ ] Add `batchBindNodes` to `MachineController`.
- [ ] Register `POST /server/machine/batchBindNodes`.
- [ ] Run backend tests if PHP is available.
- [ ] Commit backend change.

### Task 2: Admin Service And Helpers

**Files:**

- Modify: `src/services/server.ts`
- Create: `src/pages/server/machineBulkBind.ts`
- Test: `src/pages/server/machineBulkBind.test.ts`

Steps:

- [ ] Write Vitest tests for payload creation, summary calculation, and filtering.
- [ ] Add service types and `serverMachineApi.batchBindNodes`.
- [ ] Implement `machineBulkBind.ts` helpers.
- [ ] Run targeted Vitest tests.
- [ ] Commit helper change.

### Task 3: Admin Bulk Dialog UI

**Files:**

- Modify: `src/pages/server/MachineManage.tsx`
- Modify translations under `src/locales`.

Steps:

- [ ] Add bulk bind state and dialog open action.
- [ ] Add machine selection list.
- [ ] Add per-machine node picker with search and filters.
- [ ] Add mode and transfer controls.
- [ ] Submit to `batchBindNodes`, refresh machines, and show result toast.
- [ ] Run build/test.
- [ ] Commit UI change.

### Task 4: Final Verification

Steps:

- [ ] Run `npm test` in `keli-admin`.
- [ ] Run `npm run build` in `keli-admin`.
- [ ] Run `git diff --check` in both repos.
- [ ] Check both repo statuses.
- [ ] Push both repos.

## Self-Review

- Spec coverage: The endpoint and UI cover selecting nodes visually, replace/append modes, and conflict handling.
- Placeholder scan: No unresolved implementation placeholders.
- Type consistency: Backend uses `machine_id` and `node_ids`; frontend helper and service types use the same names.
