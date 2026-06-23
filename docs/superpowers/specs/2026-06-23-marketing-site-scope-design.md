# Marketing Center Site Scope

## Goal

Split marketing-center visibility by tenant without changing the existing global automation behavior.

## First Scope

- Keep automation rules global in this phase to avoid duplicating scenes and changing send cadence.
- Add tenant ownership columns to marketing templates, dispatch tasks, and dispatch logs.
- Let administrators filter marketing overview, templates, and logs by all, global, or a specific platform site.
- Tag queued marketing tasks and dispatch logs from the existing notification context so email, Telegram, and future channels share the same attribution.

## Out Of Scope

- Coupon and gift-card tenant isolation.
- Per-site rule cloning or per-site scene overrides.
- Agent-facing marketing configuration.

## Compatibility

- Existing rows are treated as global.
- When the new columns do not exist, controllers and services keep the current behavior.
- Global templates remain available to all sites.
