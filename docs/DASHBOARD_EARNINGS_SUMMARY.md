# Anonymous Individual Earnings

The dashboard has independent referral and agent entry points. Each column can show up to three anonymized individuals' cumulative settled earnings, never a platform total or a withdrawal feed. No figures are fabricated.

## Accounting

- Referral: group actual `v2_commission_log.get_amount` credits by `invite_user_id`. Count legitimate multilevel credits once each; exclude reversals, non-positive credits, unsettled/cancelled/refunded/orphan orders, agent contexts and subsite orders. Completed and discounted orders with valid settled commissions qualify.
- Agent: group `v2_agent_profit.amount` by `agent_user_id`. Only `available` records settled before today's midnight qualify, backed by completed paid non-recharge orders and a matching paid platform-collection context. Require the stored net amount to equal sale minus cost minus fees. Exclude refunds, reversals, pending profits, invalid contexts, self-collection and subsite orders.
- Both totals belong to each individual, not each order or all users together. Withdrawals and wallet transfers do not reduce historical earnings. Refund reversals do. No financial records, balances or settlement behavior are modified.
- Agent self-collection is not presented as verified net profit: external channel expenses are not fully represented in this ledger. The user-facing agent list explicitly says platform collection.
- Cutoff is yesterday in the application timezone, based on currently valid ledger rows; not an immutable historical report, real-time income or an income guarantee. CNY only. Missing required schema or signing secret suppresses the affected group rather than inventing a zero.

## Privacy and Selection

- New setting `agent_earnings_showcase_enabled`, default false, is independent of the discarded aggregate switch `agent_commission_summary_enabled`. An administrator must explicitly confirm permission to display anonymized individual earnings in system configuration -> agent center -> earnings display.
- At least five qualifying individuals are required independently in each group. Insufficient groups return no entries, including the admin preview. A small agent sample does not suppress a qualifying referral sample.
- A bounded pool of the 50 most recently credited qualifying individuals is selected after full per-person aggregation. Three are chosen in a deterministic HMAC order, not by income. This is a limited sample, not a leaderboard or an earnings distribution.
- Public rows contain only a keyed anonymous alias and that person's amount in cents. The HMAC includes the date and category, so aliases cannot directly be linked across days or between referral/agent roles. No emails, avatars, raw IDs, partial identifiers, trade numbers, individual timestamps, detail links or pagination are returned.
- This is de-identification, not a mathematical anonymity guarantee: someone who already knows a person's exact earnings may correlate an amount. The enable confirmation explicitly warns the operator; obtain appropriate permission before enabling. The daily aliases do not prevent amount-based correlation.
- Only authenticated main-site users receive the groups or recruitment flags. Account ownership takes precedence even when a subordinate returns to the main domain or visits another agent's domain. An agent owner on an agent domain is restricted too; the owner on the main site can enter their agent center. Non-default subsites and missing ownership/domain schema fail closed. Disabling the agent center removes agent results even from a cached response. The master switch is checked before reading the five-minute cache. Responses have private/no-store headers.
- Both dashboard entries and desktop/mobile navigation require a successful scoped response. Known agent domains, bindings and non-default sites additionally suppress them locally. Loading, missing endpoints and failed requests never restore a legacy main-site promotion fallback. Client refreshes on focus and every minute while visible, invalidates on user-data/auth changes and rejects stale in-flight responses. No polling animation or rotating payout ticker.

## Delivery

Deploy the backend, rebuilt admin assets and matching user theme together. This feature needs no new migration. The new theme hides promotion/recruitment entries when the scoped endpoint is unavailable, so update the backend as well as the theme. Local preview files use conspicuously marked demonstration data only. No setting is enabled and no production deployment is performed by building the theme.
