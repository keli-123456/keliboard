# R20 Candidate Internal Failure: Offline Repair

Historical run: `keli-accept-20261115-r20-7074bf7e`.
The [R20 closed result](machine-r20-result-20261115.md) remains `blocked_not_passed`.
This repair is local only, not a deployment or another acceptance round.

## Finding

The retained candidate snapshot has a provider receipt, a dispatch boundary,
`native_binding=true`, `bootstrap_state=null`, status `needs_review` and reason
`internal_failure`. The last CLI command was `process`.

R20's public CLI selected source mode for `process`. Its native gate allowed
only `native-start`, `native-advance`, `initial-dns`, `dns-external` and `post-dns`,
in native or initial-DNS mode. The coordinated task calls `assertTask`, which
reaches that gate. The original `isolated_manual_cli_required` exception code
was not in the provisioning reason list and was masked as `internal_failure`.
The generic task catch also discarded unexpected exception details.

A local fixture using the real native/provisioning services, the same command
and mode restrictions, SQLite in memory and fake HTTP reproduces that exact
status/reason combination. It performs zero candidate readback requests, no
second create, no verifier request and no DNS write after entering the old route.
The new task diagnostic retains the cause at the admission phase.

This identifies a deterministic adapter defect consistent with R20's evidence.
It does not reconstruct the missing historical exception/stack or claim that
all other possible real-machine failures have been excluded.

## Repair

- Candidate polling uses an explicit candidate reference on the existing native-advance command, through the canonical candidate-readback helpers. The source `process` route and native gate whitelist are not broadened.
- The PHP helper binds the execution, member, attempt, task, actor, policy and candidate label, and requires the original dispatch and provider receipt before processing.
- `MachineProvisionService::readback` cannot create or reconcile a task without its original provider identity. Provider access is GET-only; local authenticated callback/task state can progress to awaiting acceptance.
- Callback and fresh-report fixtures reach awaiting acceptance without native phase advancement, forwarding verification, DNS writes, a runtime/daemon row, or another create.
- Installed waiting-IPv4 candidates enter the existing bounded sampling window. The original candidate reachability and forwarding gates remain mandatory; this is not a health bypass.
- The existing task evidence column holds an immutable, row-locked first diagnostic with a separate readback. Safe fields are phase, category, fixed cause/class enums, known public relative file/line and warning severity. Unknown paths/classes are redacted.
- Both known domain rejections and unexpected throwables are captured before error handling. Later report evidence and later failures preserve the first diagnostic. Storage denial does not replace the original failure or prevent the terminal-state save.
- Authoritative task export, immediate CLI capture, cleanup-time export and a final read-only audit preserve/corroborate each task's first failure. Internal failure with missing/invalid evidence, a failed readback, or a summary without matching task proof cannot count as verified evidence.
- Fresh-driver binding checks require the explicit-reference PHP branch before full native advancement, the canonical polling/readiness helpers, active runtime publication and task audit after cleanup but before finalization. An unadapted legacy driver refuses before dispatch.

Integration details are in the [acceptance tools README](../tools/machine-acceptance/README.md#candidate-readback-and-task-first-errors-r20-offline-repair).
No migration, UI bundle-limit adjustment, queue configuration or node-program
change is needed. The new helper raises the active runtime file count to 13;
new scopes must update their explicit file-count proofs and remain within the
existing frozen-input and resource caps rather than patching an old bundle.

## Verification

- PHP Unit: 4,062 tests, 32,233 assertions, zero failures/errors; three existing conditional cases skipped.
- Acceptance-tool Python: 460 tests, zero failures/errors; 34 POSIX, Linux/iputils or explicit loopback-binary cases skipped. Python network access was denied during the full run.
- Sync preparation adds the two regression modules to the three existing CI test commands, including the post-composer PHP-backed checks. Vendor-dependent cases explicitly skip in jobs without vendor. No trigger, job, manual-dispatch or image-publication setting is changed; this sync uses `[skip ci]`.
- Fixtures validate the old refusal, fixed pending/ready paths, foreign references/actors/policies, missing provider identity, stop and denied-operation guards. All provider/DNS calls are fake; databases are SQLite in memory.
- First-error tests cover safe field parity between PHP/Python, unknown/private locations, malformed schemas, immutable earlier errors, denied writes, missing/changed readbacks, orphan/summary-only records and cleanup continuing despite diagnostic failures.
- All 125 R20 frozen inputs and the checked closed result files still match. Older pin maps of 676 frozen files and 37 closed-result files remain valid. The pre-existing user acceptance-plan edit is untouched.

The original R20 result, cleanup and closed receipts were not rewritten. No new
scope/authority, real provider/DNS/SSH request, machine, production database
connection, deployment, queue/daemon change or tanzhen access was performed.
Automatic switches were not changed. No GitHub build, release, commit or push
was performed in this repair.

Linux runtime, real MySQL/Redis concurrency, a real candidate install, HY2 and
DNS cutover still require separate authorized acceptance. The local simulated
result does not turn R20 into a passing real-machine result.

Structured record: [R20 candidate offline evidence](evidence/machine-r20-candidate-offline-20261115.json).
