# Traffic reset, accounting and quota synchronization

## Status

Scoped PostgreSQL 15.19 acceptance is complete for the cases below. This is not
MySQL/InnoDB, queue-daemon, WebSocket delivery, or production-release acceptance.
The HTTP receipt path remains opt-in. No live database, production configuration,
Git push, release, or deployment was performed.

This increment found and fixed a real reset consistency bug. `UserObserver`
deliberately treats synchronization as best-effort: it catches failures from
`UserSyncService`. Consequently, a reset could return success and commit cleared
quota/reset metadata while the node-facing SQL state still marked the user
unavailable. A later scheduled reset could then be skipped because its due date
had already advanced.

`TrafficResetService::resetLockedUser` now explicitly calls the actual
`UserSyncService` inside its existing user-locked transaction when sync tables
exist. Failed synchronization rolls back quota, reset metadata, reset audit and
SQL sync events together. A successful observer update is recognized as unchanged,
so the mandatory check does not duplicate events. Legacy schemas without sync
tables retain their previous behavior. Manual/ordinary scheduled failure keeps
the existing `false` result; calendar reconciliation still propagates exceptions.

## Test construction

`tests/Fixtures/traffic_batch_http.php` retains its original default behavior.
Setting `KELI_TRAFFIC_RESET_FIXTURE=1` enables `traffic_batch_reset.php`, but only
inside the existing marked, random temporary-directory fixture. It never boots
the production application or reads `.env`/live settings.

The extension runs the real reset service, user observer, quota service, database
transactions and reset/sync migrations. Only the external node publisher is
replaced: it records notification intent locally and asserts that its SQL event
exists after transaction completion. Redis/WebSocket delivery is not tested.

Deterministic coordination pauses one real service before its user UPDATE while
it holds the user row lock. A separate PostgreSQL connection observes the actual
waiter in `pg_stat_activity` and verifies the owner via `pg_blocking_pids`. No lock
is replaced by a mock. A second barrier pauses after accounting commits but before
the quota service reads and locks the current user state.

The authorized test host was `164.92.77.55`, in the existing isolated scratch
directory. Final unit: `keli-hy2-20260919-traffic-pg-reset-r2.service`. Tests ran as
nobody/nogroup, PrivateNetwork, CPU80%, MemoryMax512MiB, Swap0, Tasks384,
RuntimeMax300s, NoNewPrivileges, ProtectSystem=strict and ProtectHome=yes.
PostgreSQL had fsync, synchronous_commit and full_page_writes enabled. Fixed
extracted runtimes were reused; no host package installation or global network
change was made.

## Verified cases

For reset races, starting usage is 90/60 bytes. One immutable report contains
11/17 bytes at rate 1.50, adding 17/26 charged bytes and 11/17 raw node bytes.

1. Accounting gets the user lock first, repeated twice: reset audit captures
   107/86, remaining quota is 0/0. Historical statistics retain 17/26.
2. Reset gets the user lock first, repeated twice: audit captures 90/60,
   remaining quota is 17/26. Old audit plus remaining usage is still 107/86.
3. Reset between accounting COMMIT and quota synchronization: synchronization
   reads the reset user and does not emit a stale unavailable event.
4. Eight simultaneous scheduled resets: exactly one applies; seven return false.
   One audit/count increment, a future due date, and no duplicate event.
5. Actual SQL reset-audit INSERT failure: quota/reset/sync event rollback, no
   premature publish. Remove the fault, retry cron once, then duplicate cron
   remains a no-op.
6. Actual SQL quota-state UPDATE failure after accounting COMMIT: receipt stays
   processed but unsynchronized with its payload retained. Reset then retry/replay
   does not reapply old charged bytes; the completion marker clears the payload.
7. Actual SQL quota-state UPDATE failure during reset: despite the best-effort
   observer, the reset returns false and rolls back all changes. After removing
   the fault, cron produces one reset and one committed available event.

The first two cases count as four controlled lock races; total is nine scenarios.
All receipt replays preserve audit/events and historical statistics.

Three native core -> outbox -> Laravel HTTP -> real database queue integrations
also passed with the actual SQL quota branch enabled. Two equal fresh64/64
exchanges produce two receipts, final user192/192 and raw128/128. At the180-byte
quota threshold there is one unavailable event/publish, never repeated by retry.

The82 initial concurrent receipt operations at READ COMMITTED/SERIALIZABLE were
rerun. READ COMMITTED needed no external retry. Six SERIALIZABLE user-row reads
exhausted internal retries and exited255; unchanged-ID retries converged without
rebilling. Their original errors remain in evidence. This is not an all-first-try
success claim or queue-daemon retry-timing measurement.

## Regression and evidence

- New `TrafficResetAtomicSyncTest`: four tests. Before the production fix, two
  explicit failures demonstrated false success in manual/calendar reset.
- Final related panel suite:44 tests/392 assertions, PHP exit0.
- Final full panel suite:1783 tests/10018 assertions, PHP exit2. One unchanged
  `PaytaroTest` disabled-callback dataset#6 error (`getStatusCode` on string), no
  assertion failures. Full suite is NOT green.
- Windows default SQLite/native integration: natural0,11.19s. Three preliminary
  isolated SQLite reset scenarios also passed before the additional reset-sync fix.
- Final Linux native processes: natural0, no timeouts,6.09/6.07/6.60s.
  Sampled process-group RSS peaks97732/99428/102624KiB exclude the PostgreSQL
  server and may miss short-lived children; no memory-efficiency claim is made.
- Final independent verifier checked773 manifest files and14 raw SQL dumps,
  source/reflection hashes, receipts, quota/audits/events, notification intents,
  exits, resource restrictions and unchanged host network namespace/links/qdisc.
- Final unit was stopped/collected; MainPID0, inactive/dead, PostgreSQL clean stop,
  and no remaining scratch-owned executable. Old evidence/clusters are retained.

Local evidence: `keli-core-rs/target/traffic-reset-20260920/`.
`verification-reset-r2.json` is the final successful independent result.
Evidence archive SHA256:
`b8ae6d5b0b3c0bd68e907d1d83939adf9e8dc76cba721921732b32da81693378`.
Source manifest (core108/node80/panel951) SHA256:
`9190b0b020d887afdd8aeb0a7330156f968a9791b811d41e99488adde1736c39`.
Patched reset service SHA256:
`17abc04cffc44917233ed4cb2f274fc0ecafd1951507d97715bec7c5b8ccefda`.

Earlier reset-r1 passed eight narrower scenarios before the newly reproduced
observer issue was covered. Its742 files/13 dumps and verification are retained;
it is not acceptance of the later fix. Archive SHA256:
`2bfd497edea30f692cbd97aa13961c540916c53f0f6b624bc74450de6965de8d`.

## Remaining boundaries

Quota allocation follows the actual accounting/reset lock order, NOT the time
the traffic originally occurred. No usage-period epoch exists in this receipt
contract; delayed old-period reports are not automatically reassigned. The tested
invariant is conservation across reset audit plus remaining usage, and no rebill
when an already-accounted receipt is replayed.

Reset-specific failure tests use READ COMMITTED. They do not prove all isolation
levels, every reset reason, power-loss recovery, a complete queue-worker lifecycle,
or guaranteed external notification delivery. MySQL/InnoDB, whole-agent death,
counter tails, bounded backlog, WAN and release/soak gates remain open. The earlier
SQL COMMIT-loss gate has a different fixture transaction layout; it was not silently
reused as proof of new observer/reset transaction paths.
