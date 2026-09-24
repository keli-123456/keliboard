# Traffic Batch Real Horizon Timeout Acceptance

2026-09-23: two real first-attempt 60-second timeout cases pass on the
approved private Linux test host, run `traffic-mysql-r12`.

- Before handling: 60.001196267 s to JobTimedOut, same-UUID retry at
  89.146689852 s from first processing, final charged upload/download 2/5 B.
- After accounting and sync commit, before Redis ACK: 60.001042846 s to
  JobTimedOut, retry at 89.982852601 s, no additional charge, final 2/5 B.
- Both raw upload/download totals are 1/3 B; one completed receipt each.
- Two independent XML exports verify totals/receipt IDs. Full SQL exports,
  worker events, actual Redis scores and resource samples are retained.
- Timeout workers emit WorkerStopping 1, then Laravel itself kills them;
  Horizon observes exit 137/signal 9 and replaces them. Controller sends no
  worker signal. Four other workers and both masters exit normally.

The only fixture change adds explicit `KELI_TRAFFIC_REDIS_FAULT_TIMEOUT=1`
support and JobTimedOut observation to `tests/Fixtures/traffic_batch_redis_fault.php`.
Its default test hold stays 12 s; the timeout mode holds up to 75 s while
requiring the original job timeout 60 s. The real Redis retry-after stays
90 s, SQL dispatch lease 120 s. No vendor or business code change this increment.

The private run remains nobody/nogroup, private network, CPU 80%, 512 MiB,
zero swap, TasksMax 384 and runtime cap 300 s. Peak sampled whole-cgroup
memory 497,676,288 B, no OOM; this is not a production capacity result.
52 helper PHP commands exit 0; Redis, MySQL, masters and unit exit naturally 0.

Evidence: sibling `keli-core-rs/target/traffic-redis-timeout-20260923`,
raw 376 manifest files, SHA256
`905b338d7ee27d9ac2597feea61a5745f7d23a21e15869496177d6f430ca7c68`.
Detailed scope, controls, identities, cleanup and limitations are in sibling
`keli-core-rs/docs/HY2_REDIS_TIMEOUT_20260923.md`.

No live test process remains. No commit, deployment, production operation or
soak restart occurred. Redis restart/outage and SQL lease recovery are still
open, as are transaction-interior/repeated timeouts and lost Redis wire ACK
replies. This incremental gate is not general release approval.
