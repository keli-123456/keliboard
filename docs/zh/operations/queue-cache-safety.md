# Queue and cache safety

## Upgrade behavior

Updates preserve the application cache, including verification codes, cooldowns,
distributed locks and pending queue data. They clear only code caches and build
runtime configuration before recreating the application services. A running
container is not sufficient: every enabled Horizon queue must have consumers.
The safe deployment runner rolls back if this consumer gate fails.

`php artisan xboard:queue-health --local --wait=30` verifies startup on this host.
Without `--local`, it checks consumers across the Horizon deployment. The command
returns nonzero for missing consumers, a missing environment plan, paused masters,
or unavailable runtime evidence. It never restarts services or deletes jobs.
The scheduler logs unhealthy consumers once per minute; the system status API
also reports them as unhealthy. Alerts do not depend on the broken email queue.

## Existing installations

Legacy `CACHE_DRIVER=redis` installations retain their current connection unless
explicitly changed. This is intentional: silently moving the cache would lose
active verification codes, rate limits and lock ownership. `cache:clear` and its
use by `optimize:clear` now refuse an unverified Redis database or one shared with
Horizon, any configured Redis queue, or Redis sessions. Redis key prefixes do not
make a database safe for FLUSHDB. Do not use redis-cli FLUSHDB as a workaround.

New `.env` files select `CACHE_REDIS_CONNECTION=cache`. The `cache` connection
defaults to database 1, while queue/Horizon use `default` database 0; Compose also
provides a dedicated Redis cache instance. Verify URL overrides and custom Redis
settings, which can otherwise point both connections at the same database.

Do not change the live cache connection independently on one service. A separate
migration must account for web, Horizon, scheduler and WebSocket workers together,
in-flight OTPs, cooldowns and distributed locks. Pause relevant writers while
preserving TTLs and cache keys; do not copy queue/Horizon keys or delete the source
database. Queue protection already works before this migration and is sufficient
to prevent the destructive upgrade race.

## Statistics job retries

The three `stat` jobs commit their sorted batch upserts and delivery receipt in
the same database transaction. A rollback retries the whole job; a delivery
with an already committed receipt does not increment traffic again. Receipt
records in `v2_stat_job_receipt` are not cache entries and must be retained.

For a future rollout, apply the new receipt migration before starting the new
workers and let all old in-flight writers finish before switching. The migration
checks the statistics keys and requires InnoDB on MySQL/MariaDB. Do not bulk
replay failures from before this version: there is no receipt to prove which
increments the old code had already committed.

## Validation boundary

Consumer records are runtime heartbeats, not proof of email inbox delivery.
After recovery, verify a newly requested code. Do not batch-retry expired codes.
Mail-provider pacing and retry backoff are separate safeguards from this fix.
Neither node protocols nor traffic forwarding code is changed.
