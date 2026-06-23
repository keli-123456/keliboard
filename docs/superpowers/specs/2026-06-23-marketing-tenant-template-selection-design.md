# Marketing Tenant Template Selection Design

## Goal

Marketing automation should send the template that belongs to the user's tenant context. Agent users should receive agent-scoped templates first, site users should receive site-scoped templates next, and platform users should keep using the global template.

## Current Behavior

Marketing rules store a single `email_template_id` and `telegram_template_id`. Template rows already have `scope_type`, `site_id`, `agent_user_id`, and `agent_domain_id`, and dispatch tasks/logs already record scope context. The missing piece is runtime template resolution: `MarketingAutomationService` renders the rule's bound template directly.

The original `v2_marketing_template.code` unique index also prevents creating tenant overrides with the same semantic code as the global template.

## Design

Scoped template overrides use the same `code`, `channel`, and `message_type` as the rule's base template. The template `code` index becomes non-unique so the same template code can exist in global, site, and agent scopes.

When a rule queues a task:

1. Resolve the user's notification context with `NotificationSiteContextService`.
2. Resolve an effective template from the base template:
   - agent scope with matching `agent_user_id`, preferring exact `agent_domain_id` over generic agent template;
   - site scope with matching `site_id`;
   - global scope;
   - base template only when it already matches the user's context.
3. Render and enqueue with the effective template id.
4. Keep the existing dispatch context so tasks/logs stay filterable by tenant.

## Compatibility

Existing installs keep working because global templates remain valid. Existing rules do not need data migration. Sites and agents can gradually create scoped templates using the same code as the global template.

## Testing

Add unit coverage for:

- site-scoped template selected over global template;
- agent-scoped template selected over site and global templates;
- global template used when no tenant override exists.
