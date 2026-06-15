# Agent Center MVP Design

## Goal

Build a first version of the Agent Center for `keli-user` and `keliboard`.

The MVP lets an eligible user become a first-level agent, create subordinate user accounts, assign plans by deducting the agent's balance, reset subordinate traffic, and review an auditable balance ledger.

This is not a multi-level reseller system. It has no downstream commission tree in the first version.

## Product Scope

### In Scope

- Add a `keli-user` page at `/agent-center`.
- Add a top navigation entry named `代理中心`.
- Let eligible users unlock agent capability from the user side.
- Show agent status, balance, subordinate count, current-month spending, and enabled actions.
- Let an agent create subordinate accounts with email, password, and optional remark.
- Let an agent assign a plan and period to a subordinate user.
- Deduct the agent's balance when a plan is assigned.
- Let an agent reset traffic for a subordinate user.
- Deduct the agent's balance for traffic reset when a reset price is configured.
- Show subordinate users with plan, expiry, traffic, status, and remark.
- Show agent ledger entries for unlock, plan assignment, reset, refunds, and admin adjustment.
- Add backend settings with safe defaults so the feature can ship before the full admin UI is polished.

### Out of Scope

- Multi-level agents.
- Agent commission payout.
- Agent-to-agent transfer.
- Public agent storefront.
- Automatic invoice or tax handling.
- Allowing agents to manage users they did not create or explicitly own.

## Recommended Architecture

Use `keliboard` as the source of truth and `keli-user` as the user-facing control panel.

- `keli-user` owns route, page, dialogs, forms, and optimistic UI states.
- `keliboard` owns eligibility, balance deduction, plan assignment, traffic reset, and ledger writes.
- `keli-admin` receives a later settings UI. The MVP can read `admin_setting(...)` keys with defaults.

This keeps all sensitive operations on the backend. The frontend never calculates final prices or directly changes user subscriptions.

## Backend Design

### New Tables

#### `v2_agent_profile`

Stores one row per agent user.

- `id`
- `user_id`
- `status`: `pending`, `active`, `disabled`
- `level`: default `default`
- `remark`
- `enabled_at`
- `disabled_at`
- `created_at`
- `updated_at`

`user_id` must be unique.

#### `v2_agent_user`

Stores the agent-to-subordinate ownership relation.

- `id`
- `agent_user_id`
- `sub_user_id`
- `remark`
- `created_at`
- `updated_at`

`sub_user_id` must be unique in this table so one subordinate belongs to only one agent.

Do not reuse `invite_user_id` for agent ownership. Invite/referral and agent operations must remain separate.

#### `v2_agent_ledger`

Immutable audit log for money-impacting and permission-changing operations.

- `id`
- `agent_user_id`
- `target_user_id`
- `type`: `unlock`, `assign_plan`, `reset_traffic`, `refund`, `admin_adjust`
- `amount`: signed integer in cents. Deduction is negative.
- `balance_before`
- `balance_after`
- `plan_id`
- `period`
- `metadata` JSON
- `created_at`

Ledger rows are append-only. Corrections are written as new rows.

### Settings

Use `admin_setting` keys first:

- `agent_center_enable`: default `0`
- `agent_center_unlock_mode`: `balance_threshold`, `manual`, default `balance_threshold`
- `agent_center_unlock_balance`: cents, default `0`
- `agent_center_auto_activate`: default `1`
- `agent_center_allowed_plan_ids`: comma-separated list, default empty means all sellable plans
- `agent_center_discount_percent`: default `100`
- `agent_center_daily_create_limit`: default `20`
- `agent_center_allow_traffic_reset`: default `1`
- `agent_center_reset_price_mode`: `plan_reset_price`, default `plan_reset_price`

Admin UI can be added after the backend keys are stable.

When `balance_threshold` mode is used, the threshold is checked when the user unlocks the agent profile. Once active, the profile is not automatically disabled when the balance later drops below the threshold.

### New Service

Create `App\Services\AgentCenterService`.

Responsibilities:

- Read settings and calculate eligibility.
- Create or activate agent profiles.
- List subordinates owned by the current agent.
- Create subordinate users through existing user creation patterns.
- Assign plan and period inside a database transaction.
- Reset subordinate traffic inside a database transaction.
- Deduct agent balance with row locking.
- Write immutable ledger entries.
- Reject operations if the feature is disabled, the agent is inactive, the target user is not owned by the agent, or the balance is insufficient.

### Pricing Rules

The frontend sends `plan_id` and `period`.

The backend:

1. Loads the plan.
2. Confirms the plan is sellable and allowed for agents.
3. Resolves the base price from the selected period.
4. Applies `agent_center_discount_percent`.
5. Returns preview or performs deduction.

All money values stay in cents.

### Plan Assignment

Plan assignment should not be represented as a normal unpaid user order. It is an agent operation.

On confirm:

1. Lock the agent user row.
2. Check balance.
3. Lock the subordinate user row.
4. Deduct agent balance.
5. Assign plan, group, speed limit, device limit, transfer amount, and expiry.
6. Reset subordinate used traffic to `0`.
7. Write `v2_agent_ledger`.
8. Return updated agent summary and subordinate snapshot.

If any step fails, the transaction rolls back.

### Traffic Reset

Only the owner agent can reset a subordinate user.

On confirm:

1. Load subordinate ownership.
2. Resolve reset price from the subordinate's current plan `reset_price`.
3. Apply agent discount only if configured to do so.
4. Lock agent and subordinate rows.
5. Deduct balance if price is greater than zero.
6. Set subordinate `u = 0`, `d = 0`.
7. Write `v2_agent_ledger`.

### API Routes

Add routes under `/api/v1/user/agent`.

- `GET /overview`
- `POST /unlock`
- `GET /users`
- `POST /users`
- `POST /users/{id}/assign-plan/preview`
- `POST /users/{id}/assign-plan`
- `POST /users/{id}/reset-traffic/preview`
- `POST /users/{id}/reset-traffic`
- `GET /ledger`

All routes use the existing authenticated user middleware.

### Error Handling

Return actionable messages:

- Agent center is disabled.
- Agent permission is not active.
- Balance is insufficient.
- Plan is not allowed for agents.
- Period is not available.
- Target user is not managed by this agent.
- Daily creation limit exceeded.
- Email already exists.

## Frontend Design

### Files

- `keli-user/src/pages/AgentCenterPage.tsx`
- `keli-user/src/services/agent.ts`
- Add route in `keli-user/src/App.tsx`
- Add navigation item in `keli-user/src/components/NavigationBar.tsx`
- Add locale keys for menu, page labels, errors, and actions.

### Page Layout

The page should feel operational, not promotional.

Top summary:

- Agent status
- Account balance
- Managed users
- Current-month spending
- Allowed plans count

Primary tabs or sections:

- Users
- Ledger
- Settings/Rules summary

User list:

- Email
- Remark
- Plan
- Expiry
- Used / total traffic
- Status
- Actions: assign plan, reset traffic

Dialogs:

- Unlock agent
- Create subordinate account
- Assign plan
- Reset traffic

### Frontend Rules

- Use backend preview responses for final price display.
- Disable confirm buttons while requests are in flight.
- Refresh overview and user list after successful mutations.
- Show exact balance after deduction.
- Do not hide backend errors behind generic failure messages.

## Data Flow

1. User opens `/agent-center`.
2. Frontend requests `GET /user/agent/overview`.
3. If eligible but inactive, show unlock action.
4. If active, load subordinate users and ledger.
5. Create user flow calls `POST /user/agent/users`.
6. Assign plan flow calls preview, then confirm.
7. Backend performs transaction, writes ledger, returns updated state.
8. Frontend refreshes affected sections.

## Security And Abuse Controls

- Agents can only operate on users in `v2_agent_user`.
- Subordinate ownership cannot be reassigned in the MVP.
- Daily creation limit applies per agent.
- Password is hashed through existing user creation logic.
- Every money-impacting action writes a ledger row.
- Backend recalculates all prices.
- Backend rejects negative or unavailable plan prices.
- Balance deduction uses row locks and transactions.

## Testing Plan

Backend:

- Agent eligibility with disabled feature, threshold mode, and manual mode.
- Unlock creates a single active profile.
- Create subordinate rejects duplicate email and respects daily limit.
- Assign plan deducts balance and updates subordinate plan.
- Assign plan rolls back when balance is insufficient.
- Reset traffic deducts reset price and sets `u`/`d` to zero.
- Agent cannot operate on users outside ownership.
- Ledger records correct before/after balances.

Frontend:

- Agent center route renders for authenticated users.
- Locked state shows unlock CTA.
- Active state loads overview and users.
- Assign plan dialog uses preview price.
- Mutation success refreshes list and balance.
- Backend validation errors are shown to the user.

## Rollout Plan

1. Backend migrations, models, service, and API routes.
2. Backend feature tests for money and ownership boundaries.
3. `keli-user` service client and page shell.
4. User list, create user, assign plan, reset traffic, ledger.
5. Navigation and locale keys.
6. Manual verification against dev API.
7. Add `keli-admin` settings UI in a follow-up pass.

## First Implementation Target

Ship a stable first-level agent center:

- one agent owns many subordinate users;
- agent balance pays for subordinate plan assignment;
- agent can reset subordinate traffic;
- all actions are auditable;
- no multi-level commission logic.
