# R21 Result: Candidate Installed, IPv4 Reachability Blocked

Run: `keli-accept-20261115-r21-5b4e6ddd`.
Approved scope: [R21 isolated acceptance](machine-r21-isolated-acceptance-scope.md).
Structured result: [R21 evidence](evidence/linode-r21-result-20261115.json).

**Verdict: `blocked_not_passed`. Candidate creation, installation and narrow
readback passed; HY2 and DNS cutover did not reach their execution gates.
Cleanup passed independently.**

The resource window was 2026-11-15 11:33:45--11:52:55 Beijing time. The driver
reported 1,165 seconds including its preceding checks. R21 ran once and is now
closed. R20 was not resumed, patched or reclassified. The original proposal
remains a proposal snapshot; the subsequent explicit approval is retained in
R21's frozen approved-scope record, not copied from an old authorization.

## Verified Progress

- Application and tool source: `5b4e6ddd282b125c852ca550e0168f6015af86e6`.
- New-round offline regression: 549 tests, zero failures/errors, 18 conditional
  Linux/POSIX skips. The first run's two test-adaptation failures were retained
  before correction: selection of the new narrow branch as if it were the full
  observer/advance branch, and a PHP fixture path still using the repo layout.
- Fresh LF application/vendor/wrapper freeze: all 128 inputs still match after
  execution. Thirteen shell files, PHP lint, archive/binary integrity and cloud-init
  sizes passed without raising any existing input or initialization limit.
  Three preparation-only programs were consolidated into one pinned program.
- The node binary remained `v0.1.387`, SHA256
  `654a8344d22bc3373b114345d49d5f6a2804be98e6f51bc2ce85e309fbda0be0`.
  The fresh R21 wrapper SHA256 was
  `9c9639f07c7618582391d43e416acdce7cd793ab35d90ba31b3633c2987a4315`.
  This was not a node rebuild, GitHub build, image publication or formal release.
- Both pre-entry and immediately-before-create read-only preflights passed with
  zero provider mutations and zero DNS writes during those preflights.
- The control machine passed all ten pipeline stages. All 13 active runtime
  files and seven application protection files were verified at their real paths.
- The actual non-root service user loaded the candidate helper and diagnostic
  classes. The PHP-to-Python source and immutable task first-error roundtrips,
  private-location redaction, independent readback and synthetic-sandbox cleanup
  passed before source creation. No real task, source adoption or real first-error
  journal was fabricated for that smoke test.
- The source installed once and supplied three fresh, identity-bound reports.
  Its source window contained three current failed samples over 15.575 seconds;
  source registration passed with the continuous-failure threshold preserved.
- The initial default edge A record pointed to the source, with ownership and
  provider readback verified. Public-resolver priming was for this source, not
  candidate propagation or candidate cutover.
- A single native candidate was created and installed successfully, then supplied
  three fresh identity-bound reports. The final narrow readback consumed its
  authenticated callback and proved `candidate_process_only=true`,
  `native_phase_advanced=false` and `dns_cutover_dispatched=false`.
  The installed state was `waiting_ipv4_probe`, not an installation exception.

## Reachability Stop

The candidate sampling gate rejected `candidate_no_reachable_target` at
`candidate_sampling_window`. The diagnostic check was complete: each of the four
fixed domestic IPv4 targets received one outbound ICMP probe, with four packets
sent, zero echo replies, zero reported errors and four `no_echo_by_deadline`
classifications. The source's separate bounded root diagnostic had the same
counts. Agent context remained stable; no probe packet budget was exceeded.

The candidate had three fresh identity reports, but **not three healthy probe
samples**. Its health window refused the first inadmissible observation before
appending a qualifying sample. Do not describe the 0.271-second refusal as three
fresh failed probe rounds or a full 60-second measurement.

This run verifies that the repaired candidate path can progress through provider
readback, callback and installation instead of R20's observed `internal_failure`.
It does not recover R20's missing historical stack or uniquely establish every
cause of that older failure.

There was no real task exception in R21 and no real per-task first-error file.
The final task-evidence audit passed with no required failure record; the live
synthetic projection/readback and offline negative fixtures must not be counted
as a real MySQL exception or real candidate-error persistence test.

The natural-failure source branch was used. No ICMP fault was injected. Candidate
HY2 TCP/UDP, native completion, DNS cutover, post-cutover TTL convergence and actual
post-DNS forwarding were not executed. No second candidate, alternate credential,
create retry, target substitution or weakened reachability gate was used.

Zero ICMP replies do not distinguish target ICMP policy, routing/loss, filtering
or censorship. Both diagnostic records retain `root_cause_confirmed=false` and
`censorship_confirmed=false`; no user attribution or confirmed IP blocking is claimed.

## Cleanup And Production

All three new cloud resources were deleted, control last. All three actual DNS
record IDs were confirmed absent; the certificate TXT had already been removed.
Both independent passes checked exact cloud/DNS IDs, complete scoped inventory,
the three exact DNS names and scoped volumes, rather than trusting DELETE
acknowledgements or failed SSH.

| Beijing time | Independent readback |
| --- | --- |
| 11:52:21--11:52:33 | Three cloud IDs absent, scoped cloud/volume inventory empty; three DNS IDs and exact names absent. |
| 11:52:41--11:52:53 | The same cloud, DNS and volume absence checks passed again. |

The gap from the first pass finishing to the second starting was 8.003450 seconds.
Private cleanup passed all six stages: services, certificate, revocation,
containers, local clients and private files. Temporary credentials, tokens,
profiles, certificate private material and isolated containers/volumes were
removed. Original credentials and SSH identities were not revoked or rotated.
No final billing audit or zero-charge claim is made.

Final read-only production checks passed: all 13 queue classes present, zero
missing queues, zero paused masters, unchanged container identities and Nginx
configuration, and automatic gates still closed. No formal deployment, production
database write, business-node restart, queue/daemon or default-version change,
image publication or tanzhen access occurred.

All 128 R21 frozen inputs, older maps of 801 frozen inputs and 44 closed-result
files, including R20, still match. The pre-existing user acceptance-plan edit was
left untouched. Finalization's nonpassing status represents the blocked acceptance
verdict, not failed cleanup.

## Next Boundary

Investigate the four targets' ICMP availability and the relevant routing/filtering
evidence under a separately agreed read-only scope before planning another live
round. Do not assume a code defect, replace targets to obtain a green result,
relax candidate admission, resume R21, or enable production automation from this
partial result. HY2 and DNS cutover still require their own successful real gates.
