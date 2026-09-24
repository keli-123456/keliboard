# Shipped Node Control Plane Acceptance

The opt-in `tests/Fixtures/traffic_batch_node.php` extension and node hooks in
`traffic_batch_http.php` exercise shipped V1/V2 node routes in the isolated
traffic fixture. Enable only with `KELI_TRAFFIC_NODE_FIXTURE=1`, reset/Redis
fixtures active, and Linux UID 65534. It restores the real
`NodeRealtimePublisher`, uses HTTP polling with shared file cache, seeds only
private entities/settings, and never boots production `.env`.

Both actual embedded and standalone-core nodes plus the official Hysteria
v2.12.3 client passed against MySQL 8.4.11 / Redis 7.0.15 / Horizon 5.45.6 on
the approved private-loopback test host. Verified: real configuration, token
and machine authentication, group filtering, queued receipt settlement,
quota-generated user deletion, old TCP closure, fresh authentication refusal,
120-second cache invalidation, observer-driven restoration and fresh traffic.

Per mode: 14,080 raw bytes each direction, 21,120 charged each direction at 1.50,
three unique receipts/six POSTs, exact four-table totals, no failed jobs or
service errors, empty explicit traffic queue/outbox, zero final connection and
relay metrics, normal node/Horizon exit. Expected negative client exits 1.
No production PHP business logic was modified in this increment.

This is a minimal Laravel test application and seeded schema, not a full
production HTTP-kernel deployment or every Redis queue. It does not verify
WebSocket delivery, full site scheduling, WAN or production capacity. The
whole test group nearly reached 512 MiB (536,866,816 bytes, 423 max events,
zero OOM). Do not interpret this as adequate headroom or a core-memory saving.
Small live HY2 TCP tails below the batching threshold are not immediately
reportable; a separate completed stream triggers quota in this test. Bounded
live-counter latency remains a next step, not an already closed guarantee.

Full evidence and failures are documented in the sibling repository:
`keli-core-rs/docs/HY2_PANEL_QUOTA_20260923.md` and
`keli-core-rs/target/hy2-panel-control-r21-20260923`.
Raw r21 SHA256: `e8de9c5ebf4b529aae28dd91b3562f8c51097212b0cf7da0df67454f46d6f4c1`.
Previous r17-r20 failures and all backed-up private datadirs remain preserved.
