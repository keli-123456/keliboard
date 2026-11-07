# Cloud Resources

This is a panel-side integration. It does not change kelinode-rs, its report
frequency, the SSH transport, subscriptions, or the proxy data path.

## Setup

Run the included database migrations using the project's normal update workflow.
The admin entry is **Server Machines > More > Cloud Resources**. Add a Linode /
Akamai, DigitalOcean, or Vultr account, read its existing inventory, then bind a resource to a machine and
the full 32-character installation ID. A fresh explicit public address must match
the provider inventory. An HTTP peer address alone is not accepted.

Use a dedicated, expiring personal access token with the smallest resource grants
possible. Inventory requires `linodes:read_only`; power needs
`linodes:read_write`. Event and maintenance checks also require access to the
corresponding account/events endpoints (`events:read_only` and account read
permissions as applicable). Verify those reads before authorizing power actions.
Read/write Linode scope can authorize more than power at the provider: this
production power integration only implements boot, shutdown and reboot. Do not use an
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
fresh reports strictly after dispatch from the same installation. Each enabled
bound node needs an authenticated resource-status, online, or traffic report.
An online report with zero users is still a valid reporting signal. Evidence
uses panel receive timestamps within the existing node-report expiry, capped at
180 seconds; fields are validated independently so a malformed or future field
cannot replace valid evidence. Generic heartbeats/config polls, old reports,
another machine, and unidentified/other installations do not establish recovery.
This does **not** establish that a client's TCP/UDP forwarding works. Unbound
resources have cloud-only completion.

Power intent puts only the matching machine/installation's DNS bindings on hold.
The DNS sync job performs no provider write while that hold is present. It is
released after matching post-dispatch reports or an explicit read-only recovery
verification. Failure, cancellation and timeout do not silently clear a hold.
Already in-flight external DNS updates cannot be cancelled; this is a gate on
subsequent sync jobs, not an atomic transaction across two providers.

## Verification Scope

Local tests use SQLite, mocked official API responses, and synthetic browser
fixtures. A separate authorized inventory-only live check verified reading
Linode instances and comparing existing Agent identities. No production VM
power action or provider billing was tested. Before live power use, verify
resource identity and permissions, then separately approve a power test on a
non-business instance. Automatic recovery should remain disabled until that
live test is complete.

Primary references:
- [Linode API authentication/scopes](https://techdocs.akamai.com/linode-api/reference/get-started)
- [List Linodes](https://techdocs.akamai.com/linode-api/reference/get-linode-instances)
- [List events](https://techdocs.akamai.com/linode-api/reference/get-events)
- [List maintenance](https://techdocs.akamai.com/linode-api/reference/get-maintenance)
- [Filtering and sorting](https://techdocs.akamai.com/linode-api/reference/filtering-and-sorting)

## DigitalOcean and Vultr

### Credential Groups

Cloud API credentials can be organized into custom groups across providers. The
group selector filters the account, instance and power-task views locally; it
does not contact providers. New credentials default to the selected group.
Existing credentials can be reassigned from the account list without reopening
or resubmitting their token. A name has at most 60 characters, normalized names
are unique, and there are at most 100 groups. Existing accounts migrate to
Ungrouped. Group controls stay hidden against a backend without the grouping
migration; the other cloud features remain available.

Group assignment uses its own `group_revision`, not the account's security
revision. Rename, assignment and deletion never rotate credentials, invalidate
queued/running power tasks, change bindings, or disarm recovery policies.
Deleting a group moves its current members to Ungrouped in one local transaction;
no account or VM is deleted. Group and account locks serialize creation/movement
against group deletion, and stale revisions are rejected. This is an admin-only
organization feature, not a credential-access permission boundary. Notification
history retains its existing resource scope rather than being silently filtered.

### Provider Support

Select the provider when adding an account. An existing account's provider cannot
be changed; add another account instead. Stored credentials remain encrypted and
blank edits retain the current token. Provider identity is pinned on inventory
and power tasks. Resource uniqueness is `(provider, external_id)`, so equal
numeric Linode and Droplet IDs cannot collide. The migration preserves existing
Linode rows, accommodates Vultr UUIDs, and refuses a lossy downgrade while
non-Linode records remain.

DigitalOcean inventory/action reads need `droplet:read`; manual power also needs
`droplet:update`. Requests go only to `https://api.digitalocean.com/v2`. Boot uses
`power_on`, shutdown uses graceful `shutdown`, and reboot uses `reboot`, without a
hard-power fallback. Locked Droplets, incomplete or unknown action records block
power. Action history is scoped to the Droplet and bounded at three 100-row
pages; longer histories require review rather than silently dropping safety
evidence. Reboot completion needs a new completed reboot action after dispatch,
not merely an `active` Droplet.

Vultr uses a separate API key; restrict its API access to the panel's egress IP
where possible. Requests go only to `https://api.vultr.com/v2`. The integration
normalizes `power_status`, `server_status`, public IPv4 and the main IPv6 address;
passwords, KVM URLs and user data are never exported. Cursor traversal is bounded
at ten pages and forwards only an opaque query value to the fixed official
endpoint, never a provider-supplied URL. Boot/halt/reboot use the single-instance
endpoints, never mass operations. Halt is a forced stop and requires an explicit
data-loss warning. There is no claim of a Linode-style event audit for Vultr:
reboot confirmation requires an observed powered-off transition after dispatch
followed by running. Polls occur every five seconds while awaiting that result.
A fast reboot whose transition is missed stays unconfirmed and expires; it is
not resent or labelled successful just because the API returned 204.

Both new providers support inventory, exact-installation binding, explicit manual
power tasks, existing task notifications and post-operation report checks. Auto
boot is intentionally unavailable for them until equivalent maintenance/shutdown
evidence is separately implemented and accepted. Replacement provisioning is
still disabled and its first isolated foundation remains Linode-only. This phase
does not create, delete, rebuild or resize VMs, change DNS, or enable automation.
Local verification mocks provider requests; real account permissions and power
behavior still require a separately authorized non-business test.

References:
- [DigitalOcean Droplet actions](https://docs.digitalocean.com/reference/api/reference/droplet-actions/)
- [Vultr official instance API client](https://github.com/vultr/govultr/blob/master/instance.go)

## Independent Replacement Provisioning Foundation (Disabled)

This is the first local foundation for independent replacement failover, not a
production V1/V2 takeover. A read-only review of the deployed tanzhen configuration
on 2026-11-05 confirmed two V2 services and three selected provider-template
members: two in `sg-sin-2`, one in `jp-tyo-3`, all using `g6-standard-4` and
`linode/debian12`. Their template user data is empty; tanzhen installs its own
Agent before running the machine connection scripts. Both reviewed scripts enable
dependencies, BBR, system tuning and egress protection. The fourth member was
explicitly left out of this review. No credentials or script bodies were imported.

The new foundation uses cloud-init to run the shared kelinode-rs installer
directly. It preserves those four installation options and binds the new VM to
the explicitly selected logical machine/node group, while preseeding a fresh
`/etc/kelinode/report-instance-id` for each VM. The initial independent rollout
uses a dedicated machine/node group and DNS records, not the groups or records
managed by the old controller. It does not require the tanzhen Agent. Arbitrary
user data and shell scripts are not accepted.

The chosen route is a new independent system running alongside tanzhen, not a
migration of its current services. Stopping, modifying or querying that old
controller is not a prerequisite for an isolated new policy. An independent
plan needs no `source_ref`. If one is explicitly supplied, it remains provenance
for preview, not an imported script or permission to take over legacy resources;
that separate takeover path remains blocked until reviewed. Parallel operation
does not itself prove isolation: resource identities and DNS-record ownership
must be separate, and shared cloud-account quotas, rate limits and costs still
need to be considered.

- `config/machine_provision.php` has a hard-off live gate and an empty isolated
  machine allowlist. There is no browser/environment enable switch and no
  automatic scheduling hook. Existing auto boot and DNS policies are not changed.
  Preview is local only and never exports the generated script or credentials.
- Once a separate isolated test is authorized, the design requires an active
  admin, an allowlisted test machine, the configured HTTPS panel origin, an
  explicit monthly price ceiling and the reviewed image/size combination.
  Official preflight reads check Metadata/cloud-init support, regional pricing,
  and account-wide backups. Forced paid backups are refused. The cap is two
  accepted attempts per account per rolling 24 hours, including cancellations
  and failures; it is not a total billing or bandwidth-cost guarantee.
- Task payloads (including user data, callback secret and random root password)
  are encrypted with the application key. Bootstrap files are root-owned 0600;
  installer output stays in a private log. API history contains only a bounded
  summary, normalized state/reason codes and aggregate evidence. As with any
  cloud-init deployment, the cloud provider and root on the VM can access its
  initialization secrets; this does not provide isolation from those principals.
  The installer is limited to 600 seconds, followed by a 15-second forced-stop
  grace period, matching the reviewed script timeout. The native Agent process
  itself is not placed under that installer timeout.
- Account and slot locks plus a durable request ID prevent duplicate local
  acceptance. A unique provider label/tag and the dispatch marker are persisted
  before exactly one create POST. Timeout, 5xx, malformed success, or a lost
  worker lead only to filtered read-only reconciliation, never another create
  POST. Ambiguous/duplicate candidates require review. Provider pagination is
  bounded. Cancellation or expiry never deletes, shuts down or rebuilds a VM.
- Initialization receipts need a task-specific bearer secret, the exact new
  installation ID and the post-dispatch deadline. They are idempotent and cannot
  change an already accepted result. Bounded retries apply only to the callback,
  not to VM creation. Permissions, account revision, machine authority, panel
  origin and task expiry are revalidated before dispatch, including after slow
  preflight reads.
- A successful receipt alone is not readiness. The worker requires fresh exact
  installation Agent and enabled-node reports after dispatch, matching public
  cloud addresses, and a fresh source-attributed IPv4 probe with at least one
  configured target reachable. IPv6 probing is not required. These checks only
  reach `awaiting_acceptance`, with `forwarding_verified=false`.

This provisioning foundation alone does not perform DNS cutover, cloud-resource
adoption, old-resource cleanup or client forwarding acceptance. The separate
acceptance APIs below add guarded ownership and transition metadata; they do not
enable the replacement-policy engine or constitute a live billing/client test.
There is no production creation button. tanzhen keeps
managing its existing services unchanged. Local tests mock all provider mutations
and run only inert bootstrap fixtures in temporary directories.

### Independent Policy Drafts and Rehearsal

The local admin entry is **Cloud Resources > Failover** after applying
`2026_11_06_030000_create_machine_failover_policies`. Older backends without the
capability do not show the tab. This increment stores draft policies only; it has
no execution/enable endpoint, no execution scheduler and no connection to the provisioning
queue. Even enabling the separate isolated-provisioning test config cannot turn
a policy rehearsal into a live action.

Each revisioned draft owns a server-generated slot and records an independent
machine/node group, cloud account, region/size/image, failure threshold,
report-expiry interval, cooldown, monthly price ceiling, daily attempt limit and
an optional DNS credential/hostname intention. Current creation-template drafts
are Linode-only. DigitalOcean and Vultr inventory/power support does not imply
replacement creation support. Empty DNS and zero budget may be saved as incomplete
drafts, but rehearsal marks them blocked. No tokens, root passwords, generated
scripts or private SSH material are returned.

Saving a new draft refuses a machine with known cloud-resource bindings, DNS
bindings or retained Agent/node reports. An existing policy may edit its own
registered group only while exact ownership remains valid. This is a local collision check, not proof of
external ownership. The isolation checkbox is an administrator declaration, not
an inspection of tanzhen. No old-controller contact or shutdown is required.
Configured hostnames are normalized and reserved against other policy drafts
and ordinary A/AAAA bindings; a shared hostname lock protects both writer paths,
including different DNS credentials. Referenced cloud/DNS credentials cannot be
deleted while a draft uses them. Their revision changes invalidate rehearsal
checks until the draft is reviewed and saved again. Group-only metadata moves do
not change the account security revision.

Current-snapshot rehearsal reads local metadata and cached reports only. A probe
is considered only alongside its same fresh installation; an older installation's
successful probe cannot be combined with a different fresh Agent. Any reachable
target is green, while incomplete/unknown samples stay unknown. Probe failure is
an outbound ICMP observation, not independently verified TCP/UDP failure or proof
of filtering. The separate history collector is disabled by default; without
matching collected evidence, an observed failure count is not invented. Initial-setup, stale-Agent and unreachable-IPv4
scenarios are explicitly hypothetical and do not write telemetry or counters.

Every result retains `executed=false`, `execution_enabled=false`, and unverified
provider/pricing/external-ownership/forwarding evidence. Planned creation,
kelinode-rs installation, report checks, forwarding acceptance and independent
DNS switching are shown as a plan only. Reading or rehearsing never calls a
provider or sends a job. Deleting an unused draft only deletes that local metadata;
existing machines, node groups, credentials, cloud resources and DNS records are
left unchanged.

### Observation-Only Fault Evaluation

`2026_11_06_040000_create_machine_failover_observations` adds a bounded observation
journal and blocked decision plans. `config/machine_failover.php` keeps
`observe_enabled=false`; the five-second scheduler is not registered while off,
and `php artisan machines:observe-failover` is a no-op while off. This gate cannot
enable provisioning, power actions, DNS or notifications. No node-side change or
extra network probe is introduced. Deployment and observation authorization are
separate from implementing this local feature.
Cache/database read failures are errors, not empty healthy samples; they do not
reset counters or supersede a plan. Existing address presentation retains its
separate best-effort fallback.

When explicitly enabled later, the collector samples the panel's existing cache.
It is not a lossless archive of every five-second node probe. Only a single fresh,
identified installation with attributable public IPv4 evidence can establish a
failure streak. The policy revision, probe-target revision, installation and IPv4
set form the context. Changing any of these requires a new healthy baseline.
Uninitialized groups, stale Agents, ambiguous instances, future timestamps,
incomplete targets and missing/foreign IPv4 attribution remain unknown and cannot
create a plan. IPv6 is never a failure criterion.

One reachable configured target immediately resets the counter, even if another
target errors. A failure requires complete failed target sets for every known
public IPv4 source. Unattributed older reports are not upgraded into evidence.
Only distinct rounds separated by the configured interval increment the counter;
repeated, too-fast or out-of-order rounds are ignored. Gaps exceeding twice the
probe interval plus ten seconds and unknown samples break the streak. A prior
healthy baseline in the same context is required before a threshold can match;
first reports that are all failed do not imply an outage or authorize creation.

Each matched episode produces a blocked, immutable plan snapshot. It records
local policy checks, rolling 24-hour creation attempts across the entire cloud
account, account-wide creation cooldown and unresolved creation/power tasks.
Missing budget history stays unknown, not zero. Plans are not paid attempts or
budget reservations. Guard changes supersede the old snapshot and record a new
one; unchanged samples do not repeatedly create plans. Recovery, lost evidence,
context changes and policy revisions supersede prior active plans without
modifying any actual resource. Pricing, external ownership and forwarding remain
unverified, and every plan retains `execution_enabled=false`, `executed=false`
and `requires_new_authorization=true`. These records have no worker or execution
endpoint and cannot be passed to provisioning as authorization.

`GET /server/machine/cloud/failover/history?id=...&expected_revision=...` is
admin-only and reads the latest 50 observations and 20 plans. Reading, refreshing
or rehearsing never collects a new sample. Current rehearsal shows a real counter
only when the retained sample still exactly matches the live context, timestamp
and verdict; otherwise it stays unknown. Simulated scenarios never display
collected counts. The default retention is at most 500 observations and 100 plans
per policy or seven days, with bounded cleanup. Deleted-policy audit is retained
for seven days and then pruned by the enabled collector. While the collector is
off, it also performs no retention writes.

Observing a healthy baseline is not cloud-resource adoption. Existing unregistered
resources/reports remain blocked for takeover. The separate ownership and
acceptance APIs below require exact new-task provenance; they do not convert an
observed threshold into execution authority. Neither an ICMP threshold nor this journal is
independent proof of censorship or TCP/UDP forwarding failure.

### Independent Instance Acceptance and Owned DNS (Disabled)

`2026_11_06_050000_create_machine_acceptance_tables` adds four local journals:
owned installations, bounded acceptance checks, owned DNS reservations and DNS
transition intents. Rollback drops only these new tables. No scheduler invokes
acceptance or cutover. `config/machine_acceptance.php` defaults to
`allow_verification=false`, `allow_dns=false`, an empty policy allowlist and no
verifier URL, key or profiles. These are separate gates from observation and
provisioning. There is no browser switch for enabling them.

Enrollment records only a candidate, never a successful verdict. It requires an
active administrator, an exact policy revision, and a successful independent
provisioning task already at `awaiting_acceptance`. Task account, machine, slot,
region, size, image, origin, machine authority, installation and random creation
marker must match. Imported `source_ref` plans cannot be enrolled. The selected
public IPv4 must belong to that task. A release ID pins the active Linux x86-64
kelinode-rs version **and binary SHA256**; a matching version string is not enough.
The release pin cannot be silently changed. An independently configured installer
default that installs another binary will fail acceptance, not repin the record.

Unknown fresh installations, foreign node reporters, shared node groups, ordinary
DNS bindings and unrelated cloud bindings block ownership. A successful check
reads back the provider creation marker, external ID, creation time and selected
IP before adopting a resource. The cloud row remains unarmed, with automatic boot
off and manual/DNS holds on. Legacy binding changes and enabling a second
automatic controller are refused for registered resources. Policy metadata such
as name, budget and cooldown remains editable, but registered machine/account,
template and DNS identity fields are locked. A policy with registered instances
cannot be deleted. Any policy/security revision change invalidates prior proof.

Fresh exact-installation Agent and node reports, source-attributed reachable IPv4,
binary digest, process observation session and configured node IDs/protocols/ports
are required. Those runtime fields already exist in kelinode-rs reports; the
panel retains only their bounded identity projection, without adding Agent probes
or modifying the node program. The configuration fingerprint hashes the expected
panel node configuration. It is **not** a node-attested full applied-configuration
hash. Matching runtime projection plus external forwarding tests is required;
none of the individual signals alone proves complete configuration application.

Forwarding uses a separately configured, trusted HTTPS worker with a static
policy-to-isolated-profile mapping. The panel does not accept `passed=true`,
user-posted counters, arbitrary worker URLs or test credentials from this API.
Requests and raw response bodies have direction-separated HMAC-SHA256 signatures,
a single-use persisted challenge, a request digest and a 45-second deadline. The
worker must authenticate each expected node endpoint, send three distinct random
32-byte frames through **both TCP and UDP**, return all 96 bytes exactly once per
transport with matching digest, and confirm cleanup. Missing/duplicate frames,
wrong nodes, stale/replayed/unsigned receipts and incomplete cleanup all fail.
HTTP has a 30-second timeout, no redirects/retries, and a 32-KiB response limit.
Only aggregate counts, context and receipt digests are retained. Proof lasts at
most 120 seconds and is checked again around provider calls and before commit.

The [isolated worker](../tools/machine-acceptance/README.md) now invokes a pinned
sing-box client without introducing panel dependencies or node changes. Actual
HY2 loopback fixtures cover TCP/UDP, invalid credentials/TLS identity, UDP
missing/duplicate data and private client cleanup; panel/provider mutations
remain simulated. Production deployment, Linux resource-boundary verification,
isolated profile authorization, fixture expiry/revocation and WAN acceptance
remain prerequisites for a separately authorized live test. An absent worker/profile
fails closed as `verifier_not_configured`. See
[the worker contract](machine-acceptance-verifier.md).

The first owned-DNS adapter supports only a **new, independent Cloudflare A
record**, TTL 60, unproxied, with an exact record ID and a private random ownership
comment. Existing records of any type at that hostname are not adopted. Aliyun
owned transitions are not implemented; its ordinary address-sync support is not
cutover support. Owned hostname reservations also block ordinary DNS writers and
credential deletion. No DNS deletion or VM shutdown/deletion is implemented here.

A separate prepare request records the old/new installation, address, revisions,
request identity and a 120-second write deadline, without changing DNS. Execute
revalidates ownership, fresh forwarding proof, permissions, credential revision,
the complete record set and the exact source address. It persists the dispatch
boundary **before** one create/PATCH. A read-back must prove the target address
and ownership before activating the candidate and marking the provisioning task
`accepted`. Accepted tasks no longer block the next replacement, but still count
against the rolling creation budget. Returned retained installations cannot
replace the active installation's probe source in fault evaluation.

A timeout, external DNS change, lost permission or stale proof after dispatch
leaves `needs_review`; subsequent execution is read-only reconciliation, never a
second mutation. Even successful provider writes are not committed as a verified
cutover if local ownership changes during the request. Undispatched intents may
be cancelled with live gates off. A dispatched intent cannot be cancelled as if
nothing happened. Recovery needs the prior exact transition, an owned retained
installation and **new fresh forwarding acceptance**; it checks the physical DNS
address and prepares another explicit transition. Old and new VMs are kept; no
automatic rollback, deletion, tanzhen resource mutation or account-wide token
rotation occurs.

Admin API routes below are under `/server/machine/cloud/acceptance`:

| Method | Suffix | Input |
| --- | --- | --- |
| GET | (none) | Bounded owned installations and acceptance checks |
| GET | `/dns` | Bounded owned records and transition intents |
| POST | `/enroll` | `policy_id`, `policy_revision`, `task_id`, `release_id`, `ip`, `confirmed` |
| POST | `/verify` | `id` (owned installation), `policy_revision`, `revision`, `confirmed` |
| POST | `/dns/prepare` | `id` (owned installation), `policy_revision`, `revision`, `zone`, `request_id` (32 hex), optional `recovery_of` (transition UUID), `confirmed` |
| POST | `/dns/execute` | `id` (transition UUID), `confirmed` |
| POST | `/dns/cancel` | `id` (transition UUID), `confirmed` |

All routes require an active administrator before validation and reject unknown
input fields. **Cloud Resources > Replacement Acceptance** is shown only when
the backend advertises its capability. It shows bounded creation progress,
candidate registration with explicit release digest, forwarding verification,
owned-DNS preparation, dispatch/read-back, cancellation before dispatch and
freshly accepted previous-instance recovery. Controls respect per-policy gates;
there is no enable switch or paid-VM creation button. Ambiguous preparation
retains its original request identity and form; dispatched operations expose
read-back, never a second mutation.

The primary operational signal is **mainland IPv4 reachability**, shown before
forwarding acceptance. Its five-second GET refresh reuses the existing cached
reports and does not send extra pings, collect history, or execute a matched plan.
One reachable target is green even if other configured targets fail. Complete
failed rounds are amber while confirming; only a fresh collected streak with a
same-context healthy baseline that meets the threshold becomes red/suspected
blocked. Expired, ambiguous, missing-source or failed reads are unknown, never
counted as failure. IPv6 is excluded. Clicking the status reveals exact IPv4
source/target results. With collection off, the page shows the current snapshot
but no invented zero streak. This operator-defined ICMP criterion is not an
independent censorship diagnosis. TCP/UDP remains a new-instance cutover guard,
not a per-round IP-blocking criterion.

### Single-Instance Isolated Acceptance Wizard (Disabled)

**Cloud Resources > Replacement Acceptance > Single-Instance Acceptance** joins
the existing independent policy, provisioning and forwarding APIs. It has five
steps: local precheck, explicit one-instance confirmation, installation/report
progress, forwarding acceptance and a sanitized report. Older backends without
the wizard capability do not show its entry. This change needs no extra tables,
dependencies or node-side changes. It does not contact or import tanzhen.

The local precheck reads an existing independent draft and an explicit active
Linux x86-64 release. It does not call a provider, produce user data, reserve an
attempt or approve a provider quote. No DNS credential is required for this
isolated run. Creation is blocked without the existing live gate, test-machine
allowlist, verifier configuration, forwarding authorization, available daily
allowance and an unused slot. All live gates and allowlists remain off/empty in
the repository. The wizard cannot enable them.

A 120-second HMAC-bound precheck pins the administrator, policy, release binary
digest, node configuration projection, verifier configuration, template and
current local checks. The selected policy's automatic creation and DNS cutover
permissions must remain off throughout this isolated run.
Creation requires two declarations and a final explicit confirmation. The
backend stores the policy/release/node pin in the existing encrypted task
payload atomically before dispatch. Policy, binary or node changes stop the
task before creation, including changes during provider preflight. Enrollment
cannot substitute a different release; the same version string with another
digest is still a mismatch. The normal provider price/permission preflight and
one-POST reconciliation rules remain in force.

Before submission the browser stores only an opaque request ID and release ID
in session storage. Failure to save prevents submission. Ambiguous responses,
reloads and reopened views query the original request; they never automatically
resubmit it. A definite server rejection before creation permits another manual
precheck, not a retry in the background. The report endpoint reads only bounded
local journals/cache, pauses polling in hidden tabs, and has no provider,
provisioning, DNS, power or notification side effects.
An explicit cancel control is available only before recorded dispatch. A race
with dispatch retains a needs-review outcome under the existing cancel API;
neither cancellation nor closing the wizard stops or deletes a cloud instance.
The shared installer still follows panel distribution settings. A different
installed binary fails the pinned release check rather than silently changing
the expected digest.

The report requires fresh exact-installation runtime/probe context and a current
signed forwarding proof. A failed read, expired proof or changed context cannot
reuse a prior pass. Passing means isolated new-instance readiness only, not a
DNS cutover, active replacement, performance benchmark or production-user test.
The original provisioning task remains `awaiting_acceptance`; it is not falsely
marked `accepted` without an owned verified DNS transition. Temporary client
cleanup, server-side fixture revocation and retained cloud resources are shown
separately. The worker does not revoke the pre-authorized server fixture, and
this wizard does not stop/delete VMs or clean up old-controller resources.

Admin endpoints under `/server/machine/cloud/acceptance/wizard`:

- `GET`: `policy_id`, `policy_revision`, optional exact `task_id` or original
  `request_id`; returns a bounded sanitized progress/readiness report.
- `POST /preview`: policy selection plus `release_id`; returns local checks and
  an expiring precheck identity, without external requests.
- `POST /queue`: exact precheck fingerprint/token/expiry and original request
  ID, plus explicit creation, isolation and cost declarations. This endpoint
  fails closed while live configuration is disabled.

Local tests use mocked provider/verifier responses and browser fixtures only.
Linux worker isolation, actual cloud-init, live provider billing and WAN protocol
interoperability still require a separately authorized non-business test.

### Linode Replacement Coordination (Disabled)

`2026_11_07_090000_create_machine_replacement_tasks` adds a replacement journal
and a unique link to the existing provisioning task. The journal and creation
task are committed together under the account lock. The existing task itself
reserves a rolling-24-hour attempt before any provider POST; failed and cancelled
attempts count. The journal records a per-attempt monthly price cap, not an actual
charge or a cap on the whole account's retained VM bill. The provider's current
regional quote is checked immediately before creation. Retained machines may
continue to incur charges; this feature does not stop or delete them.

The internal `MachineReplacementService::queue` requires a new explicit
authorization, `machine_failover.allow_replacement=true`, a policy allowlist,
the independent provisioning live gate, and its test-machine allowlist. All
remain false/empty in the repository. There is no browser execution endpoint,
automatic observation-to-creation dispatch, new execution schedule, or DNS action
in this coordinator. A collected plan is still not authorization.

The selected account, region, size, image, slot, origin and monthly limit come
only from the exact policy revision. Existing independent ownership and a fresh
same-context collected failure with its earlier healthy baseline are required.
Imported tanzhen resources cannot establish that ownership. Each failure episode
can reserve at most one task, including across refreshed guard snapshots and
failed attempts. A new request ID cannot silently retry the same episode. Account
creation history enforces both the policy/hard daily limits and cooldown. A
manual creation request cannot bypass these guards for an active owned slot.

Before the provider POST, the worker rechecks the policy, active administrator,
machine/account identity, failure evidence, daily allowance and cooldown after
provider preflight. Recovery before dispatch cancels the reserved attempt.
Recovery after dispatch retains the candidate and reports the recovery; it never
cancels a possibly billed VM or switches DNS. Unknown POST outcomes keep the
existing read-only reconciliation behavior and never repeat the create POST.
New reports and IPv4 success stop at independent forwarding acceptance. Cached
verified acceptance is displayed separately from DNS cutover; neither a manual
verdict nor `accepted` without the matching active instance and verified owned
DNS transition can mark a replacement completed.

The failover workspace lists bounded replacement stages, cap, timestamps and the
last 30 stage changes. A visible, non-editing page polls the admin-only
`GET /server/machine/cloud/failover/replacements` every 15 seconds. This bounded
local-journal read does not reload inventory or contact providers. It has no
create or cutover button. Its separate
`provision` notice policy starts disabled. Progress, failed/uncertain outcomes,
verified cutover and original IPv4-probe recovery use the existing notification
outbox, with unique stage events and current-state/authorization checks at
submission. Old progress is suppressed after a newer stage; disabled events are
not replayed, and ambiguous Telegram submission is not retried. A recovery notice
requires a prior submitted notice plus a fresh reachable sample with the exact
original installation, IPv4/target context and policy revision. It does not claim
client forwarding or business recovery.

The existing notice advancer reconciles at most 20 existing replacement journals
per minute using cached reports and local task/acceptance state, with a rotating
last-checked timestamp and a seven-day recovery watch. It does not create tasks,
call providers, probe nodes, change DNS, or toggle execution gates. Viewing or
refreshing the admin page remains read-only. Downgrade refuses to discard used
replacement audit; an empty migration can be rolled back without affecting the
existing tasks or cloud/DNS notification policies.

Verification here is synthetic SQLite/provider HTTP/queue/Telegram fixtures;
it does not demonstrate paid cloud creation, recipient delivery, WAN forwarding,
Linux verifier resource isolation, or production DNS propagation.

### Remaining Independent Failover Work

Before enabling real execution, the remaining work is:

- The multi-member service and automatic state machine described below now run
  end-to-end in an isolated simulation. The separate shadow view below can read
  cached panel evidence, but cannot dispatch production member operations.
  Connecting trusted mutation/receipt adapters, scheduling and separately authorized recovery remains follow-up work;
  this is not a switch that can be enabled by changing an environment variable.

- Separately authorize one isolated live creation and validate the disabled
  coordinator described above. Its candidate uses one pinned template and a
  matching-provider credential from the configured group (legacy policies keep
  their pinned account). There is no automatic provider fallback. The observation
  evaluator still records decisions only. Importing old V1/V2 services is
  optional, not a prerequisite; drafts, thresholds and simulated matches are not
  authority. Production automatic dispatch still needs separate authorization.
- Deploy and validate the isolated forwarding worker's Linux sandbox and WAN
  behavior, including the expiring fixture lifecycle. Only HY2 has a real
  loopback interop fixture so far. Reusing another controller's resources or DNS
  records remains a separately authorized takeover, not this independent path.
- Validate the operator workspace against one separately authorized non-business
  VM and DNS record. No manual verdict may bypass the trusted worker.
- Live-verify the new replacement notices and define an explicitly authorized
  retention/cleanup lifecycle. Existing power-task notices remain separate from
  the new disabled replacement scope. A retained installation is excluded from
  active fault evaluation but is not stopped, revoked, forgotten or deleted.

DigitalOcean/Vultr replacement creation also needs its own provider adapters;
their inventory and manual power support must not be presented as provisioning
support. All creation remains hard-off. A subsequent live test needs separate
authorization for one non-business VM, a spending boundary, forwarding acceptance
and its cleanup before independent production use can be considered. Existing
tanzhen services and DNS records are not changed by that test.

Creation reference:
[Create a Linode](https://techdocs.akamai.com/linode-api/reference/post-linode-instance).

### Provider Catalogs And Credential Groups

Migration `2026_11_07_120000_add_provider_groups_to_machine_failover` allows a
draft to select `provider` and `credential_group_id` instead of `account_id`.
Mixed groups are supported, but only credentials matching that provider are
eligible. The native region, size and image identifiers stay separate for
Linode, DigitalOcean and Vultr. Existing single-account policies remain valid;
owned identity cannot be silently migrated or rebound. A referenced group
cannot be deleted, and downgrade refuses to discard grouped policies.

The explicit admin-only `GET /server/machine/cloud/failover/catalog` reads
bounded native catalogs from fixed official API origins. It never creates a
VM. Normal list, poll and rehearsal reads do not fetch provider catalogs.
Each collection is limited to three pages of 100 items and the read has a
20-second deadline checked between bounded five-second requests. Invalid,
inconsistent or incomplete pages fail closed. Only normalized public image
metadata is returned. A ten-minute local snapshot is tied to the provider,
group membership and credential revisions; failed refresh discards its proof.
Changing provider/group clears all dependent fields. Region, size, image and
minimum disk compatibility are rechecked on save, not just in the browser.

Linode catalog candidates are standard compute plans in healthy core regions
supporting Linodes and Metadata, with public cloud-init images. The partial
premium/GPU availability feed is not treated as a complete standard-plan list.
DigitalOcean sizes and public distribution images use their regional lists.
Vultr uses native region IDs, plan location lists and supported x64 Linux OS IDs.
Catalog compatibility is not inventory, permission, architecture interop or
final pricing acceptance. Actual selected-account regional pricing and
preflight, existing reviewed-template allowlists and live gates still apply.
DigitalOcean/Vultr draft catalogs do not add replacement creation adapters.

At a new precheck/task the matching-provider pool selects an eligible credential
with the fewest recent attempts, then the oldest attempt and lowest account ID.
Busy, cooling-down and over-budget credentials are excluded. Both the selected
account hard cap and the policy-wide rolling 24-hour cap apply, including failed
and cancelled reservations; adding tokens cannot multiply the policy cap.
The task captures the selected account and group fingerprint. Changes before
dispatch invalidate the context and cannot cause a paid POST. Once dispatched,
uncertain outcomes only reconcile that original credential/task, never select
another token or issue a second create. Grouped plans can only enter via the
policy-bound replacement or isolated-acceptance path. Enrollment and subsequent
actions recheck ownership and live membership separately.

All repository execution gates remain false. Tests here are synthetic; no paid
VM, production DNS, existing tanzhen or node process was operated for this change.

Native catalog references:
[Linode regions](https://techdocs.akamai.com/linode-api/reference/get-regions),
[Linode types](https://techdocs.akamai.com/linode-api/reference/get-linode-types),
[DigitalOcean sizes](https://docs.digitalocean.com/reference/api/reference/sizes/),
[DigitalOcean images](https://docs.digitalocean.com/reference/api/reference/images/),
[Vultr plans](https://github.com/vultr/govultr/blob/master/plan.go),
[Vultr operating systems](https://github.com/vultr/govultr/blob/master/os.go).

### Multi-Member Services And Simulated Orchestration

Migration `2026_11_07_140000_create_machine_failover_services` adds a service,
member links, runs, member progress and an action journal. A service links 1-10
existing independent policies. Each policy and logical machine can belong to
only one service. Members have an explicit policy revision, order and participation
setting. Policy/provider templates and credential groups remain at the leaf
policy; a service never invents provider-independent region or image IDs.

Service-wide limits are 1-2 concurrent members, 1-20 creation intents and a
USD 0-10000 monthly-price reservation cap **per simulated run**. These synthetic
reservations are not a cloud quote, account quota, stock check or real billing.
Reservation and member transition commit together; exact-cent totals do not
multiply the cap per member. Disabled members are not simulated. Once a run
exists, member removal and service deletion retain audit instead of silently
discarding history. A referenced policy cannot be deleted. Revision changes
are explicit; old results do not become proof for the new revision.

The admin-only API is under `/server/machine/cloud/failover/services`:

- `GET /` reads metadata and available synthetic scenarios without provider calls.
- `POST /save` and `/drop` edit only the new local model using expected revisions.
- `POST /simulate` captures an actor-scoped request ID, immutable context and
  member states, then advances the synthetic state machine automatically.
- `GET /run` reads a run by ID, or resolves an uncertain original
  `service_id`/`request_id` without starting or replaying anything.
- `POST /advance` continues that original simulated run after interruption.
- `POST /cancel` stops further progress and retains dispatched intents and audit.

The single state machine progresses through baseline observation, distinct fresh
IPv4 failure samples, shared capacity reservation, candidate creation,
initialization, matching installation/configuration reports, IPv4 availability,
TCP/UDP verification and fixture cleanup, DNS ownership precheck, one DNS write
and readback. Any reachable IPv4 target is healthy; expired, duplicate, unknown,
wrong-source and invalid probe evidence cannot prove a blocked IP. A healthy
baseline from the same synthetic installation/address is required first.
IPv6 is not used for the trigger.

Every receipt is tied to the run/member context and the original operation ID.
Candidate ID, installation, address, binary and configuration hashes must match.
TCP and UDP synthetic receipts each require exact echoed payload digests,
3/3 samples, no missing/duplicate samples, and cleanup completion. DNS requires
the pinned credential revision, hostname, ownership and expected prior address;
an API success is not completion until readback matches. These are fabricated
adapter receipts, not proof of WAN forwarding or production DNS propagation.

Creation or DNS mutation intent is journaled **before** calling the synthetic
driver. An unknown result, interrupted response, or a lost receipt can only
reconcile that original intent. It cannot issue a second create/write or select
another member's candidate. An unresolved member pauses new creates for peers.
Before creation, source recovery stops creation. After candidate creation and
before DNS dispatch, it retains the candidate without DNS cutover. Recovery
after DNS dispatch reconciles the prior write rather than silently writing back.
Stopping or context invalidation also checks durable intents if the progress
write was interrupted. Service, policy, credential pool, DNS credential or
original actor changes stop further advancement. Each run is bounded to 200
virtual five-second ticks, 64 member events and one journal entry per action
kind. History is bounded to 100 runs per service and 500 total; reaching the cap
pauses new simulations and does not delete evidence.

`MachineFailoverSimulationDriver` has no network, queue, SSH or notification
transport. Synthetic source and candidate addresses use documentation ranges.
All results remain `mode=simulation`, `evidence_origin=synthetic`,
`business_verified=false`, `execution_enabled=false`, and `dns_cutover=false`.
`startLive()` always rejects execution, even if older single-policy gates are
enabled. Synthetic records never enter provision tasks, owned instances,
acceptance verdicts, DNS transitions, DNS bindings or accounting ledgers.

The admin tab provides member editing, fault injection into one/all participating
members, per-member progress and virtual timelines, read-only original-intent
lookup, resume/stop and synthetic report download. Page open, polling and refresh
are reads only. Session storage retains the simulation intent before POST; loss
of its response cannot silently create a fresh request on reopen. Capability
detection hides the tab on older backends. A missing migration is not an empty
successful service list.

The original 18 scenarios cover healthy operation, full replacement, one reachable
target, missing baseline, unknown/stale probes, recovery before/after creation,
rejected/unknown/ambiguous creation, initialization timeout, mismatched reports,
failed candidate IPv4/UDP verification, DNS conflict, and unknown DNS outcomes.
Tests also inject persistence interruption, member isolation, revision/actor
changes, shared caps and lost client responses.

This increment does not implement shared-hostname multi-line/weighted DNS,
provider fallback, DigitalOcean/Vultr creation, live background orchestration,
live notices or automatic retained-resource deletion. Each leaf still owns its
separate DNS intention. It does not import, schedule, modify or take over any
existing tanzhen service, VM, agent or DNS record. All live repository gates stay
off. A successful simulation is not production acceptance.

### Credential Health And Safe Rotation Plans

Migration `2026_11_07_160000_create_machine_credential_health` adds local account
identity proofs, aggregated credential restrictions and sanitized audit events.
The admin account list reads local health only; opening it does not call a cloud
provider. The health dialog shows authentication status, verified account aliases,
read/check times, earliest recheck and failure categories. Create permission is
always marked unverified: reading an account does not prove permission to create
paid resources. The new capability is hidden when an older backend lacks it.

- `GET /server/machine/cloud/account/health?id=...` returns local health and at
  most 50 events, without tokens, provider profiles, raw errors or identity hashes.
- `POST /server/machine/cloud/account/health/verify` requires an active admin,
  exact `id`, `expected_revision` and `confirmed=true`. It makes one explicit GET
  to the provider's fixed official account endpoint, with no redirects/retries.
  It cannot create, power, delete or change DNS. Clients cannot submit their own
  health verdict, account identity, cooldown or recovery proof.

Credential identity is an application-keyed HMAC of provider and secret, not the
local row ID, account name or revision. Renaming cannot clear a fault, and two
local rows with the same token cannot act as separate credentials. Canonical
cloud-account IDs are stored only as HMACs with a 24-hour proof lifetime. Linode
uses `euuid`; DigitalOcean uses `team.uuid` when present, otherwise account `uuid`.
Vultr's account schema does not expose a stable unique account ID; a valid read
can prove authentication but leaves account identity unknown. Email, name and
balance are not account identifiers or proof of create permission.

Verified aliases share account-level restrictions and creation budgets. Unknown
identities conservatively share the same provider's creation history/budget and
cannot be assumed to be independent paid accounts. Provider-quota and account
locks serialize the existing native creation path. Group/leaf/service attempt
and money limits remain cumulative; adding tokens does not multiply a limit.
Inventory reads do not extend canonical identity proof lifetime.

`CloudFailurePolicy` distinguishes authentication, permission, billing, quota,
regional capacity, invalid templates, rate limits, provider availability and
unknown mutation outcomes. Only narrow recognized provider error fields are
classified; mixed or unrecognized reasons require review. No raw reason text or
response body is saved. Retry-After and DigitalOcean rate-reset hints are honored
with bounded exponential backoff. Excessive server waits require review instead
of silently retrying early. Rate limits apply conservatively provider-wide, not
as a reason to jump between tokens.

Cooldown expiry is probation, not evidence of recovery. A fresh normalized read
of the same credential can recover authentication or that read's malformed
response restriction and can confirm recovery from an expired transient hold.
It cannot shorten an active backoff or erase create-permission, billing, quota,
template or unknown-create restrictions. DigitalOcean `status=locked` remains a
restriction even when the account GET itself returns 200. There is no public
force-clear endpoint. All active restrictions are evaluated before eligibility;
the display limit cannot hide an older active billing block behind newer expired
cooldowns. Audit events stop growing at 10,000 per local account; aggregate
health remains enforced, with latest-source deduplication even at that cap.
Downgrade refuses to erase retained identity, health or event evidence.

`MachineCredentialRotationPlanner` is advisory only. Definite authentication or
permission rejection can suggest another eligible, distinct credential in the
same provider/group. Billing/quota rejection requires a verified different cloud
account, not another token for the same account. Unknown account identity,
exhaustion, template errors or budget failure stop the plan; rate limits wait;
unknown mutation results only reconcile the original intent. Every returned
plan has `automatic=false` and `dispatch_allowed=false`. Native task evidence
records this plan, but the task's credential, marker, request key and installation
ID stay pinned. No real fallback task is enqueued. Only trusted reconciliation
of the exact original create can clear its own unknown-intent restriction.

Ten added credential scenarios bring the synthetic matrix to 28: auth and
permission rejection, billing on another verified account, same-account quota,
rate limits, template error, unknown create, exhausted pool, unknown identity
and duplicate token. They use the same classifier/planner with virtual A/B/C
credentials and independent synthetic receipts. All attempts consume the shared
run creation/price reservation caps atomically. Per-member reports show each
virtual attempt and decision, not real tokens or internal hashes. Synthetic
failures never poison formal credential health. They prove control flow only,
not live cloud error compatibility or production recovery.

Real automatic rotation and multi-member live orchestration are not enabled by
this increment. DigitalOcean/Vultr creation, cross-provider fallback, shared
multi-line DNS, live orchestration notices and automatic retained-resource
cleanup still need their trusted execution adapters and isolated acceptance.
No live gates, production settings, tanzhen services or node processes were
changed, and no real credential/provider request was used by these tests.

Official API semantics checked for this increment:
[Linode account identity](https://techdocs.akamai.com/linode-api/reference/get-account),
[Linode error schema](https://techdocs.akamai.com/linode-api/reference/errors),
[Linode rate limits](https://techdocs.akamai.com/linode-api/reference/rate-limits),
[DigitalOcean account and scopes](https://docs.digitalocean.com/reference/api/reference/account/),
[Vultr account schema](https://github.com/vultr/govultr/blob/master/account.go),
[Vultr API rate limits](https://docs.vultr.com/support/platform/api/what-rate-limits-apply-to-the-vultr-api).

### Read-Only Evidence Adapter And Shadow View

`MachineFailoverReadOnlyAdapter` reads the existing observer's cached IPv4
projection and collected observation/decision records. It has no provider, DNS,
queue or notification transport. Its execution method always rejects, including
when legacy live gates are enabled. This is the read-only side of an execution
boundary, not a real creation or credential-rotation executor.

The admin-only `GET /server/machine/cloud/failover/services/shadow` accepts only
`service_id` and `service_revision`. The browser cannot submit probe facts,
timestamps, receipts, verdicts, selected credentials or execution options. It
returns `mode=shadow`, `evidence_origin=panel_cache`, `dispatch_allowed=false`,
`execution_enabled=false`, `automatic_rotation=false`, `dns_cutover=false`,
`business_verified=false` and zero cloud writes. It does not change database
rows, reserve budget, collect samples, send pings, fetch provider inventories or
catalogs, issue notices, or call cloud/DNS APIs. No migration or scheduled task is
added; missing required tables are not treated as an empty successful report.

Any valid reachable IPv4 target makes the source healthy. All-unreachable is
still a reachability observation, not independent proof of censorship. A proposed
replacement needs fresh matching collected evidence, a same-source healthy
baseline, the configured consecutive-failure threshold and a matching immutable
fault episode. Repeated page reads cannot increment failure counts. A newer
uncollected report, clock skew, stale/missing samples, changed installation or
policy, unknown or wrongly attributed probes cannot reuse the old trigger.
An older decision in the same uninterrupted failure episode remains usable;
another episode does not. Disabling observation does not silently enable a new
collector: current reachability can still display, but collected trigger proof
is unavailable. The new UI never flips that observation setting.

Independent source ownership, active accepted source-task linkage, current
credential health, provider/template support, creator authority, existing task
history, cooldowns, already-used fault episodes and supported DNS ownership are
checked locally. Imported or unowned tanzhen instances cannot authorize a
forecast. Existing pool selection is reused without token rotation. A virtual
batch respects member order, service parallelism/attempt/price caps and shared
cloud-account identities; unknown identities retain the existing conservative
sharing behavior. Later same-account members wait, not reuse a second allowance.

Forecast price is the configured per-attempt monthly-price reservation upper
bound, not an API quote, actual charge, cloud capacity guarantee, account spending
cap or the retained VM bill. `pricing_checked=false`,
`retained_cost_verified=false`, and `writes_reserved=false` remain explicit.
Cloud pricing/capacity/create permissions, candidate forwarding and mutation-time
DNS ownership remain pending checks. A `would_replace` result is a plan only,
never execution authorization, and cannot be passed into the synthetic state
machine as a trusted receipt. There is no fabricated candidate VM or IP.

The report rechecks administrator and registry context after reading; credential,
policy, machine or DNS revision changes invalidate it. Reads have a ten-second
deadline checked between members. Report validity is at most 30 seconds and no
longer than its source evidence. The service reads up to ten enabled members;
there are no background provider calls. This view does not reconcile or retry a
real mutation, and still needs trusted live receipt adapters and isolated WAN
acceptance before execution work can proceed.

The multi-member page advertises this capability separately. Opening the page
does not fetch shadow evidence. An explicit read displays source identity/IP,
sample freshness/counts, credential/template selection, virtual budget and
blockers. Optional browser-local observation reads every 15 seconds, skips hidden
pages and overlapping requests, and stops on unmount. Failed reads retain old
data only as expired; late responses from another service/revision are ignored.
No new control enables real creation, credential rotation or DNS cutover. Tests
use isolated SQLite/cache/provider fixtures and local browser adapters only;
no production system, cloud VM or existing tanzhen service was contacted.

### Gated Native Linode Bridge

`MachineFailoverNativeBridge` connects the multi-member registry to the existing
native provisioning, initialization callback, report/IPv4 checks, signed
forwarding verifier and owned Cloudflare A-record transition code. This is a
separate durable native journal, not an extension of the synthetic driver and
not a consumer of browser-posted verdicts or shadow/simulation receipts.

The bridge is deliberately unarmed: `MachineFailoverExecutionGate` always
rejects. There is no environment/configuration switch, controller route, UI
button or scheduler that enables or starts a native multi-member run. Existing
provisioning, replacement, allowlist, acceptance and DNS gates remain unchanged
and must also pass. Tests replace only the gate dependency in an isolated test
container; SQLite/cache state, queues, cloud APIs, DNS and the verifier are all
fixtures. No production database migration, cloud creation, real rotation, DNS
write, tanzhen operation or kelinode-rs change is performed by this increment.

Each run pins its administrator, registry revision, provider-specific policy and
credential-pool snapshot, release ID and binary SHA-256. Each participating
member claims one immutable failure episode and independently owned active
source installation and existing owned DNS record. Imported/unowned instances,
another controller's used episode and unsupported creation providers are
rejected. One episode cannot acquire a second independent replacement job via
the older manual replacement entry. Native run history also prevents deleting
the service or removing its members to discard reservations.

Before any native enqueue, a database transaction reserves one child attempt,
its request ID, chosen credential revision and the configured monthly-price
upper bound in integer cents. Attempts share the service's parallel, attempt
and price-reservation limits across members. Prior runs' reservations are not
reset by starting another run or crossing a calendar month; rejected, cancelled,
retained and completed attempts do not automatically refund the price reserve.
The separate finance workflow below now adds a verified source/history baseline
before reserving another attempt and permits an explicit audited local release.
The bridge's older internal report still describes its incremental reservation
only (`price_verified=false`, `baseline_cost_included=false`); it is not a quote
or combined balance. The finance workspace is the combined-balance interface.
Neither report is an actual bill or provider spending limit. Unknown mutations continue to hold
their execution slot; account aliases and unknown cloud-account identities do
not receive a separate account allowance. Native daily limits and cooldowns are
rechecked under the existing provisioning locks.

Creating a native task and binding it to an attempt occur in the same database
transaction. Task ID, credential, installation ID, machine authority, callback
identity, marker, plan hash and initialization request are bound by a private
identity digest. They cannot be rebound after a timeout or token rejection.
Only a trusted, typed definite rejection, without an external resource or
initialization callback, can lead to a distinct child attempt after the existing
rotation planner, credential health, source evidence and all shared budgets pass
again. Billing/quota errors still require a verified different cloud account;
duplicate tokens, aliases, unknown identity and template errors do not authorize
token hopping. No ordinary/manual task gains this retry behavior.

Unknown POST outcomes only reconcile the original account, request, marker and
task. A repeated enqueue uses that task's original request ID and binding.
Initialization callbacks, candidate reports and binary identity use the native
validators. Forwarding receipts are read from the trusted verifier/check records,
must match the exact native task, candidate, configuration and cleanup proof,
and are pinned before DNS. Identical checkpoints are idempotent; conflicting or
cross-task receipts require review. A worker call with an unresolved receipt is
not blindly dispatched again. Fresh-proof expiry also stops automatic progress
rather than manufacturing another successful test.

DNS preparation has one deterministic request per child attempt and must match
the original owned record, source, zone and candidate. Preparation interrupted
before its local acknowledgement reattaches that same transition. DNS writes
retain the existing persisted-before-POST boundary; an uncertain write only
reads back that transition, even if the original source becomes reachable.
Successful persisted DNS acknowledgement can be recovered without issuing
another verifier challenge or DNS mutation. Forwarding and DNS evidence cannot
be combined from different attempts. Source recovery before create cancels;
after creation but before DNS it retains the candidate and stops cutover. Source
evidence and authority are checked again immediately before each new mutation.
Unknown/stale evidence does not count as a recovered or blocked IP. There is no
automatic DNS reversal or VM deletion.

The native journal has a 20-minute run deadline; expiration, context changes,
unknown proof or unresolved failures stop for review while retaining the original
mutation identity and reservations. Review is not permission to re-dispatch.
Progress can resume the same persisted intent within its valid proof/deadline,
not start a fresh paid operation. The native bridge remains backend-internal and
has not been exposed as an execution UI. DigitalOcean/Vultr creation adapters,
live worker/WAN and provider error acceptance, controlled activation and native
orchestration notices are still outstanding. This does not claim complete live
tanzhen v2 parity or authorize a non-business cloud acceptance run.
The forwarding integration tests exercise the existing isolated HY2 verifier
profile; they do not add or certify interoperability for every node protocol.

### Cost Baseline And Reservation Review

`MachineFailoverBudget` adds a local finance workspace to the multi-member page.
Opening it reads the panel journal only. The explicit cost-check action performs
bounded GETs against the fixed Linode API; no provider mutation is available.
The snapshot includes original and unjournaled retained instances, including
powered-off instances. It deduplicates aliases of the same verified cloud
account/resource, uses integer cents and regional monthly-price overrides, and
does not count a journaled candidate twice on top of its conservative reserve.
New journaled candidates without a current quote retain the full upper-bound
reservation. Known higher quotes increase the combined allocation.

This is a compute-instance monthly estimate, not an invoice, accumulated usage,
credit balance, tax calculation or provider-enforced budget. Only the supported
standard Linode types with one public IPv4, backups disabled and no attached
volumes have a verified estimate. Unverified add-ons, types, resource identities,
untracked resources, incomplete inventory and over 100 unsettled active tasks
block new reservations instead of becoming zero-cost resources. Audited settled
entries can now be logically archived as described below. DigitalOcean and
Vultr pricing/creation are not added by this increment.

A snapshot pins service, policy, machine authority and credential context.
Credential account identity must already be known and freshly verified by the
credential-health workflow. Cost reads require the relevant account, Linode,
type and volume read permissions. Resource absence additionally requires an
unrestricted account view (`GET /profile/grants` returns an empty 204), exact
complete inventory pagination/counts and matching account identity/scope before
and after the read. A restricted or failed query never proves deletion. Cloud
reads have a 30-second total deadline checked between requests; snapshots expire
after 300 seconds. Refresh begins with a durable reading record, invalidating
the previous snapshot immediately. A reading record older than 35 seconds is
displayed as expired. A failed, changed or superseded refresh cannot resurrect
old evidence. No periodic provider polling was added.

The native review displays member phases, individual attempts, initialization,
forwarding and DNS receipt presence, and retained reservations. It exposes no
start, resume, retry, credential-rotation or cutover endpoint. The new GETs read
local records; cost capture is a separate POST that still makes only provider
GETs. The service execution gate continues to reject every real invocation.

A reservation release requires a current administrator, explicit confirmation
and a short-lived encrypted proof pinned to the exact attempt, current facts and
administrator. Eligible evidence is limited to a never-dispatched task, a trusted
typed definite rejection without a resource, or a known resource ID confirmed
absent in a fresh complete unrestricted snapshot. An uncertain create without an
external ID cannot be released, even if an inventory is empty. A running member,
active owned instance, recent Agent report, armed cloud resource or pending DNS
reference blocks release. Proofs are revalidated under the registry, account and
quota locks; one immutable release per attempt and request identity prevents
double credits. The audit retains the task, failure evidence and resource IDs.

Release credits only the panel's price reservation, never a cloud bill, customer
balance or payment/traffic ledger. It does not delete or stop a VM. It fences the
released attempt and cancels only unfinished local work so that the old intent
cannot resume with a refunded reserve. A lost browser receipt triggers readback
of existing records, not automatic resubmission. Failed reads retain old data
only as expired. Closing or switching the surrounding service is guarded while
an explicit finance operation is pending.

Tests use isolated SQLite, cache and mocked cloud responses; browser previews
use synthetic adapters only. The finance schema has not been migrated in
production, no real cloud acceptance has run, and no code was deployed by this
increment. Automatic creation, automatic credential rotation and DNS cutover
remain disabled; existing tanzhen and kelinode-rs are untouched.

### Stopped-Run Review And Long-Lived History

The native budget workspace now has a separate stopped-run review path. It
cannot start, advance, resume, reinstall or replay the old execution. The
existing execution gate and all creation/rotation/DNS gates stay closed. All
new provider calls are GETs to the existing fixed Linode endpoint. There is no
new scheduler, external dependency, deployment or production migration.

An explicit review reads the original credential revision and immutable request.
The account identity and unrestricted scope must match before and after a
complete inventory read. A late candidate must uniquely match its marker,
label, creation-time window, region, instance type and image. Unknown identity,
duplicate candidates, partial/restricted inventory and changed context stop the
review. A known ID is never substituted for a different ID, and the browser
cannot supply an instance ID or a passing verdict.

Each check is persisted with its exact subject, administrator, context and a
120-second validity window. A newer check supersedes older checks. Claim and
close operations revalidate current authority, stopped state, immutable lineage
and the check under the existing registry/quota/account locks. Provider-backed
actions read the cloud again and require the same result before recording any
local metadata. Request identities make lost-receipt retries idempotent. A lost
browser receipt triggers local readback, never automatic replay.

Claim records only the unique external ID, creation timestamp and addresses on
the original task, and leaves it in `needs_review`. It does not enroll an active
owned instance, install anything or take over business traffic. Claim or review
closure permanently fences that old attempt independently of the hard execution
gate. An active administrator may review an orphaned run without restoring its
original administrator's authority or changing the run's owner.

Closure records a separate review outcome; it does not overwrite the original
run's failure/retained status. A retained, positively identified resource keeps
its reservation and may still incur charges. A no-resource attempt can close
only after its existing budget-release audit exists. An empty member with no
attempt can close without inventing a resource or credit. An unknown POST with
no external ID stays unresolved even after a complete empty inventory. A closed
review is not proof that a VM was deleted or that clients recovered.

Verified released records are logically archived during closure, with the task,
attempt, release, review and failure evidence all retained. The archive is pinned
to the task identity and the exact release audit. A changed identity invalidates
that archive rather than hiding the task. Unowned terminal ordinary tasks that
were never dispatched are also excluded from the active footprint without
deleting their audit. Settled historical failures no longer consume a lifetime
100-task allowance; unsettled active entries still retain that safety bound.
Metadata is read in bounded database chunks, and reservation totals keep all
historical releases and attempts, not just the current page.

Archived known external IDs remain watched in complete cost snapshots. A
reappearing instance is priced and blocks new reservations until it is reviewed
and returned to active tracking. Restoring an archive does not undo its budget
release or remove its execution fence. Every new review observation changes the
financial context, immediately invalidating older cost evidence; an in-flight
older cost read cannot overwrite it as fresh.

History has 20-entry keyset pages with open-review, all and handled scopes.
Unresolved reviews sort before completed history. Service/revision/filter-bound
encrypted cursors expire after 15 minutes; review-journal changes require a
fresh first page instead of silently skipping moved entries. The original
audit remains queryable even after a review closes or an entry is archived.

Forwarding receipts now show their recorded time and validity end, with an
expired state after that boundary. An unexpired stored receipt is still labelled
as a historical pass, never a current retest. DNS evidence is labelled as
historical provider readback with no invented current validity or worldwide
propagation claim. Legacy receipt-presence booleans remain for compatibility,
but the UI no longer renders those alone as a fresh passing test.

All verification for this increment uses isolated database/cache/cloud fixtures
and local browser adapters. No real VM was created, claimed or deleted, no real
DNS was changed, and no existing tanzhen service or node binary was touched.
