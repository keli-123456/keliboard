# PostgreSQL commit-loss acceptance

## Change

`TrafficBatchService::process()` now writes its final completion marker inside
a separate transaction with at most three attempts, conditional on
`sync_finished_at IS NULL`. The retry scope contains only bookkeeping, not the
already committed accounting or quota-sync loop. A stale worker cannot overwrite
another worker's completion timestamp. Non-retryable failure leaves the applied
receipt and payload available for a later retry. This adds a transaction boundary;
it is not a performance improvement claim.

The change addresses the completion-marker conflicts observed in
`TRAFFIC_POSTGRES_ACCEPTANCE_20260920.md`. It does not eliminate contention on
the user quota row or make all SERIALIZABLE requests succeed immediately.
Three focused regression tests cover one serialization retry, retained payload
after completion failure, and preservation of another worker's completion marker.

## Real SQL wire faults

The bounded private-test relay follows PostgreSQL's documented
[message formats](https://www.postgresql.org/docs/15/protocol-message-formats.html)
and [message flow](https://www.postgresql.org/docs/15/protocol-flow.html).
It faults the actual PDO/libpq connection, not the node's HTTP response:

- Before commit: observe a simple-query COMMIT while the transaction is active,
  then close both sockets without forwarding that COMMIT.
- After commit: forward COMMIT, consume the server's CommandComplete(COMMIT) and
  ReadyForQuery(idle), discard these responses and close the client connection.

The relay verifies the database, target commit number and preceding statement
phase. Admission/accounting target the first explicit transaction; completion
targets the second, after accounting committed. A success assertion requires
that the fault actually fired and its trace has no relay error. It does not log
authentication data, cancellation keys or SQL parameter values. Frame sizes,
socket waits and fixture process lifetimes are bounded.

All six phase/fault combinations were executed against PostgreSQL 15.19 with
READ COMMITTED, fsync/full_page_writes/synchronous_commit on. Every faulted PHP
invocation naturally exited 255 with a lost-connection error. An independent
direct connection then read the database before any same-ID retry:

| Phase | Lost before commit | Lost commit reply |
| --- | --- | --- |
| Receipt admission | No receipt; zero counters | One pending receipt; zero counters |
| Accounting | Pending receipt; all four views zero | Applied receipt; all four views committed; payload retained |
| Completion | Accounting remains committed; payload retained | Completion persists; payload cleared |

Each case used one immutable 11/17-byte report at 1.50x. After retry and a further
duplicate replay, each of the three charged views was exactly 17/26 bytes, raw
node statistics 11/17, one fully applied receipt and zero queued jobs. The relay
does not substitute a synthetic receipt: these are the shipped admission and
accounting services, with the real fixture schema and primary database.

This tests direct CLI service transactions specifically to avoid accidentally
faulting queue reservation commits. The separate native integrations below
exercise the real database queue. These tests do not combine SQL wire faults
with a whole-agent death or a long-running queue daemon.

## Regression and evidence

- Three native Rust -> Laravel -> PostgreSQL/database-queue integrations passed,
  natural exit0, no timeout, 6.60/6.07/6.07 seconds. Every final database has charged
  views192/192, raw128/128, two applied receipts and empty queue/outbox/core batch.
- Repeated 82 synchronized concurrent operations at READ COMMITTED/SERIALIZABLE.
  READ COMMITTED needed no external retry. SERIALIZABLE had zero escaped
  completion-marker failures, but six user-row serialization failures exhausted
  bounded internal retries. Unchanged-ID retries converged to exact9-receipt
  totals18/45 charged and9/27 raw. These six failures remain in the raw evidence.
- Windows SQLite native integration passed, exit0,11.46s. Focused panel regression
  passed25tests/324assertions. Full panel regression:1779tests/9985assertions,
  PHP exit2 with the same existing Paytaro disabled-callback dataset#6 error
  (`getStatusCode()` on a string); zero assertion failures. It is NOT full green.

Evidence is in `keli-core-rs/target/traffic-pg-commit-20260920/`:

- `verification-commit-r2.json` independently verifies615 manifest files,
  six positive SQL fault traces, all pre-/post-retry snapshots, eleven raw SQL
  dumps, natural exits, isolation, unchanged host networking and final cleanup.
- Final archive `linux-evidence-commit-r2.tar.gz` SHA256:
  `70cee3f5aed0ce9e83bf876c007ac7a9d1be73f57445498c2568ba0b1112dee1`.
- Frozen source manifest SHA256:
  `0e2471fe81397747a114faf9827d4b392cb354695e6547fcc8e93bd5f69e0650`.
  The production service SHA256 is
  `0143dfc56c408bd7d723485d0b63b827e4e0af5db07fe62271ade357c485e4b0`.
  Native inputs/binary are unchanged from the preceding PostgreSQL gate.
- Composer class resolution was positively checked to point at the patched
  immutable panel tree and matching service hash, not the prior vendor tree.

All processes ran under the prior non-root/private-network/CPU80%/512MiB/no-swap/
Tasks384/Runtime300s protections. The private PostgreSQL cluster stopped cleanly,
the test unit was collected/stopped and no scratch-owned executable remained.
Native process-group sampled RSS was108000/103548/98416KiB, excluding the separate
database server; do not call this total runtime memory or a comparative saving.

An aborted first attempt is retained, not counted as acceptance. Preparation
found source metadata/content absent from the older runtime-only bundle, but
launch was mistakenly invoked after that failed preparation. The test unit was
explicitly stopped and132 raw files were retained without a passing conclusion.
The next attempt includes every one of949 frozen panel inputs and requires a
successful hashed preparation marker before launch and again before execution.
The abandoned archive SHA256 is
`01266e759c4e73cf790599f449e643f6cfd99f1670901222bb29fa5766e386e0`.
No earlier evidence or production files were overwritten; changed hard-linked
overlay files were unlinked in the new tree before copying their replacements.

## Still open

This is PostgreSQL15.19/private-loopback/READ-COMMITTED commit-loss evidence, not
MySQL/InnoDB, power-loss survival, every database isolation mode or WAN proof.
Actual quota-reset races, queue daemon recovery, durable uncheckpointed native
tails, whole-agent lifecycle and the remaining Hysteria/Brutal/ECH gates remain.
No live database, production node, commit, push, release or deployment changed.
