# Agent Order and Finance Operations Design

## Summary

Build an operations layer for the agent storefront commerce flow.

The platform already supports agent domains, agent storefront pricing, agent-owned payment methods, balance holds, payment callback capture, and agent balance deduction. The next step is to make the money movement observable and manageable from both sides:

- agents need to see storefront orders, platform costs, margin, holds, and deductions;
- admins need to see agent financial health, payment/domain readiness, and abnormal orders;
- both sides need clear diagnostics when an order cannot be opened or completed.

This design focuses on visibility, auditability, and safe operational controls. It does not change the existing order pricing or payment capture rules.

## Goals

1. Add an agent-facing order and finance view in `keli-user`.
2. Add an admin-facing agent operations view in `keli-admin`.
3. Expose backend APIs that summarize agent order, hold, payment, and balance state.
4. Make abnormal states easy to identify: insufficient balance, expired pending holds, failed callbacks, amount mismatch, invalid payment ownership, disabled payment, and unavailable domain context.
5. Keep all calculations based on immutable order snapshots and existing ledger records.

## Non-Goals

- Do not let agents edit platform cost formulas.
- Do not let agents install payment plugins.
- Do not implement agent withdrawals.
- Do not implement agent commission payout.
- Do not add a separate independent agent website builder in this phase.
- Do not change current agent-domain order attribution rules.

## Current Context

The commerce flow already has these concepts:

- `AgentDomain` identifies a storefront by request host.
- `AgentStorefrontService` resolves agent prices and order snapshots.
- `AgentCommerceService` creates and captures balance holds.
- `AgentBalanceHold` freezes platform settlement cost while an order is unpaid.
- `AgentOrderContext` records the order's agent, domain, payment, sale amount, and platform cost context.
- `AgentLedger` records balance mutations.
- `AgentCommerceDiagnosticsService` exposes current configuration health and balance summary.

The missing part is a user-friendly operations surface around these records.

## Product Model

### Agent Order Ledger

Agents see only orders attributed to their storefronts or subordinate users.

Each row should show:

- order number;
- buyer email or user id;
- source domain;
- plan and period;
- sale amount paid by customer;
- platform cost charged to the agent;
- gross margin, calculated as `sale_amount - platform_cost`;
- payment method;
- order status;
- hold status;
- ledger capture status;
- created time and paid time.

The agent does not see other agents' orders, global platform orders, or sensitive payment plugin secrets.

### Agent Finance Summary

Agents see a compact summary:

- account balance;
- available balance;
- pending holds;
- paid storefront sales this month;
- platform costs this month;
- estimated margin this month;
- unpaid order count;
- abnormal order count.

The summary is read-only and uses existing order context, hold, and ledger data.

### Admin Operations Overview

Admins see all agents in one operations table.

Each agent row should show:

- agent user id and email;
- active domain count;
- enabled payment count;
- balance;
- available balance;
- pending holds;
- paid storefront sales this month;
- platform costs this month;
- abnormal order count;
- diagnostics status.

Admins can drill into one agent to view domains, payments, recent orders, holds, and ledger entries.

Admin actions in this phase:

- disable or enable an agent payment method;
- disable an agent domain;
- view diagnostics details;
- copy key identifiers for support.

Admins cannot directly rewrite order snapshots, force-capture holds, or silently alter ledger records from this page.

## User Experience

### Agent Center Changes

Add a new tab named `订单财务`.

The tab contains:

1. Summary strip:
   - balance;
   - available balance;
   - pending holds;
   - this month sales;
   - this month cost;
   - this month margin.

2. Status filters:
   - all;
   - pending payment;
   - paid;
   - canceled;
   - failed;
   - abnormal.

3. Order table:
   - compact desktop table;
   - mobile cards;
   - row action opens detail drawer.

4. Detail drawer:
   - order snapshot;
   - domain and payment context;
   - hold lifecycle;
   - ledger entry;
   - failure reason if present.

Use explicit money labels. Avoid vague terms like "income" when the value is sale amount and not withdrawable profit.

### Admin Agent Operations Page

Add an admin page under the existing agent/server/operation area. Suggested menu name: `代理运营`.

The page contains:

1. Health cards:
   - active agents;
   - total pending holds;
   - abnormal orders;
   - agents with insufficient balance;
   - agents with no active payment.

2. Agent table:
   - status;
   - balance;
   - available balance;
   - pending holds;
   - sales/cost/margin this month;
   - domain/payment readiness;
   - diagnostics.

3. Agent detail drawer:
   - domains;
   - payments;
   - recent orders;
   - pending holds;
   - ledger records;
   - diagnostics recommendations.

## Backend Design

### Services

Add `AgentOperationsService`.

Responsibilities:

- build agent finance summaries;
- build agent order lists;
- normalize abnormal status flags;
- map order context, hold, and ledger rows into stable API DTOs;
- keep all money values in cents.

The service reads from existing models and does not mutate order state.

Add `AgentOrderStatusResolver`.

Responsibilities:

- derive `hold_status`;
- derive `capture_status`;
- derive `abnormal_flags`;
- produce customer-safe and admin-detailed reason codes.

Example abnormal flags:

- `insufficient_agent_balance`;
- `hold_expired`;
- `hold_missing`;
- `hold_amount_mismatch`;
- `payment_owner_mismatch`;
- `payment_amount_mismatch`;
- `payment_disabled`;
- `domain_inactive`;
- `callback_failed`;
- `ledger_missing`.

### Agent APIs

Add routes under `/api/v1/user/agent/operations`.

Endpoints:

- `GET /summary`
- `GET /orders`
- `GET /orders/{trade_no}`

`GET /orders` filters:

- `status`;
- `abnormal`;
- `domain_id`;
- `payment_id`;
- `keyword`;
- `date_from`;
- `date_to`;
- `page`;
- `page_size`.

Keyword should match trade number, buyer email, buyer id, token, uuid, and domain.

### Admin APIs

Add routes under `/api/v1/admin/agent/operations`.

Endpoints:

- `GET /summary`
- `GET /agents`
- `GET /agents/{agent_user_id}`
- `GET /agents/{agent_user_id}/orders`
- `POST /payments/{payment_id}/disable`
- `POST /payments/{payment_id}/enable`
- `POST /domains/{domain_id}/disable`

Admin list filters:

- agent keyword;
- diagnostics status;
- insufficient balance;
- no active payment;
- abnormal orders;
- active domain count.

## Data Contract

### Agent Operation Summary DTO

```json
{
  "balance": 100000,
  "available_balance": 85000,
  "pending_hold_total": 15000,
  "month_sales_total": 30000,
  "month_cost_total": 18000,
  "month_margin_total": 12000,
  "pending_order_count": 3,
  "abnormal_order_count": 1
}
```

### Agent Operation Order DTO

```json
{
  "trade_no": "202606200001",
  "buyer_user_id": 123,
  "buyer_email": "buyer@example.test",
  "agent_user_id": 10,
  "agent_email": "agent@example.test",
  "domain": "shop.example.test",
  "plan_name": "Standard",
  "period": "monthly",
  "sale_amount": 1300,
  "platform_cost": 800,
  "margin_amount": 500,
  "payment_id": 5,
  "payment_name": "Alipay",
  "order_status": 0,
  "hold_status": "pending",
  "capture_status": "not_captured",
  "abnormal_flags": [],
  "created_at": 1781880000,
  "paid_at": null
}
```

## Error Handling

Agent-facing copy should be operational but not expose platform internals.

Examples:

- insufficient balance: `站点余额不足，当前订单无法开通。`
- payment unavailable: `当前收款方式不可用，请检查收款配置。`
- domain inactive: `当前代理域名未启用。`
- abnormal order: `订单状态异常，请联系平台管理员。`

Admin-facing details can show exact reason codes and identifiers.

## Security and Permissions

- Agent APIs must scope every query by the current user id.
- Admin APIs require existing admin authorization.
- Agents cannot view platform-owned payment method secrets.
- Agents cannot view other agents' orders, holds, ledgers, domains, or payments.
- Agent order detail must not expose raw callback payloads.
- Admin detail may show sanitized callback metadata but not secret keys.

## Performance

Order and summary queries should avoid loading all rows into memory.

Required indexes should be verified or added where missing:

- `agent_order_context.agent_user_id`
- `agent_order_context.agent_domain_id`
- `agent_order_context.trade_no`
- `agent_balance_hold.agent_user_id`
- `agent_balance_hold.trade_no`
- `agent_balance_hold.status`
- `agent_ledger.agent_user_id`
- `order.trade_no`
- `order.status`
- `order.created_at`

Summary cards should aggregate by database query, not by frontend calculation.

## Testing

Backend tests:

- agent can list only own storefront orders;
- admin can list all agent operations;
- summary reports balance, available balance, pending holds, sales, cost, and margin;
- abnormal flags are derived for missing hold, amount mismatch, payment mismatch, and inactive domain;
- filters work for status, abnormal, domain, payment, keyword, and date range;
- agent cannot access another agent's order detail;
- payment/domain admin toggles affect diagnostics but do not mutate historical snapshots.

Frontend tests:

- agent finance summary formats cents correctly;
- abnormal badges map reason codes to stable labels;
- order filters build stable query params;
- admin agent operations rows map summary values correctly;
- empty, loading, and error states render without hiding the main tabs.

Manual verification:

- create agent-domain pending order;
- confirm pending hold appears in agent summary;
- complete payment callback;
- confirm hold captured, agent balance deducted, ledger visible, order margin visible;
- disable agent payment and confirm diagnostics changes;
- confirm normal platform orders remain unaffected.

## Rollout

Implement in three small phases:

1. Backend read APIs and DTO tests.
2. Agent Center `订单财务` tab.
3. Admin `代理运营` page and safe toggle actions.

Each phase should be independently shippable and pushed to `feature/agent-domain-commerce`.

## Open Decisions

None for this phase. The design intentionally avoids withdrawals, independent agent site builders, and commission payout so the operational layer can ship safely first.
