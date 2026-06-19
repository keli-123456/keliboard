# Agent Lightweight Site Isolation Design

## Goal

Give each agent storefront a consistent lightweight identity without rebuilding the whole platform. When a visitor arrives through an agent domain, or when a logged-in user already belongs to an agent, the user-facing site should use that agent's site settings and announcement context. Tickets should continue to record agent ownership so support can be filtered and audited later.

This phase intentionally stays narrow: make the site feel like the agent's site, and keep ownership metadata consistent. It does not add agent-owned payment plugins, custom pricing rules, or a full independent reseller portal beyond what already exists.

## Current State

- `AgentCommerceContextResolver` already resolves agent context by logged-in subordinate user first, then by active agent domain.
- `AgentDomainResolver` already identifies active agent domains from `Host`.
- `v2_agent_site_setting` and `AgentSiteSettingService` already support default and per-domain agent site settings.
- `keli-user` already has an Agent Center site settings tab where an agent can set site name, logo URL, landing theme, accent color, support name, support URL, and announcement.
- Ticket creation already passes `agent_context` into `TicketService::createTicket()`, and `TicketService` writes `agent_user_id` / `agent_domain_id`.
- `/user/notice/fetch` currently returns only global visible notices and does not account for agent context.
- Public/user config still needs a clear, reusable agent-site payload so frontend pages can consistently apply agent branding and support metadata.

## Scope

Included:

- Add a read-only agent site context payload service for user-facing APIs.
- Return effective agent site settings for the current request and user.
- Allow `/user/notice/fetch` to prepend or merge the effective agent announcement with normal notices.
- Keep ticket creation ownership behavior intact and add focused tests proving agent context is written for domain and user-binding cases.
- Add frontend service/type support for the effective agent site payload where needed.
- Keep the user experience non-breaking: if no agent context or no agent setting exists, existing global behavior remains unchanged.

Excluded:

- Agent-managed custom payment plugin enablement beyond existing payment configuration.
- Agent custom public pricing beyond existing agent price settings.
- Agent-specific email sender identity.
- Agent-specific knowledge base content.
- Staff/admin UI filtering for tickets and notices. This will be a later phase after the data path is stable.
- A separate public custom domain provisioning flow. Existing agent domain verification remains the source of truth.

## Effective Context Rules

Use `AgentCommerceContextResolver` as the single source for storefront ownership:

1. If the logged-in user is an agent subordinate, use that user's agent ownership.
2. Otherwise, if the request `Host` matches an active agent domain, use that domain.
3. Otherwise, there is no agent context and APIs return global behavior.

This priority prevents a logged-in subordinate from accidentally switching ownership when browsing through another agent's domain.

## Effective Site Settings

Create or extend a small service method that returns an effective site payload:

- Input: current `Request` and optional `User`.
- Resolve agent context.
- If context has `agent_domain_id`, try the matching per-domain `AgentSiteSetting`.
- If no enabled domain-level setting exists, fall back to the agent default setting where `agent_domain_id = null`.
- If no enabled setting exists, return `null`.

Payload:

- `enabled`: boolean
- `agent_user_id`
- `agent_domain_id`
- `source`
- `domain`
- `site_name`
- `logo_url`
- `landing_theme`
- `accent_color`
- `support_name`
- `support_url`
- `announcement`
- `seo_title`
- `seo_description`

All string fields should be normalized to strings or `null`; disabled settings should not override global site data.

## API Changes

### `/user/agent/site-context`

Add a user-authenticated endpoint returning the effective site payload for the current request:

- No agent context: `{ "site": null }`
- Agent context with setting: `{ "site": { ... } }`

This endpoint is read-only and should not reveal sensitive payment or internal agent data.

### `/user/notice/fetch`

Extend current notice fetch behavior:

- Continue returning global notices as it does today.
- If an effective agent setting has a non-empty `announcement`, include it as a synthetic notice at the top of the current page.
- The synthetic notice should have a stable shape compatible with current frontend notice rendering:
  - `id`: string such as `agent-announcement`
  - `title`: site name or support name fallback
  - `content`: announcement
  - `show`: true
  - `created_at`: current setting update time if available
  - `agent_context`: true

Pagination should remain simple: page size stays 5, and the agent announcement counts as one item only when present. Global notices remain unchanged in storage.

## Ticket Ownership

Keep the existing `TicketController::save()` and `TicketService::createTicket()` ownership path. Add tests around it instead of redesigning it:

- Creating a ticket through an agent domain writes `agent_user_id` and `agent_domain_id`.
- Creating a ticket as an already-bound subordinate on the main domain writes `agent_user_id` and leaves `agent_domain_id` null.
- User ticket fetch remains scoped to `user_id`, so users cannot see other users' tickets.

## Frontend Behavior

Add a small `agentSiteContext` service in `keli-user`:

- GET `/user/agent/site-context`
- Type mirrors backend effective site payload.

Use it where user-facing shell data is already loaded, preferably near existing config/user bootstrap code rather than scattering one-off requests across pages. The first visual use should be conservative:

- Prefer effective `site_name` in user-facing labels where the app already uses app name.
- Use `announcement` in notice/help surfaces through the updated notice API rather than duplicating it manually.
- Do not remove global fallback branding.

The Agent Center settings page remains the editing surface; this phase does not add another settings UI.

## Error Handling

- If site context resolution fails, API returns global behavior rather than breaking dashboard or notices.
- Invalid or disabled agent settings do not override global values.
- If the synthetic agent notice cannot be built, skip it and return global notices.
- Frontend failures to load site context should be silent or low-priority; users can still use the site.

## Testing

Backend:

- Effective site context returns null without agent context.
- Effective site context returns default agent setting for subordinate user.
- Effective site context returns domain-level setting for agent domain.
- Domain-level setting falls back to default setting if disabled or missing.
- Notice fetch includes synthetic agent announcement when context has announcement.
- Notice fetch remains unchanged for normal users.
- Ticket creation stores agent ownership for domain and user-binding contexts.

Frontend:

- Site context service unwraps typed payload.
- Notice UI continues to handle normal notice rows and synthetic agent announcement rows.
- Existing Agent Center site settings build/save helpers continue to pass tests.

## Rollout

1. Backend effective site context service and endpoint.
2. Notice fetch synthetic agent announcement.
3. Ticket ownership regression tests.
4. Frontend site context service/types.
5. Frontend minimal consumption where app shell already loads user-facing context.

## Risks

- Overriding too much branding too early could surprise existing users. This phase limits active frontend changes and uses global fallbacks.
- Synthetic notices must stay compatible with current notice rendering. Keep the shape simple and covered by tests.
- Agent context precedence must remain user-binding first to avoid cross-agent ownership changes.
