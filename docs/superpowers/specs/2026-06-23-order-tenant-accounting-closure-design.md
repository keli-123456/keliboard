# Order Tenant Accounting Closure

## Goal

Close the remaining tenant accounting gap for agent storefront orders when an order is manually marked as paid from the admin panel.

## Context

Agent storefront orders reserve the agent's platform cost with `v2_agent_balance_hold`.
Payment callbacks already re-check the agent balance before opening the order and mark the agent context as failed when the balance is no longer sufficient.

Admin manual paid uses the same `OrderService::paid()` entrypoint, but a failed capture currently only returns a generic failure. The hold can remain pending, which makes the order diagnostics unclear.

## Requirements

- Manual paid must not complete an agent order when the agent balance is below the pending hold amount.
- The order must remain pending.
- The hold and agent order context must be marked failed with the standard insufficient balance message.
- Existing normal payment callback behavior and cancellation release behavior must remain unchanged.

## Non-Goals

- No frontend changes.
- No new admin actions.
- No payment plugin behavior changes.
