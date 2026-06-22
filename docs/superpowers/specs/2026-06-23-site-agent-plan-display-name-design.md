# Site and Agent Plan Display Names

## Goal

Allow platform multi-sites and agent storefronts to customize the plan name shown to buyers without duplicating the real platform plan or changing entitlement logic.

The platform `Plan` remains the single source of truth for plan identity, traffic, speed, device limit, node groups, renewal behavior, and reset behavior. Site and agent layers may only override the display name and existing per-period sale prices.

## Current Behavior

Multi-site commerce stores site prices in `v2_site_plan_price` by `site_id`, `plan_id`, and `period`. Agent commerce stores agent prices in `v2_agent_plan_price` by `agent_user_id`, `plan_id`, and `period`.

Both storefront layers currently expose the platform plan name from `Plan.name`. This means different sites or agents can have different prices and domains, but buyers still see the same package name everywhere.

## User Experience

Admins can open the multi-site price editor and set a site-specific display name for each platform plan. Empty values inherit the platform plan name.

Agents can open the agent center price editor and set an agent-specific display name for each platform plan. Empty values inherit the resolved upstream name.

The storefront, checkout, order detail, agent subordinate-user flows, and any user-facing plan summaries should use the resolved display name. Internal IDs and business logic continue using the real `plan_id`.

## Name Resolution

Use this precedence:

1. Agent plan display name.
2. Site plan display name.
3. Platform plan name.

This means an agent operating under a multi-site domain can brand a package further. If the agent display name is empty, the agent storefront inherits the site display name. If the site display name is also empty, it falls back to `Plan.name`.

## Data Model

Add two override tables instead of adding duplicated names to every period price row:

`v2_site_plan_override`

- `id`
- `site_id`
- `plan_id`
- `display_name`
- `created_at`
- `updated_at`
- unique key: `site_id`, `plan_id`

`v2_agent_plan_override`

- `id`
- `agent_user_id`
- `plan_id`
- `display_name`
- `created_at`
- `updated_at`
- unique key: `agent_user_id`, `plan_id`

`display_name` is nullable, trimmed, and limited to 120 characters. Empty strings are stored as null or removed from the override table. Override rows do not grant access to plans; they only rename plans that are already visible through existing pricing and allowed-plan rules.

## Backend Behavior

`SiteStorefrontService::listPrices` should return both `plan_name` and `display_name` for each plan group. `display_name` is the site override or the platform name.

`SiteStorefrontService::plansForRequest` should set the resolved display name on the `Plan` resource before it is returned. It must not mutate persisted `Plan.name`.

`AgentStorefrontService::listPrices` should return both `plan_name` and `display_name` for each plan group. The display name should use the agent override, falling back to the site-resolved name when site context exists, then platform name.

`AgentStorefrontService::plansForRequest` should apply the same precedence and set the resolved display name on the returned resource.

`PlanResource` should expose the resolved display name as `name` so existing frontends automatically show the right package label. It may also include `platform_name` for debugging or admin UI context.

## Order Snapshot

When a site or agent order is created, the pricing snapshot should include:

- `display_name`
- `platform_plan_name`
- existing plan id, period, and sale price fields

Old orders without these fields remain valid. New orders should preserve the buyer-facing name used at purchase time, so changing the display name later does not rewrite historical meaning.

## Admin UI

In `keli-admin` multi-site pricing:

- Show platform plan name and ID as reference.
- Add a compact text input named "站点展示名" at the plan group level.
- Keep the current per-period price and enabled controls unchanged.
- Empty input means inherit platform plan name.

## Agent UI

In `keli-user` agent center pricing:

- Show platform or site-inherited plan name as reference.
- Add a compact text input named "代理展示名" at the plan group level.
- Keep the current per-period price and enabled controls unchanged.
- Empty input means inherit the upstream resolved name.

## API Compatibility

Existing save payloads keep accepting period price items. Add an optional plan override payload:

```json
{
  "overrides": [
    {
      "plan_id": 1,
      "display_name": "Light Starter"
    }
  ]
}
```

For compatibility, APIs should tolerate missing `overrides` and keep existing names unchanged.

## Out Of Scope

- Per-period display names.
- Duplicating platform plans per site or per agent.
- Customizing traffic, speed, device limit, node group, reset behavior, or renewal behavior per site or agent.
- Changing subscription node generation.
- Changing payment ownership or balance rules.

## Tests

Backend tests should cover:

- Site display name overrides platform name in guest and user plan APIs.
- Agent display name overrides site display name.
- Empty override falls back to upstream name.
- Site and agent order snapshots record display name and platform plan name.
- Disabled or unpriced plans are still hidden exactly as before.
- Allowed-plan restrictions still apply for agents.

Frontend tests should cover:

- Multi-site pricing editor loads and saves display names.
- Agent pricing editor loads and saves display names.
- Storefront renders the resolved display name from the plan API.

## Rollout

The migrations are additive. Existing sites, agents, prices, and orders continue working because no existing columns are removed and empty override values fall back to the current platform plan name behavior.
