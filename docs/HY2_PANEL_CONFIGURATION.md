# HY2 panel configuration

## Scope

The HY2 node editor now exposes optional settings implemented in the standard
kelinode-rs v0.1.360 release:

- Congestion control: unset (node default), `cubic`, `bbr`, `new_reno`.
- Unauthenticated HTTP/3 masquerade: unset, `not_found`, `string`, `file`, `proxy`.
- Obfuscation: existing Salamander and experimental Gecko, with optional minimum
  and maximum packet sizes. Zero/unset uses 512/1200 bytes; maximum is 2048.

`congestion_control` and `network_settings` are stored in the existing server
protocol_settings JSON. There is no database migration. Generic node responses
carry `networkSettings`; v2node responses additionally carry `network_settings`.
Existing nodes retain their defaults until an administrator changes these fields.

Brutal is deliberately not offered. ECH has a separate, default-off section.
Standard v0.1.360 artifacts do NOT compile either feature. The v0.1.361 formal
release build includes ECH, but not Brutal; its publication is gated by exact-package
real-client checks. Verify `kelinode build-info` and follow the rollout steps in
kelinode-rs `docs/HY2_ECH_PANEL_ROLLOUT.md`. A UI switch is not runtime readiness.

The `ech` object stores `enabled`, the node-local `key_file` path and a single-line
public `config` (Base64 ECHConfigList). Private keys never enter the panel.
The panel only accepts HY2, certificate-verified DNS SNI, absolute Linux paths,
and bounded extension-free X25519 public configs with unique IDs (maximum 16).
Unknown ECH fields and private PEM input fail validation. Node responses contain
only `networkSettings.ech_key_file`; subscriptions contain only public configs.
Disabling clears the object. No schema migration is needed.

ECH subscriptions use mihomo `ech-opts`, sing-box `tls.ech.config` (public PEM),
and the standard HY2 URI `ech` parameter. Automatic subscription filtering only
admits canonical stable mihomo >=1.19.31 and sing-box >=1.14.0, not application
wrappers or unknown versions. Unsupported clients omit ECH nodes rather than
silently receiving non-ECH configuration. Direct URI import must use a client
known to understand the parameter; generic URI subscription requests stay filtered.
Existing subscriptions without ECH are unaffected.

## Validation and privacy

The panel rejects unknown transport/masquerade keys, invalid packet bounds, HY1
with HY2-only settings, invalid response status/header/body combinations, and
proxy URLs containing credentials, queries, fragments, controls or backslashes.
An empty custom response is supported, including 204/205/304. Body whitespace is
preserved. Status 233 is reserved and cannot be used for masquerade.

Static paths refer to the node's filesystem, not the panel. The node performs
the final filesystem/runtime validation. Use a dedicated public-content directory,
never a directory containing keys or application secrets. With obfuscation enabled,
an ordinary HTTP/3 browser cannot directly reach the masquerade response.

Server-only settings (masquerade URL/path/body and congestion selection) are not
exported into subscriptions. Gecko exports use the selected obfs type and the
supported client-specific packet-size fields. Share URIs only use standardized
obfs type/password parameters, not invented packet-size query parameters.

## Conservative client compatibility

The initial Gecko allowlist is intentionally narrow: canonical `mihomo` core
versions >= 1.19.31 and `sing-box` core versions >= 1.14.0, with a three-component
stable version. These are conservative floors, not claims about the earliest
implementation. Unknown clients, missing versions, prereleases and application
wrapper versions are filtered for Gecko only. Salamander behavior is unchanged.
Core prerelease suffixes are retained during User-Agent parsing so an early alpha
does not accidentally satisfy a stable-version floor.

Reference schemas:

- [mihomo v1.19.31 outbound implementation](https://github.com/MetaCubeX/mihomo/blob/v1.19.31/adapter/outbound/hysteria2.go)
- [sing-box HY2 outbound](https://sing-box.sagernet.org/configuration/outbound/hysteria2/)
- [Hysteria URI scheme](https://v2.hysteria.network/docs/developers/URI-Scheme/)

## Delivery

Update keliboard backend and its compiled keli-admin assets together. Updating only
the node binary does not add these panel controls. Upgrade target nodes to a
compatible release before opting into the settings; the editor cannot prove the
version of a generic or unbound node. Confirm the node's reported running state
after saving. Do not mass-enable Gecko on existing production nodes because older
clients will no longer receive those nodes.

Local checks cover request validation, model round-trip, both node API shapes,
subscription exports, User-Agent parsing, legacy defaults, and desktop/mobile
save/reopen workflows. They do not replace a production rollout or real network
acceptance for each deployment. The preview uses mock data and makes no production
changes.

## ECH local verification (2026-09-25)

This section records the local integration stage, before the formal release build.

- Backend: 203 tests, 840 assertions passed for HY2/ECH validation, node APIs,
  exports, protocol filtering, UA parsing and existing protocol regression.
- Admin: 24 server test files / 171 tests passed. Production build and single
  bundle check passed; compiled assets were synchronized to this local repository.
- Desktop 1440px and mobile 390px: default-off, private PEM rejection before save,
  valid save/reopen, disabling clears ECH, no overflow or console errors. Existing
  non-ECH HY2 editor workflows also passed at both sizes.
- `tests/Fixtures/run_hy2_ech_smoke.py` used the real request validator, model,
  node response builder and subscription exporters through `export_hy2_ech.php`.
  It then used the current ECH core and real sing-box 1.14.0, mihomo 1.19.31 and
  official Hysteria 2.12.3 clients on loopback. Hysteria parameters were decoded
  from the exported URI; this is not a GUI import test.
- Four cases passed: sing-box, mihomo, Hysteria with ECH, and legacy plain HY2.
  Each sent and received 65,536 TCP + 512 UDP payload bytes. Completed-handshake
  counters were ECH=3, plain=1, resumed=0. Total upload and download each equaled
  264,192 bytes for fixture user 7, and the second traffic drain was empty.
- Clients were intentionally terminated on Windows (exit code 1, not natural
  success exits). Connections, UDP sessions and relays reached zero at the
  configured 30-second QUIC idle expiry. The core then stopped naturally with
  exit code 0. This is accounting at the core drain, not durable SQL billing.
- All clients verified the test certificate. For mihomo, the fixture CA was
  preloaded before reloading the exported proxy because upstream constructs
  proxy TLS configs before installing custom CAs. No insecure bypass or system
  trust-store change was used.

Local raw evidence is in `.phpunit.cache/ech-panel-smoke-r4/result.json`; SHA-256:
`1b3092a161bf23ce22a2862b81582916238a22b6012d781f908f2efe2c897b72`.
The result includes binary hashes, metrics and process exit codes. The preceding
r1/r2/r3 failure evidence was retained: missing fixture field, fixture CA load
order, and a 15-second wait shorter than the configured 30-second idle expiry.
No production/core fix was inferred from those fixture failures. Private test
keys remain in ignored local test output and must not be uploaded as evidence.

This local fixture does not run the actual node scheduler, the panel HTTP kernel
or SQL billing. It is distinct from the subsequent authorized Linux run below.
No automatic commit, release, deployment or existing node upgrade occurred.

## Combined Linux verification (2026-09-25)

The authorized isolated embedded-node run passed on 164.92.77.55, with nobody,
private loopback networking, CPU 80%, memory 512 MiB, swap 0 and a 300-second cap.
The successful unit ran from 03:37:13 to 03:40:25 UTC and exited 0. Real node HTTP
auth/routes, ECH handshakes, MySQL/Redis/Horizon settlement, quota removal and
restoration, invalid-key preservation, timed key replacement and retired-key
rejection were exercised together. Admin save used the real validator/model,
not the admin HTTP controller or authentication kernel.

Raw upload/download each equaled 9,600 bytes, billed at 1.5 as 14,400 each; four
SQL aggregates and all 13 receipts reconciled independently. Connections/relays
and queues drained; the node, database, Redis and Horizon exited 0. Linux node
regression passed 639 library tests (2 ignored) and 16 CLI tests. Relevant final
PHP tests passed 34 tests / 519 assertions.

The test group nearly exhausted its memory cap (memory.events max=217, no OOM).
It is a short functional acceptance, not long-run/WAN/capacity certification or
permission to enable production nodes. This acceptance used a pre-release ECH
binary, not the subsequently rebuilt v0.1.361 archive. Defaults remain unchanged.

Full identities, retained fixture failures, corrected timestamp/JSON comparisons,
latency/resource trends and evidence locations are recorded in
`keli-core-rs/docs/HY2_PANEL_ECH_ACCEPTANCE_20260925.md`. Successful evidence archive
SHA256: `cd4c3e1af4f0a22a2e28aff1d32a49187ba12add0da6d2e2b53c58d6bcb4ef34`;
1,379 downloaded files verified individually. Private keys are excluded.
