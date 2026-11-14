# Source Fixture Evidence Diagnostics

## R15 Finding

R15 was closed with `source_fixture_evidence_invalid` at `adopt-source`. Its
frozen evidence confirms the captured runtime binary/version, five complete
failed samples, native failure counts 1 through 5, and source identity receipts.
It does not preserve the rejected invocation's individual checks or full input
snapshot. Consequently, the unique historical failed field cannot be recovered.
This investigation does not label a plausible cause as proven or change the
failed acceptance verdict.

The confirmed defect is diagnostic attribution: many distinct rejection
conditions collapsed into one code, and registration input failures were not
recorded by field before cleanup. The new canonical adapter fixes that defect;
it is not a bypass or a claim to have repaired an unproven historical cause.

## Change

- `tools/machine-acceptance/source_fixture_evidence.php` is a pure field checker.
- `tools/machine-acceptance/source_fixture.php` uses it before creating any
  adoption intent or registering an owned instance in the isolated controller.
- The exception names the first failed field; the diagnostic contains every
  failed field and boolean checks, never their values or credential material.
- One exclusive first-error record is attempted. Failure to save it does not
  replace the validation error, and an existing first error is not overwritten.
- Both helper files are mandatory frozen inputs of the canonical fresh launcher.
  Recomputing a bundle manifest cannot authorize a different helper.

The original ownership, runtime, fresh-failure threshold, candidate forwarding,
DNS and execution boundaries remain enforced. A registered fixture still has
no health or forwarding receipt. No production route, queue, scheduler or
automatic permission is added.

## Offline Reproduction

The regression child runs only in in-memory SQLite, with the real address
inventory and Observer. It uses four synthetic target records and R15-shaped
sample gaps; no packets are sent. Provider reconciliation is mocked and stray
HTTP is forbidden.

When a new callback updates the report between collection and summary, the
summary can contain `evidence_current=false`, `threshold_reached=false` and
`observed_failures=null`, with `reason=sample_not_collected`. This now produces
`source_fixture_evidence_invalid_evidence_current`, retaining all three rejected
fields. Runtime node mismatch and diagnostic-write failures are also covered.
These are demonstrated failure modes, not retrospective proof of R15's cause.

## Verification

- Focused PHP: 54 tests, 2,781 assertions, zero failures/errors.
- Full PHP Unit: 3,966 tests, 31,615 assertions, three skips, zero failures/errors.
- Full Python acceptance tools: 379 tests, 26 environment skips, zero failures/errors.
- All 106 R15 frozen inputs and 104 pinned R14 inputs/results remained unchanged.
- No SSH, cloud/DNS API, real database, deployment, new VM, old-round replay,
  automatic-switch change, queue change or tanzhen access occurred.

See [the aggregate evidence](evidence/source-fixture-diagnostics-20261114.json).
These local tests do not replace Linux/runtime or live cloud acceptance. A
future live check needs a new approved scope, the canonical helper bindings and
a fresh freeze; this change does not prepare or authorize such a check.
