# R35 Credential Capacity and Member Retry Acceptance Scope

Status: **historical scope approved; current instruction is local-only, not armed or executed**.
Approval recorded at 2026-11-16 13:38:08 Beijing (05:38:08 UTC).
Start deadline: 2026-11-17 13:38:08 Beijing, only after all prerequisite gates pass.
Current result: [multi-source offline rehearsal passed](machine-r35-driver-offline-20261116.md).
Live transport, freeze and preflight gates remain pending; do not execute under
the historical approval while the newer no-deployment/no-creation instruction applies.
Prepared on 2026-11-16 (Beijing).
Run: `keli-accept-20261116-r35-f4940b41`.
Structured proposal: [limits and gates](evidence/linode-r35-proposal-20261116.json).

This is a new scope, not permission to execute R34 or reuse an earlier grant.
Preparation so far is local documentation and mocked regression testing. No production SSH,
database, credentials, provider API, DNS, deployment or cloud creation is used.
No new package has been built or frozen for R35. The user historically approved
this scope; the newer instruction only authorizes local work and synchronizing
its commit. All remaining gates and renewed execution authority are required
before creation.

## Objective and Pinned Inputs

Prove one selected child member A can clean its unreachable first candidate A1,
release capacity only after confirmed deletion, wait a real cooldown, then create
one next candidate A2 through the native retry path. Unselected sibling B remains
unchanged during the active test. Report retry/protection and DNS success as
separate results; an unreachable A2 does not erase a proven cleanup/retry result.

| Input | Commit or requirement |
| --- | --- |
| keliboard | `f4940b41ce70e4564d2c9174718e1c913387f0b4` |
| keli-admin | `a710421e56ecfbddfd6accef2df5a184c1467f6b` |
| kelinode-rs source | `3c707fc89b16e2895727c24251b6076252d5cab0` |
| Workflow | `agent_readiness_v1`; no HY2 specialist gate or claimed result |
| New adapter and artifact | Pending explicit file inventory, regressions and LF freeze |

The [capacity/retry change](machine-credential-capacity-and-retry.md) removes the
fixed two-task ceiling, not task history or safety gates. The historical R34
offline blocker remains intact in the separate local acceptance records.
Its approved round was never executed and must not be reopened by this proposal.
Do not package the entire dirty worktree or include temporary CI workflows.

## Resource and Time Envelope

| New role | Maximum create dispatches | Linode type | Purpose |
| --- | --- | --- | --- |
| control | 1 | `g6-standard-2`, 2 cores / 4 GiB | Isolated application, private DB/cache and expiring CLI |
| source-a | 1 | `g6-standard-4`, 4 cores / 8 GiB | Selected member A source |
| source-b | 1 | `g6-standard-4`, 4 cores / 8 GiB | Unselected sibling B control |
| candidate-a1 | 1 | `g6-standard-4`, 4 cores / 8 GiB | Native failure cleanup |
| candidate-a2 | 1 | `g6-standard-4`, 4 cores / 8 GiB | One retry after confirmed cleanup and cooldown |

Use the previously designated single Linode account, `sg-sin-2`,
`linode/debian12`, x86-64, subject to read-only availability checks after approval.
At most five create dispatches total and four simultaneously existing new
machines, including unknown create outcomes until reconciled. Each role gets
one create dispatch and one first installation only. A2 requires A1's confirmed
absence. No account rotation, A3, B candidate, extra round, region/image fallback,
existing-machine reuse or reinstall-on-failure is authorized.

Start one round within 24 hours of approval. Maximum campaign time is 7,200
seconds, with no new stage after 6,000 seconds and 1,200 seconds reserved for
cleanup. Preserve the native 1,200-second deadline and shorter task/watchdog
limits; do not extend them to achieve success. If the remaining time cannot
accommodate the next stage, real cooldown and cleanup, stop dispatch.
Expiry permits restoration and cleanup only. Billing can continue until cloud
deletion is confirmed; this scope adds no money-based execution gate.

## Isolated Limits and Counting

These proposed settings apply only to new isolated database records and the
bounded test process. Do not edit production groups, account limits, policies,
history, queue configuration or persistent automatic switches.

| Layer | Proposed setting | Counting rule |
| --- | --- | --- |
| Account capacity | `max_instances=N+4` | N is the complete real-account inventory before any R35 create; plus at most four live R35 roles |
| Account creation lane | `max_parallel=1` | Includes initialization and unresolved outcomes; Linode remains serialized |
| Account rolling fuse | `daily_limit=5` and isolated global account fuse 5 | All real isolated task rows in the preceding 24 hours, including source/failed/cleaned tasks |
| Member A policy fuse | `daily_limit=3` | Fresh source A, A1 and A2 all count |
| Member B policy fuse | `daily_limit=1` | Source B only; no B replacement is authorized |
| Service | `max_parallel=1`, `attempt_limit=2` | A1 and A2 consume the service's two native attempts |
| Child member attempts | A: 2; B: 1 configured but unselected | B has zero authorized attempts; A may not spend B's allowance |
| Probe/retry | `failure_threshold=3`, `cooldown_seconds=300` | Real consecutive complete failures; cooldown starts at confirmed deletion |

N is pinned once after approval, not reset after drift. Require complete stable
pagination, unique resource IDs and `N+4 <= 1000` within the current inventory
reader's supported range. Existing instances count toward provider capacity but
are never test-owned, imported as disposable sources or modified. Recheck actual
inventory under the provider quota lock before each POST. Unexpected non-R35
inventory drift, unresolved ownership, unavailable capacity or unknown reads
stop dispatch; never raise the cap or delete an existing resource to make room.

The isolated database contains R35 task history, not a clone of business data.
Its rolling fuse is not a claim to limit unrelated actors on the real account.
The outer durable role ledger separately counts **every** create dispatch,
including the controller if it has no application provision task. Do not invent
a task receipt merely to count it, or omit it from the outer five-create limit.
There are four source/candidate task creates in the intended native setup.
Demonstrate the exact setup accounting offline before accepting this plan.

Count remote inventory, local distinct resource IDs and pending reservations
without double counting. Aliases must not create extra capacity or bypass a
stricter limit. An ambiguous create/delete retains its slot. Confirmed A1 cleanup
releases occupancy, not daily history or attempt count. Do not backdate tasks,
clear history, change clocks or replace a failed intent with a fresh allowance.

Verify full-capacity/fuse rejection and concurrency races in mocks without an
extra real create. During the real round record actual inventory and reservations
before A1, while cleanup is pending, after confirmed cleanup, and before A2.
Do not claim a live race test if only serialized real execution was observed.

## Prerequisites After Approval

1. Adapt the current single-source harness to explicit source A/B, candidate
   ordinals, task/resource/install IDs and execution-member bindings. B needs
   genuine installation and reports, not copied A identity or synthetic health.
2. Run the complete new entry offline with cloud/SSH/DNS transports mocked:
   same-day source accounting under the exact limits above; capacity full or
   unknown; quota-lock contention; ambiguous create; duplicate/stale sampling;
   identity drift; A1 cleanup with present/404/429/error responses; tampered
   cleanup seal; real-clock-independent simulated cooldown; A2-only retry;
   sibling isolation; recovery cancellation; stop/expiry; all-exit cleanup.
   Include old positive limits still rejecting correctly. Offline fake time or
   receipts must never be enabled in the live adapter.
3. Freeze new LF packages from the pinned sources and a reviewed adapter/helper
   allowlist. Record hashes, node binary provenance, runtime/application file
   manifests, service-user loading and required imports. Validate compressed
   initialization size, default Web-service restrictions, diagnostics and fault
   restoration. No GitHub build/publish, release tags or production artifact swap.
4. Only after offline gates: use the established strict SSH read-only bridge
   (`BatchMode`, `IdentitiesOnly`, `StrictHostKeyChecking`, `UpdateHostKeys=no`)
   for minimum safety, selected credential revision, capability and ownership
   checks. No host-validation relaxation or production database writes. Confirm
   provider availability, N, selected credentials and absent DNS names. Read-only
   success alone does not prove mutation permission; no trial writes are allowed.
5. Create the controller first. Verify TLS callbacks, public-key SSH,
   default-deny firewall, non-public MySQL/Redis, frozen runtime/application hashes
   and PHP loading as the actual service user before creating source A/B. Do not
   start a periodic scheduler, Horizon or change production queue workers.

Any prerequisite failure stops creation. The new multi-source adapter, full R35
entry regression and artifact freeze are **pending**, not established by the
earlier capacity unit-test results. A material scope increase needs new approval.

## Real Probe and Retry Sequence

Create control, source A and source B serially. Require each source's genuine
identity-bound installation and at least three fresh reports. B remains an
unselected sibling; record its actual health without requiring a fabricated
healthy label. Controller domestic Ping success is not an entry gate.
The real account cooldown also applies after setup: wait until the newest source
task's creation/dispatch cooldown has elapsed before starting A1. This wait is
inside the existing campaign deadline, precedes any source fault, and grants no
timestamp rewrite or time-limit extension.

Keep the four domestic IPv4 targets fixed:
`39.156.70.37`, `111.33.167.71`, `123.150.76.218`, `157.255.219.143`.
Use the established five-second sampling, one packet per target and two-second
timeout. Any one reachable is healthy. Count three distinct, fresh, complete
failure rounds tied to the actual IPv4, install ID, runtime/session and probe
revision. Stale/duplicate/incomplete samples and tool/permission errors do not
count. One 127.0.0.1 packet per source/candidate, at most six seconds, is diagnostic
only; never add it to domestic health or failure counts.

Only if the new source A is healthy, allow one owned outbound ICMP Echo block to
the fixed four targets. A naturally failing source needs no fault injection.
Keep source-recovery cancellation effective throughout the retry sequence.

Create A1 through the original native intent. It must install and report before
failure cleanup. Natural complete failures need no injection. If A1 is healthy,
the proposed scope allows one such ICMP block **before admission**, with native
advancement gated until real failure evidence is available. Never fabricate
reports or mutate the task to claim it is unreachable. If A1 has already been
admitted or published, stop; do not use retry cleanup against an admitted machine.

Each allowed fault is limited to its exact new source-A/A1 identity, once only,
at most 900 seconds, with local recovery after 90 seconds of controller lease
loss. Preserve SSH, callbacks, loopback and other traffic. No faults on control,
B, A2 or existing hosts; no extension, second injection or service restart.

For A1, the **native task**, not the teardown script, must perform the deletion.
Require exact account/resource/ownership and unchanged source-DNS checks, then
one persisted delete intent. Two independent exact-ID not-found reads at least
10 seconds apart must seal confirmed cleanup before releasing capacity. A DELETE
success, empty list, timeout, 429 or unreadable response is not absence.
Stop retries while cleanup is unresolved; do not duplicate an uncertain DELETE.

Observe at least 300 actual seconds from `confirmed_at`, including repeated
advancement with zero new POSTs during cooldown. Only then may the same execution
member create A2 under the same account/revision, with a new native ordinal,
request/task/install identity and the A1 cleanup proof linked. Preserve source
rows, A1 first errors and all attempt counters. If the source recovers or the
execution expires, honor cancellation; do not prolong the fault to force A2.

Observe A2's genuine installation and continuous reports. Only three fresh
reports plus three distinct healthy domestic IPv4 rounds allow DNS cutover.
If A2 remains unreachable, do not publish it or create A3; record protection and
retry results, then clean up. B's resource, installation, policy and DNS remain
unchanged with fresh reporting during the active test. Final teardown deletes
the new B source, which is not a B failover.

## DNS and Credential Boundary

Only the following initially absent names under `dnscloudcloud.top` are proposed:

- `control-keli-accept-20261116-r35-f4940b41`: default A.
- `_acme-challenge.control-keli-accept-20261116-r35-f4940b41`: certificate TXT.
- `edge-a-keli-accept-20261116-r35-f4940b41`: default A, source A to eligible A2 only.
- `edge-b-keli-accept-20261116-r35-f4940b41`: default A, source B unchanged until teardown.

No A1 publication, AAAA, wildcard, CNAME, other line, unrelated TXT or existing
record adoption. Pin record IDs, ownership marker, old values and account
revision; initialize only after verifying absence. Keep TTL 600, provider
write/readback and selected Cloudflare/Google external resolution checks bounded
to 720 seconds and the remaining campaign/native deadline, whichever is shorter.
This is selected-resolver evidence, not a guarantee of global propagation.

Selected Linode/Aliyun credential temporary copies may enter only the new
controller after approval. Do not send them to source/candidate machines or
include them in logs, snapshots, Git or exported evidence. Remove copies at exit;
never revoke original credentials. Process-local expiring CLI authority only:
all persistent automatic creation, power, runner, rotation and DNS switches stay
off, including isolated defaults. No production frontend/backend deployment.

## Cleanup on Every Exit

Success, failure, user stop, watchdog or expiry follows the same plan:

1. Stop new work and restore owned faults. Keep restoration/cleanup authority
   after creation authority expires. If a faulted host has already been deleted,
   record verified absence instead of claiming an SSH restoration ran.
2. Preserve sanitized role/task/attempt evidence, first errors and safe exception
   file/line data, and verify export readback. Never export credentials, complete
   bootstrap scripts, private keys or business data. Evidence-export failure
   must not prevent cleanup or justify a success verdict.
3. Reconcile uncertain outcomes by original request/resource identities only.
   Delete only this round's receipted instances, owned auxiliary volumes and
   exact owned DNS records. Changed ownership/value or unreadable results are
   unresolved cleanup, not permission to delete by label/prefix or assume zero.
4. Clean source A/B and candidates before deleting the controller. Clear private
   temporary copies, keys/certificates, local transient materials and isolated
   containers/volumes; retain only sanitized audit evidence. Delete control last
   after dependent cleanup is confirmed, while respecting its lifetime limit.
5. Perform two independent complete cloud/volume and DNS absence checks at least
   10 seconds apart. On partial cleanup, stop new work and report exact remaining
   test resources and billing risk. Do not extend machine lifetime silently,
   create a cleanup helper or modify/revoke original credentials.

## Independent Results

Record each as passed, failed, protected-stop or not-reached with real receipts:

1. Credential occupancy and single creation lane, including ambiguous-outcome holds.
2. A1 failure threshold and native exact-identity deletion with two absence reads.
3. Real 300-second cooldown with no early create and retained attempt history.
4. Same-member A2 retry, two-attempt ceiling and unchanged sibling B.
5. A2 readiness and edge-A DNS cutover, only if real health passes.
6. Fault restoration, sanitized evidence and all-resource cleanup confirmation.

Do not collapse these into "everything passed" if cutover is not reached, and do
not discard successful protection evidence just because an IP is unreachable.
This round does not certify simultaneous failing members, live multi-worker race
handling, account rotation, other providers, multi-line/dual-stack DNS, HY2 or
production automatic operation. Production business/database/queues/default
versions, all automatic switches and tanzhen remain untouched. No old round is
replayed and no GitHub build or image publication is authorized.
