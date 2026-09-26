# Independent machine telemetry

## Scope

`kelinode-rs` samples Linux CPU, memory, swap, network counters/rates and uptime
every two seconds and sends the latest sample approximately every five seconds.
Sampling only reads host counters. It does not query the proxy core, run disk
utilities, probe public IP services, refresh users or report accounting traffic.
The first counter sample is warm-up and is not reported as zero utilization.
If no eligible network interface exists (for example a loopback-only network
namespace), `status.net` is null. CPU, memory, swap and uptime continue reporting;
missing network counters must not suppress all telemetry or invent zero traffic.

The existing machine status/control heartbeat is unchanged. Disk, IP discovery,
version, certificates, upgrades, user synchronization and accounting retain their
existing cadence. Fast telemetry never executes reload or upgrade responses.

## API and persistence

- Node: `POST /api/v2/server/machine/telemetry`, using the existing machine ID and
  token in the JSON body. The response must contain `data: true` and
  `telemetry_version: 1`. No credentials in URLs and no HTTP redirects.
- Payload: `session`, positive `sequence`, `status` with CPU/memory/swap/network/
  uptime only, and optional CPU/RX/TX peaks. Maximum request size is 8 KiB.
  CPU is percent; network rates are bytes/second, counters and memory are bytes.
- Request/body deadline: three seconds. Only the latest watch-channel sample is
  retained. A bounded three-sample window preserves short peaks between reports.
  Backoff on transient errors grows to 60 seconds. No retry queue or backlog.
- Missing, incompatible or unauthorized endpoints disable fast reporting until
  node restart/reload; the original full status reporting continues. Upgrade the
  panel first. Nodes that previously fell back need a restart/reload to re-probe.
- Latest samples and aggregates use the configured Laravel cache, with atomic
  per-machine locks and a 15-minute TTL. A shared cache such as Redis is needed
  when the panel has multiple web instances. Credential hashes scope cache keys;
  rotating a machine token invalidates access to its previous fast snapshot.
- Duplicate/out-of-order sequence numbers within a reporting session are ignored
  without refreshing the receipt time. The server's receipt time, not a client
  clock, determines freshness.
- Fast reports never update the machine database row, history table or billing.
  Full status reports persist at most one history row per machine per 60 seconds.
  History JSON includes `telemetry_summary`: report count, time bounds, mean CPU
  of received reports and CPU/RX/TX peaks. This is sampled telemetry, not an
  accounting total or a guarantee that every transient peak was captured.
- History cleanup is throttled to once/hour/machine; retention remains seven days.
  Cache/history failure must not suppress a successful control heartbeat.

## Admin page

`GET /server/machine/telemetry` is inside the existing administrator-authenticated
route group. It requires 1-200 distinct machine IDs and returns only monitoring
fields, never machine tokens or configuration. The page polls visible IDs every
five seconds, pauses while hidden, aborts on unmount, avoids overlap and backs off
on failures. A full machine list refresh runs about once a minute; metadata and
version lookups no longer block initial machine display or each metrics refresh.
Older panels fall back to the full machine list every 15 seconds.

Fast reports are fresh through 15 seconds, delayed through 60 seconds, and stale
afterward. Legacy samples use 90/300-second thresholds. These indicators are
separate from the unchanged control heartbeat online status. Displayed age
continues increasing even when requests fail and uses server age plus local
elapsed time rather than assuming the browser clock matches the panel.

## Verification and rollout

- Backend: machine, API contract and cache regression tests on local SQLite.
- Admin: server-page unit tests, production build, and desktop/mobile/dark/legacy
  browser checks using `keli-admin/audit/machine-telemetry-preview.html` fixtures.
- Node: default-feature Windows regression and local HTTP contract tests, including
  old-panel responses, bounded bodies, credentials in body and task cancellation.
- The original release guard found a mismatch between the node core lock and the
  tested sibling checkout. Release v0.1.363 aligns the lock to the tested
  `07d11dd147b6a20063dc5aa87ec694a818d99621`; the intervening commit changes only
  an acceptance document. The unfiltered local rerun passed 648 node tests,
  including this guard, with one existing ignored cost test. Release tooling
  passed 118 Python tests. No guard was bypassed for publication.
- No online systems were changed. On 2026-09-26, the isolated same-core Linux
  musl AB/BA comparison finished: 3/3 smoke cases and 12/16 full cases passed.
  Four whole-panel-timeout cases failed on both baseline and candidate because
  the tested direct-node configuration propagates user-refresh timeouts and stops
  the core. Full acceptance did not pass; the unit's real exit code was 1.
- Normal-load medians: goodput +0.16%, node CPU +0.124 percentage points, peak
  RSS +1.137 MiB. Telemetry-only timeouts retained forwarding, with goodput -0.44%
  and P99 +4.14 ms (+7.98%). Do not claim zero overhead or comprehensive memory
  savings. The one-CPU test group was quota-limited, with two repeats per variant;
  this is not production capacity or all-protocol/outage qualification.
- See `kelinode-rs/docs/MACHINE_TELEMETRY_PERFORMANCE_20260926.md` for scope,
  exact hashes, failure analysis and rollout gaps. Raw evidence and the explicit
  failure-preserving analysis are under `kelinode-rs/target/telemetry-perf-20260926/`.
  Local node regression: 637 passed, one existing ignored, one pre-existing release
  guard omitted. Backend machine controller: 46 tests / 199 assertions passed.
- Subsequent node-only recovery work adds a fixed 300-second verified-live grace
  for strict/direct transient user-refresh failures, without relaxing 401/403.
  Final local node regression: 647 passed with the same ignored/skipped exceptions.
  Isolated Linux checks verified 80 seconds of outage forwarding, authorization
  rejection, real grace expiry and account replacement after recovery. The first
  recovery fixture failed due to early client startup; its failed unit is retained,
  and the corrected standalone supplement passed with the identical binary.
  See `kelinode-rs/docs/PANEL_OUTAGE_RECOVERY.md`. This functional follow-up neither
  changes the earlier AB/BA failure record nor establishes zero performance cost.
- Release preparation rechecked the machine controller (46 tests / 199 assertions),
  contract/cache/service coverage (14 tests / 64 assertions), and admin server-page
  coverage (196 tests). These do not replace packaged Linux release gates.
- Deploy panel/admin first, then canary a new Linux node build. Check fast-report
  freshness, PHP/cache load, CPU/RSS, traffic accounting and reload/shutdown before
  widening rollout. Rollback can restore old node builds without a schema change.
