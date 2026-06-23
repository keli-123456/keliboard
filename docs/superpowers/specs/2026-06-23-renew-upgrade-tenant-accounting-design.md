# Renew And Upgrade Tenant Accounting

## Goal

Lock down tenant-aware accounting for auto-renewal and discount-upgrade orders.

## Context

Normal storefront orders already use tenant pricing through `TenantPlanPricingService`, and agent orders reserve platform cost in `v2_agent_balance_hold`.
Auto-renewal and discount-upgrade are higher-risk paths because they do not always start from the visible storefront page:

- Auto-renewal is created by `renew:auto`.
- Discount upgrade is created from an upgrade quote.
- Both paths later complete through `OrderService::paid()`.

## Requirements

- Agent-bound auto-renewal must use the agent sale price and must not create an order when the agent cannot cover platform cost.
- Site-bound auto-renewal must use the site sale price and record a site order context.
- Agent discount-upgrade orders must capture the agent hold and deduct agent balance when paid.
- Agent discount-upgrade orders must release the pending hold when cancelled.
- These guarantees must be covered by regression tests so future pricing changes do not silently fall back to platform pricing.

## Non-Goals

- No UI changes.
- No payment plugin changes.
- No change to tenant pricing precedence.
