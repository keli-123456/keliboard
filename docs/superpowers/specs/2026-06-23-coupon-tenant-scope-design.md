# Coupon Tenant Scope Design

## Goal

Coupons must support platform-wide use, multi-site use, and agent-owned users without breaking existing global coupons. A coupon created for one site or one agent must not be usable by unrelated users.

## Scope Model

Coupons store the same ownership fields used by gift cards:

- `scope_type`: `global`, `site`, or `agent`.
- `site_id`: the owning site for site coupons.
- `agent_user_id`: the owning agent for agent coupons.
- `agent_domain_id`: optional reporting context for an agent domain.

Existing coupons default to `global`, so current coupons keep their behavior after migration.

When multiple coupons share the same code, the runtime resolver chooses the best coupon for the current user in this order: matching agent coupon, matching site coupon, global coupon, then the first same-code coupon only to produce a clear scope error.

## Validation Rules

Global coupons can be used by any user who passes the current coupon checks. Site coupons require `user.site_id` to match `coupon.site_id`. Agent coupons require a row in `v2_agent_user` where `agent_user_id` matches the coupon and `sub_user_id` is the redeeming user.

The existing checks for visibility, remaining uses, active time, plan limits, period limits, and per-user limits still run after scope validation. The per-user usage count remains based on orders that are not pending or cancelled.

## Admin Experience

The coupon editor exposes an ownership section:

- Global: default platform-wide coupon.
- Site: select a site.
- Agent: select or input an agent user ID; optional agent domain ID is stored for reporting.

Coupon lists include ownership labels. Admin APIs accept ownership fields on create/update and include them in fetch results and generated CSVs.

## Testing

Unit tests cover:

- Existing global coupons remain usable.
- Site coupons reject users from other sites.
- Same-code coupons prefer the current user's matching site coupon over a global coupon.
- Agent coupons reject users not bound under the owning agent.
- Agent coupons apply successfully for owned users and decrement usage.
