# Payment Tenant Ownership

## Goal

Tighten payment-method visibility and callback ownership evidence across platform, site, and agent storefront orders.

## Context

Checkout already binds `payment_id` to the order, and payment callbacks verify that the callback payment method matches the bound order payment.
Agent storefronts can also define domain-specific payment methods. Those methods are correctly filtered during checkout, but the selected payment snapshot should keep enough ownership data for diagnostics after the payment row changes or is removed.

## Requirements

- Platform orders must not fall back to agent-domain payment methods just because the current Host is an agent domain.
- Agent order payment-method lookup with a trade number must use the order's original agent domain context, not the current request Host.
- Agent checkout must reject platform payments and payments bound to another agent domain.
- Payment callbacks with a mismatched payment method must not capture agent holds or complete the order.
- Agent payment snapshots must include `owner_domain_id` so domain-specific payment ownership remains visible in order diagnostics.

## Non-Goals

- No payment plugin behavior changes.
- No new payment setting UI.
- No change to the existing site rule that site storefronts inherit enabled platform payment methods.
