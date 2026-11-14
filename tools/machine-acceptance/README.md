# Isolated Forwarding Verifier

This is a disabled-by-default worker, not the IP-blocking detector. Independent
failover primarily consumes the existing source-attributed mainland IPv4 ICMP
reports: any configured target reachable is healthy; complete consecutive failed
rounds from the same verified source can reach the policy threshold, including
a source that has never reported healthy. A previous healthy report is optional
audit metadata, not an admission gate.
Unknown, expired, foreign-source and IPv6 reports do not establish that verdict.
The worker below checks a **new candidate's forwarding before DNS cutover** only.

Fresh acceptance drivers must use `controller_readiness.boot_ready` and
`controller_readiness.pipeline_ready` for the controller's system/security and
control-pipeline checks. Mainland ICMP success is not a controller prerequisite;
optional Ping diagnostics are evidence only. The canonical installation runner
uses these predicates and pins this helper in its frozen inputs. Per-round
three-role drivers must be regenerated and frozen under a new approved scope;
closed drivers and their prior outcomes must never be patched or replayed.

An already-unreachable source may qualify from fresh, complete, consecutive
all-target failures. A *controlled fault-injection test* still needs a healthy
source before injection so that it measures an actual transition; this is a
scenario condition, not the operational failover rule. Candidate reachability,
identity-bound TCP/UDP receipts, DNS ownership and execution gates are unchanged.
See [probe admission rules](../../docs/machine-failover-probe-admission.md).

## Source Fixture Evidence Diagnostics (2026-11-14)

R15 stopped at `adopt-source` with `source_fixture_evidence_invalid`. Its retained
version/binary reports and five complete failed samples can be checked offline,
but the failing invocation did not retain the individual validator inputs or
checks. The exact historical rejected predicate is **not recoverable** from
those aggregates. Do not relabel a synthetic reproduction as its confirmed cause
or rewrite R15's closed result.

`source_fixture.php` is the canonical isolated registration adapter; it is not a
production route, command, scheduler or source of authorization. A separately
reviewed fresh CLI must provide its existing `KeliLiveScope` and bind
`adopt-source` to `keli_acceptance_adopt_source`, and the fixture-only initial DNS
path to `keli_acceptance_source_current`. Both this file and
`source_fixture_evidence.php` must be staged, installed into the active runtime
and frozen, together with `cli_diagnostics.php`. The canonical launcher
requires these pins and rejects unreviewed replacements even when a bundle
manifest is recomputed. This change does not adapt or resume any old driver.

The pure evidence checker reports each rejected field, retaining installation,
account/task/cloud identity, exact runtime node projection, thirty-second
freshness, native failure threshold and failed-source checks. It does not loosen
them, accept stale observations, create a health/forwarding receipt, or permit
candidate creation/DNS cutover. Numeric strings are not proof of integer counts
or timestamps. Unknown and recovered sources remain refused.

Rejections retain all fixed field codes/boolean checks and attempt one exclusive
`source-fixture-evidence-first-error.json` journal write before any adoption
intent or registration. No values, IPs, session IDs, hashes, credentials or raw
logs are exported in this diagnostic. The exception also carries a fixed first
field in its reason, such as
`source_fixture_evidence_invalid_evidence_current`, so the existing bounded CLI
error projection keeps attribution even if diagnostic storage fails. A failed
write never replaces the original rejection or overwrites earlier evidence.

The PHP Unit suite covers individual negative guards, malformed values,
diagnostic storage failures and first-error preservation. Its isolated SQLite
child uses the real address inventory and Observer, four synthetic target
records and R15-shaped sample gaps; provider reconciliation is mocked and stray
HTTP is forbidden. A newer callback between collection and summary reproduces a
rejection at `evidence_current` (`sample_not_collected`). This is a tested possible
failure mode, **not proof that this was R15's unique cause**. No extra Ping,
provider/DNS request, SSH, real database, host change or live replay is involved.

```powershell
php vendor/phpunit/phpunit/phpunit tests/Unit/Services/MachineAcceptanceSourceEvidenceTest.php
python -B -m unittest -q test_probe_sampling test_diagnostic_capture
```

## Active Runtime And Safe CLI Locations (R16 Offline Repair)

R16 uploaded the evidence helper, but its installer copied only ten runtime
files and omitted `source_fixture_evidence.php`. Executing that exact public
copy loop in a temporary directory reproduces a Laravel `ErrorException` at
the old `source_fixture.php:16`. The retained R16 exception contains no original
message/location, so this confirms the packaging defect, not a recovered private
stack or proof that no other failure could have occurred.

`runtime_payload.py` is now the sole reviewed active-file list. A fresh
`runtime_install.py` must `import runtime_payload` and call
`runtime_payload.publish(BUNDLE, BASE)` unconditionally inside `install()`,
replacing its old manual copy loop before starting any service. The publisher
validates every required file against the existing staging manifest before the
first write, uses exclusive regular-file creation and independently reads all
active hashes back. Missing, changed, linked or existing runtime files refuse
startup; this is not an in-place upgrade/retry mechanism. Both `RUNTIME` and
`STAGING` must contain its complete payload and the publisher module.

The fresh CLI must load `cli_diagnostics.php` before its guarded dispatch and use
`keli_acceptance_cli_failure($command, $error)` for adoption failures. The source
adapter records the original exception through an exclusive first-error journal
attempt without replacing it. Only fixed public relative filenames, bounded
line numbers, known exception categories and warning severity are projected.
Unknown locations become null. No absolute path, message, trace, arguments,
config value or raw log is exported, and the projector needs no Laravel service
container. Existing field-level rejection details remain intact.

The local `live_operator.command` must import `cli_error_capture` and execute
`result = cli_error_capture.capture_result(campaign, result)` before its
`campaign.save('latest-' + name + '.json', result)` statement. Capture uses an
exclusive local first-error file and an independent readback. Write denial,
readback mismatch or missing diagnostics remain explicitly unconfirmed, do not
replace the original error and do not block original resource cleanup. A later
error cannot overwrite the first. The canonical fresh launcher checks these
installation and capture bindings before importing any dispatch driver.

```powershell
python -B -m unittest -q test_runtime_payload test_cli_error_capture test_diagnostic_capture
php vendor/phpunit/phpunit/phpunit tests/Unit/Services/MachineAcceptanceSourceEvidenceTest.php tests/Unit/Services/MachineAcceptanceCliDiagnosticsTest.php
```

No R16 driver/input/result was patched or replayed. No new live scope, VM,
deployment, provider/DNS/SSH operation, automatic switch or queue change is
authorized by this repair. POSIX ownership/link checks still require separate
Linux runtime validation. See the [R16 offline finding](../../docs/machine-source-fixture-diagnostics.md#r16-runtime-publication-defect).

## Timing-Safe Sampling and Cleanup Reads

R14 stopped with `baseline_report_gap`: two accepted probe times were followed
by a 22-second gap, while an intermediate real Observer sample existed. Its
closed bundle and first error remain unchanged. The reusable fix is
`probe_sampling.py`, not a longer permitted gap or an edited historical driver.

Fresh three-role bundles using `diagnostic_round_runner.py` now pin and use this
sampler for `RoundRunner.baseline`. Other fresh entries must explicitly delegate
that method to `probe_sampling.baseline` and pin the same helper before freezing.
There is no new live scope, default run ID, credential, scheduler or automatic
dispatch. Old bundles missing the new pins are refused, not silently upgraded.

The single control SSH call runs only the existing isolated CLI `observe` and
`status` commands. It does not Ping, create a VM, advance an execution or write
DNS. Observer writes remain confined to the separately authorized isolated
database. Sampling runs locally on that controller for at most 60 seconds, with
a 70-second outer SSH timeout; slow reads do not get another unconditional
five-second sleep. Stop/deadline checks bound the loop and each CLI child.
The original campaign, fault fuse and lease durations are not extended.

Each accepted round binds the same installation, public IPv4, binary, configured
node, probe revision and observation session. Source status and the actual native
Observer receipt must refer to the same probe time, policy and context; racing
reads are not counted and start a new qualification sequence without erasing
earlier evidence. Three distinct consecutive samples, at least five seconds
apart and no more than twenty seconds apart, are still required. The newest must
be no older than thirty seconds. Failed-source qualification additionally needs
the real native failure counter/threshold. Unknown, stale, duplicate, foreign or
incomplete rounds cannot manufacture that threshold. A candidate still needs
reachable evidence; no previous healthy source or controller Ping is introduced.

Full existing node safety inspection runs before and after the window. A changed
PID/start time, binary, configuration or runtime session fails the stage. Original
auxiliary diagnostics remain once per role with their existing packet budget;
they cannot replace sampling evidence or overwrite its first error, even if
diagnostics or evidence persistence also fail. Final native candidate admission,
forwarding, DNS ownership and dispatch-time guards remain unchanged.

`aliyun_cleanup.php` is a describe-only reader factory embedded by
`aliyun_cleanup.py`. Both files must be frozen; the launcher also checks the hash
of the PHP bytes already loaded by the embedding module. The PHP file requires
LF in Git. No helper file is installed on or written into the production host.
Only an explicit boolean opt-in on `DescribeDomainRecordInfo` for an exact
positive record ID accepts `InvalidRecordId.NotFound` or `InvalidRR.NoExist` with
HTTP 200/400/404 and no returned record. The latter is documented by
[Aliyun](https://help.aliyun.com/zh/dns/api-alidns-2015-01-09-errorcodes) and was
the actual post-delete response in R14. Authentication failures, domain errors,
throttling, redirects, 5xx, malformed/oversized responses and timeouts remain
unconfirmed. Missing by ID does not mean an empty name inventory: the original
ownership, full-list agreement, deletion journals and two independent absence
reads are still mandatory. This reader cannot dispatch a delete or extend the
cleanup deadline, and input parameters cannot override the selected describe
action or signing metadata.

For exact-ID success, an omitted `Remark` becomes an empty string, never a missing
record. Explicit null/non-string remarks are rejected. The caller must still
match the full journal tuple and explicitly allow a blank remark for a partial
create with a direct receipt; a blank string does not authorize deletion of an
activated or foreign record. List responses keep omitted remarks absent so the
existing exact-ID fallback still runs. Both read paths normalize the lossless
45-character Aliyun wire marker to the original 192-bit slot identity. Legacy
57-character markers are read unchanged; no stored slot is renamed or migrated.
The embedded reader's normalization is self-contained: the existing credential
bridge needs only its pre-existing signing helper, not a newly deployed provider
class or method. A separate-process fixture deliberately has no application
autoloader or new record-set class and verifies this boundary.

The provider adapter now preserves only a fixed action/code allowlist and HTTP
status on DNS failures, not messages, request IDs, signing parameters, bodies or
stack traces. `cli_diagnostics.php` and `cli_error_capture.py` project that context
for `initial-dns` as well as source diagnostics, and preserve an independently
read-back, exclusive first-error record with the correct phase. A future adapter
must route **both** commands through `keli_acceptance_cli_failure`; the fresh
runtime binding check rejects the old source-only branch. This does not reopen
R17, change its result, retry its writes or reconstruct its missing provider code.

Local synthetic regression (no SSH, cloud/DNS requests or production database):

```sh
python -B -m unittest -q test_probe_sampling test_diagnostic_capture test_cli_error_capture test_runtime_payload
```

The existing PHP Unit suite includes `MachineAcceptanceAliyunCleanupTest`, with
all provider HTTP faked and stray requests forbidden. The normal Python CI test
lists include the sampling regressions; editing those lists does not run CI.
Windows results do not substitute for a Linux runtime or a newly approved live
acceptance. R14 must never be replayed or relabeled as passed by this fix.

## Runtime Boundary

Python 3.11+ uses only the standard library. The real protocol engine is an
operator-installed, SHA256-pinned sing-box executable, not a new implementation
of HY2, TUIC, Trojan or VLESS. The generated config has only loopback direct
inbounds, one fixed isolated echo destination and one authenticated TLS outbound.
No direct-outbound fallback, insecure TLS, server override, arbitrary command,
provider API, panel-user write or real-user credentials are accepted.

Production serving requires non-root Linux. It listens on HTTPS at
`127.0.0.1:9446/v1/verify`; a separately secured reverse proxy may expose that
exact endpoint to the panel. Do not publish it as an unauthenticated test proxy.
The shared key, config, certificate and TLS key must be owned by the service user
and private (0600); state must be private (0700). The example systemd unit keeps
all public egress blocked until the exact authorized candidate addresses are
added. It is only a template and is not installed/enabled by this change.

Requests have direction-separated HMAC signatures and a durable SQLite replay
journal. The worker validates signatures before JSON parsing, rejects duplicate
JSON keys and unknown fields, and admits only one job at a time. A job has a
24-second total measurement budget, at most four expected nodes, exactly three
distinct 32-byte echo frames per transport, and bounded duplicate observation.
There are no retries. UDP is counted by original frame identity; arrival order
does not corrupt the digest. TCP validates exact bytes and checks for extra data.
This is readiness evidence, not a throughput or tail-latency benchmark.

Each node gets a private temporary client config and an independent sing-box
process. The parent reaps/kills the process before deleting its directory. A
failed stop or deletion makes `cleanup_complete=false`. Linux children also have
parent-death SIGKILL, CPU/file-descriptor/core limits; the service cgroup is the
outer memory/process boundary. A crash cannot produce a successful receipt or
replay a spent challenge. Private artifacts left by a host/process crash require
operator review; there is no broad startup directory deletion or VM cleanup.

## Isolated Profiles

`worker.example.json` deliberately contains no usable profiles and an invalid
binary pin. The deployer must install the verified binary, a secret and an
explicit isolated profile after separate live-test authorization. A profile has:

- `isolated: true` and `expires_at` (epoch, at least 30 seconds in the future and
  no more than one hour ahead). Renewal is explicit, not automatic.
- `allowed_targets`: exact canonical public IPv4/IPv6 addresses, not CIDRs, DNS
  names, mapped/transition IPv6 or metadata addresses. Schema 1 remains IPv4-only;
  schema 2 explicitly binds each target's `A`/`AAAA` family. There is **no
  production loopback override**.
- `echo_address` / `echo_port`: an exact fixed isolated destination reachable
  through the candidate. Never a user-selected arbitrary destination.
- `nodes`: one to four ordered `{id, protocol, port, outbound}` entries. The tuple
  must match every panel-requested node exactly; unsupported protocols fail closed.
- `outbound`: dedicated fixture credentials plus `tls.enabled=true`, a verified
  `server_name`, optional inline `certificate` PEM list and optional
  `certificate_public_key_sha256` base64 pins. `insecure` cannot be true. HY2 and
  Trojan require `password`; TUIC requires `uuid` and `password`; VLESS requires
  `uuid` and only XUDP packet encoding is supported in this initial adapter.

The worker **does not create or revoke server-side accounts**. Its profile is a
pre-authorized, time-bounded isolated fixture, not a production customer. Cleanup
proof covers processes, sockets and credential copies created by this challenge;
it does not claim deletion of the pre-existing private fixture profile. The
fixture owner remains responsible for expiry/revocation and server-fixture
cleanup. Do not deploy it with live customer identities or interpret client-file
cleanup as formal user/account cleanup.

## Local Verification

```powershell
python -m unittest test_worker.WorkerTests
$env:KELI_TEST_SING_BOX = '<absolute verified sing-box executable>'
$env:KELI_TEST_OPENSSL = '<absolute OpenSSL executable>'
python -m unittest test_worker.RealHysteriaTests
```

## Canonical Application Archives (2026-11-12)

`application_bundle.py` prepares a local archive from an exact, immutable Git
commit. It never creates authorization, contacts a provider, uploads a bundle,
installs anything or resumes a live round. R8's failed archive and closed evidence
must remain unchanged. Its CRLF bytes and native-installer line-2/exit-2 evidence
are a packaging lead, not retrospective proof of the original remote root cause.

The repository attributes require LF for `*.sh`. The producer also overrides
host Git EOL settings for each export; it does not edit system/user Git config,
use a dirty working tree or normalize arbitrary binary members. LFS downloads
are disabled: committed LFS pointers remain pointers, not verified materialized
assets. Any required external assets still need their own reviewed/pinned step.

From the repository root, with a new, existing private output directory and an
explicit reviewed 40-character commit (placeholders below are not authorization):

```sh
python3 -B tools/machine-acceptance/application_bundle.py build \
  --repo /absolute/repository --commit '<full-reviewed-commit>' \
  --output /private/fresh-preparation/application.tar.gz
python3 -B tools/machine-acceptance/application_bundle.py check \
  --archive /private/fresh-preparation/application.tar.gz
```

Before publishing, the producer checks actual archived shell bytes for CR,
UTF-8 BOM, NUL and invalid UTF-8, including extensionless shell shebangs. It
compares the four mandatory installer/helper members to their immutable Git
blobs. Unsafe paths, links/special files, duplicate members, private `.env`/Git/
audit entries and exceeded compressed/expanded/member/shell limits fail closed.
The report contains only hashes, sizes and fixed checks, not script contents.

Publication is exclusive and same-directory: an existing destination is never
overwritten, and a racing writer's file is preserved. The filesystem must
support hard links; there is no unsafe overwrite fallback. Stop, dispatch,
cleanup and closed markers in any ancestor refuse output. Only this writer's
temporary file is removed on failure. An I/O failure after publication remains
an uncertain preparation result, not permission to overwrite or dispatch it.

Future `install_only_runner.py` bundles must now include and pin
`application_bundle.py` alongside the runner, journal and every existing input.
The entry checks the archive again **before binding the journal or creating the
round intent**, even when the file matches its frozen digest. It rejects a
CRLF/invalid archive instead of repairing a downloaded file. No historical
package is patched, and passing these checks does not authorize execution.

Offline regression, from this tools directory:

```sh
python3 -B -m unittest -q test_application_bundle test_install_only_runner
```

Fixtures reproduce host-dependent CRLF export, verify LF/blob/binary/mode
preservation and reject malformed archives, closed outputs and output races.
The POSIX Bash test executes only two archived prefix lines, never an installer
body. Windows-only runs cannot establish that Linux behavior. Current verified
platform counts and exact CI snapshot pins are recorded separately in
`docs/evidence/application-bundle-linux-20261112.json`. The isolated Linux run
[43215000991](https://github.com/keli-123456/keliboard/actions/runs/43215000991)
passed 139 tests with zero skips, excluding four native Windows tests explicitly.
All 18 aggregate source hashes matched commit
`5c6fce2bc1f401277f0fea7957239b8661ecafd7`. Public-base legacy CRLF prefixes
returned 2; canonical LF prefixes returned 0 on real Linux. All 13 archived
shells passed the LF checks. Only the dedicated temporary-branch workflow ran;
at validation time it was not merged, and no image publication or deployment
was triggered.

The subsequent main integration preserves that tested commit in merge history,
but excludes its dedicated workflow, temporary branch filter and
`application_bundle_ci.py` entry from the final tree. Main keeps the producer,
pre-intent archive guard, offline regressions and normal CI test invocation.
Source and merge commits use `[skip ci]`; the recorded Linux result is still
the exact snapshot above, not a new Linux run of the merge. No deployment, VM
creation, R8 retry, automatic-switch change or tanzhen operation is included.

## Control-Bundle Staging Preflight

`staging.py` is a separate, read-only preparation tool. It addresses a failure
observed during the closed 2026-11-11 isolated trial: certificate preparation
started before the control run-scope file finished uploading. It is not an
execution controller, a certificate issuer, an uploader or a new panel endpoint.
It does not reopen that trial or touch its authorization, resources or evidence.

The operator must first prepare and approve a new isolated run separately. Its
reviewed artifact list must include **every** script, hook, release and scope
file that the next stage will use, including the DNS certificate hook. The
expected manifest SHA256 and required filenames come from the trusted local
packaging record, not from the remote bundle's own description. Complete and
verify the uploads before publishing `staging-manifest.json` last via an atomic
rename in the same private directory. Upload temporary names must not overwrite
files used by a running stage.

The manifest has exactly `schema: 1`, `run_id`, `role: "control"`, and `files`.
`files` maps each reviewed basename to its exact `{bytes, sha256}`. One required
entry is `run-scope.json`, which contains `schema: 1`, the same `run_id` and
`role`, and integer `started_at`/`expires_at` Unix timestamps. Other reviewed
scope fields may be present; its entire file is pinned. A run is at most two
hours. The private state directory must have an `owner.json` containing exactly
`{"schema":1,"run_id":"<new-run-id>","role":"control"}`. Either a `closed.json`
or `stop-request.json` path blocks preparation, including dangling symlinks.

Run the tool through the already verified SSH transport on the isolated Linux
control host, immediately before any credential envelope, certificate intent or
provider/DNS operation. For example, after separately approving and staging a
new run (the values below are placeholders, not executable authorization):

```sh
python3 staging.py --bundle /private/new-run/bundle --state /private/new-run/state \
  --run-id keli-accept-new-approved-run --manifest-sha256 '<trusted-manifest-sha256>' \
  --require run-scope.json --require control.py --require certificate-hook.php \
  --require application.tar.gz --minimum-remaining 300
```

The guard checks exact required membership, identity, bounded sizes and SHA256,
rejects links/path traversal and unsafe Linux ownership/permissions, and checks
stop, file identity and remaining time again after hashing. It reads at most
256 MiB per artifact and 512 MiB in total; JSON is capped at 128 KiB. Insufficient
remaining time blocks the next stage, without renewing or extending the scope.
The default five-minute margin covers the planned certificate stage, not the
entire trial. Larger stages need their own appropriate remaining-time check.

Failure returns a nonzero exit and a fixed reason without file content, paths,
credentials or raw exceptions. Success is only a current preparation snapshot:
`authorizes_execution` always remains false. The caller must stop on failure and
must require `private_permissions_checked=true` for the actual Linux controller;
Windows tests do not establish Linux filesystem protection. This is not a lock
against later changes or a transaction with a provider. Recheck the current
authorization, stop state, context and immutable inputs at the operation's own
dispatch boundary. Do not cache this result to bypass execution gates or time
limits, and never use it to repeat an ambiguous create/certificate request.

Local synthetic regression (no cloud, DNS, SSH or production database):

```powershell
python -m unittest -v test_staging test_worker.WorkerTests
```

The existing `CI` workflow has a separate Windows/Linux job for these synthetic
tests. It uses only Python's standard library and does not start the application,
MySQL, Redis, protocol client or a cloud/DNS transport; the verifier replay tests
use only temporary SQLite files. Adding the job does not dispatch CI.

On 2026-11-12, a separately authorized, Linux-only temporary-branch run
[43055064649](https://github.com/keli-123456/keliboard/actions/runs/43055064649)
passed all 35 tests with zero skips, including the real POSIX directory/file
permission test (Python 3.11.18, UID 1001). The four tool/test file hashes match
main commit `a701aa72a61d7fe4314263833d63d078c6e655b2`; temporary CI commit
`b64e811dfab6f4e62f47a3c4ba1fb403811d5f2d` changed only that branch's workflow.
Network/DNS and subprocess dispatch were blocked during tests, with zero denied
attempts. The branch was not merged and no release workflow, production database,
cloud API or DNS write was used. This closes the skipped Linux filesystem check,
not real cloud-init, certificate issuance or public-network forwarding acceptance.

The staging guard is not automatically wired into historical one-run controllers
or the panel's live runner. Future authorized test packaging must explicitly
integrate it and pin this tool/worker code as well as the run artifacts. Linux
runtime ownership, certificate issuance, cloud-init and WAN failover still need
a new independently authorized isolated acceptance.

## Bootstrap Diagnostics Before Cleanup

`bootstrap_diagnostics.py` and `diagnostic_capture.py` address an evidence gap
in the closed R5 trial: the source reported initialization failure, but deletion
started before its installation summary could be collected. The old trial and
its evidence remain unchanged; these tools cannot recover its deleted logs.

For a **new, separately approved isolated run**, package and pin these five
files alongside the reviewed one-shot driver and all its existing dependencies:

- `bootstrap_diagnostics.py`: standard-library, read-only probe on the owned node.
- `diagnostic_capture.py`: controller-side schema filtering and cleanup adapter.
- `diagnostic_round_runner.py`: fresh-scope entry using the driver's existing
  `RoundRunner(loader=...)` interface. It does not patch application queues,
  Horizon, the agent, production settings or the historical one-run scripts.
- `entry_probe_diagnostics.py` and `entry_probe_capture.py`: the separately
  opted-in ICMP evidence collector and its strict controller-side filter.

Freeze all files, including these tools and `authorization.json`, in the new
run's `offline-result.json` file-digest map. Use the copied entry from that same
new bundle, never an old directory or a previously dispatched intent:

```sh
python3 /private/new-approved-run/diagnostic_round_runner.py \
  --work /private/new-approved-run
```

The entry refuses closed/stopped/cleanup rounds and existing create/round
intents **before importing the driver**. It checks the approved disabled flags,
bounded scope, frozen file membership/hashes and the exact diagnostic tool
bytes. These local records are preparation evidence, not a substitute for the
user's live-test authorization or the driver's operation-specific gates.

Immediately before the original `cleanup.main()` starts, the adapter attempts
one collection per recorded source/candidate using the existing `node.task()`
and `node.execute()` bridges. Those bridges must keep the original resource
receipt, stable host alias, pinned host key, key-only SSH and strict host-key
checking. No new SSH implementation, key discovery, password fallback, host-key
bypass, provider operation, retry, service restart or configuration write is
introduced. The probe rechecks the exact private node owner marker before any
other observation. A role with no original receipt, or whose delete intent is
already present, is not contacted.

Collection has a 20-second shared budget, at most 10 seconds per role, and no
retries. The final minute of the existing campaign window is reserved for
cleanup; diagnostics never extend that window. Each installation log is read
only as a maximum 256 KiB tail. Result/owner JSON is bounded, rejects duplicate
keys and does not follow links. The only service query is the fixed read-only
`systemctl show kelinode` with a two-second limit. Config/installer/callback
contents, private keys and user data are not read for export.

`bootstrap-diagnostics.json` is saved before deletion and contains only fixed
schema fields: sampled installation phase, marker category counts, curl/HTTP
error numbers, bootstrap result, service state/exit numbers and binary/config
presence. The controller validates the entire response before saving it. Raw
logs, URLs, credentials, configuration bodies and arbitrary exception messages
are never stored in the exported report. Missing, unsafe, unreadable, timed-out
and expired observations remain explicitly unavailable, not zero failures.
Counts describe markers in the sampled tail, not distinct incidents or a
confirmed root cause. A successful collection is not a successful installation.

Transport, classification or summary-write failure must still call the original
cleanup exactly once. Cleanup failures remain visible and are not swallowed or
retried by this adapter. The diagnostic intent prevents repeated SSH collection
on cleanup resume. A missing summary with an existing diagnostic intent is an
incomplete observation, not permission to replay the test or report no errors.
The adapter does not change the original acceptance verdict or create new VMs.

Synthetic local regression (no real SSH, cloud/DNS, application or database):

```sh
python -m unittest -v test_bootstrap_diagnostics test_diagnostic_capture
```

Tests cover secret-bearing/untrusted log text, unknown observations, byte/time
bounds, symlinks/hard links, identity rejection, collection-before-delete,
transport/storage failures, cleanup exceptions, closed-round refusal and the
fresh entry with an inert synthetic driver. Windows tests cannot establish
POSIX file protection; that check belongs to the Linux CI job. New real-node
diagnostics and the still-unconfirmed R5 installer failure require a separately
authorized live round. This change does not create or deploy that round.

### Source, ICMP and Firewall Evidence (2026-11-13)

R10's source and independent root pings received no echo replies, but the old
aggregate did not preserve complete returned ICMP error classifications. That
source was deleted. The following new code cannot recover the old packets or
identify their historical drop point, and must not be copied into a closed run.
It does not change the Rust agent, its five-second loop, report verdicts, panel
settings, queues or automatic execution gates.

The frozen `authorization.json` of a **new, separately authorized isolated run**
may opt in with the following member. Absence or `enabled: false` means no new
network collection. This example is documentation, not live authorization:

```json
{
  "entry_probe_diagnostics": {
    "schema": 1,
    "enabled": true,
    "targets": ["39.156.70.37", "111.33.167.71", "123.150.76.218", "157.255.219.143"],
    "timeout_seconds": 2
  }
}
```

Only one to four distinct canonical public unicast IPv4 targets and a one- or
two-second response wait are accepted; no domains, CIDRs, multicast, IPv6,
metadata/private addresses or arbitrary commands. Both new tools, the original
three diagnostic tools, authorization and every other input must have reviewed
SHA256 pins in the fresh bundle. The entry refuses missing pins or invalid
diagnostic scope before importing the driver. Existing strict SSH and host-key
checks remain the responsibility of the pinned `node.execute()` bridge; there
is no new transport, credential discovery or fallback.

After bootstrap diagnostics, but before original cleanup, one exclusive
`entry-probe-diagnostics-intent.json` bounds this extra phase to 44 seconds total
and 22 seconds per role, with a 20-second remote body budget. The final campaign
minute remains reserved for cleanup. Missing receipts, already-started delete,
closed or expired rounds never initiate collection. Existing intents are never
replayed, including when their report is missing. A stop request permits passive
observation only and suppresses extra ICMP echo requests.

For a baseline-failure branch that needs fresh ICMP error classification, the
reviewed new driver must call `entry_probe_capture.capture(campaign, node)`
before it writes its stop intent, then proceed to stop/cleanup regardless of
diagnostic success. The exclusive intent makes the later cleanup adapter skip
a second collection. A branch already marked stopped must not remove that
marker or reactivate ping to fill a missing sample. No historical driver or
closed round is patched by this change.

The node verifies the exact private run/task owner before commands. It checks
the running kelinode PID's network namespace against its own, and records this
again after collection. Unknown or different namespaces suppress active ping;
a changed PID/context is explicit, not stable evidence. Each target's route
lookup retains only the public selected source and a fixed route type. Active
ping requires that source to equal the original role receipt's IPv4 and uses
`-I SOURCE -4 -n -c 1 -W TIMEOUT -w TIMEOUT_PLUS_ONE -- TARGET`, at most one echo
request per target with no retries. Missing/non-iputils tools remain unknown.

Both stdout and stderr enter one bounded pipe. Fixed categories distinguish
permission, bind and local-route failures, returned ICMP unreachable/prohibited,
fragmentation, TTL, parameter and redirect errors, no echo before deadline, and
process timeout/output overflow. Echo success requires a matching target reply,
a one-packet summary and exit zero. Returned ICMP errors are not erased into
timeouts; missing/unreadable samples never become zero received/error counts.
No raw ping text, error-sender IPs, logs or arbitrary exception text is exported.

Two read-only `nft -j list ruleset` snapshots retain hashed chain/rule references,
hook/priority/default policy, rule position/verdict, literal receipt-address
membership and anonymous counters. Catch-all drops and jumps remain visible,
not only rules mentioning ICMP. Table/chain names, comments, full predicates and
unrelated IPs are not exported. Named sets/maps, regular-chain direction and
other conditions stay unknown. This is **not** a complete nft evaluator: rule
address membership is not an effective allow/drop verdict. Counter deltas require
unchanged structure and nondecreasing values; missing/named/reset counters stay
unavailable. Shared rule and host ICMP counters cannot attribute individual
packets to this diagnostic. `/proc/self/net/snmp` is read with a byte cap rather
than its virtual zero size. Legacy iptables, XDP/eBPF, provider and transit filters
are not covered by this current-network-namespace nft snapshot.

Every command has a time/output cap and its child is killed/reaped on failure.
No firewall rule/counter reset, tcpdump, sysctl write, namespace switch, package
install, service restart, cloud/DNS operation or agent change is performed.
`entry-probe-diagnostics.json` accepts only the exact fixed schema and original
role/source/target context. Collection, transport or save failure still invokes
original cleanup once; original cleanup failures remain visible and are not retried.
These observations never change the acceptance result or establish censorship.

Local synthetic regression (fake commands/SSH/cloud/DNS, temporary files only):

```sh
python -B -m unittest -v test_entry_probe_diagnostics test_entry_probe_capture test_diagnostic_capture test_entry_probe_linux
```

The existing Windows/Linux tools job includes these tests. The Linux step keeps
the same unprivileged network/PID namespace boundary as the isolated acceptance
so procfs counters are not shared with unrelated runner traffic. Windows skips
the Linux-only cases. This normal test-list integration uses no dedicated
temporary workflow, branch filter or CI aggregation script.

On 2026-11-13, isolated Linux run
[43267496580](https://github.com/keli-123456/keliboard/actions/runs/43267496580)
passed 213 tests with zero failures or skips, excluding four Windows-native
tests explicitly. The 12 additional Linux tests used 13 fixed synthetic Python
children to check actual pipes, timeout/overflow SIGKILL, already-reaped PIDs,
nonroot permission refusal, procfs and diagnostic-before-cleanup ordering. Test
assertions inspect the recorded return code before `waitpid`; they do not call
`poll()` to perform the reaping that the implementation must have completed.
All 27 source digests matched the tested Git snapshot, and the diagnostic
implementation remained unchanged from `e634b437`.

Main retains the tested Linux regression file exactly, but excludes the temporary
workflow, its push filter and the CI-only aggregation harness. The merge is
marked `[skip ci]`; this integration does not dispatch a new CI run, publish an
image, deploy, create VMs, replay R10 or change automatic flags. Linux ICMP/error
streams, nft rulesets and the SSH/cloud/DNS bridges remain synthetic; this is
not real-node network, HY2 or DNS-cutover acceptance. See
[Linux evidence](../../docs/evidence/entry-probe-linux-20261113.json) and the
earlier `docs/evidence/entry-probe-diagnostics-local-20261113.json` snapshot.

Primary format references: [iputils ping](https://manpages.debian.org/bookworm/iputils-ping/ping.8.en.html)
and [libnftables JSON](https://manpages.debian.org/bookworm/libnftables1/libnftables-json.5.en.html).

### Local Driver Journal Repair (2026-11-12)

R7 stopped on a local `PermissionError`; its saved evidence did not include an
errno, WinError or callsite. Its exact cause is **not established**. A local-only
AST fixture extracted only the old `save()` function from the pinned public
source, with `BASE` redirected to disposable files. On this Windows host, a
native read handle without delete sharing reproduced `PermissionError`,
`errno=13`, `winerror=5` during snapshot replacement. The old JSON survived and
the same save succeeded after the handle closed. This demonstrates a defect,
not proof that the original R7 failure had the same cause.

The new standard-library `driver_journal.py` provides:

- Unique, same-directory temporary snapshots, flush/fsync before atomic
  replacement, and cleanup of only the writer's own temporary file.
- At most eight local rename attempts over a 0.5-second retry window, only for
  Windows permission/sharing errors 5/32/33. The bound is on retries, not a
  guarantee against a blocked OS/filesystem call. No API, SSH, installation,
  callback, cloud creation or DNS operation is retried.
- Exclusive intent creation once only. Existing, partially written or fsync-
  failed intents remain spent; unknown completion is never permission to replay.
- Promptly closed native Windows read handles requesting read/write/delete
  sharing. This reduces reader interference; it does not guarantee replacement
  while arbitrary third-party handles remain open. Observers must use a bounded
  polling cadence rather than continuously reopening the file in a tight loop.
- Closed-round write refusal, immutable authorization/frozen inputs, bounded
  JSON, duplicate-key rejection and link/special-file checks. This is not a
  replacement for the bundle's private-directory ACL and ownership preflight.
- Safe local failure records containing only phase, logical record kind,
  operation, exception class, errno/WinError, and bounded trusted module/function/
  line information. Messages, full paths, arguments, locals and raw logs are
  never exported. If even the failure file cannot be written, the returned
  aggregate explicitly says `persisted=false` and preserves the first error.

`install_only_runner.py` is a reusable **future-bundle template**, not a live
entry or new authorization. It requires explicit run/commit/binary pins and the
original campaign's approved two-role scope. It rejects R6/R7 identities and
existing/closed intents, checks the complete frozen bundle and its own two
module hashes, and only then binds journal I/O. A future separately approved
package must pin both new modules, use an explicitly reviewed `install_runner.py`
entry and retain all original operation-specific identity, transport and cleanup
guards. Do not import a closed round's campaign to adapt or resume it. No new
package, authorization, private material or live invocation was produced here.

The template protects progress writes before diagnostics/cleanup, keeps the
first fatal stage error, and attempts the original cleanup even when local
progress, diagnostics or outcome writes fail. The original cleanup journal's
path/scope guards remain in force. Diagnostic intent is exclusive and collection
is at most once; a denied canonical output can use a unique sanitized fallback
record without collecting again. Cached results require the exact safe schema
and task/run identity. Missing or unpersisted required diagnostics cannot pass.
An existing dispatch intent without a resource receipt remains unavailable, not
evidence that no source was created.
Finalization never writes another progress record after the closing marker.

Local regression (all transports/providers/cleanup endpoints are synthetic):

```sh
python -B -m unittest -q test_driver_journal test_install_only_runner
python -B -m unittest -q test_staging test_bootstrap_diagnostics test_diagnostic_capture test_driver_journal test_install_only_runner test_worker.WorkerTests
```

On Windows/Python 3.12, the 37 new tests passed without skips, including native
short/long read-handle locks, concurrent observations, no repeated stage
dispatch, output fallback, spent intent, disk-full/total-write failures and
cleanup/finalization errors. The combined suite ran 121 tests: 119 passed and
two actual POSIX permission tests were skipped. The existing Windows/Linux CI
test command now includes these modules, but **no CI run was dispatched** for
this patch; its Linux behavior and true process/host-loss recovery are not
established by this local run. No new durability claim covers power loss or
storage-device failure.

A full-suite repeat exposed a too-strong stress-test assumption: a continuous,
unpaced observer can exhaust the retry window even with delete sharing. The
regression now checks both valid outcomes: an atomic update, or a numerically
diagnosed bounded failure retaining the previous complete JSON and removing the
temporary file. After the observer stops, saving must succeed. The retry limits
were not enlarged and permanent contention is never reported as success.

All 105 original R7 files matched their pre-repair hashes, with no added files.
R7 remains failed, cleaned and closed; its missing source-installation evidence
cannot be reconstructed. No production, cloud/DNS, SSH, database or tanzhen
connection was made, and no execution flag was changed. See
`docs/evidence/linode-local-driver-io-20261112.json` for the sanitized aggregate.

### Linux Driver Journal CI Evidence (2026-11-12)

The isolated [CI run 43195970920](https://github.com/keli-123456/keliboard/actions/runs/43195970920)
passed **122 tests, zero failures/errors/skips** on Linux, Python 3.11.18,
non-root UID 1001. Four native Windows handle tests were explicitly excluded
from this Linux selection, not counted as passes. The tested snapshot is
`7a20dac98d72f0a09421a4ef98af0e62d5ffc66c` on temporary branch
`audit/driver-journal-linux-20261112-695a2746`, based on main `695a2746`.

Five added tests use actual disposable POSIX modes: directory-write/read denial,
private file modes, unique diagnostic fallback, a kernel denial before mock
cleanup, and another kernel denial during cleanup. The first error and durable
diagnostics remain distinguishable from subsequent cleanup failure. All cloud,
SSH and DNS operations remain mocks. A process/socket audit guard was active
before test imports; no attempted external dispatch reached it. Fifteen source
hashes in the downloaded aggregate matched the exact Git snapshot.

Only the dedicated acceptance workflow ran during that trial. Main remained
unchanged then, no image publication/deployment ran, and all 105 frozen R7 files
were unchanged. No live run was imported or resumed and no production/tanzhen
connection was made. Full evidence is in
`docs/evidence/driver-journal-linux-20261112.json`.

The main integration preserves the CI snapshot in merge history but brings in
only `test_driver_linux.py`, the evidence/documentation and that test module's
normal CI invocation. The temporary branch filter, dedicated workflow and
`driver_journal_ci.py` remain only in the acceptance history. The merge uses
`[skip ci]` to avoid automatic image publication. The passing Linux result still
belongs to the precise snapshot above, not a new run of the merge commit.

These are kernel-permission and synthetic-driver checks, not a new VM install,
real cloud cleanup, power-loss/host-loss recovery or an explanation of the
original R7 error. A new live round still requires separate approval, a fresh
identity, a frozen complete bundle and preflight.

### Linux Diagnostic CI Evidence (2026-11-12)

The isolated [Linux CI run 43137719391](https://github.com/keli-123456/keliboard/actions/runs/43137719391)
passed **74/74 tests with zero skips**, including both actual POSIX ownership
and file-mode checks, on Python 3.11.18 as non-root UID 1001. The temporary
`audit/bootstrap-diagnostics-linux-20261112-a701aa72` branch contains test snapshot
`f7e61713a1d97d35b0a3245709e2d3caf77bfc12`, based on application commit
`a701aa72a61d7fe4314263833d63d078c6e655b2`. All nine pinned Python source hashes
matched the downloaded aggregate artifact. Transport mocks and a Python audit
guard denied external process/network operations; no attempt reached either
guard. Cloud creation, DNS writes and production connections were zero.

Only the dedicated acceptance workflow ran for that branch/commit; no image
publication or deployment was triggered. The snapshot was not merged to main.
This is synthetic evidence for staging and pre-delete diagnostics, not a live
SSH, service, installer, protocol or failover acceptance. R5 remains failed with
an unconfirmed installation cause; its closed scope must not be resumed. A
future real trial still needs a fresh approved scope and frozen bundle.

### Structured Installer Execution Evidence (Local Only)

The local follow-up after R6 adds execution records independent of log-text
classification. `ProvisionBootstrap` embeds the reviewed
`resources/server-machine/provision-diagnostics.sh` prefix in its existing
bootstrap file. For this one task it explicitly uses that file as `BASH_ENV`;
child shells return before the provisioning body, callback or identity writes.
Only the fixed bootstrap, panel installer and native-installer paths install the
hooks. Unrelated Bash children do not inherit new shell options or diagnostics.
There is no `DEBUG` trap, command tracing, `BASH_COMMAND` capture or `eval`.

The callback remains exactly `task_id`, `installation_id`, `state`. New private
`diagnostic-bootstrap.json`, `diagnostic-installer.json` and
`diagnostic-native_installer.json` files contain schema 1, the same task and
installation identities, a fixed script/phase/event, exit status, ERR status and
line, and an explicitly supplied exit line. Writers use private temporary files
and atomic replacement; storage failure is best effort and cannot replace the
installer or callback exit status. Native cleanup still releases its original
lock/work directory and exits with the original code. Standalone installers
without this task's helper only use no-op optional hooks; the node binary,
runtime configuration and service definition are not changed by this work.

An ERR line is a Bash failure/call-site line in the named generated script, not
a complete stack trace or proof of the deepest failure. Explicit installer
exits pass their own `LINENO` before exit. Ordinary EXIT trap `LINENO` is not
used: it can be 1 regardless of the actual exit location. If no explicit line
hook ran, `exit_line` is null. Signals, shell syntax errors, killed children or
old installers can leave only a phase or a caller's timeout/error code; absence
of a final record does not mean success. An `event=phase` record has no final
exit status. Error markers and the final service status remain separate evidence.

The new probe wire schema is **2**, with one `execution` entry per fixed script.
Each JSON read is at most 16 KiB, private, bounded, link-safe and duplicate-key
checked. Records must match both the task and the installation ID in the valid
bootstrap result. Unknown fields, phases, non-integer codes/lines and inconsistent
events are rejected without exporting their values. Missing legacy records and
invalid/unreadable evidence retain null code/line fields, never zero. A missing
systemd unit no longer exports its default zero exit/restart numbers as a run.
Old schema-1 trial reports stay unchanged; new tools must be frozen together in
a fresh bundle, not substituted into a dispatched or closed round.

Local Bash/PHP tests exercise command, pipeline, subshell and function errors,
explicit exits, callback/storage failures, the timeout boundary, the actual
native cleanup trap with inert steps, and the Python parser of the resulting
JSON. Cloud-init round-trip checks retain the original 16 KiB decoded/21,848-byte
encoded Linode limits and reject oversized synthetic extensions before HTTP.
At the initial local-only checkpoint these tests did not establish a real
timeout/signal lifecycle or new Linux filesystem protection. The earlier
74-test CI snapshot predates this schema. The later execution CI below is the
evidence for the modified tools; it does not reopen R6 or retrospectively
establish R6's installation cause from aggregate markers.

### Linux Execution CI (2026-11-12)

The source integration keeps the normal main-branch CI workflow. The temporary
snapshot-only `ci_linux_evidence.py` and `ci-source-pins.json` remain in the tested
commit history, not as runnable main-branch entry points. The permanent sanitized
record is [bootstrap-execution-linux-20261112.json](../../docs/evidence/bootstrap-execution-linux-20261112.json).
It preserves both the source pins and failure/fix provenance; it is not a new
post-merge CI run or permission to deploy/create a live trial.

The final [run 43163637072](https://github.com/keli-123456/keliboard/actions/runs/43163637072)
passed 198 PHP/Bash tests and 84 Python tests with zero skips, failures or errors
on temporary branch `audit/bootstrap-lifecycle-linux-20261112-a701aa72`, commit
`1b811f5f0fb7475b2d5b0856b7a0de2b0ef07a67`. After dependency installation the
tests ran as UID 1001 in an isolated network namespace with only loopback.
Twenty pinned hashes matched. No production, cloud or DNS connections occurred.

The initial failing run is preserved: TERM could be recorded as exit zero by
the native EXIT trap, while terminating only bootstrap could report success
and orphan its installer. Bootstrap now forwards TERM only to its own timeout
child and waits for it; installer TERM traps preserve 143 through cleanup.
The original 600-second/15-second timeout and callback shape are unchanged.

`linux_bootstrap_lifecycle.py` accepts only private, explicitly marked synthetic
`/tmp/keli-provision-test-*` fixtures on non-root Linux. It starts a fresh session,
verifies PID/start-time/session membership, signals only the owned target and
uses an independent bounded TERM/KILL fallback for test cleanup. No service
names, host reboots or cloud operations are involved. The fixtures use the
actual native cleanup function/TERM trap and the actual panel TERM trap, with
inert installation steps and a callback returning synthetic transport failure.

Real GNU timeout returns 124 (both installer layers record 143). External TERM
to the native fixture or bootstrap returns 143 in all three layers. All three
cases record exactly one failed callback, clear the owned lock/work directory
and have zero remaining session processes before the harness fallback, as well
as after it. Linux ownership/modes, ERR lines and explicit exit lines also pass.
Only aggregate results were uploaded; no release workflow or image publication
ran. The test branch is not merged and nothing was deployed or created.

The synthetic timeout is shortened to two seconds; this is not a full-duration
600-second test, uncatchable SIGKILL/host-loss recovery, real cloud installation
or WAN/DNS failover acceptance. It does not identify R6's original cause. New
live work still requires its own approved scope and fresh frozen bundle.

## Runtime Diagnostics Repair (2026-11-13)

`runtime_diagnostics.py` is a pure parser/projector for a separately prepared
loopback harness. It has no cloud, SSH, DNS, service or execution entry. The
closed `keli-runtime-20261113-3c707fc-f39a` result remains a failed acceptance;
its original iputils cause was not retained and cannot be reconstructed here.
The repair and synthetic harness copy do not authorize another live attempt.

Fixed TCP/UDP listeners must still bind loopback. Do not simply skip UDP when
checking `/proc/net`: a wildcard UDP socket can be either an unexpected listener
or a normal ephemeral relay. The narrow exception requires an after-probe
snapshot, a fresh private PID/network namespace with only loopback and no
external route, a non-root/no-capability/no-new-privileges worker, an OS ephemeral
port outside every fixed listener, and an inode belonging to the exact live
node/client PID and start time. Recheck process identity around FD enumeration.
Before-probe wildcards, TCP wildcards, unknown/shared owners and public peers
still fail. Preserve the family/state/port/inode/role classification, not raw
socket tables. After stopping, require both fixed listeners and tracked socket
inodes to disappear. These rules establish no public-network or WAN result.

The iputils runner retains partial cases and the earliest allowlisted
`first_error` (stage, reason, class, optional exit/timeout and bounded basename/
line frames). Later decode, summary, signal or cleanup errors remain in the
bounded `secondary_errors` list. A first-error sidecar, derived from the explicit
output basename, is exclusively created as 0600 and fsynced before further
cases or cleanup; an existing different record is never overwritten. Failure
to save it is secondary evidence, not permission to discard the original error.
No raw stderr/stdout, exception messages, command arguments, environment or
configuration bodies are retained. The outer harness also checkpoints its
first error before removing private work, and projects valid child diagnostics
even when the final child file is truncated. Missing reports remain a failure.

All six iputils cases, exact kernel packet counters, zero skipped cases,
capability isolation and cleanup proof are still necessary for success.
Incomplete/malformed evidence is not zero loss or a passing test. New bundles
must pin the repaired runner and projector together; never substitute them into
a dispatched or closed bundle. This change does not modify node/core source,
queue configuration, production or automation switches, and installs nothing.

Local mocked regression from this directory:

```sh
python -B -m unittest -q test_iputils_namespace test_runtime_diagnostics
```

The local repair snapshot additionally tests the complete harness, timeout/
signal error ordering, immutable first-error writes and cleanup failures. These
Windows mock tests are not Linux namespace, actual iputils, signal lifecycle or
real protocol reruns; those still require separately authorized Linux execution.

## Iputils Child Bootstrap Repair (2026-11-13)

Runtime-R2 remains closed, failed and cleanup-confirmed. Its HY2 loopback TCP/UDP
and socket checks passed, but the first iputils child exited 1 before providing
valid JSON. The retained `JSONDecodeError` does not establish its original import
exception; this local repair does not retroactively classify that round as passed.

A CPython FileFinder regression reproduces a separate source defect: the intended
root-owned `0711` staging directory permits known-path reads but denies listing
to the unprivileged worker. Ordinary sibling imports can consequently fail before
the old child enters its reporting code. Both `bootstrap_diagnostics` and
`entry_probe_diagnostics` now load from fixed sibling files inside the guarded
child entry. Temporary module registration supports dataclasses and nested imports
and is restored even on failure. Source links/unsafe POSIX modes are refused;
no path discovery, cached shadow-module fallback or directory permission widening
is introduced. The child uses isolated Python mode, remains non-root with zero
capabilities, and retains all six existing cases and packet counters.

Import, access, syntax and test-entry failures emit one structured failure JSON
document with fixed stage/reason, allowlisted class, optional errno, and bounded
known basenames/lines. Messages, source lines, paths, argv, environment and raw
tracebacks are not exported. Unexpected Python-level output is discarded with a
bounded counter and makes the case fail. The namespace parent keeps stdout and
stderr separate; fixed interpreter/setpriv/traceback classifications survive
instead of being replaced by JSON decoding. Unknown or mixed output still fails;
it is never searched for a convenient passing JSON fragment. Both pipes are
closed independently, and later signal, decode, report or cleanup failures do not
replace the first diagnostic in the child, namespace or outer projection.

Local evidence is recorded in
[runtime-r2-child-bootstrap-local-20261113.json](../../docs/evidence/runtime-r2-child-bootstrap-local-20261113.json).
The 179-test focused integration includes a fresh authority-free public harness
copy, the control fixtures and report projection. The related tool suite has 316
tests with 26 platform/isolation skips; the counts overlap. These Windows mocked
checks do not prove POSIX file enforcement, actual iputils, namespaces or signal
behavior on Linux. R2's frozen source, reports and archive are unchanged. Any
future Linux rerun requires a new approved identity and a newly pinned bundle;
no closed round or live preparation is modified or reopened here.

## Repeated Runtime Projection Repair (2026-11-13)

Runtime-R3 is closed, failed and cleanup-confirmed. The Linux bootstrap passed
7/7 cases and the functional process exited zero, but the collector rejected
the iputils evidence after a second projection dropped required proof fields.
The retained six case counters do not reconstruct the lost raw report. Its
failure and the unexecuted five control cases are not reclassified as passed.

`project_iputils` now keeps only the typed per-case packet/child proofs and
allowlisted cleanup/isolation maps. Each boundary recomputes success from them;
cached `ok`, verification booleans or an "already projected" marker cannot
replace evidence. Original case cardinality remains when unknown rows are
filtered, and ordered six-case completeness remains mandatory. Matching packet
counters must also fit the case: 1/1/4/(1-4)/1/0. Legitimate producer
`first_error: null` is accepted, but malformed diagnostics, dropped/truncated
error history, missing proofs, skips and cleanup failures remain failures.

`project_runtime` shares the strict error ingestion with the iputils projector.
A newly prepared public harness must delegate `safe_runtime` to this pinned
function, including at control pre-admission. `runtime_report_projection.py`
is the pure collector boundary. It preserves the first error, deduplicates
secondary summaries, rechecks identity/resources and retains canonical failure
shapes across persistence. It contains no launcher, credentials or authority.
Never substitute these files into a dispatched or closed bundle.

Local regression:

```sh
python -B -m unittest -q test_runtime_diagnostics test_runtime_projection_chain test_iputils_namespace
```

The authority-free public-helper snapshot additionally exercises the actual
`collect_component` and `persist_report` functions, JSON readback, collector
reprojection, cleanup aggregation and real control pre-admission (stopping
before any cgroup/process operation). It also checks truncated final reports,
checkpoint recovery, nonzero exits and legacy missing-proof failures. The
focused suite has 263 tests without skips; the related 349-test tool suite has
26 Windows/platform/isolation skips. Counts overlap, not additive.

Evidence:
[runtime-r3-projection-local-20261113.json](../../docs/evidence/runtime-r3-projection-local-20261113.json).
This is local synthetic/JSON validation, not another Linux kernel, HY2 or
timeout/SIGTERM acceptance. All 58 closed R3 files and 52 earlier R2 files are
verified unchanged. No cloud, SSH, DNS, GitHub, production, queue or switch
operation occurs. Any further real Linux run needs a new authorized identity,
new helper adapter and freshly pinned bundle; R3 is not reopened.

## Runtime R4 Linux Acceptance (2026-11-13)

The separately approved `keli-runtime-20261113-r4-75604ea5` round passed on one
new Debian 12/x86_64 Linode and is closed with cleanup confirmed. Its fresh LF
bundle passed 308 offline tests without skips before the read-only provider
preflight and sole create dispatch. No previous round or approval was reused.

Real Linux results: 7/7 bootstrap cases with 39 checks; all six real iputils
cases with exact kernel counters 1/1/4/3/1/0 and no skips; byte-exact HY2 TCP and
two UDP echoes with one attempt per transport and no protocol retries. All five
serial controls passed: communicate timeout, external SIGTERM, GNU timeout,
socket negatives and filesystem/report recovery. The expected negative exit
statuses are assertions, not unexpected application failures.

The staged supervisor used the pinned strict runtime/iputils projectors. It
ran a separate 12-check synthetic projection smoke, explicitly not counted as
kernel traffic. The collector serialized and reprojected each aggregate, then
exclusively persisted/fsynced the exact runtime proof read by real control
pre-admission. Bootstrap, functional and control reports all survived this
roundtrip. The new driver also separated cloud ownership from SSH address
selection so missing or ambiguous IPv4 cannot by itself block owned-VM deletion.

Tests were serial, private-loopback-only and non-root under CPUQuota=25%,
MemoryMax=448MiB, swap=0 and TasksMax=96. No OOM was observed. All owned units
stopped and the private directory was removed only after sanitized evidence
was saved and read back locally. One DELETE was dispatched; two independent
GET-404 plus complete-inventory checks, 9.8 seconds apart, confirmed absence.
The 58 closed R3 and 52 earlier R2 files remain unchanged and failed as recorded.

Evidence:
[runtime-r4-linux-20261113.json](../../docs/evidence/runtime-r4-linux-20261113.json).
No production deployment, business-node restart/upgrade, queue/default/switch
change, DNS operation, GitHub action or tanzhen access occurred. This is not
WAN/mainland reachability, censorship, DNS/TTL cutover or full failover
acceptance. Build flags are not ECH/Brutal activation evidence; sampled RSS is
not peak performance or a high-user-count memory test. Further end-to-end
failover testing still needs a fresh scope and authorization.

## Protocol Interoperability

The real HY2 tests bind temporary endpoints only to 127.0.0.1/127.0.0.2, generate
fresh certificates and fixture credentials, run the actual sing-box client and
server, and check TCP/UDP byte equality, wrong credentials/TLS identity, missing
or duplicate UDP, replay refusal and temporary-client cleanup. Only the fixture
patches the public-address predicate; that option is not exposed in config/CLI.
Windows loopback passing does not establish Linux sandbox or WAN readiness.
TUIC/Trojan/VLESS are supported config adapters but still need their own real
protocol interop fixtures before being recommended for live cutover.

The native record-set path uses schema 2 from
`docs/machine-acceptance-verifier.md`. It echoes the candidate/task/attempt,
source installation, family, target, process/configuration and context digest
inside the signed receipt. It still runs the pinned sing-box protocol client,
not a ping or an HTTP readiness check. Changing family/target requires a new
challenge; spent challenges are not replayed after worker restart. The local
unit tests mock measurement to verify this binding and the address/replay
policy; they are not a real public IPv6 forwarding acceptance. No worker or
profile is automatically installed or changed by the panel code.

References: [sing-box direct inbound](https://sing-box.sagernet.org/configuration/inbound/direct/),
[TLS identity fields](https://sing-box.sagernet.org/configuration/shared/tls/).
