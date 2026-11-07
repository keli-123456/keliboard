# Independent Acceptance Worker Contract

This document specifies the panel adapter's trusted-worker boundary. The
[isolated sing-box worker](../tools/machine-acceptance/README.md) implements it
using Python's standard library and a pinned external protocol client. Real HY2
TCP/UDP loopback fixtures complement synthetic panel/provider tests. This is not
WAN, production-account or Linux sandbox acceptance. Live verification and DNS
gates remain off by default.

IP-blocking evaluation remains based on the existing mainland IPv4 probe policy,
not this worker. One reachable configured target is healthy; a complete failure
streak reaching the configured threshold is only a suspected-blocked verdict.
This worker is a separate new-candidate forwarding guard before DNS cutover.

## Trust and Scope

The deployer sets one fixed HTTPS URL, a shared secret of at least 32 bytes and
an explicit policy-ID-to-profile mapping in `config/machine_acceptance.php`.
The API cannot supply these values. Profiles must map to dedicated, bounded
protocol-client adapters and isolated test credentials, never real users.
The worker and deployer are trusted principals: a valid HMAC authenticates the
receipt's source, not the truth of an untrusted worker's measurement.

The real worker must separately enforce allowed panel identity, target provenance,
public IPv4, node/protocol/port allowlists, expiry, byte/time/process limits,
single-use challenges and concurrent-request limits. It must never turn the
request into arbitrary shell commands or a generic forwarding/SSRF service.
No provider credentials, machine token, user identity, node configuration body
or client private file is sent by the panel adapter.

## Request

The panel sends one POST with TLS validation, no redirects or retries, a
3-second connection timeout and a 30-second total timeout. JSON is UTF-8 and
signed as **raw bytes**, without reserialization:

```text
X-Keli-Signature: lowercase_hex(HMAC_SHA256(key, "request\n" + raw_body))
```

Request fields:

| Field | Meaning |
| --- | --- |
| `schema` | Integer `1` |
| `challenge_id` | Random 32-character lowercase hex; persisted before dispatch |
| `issued_at`, `deadline_at` | Integer UTC epoch seconds; deadline is issued + 45 |
| `profile` | Server-configured isolated profile, `[a-z0-9_-]{1,64}` |
| `task_id`, `installation_id` | Exact new provisioning task and installation |
| `target_ip` | Exact registered public IPv4 |
| `configuration_fingerprint` | SHA256 of expected panel node configuration, not a full node-attested config hash |
| `session`, `binary_sha256` | Current Agent process session and pinned binary digest |
| `nodes` | Ordered nonempty list of `{id, protocol, port}` entries; current worker admits at most four |
| `echo_frames` | Three distinct base64-encoded random 32-byte frames |
| `echo_sha256` | SHA256 of the three decoded frames concatenated in order |

The worker must validate the request signature in constant time before using any
input, reject expired/unknown/reused challenges and acknowledge no measurement
until the correct protocol endpoint has been authenticated. Use proven protocol
clients. TLS certificate/name or approved fingerprint checks must stay enabled.
Unsupported transport/protocol/profile combinations must fail, not skip a test.

## Response

The successful HTTP response body must be no more than 32,768 bytes and signed
over its exact raw bytes with the other direction prefix:

```text
X-Keli-Signature: lowercase_hex(HMAC_SHA256(key, "response\n" + raw_body))
```

Required JSON shape (placeholder strings below stand for the actual values):

```json
{
  "schema": 1,
  "challenge_id": "same challenge",
  "request_sha256": "sha256 of raw request body",
  "finished_at": 0,
  "cleanup_complete": true,
  "results": [
    {
      "node_id": 10,
      "endpoint_authenticated": true,
      "tcp": {"sent": 3, "received": 3, "missing": 0, "duplicates": 0, "bytes": 96, "sha256": "echo_sha256"},
      "udp": {"sent": 3, "received": 3, "missing": 0, "duplicates": 0, "bytes": 96, "sha256": "echo_sha256"}
    }
  ]
}
```

`finished_at` must be no earlier than issue time and no later than the panel's
current time plus three seconds. The panel must still be within the challenge
deadline. Results must match **all** requested node IDs in the same order. Both
TCP and UDP are mandatory for every node. Each transport must receive exactly
the three original frames with no missing/duplicate data. For UDP, distinguish
frame identity and order separately; report the aggregate digest in original
frame order, not arrival order. For TCP, verify framing and byte boundaries.
Counts/byte lengths are strict integers, not strings or rounded estimates.

`cleanup_complete=true` means all clients, sockets, temporary credential copies
and private files created for this challenge are gone. The current worker does
not create server-side identities: it uses an explicitly configured, expiring
isolated fixture, whose owner must manage expiry/revocation and cleanup. It does
not claim deletion of that pre-existing private fixture profile or formal panel
account cleanup. No production customer identity may be configured as a fixture.
Cleanup failure is a failed acceptance even if echo data matched. The worker has
bounded cancellation and rejects replay after a crash; private files left by a
host crash require operator review. A worker that cannot
finish within the adapter's timing limits must fail closed; do not extend leases
or retry work silently to make it appear successful.

## Commit and Limits

After authenticating a receipt the panel rechecks policy/admin/account/release
authority, the worker configuration, provider creation identity, exact fresh
Agent/node/IPv4 evidence and the original process/configuration context. A late
receipt cannot replace a newer challenge. Only then is an owned cloud resource
registered, held and unarmed, with proof valid for at most 120 seconds.

The audit stores challenge/request/receipt digests, bounded aggregate results,
reason/state and local context, not raw frames, worker bodies or credentials.
The last 100 checks per installation are retained. DNS has separate ownership,
authorization, preparation and read-back gates; passing this adapter is not
automatic permission to mutate DNS, operate power, delete old resources or
enable failover scheduling.

Three echoes are a minimal readiness check, not a performance benchmark, UDP
P99 measurement, loss-free guarantee or evidence about all client regions.
