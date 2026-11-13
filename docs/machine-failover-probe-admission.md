# Failover Probe Admission

## Operational Rule

The source may already be unreachable when observation begins. A previous
healthy sample is not required. A policy can qualify only after its configured
number of distinct, fresh and consecutive complete all-target IPv4 failures.
Any configured target reachable is healthy and resets the failure streak.

The collector still binds samples to policy/configuration revisions, installation
identity and observed IPv4 source addresses. Duplicate or too-fast samples do
not increment the streak. Gaps, unknown results, missing targets, tool errors,
stale reports, foreign sources and changed identities cannot accumulate failure
evidence. IPv6 does not establish mainland blocking.

`baseline_at` remains nullable historical metadata. The existing episode hash
format is preserved, including a null baseline, so refreshes cannot multiply
requests for the same failure episode. Removing this prerequisite does not
authorize execution or rewrite old plans and outcomes.

## Control And Candidate

The controller must prove resource ownership, successful bootstrap, required
runtime services, restricted firewall, key-only SSH, closed public HTTP and a
working isolated control pipeline. Its own mainland Ping success is not required.
The reusable Python readiness predicates implement this contract without sending
packets or performing any transport. The canonical installation runner uses and
freezes them. Future full-round drivers must use the same predicates when newly
generated; the closed R13 driver is not changed or executable authority for this
rule.

Candidates still need a fresh reachable configured IPv4 target, matching
installation/configuration/binary evidence and validated TCP/UDP forwarding
receipts before DNS cutover. DNS ownership, readback/TTL checks, credential
availability, concurrency, attempt limits, cooldown, deduplication, stop control
and all execution gates remain in force.

## Acceptance Scenarios

- Already-unreachable source: collect valid failures, reach the threshold, then
  verify the candidate before any DNS mutation. No artificial healthy baseline.
- Controlled fault injection: establish a healthy source before injecting a
  bounded reversible fault, to demonstrate a real transition. Failure to meet
  this scenario condition does not prevent operational failover elsewhere.
- Controller: zero mainland echo replies alone must not block source creation.
  Missing control/security readiness still blocks it.

Local regressions cover cold-start collection, shadow admission, preview/episode
deduplication, native orchestration with mocked transports, candidate rejection,
source recovery and controller readiness. Synthetic success is not real cloud,
HY2 or DNS acceptance. No deployment, creation, remote connection, switch change
or R13 rerun is part of this change. Historical approved scopes remain historical;
a new real acceptance run requires a new scope and new frozen artifacts.

Local results: PHP 631 tests / 9,473 assertions; admin 56 tests and its bundle
gate passed; Python 329 passed / 26 skipped out of 355. Skips are platform/runtime
prerequisite limitations, not Linux acceptance. See the
[local evidence](evidence/failover-probe-admission-local-20261113.json).
