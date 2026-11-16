# R35 Multi-source Driver Offline Rehearsal

This follows the user's newer **local-only** instruction. The historical R35
approval is retained, but is not current permission to launch. No production
SSH/database, credential bridge, cloud/DNS API, deployment, VM creation, R34
execution, old-round replay, GitHub operation or tanzhen access occurred.

## Implemented Entry

`tools/machine-acceptance/member_retry_driver.php` is a step-driven PHP
coordinator. Its tested entry is `MemberRetryDriver::step()`, not a test that
constructs the expected final result. It is explicitly restricted to the test
application with in-memory SQLite; there is no live transport or authority loader.
It cannot be used as an R35 cloud launcher by passing a different callback.

Preparation, observation and final teardown use an injected port. The native
execution, attempt/task bindings, source pins, candidate provisioning, cleanup
client, signed cleanup proof, capacity checks, retry and Aliyun DNS record-set
services are the real application implementations. HTTP and Bus are faked and
stray HTTP requests rejected; PHP socket/cURL execution is disabled.

The coordinator:

- Separates control, source A/B and candidate A1/A2 in its role ledger. A task
  UUID, installation identity, account/revision and external ID cannot be
  rebound, shared across roles or taken from the pinned pre-existing inventory.
- Requires the canonical `keli-<task UUID without hyphens>` label and exactly
  `[task.marker]` tags. It never rewrites the native request for role tracking.
- Verifies N+4 capacity, account concurrency one, rolling fuse five, policy A/B
  limits 3/1, service parallelism one, total attempts two and member caps 2/1.
  The controller is represented even though it has no provision task.
- Sets up A and B separately, waits for the source-setup cooldown, and starts
  only A's plan. B's task, policy, native source pin and stable remote resource/
  DNS identity are compared before and after native operations.
- Lets the native task delete A1 after three complete failures. A terminal task
  status, DELETE acknowledgement or final-teardown result cannot replace the
  native two-read, exact-identity, sealed cleanup proof.
- Requires the proof's 300-second cooldown before the same member/account can
  create ordinal two. Cleaned A1 remains historical evidence; it is not checked
  as the current active task after A2 takes over. No history is removed.
- Blocks A1 DNS publication and account rotation. The existing application gate
  must authorize every native operation first; the wrapper adds restrictions,
  restores the original gate on exit and does not open global switches.
- Saves an operation intent before dispatch. A pending operation restored after
  interruption is not automatically resent. Recovery proceeds to scoped cleanup
  and requires original-intent reconciliation, never an extra create.
- Keeps the first error separate from teardown errors. Stop, expiry, B mutation,
  invalid proof or identity all prevent further work. Clock rollback still calls
  restoration/cleanup but cannot qualify a timed absence confirmation.

## Port Contract

`prepare_role` is called once per control/source role with a stable request ID.
Source responses identify already installed, independently registered tasks;
native source pin validation is repeated, without inventing health or forwarding
proofs. In these tests, source installation/registration is a synthetic fixture.

`observe_sources` and `observe_candidates` are role-scoped. B is observation-only;
candidate observations never include B. The future transport must still enforce
one bounded authorized fault on a healthy source A/A1, restore both fault handles,
and collect the required genuine report windows. This rehearsal sends no ICMP.

`control_facts` returns stable resource identity and DNS identity/value, not
changing traffic counters or last-seen timestamps. Its digest is compared without
exporting source contents or credentials.

`cleanup` must restore faults, reconcile all pending intents, remove only owned
resources/private material and remove control last. Its response lists the exact
inspected resource IDs as well as the independent reconciliation/restoration
results. Two complete successful calls separated by at least ten seconds close
the journal. Unknown/incomplete reads reset confirmation. **The port's final
teardown is mocked here; A1's retry cleanup is not replaced by that mock.**

The caller must use the existing durable journal/strict transport infrastructure
when a future live adapter is separately prepared. This local coordinator is not
a filesystem store, SSH client, timer daemon or production API endpoint.

## Verification

The composed tests are in `TestsMemberRetryDriver.php`, included by the existing
`MachineFailoverNativeBridgeTest`, so no temporary CI workflow/filter is needed.
The final focused run passed **16 tests / 6,607 assertions**. The combined native
bridge and cleanup-client run passed **484 tests / 13,827 assertions**, including
15 of those driver cases. The clock-rollback case was added after that suite was
collected and passed in the final focused rerun; these overlapping counts are
not additive. Both runs have zero failures, errors or skips.
Run with PHP 8.2+, the repository test configuration and network execution disabled:

```sh
php -d memory_limit=768M -d disable_functions=curl_exec,fsockopen,pfsockopen,stream_socket_client \
  vendor/phpunit/phpunit/phpunit tests/Unit/Services/MachineFailoverNativeBridgeTest.php \
  --filter test_member_driver
```

The positive path uses the actual new entry for A1's native delete, both absence
reads, no early POST at 299 seconds, resumed cooldown, A2 creation and Aliyun
edge-A cutover. B and an unrelated inventory item remain unchanged. Four task
rows/two attempts are retained, with two candidate POSTs, one native A1 DELETE,
one edge-A write and zero HY2 verifications.

Negative cases cover unknown cleanup reads, altered canonical identity, changed
B DNS, stop during cooldown, failed task without proof, corrupt seal, healthy A1
attempting to skip the retry test, source recovery, changed limits, expiry,
journal failure, unresolved-operation resume, incomplete teardown receipts,
closed native authorization and refusal outside the offline environment.

The clock and setup/report data are synthetic. This does not verify a real
300-second wait, OS installation, three real delivery windows, SSH permissions,
ICMP firewall recovery, real resource deletion or public DNS/TTL propagation.
See the structured result in `evidence/linode-r35-driver-offline-20261116.json`.

## Remaining Live Gates

No application safety check was weakened. The old closed-round files and
previous evidence remain historical, unchanged by this implementation.

Still not performed: fresh multi-source SSH/runtime projection, actual two-source
registration and fault/teardown transport integration, runtime permission smoke,
fresh LF artifact freeze, minimum read-only live preflight and a real R35 run.
The old single-source launcher is not reused or armed. A mock success is not a
frozen live artifact, and the previous approval does not override local-only work.

No commit/push or deployment was requested or performed. Persistent automatic
creation, rotation, DNS switching, queues and production settings remain untouched.
