# Agent Cost Site Boundary Design

## Goal

Agents should be managed by the platform/main site, while their platform cost can differ by the site they came from. This keeps agent operations consistent and avoids leaking sub-site storefront rules into the agent center, without losing the business need for different wholesale costs.

## Current Problem

The project already has two separate concepts:

- `site_id` on users and orders, used by multi-site storefronts for site-specific prices, display names, announcements, and tenant routing.
- Agent ownership tables, used by the agent center for sub-users, balances, domains, payments, and agent storefront orders.

Using the agent user's `site_id` directly as the cost source is risky because `site_id` affects many unrelated tenant behaviors. A sub-site-origin agent should not have the agent center UI, agent domain, agent announcement, payment setup, or sub-user management constrained by the sub-site. Only the agent's platform cost should vary by origin site.

## Design

Add an explicit agent cost-site boundary:

- Agent center management remains platform-scoped.
- Agent storefront management remains agent-scoped.
- Agent-created subordinate users are bound to the agent, not to the agent's source site.
- Agent platform cost is resolved from a dedicated `cost_site_id` on the agent profile.

## Data Model

Add a nullable `cost_site_id` column to `v2_agent_profile`.

- `null` means platform/main-site cost.
- A positive site ID means use that site's configured plan price as the agent's platform cost.
- The column is independent from `v2_user.site_id`.

When a user applies for agent access:

- If the request resolves to a non-default site, initialize `cost_site_id` to that site ID.
- If the request is from the main site, initialize it to `null`.
- Existing agent profiles keep `null` unless an admin changes them later.

Admin agent management should expose this field as "cost site" so the platform can override it.

## Cost Resolution

Agent order cost calculation should use this order:

1. Resolve the agent profile.
2. Read `cost_site_id`.
3. If `cost_site_id` is set and the site has a valid enabled price for the plan period, use that site price as the cost base.
4. Otherwise fall back to the platform plan price.
5. Apply the global `agent_center_discount_percent` to the selected base amount.

This means site-specific cost can differ per agent, but discount policy remains globally controlled by the platform.

## Storefront And Pricing Boundaries

Agent storefront sale prices remain stored per agent in `v2_agent_plan_price`.

- A buyer's source site should not change an agent's sale price.
- A buyer's source site should not change which agent prices the agent configured.
- Agent domain traffic and agent-bound buyer orders should use agent sale price plus agent cost-site cost.

Normal multi-site storefront purchases that do not have an agent context continue to use site prices, site visible periods, and site display names.

## User Ownership

Agent-created subordinate users should be created with:

- `v2_agent_user.agent_user_id` set to the agent.
- `v2_user.site_id` left as `null` by default.

This prevents the subordinate user from inheriting a random sub-site just because the agent originally registered there. If the platform admin later edits the user and assigns a site, that remains an explicit admin action.

## Orders

Agent orders should keep their current agent order context and balance hold rows.

The order row can still record the buyer's `site_id` when the buyer already has one, but cost calculation must not derive from the buyer site. The pricing snapshot should include:

- `platform_base_amount`
- `cost_amount`
- `discount_percent`
- `cost_site_id`
- `cost_source` as `site` or `platform`

This makes later support and accounting clear.

## Admin UX

Add a cost-site selector in the admin agent detail/edit area.

Recommended labels:

- "成本来源"
- "主站套餐价格"
- "指定分站套餐价格"

The table/detail payload should show the resolved cost source so operators can see why two agents have different costs.

## Error Handling

If the agent cost site exists but does not configure the requested period, the system falls back to the platform price instead of blocking the order.

If neither site nor platform has a valid period price, keep the existing "Period is not available" behavior.

## Tests

Add focused tests for:

- Agent application from a site initializes `cost_site_id`.
- Agent application from platform initializes `cost_site_id` as null.
- Agent cost uses site price when `cost_site_id` has a matching period price.
- Agent cost falls back to platform price when the site period is missing.
- Agent-created subordinate users do not inherit the agent user's `site_id`.
- Normal non-agent site checkout still uses the site's storefront price and period visibility.

## Out Of Scope

This design does not add per-agent custom wholesale price tables. If needed later, that can be layered above `cost_site_id` as an explicit per-agent cost override.
