# Machine Runtime Insight

The admin machine list opens Runtime Health from its Agent status badge, details
popover or existing action menu. The dialog reads `GET server/machine/insight?id=`
under the existing administrator middleware. It makes one request on opening or
explicit refresh. Switching tabs and aging the displayed snapshot do not poll.

## Data and safety boundaries

- Host resources reuse `MachineTelemetryService::snapshot()`. Process resources
  use the last full status report's `metrics.keli_core_rs` numeric allowlist.
  Full-report freshness is independent of lightweight telemetry freshness.
- Missing process metrics remain null, not zero. Process CPU is expressed against
  one core. Host uptime is not process age. This endpoint does not run doctor,
  read logs, scan users, restart services or execute commands on the node.
- RSS and cumulative errors alone do not diagnose a memory leak or current failure.
  Nodes with `runtime.observation.schema=1` also report a per-process random
  session, PID, main-entry start time, monotonic uptime and cgroup-v2 OOM events.
  Unsupported counters stay null. The panel counts observed session changes,
  not all systemd restarts or activity before tracking began. OOM counters cover
  the current cgroup, potentially including other processes; historical totals
  alone are not a new OOM incident. No raw cgroup path or logs are returned.
- The response excludes machine tokens, full runtime config, per-user metrics,
  receipt messages and raw logs.

## IP history

Full heartbeats keep a panel-owned `ip_observation` inside the existing
`load_status` column. There is no schema migration or additional history write.
The observation history is excluded from minute-level load history snapshots
to avoid repeating the same address records throughout the seven-day history.
Each source (reported IPv4, reported IPv6, panel-observed IP) has its own last
valid baseline. IPv6 is canonicalized; absent or invalid values do not erase
that baseline. The first valid observation is not a change. An existing valid
full-report IP can serve as the initial baseline after upgrading the panel.

Keep only the latest 20 source changes per stable machine ID. Changes before
tracking began cannot be reconstructed. A panel-observed IP can be an egress or
reverse proxy, not the node's listening address. Never automatically rewrite
node hosts, domains, CDN addresses, certificates or SNI based on these records.

## Apply receipts

Read the existing WebSocket receipt snapshot once and select only the machine's
currently bound server IDs. Never assign a parent's or sibling's receipt to a
different node. Receipt text is omitted; only status, bounded event ID and time
are returned. Missing receipt storage means no receipt, not success.

These are the latest *event* receipts, not an end-to-end per-user action audit.
A node modification later than its config receipt is shown as unconfirmed.
This cannot prove every global setting was delivered. Historical successful
receipts are not proof of current online status. A queued/received event is not
applied. Nodes or connection modes that do not supply node-scoped receipts remain
unconfirmed; do not fabricate per-node success from machine-level activity.

## Release boundary

Deploy the matching keliboard API and keli-admin build together after normal
release checks. Basic read-only insight does not require a node upgrade or
migration; new process observations require the matching node binary. Old panels
without the new endpoint display a retriable error in the dialog. This change
does not enable health/IP notifications or change the existing node reporting
cadence. IP changes are recorded on subsequent full heartbeats after deployment.

## Staged upgrades

The machine page's Staged Upgrade dialog uses the existing panel release
repository and single-machine upgrade executor. No new sidebar page is needed.
This is local implementation only until a normal release deploys these changes.

Deployment order:

1. Back up the panel database and apply migration
   `2026_10_16_000001_create_machine_rollouts.php` using the normal panel upgrade
   procedure. It creates task and target tables with unique active-machine
   reservations and administrator-scoped idempotency tokens.
2. Deploy the matching API/admin build. The existing Laravel scheduler runs
   `machines:advance-rollouts` once a minute, on one server and without overlap.
   No browser, queue worker sleep loop or node polling-frequency change drives it.
3. Use an ordinary single-machine upgrade to install node observation support
   before attempting a staged upgrade from an older node. Unsupported reports
   cannot be used as evidence. Allow at least ten minutes of process uptime and
   three comparable full-report history samples spanning at least two minutes.
4. Select a panel-hosted Linux x86_64 kelinode-rs release with archive and binary
   SHA256, explicitly select the canary and up to 19 remaining machines, then
   confirm task creation. GitHub/custom-distribution and external-core modes are
   not admitted in this first version. No release or deployment is initiated by
   installing this feature alone.

Each scheduler tick locks the task and machine rows and dispatches at most one
machine per task. Artifact identity is pinned and checked again before dispatch.
Node reports cannot replace a rollout's expected hashes. A normal upgrade cannot
override a reserved machine, even with `force`. A full heartbeat resolves upgrade
state from the locked latest row so it cannot erase a concurrently queued task.

After a successful version/hash acknowledgement, the new process must complete
ten minutes of warm-up. Stable observation lasts the selected 10–120 minutes,
with at least three distinct full reports. QUIC connections, HY2 TCP streams and
UDP sessions are each matched within max(5, baseline * 20%). Load mismatch or a
sample gap over 180 seconds resets the observation window. Re-reading the same
snapshot does not accumulate samples. A fresh lightweight heartbeat cannot
rescue an expired full report. Only the latest five matched baseline/post samples
are retained, with a separate matched-window count; there is no per-user scan.

The last-three-sample median pauses the task if RSS exceeds both 125% of baseline
and baseline +64 MiB, or single-core CPU exceeds both 150% and baseline +10
percentage points. These conservative guardrails do not establish client latency,
throughput or absence of leaks. They may pause for unrelated workloads and need
operator review, not automatic rollback.

Other stop gates include failed/replaced upgrades, runtime binary mismatch,
stale/unsupported reports, extra process restarts, changed OOM scope, decreasing
OOM counters, increased OOM/OOM-kill counters or new-process trim/user-apply
errors. Upgrade confirmation times out after 15 minutes. Missing stable evidence
times out after max(2 hours, observation duration +30 minutes). Paused tasks do
not auto-resume. Previously passed machines remain monitored until task completion.

After the canary passes, the task waits for explicit human release before the
remaining machines proceed sequentially. Closing the window does not stop it.
Stopping prevents future dispatch only: an already queued/dispatched upgrade
cannot be revoked by this action. Existing node-side transactional failure
rollback remains unchanged; the panel never initiates automatic rollback.

Manual rollback requires a stopped, paused or completed task and a matching old
artifact still in the panel repository. The server selects the recorded baseline
version/hash, not a client-provided version. It stops remaining dispatch and queues
one deliberate ordinary binary upgrade to that baseline. Accounting data and
business configuration are never restored from history. Running upgrades are not
force-overridden. Completed/stopped task evidence is retained for 30 days; paused
tasks remain until an operator stops them.
