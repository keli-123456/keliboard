# R35 CLI Linux Isolation Acceptance

## Scope And Result

The temporary branch `acceptance/r35-cli-linux-20261116` verifies the CLI binding
from main `aa5387df071b967f00e2670804e95199305aa86d`. Main was not merged or changed.
The passing source is `7c9e1375b984324f10c3307b0b57ca0b053375c2`, before this
documentation-only commit. This is simulated acceptance, not a live R35 run.

- [Passing manual run 43866513310](https://github.com/keli-123456/keliboard/actions/runs/43866513310)
  completed on 2026-11-16 at 20:29:31 Beijing time.
- Fixed-path Linux PHP entry: 8 tests passed, no skips.
- Python orchestration/transport/packaging: 115 tests, 112 passed, 3 Windows-only
  sharing-semantics skips. No failures or errors.
- Full native PHP bridge: 343 tests, 15,545 assertions, no failures/errors/skips.
- The final runs contain 463 passing tests and 3 platform skips in total. The PHP
  focused CLI cases are already included in the native suite, not additional tests.
- PHP runtime: 8.2.35; PHPUnit: 11.5.55; Ubuntu 24.04 runner.

## Isolation And Coverage

The test step uses fresh network, PID and mount namespaces. Only loopback is up;
there are no external IPv4/IPv6 routes. Its environment is cleared, checkout does
not persist credentials, and neither provider tokens nor production SSH materials
are supplied. Composer installs the unchanged lock before the offline test step,
with scripts/plugins disabled. It is not a network-free dependency installation.

The real PHP process boots the copied application at `/opt/keli-isolated/app`.
A synthetic root-owned 0600 grant exists only inside this disposable fixture.
The successful cold entry exercises stop, repeated stop and expiry recovery, not
actual cloud creation. Negative cases cover missing/linked/insecure grants,
machine/RPC identity changes, runtime tampering, cached configuration, outside
database/cache settings, open persistent switches and competing file locks.

The actual host port serializes bound SSH/RPC messages against fake byte responses.
A real Linux SIGTERM stops further native dispatch, sends the bound stop operation
and completes exact simulated cleanup, with the controller deleted last. Other
tests cover lost replies, pending mutation recovery, unknown candidate identity,
two absence confirmations, A1 cleanup/cooldown/A2 retry and B remaining unchanged.

Native application service tests use in-memory SQLite and deny-stray HTTP fakes.
They assert process-local capability restrictions, NullQueue fallback dispatch,
restoration of the original dispatcher/gate/configuration after exceptions, and
unchanged queue configuration. No existing queue worker or Horizon daemon is used.

## Fixture Corrections And Run History

1. `43863501807` (`d5315ca6`) failed: three positive entry checks were rejected at
   the directory permissions guard. The remaining five negative checks passed,
   but that partial result is not accepted as overall success.
2. `43864265059` (`936e6cf0`) remains registered as queued with zero jobs at the
   final check. Normal and force cancellation returned HTTP 409, indicating that
   GitHub had not queued an executable workflow yet. It is not passing evidence.
3. `43866218652` (`d5323eb7`) failed at the new directory precondition assertion.
   Setting umask alone was insufficient for this fixture. Explicit 0700 modes
   on app/runtime and 0644 on the copied vendor autoloader resolved the issue.
   The earlier runs did not capture enough permission facts to claim an ACL cause.
4. `43866513310` (`7c9e1375`) passed. Logs explicitly confirm root ownership and
   mode 0700 for all three fixed directories. Aggregate evidence is readable by
   artifact upload after the private fixture has been removed.

No production guard was relaxed and no product/runtime behavior changed this turn.
The root fixture's removal and absence of external routes were confirmed in both
failed executed runs and the passing run. The obsolete zero-job request remains
subject to the same bounded, mock-only workflow if GitHub eventually schedules it.

## Evidence And Publication Boundary

Artifact `14226421433`, `r35-cli-isolated-linux-evidence`, contains synthetic logs,
aggregate summaries and JUnit only. Downloaded SHA-256 matches GitHub's digest:

`436729e8da342c926cf4d9dcd52dff1c5fbca0f0c24849c5a895ca2dc9a9b89f`

The passing artifact has `fixed_fixture_removed=true`, no external routes after
cleanup, zero real cloud/SSH/DNS calls and no live grant. No runtime archive or
node binary was built or published. All commits use `[skip ci]`; the branch-only
workflow is manually dispatched, named differently from release-triggering CI,
and has read-only repository permissions. Branch run enumeration found only the
four manual acceptance requests above, not an image-publishing workflow.

Keep the temporary `.github/workflows` changes and `audit/r35-cli-linux-ci.py`
out of any later main-branch merge. The reusable tests are
`test_member_cli_entry_linux.py` (requires an explicit disposable root fixture)
and `test_member_bound_linux.py`. Do not run the root fixture on a real controller.

## Remaining Limitations

This run does not verify real MySQL/Redis services, live callbacks, real SSH
authentication, provider capacity, wall-clock cloud cooldown or DNS propagation.
It does not issue an execution grant, freeze a new live package, renew an old
approval or establish live readiness. Controller bootstrap, real source/report
composition and a current separately approved real acceptance remain necessary.

No deployment, cloud creation, R34 execution, production connection, automatic
switch change or tanzhen operation occurred. Production queues and defaults remain
untouched. The prior Windows test totals are separate, overlapping evidence.
