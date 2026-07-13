# Agent Diagnostics Clarity Design

## Goal

Make agent health reporting describe the agent's actual operating mode, stop treating historical payment changes as active order failures, and restore reliable automated coverage without changing order, balance, or settlement behavior.

## Decisions

- Derive the mode from existing commerce artifacts; do not add a database column or migration.
- An agent with no domains, agent-owned payments, agent prices, or site settings is a `basic` agent. Basic agents can manage subordinate users and are not blocked for lacking an independent storefront.
- Once any commerce artifact exists, the agent is a `storefront` agent and the existing domain, payment, price, site-setting, and balance checks remain authoritative.
- A disabled payment is actionable only while the related order is pending or processing. Completed, cancelled, and discounted historical orders must not become abnormal when a payment method is disabled later.
- Existing API fields remain compatible. Diagnostics add `mode` and `storefront_configured`; `overall_status` becomes `ok` for a clean basic agent while detailed storefront checks remain available for clients that need setup guidance.

## User Experience

- The agent center labels the current mode as Basic Agent or Independent Storefront Agent.
- Basic agents see a calm explanation that subordinate-user management is available and independent storefront configuration is optional.
- Storefront agents continue to see the current readiness checks and actions.
- The admin reconciliation count excludes terminal orders whose only historical condition is a disabled payment method.

## Verification

- Add resolver tests for cancelled and completed orders linked to a payment disabled after the order finished.
- Repair the in-memory schema binding so Telegram source tests exercise the actual relation-loading path.
- Create the missing agent site-setting table in diagnostics tests and add mode assertions.
- Run targeted backend tests, the broader agent/multisite suite, user frontend tests, and production builds.

