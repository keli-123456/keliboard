# Financial reconciliation timeout fix

## Scope

The synchronous read-only overview previously grouped the entire commission log
for each summary query. Its per-order commission checks also lacked a
trade-number lookup index in the repository migrations. Repeated schema checks
added metadata round trips to every rule.

- Aggregate commission logs only for orders in the requested creation-date range.
  Do not filter by commission-log date: cross-period postings must remain included.
- Add the non-unique trade-number index, reusing an existing index with the same
  leading column. Multiple recipients/postings per order remain supported.
- Share schema metadata only within one overview run. A new run checks again.
- Keep all audit rules, exact issue counts and existing sample limits.
- Cancel obsolete browser requests and reject their late results.
- Mark timeouts/incomplete scans explicitly instead of presenting zero balances
  or a successful zero-issue result. The global 30-second timeout is unchanged.

No settlement, wallet, commission, profit or refund records are modified by the
overview. The migration changes only an index.

## Deployment

Deploy the updated keliboard source and its rebuilt admin assets together.
After reviewing pending migrations and arranging a suitable low-traffic window,
apply this migration using the project's normal migration process:

    php artisan migrate --path=database/migrations/2026_09_15_180000_add_commission_trade_lookup_index.php --force

In the existing Docker Compose setup, prefix with docker compose exec -T web.
Reload long-running application workers after updating the code, as usual.
Creating an index on a large production table can take time and wait on metadata
locks. Do not execute index creation inside a web request or repeatedly retry it.
Verify the migration status and reopen the default 30-day reconciliation view.

## Local Verification

- 127 focused backend tests, 414 assertions: reconciliation, agent profits,
  collection policies, referral boundaries, order state and refund disposition.
- The reconciliation subset includes split postings, cross-period logs, exact
  counts beyond the sample limit, per-run schema freshness and index idempotency.
- SQLite fixture: 1,006 orders and 10,001 commission logs. The optimized scanner
  took 457.6 ms without the new index and 17.4 ms with it; summaries, scope totals
  and issue breakdowns were identical. This is not a production MySQL benchmark.
- Admin request/page tests cover timeout, unmount, superseded searches, stale
  responses and failed refreshes. Desktop/mobile previews use synthetic data only.

Production query plans, row counts and response times still need verification
after deployment. No online migration or deployment was performed locally.
