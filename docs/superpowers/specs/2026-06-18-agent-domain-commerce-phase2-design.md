# Agent Domain Commerce Phase 2 Design

## Goal

Make agent commerce apply permanently to users who are bound to an agent. Once a user belongs to an agent, every future plan purchase uses the agent storefront price, the agent payment methods, and the agent balance guard, even when the user orders from the main site domain instead of the agent domain.

## Current State

Phase 1 already supports an agent-owned domain flow:

- Admins assign domains to agents.
- Agents configure storefront plan prices.
- Agents configure payment methods backed by platform-enabled plugins.
- Orders created through an agent domain create an `AgentOrderContext`.
- Agent balance is held before checkout and captured after the order is paid.
- Admins can inspect agent domains, payment methods, balance holds, and agent order contexts.

The main gap is that several user flows still resolve agent commerce from the current request domain only. A user who was originally bound to an agent can later use the main domain and see platform prices or platform payment methods. That weakens balance control and makes the business rule unclear.

## Accepted Business Rule

Agent ownership is sticky:

- If the current user is already recorded in `v2_agent_user`, that agent owns the user's future storefront purchases.
- This ownership takes priority over the current request domain.
- If the user is not bound yet and the current host matches an active agent domain, the request uses that domain's agent.
- A user already bound to one agent is never reassigned by visiting another agent domain.
- If no user binding and no agent domain are present, the request stays on the normal platform purchase flow.

This rule applies to plan listing, order creation, payment method listing, checkout validation, and paid-order capture.

## Architecture

Introduce a single agent commerce context resolver in `keliboard`. Existing domain-only resolution stays available, but purchase code should call the commerce resolver so every flow gets the same answer.

The resolver returns:

- `agent_user_id`
- optional `agent_domain_id`
- optional `domain`
- `source`, one of `user_binding` or `domain`
- optional `is_primary`

Priority:

1. If an authenticated user exists and `v2_agent_user.sub_user_id = user.id`, return that binding with source `user_binding`.
2. Otherwise resolve the current host through `AgentDomainResolver`.
3. Otherwise return `null`.

The resolver should validate that the agent profile is still active before prices, order creation, or payment methods are exposed.

## Backend Behavior

### Plan Lists

`AgentStorefrontService::plansForRequest()` should use the unified resolver. Logged-in bound users should receive agent prices on any domain. Guest users can only receive agent prices when the current host is an agent domain, because guests do not have a user binding yet.

Plans without an enabled agent price for any valid period remain hidden in agent storefront mode.

### Order Creation

`AgentCommerceService::createOrderFromRequest()` should use the unified resolver.

When the context comes from a domain and the buyer is not already bound, create `v2_agent_user` and set `invite_user_id` as Phase 1 does today.

When the context comes from an existing user binding:

- Do not change ownership.
- Create the agent order against the bound agent.
- Store `source = user_binding` in the order context snapshot.
- If no domain exists in the request context, store an empty domain snapshot or a snapshot with `source = user_binding`.

Coupon use stays disabled for agent storefront orders unless a later phase explicitly designs agent coupon support.

### Payment Methods

`AgentCommerceService::agentUserIdForPaymentMethods()` should use the unified context when no order context can be found by `trade_no`.

Payment method listing must show only enabled agent-owned payment methods for bound users. Platform payment methods remain available only for normal platform orders.

Checkout must continue to validate that the selected payment method belongs to the same agent as the order context.

### Balance Guard

Order creation keeps the existing pre-check and hold:

- Calculate platform cost from the platform plan price and the configured agent discount percent.
- Require the agent's available balance to cover the cost.
- Create a pending balance hold.

Paid-order capture keeps the existing second guard:

- Lock the order context, hold, and agent user.
- If the hold is already captured, return idempotently.
- If the hold is missing or not pending, fail.
- If the agent balance is now below the hold amount, fail the capture and prevent subscription opening.
- Deduct the agent balance, mark the hold captured, mark the context paid, and create a ledger row.

Order cancellation or failed abandoned orders should release pending holds through the existing release path.

## User Experience

### `keli-user`

For users bound to an agent:

- Store and purchase pages show agent sale prices returned by the API.
- Payment method selection shows only that agent's enabled payment methods.
- Agent-domain users and main-domain bound users should have the same purchase behavior.
- Error messages for insufficient agent balance should be human-friendly, for example: "Site balance is insufficient. Please contact site support."

No major new page is required in this phase. The page work is mostly verification and small copy adjustments if the API exposes the new source field.

### `keli-admin`

The existing Agent Commerce page remains the monitoring surface.

Add source visibility where useful:

- Order context rows show whether the order came from `domain` or `user_binding`.
- Domain fields remain blank or muted for user-binding orders created from the main site.

Admin should still never see agent payment secrets.

## Data Snapshots

`AgentOrderContext` and hold metadata should preserve enough context for support:

- `source`
- `agent_user_id`
- optional `agent_domain_id`
- optional `domain`
- plan id
- period
- sale price
- platform base amount
- cost amount
- discount percent

The context should be historical. Later changes to agent prices, domains, or discount percent must not rewrite existing order records.

## Error Handling

- Agent profile inactive: do not expose agent storefront prices or payment methods; order creation fails with an agent permission message.
- Agent price missing for requested period: order creation fails before creating any order.
- Agent balance insufficient before order creation: fail without creating order, hold, or context.
- Agent balance insufficient during paid callback: fail capture and do not open the subscription.
- Selected payment method belongs to another owner: checkout fails.
- Existing user binding conflicts with current domain agent: keep the existing user binding.

## Testing Strategy

Backend unit coverage should include:

- A bound user on the main domain sees agent storefront prices.
- A bound user on the main domain creates an agent order with source `user_binding`.
- A bound user on the main domain sees agent payment methods.
- A bound user visiting another agent domain keeps the original agent.
- Insufficient balance on a bound-user order fails before order creation.
- Capture still deducts balance and is idempotent.

Frontend coverage should include helper-level tests for source display and money formatting. Full UI tests are optional for this phase unless behavior changes require new page state.

## Rollout

This phase is backward compatible:

- Existing agent domains keep working.
- Existing bound users gain sticky agent commerce behavior.
- Existing normal users stay on platform pricing and platform payment methods.

Deploy backend first, then `keli-user`, then `keli-admin`. The backend change is the source of truth; frontend changes should only display and consume returned data.
