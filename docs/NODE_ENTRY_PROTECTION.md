# Node entry protection: observation phase

The objective is reducing avoidable exposure of proxy entry points, not penalizing
ordinary device/network changes or claiming to prevent every IP block. This phase
correlates bounded subscription response candidates with existing machine-wide
failure counters. It does not change subscription contents, credentials, old rules,
AI authorizations, node listeners, or reporting frequency.

## Data path

- The existing access hook buffers normal requests until successful rendering.
  Blocked/error access observations still use the existing snapshot path.
- After a successful 2xx render, the client controller records only real positive
  node IDs from the filtered candidate list. Synthetic website entries, addresses,
  passwords and configuration bodies are not collected. Rendering is not proof of
  client receipt or use; individual exporters may omit unsupported entries.
- Access and response evidence share the existing nonblocking account lock,
  five-minute bucket, one snapshot/account/minute and global write limits. No model
  request runs on the subscription path. Failed renders, cache contention, quota
  limits and buffered tails can lose observations; counts remain lower bounds.
- At analysis time, at most 16 candidate IDs are checked against the account's
  current enabled/visible group authorization. Up to 8 active machines contribute
  allowlisted shared counters. Provider references are account-scoped hashes, not
  IPs, domains, emails, tokens or raw node names. A candidate is not proof of leak.

`kelinode-rs` projects four existing cumulative metrics into `entry_security`,
schema 1: TLS handshake failure/backoff rejection and TCP authentication
failure/backoff rejection. It adds no timer, packet collection, per-IP map, new
dependencies or synchronous I/O. These existing counters do not cover every
protocol or every QUIC authentication/handshake path. Unsupported values are null,
never fabricated zeros. Older full reports work through the legacy metric names.

The panel computes differences only between adjacent full reports, at most 300
seconds apart, from the same embedded-core process epoch. Counter decreases,
unknown/external core identity and gaps do not create spikes. External cores can
restart independently of the agent, so only cumulative totals are shown there.
Machine details show these observations under **IP History / Entry Protection**.

## Safety and evaluation

Machine-wide counters cannot identify a subscriber or prove hostile scanning.
When actual machine context is sent to Luna, allowed actions in this phase are
restricted to `observe`; a proposed account penalty is rejected. This restriction
is enforced in validation, not only the prompt. Existing gates, scope, hourly
budget and old deterministic rules remain unchanged. No notification or production
activation is authorized by this feature.

The new evidence/code is included in benchmark fingerprints. Existing runs are not
rewritten, relabeled, cleared or silently combined with a new experiment. A stale
admission fails closed. The account-focused comparison is not an evaluation of
IP-block prevention: operator-confirmed leakage cases, availability measurements,
false-positive impact and incident response time require separate evaluation.

## Optional domestic-direction ICMP criterion

Server Management / Domestic Probes contains `server_entry_probe_enable` (default
false), `server_entry_probe_targets` (up to 8 public literal IPs), and
`server_entry_probe_interval` (5-600 seconds, default 120). Short intervals require
the matching new agent; published v0.1.382 accepts 60-600 seconds. Administrators choose
and verify the targets' location and ICMP availability; the software validates
public address scope, not country. No default target is silently activated.

Desired configuration travels in the existing authenticated **full machine status
response**, as `entry_probe` schema 1. It includes a deterministic revision, stable
target IDs, interval and a fixed 2-second timeout. This does not reload core proxy
configuration or increase status reporting frequency. Old agents ignore the new
command and remain unknown. Inactive machines receive a disabled command.

An updated Linux agent uses only `/usr/bin/ping` or `/bin/ping` verified as iputils.
There is no shell, DNS resolution, install, privilege escalation or change to
capabilities. Missing tools, unsupported ping implementations, permission errors
and execution errors report `error`, not `unreachable`. Private, link-local,
reserved and transition addresses are rejected by both panel and agent.

The Rust machine installer now checks for iputils and attempts to install the
missing optional package (`iputils-ping` on Debian/Ubuntu/Alpine, `iputils` on
RPM-based systems/Arch). Existing compatible tools are kept; installed but broken
packages are not forcibly reinstalled. Failure emits a warning and continues the
node installation. It never enables probes or changes capabilities, and does not
refresh Arch repositories or initiate a system upgrade. This installer change
does not alter already running agents or require a new node binary; existing
machines need an operator-requested installation of the missing package.

The agent starts one shared worker only after opt-in, runs at most two child
processes at once, and retains at most 8 panel/machine profiles. Identical command
renewals do not cause extra probes. A 600-second lease stops new probes if control
responses cease; disable/removal invalidates cached results. An in-flight command
has a bounded deadline. With no active profile the worker waits without periodic
polling. Results piggyback the next full status report; per-target raw output and
addresses are not returned by the agent.

The newer worker tries the most recently successful target first and stops on
any success. Remaining targets are bounded-concurrency fallbacks, not assumed
failures when skipped. It waits the configured interval after the completed
round, with no overlaps or catch-up queue. New reports contain a 0-2 consecutive
failure count; only complete all-unreachable rounds advance it. Errors or success
reset it. An in-flight round cannot publish after disable/re-enable or a changed
configuration generation, even if the configuration content is restored.

The existing five-second telemetry accepts a bounded optional probe report and
stores it only in the credential-scoped telemetry cache. It does not touch machine
liveness, control/configuration, users, billing or history. Cached probe receipt
time is separate from resource time, identical/older measurements do not renew it,
and a newer full status carrying an older probe does not override a newer fast
measurement. Insight and AI context use this same merged observation; model
context still strips target IPs. The open detail view uses the existing list
refresh, not an additional polling loop, and expires observations locally.

The panel derives the operator's requested criterion, rather than accepting a
client-supplied verdict:

- Any configured target reports `reachable`: `status=reachable`,
  `blocked_by_policy=false` ("block criterion not triggered"), even if others fail.
- Every configured target reports `unreachable` for two complete new-agent
  rounds: `status=suspected_blocked`, `blocked_by_policy=true`. One round remains
  unknown pending confirmation. Legacy reports without a consecutive failure
  count retain the previous one-round criterion.
- Missing/error results with no success, changed configuration, unknown target
  IDs, duplicate IDs, invalid or expired reports: `status=unknown`, verdict null.
- Disabled/unconfigured states are explicit. Full status age is limited to 300
  seconds; probe age to twice the interval plus 30 seconds, capped at 900 seconds.
  Future timestamps beyond 30 seconds are rejected. Open details expire locally
  at `valid_until` without issuing more network requests.

This is **outbound ICMP round-trip evidence and an operator-defined project
criterion**, not proof of censorship, inbound TCP/UDP availability, every Chinese
network's reachability, or any subscriber's behavior. Independent domestic-to-node
vantage points are not deployed. ICMP may be filtered or rate-limited independently
of proxy traffic. Machine details show target IPs; the model gets only the verdict,
timestamps and counts, with target details removed. These observations alone do
not enable AI enforcement, notify users, restart nodes, change credentials or IPs.

## Verification scope

Synthetic tests cover missing/stale/malformed metrics, resets and restart epochs,
group isolation, candidate/machine limits, privacy, response filtering, observer
failures leaving delivery unchanged, snapshot budgets and the observe-only action
gate. Probe tests cover opt-in, public IP validation, bounds, one-success/all-fail
semantics, missing/error/stale results, configuration revisions and authenticated
control/report projection. Rust tests cover bounded state, leases, revision changes
and report encoding; frontend tests cover configuration round trips, missing values,
fresh/stale counters and expiring project verdicts. Local tests and Linux compilation
are not production traffic, multi-region reachability or resource benchmarks.
