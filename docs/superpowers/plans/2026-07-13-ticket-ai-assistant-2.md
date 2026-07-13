# Ticket AI Assistant 2.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a tenant-aware, privacy-conscious, observable ticket AI assistant that still requires administrators to review and send every reply.

**Architecture:** Split the existing monolithic assistant into a sanitized context builder, an OpenAI-compatible provider client, and an operational request logger. Keep `TicketAiSuggestion` and the current insert/edit/send workflow as the business source of truth, and expose a small capability API to drive the admin UI.

**Tech Stack:** PHP 8.2, Laravel 12, Eloquent, PHPUnit 11, React 18, TypeScript, Vitest, Axios, i18next.

## Global Constraints

- The assistant remains read-only for users, orders, payments, subscriptions, risk controls, servers, and nodes.
- No automatic replies or unattended actions.
- API keys, raw prompts, ticket transcripts, and provider response bodies must never be written to request logs.
- Existing AI settings and suggestions remain backward compatible.
- Existing global knowledge articles remain global in this phase.
- All new behavior is introduced with failing tests first.

---

### Task 1: AI Request Audit and Suggestion Scope

**Files:**
- Create: `database/migrations/2026_07_13_000001_add_ticket_ai_scope_and_request_logs.php`
- Create: `app/Models/TicketAiRequestLog.php`
- Modify: `app/Models/TicketAiSuggestion.php`
- Test: `tests/Unit/Models/TicketAiRequestLogTest.php`

**Interfaces:**
- Produces: `TicketAiRequestLog::record(array $attributes): TicketAiRequestLog`
- Produces suggestion fields: `scope_type`, `site_id`, `agent_user_id`, `agent_domain_id`, `structured_output`.

- [ ] **Step 1: Write failing model and migration-contract tests**

Create an in-memory `v2_ticket_ai_request_log` table and assert fill/casts for status, latency, token counts, and tenant IDs. Assert `TicketAiSuggestion` casts `structured_output` to boolean.

```php
$log = TicketAiRequestLog::record([
    'status' => TicketAiRequestLog::STATUS_SUCCESS,
    'scope_type' => 'site',
    'site_id' => 3,
    'latency_ms' => 125,
    'input_tokens' => 80,
    'output_tokens' => 30,
]);

$this->assertSame(110, $log->total_tokens);
$this->assertSame(3, $log->site_id);
```

- [ ] **Step 2: Run the new test and confirm it fails**

Run: `php vendor/bin/phpunit tests/Unit/Models/TicketAiRequestLogTest.php`

Expected: FAIL because `TicketAiRequestLog` does not exist.

- [ ] **Step 3: Implement the migration and model**

The request-log model exposes status constants `success` and `failed`, casts numeric columns, computes missing `total_tokens`, and uses guarded `id`. The migration adds nullable scope columns and `structured_output` to `v2_ticket_ai_suggestion`, creates indexed request-log columns, and supports safe rollback.

- [ ] **Step 4: Run tests and commit**

Run the model test and `php -l` on the migration/model. Commit only Task 1 files with `feat: add ticket AI request audit`.

### Task 2: Privacy Sanitizer

**Files:**
- Create: `app/Services/TicketAiContentSanitizer.php`
- Test: `tests/Unit/Services/TicketAiContentSanitizerTest.php`

**Interfaces:**
- Produces: `sanitize(string $value, int $maxLength = 2000): string`
- Produces: `sanitizeConversation(iterable $messages, int $maxMessages, int $maxTotalChars = 12000): array`
- Produces: `sanitizeKnowledge(array $items, int $maxTotalChars = 6000): array`

- [ ] **Step 1: Write failing redaction and bound tests**

Use text containing an email, UUID, bearer token, subscription token, password assignment, and an ordinary troubleshooting URL. Assert sensitive values disappear, the normal URL remains, and every configured length cap is respected.

```php
$input = 'mail a@example.com uuid 123e4567-e89b-12d3-a456-426614174000 password=secret123 https://help.example.com/a';
$output = $sanitizer->sanitize($input, 500);
$this->assertStringNotContainsString('a@example.com', $output);
$this->assertStringNotContainsString('secret123', $output);
$this->assertStringContainsString('https://help.example.com/a', $output);
```

- [ ] **Step 2: Run and confirm failure**

Run: `php vendor/bin/phpunit tests/Unit/Services/TicketAiContentSanitizerTest.php`.

- [ ] **Step 3: Implement deterministic sanitization**

Use Unicode-safe regular expressions and `mb_substr`. Replace secrets with stable labels such as `[EMAIL]`, `[UUID]`, `[TOKEN]`, and `[REDACTED]`. Conversation output is a list of `{role, content}` values with oldest-to-newest ordering among the retained messages.

- [ ] **Step 4: Run tests and commit**

Commit Task 2 with `feat: sanitize ticket AI context`.

### Task 3: Tenant-Aware Ticket Context

**Files:**
- Create: `app/Services/TicketAiContextService.php`
- Test: `tests/Unit/Services/TicketAiContextServiceTest.php`
- Modify: `tests/Support/InteractsWithInMemoryDatabase.php` only if new shared tables are required.

**Interfaces:**
- Consumes: `Ticket`, `TicketAiContentSanitizer`, existing site/agent relations and read-only user/order/risk data.
- Produces: `build(Ticket $ticket, int $maxMessages, ?string $instruction): array{scope:array,user:array,subscription:array,orders:array,risk:array,conversation:array,instruction:string}`.

- [ ] **Step 1: Add platform/site/agent failing tests**

Assert:

- platform context uses the panel brand;
- site context uses the site's name and primary domain;
- agent-domain context uses the storefront name/domain and does not include the platform name;
- user context contains `user_ref` but no email, token, or UUID;
- subscription and order summaries contain only approved fields;
- risk context contains level/counts but no raw IP.

- [ ] **Step 2: Run and confirm failures**

Run: `php vendor/bin/phpunit tests/Unit/Services/TicketAiContextServiceTest.php`.

- [ ] **Step 3: Implement context resolution with graceful degradation**

Load `messages`, `user.plan`, `user.site`, `site.domains`, `agent`, and `agentDomain`. Resolve scope in agent > site > platform order. Query at most three recent orders. Convert byte values to integer byte summaries, preserve epoch timestamps, and guard optional-table reads with schema checks/catches.

- [ ] **Step 4: Run tests and commit**

Commit Task 3 with `feat: add tenant-aware ticket AI context`.

### Task 4: Reliable OpenAI-Compatible Provider Client

**Files:**
- Create: `app/Services/TicketAiProviderClient.php`
- Create: `app/Exceptions/TicketAiProviderException.php`
- Test: `tests/Unit/Services/TicketAiProviderClientTest.php`

**Interfaces:**
- Consumes: settings and prepared `messages`.
- Produces: `complete(array $settings, array $messages): array{content:string,decoded:?array,structured:bool,latency_ms:int,input_tokens:int,output_tokens:int,total_tokens:int,prompt_chars:int,response_chars:int}`.
- Throws: `TicketAiProviderException` with codes `timeout`, `connection`, `authentication`, `rate_limited`, `invalid_response`, or `upstream`.

- [ ] **Step 1: Write response/parser and error-mapping tests**

Cover direct JSON, fenced JSON, prose containing one JSON object, plain-text fallback, missing content, 401, 429, 500, and connection exceptions. Assert `max_tokens` and optional `response_format` request fields.

- [ ] **Step 2: Run and confirm failure**

Run: `php vendor/bin/phpunit tests/Unit/Services/TicketAiProviderClientTest.php`.

- [ ] **Step 3: Implement the provider client**

Build the endpoint from the base URL, use bearer auth, clamp timeout to 5-120 seconds and maximum output tokens to 128-4096, measure latency with `hrtime`, parse provider `usage`, and never include response bodies in exception messages.

- [ ] **Step 4: Run tests and commit**

Commit Task 4 with `feat: harden ticket AI provider calls`.

### Task 5: Assistant Orchestration, Capabilities, Connection Test, and Statistics

**Files:**
- Modify: `app/Services/TicketAiAssistantService.php`
- Modify: `app/Http/Controllers/V2/Admin/TicketController.php`
- Modify: `app/Http/Routes/V2/AdminRoute.php`
- Modify: `app/Http/Requests/Admin/ConfigSave.php`
- Modify: `app/Http/Controllers/V2/Admin/ConfigController.php`
- Create: `app/Console/Commands/CleanupTicketAiRequestLogs.php`
- Modify: `app/Console/Kernel.php`
- Test: `tests/Unit/Services/TicketAiAssistantServiceTest.php`
- Test: `tests/Unit/Http/TicketAiControllerTest.php`

**Interfaces:**
- Produces: `capabilities(): array{enabled:bool,configured:bool,available:bool,reason:?string}`
- Produces: `testConnection(?int $adminId): array{ok:bool,model:string,latency_ms:int}`
- Extends: `stats(int $days): array` with requests, success rate, average latency, tokens, and errors.

- [ ] **Step 1: Expand service tests before implementation**

Assert provider payload contains the correct site/agent brand and no private identifiers. Assert suggestions and request logs receive tenant scope. Assert failed provider calls create failure logs. Assert plain-text fallback sets `needs_human=true` and `structured_output=false`.

- [ ] **Step 2: Add capability/controller failing tests**

Cover disabled, missing API key, missing endpoint/model, ready, connection success/failure, and settings validation for `ticket_ai_max_tokens`, `ticket_ai_timeout`, `ticket_ai_json_mode`, and `ticket_ai_log_retention_days`.

- [ ] **Step 3: Refactor orchestration**

Inject/use the context service, sanitizer, and provider client. Preserve current category/risk normalization and feedback/send methods. Record request metadata on success and failure. Add route endpoints:

```text
GET  /ticket/aiCapabilities
POST /ticket/aiTestConnection
```

- [ ] **Step 4: Add retention cleanup**

Add `cleanup:ticket-ai-logs`, schedule it daily at `03:40` with `onOneServer()` and `withoutOverlapping()`, and delete logs older than the configured 7-365 day retention. Test the command against an in-memory log table.

- [ ] **Step 5: Run related backend regression tests and commit**

Run assistant, controller, ticket reply, tenant source, and migration tests. Commit with `feat: make ticket AI tenant-aware and observable`.

### Task 6: Admin UI Capabilities, Connection Test, and Diagnostics

**Files:**
- Modify: `keli-admin/src/services/ticket.ts`
- Create: `keli-admin/src/lib/ticketAi.ts`
- Test: `keli-admin/src/lib/ticketAi.test.ts`
- Modify: `keli-admin/src/pages/user/TicketManage.tsx`
- Modify: `keli-admin/src/pages/user/components/TicketAiSuggestionPanel.tsx`
- Modify: `keli-admin/src/pages/system/config/components/TicketSettings.tsx`
- Modify: `keli-admin/src/locales/zh/translation.json`
- Modify: `keli-admin/src/locales/en/translation.json`

**Interfaces:**
- Consumes backend capability, test, suggestion, and extended-stat responses.
- Produces helper functions `getTicketAiActionState(capabilities)` and `formatTicketAiFailure(code, t)`.

- [ ] **Step 1: Write failing helper tests**

Assert disabled state hides the action, incomplete configuration disables it with a reason, and ready state enables it. Assert normalized provider errors map to concise localized messages.

- [ ] **Step 2: Run and confirm failure**

Run: `npm run test -- src/lib/ticketAi.test.ts`.

- [ ] **Step 3: Implement capability-driven ticket UI**

Fetch capabilities once when the ticket page loads. Hide the button when disabled, disable it with a tooltip when enabled but incomplete, and preserve the existing dialog when ready. Display the resolved scope label and stronger human-review warning in the suggestion panel.

- [ ] **Step 4: Implement settings diagnostics**

Add timeout, maximum output tokens, JSON mode, retention inputs, a connection-test button/status, and 7/30-day statistics controls. Display request success rate, average latency, total tokens, and top failures without adding nested cards.

- [ ] **Step 5: Run frontend tests/build and commit**

Run `npm run test` and `npm run build`. Commit with `feat: improve ticket AI controls and diagnostics`.

### Task 7: Admin Asset Sync, Final Regression, and Delivery

**Files:**
- Generated: `keliboard/public/assets/admin-xboard/**`
- Update docs only when implementation deviates from the approved design.

**Interfaces:**
- Produces synchronized backend/admin commits on `main` and a verified remote state.

- [ ] **Step 1: Build and sync admin assets**

Run `npm run build:xboardpro` in `keli-admin`. Confirm the generated `index.html`, JS, and CSS versions changed in `keliboard`.

- [ ] **Step 2: Run final verification**

Backend: all new AI tests plus ticket, tenant, source, feedback, and reply regressions. Admin: full Vitest suite, TypeScript/Vite build, and `git diff --check` in both repositories.

- [ ] **Step 3: Review the final diff**

Confirm no secret, local log, development server output, theme package, or unrelated user file is staged. Request an independent code review and resolve all critical/important findings.

- [ ] **Step 4: Commit generated panel assets and push**

Commit generated keliboard assets with the backend changes if not already included. Push `keli-admin/main` and `keliboard/main`, then verify each remote branch hash using `git ls-remote`.
