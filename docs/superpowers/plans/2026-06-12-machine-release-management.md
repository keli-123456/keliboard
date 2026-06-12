# Machine Release Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add panel-hosted `kelinode-rs` and `keli-core-rs` binary version management.

**Architecture:** Keep the existing panel release download routes and add an admin management layer. Persist release metadata in `v2_server_machine_release`, store files under Laravel local storage, and make `latestLocalVersion()` prefer an explicit default release.

**Tech Stack:** Laravel/PHP backend, local storage disk, React/Vite admin frontend, Vitest frontend tests, PHPUnit backend tests where PHP is available.

---

### Task 1: Backend Release Metadata

**Files:**
- Create: `database/migrations/2026_06_12_000002_create_v2_server_machine_release_table.php`
- Create: `app/Models/ServerMachineRelease.php`
- Modify: `app/Services/ServerMachine/MachineReleaseDistributionService.php`
- Test: `tests/Unit/Http/MachineReleaseManagementControllerTest.php`

- [ ] Write tests for listing uploaded releases and resolving the default local version.
- [ ] Add the migration and model.
- [ ] Add service helpers for listing, storing, marking default, and deleting local releases.
- [ ] Verify `latestLocalVersion()` returns the explicit default before falling back to scanned storage.

### Task 2: Backend Admin Endpoints

**Files:**
- Create: `app/Http/Controllers/V2/Admin/Server/MachineReleaseManagementController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Test: `tests/Unit/Http/MachineReleaseManagementControllerTest.php`

- [ ] Write tests for upload validation, set-default behavior, and default deletion rejection.
- [ ] Add admin endpoints under `/admin/server/machine/release`.
- [ ] Store manifest and archive in the existing release directory naming convention.
- [ ] Clear version cache after upload, default change, or deletion.

### Task 3: Admin UI

**Files:**
- Modify: `keli-admin/src/services/server.ts`
- Create: `keli-admin/src/pages/server/components/MachineReleaseManager.tsx`
- Modify: `keli-admin/src/pages/server/MachineManage.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`
- Test: `keli-admin/src/pages/server/components/MachineReleaseManager.test.tsx`

- [ ] Write frontend tests for rendering releases and disabling delete on default releases.
- [ ] Add service methods for list/upload/set-default/delete.
- [ ] Add a compact release management section to the machine page.
- [ ] Build and sync admin assets into `keliboard`.

