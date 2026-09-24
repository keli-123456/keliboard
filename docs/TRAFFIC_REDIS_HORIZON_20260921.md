# Real Linux Redis/Horizon Receipt Queue Gate

The opt-in fixtures `tests/Fixtures/traffic_batch_redis.php` and
`tests/Fixtures/traffic_batch_horizon.php` extend the existing traffic HTTP,
queue and quota-reset fixtures. No production configuration is changed.

All three explicit fixture flags are required; Redis also requires Linux,
nonroot execution, a private network, an owned private manifest, authenticated
loopback Redis 7.0.15 with the expected server run ID, and per-fixture prefixes.
The private harness does not read production `.env` or run the production
Artisan bootstrap. Its real Horizon master/supervisor/workers use pinned
Composer classes and the real TrafficBatchApplyJob.

Verified on approved isolated Linux host 164.92.77.55 with MySQL 8.4.11,
Redis 7.0.15, Horizon v5.45.6 and PHP 8.2.33:

- 64 pending receipts recovered through the real command and completed by
  two workers, 32 each, with exact charged/raw totals and cleared payloads.
- 20 valid receipts and one checksum-invalid receipt: valid work drains,
  invalid work retries three times and enters the real failed-job table,
  without charging or completing the invalid receipt.
- Two normal Horizon terminations, four WorkerStopping status-0 events,
  real parent/child PID evidence, Redis/MySQL/unit exit 0, no residual process.
- Independent XML and SQL exports, zero OOM, original resource limits kept.

The full result, failed attempts, resource caveats, retained backups and hashes
are in `../../keli-core-rs/docs/HY2_REDIS_HORIZON_20260921.md`. The sealed
runner and verifier are in the sibling core repository's
`target/traffic-redis-horizon-r3-20260921`; use a fresh stage to reproduce,
never write over sealed evidence.

Four local fixture files match the tested bytes exactly and passed remote PHP
lint. The original minimal cache stub was insufficient for Horizon termination;
the Redis-only fixture binds a real per-fixture CacheManager and explicitly
removes vendor default supervisors. The preceding r9 run remains failed.

Crash/ACK recovery, actual job timeout signals, Redis outages/restart,
120-second recovery leases and production-sized backlog are not passed by
this gate. The earlier SQLite concurrency failure is not relabeled as passed.
No production deployment, default change, node rerun or release approval.
