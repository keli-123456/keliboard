# R35 Offline Prerequisite Review

Historical review snapshot. The later [R35 driver rehearsal](machine-r35-driver-offline-20261116.md)
records the subsequent local-only implementation and tests; this earlier record
is not current execution authority.

Status: **application-core mocks passed; live adapter gate not passed**.
The R35 scope was approved at 2026-11-16 13:38:08 Beijing. No real round has
started and no create allowance was consumed. This is not a real-machine result.

See the [scope](machine-r35-member-retry-isolated-acceptance-scope.md) and
[structured record](evidence/linode-r35-offline-preflight-20261116.json).

## Verified Core Sequence

`TestsMemberRetryAcceptance.php` adds an exact positive-limit synthetic scenario
to the existing native-bridge suite. Both sources were created in the same
24-hour window on one selected account. Account/global rolling limits are five,
A/B policy limits are three/one, the service and A have two attempts, and B has
one configured allowance but is not selected. Capacity is five for a synthetic
N=1 pre-existing resource plus a peak of four test resources, with concurrency
one. The controller and unrelated resource are inventory-only; all four
source/candidate tasks remain in the task ledger.

The real application services run against in-memory SQLite/cache and mocked
HTTP/Bus transports. Socket/cURL execution is disabled for PHP. These scenarios
exercise the native cleanup client, not a stub that always says "deleted":

- Three complete failures for the four fixed domestic targets are required.
- A1 is deleted by the native path; two exact missing-resource reads release
  occupancy, while all source/A1 task rows and counts remain.
- Advancement before 300 seconds does not create A2. After simulated cooldown,
  a second native ordinal creates A2 using the same account/member.
- A healthy synthetic A2 completes readiness and DNS. B's task, policy and DNS,
  plus the unrelated inventory item, are unchanged. No HY2 verifier runs.
- Both legacy DNS and the actual Aliyun service-record-set path are covered;
  Aliyun uses two separate default-A member records, not a dummy shared record.
- A recent setup only 120 seconds old is rejected by the existing account
  cooldown before any candidate POST. Real setup must wait; the fuse change does
  not remove this cooldown.

The PHP fixture deliberately advances a test clock and ages synthetic values
within the same-day history window, following existing unit-test conventions.
It does not prove a real 300-second wait, three real report deliveries, provider
permissions, a kernel fault or live deletion. No live timestamp may be rewritten.

## Adapter Gate

The latest closed R33 transport/runtime is a read-only reference, not an R35
launcher. Its `live_support.php::KeliLiveLinode::buildRequest` replaces the normal
provider label with a round/role label and appends another ownership tag. The
current native `MachineCandidateCleanupClient::context` intentionally requires
the canonical `keli-<task UUID without hyphens>` label and exactly the task marker
tag. Separate tests reproduce both legacy changes and confirm
`ownership_mismatch` **before any provider read/delete**. This is a safety check
working correctly, not permission to relax application ownership validation.

Other R33 assumptions are still incompatible with this scope: one machine ID,
at most two tasks, one source registration, source/candidate roles only, a
process-local daily limit of two, and no scoped candidate-cleanup operation.
Its task classifier and teardown also depend on the old role-labelled request.
Changing a round number or only the numeric cap cannot satisfy R35.

The required fresh adapter must retain canonical provider request identity and
bind separate role metadata to exact task/install/resource/member/ordinal
receipts. That binding must be used consistently for dispatch, two independent
source registrations, A1 fault, native deletion, A2 retry, DNS and teardown.
The controller role ledger must still count its create even though it is not a
provision task. B must never gain a selected intent or replacement authority.

No old driver, frozen package, source evidence, ownership check or historical
result was modified. No R35 adapter/package has been claimed complete, frozen or
launched. The full new-entry simulation remains required before any read-only
live preflight or creation. Until then the approved scope stays unarmed.

## Verification and Remaining Work

- Focused scope tests: 5 tests / 116 assertions passed, including two expected
  legacy-identity rejections. They do not mean the old adapter is accepted.
- Combined native-bridge/cleanup-client regression: 469 tests / 7,232 assertions
  passed, zero failures/errors/skips, elapsed 302.905 seconds. This uses the
  current working tree, including preserved earlier tests, not a frozen R35
  artifact. The five focused cases are included in that total, not additive.
- New multi-role live entry: pending, not covered by the core mock count.
- Fresh LF bundle, node provenance/hash freeze and live read-only preflight:
  not performed.
- Production SSH/DB connections, cloud calls, create dispatches, DNS writes and
  credential copies: zero. Cleanup is not applicable to newly created resources;
  no cloud absence readback is claimed.
- No commit/push, deployment, GitHub build/publish, R34 execution, old-round replay,
  automatic-switch change or tanzhen access.

The approval does not waive these prerequisites. Further preparation can continue
within the recorded scope/start window; no unpassed gate may be treated as a
live result, and no extra round or resource is authorized by this review.

Review finalized at 2026-11-16 13:56:03 Beijing. New files passed LF/ASCII,
whitespace, PHP syntax and JSON consistency checks. Recorded test/application
hashes and the three closed R33 reference hashes were verified unchanged.
