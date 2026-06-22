# Platform Multi-Site Commerce Design

## Goal

Continue the first-party multi-site work so one `keliboard` installation can serve several branded storefront domains with different prices while sharing one backend, one node pool, one subscription system, and one operational panel.

The business goal is to replace several independent storefront deployments with one maintained platform. Nodes only need to dock to the main panel once. Each storefront domain can still present its own name, logo, landing theme, announcement, support information, payment availability, and plan prices.

## Scope

This phase connects the existing site tenant foundation to user-facing commerce:

- Resolve the effective first-party site from request `Host` / `X-Forwarded-Host`.
- Expose site context to guest and authenticated frontend requests.
- Register and log in users within the current site namespace.
- Show plan prices for the current site.
- Create orders with site-specific prices and `site_id`.
- Store a compact order pricing snapshot for auditability.
- Keep node docking, subscription tokens, server groups, and plan group permissions shared globally.

This phase does not add site-specific nodes, separate databases, agent balances, reseller settlement rules, or per-site payment credentials.

## Recommended Approach

Use a dedicated first-party site layer, not the agent domain commerce layer.

Agent domains remain for reseller storefronts and include agent-specific concepts such as balances, holds, payment ownership, and margin controls. First-party multi-site is platform-owned. It should reuse the same design ideas where useful, especially host resolution and pricing snapshots, but not inherit agent financial rules.

## Alternatives Considered

### A. Site Context + Site Prices

Add first-party site context, site settings, site plan prices, and site order snapshots. Users and orders receive `site_id`, nodes remain shared.

This is the recommended approach. It solves the current problem with the least operational risk and keeps existing node integration untouched.

### B. Full Tenant Isolation

Every feature would become site-scoped: users, orders, tickets, coupons, payments, plans, knowledge base, marketing, and node visibility.

This is more isolated but too large for the current step. It would also make migration and support harder before the storefront model is proven.

### C. Reuse Agent Domains as Sites

Treat each site like an agent domain.

This would be fast at first but wrong long-term. Agent features include reseller balance and payment ownership, which are not appropriate for first-party sites with platform-owned revenue.

## Data Model

Phase 1 already introduces:

- `v2_site`
- `v2_site_domain`
- nullable `site_id` on `v2_user`
- nullable `site_id` on `v2_order`

Add `v2_site_setting`:

- `id`
- `site_id`
- `site_name`
- `logo_url`
- `landing_theme`
- `accent_color`
- `support_name`
- `support_url`
- `announcement`
- `seo_title`
- `seo_description`
- `enabled`
- `created_at`
- `updated_at`

Add `v2_site_plan_price`:

- `id`
- `site_id`
- `plan_id`
- `period`
- `sale_price`
- `enabled`
- `created_at`
- `updated_at`

Add `v2_site_payment`:

- `id`
- `site_id`
- `payment_id`
- `enabled`
- `sort`
- `created_at`
- `updated_at`

Add `v2_site_order_context`:

- `id`
- `order_id`
- `trade_no`
- `site_id`
- `site_domain_id`
- `sale_amount`
- `platform_plan_price`
- `pricing_snapshot`
- `domain_snapshot`
- `created_at`
- `updated_at`

The snapshot table keeps historical order records stable when a site price, domain, or name changes later.

## Site Resolution

`SiteResolver` remains the backend boundary for first-party site context.

Resolution order:

1. Normalize `X-Forwarded-Host` when present. If it contains multiple hosts, use the first host.
2. Fall back to `Host`.
3. Match an active `v2_site_domain` whose parent `v2_site` is active.
4. If no active match exists, use the active default site.

Authenticated requests use the logged-in user's `site_id` when it is set. Guest requests use the request host. This lets a user remain attached to their original storefront even when they later browse through the main domain.

## User Identity

Use site-scoped user identity:

- The same email may exist on different sites.
- Registration stores the resolved `site_id` on `v2_user`.
- Login resolves users by `(site_id, email)` for storefront requests.
- Legacy users with `site_id = null` are treated as default-site users.
- Admin user search remains global and can filter by site.

This prevents customers from different storefronts accidentally sharing account state while still keeping all users in one database.

## Site Settings

Guest and authenticated config endpoints should return an effective site payload:

- Site name and logo override global display values.
- Landing theme can be selected per site.
- Accent color can be applied by the user theme.
- Support name and support URL override public support entry points.
- Site announcement can be displayed before or beside global announcements.

Global settings remain the fallback. A site does not need to fill every field.

## Plan Pricing

Storefront plan display must be site-aware.

Default behavior:

- The default site may fall back to platform plan prices.
- Non-default sites only show enabled `v2_site_plan_price` rows.
- A plan period without an enabled site price is hidden for that site.

This avoids accidental exposure of platform base prices on a branded storefront that should have custom pricing.

The plan object returned to the frontend should include:

- Base platform prices for admin/audit use only when appropriate.
- Effective storefront prices for the current site.
- A marker that the price is site-overridden.

The frontend should use effective storefront prices for display and checkout.

## Orders

Order creation must resolve site context before validating the selected period.

Flow:

1. Resolve current site from logged-in user or request host.
2. Load the selected plan.
3. Validate the selected period is enabled for the site.
4. Use the site sale price as the order amount.
5. Store `site_id` on `v2_order`.
6. Store `v2_site_order_context` with price and domain snapshots.

If the request is also an agent-domain order, agent commerce stays separate and continues to manage agent balances and holds. First-party site context should still exist underneath as the platform site, but agent financial logic must not read from site pricing.

## Payment Availability

In this phase payment methods remain platform-owned.

The platform operator can enable or disable existing payment methods per site through `v2_site_payment`. If a site has no explicit payment rows, the default site can fall back to globally enabled methods. Non-default sites should require at least one enabled site payment row before paid checkout is available.

Payment callbacks continue to settle the original order by trade number. They do not need to resolve the current request host.

## Frontend Integration

`keli-user` should add a first-party site context layer that can coexist with the existing agent site context layer:

- Load guest site context before landing, login, register, and public plan display.
- Load authenticated site context after login.
- Apply site name, logo, accent, support, announcement, and landing theme.
- Fetch plan/store data with site-aware effective prices.
- Keep subscription URL and node display behavior unchanged.

Agent site context remains higher priority for reseller storefront branding when the request host belongs to an agent domain. First-party site context is the base platform site.

## Admin UX

`keli-admin` should expose first-party site management:

- Site list: code, name, status, primary domain, users, orders.
- Site edit: branding, landing theme, support, announcement, SEO.
- Domains: add, enable, disable, mark primary.
- Prices: set enabled periods and sale prices per plan.
- Payments: choose enabled platform payment methods for the site.
- Users and orders: filter by site.

This should be separate from agent commerce management to avoid mixing platform sites with reseller storefronts.

## Migration Use

For the old independent sites:

1. Create a site for each old storefront.
2. Add its public domain to `v2_site_domain`.
3. Import users with the mapped `site_id`.
4. Import orders with the mapped `site_id`.
5. Map old plans to shared platform plans.
6. Create site prices matching the old storefront prices.
7. Keep nodes and server groups only in the main panel.

The migration script should require an explicit mapping file. It should not infer plan mappings automatically.

## Error Handling

- Unknown host falls back to the default site.
- Disabled site domain falls back to default site for guest context.
- Disabled site on an authenticated user blocks checkout and returns a clear site unavailable error.
- Missing site price hides the period from plan display and rejects checkout for that period.
- Missing enabled site payment rejects checkout with a payment unavailable error.
- Duplicate domains are rejected across first-party sites.

## Testing

Backend tests should cover:

- Host and forwarded-host resolution.
- Default-site fallback.
- Site-scoped registration and login.
- Same email on two sites.
- Site-aware plan fetch.
- Site-aware order amount and snapshots.
- Missing price rejection.
- Site payment filtering.
- Legacy default users and orders.
- Agent-domain commerce still using agent pricing and holds.

Frontend tests should cover:

- Site context normalization.
- Brand and support fallback behavior.
- Landing theme selection from site context.
- Store plan cards using effective site prices.
- Checkout payloads remaining compatible with existing order APIs.

## Rollout

Roll out behind additive tables and nullable fields. Existing installations continue to work because unknown hosts and legacy users resolve to the default site.

Implementation order:

1. Rebase Phase 1 onto current `main`.
2. Add site setting, site price, site payment, and site order context tables.
3. Add backend service boundaries for settings, pricing, and checkout resolution.
4. Expose user-facing site context and site-aware plans.
5. Attach site context to registration and order creation.
6. Add admin APIs.
7. Add `keli-user` site context integration.
8. Add `keli-admin` management screens.

Each step should preserve existing agent-domain behavior and existing global-store behavior.
