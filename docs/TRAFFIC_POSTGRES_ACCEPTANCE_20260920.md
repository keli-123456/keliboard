# Isolated PostgreSQL traffic acceptance

Follow-up: `TRAFFIC_POSTGRES_COMMIT_LOSS_20260920.md` records a subsequent
production completion-marker fix and real SQL COMMIT fault tests. Results and
open items below describe this earlier, unmodified-service gate, not that fix.

## Result and scope

PostgreSQL 15.19 on the authorized independent Linux host now has real
Rust -> Laravel -> database queue -> four-view accounting evidence. This is
limited acceptance, not production readiness or proof of all-database behavior.
No production PHP/Rust logic changed in this increment. Only the acceptance
fixture and documentation changed. Default receipt reporting remains opt-in.

`tests/Fixtures/traffic_batch_http.php` keeps its default file-SQLite behavior.
With explicit `KELI_TRAFFIC_POSTGRES_CONFIG`, it includes
`tests/Fixtures/traffic_batch_postgres.php`. The latter requires Linux, non-root,
a private network namespace, a marked direct-child temporary directory, an owned
local manifest, loopback and a server identity matching the manifest's random
nonce, scratch data directory and exact version 150019 before any DDL. The
database name is a fixed prefix plus the fixture's validated random hex ID.
Neither fixture loads the real application bootstrap, `.env` or live settings.

The service, router, middleware, controller, jobs and accounting are the real
application implementations. Minimal schemas and the unit-test container remain
fixture-specific. There is no `user_sync_states` table, full HTTP kernel,
Artisan/Horizon daemon, production scheduler timing or WebSocket delivery claim.

## Verified scenarios

- Three explicit native integrations, each natural exit 0: 6.09, 6.10 and 5.57s.
  Each transfers two actual 64/64-byte authenticated SOCKS streams through the
  embedded core and verifies core prepare/ACK and immutable HTTP-outbox behavior.
- Request loss before SQL, queue insert failure, late statistics-write rollback,
  recovery, actual job retry, lost 200/applied HTTP response after database commit,
  embedded-runtime/PHP-server restarts and equal new traffic with a distinct ID.
- Every native fixture ends with user quota/user statistics/user-node statistics
  each 192/192 bytes, raw node statistics 128/128, two applied receipts with cleared
  payloads, zero queued jobs, empty outbox and no prepared core batch.
- READ COMMITTED and SERIALIZABLE each run five synchronized process groups:
  eight same-ID admissions, eight same-ID applications, eight distinct equal
  admissions, eight distinct applications and nine replayed applications.
  A start barrier ensures all PHP processes are ready before each group proceeds.
- A reused ID with changed traffic is rejected, without changing committed totals.
  Distinct IDs with identical content are correctly counted as separate traffic.
- For nine batches of 1/3 bytes at 1.50x, all three user views are 18/45 and raw
  node statistics are 9/27. This checks per-batch positive half-up rounding, not
  aggregate rounding or deployed MySQL parity. Every final receipt is applied;
  replaying all IDs does not increment any view.

There are 82 initial parallel CLI invocations. READ COMMITTED completed all 41
without external retries. SERIALIZABLE had 12 SQLSTATE 40001 failures: six in the
post-commit `sync_finished_at` update and six at the user-row accounting lock.
The first group is outside the service's transaction-retry closure; the second
exhausted bounded transaction retries. All 12 unchanged-ID retries converged with
exact accounting. Raw failures are retained. This establishes safe retry behavior
in this fixture, NOT that every serializable request succeeds on its first try.
Reducing redundant completion-marker writes/retrying that marker remains useful
follow-up work; increasing retries indefinitely is not justified by this test.

## Isolation and evidence

Debian packages were downloaded as nobody, checked against fixed-version APT
metadata SHA256/size and extracted into the scratch directory, without apt
installation, upgrades or maintainer scripts:

- postgresql-15 / postgresql-client-15 / libpq5: 15.19-0+deb12u1.
- php8.2-pgsql: 8.2.33-1~deb12u1, using the previous private PHP 8.2.33 runtime.

All test/database processes ran as nobody/nogroup in one private network namespace,
CPU80%, MemoryMax512MiB, swap0, Tasks384, Runtime300s, NoNewPrivileges,
ProtectSystem=strict and ProtectHome=yes. PostgreSQL used fsync, full_page_writes
and synchronous_commit, with JIT off and shared_buffers16MiB. Its trust
authentication exists only inside this isolated namespace and is not a suggested
production setting. The cluster was cleanly stopped, the unit collected/stopped,
and no scratch-owned executable remained. Host netns/links/qdisc matched the
previous gate. No host-global network rules or existing services were changed.

The native process-group RSS samples were 108140/98604/107976 KiB. These exclude
the independently started PostgreSQL server and may miss short-lived workers.
They must not be presented as total database cost or an optimization comparison.

Local evidence: `keli-core-rs/target/traffic-pg-20260920/`:

- `verification-r3.json`: independently verified 430 manifest files, raw traces,
  five SQL dumps, all four totals, source hashes, isolation and cleanup.
- `linux-evidence-r3.tar.gz` SHA256:
  `4300f7be7ca878aacedb0e64e709c34833ebdecde88d5d916f52dec86515dd8b`.
- `source-r3.json`: unchanged 108 core and 80 node inputs; only two panel fixtures
  differ from the preceding frozen integration source. The same validated Linux
  node binary was reused, SHA256
  `a61bf8188c9137f1cbc0e920a97d45a0f585aed46abd9859f3974fc91c823379`.
- Final Windows SQLite integration `traffic-pg-sqlite-r2`: 1 passed, exit0,
  11.51s. Panel `traffic-pg-receipts-r1`: 22 tests / 279 assertions, PHP exit0.
  Unchanged native full suites were not rerun in this fixture-only increment.

Retained failures: r1 was packaged locally but not uploaded/run after static
inspection found an unsupported `measured(..., env=...)` call. Remote r2 failed
before traffic: Capsule's cached SQLite schema object emitted `tinyint` to
PostgreSQL. Rebinding `db.connection`/`db.schema` after the driver switch fixed
the fixture. No production schema was changed or type coercion added. r2 was
collected/stopped, with its failed logs/dump retained; archive SHA256
`652d81d3b4f14c1e7e6b0496243d029c7df7cddc272a8f3fdf755667b0a6e3f1`.

## Open gates

Actual MySQL/InnoDB remains untested, and MariaDB is not an equivalent claim.
PostgreSQL commit-response loss at the SQL wire, real quota reset/accounting
races, daemon crash recovery, power loss and capacity/retention remain open.
Lost HTTP responses after commit are not SQL COMMIT ambiguity. Full panel
regression still has the previously recorded Paytaro callback dataset error;
this focused success does not make that suite green. Whole-agent lifecycle,
uncheckpointed native counters and the remaining Hysteria/ECH/Brutal release
gates also remain separate. No commit, push, release or deployment was performed.
