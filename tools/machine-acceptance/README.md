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
