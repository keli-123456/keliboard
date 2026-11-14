# R18 Evidence Revalidation and Cleanup Classification

Date: 2026-11-14, Asia/Shanghai. Base commit: `c27c5a81522e4efc7df89c1c57b96c67e1fd4743`.

## Scope

Local implementation and offline tests only. No production SSH, database or provider requests;
no deployment, new machine, DNS operation, node restart, R18 replay, GitHub run or tanzhen access.
Persistent creation, rotation, DNS and runner switches were not modified. This record describes
the offline validation stage. Subsequent user-authorized source synchronization uses `[skip ci]`
only; it grants no deployment or live-execution authority.

## Finding and Evidence Limit

R18 preserved a failed candidate task with reason `replacement_evidence_changed`, without a
dispatch timestamp, provider ID or provider creation time. Its public export did not retain the
individual failed guard predicates. The exact historical predicate cannot be uniquely recovered.

A deterministic local regression reproduced the same rejection: a complete failed report arrives
during provider preflight, while the persisted observation still references the previous report.
The observer requires an exact report timestamp match. Consequently the current-evidence and
threshold flags become false, and the previously valid intent was permanently failed before POST.
This demonstrates a code defect consistent with R18, not proof that it was R18's only cause.

## Changes

- The observer distinguishes a pending collection from a changed fault. Pending requires the same
  source installation and context, an already collected failed threshold, a new complete failed
  sample, and the existing continuity bound. Its current-evidence and threshold flags remain false.
- Creation waits for collection and a matching original decision/episode; pending is never a PASS.
  The original execution, attempt and task are reused. No automatic rotation or replacement intent
  is created merely because a report is pending. Task deadlines precede the pending guard; native
  execution deadlines and stop/authority gates remain enforced.
- Context, decision, native-source and dispatch-reservation rejections retain fixed boolean checks
  and failed field names. No raw context, IP, credential, response body, exception text or stack is
  included. Diagnostic-save failure cannot replace the original terminal rejection.
- The task cleanup snapshot reads the locked task and its bounded step history in one transaction.
  A no-dispatch proof requires terminal state and step, queued step, no dispatch step, no provider
  identity or bootstrap activity, complete history and the original native task/attempt binding.
- Cleanup distinguishes `not_dispatched`, `dispatched` and `needs_review`. Only a matching terminal
  proof plus a complete conflict-free cloud inventory can omit an uncreated candidate from cloud
  reconciliation. Missing fields, contradictory snapshots, retained receipts, lost POST responses
  and incomplete inventories retain the original reconciliation and ownership guards.
- This omission applies only to cloud receipt collection. The task remains in audits and private
  cleanup/revocation inventories. It does not waive credential, fixture, client or controller cleanup.
- The fresh-round launcher pins and installs the classifier, verifies snapshot calls in both CLI
  and teardown collection, and rejects legacy collectors before importing the run driver. Closed
  scopes remain forbidden. The classifier does not grant a provider write or delete permission.
- Native CLI results and controller cleanup exports preserve a safe, immutable first error with
  independent local readback. Later failures cannot overwrite it; failed storage is not reported
  as a verified export. Cleanup can recover the diagnostic even if the original CLI response is lost.

## Validation

- Initial regression on the old implementation: expected pending task, actually terminal failed.
- Final PHP Unit suite: 4,037 tests, 32,082 assertions, zero failures/errors, 3 skips.
- Final canonical Python acceptance suite: 428 tests, zero failures/errors, 33 environment skips.
- PHP syntax checks: 10 changed/new PHP files, zero failures. Git whitespace check passed.
- The new cleanup and replacement diagnostic modules are included in both existing CI test lists.
  The PHP diagnostic producer probe loads the standalone exception class without requiring vendor
  installation in the Python-only tools job. No CI trigger or publication rule was changed.
- SQL tests use isolated in-memory fixtures; provider, SSH and DNS behavior is mocked. Linux-only
  namespace, real iputils and ownership tests were not executed on this Windows host. This is not
  a new Linux concurrency or cloud/DNS acceptance result.

Covered cases include preflight report races, pending before reservation, original-intent reuse,
source recovery/identity changes, stale/incomplete reports, deadlines, field redaction, diagnostic
storage failure, missing or conflicting cleanup evidence, inventory conflicts, retained receipts,
post-dispatch uncertainty, first-error retention and cleanup-time diagnostic export.

The final JUnit report is local at
`C:/Users/Administrator/Documents/keli/.codex-audit/r18-offline-fix-unit-final-20261114.xml`;
SHA256: `7e37dc59cdda9d044a443d1169c22827a92459934c4a7e08c85ba65ff69b2ac1`.

## R18 Remains Closed

All 120 frozen R18 inputs, plus the original closed, result and cleanup records, were checked
unchanged. The pre-existing acceptance-plan edit was also preserved unchanged.

R18 remains **not passed**, with its original `cleanup_incomplete_needs_review` verdict. Previous
two-pass VM/DNS absence evidence is historical; this work did not repeat external checks and cannot
retroactively supply missing private-file cleanup or fixture-revocation receipts. It does not
authorize a new live run. Any future live acceptance requires a new scope and explicit approval.
