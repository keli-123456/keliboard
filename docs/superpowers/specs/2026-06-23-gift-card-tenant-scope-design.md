# Gift Card Tenant Scope Design

## Goal

Gift cards must work safely with platform-wide use, multi-site use, and agent-owned users. A gift card created for one site or one agent must not be redeemable by unrelated users, and agent-owned gift cards must deduct the agent balance before rewards are granted.

## Scope Model

Gift card templates, generated codes, and usage records all store the same ownership fields:

- `scope_type`: `global`, `site`, or `agent`.
- `site_id`: the owning platform site for site cards.
- `agent_user_id`: the owning agent for agent cards.
- `agent_domain_id`: optional domain context for reporting.

Existing records default to `global`, so current gift cards keep working after migration. Codes inherit the template scope at generation time. Usage records snapshot the code scope at redemption time so future reports are auditable even if a template changes later.

## Redemption Rules

Global cards can be redeemed by any eligible user. Site cards require the user `site_id` to match the card `site_id`. Agent cards require the user to be bound under the same `agent_user_id` in `v2_agent_user`; the current access domain is not required because代理用户也可能从主域名登录。

The normal gift-card conditions and limits still run after scope validation.

The user-side check API must not calculate or return reward previews when the user fails scope or condition validation. It should return the rejection reason and leave `reward_preview` and `plan_operation` empty so cross-site or cross-agent cards do not expose reward details before redemption eligibility is confirmed.

## Agent Cost Rules

Agent-scoped gift cards charge the agent before rewards are issued:

- Balance rewards cost the same amount of user balance.
- Expiry days and plan validity days use `agent_center_bonus_day_price`.
- Traffic rewards require `agent_center_gift_card_traffic_gb_price`; if unset, agent redemption is blocked.
- Device rewards require `agent_center_gift_card_device_price`; if unset, agent redemption is blocked.
- Traffic reset uses the existing agent reset pricing policy.

If the agent balance is insufficient, redemption rolls back: code status, user rewards, usage records, and ledger entries remain unchanged.

The agent center settings page exposes the traffic and device unit prices so operators do not need hidden configuration values.

## Admin Experience

The gift-card template editor exposes an ownership section:

- 全局：platform-wide.
- 多站点：select a site.
- 代理：select or input an agent user ID; optional domain ID remains a reporting hook.

Template, code, and usage lists include ownership labels so operators can see whether a gift card belongs to the platform, a site, or an agent. Admin APIs also accept ownership filters for reporting and CSV export.

## Testing

Unit coverage focuses on the boundary:

- Site card rejects a user from another site.
- Agent card rejects users not owned by that agent.
- Agent card deducts balance, grants reward, writes scoped usage, and writes an agent ledger.
- Agent card rolls back when the agent balance is insufficient.
- User-side card checks do not expose reward previews when scope validation rejects the user.
