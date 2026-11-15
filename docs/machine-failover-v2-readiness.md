# Native V2 Failover and Independent Protocol Diagnostics

## Scope

This is a local code and simulated-transport change. It is not a production
rollout or a new live-acceptance authorization. No VM, DNS record, production
queue, default release, or tanzhen configuration is changed by this work.
R29 is not replayed. Historical acceptance artifacts are not rewritten.

The local reference is tanzhen's `kelicloud/utils/failoverv2/runner_failover.go`:
provision a member, wait for its Agent, validate outlet connectivity, run its
configured initialization scripts, attach DNS and verify provider readback.
Its fixed orchestration does not require our HY2 echo-worker challenge.
kelinode-rs supplies both Agent reports and node configuration reports here.

## Two Contracts

| Contract | Main acceptance | Protocol worker | DNS authority |
| --- | --- | --- | --- |
| `agent_readiness_v1` | Initialization, exact identity, current Agent/node configuration and domestic IPv4 | Independent, separately authorized diagnostic | Signed readiness plus original DNS ownership/intent checks |
| `protocol_verification_v1` | Existing full forwarding acceptance | Required, unchanged | Existing signed forwarding receipt plus DNS checks |

New native runs pin the configured workflow into their immutable intent.
Missing-workflow historical runs and non-coordinated manual tasks retain the
protocol contract. Changing the default cannot downgrade or reinterpret an
existing run. Replaying a request uses the original pinned contract.
Automatic admission must explicitly pin the readiness workflow in its separate
authorization; a legacy authorization does not silently acquire new powers.

## Main Flow

1. Require distinct, fresh, same-installation source failures at the existing
   consecutive-failure threshold. A single reachable configured domestic IPv4
   target is healthy. Unknown/error evidence is not a failed round.
2. Reserve the existing bounded attempt and persist the original create intent.
   Check readiness authorization before reservation and before the create
   boundary. A lost/ambiguous response permits only original-intent readback.
3. Advance through initialization, Agent/node reports and candidate IPv4.
   Waiting states remain nonterminal and bounded by the original task/run
   deadline. Identity/configuration changes still stop advancement.
4. Bind a readiness proof to the exact run/attempt/task, installation, provider
   identity and creation time, release hash, runtime session, configuration,
   policy revision and IP. Its validity is at most 20 minutes and never exceeds
   the original execution deadline. Rechecks cannot renew or replace it.
5. Prepare DNS using the existing record/credential/marker/revision checks.
   Multi-record A/AAAA proofs bind each provider-owned address to reports from
   the same candidate. They use a separate `ready` state and origin, with no
   fabricated worker receipt or protocol-pass flag.
6. Dispatch the original DNS mutation once and require provider readback before
   marking the candidate accepted/active and the member completed. Uncertainty
   remains reconciliation/review, not another create or write intent.

The readiness proof confirms Agent/configuration/IPv4 evidence. It does not
prove an open service port or end-to-end application traffic. DNS provider
readback does not claim public-resolver or TTL propagation verification.

## Independent HY2 / TCP / UDP Diagnostic

The advanced acceptance workspace retains the existing real worker challenge,
signature, exact installation/configuration context, TCP/UDP echoes, deadline,
cleanup checks and separate `allow_verification` authorization.

For a readiness run, the result is stored as a diagnostic check only. Either
success or failure leaves the instance revision, readiness seal, active DNS
intent and forwarding-authorization fields unchanged. A successful diagnostic
does not manufacture a legacy forwarding receipt or enable manual DNS actions.
The UI distinguishes historical protocol diagnostics from current acceptance.
Diagnostics on an already published candidate validate its committed DNS
ownership and original sealed identity without reopening the execution lease.
That exception is private to the diagnostic path; new readiness/DNS admission
still rejects completed executions.

## Stop, Recovery and Compatibility

- Existing lease/generation fencing, duplicate-job handling, parallel/attempt
  limits, cooldowns, source-recovery checks and stop controls remain in force.
- Stopping before dispatch cancels only local work. A dispatched/uncertain
  create or DNS operation remains retained or under review, never blindly
  retried, reversed, or deleted by this change.
- A crash after a verified single-record DNS commit can acknowledge that
  already committed intent using its original sealed historical receipt. It
  does not need to re-admit a now-active candidate or issue another write.
- A prepared but expired or changed proof cannot authorize a new DNS mutation.
- No migrations, new packages, new queues or Horizon changes are needed.
- The production hard execution gate still rejects execution independently of
  settings. Readiness, provisioning, admission, runner, rotation and DNS
  execution permissions remain disabled by default.

## Verification

The [local evidence file](evidence/machine-failover-v2-readiness-local-20261115.json) records PHP/SQLite simulated-provider
regression results, frontend tests and the existing build-size check. These
results are not evidence of real cloud, HY2 or DNS acceptance. A future live
round requires a new approved scope and a newly frozen matching harness; old
R29 packages and results remain historical.
