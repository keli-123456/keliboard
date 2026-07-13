# Ticket AI Assistant 2.0 Design

## Goal

Turn the existing ticket draft generator into a tenant-aware, privacy-conscious, observable assistant while preserving the current human-review workflow.

The assistant remains read-only with respect to users, orders, payments, subscriptions, and risk controls. It may only generate a reply draft; an administrator must review and send the final message.

## Chosen Scope

This phase uses a bounded foundation refactor. It is larger than a UI-only patch but deliberately smaller than a full RAG platform or automatic support agent.

### Included

- Resolve the ticket's platform, site, agent, and agent-domain context.
- Build a sanitized operational summary from existing read-only data.
- Remove unnecessary personal identifiers before calling an external AI provider.
- Truncate and redact conversation content before it leaves the panel.
- Parse JSON responses reliably, including fenced JSON and compatible plain-text fallbacks.
- Limit generated output and classify provider failures consistently.
- Record success/failure, latency, token usage, model, administrator, ticket, and tenant scope without storing prompts or provider secrets.
- Expose AI readiness so the admin UI hides the action when the assistant is unavailable.
- Add a connection test and useful operating statistics in system settings.
- Preserve existing suggestion, feedback, edit, and send tracking.

### Deferred

- Automatic replies or unattended actions.
- Vector databases, embeddings, or a new RAG service.
- Agent-managed private knowledge bases.
- Per-site AI provider credentials.
- Automated refunds, bans, subscription resets, order changes, or node changes.
- Provider-specific billing estimates; this phase reports token usage rather than currency.

## Considered Approaches

### 1. Minimal patch

Add site labels to the prompt, remove the email field, and hide the disabled button. This is fast but leaves parsing, diagnostics, rate control, and future context expansion tangled inside `TicketAiAssistantService`.

### 2. Bounded foundation refactor (selected)

Extract tenant context, sanitization, provider calling, and request auditing behind small services. Reuse the current suggestion model and admin workflow. This provides the required safety and observability without introducing an independent AI platform.

### 3. Full AI support platform

Add embeddings, vector search, per-tenant knowledge stores, automatic replies, tool execution, and evaluation pipelines. This has the highest ceiling but is too broad and risky before tenant context and privacy controls are proven.

## Architecture

### `TicketAiContextService`

Builds a stable array for AI use from a ticket and existing services. It resolves:

- scope type: `platform`, `site`, or `agent`
- site code and display name
- agent account ID and verified agent domain
- storefront display name and primary domain when available
- user pseudonym based on internal ID, never email/token/UUID
- plan name, subscription state, expiry, total/used/remaining traffic
- recent order summaries limited to status, plan, period, amount, and timestamps
- subscription risk level, score, reset count, and event types without raw client IPs

The returned structure contains only values required to draft support replies. Missing optional tables or relations degrade to an `available: false` subsection instead of failing the whole request.

### `TicketAiContentSanitizer`

Processes ticket subject, messages, knowledge excerpts, and administrator instructions before provider submission.

- Redacts email addresses, bearer/API tokens, UUIDs, subscription tokens, and common password/secret assignments.
- Caps each message and instruction length.
- Caps the total conversation and knowledge context size.
- Labels user-provided text as untrusted content and instructs the model not to follow commands embedded inside it.
- Preserves ordinary URLs needed for troubleshooting but removes credentials and sensitive query values.

### `TicketAiProviderClient`

Owns the OpenAI-compatible `/chat/completions` request.

- Uses configured base URL, API key, model, temperature, timeout, and maximum output tokens.
- Uses an explicit `JSON response mode` setting, disabled by default for broad provider compatibility. When enabled, it sends the OpenAI-compatible JSON response-format field; otherwise it uses the prompt contract.
- Parses direct JSON, Markdown-fenced JSON, and the first valid JSON object in a response.
- Allows a plain-text draft fallback but marks it as unstructured and forces human review.
- Produces typed provider errors for timeout, connection, authentication, rate limiting, invalid response, and upstream failure.
- Returns latency and token usage reported by the provider.

### `TicketAiRequestLog`

A new lightweight table records one row per generation or connection-test request:

- ticket and administrator IDs when applicable
- scope type, site ID, agent user ID, and agent domain ID
- model and provider host (not API key or full prompt)
- status and normalized error code
- latency, prompt/response character counts, and input/output/total tokens
- creation timestamp

Logs do not store the ticket transcript, administrator instruction, knowledge body, or raw provider response. A scheduled retention rule removes old logs after the configured retention period, defaulting to 30 days.

### Existing `TicketAiSuggestion`

The existing table remains the source for generated drafts and adoption statistics. New scope columns are added so reports remain accurate even if a ticket's ownership changes later. Existing rows remain valid with null scope values.

## Data Flow

1. The admin ticket page requests AI capabilities.
2. The backend returns `enabled`, `configured`, and a safe readiness reason.
3. When generation starts, the controller loads the ticket and delegates authorization and preparation to the assistant service.
4. The context service resolves tenant and operational facts.
5. The sanitizer redacts and bounds all external content.
6. The provider client sends the request and normalizes the response.
7. The request log stores operational metadata for both success and failure.
8. A successful draft is stored in `TicketAiSuggestion` with tenant scope.
9. The admin reviews, inserts, edits, discards, or sends the draft using the existing workflow.

## Multi-Tenant Rules

- Platform tickets use the primary panel brand.
- Site tickets use that site's display name, primary domain, plan display overrides, and site-scoped commerce facts.
- Agent tickets use the agent-domain storefront name and domain. They must not inherit the platform name in generated customer-facing text when an agent brand exists.
- The AI provider and API key remain global administrator settings in this phase.
- Existing knowledge articles are treated as global technical knowledge. Tenant branding and commercial facts always come from resolved tenant settings, never from global article text.
- Tenant-scoped knowledge management is a later phase because the current knowledge schema and UI are global.

## Safety Rules

- The assistant cannot call mutation services or execute tools.
- Payment/refund, account security, bans, privacy, legal issues, and server-wide incidents always require human review.
- Medium/high subscription-risk cases require human review.
- Plain-text or partially parsed provider output requires human review.
- The final reply remains editable, and sending requires the existing explicit admin action.
- Provider errors never fall back to automatic canned replies.

## Admin Experience

### Ticket page

- Hide the AI action when disabled.
- Show a disabled action with a concise readiness reason only when the feature is enabled but incomplete.
- Show generation progress, tenant context summary, risk flag, confidence, matched knowledge, and a clear human-review warning.
- Keep insert, discard, regenerate, and manual instruction controls.

### System settings

- Keep current provider fields and encrypted API key behavior.
- Add maximum output tokens, timeout, log retention, and a connection-test action.
- Show request success rate, average latency, total tokens, failure categories, adoption rate, and human-review rate.
- Statistics default to seven days and support 7/30-day selection.

## Error Handling

- Validation errors return actionable configuration messages.
- Provider authentication and rate-limit errors are shown without exposing response bodies or secrets.
- Timeouts and network failures are normalized for the UI and recorded in request logs.
- Missing optional tenant tables or risk data reduce context quality but do not block draft generation.
- Database failure while writing the suggestion blocks returning an untracked draft; request-log failure does not hide a successfully stored suggestion.

## Testing

### Backend

- Tenant context tests for platform, site, and agent tickets.
- Privacy tests proving email, token, UUID, and password-like values are absent from provider payloads.
- Parser tests for direct JSON, fenced JSON, extracted JSON, plain-text fallback, and malformed responses.
- Provider error mapping and token/latency logging tests.
- Capability/readiness and connection-test controller tests.
- Regression tests for suggestion feedback, edit detection, and manual sending.

### Admin frontend

- Capability helper tests for hidden, unavailable, and ready states.
- AI panel tests for tenant labels, loading, provider errors, insert, discard, and regenerate behavior.
- Settings tests for connection status and statistics formatting.
- Production build and admin-to-panel asset synchronization verification.

## Success Criteria

- No raw email, token, UUID, API key, or password-like value is sent in a tested provider payload.
- Site and agent-domain tickets generate prompts containing the correct tenant brand and never the platform brand when an override exists.
- Every provider call has a success or failure audit row without prompt content.
- Disabled AI has no active ticket-page entry; incomplete configuration shows a clear readiness state.
- Existing manual review, suggestion feedback, and final-send tracking continue to pass.
- Backend and admin frontend tests cover the new behavior and production builds succeed.
