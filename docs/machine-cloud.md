# Linode Cloud Resources

This is a panel-side integration. It does not change kelinode-rs, its report
frequency, the SSH transport, subscriptions, or the proxy data path.

## Setup

Run the included database migrations using the project's normal update workflow.
The admin entry is **Server Machines > More > Cloud Resources**. Add a Linode /
Akamai account, read its existing inventory, then bind a resource to a machine and
the full 32-character installation ID. A fresh explicit public address must match
the provider inventory. An HTTP peer address alone is not accepted.

Use a dedicated, expiring personal access token with the smallest resource grants
possible. Inventory requires `linodes:read_only`; power needs
`linodes:read_write`. Event and maintenance checks also require access to the
corresponding account/events endpoints (`events:read_only` and account read
permissions as applicable). Verify those reads before authorizing power actions.
Read/write Linode scope can authorize more than power at the provider: this
integration itself only implements boot, shutdown and reboot. Do not use an
unrestricted owner token where a restricted operator is sufficient.

Tokens use Laravel encrypted attributes and the existing application key. They
are never returned by the API. Editing with an empty token retains the stored
value. Editing an account disarms all its recovery policies. Account deletion
only removes local cached metadata and is refused while bound or busy.

## Power Tasks

- Every manual operation requires explicit confirmation and an exact resource
  revision. A durable request ID prevents duplicate acceptance after a browser
  timeout. Resource IDs are additionally pinned to their provider creation time.
- Jobs run on the existing `redis:admin_operations` queue. The minute scheduler
  dispatches at most 25 due tasks and 25 due recovery checks. It performs no cloud
  HTTP work itself. Reports do not trigger cloud requests.
- Account/resource locks serialize local operations. Before POST, the worker
  revalidates permissions, identity, cloud state, maintenance, and unfinished
  events. It commits a dispatch marker before sending exactly one power POST.
- Network failures after POST and workers lost across that marker enter read-only
  reconciliation. No POST retry is performed. A reboot needs a new completed
  provider reboot event, not just a `running` status. Queued tasks alone can be
  cancelled. The reconciliation window is 15 minutes; expiry does not undo a
  provider operation that may already have happened.
- Provider pagination is bounded at three pages for safety data. Unknown or
  incomplete safety data blocks power. Events are scoped to the cloud resource,
  including unfinished events outside the recent baseline window. Normalized
  error codes are stored; provider messages, account usernames, and raw bodies
  are not stored or exposed.
- Inventory is read one page at a time, up to 100 records/page; the local limits
  are 50 accounts and 500 resources. Inventory refresh never deletes a cloud VM.
  Snapshots show their observation timestamp and are not a push feed.

## Automatic Boot

Off by default, enabled per explicitly bound resource by an administrator:

1. Establish a running-cloud and fresh same-installation Agent baseline.
2. Require two cloud `offline` samples at least 55 seconds apart. A running VM
   with stale Agent reports is not rebooted. Baselines expire after 10 minutes.
3. Refuse maintenance, unfinished provider events, explicit shutdown events,
   identity changes, revoked permissions, manual holds, or concurrent tasks.
4. Allow at most two automatic attempts in a rolling 24 hours, at least one hour
   apart. Failed attempts count. Consume the armed state when accepting a task;
   a new healthy report is required before it can arm again.

A manual shutdown keeps auto boot paused, even after the cloud confirms offline.
A new explicit policy authorization is required to resume it. Linode's native
watchdog is not modified. External API actions cannot be atomically locked by
the panel; the final preflight reduces, but cannot eliminate, external races.

## Recovery and DNS

Cloud power and report recovery are separate. Bound boot/reboot tasks wait for
fresh reports strictly after dispatch from the same installation, including each
enabled bound node's status report. This does **not** establish that a client's
TCP/UDP forwarding works. Unbound resources have cloud-only completion.

Power intent puts only the matching machine/installation's DNS bindings on hold.
The DNS sync job performs no provider write while that hold is present. It is
released after matching post-dispatch reports or an explicit read-only recovery
verification. Failure, cancellation and timeout do not silently clear a hold.
Already in-flight external DNS updates cannot be cancelled; this is a gate on
subsequent sync jobs, not an atomic transaction across two providers.

## Verification Scope

Local tests use SQLite, mocked official API responses, and synthetic browser
fixtures. No real token, production VM power action, or provider billing was
tested in this implementation task. Before live use, first authorize an inventory
read, verify resource identity and permissions, then separately approve a power
test on a non-business instance. Automatic recovery should remain disabled until
that live test is complete.

Primary references:
- [Linode API authentication/scopes](https://techdocs.akamai.com/linode-api/reference/get-started)
- [List Linodes](https://techdocs.akamai.com/linode-api/reference/get-linode-instances)
- [List events](https://techdocs.akamai.com/linode-api/reference/get-events)
- [List maintenance](https://techdocs.akamai.com/linode-api/reference/get-maintenance)
- [Filtering and sorting](https://techdocs.akamai.com/linode-api/reference/filtering-and-sorting)
