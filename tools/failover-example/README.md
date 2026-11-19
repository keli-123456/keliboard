# Isolated example rehearsal

The admin entry only drives the fixed `lab-failover-01` fixture. It does not create entries in the panel's service, machine, policy, credential or DNS lists. There is no scheduler. It uses the regular admin authentication and a separate, default-closed grant, not a production failover switch.

## Boundary

The panel and isolated worker must receive the same explicit `FAILOVER_EXAMPLE_GRANT_ID` (32 lowercase hex characters), `FAILOVER_EXAMPLE_ADMIN_ID`, `FAILOVER_EXAMPLE_OPENS_AT` and `FAILOVER_EXAMPLE_EXPIRES_AT` (Unix seconds). The window is at most 30 minutes, evaluated at each request; a grant never renews itself. Missing, malformed, out-of-window and mismatched grants are rejected. The panel sends a grant fingerprint and a fixed operation over `/run/keli-failover-example/worker.sock`. It never forwards the admin credential or accepts a service, provider, domain, or storage address from a request.

`worker.php` processes one bounded newline-delimited request on stdin and replies on stdout. A Unix socket supervisor can connect one accepted stream to that process. For a real installation the supervisor must run the worker as a separate identity with PHP/PDO SQLite, in a code copy with no panel `.env`, no mounted production secrets or data, and no IP network access. The only writable directory is a dedicated, private `FAILOVER_EXAMPLE_STATE_DIR` outside the code tree. Restrict the socket to the panel gateway and the worker; do not publish a TCP listener or route it to an existing worker. Enforce a supervisor wall-clock kill limit in addition to the worker's 12-second stage deadline; the gateway stops waiting after 16 seconds. The Windows test relay is not a supported runtime transport.

## State and stop

- V2 verifies only A reaches one synthetic create after three failed samples. Its checked result is recorded in a private SQLite file.
- DNS starts only after V2 passes. It uses a private copy, preserving the DNS engine's commit-before-I/O guard. Only a finished, verified copy is reported as passed; a partial copy left after process interruption is not resumed or reported as success.
- DNS simulates a lost provider reply after applying one update. Readback must confirm each record once. It checks that only A's four values change and B's four records and other control data are unchanged. There is no real DNS propagation claim.
- Stop seals this grant even if an operation is in flight. Stop and timeout discard an unfinished DNS copy, leaving the V2 result intact. An uncertain HTTP reply is resolved by reading the existing summary, not issuing another operation. Neither successful replay nor reopening the browser starts a second round.

After the window, disable the entry, stop accepting new socket requests and confirm no worker is active. Retain only the sanitized result, then remove this grant's identified scratch files. No production migration, queue operation or production database restore is part of enabling or rolling back the example.
