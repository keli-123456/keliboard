# R21 ICMP Read-Only Review And Test-Only Loopback Control

This is a local retrospective of [R21](machine-r21-result-20261115.md), plus an
opt-in diagnostic helper for future isolated tests. No live cloud, DNS, SSH,
production or tanzhen connection was made. R21 remains closed and was not replayed.

## Retained Evidence

The fixed targets remain `39.156.70.37`, `111.33.167.71`, `123.150.76.218` and
`157.255.219.143`. Source and candidate each sent four single-packet diagnostic
probes with zero echoes, zero reported ICMP errors and four
`no_echo_by_deadline` classifications. The saved iputils checks, source-attributed
unicast routes and agent network namespace checks passed for both machines.
No local route, bind or permission error was reported for those commands.

The saved provider readbacks showed no attached Linode Cloud Firewall for either
machine. Guest nftables had an ICMP input accept rule and a stable structure, but
seven rules lacked counters, 15 objects were unmodelled and effective policy was
not fully evaluated. No claim that all guest or provider filtering is excluded
can be made from this evidence.

Host-wide `OutEchos` increased by seven on the source and five on the candidate,
not four: the Agent was also collecting probes. `InEchoReps` increased by zero
on both. These shared counters are not per-probe attribution or physical-NIC
packet-capture proof. They do not locate an upstream, return-path or guest drop.

The missing evidence includes an on-machine positive control at that time,
per-packet interface capture, relevant rule hits and simultaneous independent
target health. The deleted test machines cannot supply it retrospectively.
The precise cause of the missing replies and actual censorship remain unconfirmed.

## Loopback Is For Tests Only

The existing diagnostic module now exposes
`entry_probe_diagnostics.loopback_selfcheck(run_id, task_id, role, enabled=True)`.
It is disabled by default and only operates after validating an owned isolated
source/candidate marker and the Agent's network namespace.

It sends at most one IPv4 packet from `127.0.0.1` to `127.0.0.1`, with a one-second
iputils reply wait and a six-second total collection budget. It uses the existing
bounded command runner and strict packet parser; it does not retry or change
routes, services, firewall rules, credentials or production settings.

Its result is separate from the four external targets. A positive result can
show that this diagnostic process can send and parse a local reply, not that a
public route works or every other service user has the same permissions. A failed
result is a local diagnostic failure, not a blocked-IP verdict. Neither result
counts toward domestic green status, consecutive failures or candidate admission.
The public-target validator still rejects loopback addresses.

Future newly approved drivers must bind stop/deadline and role/task checks before
calling it once, retain the separate result with independent readback, and budget
the extra loopback packet separately from external diagnostics. It does not run
automatically in the Agent, controller or existing closed drivers. This local
change does not authorize a new VM, a new acceptance round or changed production
automation. No loopback measurement is claimed for R21.

## Verification

Ten new mocked selfcheck tests cover default-off/no execution, owned identity,
single-packet arguments, the unchanged public-target rejection, missing tools,
permission/timeouts, malformed/oversized/late output, context changes and private
exception redaction. The focused 101-test set passed. The existing CI commands
already include this test module, so no workflow, trigger or file-count increase
is needed. No new dependency, node, backend, UI or queue change was made.

The broader current-tool regression runs with network and SSH/cloud/DNS commands
denied. Linux/POSIX-only cases remain explicitly conditional; no fresh Linux or
real loopback success is inferred from mocks. Original R21 frozen inputs and
closed/cleanup receipts remain unchanged. Machine-readable summary:
[offline selfcheck evidence](evidence/machine-r21-loopback-offline-20261115.json).
