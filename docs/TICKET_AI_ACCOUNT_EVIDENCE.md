# Read-only account evidence for ticket support

`TicketAiContextService` now supplies bounded account-history queries through
`TicketAiAccountEvidenceService`. No new dependencies, schema migrations, queue
workers or provider calls are needed. Existing model selection, attachment consent,
reply authorization and human-review policies are unchanged.

## Available information

Existing context includes the current subscription, recent orders, sanitized risk
events, the current conversation and scoped operational health. It now also includes:

| Source | Query boundary | Meaning |
| --- | --- | --- |
| `v2_stat_user` | Current user, daily buckets, today plus preceding 29 days, at most 30 dates | Billed upload/download and latest statistics update |
| `v2_stat_user_node_day` | Same user/window, top 5 protocol/node pairs by billed bytes | Billed usage, protocol, node ID and observed min/max rates |
| `v2_traffic_reset_logs` | Same user/window, latest 3 resets | Before/after used counters, reset time and allowlisted trigger label |
| `v2_ticket` | Same owner and exact site/agent/domain scope, last 90 days, latest 5 other tickets | Status, creation and last-update time only |
| Existing scoped order query | Latest 5 orders | Plan, order type/status and creation time; not proof a plan change executed |
| Current user | Existing persisted fields | Plan, allowance, used/remaining traffic and last/planned next reset |

Traffic-history and ticket-history queries run only when the subject or recent
user messages request them. Follow-ups preserve the earlier user request; staff or
assistant messages do not trigger queries. Ordinary FAQs add no history queries.
Traffic investigations also include recent typed orders to help distinguish a
renewal, upgrade or traffic-reset purchase from an actual reset record.

## Accounting and uncertainty

- User statistics already include the billing multiplier. Never multiply `u + d`
  again; server-wide raw counters are not account-billing evidence.
- The history window is not necessarily the current allowance period. Daily
  aggregates cannot reconstruct hourly usage or the portions before/after an
  intra-day reset. Historical totals are not substituted for current used counters.
- A reset changes used counters; it is not a deduction from the plan allowance.
  A future `next_reset_at` is a schedule, not evidence that a reset happened.
- `available`, `no_records`, `unavailable` and `scope_mismatch` remain distinct.
  A missing table, query failure or empty history is never presented as zero usage.
  One failed optional source does not erase other verified information.
- These records do not identify websites, apps, theft or erroneous charging.
  A ticket's update time does not establish closure time, actor or reason.

## Trust and reply boundaries

The user and site must match the current ticket. Agent history additionally requires
the current parent-agent relationship and matching domain ownership. History never
selects an account based on an email, user ID or ticket ID written in the message.
An invalid loaded user relation is excluded from account/order/risk context.

New evidence exposes no account credentials, raw IPs, node names/addresses,
reset metadata, previous ticket text or attachments. The model receives only the
allowlisted results in `verified_business_context.account_evidence`.

Backend-generated factual replies prevent invented usage, false claims of completed
refunds/compensation and generic statements that no records can be viewed when
records were supplied. If human review is required, known facts remain in the
draft while the unresolved cause or operation stays with staff. This feature cannot
change balances, reset traffic, close tickets, or grant auto-reply permissions.

## Verification

Unit coverage includes already-scaled billing, result/time limits, protocol ID
collisions, missing/partial data, reset semantics, tenant/agent isolation, malicious
account selectors and follow-up intent preservation. A mocked-provider integration
test checks the complete context-to-draft path, replaces a hallucinated response,
and verifies that no account/ticket state was changed or reply sent. These tests use
synthetic data, not a real customer's ticket or a live model request.
