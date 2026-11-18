# R35 Preparation Review and Fresh Live Proposal

Status: **local and isolated preparation only; no live authorization**.
The connected retry and cleanup sequence passes with the complete application,
dedicated MySQL/Redis, HTTP/TLS and actual non-root service permissions. Cloud,
SSH and DNS effects remain simulated. This is not a real-machine acceptance.

The historical R35 approval expired on 2026-11-17 at 13:38:08 Beijing. It cannot
be reused. R34 and all earlier live rounds remain closed. No main-branch commit,
production access, deployment, cloud creation or persistent switch change is
part of this preparation.

## Verified Evidence

| Check | Result and scope |
| --- | --- |
| Connected full round | [Latest Linux record](evidence/linode-r35-prepared-cleanup-linux-20261118.json): all ten initialization stages, A1 absence reads 13 seconds apart, A2 creation 302 seconds after confirmed cleanup, B unchanged, DNS cutover, private material removal and controller-last cleanup. Provider effects and node telemetry are simulated. |
| Partial initialization | [Partial source cleanup](evidence/linode-r35-partial-source-cleanup-linux-20261118.json): A registered and reported; stop before B/native authority; exact source RPC deletion, cleanup input preparation and lost controller DELETE acknowledgement recovery. |
| Stop and contention | [Native stop](evidence/linode-r35-native-stop-linux-20261117.json): real Linux locks, separate-process admission, SIGTERM release and stopped callback rejection. This is component coverage, not every possible full-round signal point. |
| Crash recovery | [Outer SIGKILL](evidence/linode-r35-outer-crash-linux-20261118.json): real process termination after callback receipt, disk recovery without replay, first error retained and cleanup confirmed. |
| Expiry and permissions | [Import expiry](evidence/linode-r35-prerequisite-import-linux-20261117.json) uses an actual elapsed import-authority deadline; [entry regressions](evidence/linode-r35-connected-entry-linux-20261118.json) test bounded transport and stale/expired bindings. Do not describe this as a real elapsed full native lease. |
| Final local regression | 330 Python tests, no failures, 73 Linux/service-specific skips. Latest Linux run separately reports 264 Python tests with one skip, and PHP suites of 144, 21 and 53 tests. Counts overlap across runs and must not be summed as unique coverage. |

The public constructor has separate lock/wiring tests. The full-round Linux
coordinator drives the same concrete connected ports, not a standalone shell
invocation of every operator input. OS package installation, public certificate
issuance, actual node execution in this full-app suite, provider mutation rights
and public DNS propagation still require their own real-environment checks.

## Explicit Fault Boundary

The connected setup currently exports no injected-fault handles. It does not
implement controlled ICMP injection or its watchdog/restoration transport.
A nonempty unknown fault handle is rejected; an empty list is not restoration
evidence. The previous R35 injectable live scope is therefore **not ready**.

The reduced draft below permits only naturally failing nodes. This is a proposal,
not a change to an existing approval. If deterministic failure on a healthy
source or A1 is required, first implement and test the exact-identity injection,
900-second limit, 90-second lost-controller recovery and explicit restoration
readback. That work must precede a separately reviewed injectable scope.

## Fresh Draft Scope

Proposed ID: `keli-accept-20261118-r36-f192cd78`; **not approved or executable**.
The frozen review package intentionally retains the old unarmed R35 identifiers.
After scope review, any new identifier binding must be regressed and frozen again;
do not edit a frozen archive, reuse an old grant or execute the review package.

- One round, start within 24 hours of a new approval, maximum two hours; stop new
  dispatch by 6,000 seconds and reserve 1,200 seconds for cleanup. Shorter native
  leases and stage deadlines remain effective and are never extended.
- Linode Singapore `sg-sin-2`, Debian 12 x86-64, subject to fresh availability:
  one 2-core/4-GiB controller and at most four 4-core/8-GiB roles: source A,
  source B, candidate A1 and subsequent A2. Maximum five creates total, four
  simultaneously existing new machines; one create/first install per role.
- Single reviewed credential group/account/revision, no rotation or fallback.
  Inventory baseline N is pinned. Isolated capacity N+4, account concurrency 1,
  rolling account fuse 5, A/B task limits 3/1, service concurrency 1 and A attempt
  limit 2. Unknown creates/deletes keep their slot; no history deletion.
- Fixed domestic IPv4 targets: `39.156.70.37`, `111.33.167.71`,
  `123.150.76.218`, `157.255.219.143`. Any one reachable is healthy. Require
  three distinct fresh complete failure rounds; tool errors are not failures.
  A separate bounded 127.0.0.1 self-check never contributes to health counts.
- No ICMP injection on any role. Healthy source A or healthy A1 ends the retry
  experiment with cleanup; record that the retry branch was not exercised.
  Do not deliberately reject a healthy candidate or create another to force failure.
- If A1 naturally fails, native cleanup must confirm exact absence twice at least
  ten seconds apart; only after 300 actual seconds of cooldown may A2 be created.
  Source recovery still cancels replacement. No A3 or B replacement.
- Only three fresh A2 reports and three genuinely reachable domestic rounds
  permit the test edge-a A-record switch. Unreachable A2 ends with protection
  and cleanup, not another candidate. Report retry and cutover separately.
- DNS scope under `dnscloudcloud.top`, only initially absent names:
  `control-keli-accept-20261118-r36-f192cd78` default A and its corresponding
  `_acme-challenge` TXT; `edge-a-keli-accept-20261118-r36-f192cd78` and
  `edge-b-keli-accept-20261118-r36-f192cd78` default A. B does not switch.
  No AAAA, additional lines or existing records. HY2 is not a main-flow gate.
- Credentials are not requested in chat. A separately authorized, strict,
  minimal read-only bridge may supply only reviewed temporary material to the
  new isolated controller. No production DB/queue/configuration writes.

Instances may be billed until deletion is confirmed. There is no monetary
execution threshold, additional round allowance or authorization in this draft.

## Remaining Preflight Checklist

1. Obtain a new decision on the reduced, non-injecting scope or complete the
   controlled-fault binding first. Old scope approvals do not cover either action.
2. Regress the new run-ID/domain binding, freeze and verify its LF package,
   application/runtime manifests, locked vendor and pinned Linux node archive.
3. After separate read-only approval, verify credential revision/capability,
   complete stable cloud inventory, available type/image/region, capacity and
   absent dedicated DNS names. Unknown/changed state stops preparation. Read
   permission alone is not evidence of create/delete/DNS-write permission.
4. Prepare explicit operator host identity, root-owned private inputs, separate
   creation/import/cleanup approvals, strict pinned SSH host keys and key-only
   access. The cleanup credential must equal original creation/import material.
5. Supply a valid, hostname-matching TLS chain trusted by the actual node installer;
   arrange bounded test-name certificate issuance if needed. No trust bypass,
   self-signed production substitute or implicit certificate issuance is provided.
6. Verify disposable controller OS packages, firewall, non-public MySQL/Redis,
   actual service-user loading, migrations and HTTP/TLS before any source create.
   Do not start production Horizon, periodic dispatch or modify queue settings.
7. Confirm durable local evidence storage, original-account cleanup access after
   controller loss, stage/time limits and a cleanup escalation contact. Refuse
   live dispatch without all inputs, exact scopes and rollback/readback readiness.

## Exit and Cleanup Checklist

Stop new work and revoke/drain callbacks before teardown. Preserve the original
host and task first errors independently, then hash and read back sanitized
evidence. Reconcile uncertain requests by exact identity without repeating POST
or DELETE. Native task resources are removed before auxiliary controller cleanup;
two independent exact-ID absence reads are required, not an empty list or timeout.

Delete only owned test DNS records after verifying current identity and the
original publication journal; unknown ownership remains pending. Remove private
materials and read back their absence. Delete the controller last, retain an
independent operator cleanup path, and confirm its absence twice. Never use an
existing machine or another account to clear an unresolved test resource.

Success, failure, stop and expiry all follow cleanup. If cleanup remains
unconfirmed, report the pending resource and retained billing risk; do not claim
zero resources, continue creating, erase history or silently abandon recovery.
No formal live approval is requested or consumed by this document.
