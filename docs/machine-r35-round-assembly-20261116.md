# R35 round entry and Linux runtime assembly

## Scope

Preparation and simulation only. No cloud creation, production SSH, DNS changes,
production database, deployment, image publication or R34 execution. Persistent
automatic switches and tanzhen are untouched. The initial approval covered a
temporary GitHub branch for isolated Linux tests. On 2026-11-16 the user separately
approved selective integration of this round's source, reusable tests and evidence
into main, with `[skip ci]` and no image publication. The temporary workflow,
CI-only launcher, checksum sidecar and binary CI artifact are excluded.

## Changes

- `member_round.py`: host-side lifecycle, bounded execution/cleanup, durable
  pending requests, stop handling, exact response binding, Linux journal lock,
  and separate `resources_cleaned` / `acceptance_passed` results. Uncertain
  dispatches move to reconciliation; they do not replay bootstrap or native work.
- `member_auxiliary.py`: pinned DNS records, fault restoration, evidence export,
  private-material removal and controller-last teardown. DELETE intent precedes
  dispatch; ambiguous results only permit inspection. Absence needs independent
  reads separated by ten seconds, timed after the response. Unknown is not absent.
- Evidence export failure fails acceptance but does not deliberately retain
  billable resources. Unresolved ownership or deletion still blocks cleanup claims.
- `member_runtime_bundle.py`: immutable application/vendor/runtime/node assembly,
  exact Composer lock matching, pinned ELF and source provenance, duplicate/path/
  link checks, all-file hashes, executable modes and exclusive extraction.
- Adapter packaging accepts an already-committed test trait without inserting it
  twice. Runtime copies and application copies must have identical hashes.
- The native integration test now continues from two source registrations through
  A1 deletion, confirmed cleanup, cooldown, A2 creation and DNS switch, then exact
  task cleanup. B remains unchanged before teardown; its final deletion is cleanup,
  not failover. Existing canonical labels, markers and native safety gates remain.

## Frozen Inputs

- Application baseline: `5c683d6fe33ed47b12d93bc59b913b9026989cd9` plus the explicit
  adapter/test overlay. Other dirty worktree files are not in the package.
- Application archive SHA-256:
  `d85ee6e6f525eace218cc0631930a407ba8669e0b0060126be828087afb8a53f`.
- Full runtime archive SHA-256:
  `2b8bd3573e12bd21c038cd84c7761ef7d709c20cfae448f112c9713cf6066e46`.
- Runtime size: 41,881,208 bytes; 14,004 files; 157 dependencies.
- Composer lock SHA-256:
  `1a7bc62c308529c9edd7f495ca544e93c53933d39570f4eb43fe92c6365f64e1`.
- Node source: `3c707fc89b16e2895727c24251b6076252d5cab0`, v0.1.387,
  Linux x86_64 musl. This reuses the original local candidate; it is not a new
  compilation or a formal node release.
- Node ELF SHA-256:
  `654a8344d22bc3373b114345d49d5f6a2804be98e6f51bc2ce85e309fbda0be0`.

No authorization, credentials, environment file or runtime grant is included.
`execution_authorized=false` and `live_ready=false` remain explicit.

## Verification

- Local Python: 81 tests, 5 platform skips; repeated before main integration.
- Local focused PHP: 26 tests, 9,687 assertions, passed.
- Temporary branch: `acceptance/r35-round-linux-20261116`.
- Temporary commit: `9702f8ff4e39be60f9db4d229a04d16f5e239463` (`[skip ci]`).
- Push-triggered workflows before manual dispatch: zero.
- Linux run: https://github.com/keli-123456/keliboard/actions/runs/43827001232,
  completed successfully on 2026-11-16 at 15:58:10 Beijing time.
- Linux Python: 81 tests, 78 passed and 3 Windows-only sharing tests skipped.
  All four Linux-specific process/lock/permission/unauthorized-entry tests passed.
- Linux PHP 8.2.35: all 335 tests in `MachineFailoverNativeBridgeTest`, 14,937
  assertions, zero failures/errors/skips. This is the complete native-bridge test
  class, not the entire application test suite.
- The pinned ELF executed successfully and reported version 0.1.387. Archive,
  node and lock hashes in the CI summary match the local freeze.
- Downloaded synthetic evidence archive SHA-256:
  `be2a35aeaceefc499169d859e3469523dcb1ed9925bd6765f47d5a27ce054458`.

Linux checks run as an unprivileged user inside a separate network namespace with
no external routes. They use the exact frozen archive, not a fresh dependency
resolution. The node executes `build-info`; this does not install a daemon or
prove HY2 forwarding. Cloud/SSH/DNS receipts are simulated. PHP tests exercise
actual native services against in-memory SQLite and deny-stray HTTP fakes.

## Remaining Live Gates

This is not a live R35 execution claim. The host entry uses an injected controller
port; no live bootstrap/authority factory is armed by this package. The PHP
coordinator retains its in-memory rehearsal guard. The strict existing transport
is packaged, but packaging is not proof of a permitted full live controller.

Before any real round, the bounded isolated CLI authority and live host-port
composition still need explicit verification, followed by minimal read-only
preflight and a valid execution grant. Real elapsed cooldown, provider quota,
installation callbacks, genuine domestic reachability and external DNS propagation
remain unverified by this simulated run. Main integration preserves these gates
and does not import the temporary CI workflow, CI-only script or binary CI assets.
