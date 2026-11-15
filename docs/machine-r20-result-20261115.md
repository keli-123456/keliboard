# R20 Result: Candidate Processing Blocked, Cleanup Verified

Run: `keli-accept-20261115-r20-7074bf7e`.
Approved scope: [R20 isolated acceptance](machine-r20-isolated-acceptance-scope.md).
Structured evidence: [R20 blocked result](evidence/linode-r20-blocked-20261115.json).

**Verdict: `blocked_not_passed`. Cleanup passed independently; failover did not.**
The resource window was 2026-11-15 09:25:00--09:45:14 Beijing time.
The runner reported 1,231 seconds, including its preceding checks.
R20 was executed once, is closed, and must not be resumed or patched in place.
R19 was not replayed or modified.

## Verified Progress

- Application source was fixed to `7074bf7e6ba747bce743cf8077e7b36c8b3e4b33`.
- Complete offline regression: 520 tests, zero failures/errors, 17 Windows/Linux environment skips. The first offline failure, a stale test pin, was retained before its correction and the full rerun.
- All 125 frozen inputs matched after execution. Thirteen shell files were LF; PHP lint, cloud-init sizes, archive and binary hashes passed.
- Read-only preflight passed before creation, with zero provider mutations and zero DNS writes during that preflight.
- The new control machine passed all ten pipeline stages, twelve active runtime file checks and six application guard hashes.
- Actual non-root service-user PHP loading and PHP-to-Python legacy/nested diagnostic journal readback passed. The synthetic sandbox was removed before source creation; no real source adoption or first-error journals were used for that smoke test.
- The new source installed once and supplied three fresh, identity-bound reports. Its initial window contained four complete samples over 41.86 seconds, no healthy samples, and no diagnostic root echo replies.
- Source registration passed with the existing continuous-failure threshold intact and no health or forwarding success claim. This passes the step that blocked R19; it does not uniquely establish R19's historical root cause.
- The initial edge A record pointed to the source and provider readback passed. The selected public resolvers were primed to that source, not to a candidate.
- Before native dispatch, three fresh failure samples were collected over 25.818 seconds. One native execution and one candidate attempt were observed, and a distinct candidate cloud creation was receipted.

The node binary remained `v0.1.387`, SHA256
`654a8344d22bc3373b114345d49d5f6a2804be98e6f51bc2ce85e309fbda0be0`.
The fresh R20 wrapper SHA256 was
`7cd2ad41299e2f7c02c824fe9ab88f65dcd7c4612b7d10e55fcf0a1d7c471a0b`.
This was not a node rebuild, formal release, GitHub build or image publication.

## Blocking Evidence And Limits

After candidate creation, its task was exported as `needs_review`, reason
`internal_failure`, with no successful bootstrap state. The runner stopped in
`process` with `task_terminal`. The last observed native execution was still
`running`, with one attempt; it was not completed.

The candidate's bounded bootstrap diagnostic returned `response_unavailable`.
The exported evidence does not establish its underlying exception class, file
or line. No corresponding verifiable generic CLI or replacement first-error
report was present. A cleanup/evidence audit succeeding does not fill this
diagnostic gap, and the absence of an exception report does not mean the
candidate operation succeeded.

Do not attribute this failure to SSH authentication, a particular cloud API,
initialization scripts, user traffic or confirmed IP blocking without more
evidence. Bootstrap success was not verified; a specific bootstrap failure was
not proved either. No second candidate, alternate credential or create retry
was dispatched.

The source used the natural-failure branch. No ICMP fault was injected.
Candidate reachability, candidate HY2 TCP/UDP, DNS cutover, post-cutover TTL
convergence and actual post-DNS forwarding were not completed. Initial source
DNS publication is not candidate DNS cutover. The four-target policy remains
unchanged: any one reachable IPv4 is healthy; no replies alone do not uniquely
prove external censorship.

## Cleanup And Production Boundary

All three new cloud resources were deleted, control last. All three actual DNS
record IDs were confirmed absent; the certificate TXT had already been deleted
and was not deleted again. No unrelated record or machine was claimed.

| Beijing time | Independent readback |
| --- | --- |
| 09:44:41--09:44:53 | All three cloud IDs absent, scoped inventory empty; all three DNS IDs absent and exact names empty; no scoped volumes. |
| 09:45:01--09:45:13 | Same independent cloud, DNS and volume absence checks passed. |

The gap between the first readback finishing and the second starting was
8.003998 seconds. Neither DELETE acknowledgement nor failed SSH was used as
absence proof.

Private cleanup passed all six stages: services, certificate, fixture revocation,
containers, local clients and private files. Two synthetic accounts were
confirmed revoked. Secret files, certificate private material and isolated
containers/volumes were removed; the local run directory had no named private
key, credential envelope or client configuration residue. Original credentials
and SSH identities were not revoked or rotated. No final billing audit is claimed.

Final read-only production checks confirmed healthy queues, all thirteen queue
classes present, zero paused masters, unchanged container identities and Nginx
configuration, and automatic gates still closed. There was no formal deployment,
database write, queue/daemon change, business-node restart, default-version
change or tanzhen access. The older frozen-input and closed-result pin maps
(676 and 37 files) remained valid; the pre-existing user acceptance-plan edit
was also left untouched.

## Next Step

Offline, locate the candidate `internal_failure` path and add a safe, independently
preserved task-level exception class/file/line diagnostic plus regression tests.
Do not guess its root cause, retry R20, reopen R19 or create another machine.
Any subsequent real run needs a new identifier and explicit approval. This
partial Linode/single-member/IPv4/HY2/default-A run does not authorize production
automation or establish DO/Vultr, dual-stack, multi-line or long-running readiness.
