# Agent Self-Service Domains Design

## Goal

Let active agents add and manage their own storefront domains while the platform keeps control through DNS ownership verification, quantity limits, global uniqueness, and admin oversight.

## Recommended Approach

Use a self-service domain workflow with DNS verification:

1. Agent submits a domain in the user-side agent center.
2. Backend normalizes and validates the host.
3. Backend creates the domain as `pending` with a generated verification token.
4. Agent adds a DNS TXT record using the token.
5. Agent clicks verify.
6. Backend resolves DNS, marks the domain `active` only when the token is present.
7. Active domains are recognized by the existing `AgentDomainResolver`.

This is safer than making a submitted domain active immediately, because an agent cannot claim a domain they do not control.

## Current State

The existing system already has useful pieces:

- `v2_agent_domain` stores agent domains with `active` and `disabled` states.
- Admins can add, edit, enable, disable, and delete agent domains in `keli-admin`.
- Agents can view assigned domains in `keli-user`.
- Agent payments can optionally bind to an owned domain.
- Agent storefront/order logic already resolves active domains through `AgentDomainResolver`.

The missing pieces are user-side create/verify/delete actions, a pending status, DNS verification metadata, and quantity limits.

## Domain Statuses

Extend domain statuses to:

- `pending`: submitted by the agent, waiting for DNS verification.
- `active`: verified and usable by `AgentDomainResolver`.
- `disabled`: blocked by admin or disabled by platform policy.

Only `active` domains should be recognized as storefront hosts.

Pending domains should be visible to the agent and admin, but not usable for storefront routing or payment domain binding.

## Data Model

Extend `v2_agent_domain` with verification fields:

- `verification_token`: generated random token.
- `verification_type`: initially `txt`.
- `verified_at`: timestamp when DNS verification succeeds.
- `last_checked_at`: timestamp of last verification attempt.
- `verification_error`: last user-safe verification failure message.
- `created_by_agent_id`: nullable user id for self-service domains.

Existing fields remain:

- `agent_user_id`
- `domain`
- `status`
- `is_primary`
- `remark`
- `created_by_admin_id`
- timestamps

## Quantity Limit

Add an admin setting:

- `agent_center_domain_limit`
- Default: `1`
- `0` means agents cannot self-add domains.

Admin-created domains count toward the limit because the limit is about total domains owned by the agent. Admins can still override by editing the database only, but the UI/API should enforce the configured limit for normal operations.

## Validation Rules

Domain validation must happen before creating a pending row:

- Normalize host with `AgentDomainResolver::normalizeHost()`.
- Reject empty hosts.
- Reject IP addresses.
- Reject `localhost`.
- Reject wildcard domains.
- Reject URLs that normalize to no host.
- Reject platform/base/admin reserved hosts from configured site URLs where available.
- Enforce global uniqueness across all agents.
- Enforce active agent permission.
- Enforce quantity limit.

The system should store only the normalized host, not a full URL.

## DNS Verification

Use TXT verification first:

- Record name: `_keli-agent.<domain>`
- Record value: generated token, for example `keli-agent-verification=<token>`

The backend should check TXT records for that exact value.

Verification should be manual in this phase. The agent clicks "Verify" after adding DNS. Automatic background polling can be a later enhancement.

DNS lookup errors should be captured in `verification_error` and returned as friendly text. They should not crash the request.

## User-Side API

Add endpoints under existing user agent commerce routes:

- `POST /user/agent/domains`
  - Creates a pending domain.
  - Returns the domain payload plus DNS instructions.
- `POST /user/agent/domains/{id}/verify`
  - Verifies DNS and activates the domain when the TXT record matches.
- `POST /user/agent/domains/{id}/delete`
  - Allows the owning agent to delete a pending domain.
  - Allows deleting active domains only when no enabled payment method is bound to the domain.

Agents cannot edit another agent's domain.

## User-Side UI

In `keli-user` agent center domain tab:

- Add an "Add Domain" button.
- Show current count and max count.
- Pending domain rows show:
  - domain
  - status
  - TXT record name
  - TXT record value
  - verify button
  - delete button
  - last error if present
- Active domain rows continue to show proxy snippet and can be used by payment settings.

Keep the UI practical and compact. This is an operations panel, not a marketing page.

## Admin UI

The existing `keli-admin` Agent Commerce page remains the control center.

Enhance domain rows to show:

- status including `pending`
- source: admin-created or agent-created
- verified time
- last checked time
- verification error if present

Admin actions:

- enable/disable/delete still work.
- Admin may manually create active domains as today.
- Admin can disable suspicious self-service domains.

## Payment Binding Rule

Agent payment methods may bind only to domains owned by the same agent and in `active` status.

Pending or disabled domains should not appear in the payment domain selector.

## Storefront Resolver Rule

`AgentDomainResolver` should continue to resolve only `active` domains. Pending, disabled, invalid, and unverified domains must never become storefront hosts.

## Error Handling

Use clear user-facing errors:

- Domain already assigned.
- Domain limit reached.
- Invalid domain.
- Domain verification record not found.
- DNS lookup failed, try again later.
- Domain is used by an enabled payment method.
- Agent permission is not active.

## Testing Strategy

Backend unit coverage:

- Agent can create a pending domain when under limit.
- Creating a duplicate domain fails.
- Creating beyond the configured limit fails.
- Invalid host, IP, wildcard, and localhost fail.
- Verification succeeds when TXT record contains the token.
- Verification fails safely when TXT record is missing.
- Pending domains are not resolved by `AgentDomainResolver`.
- Active verified domains are resolved.
- Payment domain selector excludes pending domains.
- Agent cannot delete another agent's domain.

Frontend coverage:

- Helper tests for DNS instruction formatting.
- Agent center renders pending domain actions.
- Admin domain status/source display helper tests.

## Rollout

This is backward compatible:

- Existing admin-created active domains keep working.
- Existing active domains can remain verified-at empty unless manually verified later.
- New self-service domains start pending and do not affect routing until verified.

Deploy backend first, then `keli-user`, then `keli-admin`.
