# Durable traffic batch receipts

## Status

Implemented an additive panel endpoint and durable application worker. The Rust
node now has an opt-in HTTP outbox path, disabled by default. Subsequent real
Rust-to-Laravel loopback/file-SQLite/database-queue integration passes on Windows
and Linux; see `kelinode-rs/docs/TRAFFIC_LARAVEL_ACCEPTANCE_20260920.md`.
Isolated PostgreSQL 15.19 integration and concurrent retry/accounting now also
have evidence in `TRAFFIC_POSTGRES_ACCEPTANCE_20260920.md`. READ COMMITTED passed
without external retries; SERIALIZABLE required 12 unchanged-ID retries. This
does not establish MySQL behavior. Subsequent scoped PostgreSQL SQL COMMIT
response-loss evidence and the completion-marker fix are documented in
`TRAFFIC_POSTGRES_COMMIT_LOSS_20260920.md`; the earlier gate alone does not prove it.
Subsequent actual reset/accounting row-lock races and SQL quota synchronization
are documented in `TRAFFIC_RESET_ACCOUNTING_20260920.md`. A reset now requires
successful synchronization within its transaction, fixing best-effort observer
false success. Period attribution and external notification delivery remain open.
The later `TRAFFIC_MYSQL_ACCEPTANCE_20260920.md` records partial MySQL 8.4.11
evidence: native integrations and two isolation gates pass, but a SERIALIZABLE
replay timeout leaves the complete MySQL gate failed. Do not treat it as approval.
`TRAFFIC_QUEUE_DAEMON_20260920.md` adds seven passing Windows real queue:work
scenarios, including unaccelerated lease recovery and process death. Its
two-worker SQLite gate remains failed despite correct receipt totals; restored
ACK recovery is separately verified. Redis/Horizon/Linux coverage remains open.
This is not full production end-to-end accounting,
production acceptance, a release, or a migration run against a real site.
The old report/push endpoints and four legacy job implementations are unchanged.

## Wire contract

`POST /api/v2/server/traffic/batch` uses the existing `server` authentication
middleware. Ownership comes from authenticated `node_info`, never client-selected
receipt ownership. Query/body authentication fields remain compatible with that
middleware. The JSON business body is:

```json
{"version":1,"report_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","traffic":{"7":[10,20]}}
```

Authenticated GET on the same path advertises version 1, node ID/type, max_users
1000, max_body_bytes 262144 and durable_receipts=true, with Cache-Control:no-store.
It returns 503 if the receipt table has not been migrated. This is protocol
discovery, not proof that driver/queue/capacity release gates have passed.

- Lowercase 32-hex report ID, scoped to the authenticated unified server ID.
- 1 to 1000 distinct positive uint32 user IDs, nonnegative signed-64-bit integer
  byte pairs, at least one nonzero direction per user. No floats, numeric strings,
  booleans, negative values or silent filtering. Each raw direction's batch total
  must also fit signed 64-bit. Request/prepared payload limit: 262144 bytes.
- Canonical content hash is SHA-256 of `keli-traffic-v1\n`, followed by lines
  `user_id:upload:download\n`, ordered by numeric user ID. No spaces, leading zeros
  or locale formatting. The server computes this hash; it is not an authentication
  signature and does not prove that a trusted node honestly measured the bytes.
- A repeated ID and identical canonical data returns the existing receipt.
  Different bytes or a changed node protocol conflict with HTTP 409. Equal data
  under a NEW ID is new traffic and must be counted again.
- The response contains `version`, `report_id`, `content_hash`, authenticated
  `node_id`, `node_type` and `status`. HTTP 202 / `pending` means only durable
  receipt ownership. HTTP 200 / `applied` means the accounting transaction has
  committed. A caller must validate identity/hash/status and retain its outbox
  until `applied`; generic HTTP success is NOT an accounting receipt.
- Unknown business fields/version or invalid bytes return 422; oversized body
  returns 413. The dedicated endpoint must not silently fall back to legacy push.

## Persistence and application

Migration `2026_09_20_000001_create_traffic_batch_receipts.php` adds
`v2_traffic_batch`. Each receipt stores original content hash, an independently
hashed immutable prepared payload, creation time, application/sync markers and
a recovery dispatch lease. The unique key is `(node_id, report_id)`. There is no
cascade from node deletion and no TTL deleting dedup tombstones. Migration down
intentionally refuses to discard receipts without an explicit settled-data plan.

Admission serializes on the authenticated node row, checks for an existing
receipt, then snapshots current rate, day bucket, node identity/name and the two
existing traffic filters. Retries do not rerun filters, change rate or move the
day bucket. Plugin filters must remain pure transformations: external side
effects cannot be made exactly-once by this database transaction. Filters cannot
change authenticated node ownership. Invalid/empty filtered data fails closed.

Rates must exactly fit the existing two-decimal statistics schema, from 0 to
999999.99. Unsupported precision is rejected, not silently rounded. New-path
scaling uses overflow-checked integer arithmetic and positive half-up rounding
to whole bytes, avoiding float loss above 2^53. Raw node statistics remain raw;
user quota/user statistics/user-node statistics use the frozen multiplier.
Existing counter overflow aborts the entire batch rather than wrapping/saturating.
Compatibility with deployed MySQL rounding/storage still requires its own gate.

`TrafficBatchApplyJob` runs on the existing `traffic_fetch` queue. In ONE database
transaction, it locks the receipt, applies quota + user daily + user-node daily +
node daily totals, then sets `processed_at`. All four changes roll back if any
write or the receipt update fails. A duplicate job sees `processed_at` and does
not repeat increments. It does not enqueue four independently acknowledged jobs
or retry a partial update through a fallback branch. Current statistics unique
indexes are required. MySQL requires all involved tables to use InnoDB.

SQLite ignores `FOR UPDATE`. The service issues a no-op key update before reads
inside each SQLite write transaction to acquire write intent and prevent a
deferred read-to-write upgrade deadlock. MySQL/PostgreSQL continue using row locks.
Accounting visits tables/user IDs in a stable order. Driver-specific concurrency
semantics are not inferred from SQLite tests for the other databases.

After accounting commits, quota sync is retried independently. Each user snapshot
is loaded under its primary-database user lock; durable user-sync state/events
use the existing `UserSyncService`. A sync failure does not repeat accounting.
Only after sync succeeds is the prepared payload removed, keeping compact dedup
identity/hash/receipt markers. `applied` does not claim WebSocket delivery; the
existing durable user delta/polling path remains necessary.
The final marker has its own three-attempt transaction and updates only unfinished
rows. Retrying this marker does not repeat accounting or overwrite an already
finished marker. Database/server-specific evidence remains required.

Queue dispatch is only a wake-up, not ownership. Failure leaves the SQL receipt
pending. `traffic-batches:recover --limit=200` runs each minute and re-enqueues
unfinished rows. A 120-second per-row dispatch lease bounds duplicate kicks;
ordering by retry time then ID prevents poison rows starving newer work. Even
exhausted queue attempts retain the receipt for recovery/diagnosis. Restoring
an old database backup or deleting receipts can invalidate dedup guarantees.

## Initial Panel-Only Verification

Local PHP 8.2.31 / SQLite, isolated unit-test containers and random temporary file
databases only. No real application `.env`, production database, remote host or
online node was used in this increment.

- Final focused gate: 21 tests / 275 assertions, natural PHP exit 0.
- Actual child-process kill before transaction commit: zero partial changes,
  receipt still pending. Kill after commit/before reply: exactly one increment
  remains, later replay does not repeat it.
- Two concurrent acceptors and two concurrent application workers: one receipt,
  one increment. Final source passed five repetitions of the three real-process/
  quota-sync tests, each 3 tests / 70 assertions / exit 0.
- SQL trigger failures at the last statistic and receipt update prove rollback
  of all preceding writes; lost queue dispatch, post-commit quota-sync failure,
  corrupt payload, poison-row recovery, ID conflicts and overflow are covered.
- Real user-sync state/delta event is emitted once after quota crossing. The
  WebSocket publisher is mocked; real broker delivery is not claimed.
- 1000-user exact-count batch: 641.71 ms in the final focused run, 706.15 ms in
  the full run. This uses local in-memory SQLite and per-row operations; it is
  not a network-DB throughput, hundreds-of-thousands-user or capacity result.
- Full final suite: 1775 tests / 9936 assertions, zero failures, ONE error, PHP
  exit 2. The error is unchanged `PaytaroTest` disabled-channel callback dataset
  #6: it expects an error response, while existing code permits callbacks for
  already-associated orders. It independently reproduces (7 tests / one error).
  Relevant callback/test Git blobs match HEAD. The full suite is NOT green.

Retained failures: first process fixture blocked on Windows anonymous-pipe reads;
logs moved to files. A subsequent full regression exposed real SQLite lock
upgrade exhaustion, fixed by write intent above; its failure log is retained.
No weakened concurrency/count assertion or hidden rerun is used.

Core repository `target/traffic-panel-20260920/` retains raw logs, JUnit, process
exit records, runner, verifier and `verification-r1.json`. Frozen panel snapshot
`source-identity-r1.json`: 947 repository inputs, SHA-256
`f7debaf5cb79a6abb1c356f184bab3b4ffd1d92ebee629f845ba6faf466d51fa`.
It records base commit `9188cf768c169b89fad377e48e8503e3d2f38cc8`, PHP binary hash,
runtime PDO drivers and callback blob identities. The preceding 169 native inputs
are verified unchanged. Vendor dependencies are represented by composer.lock,
not an independent full vendor-source attestation. Verifier explicitly reports
scoped acceptance true, full-suite acceptance false, release acceptance false.

## Remaining gates

1. Rust immutable per-target/per-chunk outbox is implemented behind the explicit
   agent.traffic_batch_receipts setting; see kelinode-rs/docs/TRAFFIC_HTTP_OUTBOX_20260920.md.
   Both deterministic receiver and real Laravel loopback/SQLite tests now pass.
2. Production queue daemon/broker recovery and wider database-server commit ambiguity,
   including old-version capability/rollback behavior. The bounded fixture uses
   real DatabaseQueue/DatabaseJob/CallQueuedHandler, not an Artisan/Horizon daemon.
3. Real MySQL/InnoDB acceptance and PostgreSQL quota-reset races. Scoped PostgreSQL
   READ COMMITTED SQL-wire faults now pass for admission/accounting/completion;
   see `TRAFFIC_POSTGRES_COMMIT_LOSS_20260920.md`, not a power-loss proof.
   PostgreSQL concurrency and two isolation levels now have bounded evidence,
   including retained serialization failures and successful same-ID retries;
   see `TRAFFIC_POSTGRES_ACCEPTANCE_20260920.md`. Process kills are not power loss.
4. Production Linux panel runtime, operational recovery/alerts, bounded backlog, receipt
   retention/archive policy, query count and high-cardinality performance.
5. Uncheckpointed core tails and whole-agent shutdown/reload/retirement remain
   separate open native work. No commit, release or deployment was performed.
