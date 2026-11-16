# R35 Transport, Source Registration and Teardown Preparation

## Boundary

The latest instruction is local-only. This work does not restore the historical
R35 execution grant. No cloud, SSH, DNS or production database connection is made.
R34 and closed rounds remain untouched. No deployment, queue change, automatic
switch change, commit or publication is performed.

Baseline: keliboard `d5f99c68b4c80031c05c0b65bd65dd61645dbfae` plus the explicit
adapter/test overlay recorded by the frozen manifest. Other worktree changes
are not silently included in the package.

## Implemented Adapters

- `member_transport.py`: strict SSH, fixed remote entry, stdin JSON correlation,
  bounded timeout/output, disabled password/agent forwarding, no destructive RPC
  replay after an ambiguous response, and sanitized first-error preservation.
- Package transfer uses an exclusive fixed destination, digest verification before
  sending and after remote readback. Upload does not extract or execute the archive.
- `member_transport_entry.php`: CLI-only isolated controller endpoint. It requires
  a separately prepared R35 authority, exact host identity and runtime hashes,
  loopback isolated MySQL/Redis and an isolated cache prefix. The package contains
  no such authority. It never enables application execution or queue switches.
- `member_source_registry.php`: distinct source-A/source-B task, machine,
  installation, credential revision, policy and cloud-creation identities.
  Both sources require three fresh distinct reports from the same observation
  session, the pinned binary/version and configured nodes. Duplicate/stale,
  session-changed or mismatched reports cannot qualify registration.
- Source registration preserves canonical UUID labels and the single original
  marker. Exact cloud receipt/reconciliation checks reuse application services.
  Registration is transactional and idempotent; it grants source identity, not
  domestic reachability or forwarding success. The native observer still enforces
  A's continuous failure threshold. B need not be unhealthy merely to register.
- `member_controller_bridge.php`: only source observation/registration and exact
  task inspection/final deletion are exposed. No shell, create, power, rotation
  or arbitrary task-ID RPC exists. Final deletion persists its intent before
  dispatch; uncertain deletion is reconciled, not blindly repeated.
- `MemberCleanup`: restore faults first; inspect/delete only the pinned task roles;
  two exact absence reads at least ten seconds apart; require DNS/private-material,
  intent-reconciliation and evidence-readback receipts before controller teardown;
  controller last with two absence reads. Unknown reads, clock rollback or failed
  auxiliary cleanup do not close the run. Final teardown is not A1's retry proof.

## Offline Validation

The new source-registration RPC is composed with the native coordinator in an
in-memory application test: register A/B, observe A's fixture failure episode,
create A1, native cleanup confirmation, cooldown, create A2, and verify B's identity
and DNS remain unchanged. Cloud/DNS transports are mocked and stray requests are
denied. Synthetic time progression is not a real 300-second run.

Python tests mock SSH and auxiliary cleanup. Local child processes additionally
exercise timeout, nonzero exit and oversized output. Archive and durable-journal
regressions remain in the local test command. This Windows host does not provide
a positive Linux CLI/permissions execution result; the unarmed entry's refusal
is checked locally instead. The companion JSON contains final counts and hashes.

## Freeze and Remaining Gates

The artifact is an **unarmed adapter/application source package**, not a turnkey
live R35 round or a new node release. It contains the immutable Git baseline and
explicit LF-normalized overlay, with per-file and archive SHA-256. Its manifest
keeps `execution_authorized=false` and `live_ready=false`.

The full-round bootstrap must still compose these adapters with scoped control
provisioning, fault restoration, DNS/private-material teardown and external
controller cleanup. The native coordinator's existing in-memory-only guard is
not removed. Node binary/vendor provenance, the installed service-user environment,
all-role Linux composition and minimal read-only preflight remain pending. No old
authority or closed-round runtime is copied to pretend these gates passed.
Freeze verification and preparation are not permission to execute.
