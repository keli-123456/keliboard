# Isolated Forwarding Verifier

This is a disabled-by-default worker, not the IP-blocking detector. Independent
failover primarily consumes the existing source-attributed mainland IPv4 ICMP
reports: any configured target reachable is healthy; complete consecutive failed
rounds with a same-context healthy baseline can reach the policy threshold.
Unknown, expired, foreign-source and IPv6 reports do not establish that verdict.
The worker below checks a **new candidate's forwarding before DNS cutover** only.

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
use only temporary SQLite files. Adding the job does not
dispatch CI; this local change has not run the Linux job yet.

The staging guard is not automatically wired into historical one-run controllers
or the panel's live runner. Future authorized test packaging must explicitly
integrate it and pin this tool/worker code as well as the run artifacts. Linux
runtime ownership, certificate issuance, cloud-init and WAN failover still need
a new independently authorized isolated acceptance.

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
