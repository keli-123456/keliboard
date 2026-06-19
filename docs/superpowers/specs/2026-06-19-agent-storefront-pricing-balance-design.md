# Agent Storefront Pricing and Balance Closure Design

## Goal

Complete the agent storefront commerce loop for per-plan, per-period pricing. Agent users can set their own sale price for each sellable plan period, buyers on agent domains see and pay those prices, and the platform only opens the subscription after confirming the agent has enough balance to cover the platform settlement cost.

This phase focuses on pricing, order snapshots, balance holds, payment ownership, and user-facing failure states. It does not add a separate agent website builder, agent-specific coupons, custom commission rules, or a new payment plugin system.

## Current Foundation

The project already has most of the required infrastructure:

- `v2_agent_plan_price` stores agent prices by `agent_user_id`, `plan_id`, and `period`.
- `v2_agent_order_context` stores the order's agent ownership, sale amount, cost amount, pricing snapshot, domain snapshot, and payment snapshot.
- `v2_agent_balance_hold` reserves agent balance while an order is pending.
- `AgentStorefrontService` lists/saves prices and maps agent-domain plan lists to sale prices.
- `AgentCommerceService` creates agent orders, validates payment ownership, creates holds, captures holds on paid orders, and releases holds on cancellation.
- `keli-user` already exposes agent pricing, domain, payment, site setting, and diagnostics APIs.

The implementation should tighten the existing flow rather than replace it.

## Data Model

Use the existing tables and treat cents as the only storage unit for all agent commerce prices.

`v2_agent_plan_price` is the source of current storefront sale prices:

- `agent_user_id`
- `plan_id`
- `period`
- `sale_price`
- `enabled`

`v2_agent_order_context` is the immutable audit context for created orders:

- `agent_user_id`
- `agent_domain_id`
- `sale_amount`
- `cost_amount`
- `pricing_snapshot`
- `domain_snapshot`
- `payment_snapshot`
- `status`
- `hold_id`

`pricing_snapshot` must include at least:

- `agent_plan_price_id`
- `plan_id`
- `period`
- `sale_price`
- `platform_base_amount`
- `cost_amount`
- `discount_percent`

`v2_agent_balance_hold` reserves the platform cost:

- `amount` equals the platform settlement cost, not the buyer sale price.
- `status` is `pending`, `captured`, `released`, or `failed`.
- `metadata` mirrors enough order context to debug without joining every table.

## Pricing Rules

Agents configure prices per plan and per period. A plan period appears on an agent storefront only when all of these are true:

- the platform plan is sellable;
- the platform period is available and has a positive platform price;
- the agent has an enabled `v2_agent_plan_price` row for that plan and period;
- the sale price is non-negative.

The buyer-facing order amount is the agent sale price. The platform settlement cost is calculated from the platform price and the global `agent_center_discount_percent` setting. Sale price and platform cost are intentionally independent:

- agent sale price controls what the buyer pays;
- platform cost controls what is deducted from the agent balance;
- agent profit/loss is the difference between sale price collected by the agent payment method and platform cost deducted by the platform.

Coupons are not available for agent storefront orders in this phase.

## Order Flow

When a logged-in buyer creates an order through an agent domain:

1. Resolve agent context from request host or existing subordinate ownership.
2. Validate the plan and period using the same platform plan rules.
3. Resolve the agent sale price from `v2_agent_plan_price`.
4. Calculate the platform settlement cost.
5. Lock the agent and buyer rows.
6. Reject if the buyer has an unfinished order.
7. Reject if agent available balance is lower than the cost.
8. Create the order using sale amount as `total_amount`.
9. Create or preserve subordinate ownership.
10. Create a pending `AgentBalanceHold`.
11. Create `AgentOrderContext` with pricing and domain snapshots.

Available agent balance is:

```text
agent.balance - sum(pending holds for the agent)
```

The existing `AgentCommerceService::availableBalance()` remains the single source for this calculation.

## Checkout and Payment Ownership

Agent orders can only use agent-owned payment methods:

- `payment.owner_type = agent`
- `payment.owner_id = agent_user_id`
- if `payment.owner_domain_id` is set, it must match the order's `agent_domain_id`

Platform orders continue to use platform-owned payments only.

During checkout:

1. Load the pending order.
2. Verify selected payment belongs to the order context.
3. Lock the hold and agent.
4. Re-check available balance excluding the current hold.
5. Store payment snapshot on `AgentOrderContext`.
6. Store handling fee on the order.
7. Call the payment plugin with the buyer-facing amount plus handling fee.

## Payment Callback and Balance Capture

On payment callback:

1. Verify callback trade number and payment method.
2. Verify paid amount equals `order.total_amount + order.handling_amount`.
3. Before marking order as processing, call `AgentCommerceService::captureForPaidOrder()`.
4. `captureForPaidOrder()` locks the order context, hold, and agent.
5. If agent balance is insufficient, it throws the site-balance error and the order must not open.
6. If sufficient, deduct the hold amount from the agent balance.
7. Mark hold `captured`.
8. Mark agent order context `paid`.
9. Write `AgentLedger` with type `agent_order_cost`.
10. Continue the existing `OrderService::paid()` flow so plan activation, expiry, and traffic reset use the normal platform logic.

If callback capture fails because the agent balance is insufficient, mark the agent order context and hold as `failed` with the balance error. The buyer-facing status remains not successfully opened.

## Cancellation and Failure

When a buyer cancels a pending agent order:

- set order status to cancelled through the existing order service;
- release the pending hold;
- mark agent order context as cancelled.

When payment callback fails due to amount mismatch, payment mismatch, or plugin verification failure, do not capture the hold. Existing pending orders can still be cancelled manually or by cleanup jobs.

When callback reaches paid handling but agent balance is insufficient, mark the agent context and hold failed so support can see why the order did not open.

## Agent Center UX

The agent pricing interface should clearly show:

- plan name;
- period;
- platform price;
- sale price input in yuan;
- enabled switch;
- save state and validation errors.

Amounts displayed in the UI are yuan. Amounts sent to the API are cents. The UI must avoid double-converting prices, especially the earlier class of bug where `13` was shown as `1300`.

The agent center should add a compact commerce health summary:

- current agent balance;
- available balance;
- pending hold total;
- minimum enabled plan cost;
- maximum enabled plan cost.

This summary helps the agent understand whether buyers can successfully place orders.

## Buyer UX

On an agent domain:

- the store shows only enabled agent-priced periods;
- buyer-facing prices are agent sale prices;
- platform prices are not shown;
- if no payment method is available, show the existing agent payment unavailable message;
- if the site balance is insufficient, show the existing site balance insufficient message;
- pending orders should preserve their original sale price even if the agent later changes pricing.

On the main platform domain:

- storefront prices and payment methods remain platform-owned;
- agent prices must not leak into platform orders.

## API Boundaries

Keep the current API shape where possible:

- `GET /user/agent/prices`
- `POST /user/agent/prices`
- `GET /user/agent/commerce/summary`
- `GET /user/agent/commerce/diagnostics`
- existing plan fetch endpoints with agent storefront mapping by request context
- existing order save and checkout endpoints with agent context resolution

Only add API fields when the current response cannot support the UX requirements, such as available balance summary or pending hold total.

## Error Handling

Use stable backend messages for frontend mapping:

- `The site balance is insufficient. Please contact site support.`
- `Agent price is not available`
- `This payment method is unavailable.`
- `Coupon is not available for agent storefront orders`

Frontend should map these into localized user-facing messages.

Backend should log payment callback failures with `trade_no`, `callback_no`, exception class, and message, without leaking payment secrets.

## Testing

Backend focused tests should cover:

- agent storefront plan list only includes enabled agent-priced periods;
- saving agent prices stores cents exactly once;
- creating an agent order writes sale amount, cost amount, pricing snapshot, hold, and context;
- insufficient available balance rejects order creation;
- checkout rejects platform payments for agent orders;
- checkout rejects another agent's payment method;
- checkout re-checks balance before payment plugin call;
- payment callback captures hold, deducts agent balance, writes ledger, and opens the plan;
- payment callback fails cleanly when agent balance is insufficient after order creation;
- cancelling an agent order releases the hold.

Frontend focused tests should cover:

- agent price conversion between yuan UI and cents API;
- storefront display uses agent prices when agent context exists;
- checkout errors map to agent-specific localized messages;
- agent center summary displays balance, available balance, and pending holds.

## Rollout

This should be implemented behind the existing agent commerce behavior and settings. Existing platform orders and non-agent storefront flows must remain unchanged.

Recommended rollout order:

1. Backend tests for current agent commerce guarantees.
2. Backend fixes for any missing balance, snapshot, payment, or failure edge cases.
3. Agent commerce summary/diagnostics fields if missing.
4. Frontend pricing and summary UX tightening.
5. End-to-end manual test on an agent domain with a low-balance and sufficient-balance agent.

## Non-Goals

- Agent-specific coupons.
- Agent-specific refund automation.
- Agent self-service custom site routing beyond existing domains.
- Multi-level agent pricing.
- Public guest checkout.
- Changing how platform plans activate after successful payment.
