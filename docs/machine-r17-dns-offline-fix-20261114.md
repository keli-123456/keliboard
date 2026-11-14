# R17 DNS initialization: offline compatibility and diagnostics

## Scope and historical evidence

This is a local-only follow-up to `keli-accept-20261114-r17-d22e2906`, based on
`d22e290698e337c6d4eb9f7a5008a0526fca4f10`. R17 is closed, not passed, and must
not be rerun, repaired in place or relabeled by these changes.

The preserved R17 aggregates establish that source registration succeeded, an
edge A creation returned a direct record ID, and `initial-dns` subsequently
failed with `DnsException / provider_rejected`. They do **not** preserve the
failing API action, HTTP status or provider code. The historical initialization
failure therefore cannot be assigned a unique root cause offline. No candidate,
forwarding acceptance or DNS cutover was completed.

The cleanup mismatch is independently established: a successful exact-ID Aliyun
response omitted the empty `Remark`, while the local matcher required the key.
R17's separately recorded guarded reconciliation already removed that record
and independently confirmed absence twice. This patch does not repeat that
delete or rewrite the original `cleanup_incomplete_needs_review` result.

## Changes

1. Aliyun slot markers no longer send the internal 57-character hexadecimal
   identity as a record remark. The wire form is `keli-set-b64-` plus 32 URL-safe
   Base64 characters, exactly 45 characters. All 192 identity bits are retained;
   no truncation, hashing shortcut, database migration or slot reassignment is
   used. Inventory and cleanup translate it back to the same internal identity;
   existing hexadecimal remarks remain readable without mutation.
2. If a native list response omits `Remark`, the client reads that exact record
   ID and compares ID, domain, RR, type, value, TTL, line, status and lock state
   before using its remark. Only omitted remarks on successful exact-ID reads
   become `''`. Explicit null, non-string and oversized remarks fail closed.
3. The pinned cleanup reader applies the same representation to both list and
   exact-ID reads. It keeps omitted list remarks absent to retain the caller's
   exact-ID fallback. A blank remark is not a missing record and grants no delete
   authority: the original full journal tuple, direct creation receipt and
   explicit partial-create blank opt-in are still required. Foreign remarks,
   inconsistent inventories and unknown outcomes remain blocked.
   The embedded reader is self-contained and does not require deployment of a
   new application method on the existing credential bridge; a separate PHP
   process with only the old signing-helper interface verifies this constraint.
4. Aliyun failures retain only fixed-enumeration operation/code and HTTP status.
   Unknown values become `other`; transport failure has no invented status.
   No provider message, request ID, response body, credentials, signing material,
   hostname, record ID or stack is copied into the diagnostic.
5. `initial-dns` can use the existing safe CLI projection and exclusive local
   first-error readback, with its actual phase and provider diagnostic preserved.
   Later or different errors cannot overwrite the first. A future fresh adapter
   must route both `adopt-source` and `initial-dns` through this formatter; the
   runtime binding check rejects the former source-only branch. Closed R17's
   adapter and result files are not changed.

## Compatibility rationale

The current [record remark API documentation](https://help.aliyun.com/zh/dns/api-alidns-2015-01-09-updatedomainrecordremark)
describes the remark as optional and its empty default. The
[record-management import documentation](https://www.alibabacloud.com/help/en/dns/pubz-manage-resolution-records)
describes a 50-character remark limit for imports. The 45-character lossless
encoding avoids that interoperability boundary. The API page does not establish
that R17's specific RPC was rejected for length: the 50-character rejecting
provider in this regression is an explicit **synthetic fixture**, not recovered
R17 evidence. Public documentation was read without account access; no live
provider API or SSH connection was made in this follow-up.

## Local verification

- Focused provider/cleanup/CLI tests: **114 tests, 449 assertions**, no failures.
- Full PHP Unit suite: **4,012 tests, 31,913 assertions**, 3 skips, no failures or
  errors. Runtime: Windows PHP 8.2.33; minimal test application, in-memory test
  database/cache and faked provider HTTP, not the production application.
- Canonical Python acceptance suite: **409 tests**, 33 environment skips, no
  failures or errors. Includes actual local PHP-to-Python projection/allowlist
  parity and independent local first-error readback.
- Cases cover create/claim/readback under the synthetic remark limit; missing,
  empty, malformed, legacy, compact and foreign remarks; identity/state changes;
  HTTP/RPC refusal and redaction; preserving a direct create receipt without
  repeating creation; unconfirmed timeout; no fabricated absence; first-error
  protection; and rejecting the source-only CLI binding.
- All **117** frozen R17 input hashes still match. Original offline/final/closed
  records and the supplemental reconciliation/result safety hashes are unchanged.
  The pre-existing unrelated acceptance-plan modification is also unchanged.

Windows skips are not Linux acceptance. No live Aliyun initialization, candidate
creation, HY2 forwarding or DNS propagation has been retested. A future live run
needs its own approved scope, a newly committed/frozen package and a new run ID.

## Unchanged boundaries

No deployment, machine creation, R17 rerun, GitHub build, image/node release,
production SSH/database/provider access, DNS write, queue/service change or
automatic-switch change was performed. No tanzhen access was made. Automatic
creation, rotation and DNS switching are not enabled by this patch. These changes
are not committed or pushed by this local-fix task.

Machine-readable local aggregate:
[`r17-dns-offline-fix-20261114.json`](evidence/r17-dns-offline-fix-20261114.json).
