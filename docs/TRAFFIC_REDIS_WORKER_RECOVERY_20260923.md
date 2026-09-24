# Redis Worker Crash Acceptance

The new `tests/Fixtures/traffic_batch_redis_fault.php` is enabled only by
`KELI_TRAFFIC_REDIS_FAULT_FIXTURE=1` inside the already isolated Redis fixture.
It rejects root/non-private-network use and pauses only a marked real Horizon
worker. Ordinary production configuration and business logic are untouched.

On the approved private Linux test host, three actual SIGKILL cases pass:
before handling, after durable accounting but before Redis ACK, and after
Redis ACK. The real Horizon supervisor observes exit 137/signal 9 and starts
replacements. Two jobs retry after actual 90-second Redis reservations expire.
Independent SQL/XML evidence shows exactly one charge and one completed
receipt per case, with no failed jobs or residual queue entries.

The test subclass delegates the actual ACK to unchanged vendor code; it is
not a substituted production queue driver. Five local fixture files match
the linted/tested bytes. No production service, default, release or GitHub
publication was changed.

Full evidence, resource limits, exact results and scoped cleanup are in
`../../keli-core-rs/docs/HY2_REDIS_WORKER_RECOVERY_20260923.md` and the sibling
core repository's sealed `target/traffic-redis-recovery-20260923` stage.
Use a fresh stage for future tests, never rewrite sealed outputs.

Redis wire-reply loss, service restart, actual job timeout and SQL recovery
lease behavior still require their own gates. Do not infer them from this
worker-crash result or relabel earlier failed SQLite/Horizon attempts.
