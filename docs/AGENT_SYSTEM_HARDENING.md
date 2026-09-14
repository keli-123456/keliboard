# Agent System Hardening

## Scope

- Manual account creation with a plan, plan assignment, traffic resets, bonus days, agent gift-card redemption, and shared balance debits respect active order reserves.
- Active different-plan assignments are rejected at preview and execution. Permanent plans are active. Same-plan renewal and replacing expired plans remain supported. There is no prorated upgrade in this change.
- Pending applications cannot bypass review; disabled agents cannot reactivate themselves. Manual mutations recheck the profile under transaction locks.
- Deleted payment channels disappear from normal lists but retain historical credentials. Disabled or archived channels cannot initiate new payments. Existing callbacks still require signature verification, matching order/channel, and exact paid amount.
- The four manual paid endpoints accept an optional Idempotency-Key header. Durable receipts and mutations commit in one transaction, serialized on the agent user row. Same-key/same-payload retries return the original response; conflicting payloads fail. Request payloads and passwords are not saved in receipts.
- Order pagination and abnormal filters run in SQL. Monthly money totals use aggregates; reconciliation streams batches of 250. Admin summary includes commerce agents beyond the first 100.

## Upgrade

1. Back up the database using the existing deployment process.
2. Deploy the backend and run its normal migrations before routing requests to the new code:
   - 2026_09_14_180000_preserve_deleted_payment_channels
   - 2026_09_14_180100_create_agent_operations_table
3. Update the keli-user theme. It displays available balance and sends stable operation keys for retries.
4. Verify an enabled channel can initiate payment and a retired channel cannot. Validate a previously initiated order callback in a test environment.

Do not purge operation receipts during routine cache cleanup. A code rollback should retain these additive database changes; dropping the receipt table removes replay protection.

## Compatibility And Limits

- Previously hard-deleted channel credentials cannot be recovered by this migration. Retiring a channel after this upgrade preserves them; disabling the payment plugin itself is a separate action and still disables its callback handler.
- Old themes without the header remain functional but do not receive operation replay protection. The new theme retains retry keys in memory for the active account/session, not across page reloads. After a reload following an ambiguous timeout, check the ledger before initiating a fresh operation.
- Replayed responses deliberately contain the original operation snapshot. Refresh the overview for current balance and status.
- Account reserves are not extra money. Available balance is the total balance minus active pending holds; historical ledger before/after amounts continue to record total balance.
- No real-money transactions, production changes, automatic commits, or deployment were performed during this work.

## Verification

Focused backend regression command:

```sh
php vendor/bin/phpunit --filter 'Agent|Payment|Order|GiftCard|UserService'
```

Frontend verification:

```sh
npx vitest run agent
npx tsc --noEmit
```

Tests cover all four real paid-operation retries, rollback and account isolation, active/permanent/expired plan rules, reserved-balance rejection, historical callbacks and amount/channel mismatch, migration behavior, SQL abnormal-filter parity, multi-page results, 101-agent totals, and 251-order reconciliation.

Local Playwright checks use simulated data at 1440px and 390px widths to verify available-balance feedback and disabled confirmation without horizontal overflow. MySQL multi-process contention and real payment callbacks have not been exercised; SQLite regression is not a substitute for production-engine concurrency acceptance.
