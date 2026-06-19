# Agent Commerce Diagnostics Design

## Goal

Give agents a clear self-service diagnostics panel that explains why their storefront users can or cannot place orders.

The feature is read-only. It does not change checkout, payment callback, balance hold, or order-opening logic. It surfaces the rules already enforced by agent commerce: agent sale prices, agent payment methods, verified domains, and available agent balance.

## User Problem

Agents can now configure domains, site settings, prices, and payment methods. When a subordinate user cannot buy, the failure can come from several places:

- no enabled agent payment method;
- payment method is bound to a different domain;
- a plan period has no enabled agent sale price;
- agent balance is lower than the platform cost;
- a domain is pending, disabled, or deleted.

Today these rules are correct but not obvious. Agents need a single place that says what is ready, what is risky, and how to fix it.

## Scope

### In Scope

- Backend read-only diagnostics endpoint for the authenticated agent.
- Frontend diagnostics card/section inside `keli-user` agent center.
- Summary status for payments, prices, domains, and balance.
- Actionable messages that point agents to the existing tabs: domains, prices, payments, and balance/recharge.
- Tests for diagnostic calculations and frontend display helpers.

### Out Of Scope

- Changing checkout behavior.
- Changing payment callback behavior.
- Automatic repair or auto-enabling settings.
- Admin-side diagnostics.
- Agent-specific coupons, refunds, settlement, or custom product catalogs.

## Backend Design

Add a read-only endpoint:

`GET /api/v1/user/agent/commerce/diagnostics`

The endpoint is available only to active agents. If the user is not an active agent, it returns the existing agent permission error used by other agent center APIs.

### Service

Create or extend an agent commerce diagnostics service with one responsibility: inspect current agent commerce configuration and return normalized checks.

Suggested service:

`App\Services\AgentCommerceDiagnosticsService`

Responsibilities:

- load active agent profile;
- load agent domains;
- load agent prices;
- load agent payments;
- compute available agent balance using existing `AgentCommerceService::availableBalance()`;
- compute plan cost using existing platform plan prices and agent discount settings;
- return diagnostics without mutating data.

### Response Shape

The response should be stable and easy for the frontend to render:

```json
{
  "overall_status": "ok",
  "summary": {
    "domains_total": 1,
    "active_domains": 1,
    "enabled_payments": 2,
    "priced_periods": 6,
    "missing_price_periods": 3,
    "balance": 10000,
    "available_balance": 8000,
    "minimum_cost": 500
  },
  "checks": [
    {
      "key": "payments",
      "status": "ok",
      "title": "收款方式正常",
      "message": "当前有 2 个启用收款方式。",
      "action": "payments"
    }
  ],
  "domains": [
    {
      "id": 1,
      "domain": "shop.example.com",
      "status": "active",
      "available_payment_count": 1,
      "issues": []
    }
  ],
  "plans": [
    {
      "plan_id": 1,
      "plan_name": "标准套餐",
      "configured_periods": ["monthly"],
      "missing_periods": ["yearly"],
      "minimum_cost": 500,
      "issues": ["missing_prices"]
    }
  ]
}
```

### Status Rules

Use three statuses:

- `ok`: users should be able to buy at least one configured product with at least one available payment method and sufficient available balance.
- `warning`: the storefront can work, but some plans, periods, domains, or payments are incomplete.
- `blocked`: users cannot complete a normal agent storefront order.

Overall status should be the worst status among checks.

### Checks

#### Domain Check

`blocked` when the agent has no active domain and no existing subordinate user context is useful for storefront operation.

`warning` when there are pending/disabled domains.

`ok` when at least one active domain exists.

#### Payment Check

`blocked` when there are no enabled agent payment methods.

`warning` when enabled payments exist but all are bound to domains that are not active.

`ok` when at least one enabled payment is globally available or bound to an active domain.

#### Price Check

`blocked` when no enabled agent sale price exists for any sellable plan period.

`warning` when at least one enabled price exists but some sellable plan periods are missing.

`ok` when all sellable allowed plan periods have enabled agent prices.

#### Balance Check

`blocked` when available balance is lower than the minimum platform cost among configured sale periods.

`warning` when available balance is enough for the minimum cost but lower than some configured products.

`ok` when available balance covers all configured products.

If there are no configured sale periods, the balance check should be `warning`, not `blocked`; the price check is already responsible for the blocker.

## Frontend Design

Add a diagnostics section inside `keli-user/src/pages/AgentCenterPage.tsx`.

The section should be near the top of the commerce area, before detailed configuration tables. It should be scan-friendly:

- one overall status card;
- four compact check cards: domains, payments, prices, balance;
- a short issue list with action buttons that jump to existing tabs/sections.

Do not create a separate route for the first version. Keep it in the agent center because agents are already configuring domains, prices, and payments there.

### UI Behavior

- Green: ready.
- Yellow: partially configured.
- Red: blocked.
- Each issue has one clear action:
  - domains -> go to domain section;
  - payments -> go to payment section;
  - prices -> go to price section;
  - balance -> go to recharge/balance section if available, otherwise show instruction text.

### Copy Style

Use direct operational copy:

- "当前没有启用的代理收款方式。"
- "年付周期未设置代理售价。"
- "可用余额不足以覆盖最低套餐成本。"
- "这个域名未验证，绑定到它的收款方式不会出现在下单页。"

Avoid abstract terms such as "configuration invalid" without telling the agent what to fix.

## Error Handling

- If diagnostics endpoint fails, show a non-blocking warning in the diagnostics card and keep the rest of the agent center usable.
- If the user is not an active agent, preserve the existing locked state.
- Empty arrays are valid data, not transport errors.

## Testing

### Backend

Add PHPUnit tests for:

- no enabled payments -> payment check blocked;
- enabled payment bound to inactive domain -> payment check warning;
- no enabled sale prices -> price check blocked;
- partially configured prices -> price check warning;
- available balance lower than minimum configured cost -> balance check blocked;
- healthy configuration -> overall status ok.

### Frontend

Add Vitest tests for diagnostics helpers:

- worst-status aggregation;
- status tone mapping;
- issue action labels;
- money formatting for balance/minimum cost summaries.

Build verification:

- `keli-user`: `npm run test -- agentDiagnostics` and `npm run build`.
- `keliboard`: targeted PHPUnit on the Linux test machine if local PHP is unavailable.

## Rollout

This can ship safely behind the existing agent center availability. No database migration is required.

The first version should be read-only diagnostics. If agents later need one-click repair, that should be a separate feature with explicit confirmation and tests.
