# Agent Domain And Payment Design

## Goal

Extend the Agent Center from manual subordinate management into an agent storefront model.

An admin assigns one or more domains to an agent. Any user who enters through an assigned domain is attributed to that agent, sees the agent storefront pricing, pays through that agent's own payment configuration, and receives normal platform subscription service after the platform deducts the agent cost from the agent balance.

The hard business rule is: if the agent does not have enough available balance for the platform cost, the user cannot create an order.

## Confirmed Decisions

- Agent domains are assigned by the platform admin.
- Agents may reverse proxy their assigned domain to the main user site.
- Domain attribution is based on the HTTP `Host` seen by `keliboard`.
- Payment plugins remain platform-managed. If a payment plugin is enabled by the platform, agents may create their own payment method using that plugin.
- Agents cannot upload or install payment plugins.
- Agent payment method configuration reuses the existing payment plugin form contract.
- Users under an agent domain pay the agent's payment account.
- Platform subscription delivery remains owned by `keliboard`.
- Agent balance is required before order creation. Insufficient balance blocks order creation.
- Order creation freezes the agent cost. Payment success captures the freeze and deducts the real balance.

## Product Scope

### In Scope

- Admin can bind domains to active agents.
- `keliboard` resolves the current agent from request host.
- Registration and purchase through an agent domain bind the user to that agent when the user is not already owned by another agent.
- Agent can view assigned domains in `keli-user`.
- Agent can create, edit, enable, disable, and delete its own payment methods.
- Agent payment methods use enabled payment plugins only.
- Agent can configure storefront prices for allowed plans and periods.
- Agent-domain users see agent storefront prices.
- Agent-domain users see only that agent's enabled payment methods.
- Order creation checks and freezes the agent platform cost.
- Payment callback captures the frozen cost before completing the platform order.
- Cancelled or expired orders release frozen agent balance.
- Admin and agent can audit agent order context, cost, sale amount, balance holds, and ledger entries.

### Out Of Scope For This Phase

- Multi-level agents.
- Agent-owned theme upload.
- Agent payout settlement from platform to agent.
- Tax invoices.
- Manual payment proof upload.
- Letting agents add domains by themselves.
- Letting agents use disabled or missing payment plugins.
- Reassigning users between agents from the user side.

## Domain Attribution

Create a backend service, `AgentDomainResolver`, that normalizes the request host and resolves it against active agent domains.

Resolution rules:

1. Strip port from the host.
2. Lowercase and punycode-normalize where PHP support is available.
3. Match an enabled row in `v2_agent_domain`.
4. Return agent profile, agent user id, domain id, and public storefront metadata.
5. If no domain matches, the request is a normal platform request.

Ownership rules:

- New registrations through an agent domain become subordinates of that agent.
- Existing users with no agent ownership become subordinates when they create their first agent-domain order.
- Existing users already owned by an agent are not reassigned by simply visiting another agent domain.
- Admin reassignment is a future admin-only operation.

Reverse proxy requirement:

- The proxy must preserve the original `Host` header.
- The platform should show agents a short reverse proxy example, but the system does not manage their Nginx.

## Data Model

### `v2_agent_domain`

Stores domains assigned to agents.

- `id`
- `agent_user_id`
- `domain`
- `status`: `active`, `disabled`
- `is_primary`
- `remark`
- `created_by_admin_id`
- `created_at`
- `updated_at`

Indexes:

- unique `domain`
- index `agent_user_id`
- index `status`

### `v2_payment` Extension

Reuse the existing payment method table for platform and agent payment methods.

Add:

- `owner_type`: `platform`, `agent`
- `owner_id`: nullable integer, agent user id when owner is `agent`
- `owner_domain_id`: nullable integer, optional domain-specific payment method

Compatibility:

- Existing rows migrate to `owner_type = platform`, `owner_id = null`.
- Existing admin payment UI only manages platform-owned rows.
- Agent payment UI only manages rows where `owner_type = agent` and `owner_id = current user id`.

### `v2_agent_plan_price`

Stores agent storefront sale prices.

- `id`
- `agent_user_id`
- `plan_id`
- `period`
- `sale_price`
- `enabled`
- `created_at`
- `updated_at`

Unique key:

- `agent_user_id`, `plan_id`, `period`

Rules:

- `sale_price` is in cents.
- Period must exist on the plan.
- Plan must be allowed by agent center settings.
- If no agent price exists, the plan period is hidden for that agent storefront in the first phase.

### `v2_agent_balance_hold`

Freezes agent balance when an order is created.

- `id`
- `agent_user_id`
- `order_id`
- `trade_no`
- `amount`
- `status`: `pending`, `captured`, `released`, `expired`
- `expires_at`
- `captured_at`
- `released_at`
- `metadata`
- `created_at`
- `updated_at`

Indexes:

- unique `order_id`
- unique `trade_no`
- index `agent_user_id`, `status`

Available balance:

`available_agent_balance = user.balance - sum(pending holds for that agent)`

### `v2_agent_order_context`

Stores the immutable agent commerce snapshot for a platform order.

- `id`
- `order_id`
- `trade_no`
- `agent_user_id`
- `agent_domain_id`
- `payment_id`
- `sale_amount`
- `cost_amount`
- `hold_id`
- `status`: `pending`, `paid`, `cancelled`, `failed`
- `pricing_snapshot`
- `domain_snapshot`
- `payment_snapshot`
- `created_at`
- `updated_at`

This table avoids overloading `v2_order` with agent-specific fields while keeping a stable audit trail.

## Pricing Model

There are two prices:

- Sale price: what the subordinate user pays to the agent.
- Platform cost: what the platform deducts from the agent balance.

First implementation:

- Agent sale price comes from `v2_agent_plan_price`.
- Platform cost reuses the current agent assignment pricing rules:
  - validate plan and period;
  - load base platform plan price;
  - apply `agent_center_discount_percent`;
  - include configured bonus-day cost only when the purchase flow explicitly supports bonus days.

The backend calculates both values. Frontend price display is informational only.

## Order Flow

### Create Order

For a user under an agent domain:

1. Resolve agent from host.
2. Resolve the selected plan and period.
3. Resolve agent sale price.
4. Calculate platform cost.
5. Start database transaction.
6. Lock the agent user row.
7. Calculate available agent balance.
8. If available balance is below cost, return a validation error and do not create an order.
9. Create normal `v2_order` with `total_amount = sale price`.
10. Create `v2_agent_balance_hold` for the platform cost.
11. Create `v2_agent_order_context`.
12. Bind user to agent if not already owned.
13. Commit.

Error copy should be customer-safe, for example: "The site balance is insufficient. Please contact site support."

### Checkout

For an agent order:

1. Payment methods endpoint returns only enabled payment rows owned by the order agent.
2. Checkout accepts only payment ids owned by the same agent.
3. Existing `PaymentService` uses the selected agent payment config.
4. Notify URL remains `/api/v1/guest/payment/notify/{method}/{uuid}`.

### Payment Callback

On payment notify:

1. Existing payment plugin verifies the callback.
2. Existing callback code finds the order by `trade_no`.
3. Verify callback payment id matches `order.payment_id`.
4. Verify paid amount equals sale price plus handling fee.
5. If the order has agent context, capture the pending hold:
   - lock order, hold, and agent user row;
   - ensure hold is pending and belongs to the order;
   - deduct `hold.amount` from agent user balance;
   - mark hold captured;
   - write an agent ledger row;
   - mark agent order context paid.
6. Complete normal `OrderService::paid(...)`.

Because the cost was frozen at order creation, a successful callback should not fail due to insufficient balance. If the hold is missing or invalid, the callback must not open the subscription.

### Cancel And Expire

When an order is cancelled:

1. Cancel normal order.
2. Release pending agent balance hold.
3. Mark agent order context cancelled.

When an order expires:

1. A scheduled cleanup releases pending holds for expired pending orders.
2. The same release path is reused by manual cancel.

## Payment Method Rules

Agent payment methods are regular `v2_payment` rows owned by the agent.

Allowed methods:

- Use `PaymentService::getAllPaymentMethodNames()`.
- This list comes from enabled payment plugins.
- If the plugin is disabled later, the agent method is not usable even if the row remains enabled.

Agent permissions:

- Agent can manage only its own payment methods.
- Agent cannot edit platform payment methods.
- Agent cannot edit another agent's payment methods.
- Agent cannot set `owner_type`.
- Agent cannot force a disabled plugin to be used.

Admin permissions:

- Admin can see platform methods in the existing payment page.
- Admin can view agent methods from agent detail or a future agent commerce page.
- Admin can disable a problematic agent payment method.

## API Design

### User Agent APIs

Add under `/api/v1/user/agent`:

- `GET /domains`
- `GET /payment-methods/available`
- `GET /payments`
- `POST /payments/form`
- `POST /payments`
- `POST /payments/{id}`
- `POST /payments/{id}/toggle`
- `POST /payments/{id}/delete`
- `GET /prices`
- `POST /prices`
- `GET /commerce/summary`

### Admin APIs

Add under `/api/v2/admin/agent` or the existing admin route grouping:

- `GET /domains`
- `POST /domains`
- `POST /domains/{id}`
- `POST /domains/{id}/disable`
- `POST /domains/{id}/enable`
- `POST /domains/{id}/delete`
- `GET /commerce/orders`
- `GET /commerce/holds`
- `GET /commerce/payments`

### Existing User APIs To Adjust

- `GET /user/order/getPaymentMethod`
  - return platform payments for normal requests;
  - return current agent payments for agent-domain order/payment flows.

- `POST /user/order/save`
  - resolve agent domain;
  - apply agent sale price;
  - create balance hold before returning trade number.

- `POST /user/order/checkout`
  - enforce payment ownership for agent orders.

- `GET /user/plan/fetch`
  - return agent sale prices when request is under an agent domain.

## Frontend Design

### `keli-user`

Agent Center gains three sections:

- Domains
  - show assigned domains;
  - show reverse proxy note;
  - show whether the current domain is active.

- Storefront Prices
  - list allowed plans and periods;
  - agent sets sale price;
  - disabled or unpriced periods are hidden from agent storefront.

- Payment Methods
  - list agent-owned methods;
  - create/edit dialog using payment plugin form schema;
  - copy notify URL;
  - enable/disable.

Store and Purchase pages:

- show agent storefront prices when domain context exists;
- show only agent-owned payment methods;
- show a clear unavailable state when the agent has no enabled payment method;
- show insufficient site balance message if backend rejects order creation.

### `keli-admin`

Add an Agent Commerce area or extend the existing Agent settings:

- assign domains to agent users;
- view domain status;
- view agent payment method list;
- view agent order context;
- view pending holds;
- disable an agent payment method if needed.

## Backend Components

- `AgentDomainResolver`
  - resolves host to agent domain.

- `AgentCommerceService`
  - calculates sale price and cost;
  - creates orders and holds;
  - captures and releases holds;
  - writes agent ledger rows.

- `AgentPaymentService`
  - wraps payment method CRUD for agents;
  - validates enabled plugin methods;
  - reuses `PaymentService::form()`.

- `AgentStorefrontService`
  - returns agent-priced plan data;
  - hides unavailable periods.

Keep `AgentCenterService` focused on subordinate user operations. Commerce should be a separate service so the current agent center does not become too large.

## Error Handling

Customer-facing errors:

- Site balance is insufficient. Please contact site support.
- This payment method is unavailable.
- This plan is unavailable on this site.
- This order cannot be paid.

Agent-facing errors:

- Available balance is insufficient for this order.
- This payment plugin is not enabled by the platform.
- This domain is not assigned to your account.
- This plan period is not available.

Admin-facing logs:

- agent domain resolution failures;
- agent payment ownership mismatches;
- missing or invalid balance hold on callback;
- payment callback amount mismatch;
- hold release/capture failures.

## Security And Consistency

- Backend recalculates sale price and platform cost.
- Agents cannot use arbitrary plugin code.
- Agents cannot edit payment rows they do not own.
- Payment callback is tied to payment UUID, not request host.
- Payment ownership is verified against the order before checkout and callback completion.
- Agent balance availability includes pending holds.
- Hold capture is idempotent.
- Duplicate payment callbacks do not double deduct balance.
- User ownership is immutable in this phase.
- All money values are cents.

## Testing Plan

Backend tests:

- Domain resolver normalizes host and ignores port.
- Registration through agent domain creates ownership.
- Existing owned user is not reassigned by another domain.
- Agent payment CRUD rejects disabled plugin methods.
- Agent cannot edit another owner's payment method.
- Agent order creation fails before order creation when available balance is insufficient.
- Agent order creation creates order, hold, and context when balance is sufficient.
- Concurrent order creation cannot overspend available balance.
- Checkout rejects payment methods not owned by the order agent.
- Payment callback captures hold and deducts agent balance once.
- Duplicate callback is idempotent.
- Cancelled order releases pending hold.
- Expired pending order releases pending hold.

Frontend tests:

- Agent payment form renders plugin fields.
- Agent price settings save sale prices in cents.
- Store page uses agent prices under agent domain context.
- Purchase page lists only agent payment methods for agent orders.
- Insufficient balance errors are displayed clearly.

Manual verification:

- Configure an agent domain through admin.
- Reverse proxy that domain preserving Host.
- Register a user through the agent domain.
- Configure an agent payment method.
- Set storefront prices.
- Create order with insufficient balance and confirm no trade number is created.
- Recharge agent balance.
- Create and pay an order.
- Confirm subordinate plan opens and agent balance is deducted by cost, not sale price.

## Rollout Plan

1. Database migrations and model casts.
2. Backend domain resolver and admin domain APIs.
3. User registration/order attribution.
4. Agent payment method backend APIs.
5. Agent price backend APIs.
6. Agent order creation with balance holds.
7. Checkout and callback ownership/capture changes.
8. `keli-user` domain, price, and payment management UI.
9. Store and purchase page agent-domain adaptations.
10. `keli-admin` domain and commerce oversight UI.
11. Full regression tests and manual end-to-end verification.

## First Implementation Slice

The first build should stop after these are working:

- admin binds an active domain to an agent;
- user registration through that domain creates subordinate ownership;
- agent can configure a payment method using an enabled payment plugin;
- agent can set sale prices;
- order creation under the agent domain checks available agent balance and creates no order when balance is insufficient;
- sufficient balance creates order plus hold;
- payment success captures the hold and opens the subscription.

This slice proves the full model without adding reports or advanced admin tooling first.
