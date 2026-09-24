# HY2 Client To Node Receipt Accounting

Bounded Linux run `traffic-mysql-r16` on the approved isolated test machine
passed with an actual official Hysteria v2.12.3 client and both embedded and
standalone-native node modes. Laravel server authentication, controller,
TrafficBatchService, MySQL and Horizon workers were real; panel bootstrap
metadata and users were explicitly simulated.

Each mode forwarded 4,096 TCP + 1,536 UDP bytes per direction and billed exactly
8,448 per direction at rate 1.50. After the gateway deliberately lost the first
actual `applied` reply, the node retried the same ID and content hash. There was
one receipt and one actual job execution, with no duplicate billing or legacy
fallback. SQL and XML exports independently match all four statistics views.

The tiny fixture quota (180 bytes) correctly caused one unavailable-state
event, without resetting traffic. Live delivery of that revocation was not
tested because bootstrap users stayed static. Production financial workflows,
WAN/capacity, host power loss and complete ECH lifecycle are not covered.

Two fixture-only changes support this check: an explicitly guarded pristine
HY2 initializer and correction of Horizon event `connection` to `connectionName`.
Both PHP lints and live queue logging passed. No production application logic
was changed in this increment. Original r15 bootstrap-route failure remains.

Whole-test memory nearly reached its 512 MiB cap (419 max events, no OOM),
so a pass is not a resource-headroom certificate. Core auto sizing also read
host rather than service-cgroup memory and remains a follow-up. See
`../../keli-core-rs/docs/HY2_NODE_MYSQL_20260923.md`,
and the r16 `verification.json`, `runtime-audit.json`, `resource-analysis.json`.

r16 raw archive: 267 manifest files, SHA-256
`18b96edd42f103bd1502e998b3ad05c368ef63c8636a75ff01175d863c61b309`.
r15 raw archive: 140 manifest files, SHA-256
`19c289b631122ce716631df26264d9737fb3b9736db26a96acdca3b092aa80c9`.
No automatic commit, release, production changes or deployment.
