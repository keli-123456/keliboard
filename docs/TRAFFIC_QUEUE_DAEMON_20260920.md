# Traffic queue daemon acceptance

## Status and scope

Seven scoped Windows/file-SQLite scenarios pass with Laravel's actual
`queue:work` command, `Worker::daemon`, `DatabaseQueue`, serialized
`TrafficBatchApplyJob`, production receipt/accounting/reset services and real
quota SQL state/events. This is no longer the earlier one-job helper test.
The full queue gate is NOT green: two-worker SQLite contention remains a
documented failure/limitation, and Linux/MySQL/Redis/Horizon acceptance is open.

This increment adds only an opt-in test fixture and acceptance evidence. No
production source, queue configuration, runtime dependency or vendor code was
changed. No real application bootstrap, `.env`, production DB, maintenance
state, network service, Git commit or deployment is used.

## Fixture boundaries

`tests/Fixtures/traffic_batch_queue.php` is included only through the marked
CLI fixture with both `KELI_TRAFFIC_QUEUE_FIXTURE=1` and
`KELI_TRAFFIC_RESET_FIXTURE=1`. The default native integration does not include it.
WorkCommand/Worker are the unmodified installed Laravel classes. Their class
paths and SHA-256 are recorded along with those of the actual job/service.

The fixture initializes the real failed-jobs migration and uses the default
`database` failed-job provider, not a substitute in-memory failure counter.
It binds a fixture-only maintenance state, local exception reporter and private
file-cache restart signal. Quota notifications are captured at their external
publish boundary; real Redis/WebSocket delivery is not claimed.

CLI operations are bounded to 128 generated receipts, the actual 1000-user batch
boundary, at most 256 worker attempts and at most 150 seconds per worker. The
production job's tries=3, timeout=60 and backoff=[1,5,30] are unchanged even when
the command's generic tries option is 1. Only the 1/5-second delays are reached:
the third failed attempt exhausts the job, so the 30-second delay is not tested.

Job leases remain 90 seconds; dispatch recovery remains 120 seconds. No machine
clock, SQL reservation timestamp or receipt retry timestamp was accelerated.
Recovery invokes the actual `RecoverTrafficBatches` command after its due time,
but the operating-system scheduler/one-server lock was not exercised.

Windows has no pcntl in this runtime. Natural jobs complete well below 60 seconds,
and the outer harness bounds processes, but this does NOT verify the Linux
worker's signal-based job timeout or Horizon's supervisor lifecycle.

## Results

| Scenario | Evidence |
| --- | --- |
| Transient late statistics failure | Real trigger fails first two attempts; actual delays 1.014/5.019 s; third succeeds with exact 17/26 charged, 11/17 raw bytes. |
| Retry exhaustion and recovery | Three failures persist a failed-job record and retain the receipt; immediate recovery does not bypass its lease; recovery at 122.056 s succeeds exactly once. |
| Corrupt receipt with good backlog | Corrupt receipt remains unapplied after three failures; all 20 valid receipts complete without starvation; totals 40/100 charged, 20/60 raw. |
| 1000-user boundary | One genuine job processes 1000 users in 6.549 s; per-user 11/17, total 11000/17000 charged, 7000/11000 raw; 1001 quota snapshots remain valid. |
| Cache restart | A live idle daemon observes a private file-cache restart signal and exits naturally. |
| Process death before handler | Proven reserved-job barrier, actual kill, new worker naturally reacquires the same job at its original lease expiry; accounting occurs once. |
| Death after billing, before queue ACK | Proven delete barrier after committed billing, actual kill, new worker finishes the original job without changing receipt/accounting state. |

Measured retry reservation intervals were 89.888/89.816 seconds from the
microsecond event timestamps. Both were at or after `reserved_at + 90` using
Laravel's integer-second timestamps; these are not claims of >=90.000 elapsed
monotonic seconds. The job UUID remained unchanged and attempts increased 1->2.

Event-sampled peak PHP allocation was 23,068,672 bytes (22 MiB). This excludes
other processes/native core, is not OS RSS, and is not evidence of a production
capacity or memory improvement. The 1000-user figure is one bounded local run,
not a production throughput benchmark.

## Retained two-worker failure

In `windows-r3`, the initial harness used stop-when-empty. SQLite reservation
lock errors made Laravel's pop return no job, stopping the workers before the
backlog completed. The evidence was retained, not overwritten.

`windows-r4` keeps both daemons running for their complete 15-second observation
window without stop-when-empty. All 64 receipts become fully accounted and synced:
128/320 charged, 64/192 raw. However two jobs still have reservations after ACK
delete lock errors, and two reservation errors were logged as failed jobs.
There are four actual `database is locked` reports, not fabricated exceptions.

The installed DatabaseQueue::pop catches reservation failures and may fail the
selected job record. Its false/empty pop behavior and SQLite read-to-write lock
upgrade are outside the receipt service's transaction. A natural worker exit 0
therefore does not mean the backlog is fully acknowledged. The main harness and
independent verifier deliberately retain a failed full-gate verdict.

An additional isolated recovery restores the independently checked SQL database
copy after the original reservations have expired. Two fresh real workers clear
the two residual jobs; all non-queue tables, including every receipt, user byte
counter, statistic and quota event, compare exactly unchanged. Historical
failed-job records remain. This proves recovery of this restored checkpoint,
not a fix for the SQLite contention or a power-loss guarantee.

Do not change vendor code or globally alter production database isolation merely
to make this test green. The site's configured production queue is Redis/Horizon;
that driver and MySQL must receive their own isolated concurrency/daemon gate.

## Evidence

Directory: `keli-core-rs/target/traffic-queue-20260920/`.
`verification-windows-r4.json` records `full_queue_gate_passed: false`, seven
passing scoped scenarios, 49 natural zero exits and two intentional proven
kills, with no unexpected outer process timeout. Eight SQL dumps were restored
into fresh local SQLite files, integrity-checked and compared across all tables
against the original databases and recorded service results.

Earlier attempts are preserved: r1 smoke passed; r2 failed a Windows short/long
path barrier comparison (fixed by canonicalizing the parent); r3 failed its
two-worker drain. Their manifests contain 18/47/146 files respectively. The r4
manifest contains 195 files. No failed attempt was restarted or overwritten.

`restored-backlog-recovery-r1/result.json` separately records successful ACK
recovery with all non-queue database tables unchanged. It does not change r4's
verdict. The consolidated 459-file evidence bundle was reopened and every member
hashed against its manifest:

`windows-queue-evidence-r1.tar.gz`, 887970 bytes, SHA-256
`42c0270eb84bb80eb122fc170bea5d788eb76a8927c500cbb8b108f531f16eb8`.

Local focused regression remains 44 tests / 392 assertions, PHP exit 0
(`traffic-queue-focused-r1`). The default Windows native/SQLite HTTP integration
passes naturally in 11.62 s (`traffic-queue-default-sqlite-r1`). Both fixture
files pass PHP lint; diff whitespace checks pass. No fixture PHP child remains.
The full-panel suite was not rerun here; its previously recorded Paytaro callback
dataset error remains unresolved, so there is no full-panel green claim.

## Remaining work

Linux/MySQL/Redis/Horizon daemon retries, concurrent recovery scheduling,
signal-based timeout, larger/backpressured backlog, poison-row long-term cost,
and whole-agent accounting remain open. The existing MySQL SERIALIZABLE replay
timeout also remains unresolved. The authorized Linux host's disk-space guard
was not bypassed; its earlier three datadir copies remain untouched pending the
user's cleanup decision. No release/deployment approval follows from this gate.
