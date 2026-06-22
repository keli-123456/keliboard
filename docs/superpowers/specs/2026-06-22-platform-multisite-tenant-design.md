# Platform Multi-Site Tenant Design

## Goal

Support multiple first-party storefront sites on one `keliboard` installation. The platform operator can run several domains with different branding, announcements, support identity, payment methods, and plan prices while all users, nodes, subscriptions, server groups, and operations remain in one backend.

The node side must continue to integrate with only one panel. Multi-site behavior is a storefront and order-context concern, not a node provisioning concern.

## Recommendation

Build a first-party `Site` / `Tenant` layer instead of reusing the reseller agent feature directly.

Agent commerce is a useful reference for host-based attribution, price overrides, site settings, and order context, but it also contains agent-specific concepts such as balances, holds, deductions, self-service payment ownership, and reseller margins. First-party sites should not inherit those financial rules.

## Non-Goals

- No separate database per site.
- No separate node docking per site.
- No agent balance, agent margin, or reseller settlement behavior for first-party sites.
- No hard split of server nodes by site in this phase. All sites can share the same available node pool unless existing plan/group rules restrict access.
- No automatic migration from old independent sites in the first implementation phase. Migration should be a follow-up tool once the data model is stable.

## Core Model

Add a `v2_site` table as the first-party site registry:

- `id`
- `code`: stable short identifier, used in exports and migration mapping
- `name`
- `status`: `active`, `disabled`
- `is_default`
- `created_at`, `updated_at`

Add `v2_site_domain` for domain ownership:

- `id`
- `site_id`
- `domain`
- `status`: `active`, `pending`, `disabled`
- `is_primary`
- `created_at`, `updated_at`

Add `site_id` to first-party user and order ownership:

- `v2_user.site_id nullable`
- `v2_order.site_id nullable`

Null `site_id` means legacy/default site and is resolved to the default site at runtime. This keeps existing installations compatible.

## Branding and Content

Add `v2_site_setting` for site-level display and support settings:

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

This mirrors the useful parts of agent site settings, but belongs to the platform operator and is managed in `keli-admin`.

Runtime resolution:

1. Resolve the current site by request `Host` from `v2_site_domain`.
2. If no domain matches, use the default site.
3. If a logged-in user has `site_id`, use that user's site for authenticated pages, even if they browse through the main/default domain.
4. For guest landing, login, register, and plan browsing, use the request host site.

This rule lets users keep their original site identity after login while still allowing every site to reverse proxy to the same user frontend.

## User Identity

Use site-scoped user identity:

- The same email can exist on different sites.
- Login resolves by `(site_id, email)` for storefront users.
- Admin search can still search globally.
- Existing users with null `site_id` belong to the default site.

This avoids forcing unrelated storefronts to share one visible customer account namespace while still keeping one backend user table.

## Pricing

Add `v2_site_plan_price`:

- `site_id`
- `plan_id`
- `period`
- `sale_price`
- `enabled`
- `created_at`, `updated_at`

The site plan price controls what buyers see and pay on that site. It should not alter the base platform plan price. If no enabled site price exists for a period, that plan period is hidden for that site unless the site is configured to fall back to platform price.

Recommended default:

- Default site can use platform prices.
- Non-default sites should require explicit site prices for clarity.

Coupons remain platform coupons unless a later phase adds site-scoped coupons.

## Payment Methods

First phase:

- Payment methods remain platform-owned.
- Admin can choose which payment methods are enabled for each site.

Add `v2_site_payment`:

- `site_id`
- `payment_id`
- `enabled`
- `sort`

This is simpler than agent-owned payment methods and avoids per-site third-party payment credential sprawl at the start.

Later phase can allow site-specific payment config if needed.

## Orders

When creating an order:

1. Resolve site context from logged-in user or request host.
2. Validate that the selected plan period is enabled for that site.
3. Use the site sale price.
4. Store `site_id` on `v2_order`.
5. Store a pricing snapshot so historical orders do not change when site prices are edited.

Add a compact `v2_site_order_context` table if snapshots would otherwise overload `v2_order`:

- `order_id`
- `trade_no`
- `site_id`
- `site_domain_id nullable`
- `sale_amount`
- `platform_plan_price`
- `pricing_snapshot json`
- `domain_snapshot json`
- `created_at`, `updated_at`

This follows the agent order-context pattern without agent balances or holds.

## Registration and Invite Rules

Registration:

- Guest registration resolves `site_id` from request host.
- The created user receives that `site_id`.
- Invite codes remain user-owned. If an invite link is used on another site, the invited user still belongs to the current request site unless a stricter site invite policy is enabled later.

Login:

- On a site domain, login checks users for that site first.
- On default/admin-facing domain, normal user login uses default site unless a global login mode is later enabled.

This keeps behavior predictable for customers.

## Admin UX

Add a `Sites` section in `keli-admin`:

- Sites list: name, code, domains, status, user count, order count.
- Site edit: branding, support, announcement, SEO, landing theme.
- Domains tab: add/disable domains.
- Prices tab: set plan period sale prices.
- Payments tab: enable platform payment methods per site.
- Orders/users filters: filter by site.

Existing global settings remain global. Site settings only override storefront-facing display and commerce behavior.

## Frontend UX

`keli-user` should add a `SiteContextProvider`, similar to the agent site provider:

- Fetch guest site context before login/register/landing rendering.
- Fetch authenticated site context after login.
- Apply site name, logo, theme, accent, announcement, and support config.
- Plan store and purchase pages use site-aware plan prices.

The frontend should not need separate builds per site. One theme package supports all site variants.

## Node and Subscription Behavior

Nodes remain attached to the single panel:

- `kelinode` / `kelinode-rs` continues to pull from one backend.
- User subscription URLs still resolve a user by token.
- Server groups and plan permissions continue to decide which nodes a user can access.
- No site-specific node endpoint is required in this phase.

If a later business rule needs different node visibility per site, implement it through plan/group assignment rather than duplicating node docking.

## Migration Strategy

For old independent sites:

1. Create one site row per old site.
2. Add the old domain to `v2_site_domain`.
3. Import users with `site_id` set to the matching site.
4. Import orders with `site_id` set to the matching site.
5. Map old plan IDs to shared platform plans.
6. Create site prices matching each old site's public prices.
7. Keep node/server definitions only in the target main panel.

Before migration, define a mapping file:

- old database
- old domain
- new site code
- old plan ID to new plan ID
- old payment records handling

## Error Handling

- Unknown host falls back to default site.
- Disabled site domain returns a user-friendly disabled-site error on storefront endpoints.
- Missing site price returns a clear "This site has not configured this plan price" error.
- Orders always snapshot the resolved site and price to prevent later setting edits from changing old order meaning.

## Security and Isolation

- Site-scoped login prevents cross-site account collision.
- Admin APIs must require explicit permissions and expose site filters.
- User APIs must only return the effective site context for the current user/host.
- Payment method availability must be checked server-side during order creation, not only in the UI.
- Host resolution must rely on preserved `Host` / `X-Forwarded-Host` from trusted reverse proxy configuration.

## Testing

Backend tests:

- Host resolves active site domain.
- Unknown host falls back to default site.
- Registration stores resolved `site_id`.
- Login can distinguish same email on two sites.
- Plan fetch returns site prices.
- Order creation stores `site_id` and price snapshot.
- Disabled site price cannot be purchased.
- Payment methods are filtered by site.
- Existing null-site users remain compatible with default site.

Frontend tests:

- Site context normalization.
- Branding fallback.
- Site-aware plan period and price helpers.
- Purchase payload keeps the selected site-aware period.
- Login/register pages use request-host site context without affecting authenticated fallback.

## Implementation Phases

Phase 1: Backend site model and resolver

- Tables, models, default-site fallback, host resolver, admin read/write APIs.

Phase 2: User identity and registration/login

- Add `site_id` to users, register by host, login by site + email, compatibility fallback for default site.

Phase 3: Site settings and frontend branding

- Site context endpoint and `keli-user` provider.

Phase 4: Site prices and order flow

- Plan price override, order snapshot, purchase validation.

Phase 5: Admin management UI

- Sites page, domains, prices, payments, user/order filters.

Phase 6: Migration tooling

- Mapping-based import helpers for old independent sites.

Each phase should be independently testable and deployable.

## Open Operational Decision

Use site-scoped accounts by default. This means the same email can register separately on different first-party sites. Admin remains global and can search all sites.

If the operator later wants a single global customer identity across all sites, that should be a separate migration and product decision because it changes login, privacy, support, and pricing expectations.
