# Traffic Redis Outage Acceptance

2026-09-23, isolated Linux run `traffic-mysql-r13`: two cases pass.

- Redis process SIGKILL after 8 enqueues: AOF restart preserves the original
  ordered queue payloads/UUIDs. All eight jobs execute once, charged upload/
  download 16/40 B, raw 8/24 B, no failed jobs or duplicate receipts.
- Redis process SIGKILL after guarded helper boot: the real service accepts
  a new SQL receipt but enqueue fails with RedisException. The receipt stays
  pending and uncharged. After restart, real recovery commands before the
  original 120-second deadline do nothing; the next real minute-boundary tick
  enqueues once. Final charged 2/5 B, raw 1/3 B, one completed receipt.

Original retry_at 1790127776, successful recovery invocation
1790127780.001780. No manual deadline rewrite or clock advancement. The test
harness invokes the production recovery command at minute boundaries; it
does not bootstrap the full production scheduler or its distributed locks.

Both Redis crashes are actual -9 exits, with verified process identity and
private TCP connection refusal. Each new Redis PID/run_id is verified before
updating the owned fixture manifest. Existing boot guards remain unchanged.
Two Horizon masters/four workers, final Redis, MySQL and unit exit naturally
0. Horizon is started after recovery, not kept live across the Redis crashes.

New opt-in `queue-redis-outage` fixture action requires
`KELI_TRAFFIC_REDIS_OUTAGE_FIXTURE=1` after the existing Redis boot checks.
Its helper only records real service dispatch errors and coordinates a bounded
barrier. No business, vendor or production configuration changes this increment.
Six PHP fixture lints and exact tested-byte checks pass. Full PHP regression
is not rerun. Independent XML exports verify both databases; SQL dumps retained.

Raw evidence: 263 manifest files, SHA256
`32921bbbdf001f9d53ae00bf987ce2ac01d8940e0e561d9e7bcae0cff5c96140`.
See sibling `keli-core-rs/docs/HY2_REDIS_OUTAGE_20260923.md` and
`keli-core-rs/target/traffic-redis-outage-20260923` for controls, source identities,
local verifier correction, scoped backup/cleanup and full limitations.

This is not live-worker Redis reconnect, wire ACK ambiguity, power-loss,
large-backlog or production capacity acceptance. No commit, deployment,
production operation or original long-soak restart occurred.
