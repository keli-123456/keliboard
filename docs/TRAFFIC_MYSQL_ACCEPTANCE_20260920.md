# Isolated MySQL acceptance

## Latest r7: Real COMMIT-loss Cases Pass

Six real MySQL8.4.11 cases cover admission, accounting and completion, each
before COMMIT forwarding and after the server confirms COMMIT but its reply
is lost. 48 PHP calls include six expected native disconnect exits. Direct
snapshots plus six independent XML/SQL exports prove same-ID retry and a
second duplicate replay preserve exactly one receipt, charged17/26B and
raw11/17B. No duplicate charge or lost accounting. MySQL/unit natural0.

Same private r6 settings and allocator bound; no production/business changes.
Not a native-node rerun or Redis/Horizon acceptance. See core document
docs/HY2_MYSQL_COMMIT_20260921.md. Evidence seal412files/SHA
386088188c79b32acc07c8ba14ec24a104bdf99237992eb3a353cef899418de0.
Only obsolete stopped r4 datadir was removed AFTER361files were fully backed
up/hashverified locally and remotely. Backup94a4963cbaaa99044b5c74f67a480b65e9742b09380b0b32420da5631e770004
retained. DoNOTrepeat r4/r5/r1-r3 cleanup. r6/r7 datadirs remain. Next Linux
Redis/Horizon/backlog; no live tests, original long test not restarted.

## Latest r6: Current Workload Pass With Explicit Fixture Allocator Bound

Currentoptimizednode/mysql-r6 completes3nativeintegrations,123receiptactors,
9resetcases/fouractualInnoDBlockraces,8duplicatecronactors,2MyISAMguards and
16independentlymatchedXML/SQLexports. Allnatural0/noOOM. OnlyprivateMySQL
getsMALLOC_ARENA_MAX=2; unchanged512MiB/CPU80/SQLisolation/actors/deadlines.
No production/businesscode change. Memory stillapproachescap; thisdoesnot
erase r5OOM, prove r3timeoutcause orunconditionaldefaultMySQLrobustness.
See core docs/HY2_MYSQL_MEMORY_20260921.md. Stage sealed1325files/SHA
0073625469441555c72fa3ac08c1595a87892633aba0777f9f0dababbce76be2.

The exactr5crashdatadir waslaterdeletedunderuserauthorization AFTERall324
filesmatchedlocal+remotebackups;1000protectedfilesunchanged. Backupremains
b44a7abd033e2482bce03520cef971c672ec1c4ce8fe6765bdbe39ce51b8ad65.
DoNOTrepeatr5orr1-r3cleanup. r4/r6datadirsremain. NextactualMySQLCOMMITloss
andLinuxqueue/Redis/Horizon; noautomaticGit/release/productiondeployment.

## Latest Follow-Up, 2026-09-21

Historical failure records below are retained. New frozen-control mysql-r4
passes3native integrations,123receiptactors,9reset scenarios including4real
InnoDB lock races,8duplicatecronactors,2non-InnoDB rejections and16matched
SQL/XMLdatabase exports. Oldr3timeout did not reproduce; cause remainsunproven.

Current optimized Rust mysql-r5 passes3native integrations and123receiptactors,
then the unchanged512MiB cgroup OOMs while8PHPresetactors start. Rust processes
had already exited. Entire currentgateFAILED; nofullfinaldump/cleanMySQLstop.
Rawresults and all324crashdatadirfiles are backedup/hashverified, notdeleted.
This is not evidence of a Brutal leak or proof of the oldr3timeout'scause.
No business-code, production, deadline/isolation/resource-limit changes.

See `../../keli-core-rs/docs/HY2_MYSQL_DIAGNOSTIC_20260921.md` and latestTOP
of core target/hy2-remaining-20260919/FOLLOWUP.md. Combined seal2418files/SHA
c6360507914a240ecf41659c932c7f7c95767f286ef105ad083255dcb2edc4bf.
Currentnativeintegrationisverified; currentfullresource/reset/enginegate,
MySQLCOMMITloss andLinuxqueue/Horizon remainopen. No testjob islive.

## Later cleanup status

The cleanup wait described below is historical, not an outstanding authorization
request. The recorded execution at2026-09-20T02:34:24Z removed only the three
failed test `mysqldata` directories after verifying584 backed-up files and their
protected result files. The backup SHA256 remains
`34c76318a48edc7992c6e04202e00ba28ae202236278ae03e2c2281d088f75c6`;
its local archive was rehashed on2026-09-20 and matches the execution record.
A fresh read-only check confirms all three exact remote paths are absent.
Do not rerun that cleanup or count those486,891,520 allocated bytes as space
that can still be recovered. Other tests have since consumed space: any new
MySQL cluster still requires a fresh capacity check. No new MySQL acceptance
has run as part of this status correction, and the failed SERIALIZABLE group
and other gates below remain unresolved. Authoritative cleanup record:
`keli-core-rs/target/traffic-mysql-20260920/cleanup-execution-r1.jsonl`.

## Status: incomplete

Oracle MySQL Community Server 8.4.11 was exercised on the authorized Linux
test machine, inside the existing private scratch directory. This is not
MariaDB, a production database, release approval, or a full passing gate.
The final attempt `mysql-r3` failed in the last SERIALIZABLE replay group.
Its database was shut down cleanly and all test processes were reclaimed.

The default legacy traffic endpoint remains unchanged. This increment changes
only test fixtures and acceptance tooling; the earlier receipt/reset service
fixes are included unchanged. No application bootstrap, `.env`, live settings,
production data, package installation, or system-network changes were used.

## Runtime and isolation

- Oracle Debian bookworm packages are pinned at `8.4.11-1debian12`. Downloaded
  bytes were matched to pinned SHA-256 and HTTPS repository metadata before
  extraction. Package signatures were not independently verified.
- MySQL server/client/plugins and Debian libaio, libnuma, libmecab, PHP MySQL
  modules were extracted into a private runtime. No apt installation/update,
  maintainer scripts, or distribution MySQL service was used.
- Each attempt has a fresh random test database namespace and exclusive result
  directory. Fixture guards verify Linux/non-root/private netns, ownership,
  datadir, server UUID/version, acceptance nonce and durable server settings
  before creating any test schema.
- Actual processes use nobody/nogroup, PrivateNetwork, CPU 80%, MemoryMax 512 MiB,
  no swap, TasksMax 384, five-minute runtime cap and read-only system/home.
- MySQL uses only private loopback/socket, InnoDB, transaction log flush 1,
  sync_binlog 1 and binary logging. SQL session lock waits are bounded.
- `--secure-file-priv=NULL` disables server file import/export. The generated
  self-signed certificate and temporary pid-directory warnings remain recorded;
  these are isolated test instances, not a production hardening template.

## Retained attempts

1. `mysql-r1`: initialization succeeded; startup exited 1 because the compiled
   secure-file default points at an unavailable global directory. No business
   test ran. Fixed with the private instance's file-operation disable setting.
2. `mysql-r2`: server started and stopped normally. Native test exited 101 because
   Laravel's generated fixture index name exceeded MySQL's 64-character limit.
   Fixed test-only index names while preserving the exact unique columns.
3. `mysql-r3`: three native integrations passed, followed by both REPEATABLE READ
   and READ COMMITTED concurrency gates. SERIALIZABLE's first four eight-worker
   groups passed; the final nine-worker duplicate replay group exceeded 18 seconds.
   The runner failed and reclaimed actors. That failed group has no recorded
   natural actor exits and cannot be counted as successful.

All three units retain failed systemd state with MainPID 0 after collection;
they were not reset to disguise the failure. The archives retain their failures.

## Verified scope of mysql-r3

- Three real native core -> node outbox -> Laravel HTTP/receipt -> serialized
  database-queue job integrations exited naturally with 0 in 6.59/6.60/7.12 s.
  Each produced exact user/statistic totals 192/192, raw server totals 128/128,
  two completed receipts and one SQL quota-unavailable event. The external
  publisher is captured at the boundary, not delivered through real Redis.
- 114 concurrent operations have independently recorded natural zero exits:
  41 per completed isolation level plus 32 SERIALIZABLE operations. No external
  retries were needed for those completed operations. The remaining nine actor
  exits are unknown in the retained runner evidence.
- Six independent XML database exports were parsed and matched to expected byte
  totals, receipt completion/payload clearing and quota state. Five also match
  complete service snapshots; the sixth is the interrupted SERIALIZABLE case.
  Six raw SQL dumps are retained and hashed, not restored as part of this gate.
- The interrupted case's final database contains nine completed receipts and
  correct totals 18/45 (raw 9/27). This does not excuse the replay timeout or
  establish that the replay actors finished normally.
- CPU/memory figures from the native measurement are process-group samples,
  exclude the MySQL server and may miss short-lived workers. They do not prove
  memory savings or a production capacity limit.

The timeout root cause remains unresolved. The retained run has no in-flight
SQL/lock/cgroup snapshots at timeout, so neither a database deadlock nor memory
pressure is established. New optional fixture query timing and runner resource/
lock diagnostics are prepared locally, not yet exercised on Linux. The same
18-second deadline, actor count and resource limits are preserved.

## Evidence and local regression

Evidence directory: `keli-core-rs/target/traffic-mysql-20260920/`.
`verification-partial-mysql-r3.json` verifies evidence, explicitly records
`gate_passed: false`, and does not approve release.

| Archive | SHA-256 | Manifest files |
| --- | --- | ---: |
| linux-evidence-mysql-r1.tar.gz | bf3d4ccc5927e6d12f7ee6b9eec87eafddb94cc352f7ad4612de0f08480f04af | 71 |
| linux-evidence-mysql-r2.tar.gz | 2ac8d709f2679abff1fd70d17819fd21d5290ceb57140cbaf38665876fe6cb94 | 85 |
| linux-evidence-mysql-r3.tar.gz | a9c8435a1fdfd68a675ab2762576966fe543d5d5c51d4b30d14e257092dc1a28 | 635 |

The three stopped datadirs were streamed to a local backup without additional
remote archive disk usage: 584 files individually hash-verified, compressed
size 13,040,830 bytes, SHA-256
`34c76318a48edc7992c6e04202e00ba28ae202236278ae03e2c2281d088f75c6`.
Restoration was not tested; original remote datadirs have not been deleted.

After the optional diagnostics changes, local focused PHP regression passes
44 tests / 392 assertions (`traffic-mysql-focused-r4`, actual exit 0). The
default Windows SQLite/native integration also passes naturally in 11.82 s
(`traffic-mysql-default-sqlite-r4`). PHP lint and diff whitespace checks pass.
The earlier full-panel test run still has the separately recorded existing
Paytaro callback dataset error; this increment does not claim a green full suite.

## Remaining gates

At the last check the host had 2,099,695,616 free bytes, below the existing 2 GiB
safety floor. New cluster preparation has not been attempted under that floor.
Permission was requested to remove only these three failed datadir copies after
their verified backup; no response or deletion is recorded at this checkpoint.
Do not weaken the space guard, remove other evidence, reuse a failed attempt
directory, or silently move tests to a different host.

After space is safely available, use a new attempt with query/resource/lock
diagnostics to resolve the replay timeout. MySQL reset/accounting races,
non-InnoDB rejection, SQL COMMIT response loss and queue-daemon recovery remain
unverified. Broader live-counter, whole-agent, Brutal runtime, architecture,
WAN/soak and release gates remain separate and open.
