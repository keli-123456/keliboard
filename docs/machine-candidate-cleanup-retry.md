# Unreachable Candidate Cleanup and Retry

## Scope

This change is local implementation and mocked verification only. It does not
authorize a live round, reopen R33, deploy code, modify production, operate
tanzhen, or enable any automatic switch. Previous single-candidate acceptance
records remain historical evidence, not permission for additional candidates.

`MachineFailoverExecutionGate` remains closed. `allow_candidate_retry` defaults
to false and its policy allowlist defaults to empty. An authorized test must
explicitly inject the execution gate and enable the policy before starting a
new run. Retry permission is pinned to that run; old runs do not acquire it.
Candidate replacement does not enable credential rotation: the next attempt
uses the same account and revision, with a new request, installation and marker.

## Progression

1. The normal V2 source identity and continuous-failure checks authorize the
   first attempt. Bootstrap and fresh identity-bound Agent/node reports must
   succeed before candidate failure can authorize cleanup.
2. Count distinct, fresh, complete domestic IPv4 probe failures. Preserve the
   configured failure threshold and sample interval. One reachable target,
   errors, incomplete evidence, stale reports, future timestamps, or changed
   configuration/runtime/session do not authorize deletion. Loopback never
   contributes to domestic connectivity. This first cleanup path requires
   exactly one public IPv4, bound to every target's reported source address.
3. Before deletion, recheck task/lease/config, ownership marker, exact provider
   ID, creation time, addresses and unchanged DNS source ownership. Never clean
   an enrolled, imported, active or DNS-published candidate through this path.
   Provider identity locks cover aliases across accounts as well as imports
   and bindings.
4. Save deletion intent before sending one DELETE. A crash or ambiguous
   response permits only reconciliation of that exact resource, not another
   DELETE or create. A known pre-dispatch abort does not become a dispatch
   claim; a lock contention or recovered candidate can continue observation.
5. Require two exact-resource GET absence receipts at least ten seconds apart.
   Only an explicit resource-missing 404 counts. Timeouts, malformed responses,
   empty inventory, authentication errors and 2xx delete acknowledgements do
   not prove absence. Presence/errors reset the confirmation streak, and
   rate-limit backoff is honored.
6. Seal the identity-bound cleanup journal and mark the attempt `cleaned`.
   Wait the policy cooldown. Then check source failure, current permission,
   credential health, DNS ownership, total attempts, per-account reservation,
   service concurrency and daily creation limits again before reserving a
   new attempt. Cleaned attempts still count toward the attempt limit.
7. A healthy next candidate follows the existing fresh-report/domestic IPv4
   readiness and owned DNS cutover path. HY2 specialist verification remains
   separate from V2 readiness; its requirement is not silently weakened.

## Uncertain and Stopped Work

Pinned candidate observation shares the priority tier with DNS reconciliation,
round-robins the at-most-two active members, and schedules ten-second checks
while collecting failure evidence. Waiting members cannot starve those samples.
This does not change queue connections, Horizon configuration, worker limits,
or the failure threshold; other scheduling retains its existing cadence.

- Pending cleanup retains its capacity reservation. No new candidate is created
  while the previous candidate's absence is unconfirmed.
- Stop, deadline, permission revocation, identity drift, conflicting ownership
  or invalid cleanup proof prevents another create. Pending cleanup is recorded
  for review; it is not described as a retained or successfully deleted VM.
- Source recovery after confirmed cleanup cancels further replacement. Recovery
  while cleanup is pending permits only the original cleanup readbacks, not a
  new create. Unresolved cleanup at stopping/deadline remains for review.
- Never delete a source, another attempt's resource, or a candidate already
  admitted to DNS/instance ownership through this retry path.
- The history UI distinguishes cleanup confirmation, retry cooldown and cleaned
  attempts. It does not add a retry/start button or expose credentials.

## Verification

`TestsCandidateRetry` is included by `MachineFailoverNativeBridgeTest`. It uses
the actual bridge/provision/readiness/DNS services with in-memory SQLite/cache,
fake queues and mocked cloud/DNS HTTP. The positive flow is first candidate
unreachable, confirmed cleanup, cooldown, second candidate healthy, owned DNS
publication and idempotent completion. Negative cases exercise duplicate or
invalid evidence, recovery, stop/deadline/revocation, cleanup ambiguity, rate
limits, changed ownership and exhausted limits.

`MachineCandidateCleanupClientTest` covers Linode, DigitalOcean and Vultr
transport/identity contracts. `MachineCloudTest` covers shared identity locking
against inventory import and binding. These simulations are not a claim that
real provider deletion, multi-worker Redis leases, or live cutover was retested.

Local verification on 2026-11-16:

| Check | Result |
| --- | --- |
| Related backend regression (11 test classes) | 779 tests, 8,953 assertions passed |
| Final candidate-retry and runner regression, after scheduling adjustment | 90 tests, 734 assertions passed |
| History component and API contract tests | 78 tests passed |
| Admin TypeScript and production bundle verification | Passed; JS 4,677,772 / CSS 570,854 bytes; existing limits unchanged |
| Changed PHP syntax and tracked diff whitespace | Passed |

The focused suite overlaps the broader suite; these counts are not additive.
PHP tests used SQLite/cache in memory, fake jobs, blocked real socket/cURL
functions and mocked cloud/DNS transports. The broad run used a process-local
768 MiB PHP memory limit after the local default of 128 MiB was insufficient;
no application memory, queue or worker configuration was changed. No SSH,
production database, cloud instance, live DNS, deployment or GitHub action was
used. These results were recorded before the synchronized source commits;
committing them does not authorize deployment or a live acceptance round.
