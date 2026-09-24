# Live Redis Outage And Traffic Receipts

The 2026-09-23 `mysql-r14` isolated Linux gate passed on its first launch.
Full record: sibling `keli-core-rs/docs/HY2_REDIS_LIVE_OUTAGE_20260923.md`.

Real Redis was stopped by SIGKILL while real Horizon workers were paused
before handling or before ACK. Outages lasted 8.2676 and 8.2893 seconds.
Original master/supervisor processes survived, reported Redis errors and
resumed. Four old workers exited naturally with code 0 after stale-connection
errors; Horizon replaced them automatically. All eight worker lifetimes and
both masters ended with code 0. No manual worker restarts were used.

AOF restart restored the exact original reserved payloads, UUIDs and 90-second
deadline scores. Both jobs had attempts 1 then 2, without SQL re-enqueue or
artificial lease changes. SQL inspection while Redis was down and independent
final XML exports show one completed receipt and exactly charged 2/5 bytes,
raw 1/3 bytes, per case. No duplicate accounting, failed job, quota reset or
user-sync event occurred. Two SQL dumps and all original logs are preserved.

This increment changes only an explicitly enabled test observation hook in
`tests/Fixtures/traffic_batch_redis_fault.php`; application and vendor code are
unchanged. Original identity guards, queue retry policy, non-root/private-net
constraints and resource limits are retained. Six fixture lint checks passed.

Known test-only warning: the existing Horizon event logger reads `connection`
instead of `connectionName`. Six warning lines are preserved, and that optional
log field is null. Exact job identity, real driver, reservation state and SQL
were checked independently. Correct the logger separately, not by editing
the sealed results.

Raw archive: 420 manifest files, SHA-256
`d33bd5870e24713212a279b613396d785a889284f54a087f7fd3b97965655acb`.
60 CLI calls, final Redis/MySQL/systemd and two masters exited 0.
418 resource samples span 209.2503 seconds; whole-group peak 508,932,096 bytes,
no OOM. Not a memory saving or production capacity result.

This proves recovery of the bounded original reservations after a live Redis
outage, not a lost ACK reply, delayed-job AOF recovery, power loss, large-scale
capacity, full production scheduler, or complete node-to-panel accounting.
No online change, release, Git push or long-soak restart was made.
