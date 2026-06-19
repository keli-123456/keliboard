# Agent Commerce Unification Design

## Goal

Unify the agent commerce flow so domain attribution, user binding, storefront prices, payment methods, balance checks, and admin visibility all follow one consistent rule set.

This is not a new standalone reseller website system. It is a stabilization and completion pass for the existing agent center, agent domain, agent price, agent payment, and agent order context features.

## Current Context

The codebase already has these foundations:

- Agent self-service:
  - agents can create subordinate users;
  - agents can assign plans, reset traffic, grant bonus days, reset subscriptions, and delete subordinate users;
  - agent operations debit agent balance through the agent center ledger.
- Agent domains:
  - agents can add domains;
  - verified domains are recognized from the request `Host`;
  - reverse proxy snippets require preserving `Host`.
- Agent storefront:
  - agents can configure site settings, landing theme, announcement, support fields, plan sale prices, and payment methods.
- Agent order context:
  - order creation can store agent, domain, sale amount, cost amount, hold, and payment metadata;
  - checkout and payment callback now protect against insufficient agent balance;
  - admin API exposes source and failure reason.

The missing piece is consistency. Store, purchase, order creation, checkout, payment, and admin views must resolve the same agent context and use the same price/payment/hold model every time.

## Definitions

- **Platform context:** no active agent domain and no existing agent binding. User sees platform prices and platform payment methods.
- **Agent domain context:** request host matches an active verified agent domain.
- **Agent binding context:** authenticated user already belongs to an agent, even if current host is the platform domain or another agent domain.
- **Effective agent context:** the single resolved context used for prices, payments, order creation, and tickets.
- **Agent sale price:** the price configured by the effective agent for a plan and period.
- **Platform base price:** the normal platform plan period price.
- **Agent cost:** the amount deducted from the agent balance for the order, calculated from platform base price and platform agent discount rules.

## Unified Rules

### Attribution

1. If an authenticated user is already bound to an agent, that binding wins.
2. If the user is not bound and the current host is an active agent domain, the domain agent becomes the effective agent.
3. A user bound to agent A must not be reassigned to agent B by visiting another agent domain.
4. Guest/public storefront can use current host domain context, but permanent binding only happens when a user registers or places an authenticated order through that context.
5. Tickets, orders, user list context, and admin displays should use the same effective agent fields.

### Pricing

1. Platform context uses platform plan prices.
2. Agent context uses agent sale prices only.
3. If an agent has not configured a sale price for a plan period, that period is not purchasable in agent context.
4. Store and purchase pages must show the same effective prices.
5. Order amount must be based on the effective displayed price, not recalculated from a different source.
6. Agent cost is separate from sale price. The customer may pay the agent sale price, while the platform deducts the agent cost from the agent balance.

### Payments

1. Platform context uses platform payment methods.
2. Agent context uses the effective agent's enabled payment methods.
3. If an agent payment is bound to a specific active domain, it is available only for that domain context.
4. If the user is bound to an agent but currently on the platform host, agent payment methods not restricted to another domain remain available.
5. If no agent payment method is available, checkout must not proceed and the user sees the localized unavailable-payment message.

### Balance Checks

1. At order creation, the effective agent must have enough available balance for the agent cost.
2. At checkout, the hold and effective agent balance must still be valid before payment selection is saved.
3. At payment callback, agent balance is checked again before opening the order.
4. If balance is insufficient at callback, the order remains pending, agent balance is not deducted, hold/context are marked failed, and admin sees a failure reason.
5. Pending holds reduce available balance, but the current order hold is not double-counted during checkout validation.

### Admin Visibility

Admin agent commerce screens should show:

- effective source: domain, user binding, or unknown;
- agent user and domain;
- sale amount, platform base amount, cost amount, discount percent;
- hold status and failure reason;
- payment method and payment owner;
- order status and callback failure reason.

## Recommended Architecture

### Backend

Create or harden a single resolver boundary:

- `AgentCommerceContextResolver`
  - resolves host domain context;
  - resolves authenticated user binding context;
  - returns a normalized effective context with source, agent id, domain id, and domain snapshot;
  - never mutates user ownership while resolving.

Keep pricing and payment behind service boundaries:

- `AgentStorefrontService`
  - lists public/user plans for the effective context;
  - overlays agent sale prices when agent context exists;
  - hides periods without configured agent sale price;
  - resolves the sale price used for order creation.
- `AgentPaymentService`
  - lists enabled payment methods for the effective context;
  - enforces owner/domain restrictions.
- `AgentCommerceService`
  - creates order context and holds;
  - atomically assigns checkout payment;
  - captures or fails agent holds on callback.

The user-facing controllers should not duplicate attribution, price, or payment decisions. They should ask these services for the effective result.

### User Frontend

`keli-user` should consume one consistent backend surface:

- Store and landing plan cards load effective public plans.
- Purchase page loads effective user plans and effective payment methods.
- Agent center remains where agents configure domains, site settings, prices, and payments.
- When agent context has no payment method or no sale price, the UI explains the specific unavailable state without falling back to platform behavior.

### Admin Frontend

`keli-admin` should keep the existing system settings and agent commerce screens, but use them as the unified control plane:

- Agent center settings define platform rules: enable state, unlock mode, discount percent, bonus day price, user limit, allowed plans, reset rules.
- Agent commerce page observes agent domains, payments, holds, and orders.
- Plan management remains the platform catalog. Agent sale prices are configured by agents in the user-side agent center.

## Data Flow

### Guest Storefront

1. Request enters with `Host`.
2. Backend resolves host domain context.
3. If agent domain exists, plans are filtered to agent sale prices.
4. If no agent domain exists, platform public plans are returned.

### Authenticated Purchase

1. Backend resolves effective context:
   - existing user binding first;
   - otherwise active host agent domain.
2. Plan list and payment methods are loaded for that context.
3. Order creation uses the same context and same sale price.
4. If the order is agent-backed, a hold is created for agent cost.
5. Checkout validates the payment method belongs to the correct owner and atomically assigns payment.
6. Payment callback captures hold and deducts agent balance, or marks the context failed.

### User Binding

1. Creating a subordinate user binds that user to the creating agent.
2. Registering through an agent domain binds the new user to that domain's agent when no invite or existing binding overrides it.
3. An existing binding is stable and cannot be overwritten by another agent domain visit.

## Error Handling

- Missing agent sale price:
  - backend: `Agent price is not available`;
  - user UI: "当前代理站暂未配置该套餐价格，请联系站点客服。"
- Missing agent payment method:
  - backend: `This payment method is unavailable.` or empty payment list;
  - user UI: current localized no-payment-method message.
- Insufficient agent balance:
  - backend canonical message remains `The site balance is insufficient. Please contact site support.`;
  - user UI maps this to localized support copy.
- Callback low balance:
  - order remains pending;
  - hold/context become failed;
  - admin sees failure reason.

## Testing Strategy

### Backend PHPUnit

Add focused tests for:

- bound user on platform host uses agent sale prices and agent payments;
- bound user on another agent domain keeps original agent;
- guest on agent domain sees only configured agent sale prices;
- authenticated order creation uses the same sale price shown in purchase;
- missing agent sale price is not purchasable;
- missing agent payment method does not fall back to platform payment;
- existing insufficient-balance creation, checkout, and callback tests still pass;
- admin commerce payload exposes unified source, sale, cost, and failure fields.

### User Frontend

Add/extend tests for:

- plan price display uses backend effective prices;
- purchase errors map agent price/payment/balance failures;
- no payment method state blocks checkout.

### Admin Frontend

Add/extend tests for:

- agent commerce source labels;
- failure reason display;
- settings payload still serializes agent center rules.

Run backend tests on the Linux test machine when local Windows PHP is unavailable.

## Implementation Phases

### Phase 1: Resolver And Backend Consistency

Unify effective context resolution and make all plan/payment/order endpoints use it.

### Phase 2: Storefront And Purchase UX

Ensure `keli-user` store and purchase pages expose the effective agent prices and do not fall back to platform payments in agent context.

### Phase 3: Admin Observability

Fill any missing fields in admin commerce overview and make source/cost/failure states easy to inspect.

### Phase 4: Verification

Run targeted backend tests on the test machine, frontend tests/build locally, then push all repositories.

## Out Of Scope For This Pass

- Full independent reseller website builder.
- Agent-specific coupon system.
- Agent-specific refund/accounting settlement system.
- Agent custom product catalog outside platform plans.
- Automatic DNS/SSL provisioning for agent domains.

These can be added later after the unified commerce core is stable.

