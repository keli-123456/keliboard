# Instance DNS Management

This first phase manages existing A and AAAA records through Cloudflare and
Aliyun DNS. It does not provision servers, create/delete DNS records, restart
nodes, change subscriptions or alter the ICMP probe policy.

## Deployment and Defaults

Run normal database migrations before opening Server Management > DNS.
All newly created bindings are disabled. Creating a binding performs only a
provider read to validate the record name/type and establish its baseline.
Enabling automation and manual synchronization require explicit confirmation.

Credentials use Laravel encrypted casts and the existing application key.
Back up that key securely. No API exposes saved credential contents; blank
fields retain the stored secret during rotation. Existing request-audit
redaction covers api_token, access_key_id and access_key_secret.

## Identity and Address Selection

Each record is bound to one machine ID and one full 32-character installation
ID. A machine token hash pins its authority. Rotating that token pauses the
binding. No source is selected by a short instance label or by matching an IP.

Only explicit public addresses in an identified instance's existing report
are eligible. HTTP peer addresses, private addresses, another instance's IPs,
and retained history without a matching current address are not eligible.
Reports older than 180 seconds or from the future are rejected for writes.
IPv4 and IPv6 records are bound independently; IPv6 is not added to ICMP probes.
Installation IDs are self-reported identities within an authenticated machine,
not cryptographic host attestation. Shared machine tokens share this trust
boundary. Use least-privilege DNS credentials and dedicated node subdomains.

## Execution and Conflicts

`machines:dns-sync` runs once per minute, queues at most 50 enabled due bindings
on the existing redis/admin_operations queue, and does no provider I/O in the
Agent report path. Jobs contain only a binding ID and configuration revision.
Unique jobs and credential/binding locks serialize work. Old revisions and
deleted bindings are ignored. Configuration edits acquire the same locks.

Unchanged addresses skip provider calls for up to 15 minutes. Changed addresses
are read, compared to the baseline, updated and read back before success.
Provider failures and missing reports back off for five minutes. Identity,
record name/type, locked-record and external-edit conflicts disable automation.
Rebind a record explicitly to accept a new external baseline.
This is a before-write conflict check, not a provider-side compare-and-swap;
uncoordinated external writers can still race between the read and update.

Cloudflare changes only `content`. Aliyun preserves TTL and line. Neither
adapter changes proxy state or deletes sibling records. API verification does
not establish public resolver propagation or client connectivity. A timed-out
write may have reached the provider; subsequent reads reconcile it without
blindly replaying writes. Deleting a local binding leaves the remote record.

Activity contains identifiers, action codes and before/after IPs, never provider
bodies or credential material. The UI displays the latest 100 events; events
older than 30 days are pruned in bounded batches by the scheduler.

## Provider References

- Cloudflare record read/edit: https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/edit/
- Aliyun record inspection: https://www.alibabacloud.com/help/en/dns/api-alidns-2015-01-09-describedomainrecordinfo
- Aliyun record update: https://www.alibabacloud.com/help/en/dns/api-alidns-2015-01-09-updatedomainrecord

No live provider credentials are used by the unit/browser tests. Test requests
are intercepted and restricted to synthetic records.
