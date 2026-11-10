# Cloud Resources

This is a panel-side integration. It does not change kelinode-rs, its report
frequency, the SSH transport, subscriptions, or the proxy data path.

Current code includes guarded Linode, DigitalOcean and Vultr replacement
creation adapters. All real execution gates remain closed. The historical
increments below describe their original scope; the current multi-provider
boundaries and missing live credentials are recorded under
[Multi-Provider Replacement Adapters](#multi-provider-replacement-adapters-disabled).

For the preparation-only checklist and required operator inputs for one new
non-business candidate, see [the isolated acceptance preparation plan](machine-isolated-acceptance-plan.md).
That plan does not authorize provisioning, deployment, production access or DNS changes.

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

## Admin Navigation

The server-machine toolbar has a direct **Failover** entry. It opens the existing
multi-member service workspace, ahead of inventory and credential tabs. Older
panels fall back to their supported independent drafts or inventory without
requesting an unsupported orchestration endpoint. The separate **More > Cloud
Resources** entry and instance-specific cloud links still open inventory.

Inventory retains cloud state, reported identity, addresses, reporting health
and explicit installation binding. It no longer exposes whole-VM power actions
or auto-boot switches. **Advanced Maintenance** contains three separate views:

- **Power Tasks** opens first and retains queued, uncertain and historical
  completed/failed records, including their reasons and timestamps. The existing
  pre-dispatch cancellation restriction remains; this is not deletion or replay.
- **Instance Maintenance** retains the existing explicitly confirmed power,
  auto-boot authorization and recovery-verification controls. Provider and
  instance identity checks, disabled states and ambiguous-request IDs are unchanged.
- **Notices** retains the existing cloud notification history and expandable
  audit details, with resource-scoped reads and the explicit notification-policy
  confirmation. It is loaded only when selected.

Re-entering Advanced Maintenance starts with task history, not power buttons.
Opening or switching views does not issue a provider mutation, arm auto boot,
rotate a credential or change a notification policy. Existing policy values are
displayed as received, never overwritten to manufacture an off state. This is
an admin navigation change only: it deletes no task/audit records, introduces no
migration, changes no queue or execution gate, and does not deploy or operate
existing cloud instances, DNS or tanzhen. All live-automation settings remain as
previously authorized; this UI change grants no additional execution authority.

Local UI verification on 2026-11-11 passed 797 server tests in 76 files and twelve
Playwright scenarios, with 34 desktop/mobile, Chinese/English and light/dark
screenshots. The browser run includes the full server-list-to-failover entry,
legacy fallback, retained task/audit views and cancelled power confirmation;
all browser requests stay on local fixtures, with zero mutation requests.
The mobile navigation checks reject clipped tabs and overflowing toolbar text.
The single-file build passed the unchanged limits at 4,667,944 JS bytes and
570,603 CSS bytes, without new dependencies. Aggregate reports and screenshots
are in the ignored workspace `.codex-audit/cloud-maintenance-20261111/` directory.
This is UI/synthetic acceptance, not authorization or testing of real VM power.

The subsequent source-only synchronization builds from clean keli-admin commit
`c42ab1c` and includes the navigation, fixtures and regression tests above.
The normalized committed assets are 4,667,914 JS bytes and 570,603 CSS bytes;
their content-hash URL versions are `60e6982dc675` and `262c502cd66f` respectively.
The build manifest pins the full clean source commit and all three entry-file
digests. The asset verifier, unchanged bundle limits and 103 focused navigation
regression tests passed. This synchronization does not publish an image, deploy,
change an automation setting or operate cloud power, DNS or tanzhen.

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
an optional DNS credential/hostname intention. Legacy single-account drafts
remain Linode-only. Provider-group drafts support all three catalogs; native
adapters still require their own reviewed templates and execution gates.
Empty DNS and zero budget may be saved as incomplete
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
  The Linode native bridge, receipt checks, background tickets and local stop
  recovery described below are now implemented behind a rejecting execution
  gate. The gated automatic admission described below now links current collected
  proposals to native intents in local tests. Scoped production authorization,
  live cloud/WAN/DNS acceptance and deployment remain outstanding; changing an
  environment variable cannot activate this path.

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

DigitalOcean/Vultr replacement adapters have since been added locally as described
below; inventory and manual power support alone are not acceptance evidence.
All creation remains hard-off. A subsequent live test needs separate
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
Catalog reads themselves do not enable the separately guarded creation adapters.

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

This simulation increment does not implement shared-hostname multi-line/weighted
DNS, provider fallback, DigitalOcean/Vultr creation or automatic retained-resource
deletion. Later sections describe guarded native scheduling and notices, neither
of which has live acceptance or activation. Each leaf still owns its
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
multi-line DNS and automatic retained-resource cleanup still need their trusted
execution adapters and isolated acceptance. Guarded native notices and scheduling
are described below; those additions also remain unarmed and not live-accepted.
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
and must also pass. The later runner only advances already recorded internal
intents; its local stop API cannot start or resume a run. Tests replace only the
gate dependency in an isolated test
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
has not been exposed as an activation UI. DigitalOcean/Vultr adapters are now
implemented locally; live worker/WAN, provider error acceptance and controlled
activation remain outstanding. The later native notices and background runner sections describe
their separately guarded additions. This does not claim complete live
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

### Native Failover Notifications

Native multi-member journals use the existing `admin_operations` outbox and
Telegram administrator channel, with a separate `failover` policy that defaults
to off. Enabling the policy baselines existing member, review and resource-risk
signals in the same transaction as the policy revision. Historical entries and
events from a disabled period are not replayed. The UI is a collapsed section
under multi-member orchestration; opening it reads local notification history
only. A policy change is explicitly confirmed and never enables execution.

The source reads native journals, stopped-run review actions, owned-instance
records and already captured cost snapshots. It does not call provider APIs,
collect new telemetry, run acceptance, advance a task, release budget, or alter
cloud/DNS state. Start/advance/review hooks run after their business transaction;
completed cost reads mark the relevant local notice cursors due. The existing
minute notification command also scans at most 100 due members, covering missed
hooks and deadlines. Notification failures cannot fail a business transaction.
Typical rechecks are one minute for active members, five minutes for review,
one hour for retained-risk records and one day for settled completed/cancelled
members. This bounded sweep can take multiple ticks for a large backlog; it is
not a five-second real-time alerting SLA.

Progress, timeout, uncertain/review, budget blocks, retention, claim/closure/
restore and original IPv4-probe recovery are distinct events. Routine progress
is coalesced to one per member per ten minutes; critical stage changes are not
held by that progress cooldown. A durable per-member cursor prevents a pruned
30-day outbox record from being recreated. Retention risk starts after an hour
and repeats at most daily per member. The warning includes stopped candidates
and a retained old source after cutover, but never the active accepted candidate.
A matching fresh unrestricted absence observation suppresses the warning. A
released attempt stays quiet unless later matching positive cost evidence finds
that resource again. An unknown result is not treated as zero cost or absence.
All monetary wording is potential ongoing charges, not an observed invoice.

Forwarding/DNS success notices require pinned attempt/task/account/installation/
release identities and matching receipt digests. The isolated acceptance record
must have completed cleanup and still be within its recorded validity window.
DNS additionally requires the exact native request, original/target instances,
owned record and a verified provider readback. Missing, forged or expired proof
is a review signal, never success. These remain historical synthetic forwarding
and provider readback facts, not proof of current client service, worldwide DNS
propagation or all-user recovery. Probe recovery requires a fresh matching
original-instance observation and never implies forwarding recovery.

Before submission, the notice worker checks current authorization, policy
revision, source context, phase, proof and expiry under its existing delivery
claim. Changed or obsolete notices are suppressed. Telegram queue submission is
not proof of recipient delivery; uncertain submission is never blindly retried.
Only whitelisted codes, numeric IDs, shortened run/installation references and
receipt times enter the outbox/Telegram text. Raw provider errors, credentials,
configuration, customer data and logs do not enter notification snapshots.

Migration `2026_11_07_210000` adds a disabled policy and persistent local cursors;
downgrade refuses to erase populated notification evidence. Verification uses
in-memory databases, fake transports and local browser adapters only. This
increment is not deployed or enabled in production. Real creation, automatic
rotation and DNS cutover remain hard-disabled. Existing tanzhen and kelinode-rs
are untouched; no production notification or cloud acceptance has been run.

### Native Background Runner And Local Stop Control

Migration `2026_11_07_220000` adds durable runtime tickets and stop events for
existing native execution IDs. It refuses to discard populated audit on downgrade.
`machine_failover.runner_enabled` defaults to false, and the unchanged
`MachineFailoverExecutionGate` still rejects execution even if that config is
changed. There is no start/resume/activation HTTP endpoint, automatic fault-plan
enrollment, tanzhen import or takeover. Real creation, automatic rotation and DNS
cutover remain disabled. This increment is local and has not been deployed.

The internal start hook can enroll an already authorized native intent only when
every execution gate permits it. The conditional minute command
`machines:advance-native-failover` recovers at most 20 due tickets per sweep.
Each queued job carries a generation and random nonce; only its hash is stored.
A worker claims a 120-second lease under the run lock and advances one member.
Jobs use the dedicated `redis_failover:machine_failover` queue, one attempt and a 75-second
timeout, below its 90-second retry visibility. Ordinary progress chains a delayed
ticket (normally 20 seconds, one
second for urgent DNS/retry work); the minute sweep is recovery, not the primary
step timer. Members rotate by priority/ID, with acceptance-ready DNS work ahead
of waiting members so a bounded receipt is not deliberately starved.

Duplicate/early deliveries, replaced tickets and expired workers cannot advance.
Missing queue receipts and expired leases create a new fenced ticket for the
same run, not a new paid intent. A surviving member lock causes bounded waiting
within the original deadline. A crash after the run's terminal commit is settled
locally so stale runtime rows do not clog the due sweep. The original actor,
configuration, limits, task lineage and proof validity are rechecked at existing
boundaries. Lease context is removed after each job, including failure paths.
Missing runtime event schema fails closed for already managed tasks. A removed
payload marker cannot bypass the reverse task/attempt ownership check.

The admin-only metadata endpoint is
`GET /server/machine/cloud/failover/services/native/runtime`. The local stop
endpoint is `POST /server/machine/cloud/failover/services/native/stop`, accepting
only the run ID, expected runtime revision, durable request ID and confirmation.
An active administrator can irreversibly latch a stop; stale revisions and
conflicting request reuse are rejected. Retrying the same stop intent reads its
recorded result. The latch invalidates queued tickets and current leases. A
separate always-registered minute `--stops-only` sweep finishes pending **local**
stops after worker death, even when execution is disabled; it cannot call cloud
or DNS adapters. No schedule was installed or executed in production here.

The dispatch marker and stop latch serialize on the runtime row. Once a provider
request has crossed that boundary, it may still complete: stopping progression
does not revoke an in-flight HTTP request or prove a VM stopped. Unsubmitted work
is cancelled locally; a prepared, matching DNS intent may be locally cancelled.
Known created candidates are retained, while unknown create results or dispatched
DNS intents remain for review. Earlier definite rejections keep their original
task/attempt verdict and evidence; stopping a later attempt does not rewrite them
as uncertain. Changed/foreign task or DNS lineage is left
untouched and marked for review. Completed members remain completed. Stop never
deletes or powers off a VM, reverses DNS, releases reservations or retries a paid
request. Existing initialization callbacks can record pinned facts while active
without a worker lease, but cannot advance managed tasks or revive a stopped run.

The native record page shows lease/due/stop times, generation and the latest 20
whitelisted scheduling events. It polls metadata only while visible and idle,
rejects stale responses, disables stop after an uncertain read and reads back an
ambiguous stop response without automatically resending. No nonce, actor ID,
private queue error or provider response is exposed. Local run completion is not
presented as real forwarding, DNS propagation or billing acceptance.

The default unit/browser suite uses in-memory SQLite, fake cache locks/queue/cloud/DNS/verifier
adapters and local desktop/mobile/dark/English browser cases. It covers full
multi-member progression, duplicate/lost tickets, worker expiry, deadline/owner
revocation, pre-dispatch/in-flight stops, lock survival, migration failures and
stop recovery while all real gates are closed. These tests are not distributed
Redis/MySQL concurrency acceptance, Linux worker/WAN acceptance or paid cloud
acceptance. No node binary/configuration or existing tanzhen resource was changed.

### Disposable Redis/MySQL Multi-Worker Acceptance

`Native Failover Isolated Acceptance` is a separate, manual-only GitHub Actions
workflow. It cannot publish an image or deploy: permissions are contents-read,
there are no repository secret inputs, and it is not the `CI` workflow consumed
by the existing image publisher. Code synchronization for this work uses
`[skip ci]` commits to avoid that automatic image-release chain. The admin source
and its versioned panel assets are synchronized independently of deployment.

Each matrix job creates disposable MySQL 8.4 and Redis 7 service containers on
random loopback ports, with a database-scoped non-root MySQL account. It runs
both REPEATABLE READ and READ COMMITTED. The fixture refuses implicit connections,
standard database/Redis ports, non-loopback hosts, unexpected database/user/server
identities, global grants, a nonempty initial database or missing ownership
markers. It loads only the minimal test application, not the panel `.env` or its
production bootstrap. SQL tables and Redis keys belong to a random fixture
namespace; cleanup verifies ownership and removes only that fixture's resources.

The tests use real InnoDB transactions, Redis locks, Laravel Redis queue payloads
and separate PHP worker processes. Cloud, DNS and forwarding verifier HTTP calls
are intercepted in every process and backed by shared synthetic fixture tables.
No real cloud/DNS API, node process, user account, production database or tanzhen
resource is used. Execution gates are replaced only inside the explicitly
guarded test processes; repository defaults and the rejecting production gate
are unchanged. The mock budget guard isolates scheduling from separate finance
acceptance; this is not a real cloud-cost baseline or invoice check.

The scenarios cover two-worker/two-member completion, three-worker duplicate
payloads, simultaneous recovery sweepers after lost ready/delayed tickets, a real
queue-only loopback connection refusal, SIGKILL after a recorded mock creation,
stale live-worker readback, preflight stop, stop recovery after worker death with
execution disabled, simultaneous identical stop requests and pre/post DNS-write
stops. A killed original queue job can exhaust its one allowed attempt; that
expected queue outcome is counted separately, and the native recovery ticket
must reconcile the original paid intent without a second mock create. Unexpected
worker errors or exhausted jobs in the other scenarios fail acceptance.

Fault timers are accelerated only in fixture-owned rows/keys. Normal tests do
not wait the full 120/300-second production leases: the test expires the recorded
lease and applies a short real Redis expiry to owned locks. Workers exercise
Laravel `runNextJob`, not a production Horizon supervisor lifecycle. This suite
therefore does not certify real-time timeout duration, Horizon daemon recovery,
WAN forwarding, cloud-provider errors/charges or DNS propagation.

The concurrent stop test holds the runtime row while both child processes take
their first request snapshot. The replay lookup after acquiring that row now
uses a current locking read, so REPEATABLE READ cannot mistake the first committed
stop for an unrelated runtime revision. JSON-backed request/plan/acceptance pins
compare object members independently of key order; string/boolean/null values,
list order, concrete resource identities and signed verifier bytes remain
checked. Native request hashes are stable across MySQL JSON object normalization;
the forwarding receipt/signature protocol is unchanged.

Artifacts contain JUnit and bounded aggregate JSONL summaries only: scenario,
isolation, worker/error/exhaustion counts, mock operation counts, whitelisted
reasons and cleanup results. They do not include serialized queue payloads,
generated credentials, scripts, raw provider logs or database dumps. Failed runs
are retained as evidence and are not presented as successful acceptance.

### Disposable Horizon Daemon Acceptance

`Native Failover Horizon Acceptance` is a different manual-only workflow. Its
`smoke` scope validates console startup, a local stop and neighbor isolation;
`full` scope additionally observes real process timeout, runtime/member lease
expiry, master restart and stop recovery. It has the same disposable service
identity guards and no release/deployment path. Never point this fixture at an
existing Redis server, panel database or production Horizon environment.

Each case starts the locked Horizon v5.45.6 console implementation: a master,
two supervisors and independent failover/admin `horizon:work` processes using Horizon RedisQueue.
The console-only test bootstrap registers the vendor queue/events/services but
does not load the panel `.env`, dashboard, production schedules or supervisors.
Master identity, Horizon/queue/cache prefixes and the Linux session are owned by
the case. A neighboring synthetic queue and master marker are checked before
and after cleanup. This is a namespace noninterference check, not an attached
production neighbor or a test of an existing external process manager.

Native jobs keep their actual 75-second timeout, 90-second Redis visibility,
120-second run lease and 300-second member lock. The suite never edits expiry,
due rows, Redis sorted-set times or the clock to advance a stalled run. A blocked
synthetic create is recorded durably before waiting for the real worker timeout.
Horizon must replace the worker; new fenced tickets must wait out the surviving
member lock and reconcile the same mock resource without another paid intent.
After a master-only SIGKILL, its orphan supervisor/workers must exit and a fresh
master must continue the original task. A separate worker SIGKILL after stop
must settle locally after the actual run lease, even with the execution gate
closed, without DNS writes or releasing an uncertain cost reservation.

Controlled source telemetry is kept fresh during real waits and initialization
callbacks use current timestamps. Publishing a new source probe while a short
step is validating an older SQL decision snapshot is intentionally avoided;
changed-evidence concurrency is not the purpose of this daemon-lifecycle suite.
No evidence checks, signed receipts or production gates are weakened for it.
The parent drives the normal due/stop sweeper at its real clock (polled every
second for bounded observation), not the panel's one-minute scheduler cadence.
It therefore validates lease lower bounds and recovery semantics, not a promise
that production minute scheduling will recover at exactly the expiry second.

Signals are restricted to a recorded fixture Linux session, matching UID and
owner-bearing wrapper argv. Cleanup first requests graceful termination, then
signals only individually verified surviving fixture PIDs. It never invokes
global Horizon terminate/purge, queue clear or unqualified process killing.
Aggregate artifacts include runtime hashes, actual elapsed times, expected
timeout/exhaustion counts and cleanup evidence. Owned processes, private console
files, tables and Redis keys must be gone, neighbor sentinels unchanged, and the
CI service containers/network removed. Cloud, DNS and verifier remain mocked;
this is not paid cloud, DNS propagation, WAN or real user acceptance.

Historical full daemon run before the dedicated-pool split:
[42392469002](https://github.com/keli-123456/keliboard/actions/runs/42392469002),
test source `275af39b0ab5ea01a1428856893aff6487097825`. All four cases passed in
both isolation levels (8/8, no errors or skips). Each matrix observed a 75-second
job timeout and 120-second runtime recovery; original-intent readback resumed at
316/317 seconds, after the unshortened 300-second member lock. The stop with a
dead worker settled at 121 seconds with the execution gate closed, zero DNS
writes and no uncertain budget refund. Master restart retained the original
task, with no orphan live processes or duplicate mock creations. All eight cases
verified empty owned tables/keys, no live fixture processes and unchanged
neighbor sentinels; both jobs removed their two service containers and network.

The shared non-daemon multi-worker suite was rerun at the same test source:
[42392563241](https://github.com/keli-123456/keliboard/actions/runs/42392563241),
22/22 with no errors/skips and all fixture cleanup checks passed. Final local unit
regression, including the native job/fixture timeout contract, passed 3,387 tests
and 21,634 assertions. Earlier smoke failures concerned fixture probe publication
cadence/decision snapshots and are retained separately; the final run did not
relax production evidence guards. No panel/node runtime code or existing Horizon,
queue, execution-gate, release or deployment configuration changed in this work.

### Dedicated Failover Queue And Bounded Worker

Native execution uses the `redis_failover` Laravel queue connection and only
`machine_failover`. This is a separate queue/consumer pool on the existing Redis
transport, not a new physical Redis server. Generic provisioning, manual power,
DNS-binding jobs and bounded notification delivery keep their existing queues.
Native managed provisioning advances inline under the native fenced ticket;
it does not dispatch an extra generic provisioning job to the admin pool.

`MACHINE_FAILOVER_WORKER_ENABLED=false` is the default. With the flag absent or
false, Horizon provisions exactly the original Xboard, RiskAI, AdminOperations
and Backup pools; no failover supervisor/process is added or expected by queue
health. Opting in adds `MachineFailover` to the same existing master plan in
production/local. It does not start a second Horizon daemon, resize any original
pool, subscribe those pools to failover, or grant execution permission. The
unchanged runner/replacement flags and rejecting execution gate remain closed.

The dedicated profile has balancing disabled, exactly one worker, one attempt,
an 80-second supervisor timeout, 128 MiB PHP-memory recycling threshold, 100-job
and 900-second worker recycling bounds, one-second idle sleep and lower CPU
scheduling priority (`nice=10`). Native jobs
still time out at 75 seconds, below the 80-second supervisor and 90-second retry
visibility, while the existing 120/300-second run/member leases are unchanged.
The memory/age/job thresholds are checked by Laravel between jobs: they are not
OS RSS/cgroup limits or a CPU quota. Redis, SQL and host CPU/I/O are still shared
if deployed together; independent consumers prevent queue-capacity borrowing,
not all possible resource contention. This work does not change OS daemons.

An old serialized native delivery received on the admin connection/queue exits
before calling the native runner. It is not moved, replayed or paid again. The
normal fenced sweep can recover its existing runtime ticket onto the dedicated
queue after the original visibility marker; no new intent is enrolled. A new
connection failure is recorded as `queue_unavailable`, with no fallback to admin.
Queue health expects the dedicated consumer only when its supervisor is opted in;
an admin worker, even with a matching queue label, cannot satisfy that connection.

Isolated acceptance reuses this exact production worker profile. It creates a
second synthetic admin pool in the same temporary master, deliberately blocks
the failover worker and leaves three native deliveries queued. Admin probe jobs
must complete while that backlog remains, and the same admin worker must survive
the native timeout/replacement. Live-process samples must never exceed one native
worker. CLI memory/timeout/recycling options, restart/stop behavior and cleanup
are checked alongside the unchanged real leases and mock paid-intent counts.
The non-daemon suite also checks old admin delivery rejection/fenced recovery.

No environment flag, config cache, existing queue, active supervisor, production
database, cloud/DNS endpoint or tanzhen resource is changed by these tests. A
future activation must load the optional plan through the normal planned Horizon
lifecycle; changing an environment file alone cannot reconfigure an already
running master. There is no deployment or daemon reload in this increment.

Dedicated-pool daemon acceptance:
[42395836205](https://github.com/keli-123456/keliboard/actions/runs/42395836205)
at `a5b78dba7b0524488a870f04e7f0343dc3460a0f`, 8/8 across both isolation
levels, no JUnit errors/skips or unexpected worker/Horizon errors. All samples
kept the native worker peak at one and verified the actual CLI recycling/timeout
options and Linux nice value. Ten synthetic admin probes completed in
106.075-1065.856 ms, including while three fresh duplicate native tickets waited
behind a blocked native worker. Its timeout/replacement did not replace the
admin worker. These timings describe only the disposable CI path, not a
production latency or resource-contention SLA.

Each isolation observed the actual 75-second timeout and 120-second runtime
recovery; readback resumed at 316/318 seconds after natural member-lock expiry.
Creation remained one per intent. The master restart preserved the task; stop
with a dead worker settled at 120 seconds with no DNS write or budget refund.
All eight cases verified zero live owned processes, removed private console
files, empty owned tables/keys and unchanged neighbor sentinels. Both matrix
jobs removed their two service containers and one network.

The multi-worker suite at `c40b01b2` passed 24/24, including legacy admin delivery
rejection and recovery to the dedicated connection:
[42394971041](https://github.com/keli-123456/keliboard/actions/runs/42394971041).
Full local unit regression passed 3,393 tests / 21,691 assertions; the 22 queue
and Horizon guard tests were rerun after the final fixture correction. The first
full daemon attempt, 42395097143, retained four cleaned case records but failed
its expected-failure-count assertions: it copied already-reserved payloads with
an advanced attempt count. The corrected test duplicates the original queued
delivery instead; no production retry limit, lease or evidence check changed.

Developed and validated on `codex/failover-queue-isolation`, this increment is
now merged into main as a code-only synchronization with `[skip ci]` commits.
The dedicated pool remains disabled by default; real creation, automatic
credential rotation and DNS cutover remain closed. No image publication,
production deployment/database connection, environment change, daemon restart
or tanzhen operation was performed.

### Cache-Only Automatic Trigger Preview

`MACHINE_FAILOVER_PREVIEW_ENABLED=false` is a separate, default-off audit
collector. When explicitly enabled in a future deployment, the scheduler runs
`machines:preview-failover --limit=5` once per minute as a background CLI command,
with a singleton scheduler mutex, non-overlap guard and a separate sweep lock.
It does not add a Horizon worker or change any existing consumer pool.

The collector reads existing observer plans, shadow checks, native episode
claims and cached cost baselines only. It never collects new probe samples,
refreshes cloud inventory/prices, reserves funds, dispatches native jobs,
creates resources, rotates credentials, sends notifications or changes DNS.
Its only database writes are to the new trigger preview and transition audit
tables. No operation is sent to an external provider or to tanzhen.

At most ten services can be requested per sweep (five by default), rotating
over the registry's bounded fifty-service catalog. A five-second wall budget
is checked between services; it is not a hard timeout for an in-progress
database/shadow read or a five-second detection SLA. Shadow evidence is fresh
for at most thirty seconds, and scheduling may therefore yield stale or
missing evidence. Missing cost evidence is unknown, never a zero-cost baseline.

Qualified failure episodes are deduplicated per service/member/policy. Timestamp
refreshes update the last check without appending repeated transitions. A
same-source healthy observation withdraws the earlier proposal; a different
source, revision, permission loss, disabled member or expired evidence blocks,
cancels or supersedes it. Already claimed native episodes and active native
runs cannot be proposed again. Preview amounts are hypothetical only and
consume no attempt, reservation or release ledger entries.

The existing shadow-check page has a collapsed automatic-trigger audit view.
It issues no request until expanded, supports refresh and cursor-paged
transitions, ignores late responses after a service change, and marks failed
reads, expired evidence and disabled collectors as historical. The endpoint is
admin-only GET, rejects execution parameters, and returns no credentials,
private episode/context hashes or unfiltered provider errors. Populated audit
tables cannot be silently discarded by migration rollback.

`would_start` means only that cached preview conditions passed. Provider
capacity/pricing, creation permission, isolated forwarding, DNS ownership and
real-world recovery acceptance remain outstanding; this is not an execution
authorization or end-to-end certification. The existing execution gate and
all real creation, rotation and DNS cutover switches remain unchanged and
closed. This is a code-only synchronization; the collector is not deployed or enabled.

Local validation: full unit regression passed 3,426 tests / 22,160 assertions;
the final collector/queue guard rerun passed 44 tests / 505 assertions after
the failure-path audit counter correction. Ten frontend suites passed 197
tests. The single-file build verified JS 4,634,738 / CSS 567,961 bytes against
the approved 4,638,000 / 568,000 limits. Synthetic Playwright checks covered
desktop/mobile, dark mode, English, disabled/missing audit schema and failed
readback, with no page overflow, page errors or write requests. These tests
used in-memory SQLite, mocked provider/queue boundaries and a local browser
fixture, not production MySQL/Redis or a live cloud/DNS acceptance.

### Isolated Trigger Scheduling Acceptance

`Failover Trigger Preview Isolated Acceptance` is manual-only and uses the
existing strict disposable connection guard: Linux CI, MySQL 8.4/InnoDB,
Redis 7, the fixed fixture-only database/user and random loopback ports.
Both `REPEATABLE READ` and `READ COMMITTED` are tested. There are no repository
secret inputs, image publication or deployment steps. The fixture refuses any
other workflow identity, host, default service port, database or root user
before database I/O. It never boots the production application or reads its
environment/database configuration.

The real collector, cached finance projection and preview CLI are used without
overriding the execution gate. All real creation, rotation, verification and
DNS switches stay false, including inside the fixture. Only synthetic probe
collection and trigger preview are enabled in the disposable PHP container.
Provider/DNS/verifier HTTP is prohibited, not merely accepted by a mock server.
Finance snapshots are explicitly seeded synthetic estimates, not cloud quotes.

The real Laravel schedule is reduced to its existing preview event before
execution. Two independent scheduler processes use `schedule:run`, background
CLI execution and `schedule:finish` across natural minute boundaries. Other
production scheduled commands cannot run. The console entry point accepts
only the preview and its scheduler finish command; it does not load `artisan`
or the production bootstrap. Separate cases cover four simultaneous CLI
collectors, source recovery and a later new episode, a MySQL statement failure
inside the audit transaction, SIGKILL after the first uncommitted transition,
budget/permission changes and natural evidence expiry.

The crash case waits out the real 120-second registry lease; it does not delete
or shorten locks. Fresh collected evidence is required again after recovery.
Collector processes allow SQL writes only to their two audit tables and
fixture telemetry, and compare business tables before/after standalone runs.
Every case checks zero native executions, attempts, new provisioning tasks,
budget releases, queued jobs and HTTP operations. Owned process sessions are
terminated and verified, then owner-marked tables and prefixed Redis keys are
removed. Neighbor queue/master sentinels must remain unchanged. Only aggregate
counts, decision/reason codes and cleanup flags are retained as evidence.

This validates disposable scheduling/transaction/cache behavior, not a live
cloud/DNS cutover, production latency, external capacity or an operational
resource-isolation SLA. No production database, service or tanzhen is involved.

Validated source `64d8614637911a7f109ddb4db2e7c24781c9da0c` passed **18/18**
cases / 622 assertions in manual run
[42436215368](https://github.com/keli-123456/keliboard/actions/runs/42436215368),
with zero failures, errors or skips. Each isolation ran two schedulers across
three natural minute ticks (127.4-127.6 seconds), producing three collections
but only two member transitions for the unchanged failure episode. Four
simultaneous CLI processes also produced only two transitions. Recovery
withdrew the prior episode; later qualified failure received a distinct one.

Database-local CHECK failure rolled back the complete audit without a partial
record or inflated write counters. SIGKILL after the first uncommitted
transition left no partial rows, waited out the unchanged 120-second registry
lease, and resumed with fresh collected evidence (132.0-132.2 seconds for the
complete case). Budget changes, permission loss and natural evidence expiry
remained blocking across fresh PHP processes. Every case verified zero native
intents/attempts, new provisioning tasks, releases, HTTP calls and queued jobs;
all owned live processes, tables, keys and private console files were removed,
and neighboring queue/master sentinels remained unchanged.

Earlier attempts were retained, not counted as passes: backdated synthetic
probes predated policy activation; Symfony did not inherit a putenv-only
fixture owner; and a trigger-based fault required an unwanted SUPER privilege.
The fixture now dates activation before its baseline, explicitly forwards its
owner to subprocesses, and uses a database-local CHECK constraint instead.

### Gated Linode Automatic Admission (Disabled)

`machines:admit-failover` is an internal, default-off link from collected trigger
proposals to the existing native bridge and dedicated worker. It is not an
activation HTTP endpoint and does not turn the cache-only preview collector into
an executor. `admission_enabled=false`, an empty `admission_authorizations` list,
all original creation/rotation/DNS gates and the unconditionally rejecting
`MachineFailoverExecutionGate` remain unchanged in effect. No production schedule,
queue configuration, database, cloud resource, DNS record or tanzhen service is
modified by this work.

A future, separately reviewed server-side authorization must pin one service ID
and revision, the current complete registry context hash, an active administrator,
an issued/expiry interval of at most 24 hours and one exact active kelinode-rs
Linux x86-64 release. The release pin includes component, platform, version,
archive SHA256 and binary SHA256. No latest/default release or provider fallback
is selected. Duplicate services, malformed scopes, future/expired grants and
unsupported-provider service members fail closed. These local configuration structures are
not evidence of operator approval or real acceptance, and cannot bypass the hard
gate. They have deliberately not been exposed in the browser or environment.

Before enrollment, the link requires a current cache-only shadow report, a
qualified collected failure plan, an unclosed matching `would_start` preview,
fresh source identity/threshold/baseline, unchanged member/policy/context,
unclaimed episode and a fresh verified cached cost baseline with enough capacity.
Future/backdated finance timestamps and validity windows exceeding the existing
five-minute baseline lifetime are rejected, not treated as a current quote.
The preview's decision alone is never authority. The native bridge repeats the
authorization, proposal, source and finance checks within its existing serialized
start transaction before writing the execution and episode claims. Existing
per-policy creation allowlists, credential health, ownership, price/capacity
checks and per-attempt budget reservations still apply downstream.

The encrypted native intent stores its admission authorization and bounded
proposal pins atomically with member episode claims. A deterministic private
request key makes identical admission attempts converge. A crash after commit,
notification error or missing queue receipt can recover only the matching running
execution. A foreign/manual run is not adopted, stopped or replaced. An existing
stop latch, expired execution, claimed episode, changed grant or release cannot
authorize another creation. Fresh shadow/preview evidence is required for a new
intent; already-committed executions recover under their original pins and the
native runner's normal fresh-source and lease checks, not an invented new episode.

Authorization and both release digests are checked again at native create and
DNS boundaries. Revocation/expiry blocks new work; it does not revoke an in-flight
provider request, prove a paid resource absent, refund a reservation, reverse DNS
or authorize cleanup. Earlier unarmed native records with the smaller release pin
fail closed for review rather than being silently upgraded to a new intent.

The sweep accepts at most ten scopes (five by default), rotates a cursor over at
most fifty explicitly pinned services, and has nonblocking sweep/per-service
locks. A five-second wall budget is checked between services, not a hard timeout
or detection SLA. The minute schedule exists only when admission is explicitly
configured; the command remains blocked by the execution gate. Native tasks use
the existing bounded `redis_failover:machine_failover` worker; no worker/pool is
added or resized here. Output contains bounded execution/service identifiers,
counters and whitelisted failure codes only, not grants, private context/episode
hashes, credentials, provider errors or account data. Committed intent audit stays
in the native journal; pre-start rejection counters are command results, not a
new durable cloud-operation ledger.

Validation uses the minimal in-memory SQLite/array-cache application, fake queue
and provider/verifier/DNS transports. It exercises the real native bridge and
runner through initialization, signed forwarding and owned-DNS receipts, along
with post-commit interruption, revocation before provider writes, stopped/foreign
run rejection, stale/forged previews, changed releases, unknown finance, scope
expiry and default-off scheduling. It is not new distributed Redis/MySQL,
Horizon-daemon, real cloud billing, WAN or DNS-propagation acceptance. This earlier
admission increment did not add DigitalOcean/Vultr adapters; their later local
implementation below still does not establish production readiness.

Local final regression passed 3,504 unit tests / 22,864 assertions. The focused
admission/native-boundary run passed 50 tests / 548 assertions. Changed PHP files
passed syntax checks. These are local code results, not a new CI/distributed or
live-cloud acceptance; the changes are not deployed and all real gates stay shut.
No production evidence guard, MySQL privilege, runtime lease or execution
gate was relaxed. The first Windows unit run also lacked its OpenSSL config;
after restoring the existing runtime configuration, full unit regression
passed 3,436 tests / 22,182 assertions. Final preview/queue/fixture guard tests
passed 54 / 525 assertions; frontend regression remained 197 passing tests.

The management source was synchronized as `keli-admin@69f17ba`, and its clean
build manifest/assets were committed with the preview backend in
`keliboard@68c2095b`. Implementation, isolated tests and evidence are code-only
synchronizations using `[skip ci]`; no image or deployment was requested.
The preview collector and native worker remain default-off. Real creation,
automatic credential rotation and DNS cutover remain closed.

### Multi-Provider Replacement Adapters (Disabled)

The local creation/acceptance path now selects a `ProvisionClient` through
`MachineProvisionProviders`, using the pinned plan provider and the selected
account's exact provider. It never falls back between providers. Linode's
existing payload and preflight stay unchanged. DigitalOcean and Vultr use
their own native region, size/plan and image/OS identifiers, not Linode codes.
Their explicit `machine_provision.providers.<provider>` region/size/image
allowlists default to **empty**. Catalog compatibility is necessary but is not
permission to create, and unreviewed templates remain blocked in rehearsal.

- DigitalOcean creates one Droplet at the fixed `/v2/droplets` endpoint, sends
  cloud-init as plain `user_data`, disables backups and optional monitoring,
  and checks active account state, regional size/image availability, image disk
  requirements and the current monthly compute price before dispatch.
- Vultr creates one instance at the fixed `/v2/instances` endpoint, using an
  integer `os_id` and base64 cloud-init. Preflight additionally checks the
  region's `available_plans`. Backups, DDoS add-ons and activation email are
  explicitly disabled; public IPv4 is not disabled.
- Optional raw SSH public keys are installed inside the new VM's cloud-init,
  never imported as account-level provider SSH keys. SSH password authentication
  is disabled on both JSON-provider templates. Vultr's returned default password
  is neither stored nor included in normalized resource/task output. This does
  not hide cloud-init secrets from the cloud provider or VM root.
- Both adapters reuse the encrypted task, callback authentication, post-dispatch
  Agent/node evidence, installation ID, exact binary SHA256 and signed forwarding
  receipt boundary. Acceptance writes the matching provider on the owned resource;
  a coincident numeric ID on another provider cannot be adopted.
- A dispatch fence precedes the single create POST. Timeout, 5xx or malformed or
  mismatched success remains an unknown paid outcome. Recovery only reads the
  original account and request. Reconciliation validates the marker, name,
  template and creation-time window, rejects duplicate candidates/IDs, and is
  limited to three pages/300 resources. It never follows returned pagination
  URLs or re-sends a POST when no candidate is found.
- Typed 401/403, billing, quota, rate and capacity failures reuse the existing
  credential-health/cooldown machinery. Unknown outcomes cannot rotate. Vultr's
  account read still lacks a stable unique account identity; email and balance
  do not invent one, so automatic independent-account rotation stays blocked.
- The cost and review paths use a provider-specific `FailoverCostClient`.
  Matching positive inventory can establish a supported compute monthly estimate
  after checking the same identity, one IPv4 and no unverified paid add-ons or
  attached volumes. This is not a provider bill or a spend cap. Neither JSON API
  account read proves an unrestricted resource view. A missing resource therefore
  remains unknown/unresolved: it cannot prove deletion, zero the baseline, release
  a reservation or authorize another creation. Revision/context expiry and
  provider-scoped quota locks still apply.

`MachineFailoverExecutionGate` still unconditionally rejects native execution.
Live creation, replacement, admission, runner enrollment, automatic rotation and
DNS mutation remain disabled; no activation API or environment bypass was added.
No queue/Horizon configuration, node binary, production database, existing cloud
resource, DNS record or tanzhen service was changed. Tests replace gates only
inside inert in-memory fixtures and fake all provider, verifier and DNS traffic.

The added tests cover native payloads and user-data encoding, closed live gates,
unapproved/incompatible templates, prices, credentials sent to the wrong provider,
typed provider failures, duplicate/foreign candidates, bounded reconciliation,
positive-only cost evidence and missing-resource budget safety. End-to-end
fixtures for both providers exercise create, authenticated initialization,
matching reports, signed TCP/UDP forwarding and owned-DNS receipts, plus unknown
POST recovery without a second VM or credential change. These are simulations,
not real VM, cloud-init, WAN, billing or DNS-propagation acceptance.

Local regression for this increment passed 3,571 unit tests / 23,443 assertions.
The focused multi-provider adapter, native receipt and budget tests passed
67 tests / 564 assertions; all 32 changed or added PHP files passed syntax
checks. No new Redis/MySQL/Horizon, provider account or WAN acceptance was run.
Source and bundled-admin synchronization do not authorize deployment, activation
or queue changes. The live execution gates remain closed.

#### Live Acceptance Still Pending

The operator has not yet prepared API credentials. No cloud/DNS account was
accessed or charged by this increment. SSH private keys are not the prerequisite:
dedicated provider API tokens and a dedicated DNS API credential must first be
added securely to the isolated panel, not sent in chat. Use test-only credential
groups, provider-native catalogs and expiring, least-privilege tokens. For
DigitalOcean, verify `account:read`, the catalog/metadata read permissions and
`droplet:create` plus its documented dependencies (including tag permissions)
before an explicitly authorized run. Do not grant delete merely to run creation.
Restrict Vultr API access to the isolated controller's exact egress IP. DNS
permissions must cover only the dedicated non-business zone/record.

The outstanding confirmation is recorded in the isolated preparation plan:
test groups per provider, region, monthly quote limit and actual test budget,
retention deadline, independent controller/verifier, test DNS hostname and
credential, and who retains or deletes resources afterward. The proposed cap is
one candidate per provider, run sequentially; this is not approval. Initial
single-instance readiness and a later owned-DNS replacement trial are separate
stages. Do not use business DNS or an existing tanzhen member as the source.

References checked against official APIs/clients:
[DigitalOcean Droplets](https://docs.digitalocean.com/reference/api/reference/droplets/),
[DigitalOcean token scopes](https://docs.digitalocean.com/reference/api/scopes/),
[DigitalOcean request schema](https://github.com/digitalocean/godo/blob/main/droplets.go),
[Vultr instances](https://github.com/vultr/govultr/blob/master/instance.go),
[Vultr regional availability](https://github.com/vultr/govultr/blob/master/region.go),
[Vultr block storage](https://github.com/vultr/govultr/blob/master/block_storage.go).

### Rotation Authorization And DNS Readback Hardening (Disabled)

Credential rotation now has its own `machine_failover.allow_rotation=false`
gate and empty `allowed_rotation_policy_ids` allowlist. Permission for a first
creation or replacement is not permission to try another credential. There is
still no browser or environment bypass of the native execution hard gate.

- Only a definite, typed authentication, permission, billing or quota rejection
  marked as an eligible, non-review rotation candidate can select another
  credential. A timeout, ambiguous success, provider failure or rate limit does
  not authorize another paid request. Unknown results stay with the original
  request/account; transient failures wait rather than cycling tokens.
- Selection remains in the same provider and credential group, with health,
  cooldown, account-identity, attempt and budget limits. Full persisted member
  attempt history prevents A-to-B-to-A reuse after process recovery or expiry of
  daily counters. Candidates prefer fewer attempts, then the older last attempt.
- Each child persists its parent attempt/account, typed rejection reason and
  rejection digest in the encrypted task. The bridge revalidates that lineage
  and the independent rotation grant before dispatch. Revocation prevents an
  unsubmitted child POST; a child already dispatched may still be read back under
  its original request without authorizing another creation.
- Vultr still cannot prove independent accounts from its account metadata.
  Email, balance, group membership and token names cannot substitute for that
  proof. Account-level billing/quota rotation is therefore blocked where the
  required independent account identity is unavailable.

Native owned-DNS replacement supports **Cloudflare-owned A records only**.
Unsupported providers, including Aliyun, now block the native policy before VM
creation rather than failing only after paying for a candidate. This does not
remove Aliyun from ordinary instance DNS bindings. DNS credentials do not rotate
automatically: the selected credential, revision, zone, hostname, marker and
record ID remain pinned throughout the original transition.

The new migration `2026_11_09_140000_add_machine_dns_readback_state.php` adds
`next_check_at`, `readback_attempts`, `last_checked_at` and `confirmed_at`.
Owned-DNS execution and its UI allowance fail closed until all columns exist;
historical records remain readable. Downgrade refuses to discard used readback
evidence. No production migration was run in this increment.

- Exact-name enumeration requires complete, internally consistent bounded
  pagination, unique IDs and well-typed DNS data. Missing or truncated inventory
  is not an empty record set and cannot authorize creation or adoption.
- Execution rechecks the current record immediately before the durable
  dispatch fence. A changed marker, IP, identity or record shape blocks the write.
  This is not atomic compare-and-swap against an external administrator: the
  provider can still change after the final read. The ownership/readback checks
  and manual review boundary remain necessary.
- Only one create or patch is dispatched for each transition. A transient
  failure before dispatch persists a wait without marking a write sent. An
  expired unsubmitted intent cannot be extended by its backoff. A source that
  recovers before dispatch is retained without proceeding to DNS.
- After dispatch, unknown results and retryable reads persist the next check and
  count **each** provider readback before sending it. Rate-limit waits honor a
  bounded `Retry-After`; excessive or non-retryable waits require review.
  There are at most 12 readback attempts. Exhaustion, permission/ownership
  changes or expired forwarding evidence stop automatic progress. They do not
  resend the DNS write, delete a record, roll back DNS, release budget or create
  another VM.
- Confirmation still requires the same fresh signed TCP/UDP forwarding and
  instance evidence. It records the provider readback confirmation time while
  retaining the previous instance. A stale signed receipt cannot be silently
  replaced with a new test run merely to finish DNS reconciliation.

The read-only operator view shows the rotation's parent and failure kind,
DNS readback count/limit and next check time. It continues to label historical
provider readback separately from current proof and never claims global DNS
propagation. No new execution or retry control was added.

Synthetic coverage includes independent-grant revocation at dispatch, original
readback after revocation, no credential cycles, changed rejection lineage,
DigitalOcean typed rotation through initialization/forwarding/DNS, malformed DNS
inventory, a competing record change before write, persisted rate-limit recovery,
the durable readback cap, original-admin binding, migration gating and evidence
preservation. Provider APIs, verifier and DNS remain faked; none of these tests
proves real cloud-init, billing, WAN reachability, DNS propagation or distributed
worker behavior. Dedicated provider/DNS credentials and the isolated acceptance
parameters below are still missing.

All creation, native execution/admission/runner, automatic rotation and DNS
cutover gates remain closed. No node binary, queue/Horizon configuration,
production database, tanzhen service or existing cloud/DNS resource was changed.
This source-only synchronization does not deploy or activate these changes.

Local regression after hardening passed 3,607 backend unit tests / 23,677
assertions and 149 focused admin contract/component tests. All 53 changed or
added PHP files passed syntax checks. Playwright checked the read-only receipts
at 1440px and 390px in light/dark themes with mocked reads, no external requests
and no document overflow. The clean-source admin single-bundle build passed at JS 4,637,066 /
CSS 567,961 bytes, within unchanged gates of 4,638,000 / 568,000 bytes.
No dependencies or queue/Horizon settings changed; no real provider, DNS,
database or WAN acceptance was performed.

Provider references:
[Cloudflare DNS record listing](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/),
[record creation](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/create/),
[record patching](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/edit/).

### Service Member DNS Record Sets (Simulation Only)

This increment adds service-scoped DNS configuration and a persistent offline
rehearsal. It does **not** activate a multi-record live executor or migrate the
existing single-A ownership rows. A service with record sets is explicitly
rejected by the legacy scalar DNS rehearsal and the native bridge; its dedicated
record-set simulation is a separate entry point. Simulated receipts cannot be
used as forwarding, ownership, propagation or paid-resource acceptance evidence.

The additive `2026_11_09_180000_create_machine_failover_dns_sets.php` migration
introduces three tables:

- `v2_machine_failover_dns_set`: service, revision, pinned DNS credential revision,
  provider, zone, zone name, exact hostname and TTL. A hostname has one controller
  across services, legacy policies, owned DNS and ordinary instance bindings.
- `v2_machine_failover_dns_slot`: stable member/type/line association and a unique
  ownership marker per slot. One service can have five record sets, ten members,
  and up to 120 slots per set. Multiple enabled members can share a hostname while
  keeping separate records. Markers are hidden from the management response.
- `v2_machine_failover_dns_run`: original administrator/request, pinned service
  and record-set revisions, encrypted context and simulated action journal.
  The same intent resolves to the same audit instead of another dispatch.

Draft writes require administrator permission and both revision checks. Saving
or deleting a draft increments the parent service revision and invalidates stale
contexts. Active service work blocks edits. Members with slots cannot be removed,
referenced DNS credentials cannot be deleted, and parent/draft downgrade refuses
to orphan retained configuration or simulation audit. An audited set cannot
change hostname, zone, provider or credential identity. No migration was run
against a real database during this work.

`MachineDnsRecordSetPlanner` builds a member-scoped diff from complete provider
inventory and caller-supplied address facts. It preserves non-target member
records, including their AAAA records when the target is IPv4-only. Missing a
selected family blocks progress; it is not a reason to delete the old family.
Duplicate record IDs/markers, shared member addresses, unmanaged records in a
managed type/line, CNAME conflicts, orphan markers, changed ownership or changed
non-target members require review. Non-managed TXT records and unselected
families are not automatically adopted or pruned. Explicit member detach is
limited to that member's owned slots and cannot withdraw the last enabled member.

The offline `MachineDnsRecordSetProvider` contract maps Cloudflare and Aliyun
responses and mutation descriptions without accepting secrets or constructing an
HTTP client. Inventory is capped at three complete pages/150 records, preserving
long Aliyun record IDs as strings. A/AAAA values are normalized by address family.

- Cloudflare uses its zone/record IDs, A/AAAA, ownership comment and DNS-only
  records. Only its default line is accepted. TTL is conservatively 60..86400.
- Aliyun uses domain/RR, A/AAAA, provider-native `default`, `telecom`, `unicom`,
  `mobile`, `edu` and `oversea` lines. TTL is conservatively 600..86400. These
  ranges/line names are local schema constraints, **not** proof of the account's
  paid edition, supported lines or current quota.
- Aliyun creation and ownership remark are separate operations: an acknowledged
  `AddDomainRecord` ID is retained before `UpdateDomainRecordRemark`. Recovery
  resumes from that same ID. If creation is unknown and the record is unclaimed,
  the simulation stops; matching an IP cannot authorize adopting or tagging it.
  A lost remark receipt is resolved by readback, not another creation.

The dedicated simulated runner exercises bootstrap, replacement, explicit detach
and restore, source recovery, missing IPv6, foreign/duplicate records, truncated
inventory, competing edits, permission rejection, partial multi-line/dual-stack
failure, unknown outcomes, remark interruptions and readback throttling. It stores
the dispatch boundary before a simulated mutation, checks context on continuation,
and permits stopping without discarding earlier audit. Partial failure or unknown
results do not cause automatic reverse writes or replay. Rate-limit waiting uses
a labeled virtual clock, not a background sleeper or real provider request.

Administrator routes under `/server/machine/cloud/failover/services/dns` expose
workspace, draft save/drop, simulate, original-run read, continue and stop. They
reject caller-supplied execution flags, arbitrary inventories and claimed receipts.
The management page selects a DNS credential and zone, then member A/AAAA and line
checkboxes. Simulation results show the affected member, before/after values,
action state and separately labeled simulated/real counts. It does not offer a
live activation or provider write button. Existing zone selection remains a
separately initiated read-only catalog request; all catalogs in the local preview
and tests are fixtures.

Remaining live work includes a separately reviewed multi-record execution bridge,
fresh address/forwarding evidence for every published family, dedicated credential
scope and edition/line/TTL validation, durable real-provider mutation/readback
integration, isolated concurrency/crash acceptance and external DNS propagation
checks. The current single-A native bridge remains intact and hard-disabled;
this record-set model must not silently broaden it. Domestic IPv4 reachability
classification is unchanged; publishing AAAA does not enable IPv6 domestic probes.

This increment did not change node code, queue/Horizon settings, execution gates,
cloud instances, production data, DNS records or tanzhen. All real automatic
creation, credential rotation and DNS cutover remain closed. This source-only
synchronization includes the new source, tests and locally built admin assets
from keli-admin commit `e4ed5703c3689e2655641556e890e1342e7d8be8`. It does not
deploy, publish a release, apply real migrations or change tanzhen scheduling.

Local validation passed 3,695 backend unit tests / 25,065 assertions and syntax
checks on all 19 added or changed PHP files. The full admin suite passed 2,764
tests in 287 files; the final HTTP-error receipt hardening then passed 68 focused
contract/component tests, including three new cases for HTTP 500 and definitive
rejection. A server error is not treated as proof that no audit was persisted:
the original intent is read first, and an unresolved result retains its key.
Admin lint and the clean-source sync build passed at JS 4,663,734 / CSS 570,093
bytes, within the explicitly approved gates of 4,668,000 / 573,000 bytes. No
dependencies were added. Playwright checked draft save, synthetic success and
missing-IPv6 rejection at 1440px and 390px in light/dark themes, with no page
errors, external requests or page/modal overflow. These are mocked UI and unit
checks, not isolated MySQL/Redis, paid-provider, forwarding or DNS propagation
acceptance. The fixture preview is available locally at
`http://127.0.0.1:5191/audit/machine-cloud-preview.html?orchestration=1&dns_sets=1`.
The source synchronization rechecked 88 focused backend tests / 1,368 assertions
and 68 focused admin tests. Synced asset hashes, cache-busting references and the
clean admin source commit in `build-manifest.json` were also verified locally.

Official wire-contract references:
[Aliyun subdomain record listing](https://help.aliyun.com/en/dns/api-alidns-2015-01-09-describesubdomainrecords),
[Aliyun record update](https://api.alibabacloud.com/document/Alidns/2015-01-09/UpdateDomainRecord),
[Aliyun DNS API operations](https://api.aliyun.com/product/Alidns),
[Cloudflare record listing](https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/list/).

### Journaled Record-Set Execution (Closed)

The next increment replaces the payload-only DNS simulator with a shared
`MachineDnsRecordSetExecution` kernel and independent transport contracts. The
public rehearsal still always selects the synthetic transport. Native
initialization, the HTTP transport and the low-level record-set HTTP methods all
check the unchanged, unconditional `MachineFailoverExecutionGate`. There is no
new live route or setting that bypasses it. At this increment the scalar native
bridge still rejected record-set services; the subsequent closed native binding
is described below.

`2026_11_09_200000_create_machine_dns_set_runtime.php` adds a per-run runtime row
and a separate encrypted provider-fixture ledger. A runtime owns a 120-second
lease with a monotonically increasing generation, hashed private lease ticket,
persisted next-check time, stop request and terminal time. A stale worker cannot
commit an old receipt or release its successor's lease. No provider request runs
inside a database transaction. Draft, member, credential and source revisions
are checked again before dispatch, and the final inventory cannot complete the
run after its context changes.

Each action has an immutable plan seal. Its exact provider request and dispatch
hash are committed before I/O. A recovered dispatch can only read back the
original action; it cannot repeat a create, patch, delete or remark. Complete
inventory is compared with the last verified working set before subsequent
mutations, preserving other members, unmanaged records and unselected families.
Provider IDs, address families, lines, ownership markers and full expected
record-set readback must agree. Partial failure never initiates compensating
reverse writes, credential rotation or further line updates.

Aliyun add and remark have separate durable checkpoints. A successful add must
acknowledge a string record ID. `UpdateDomainRecordRemark` normally returns only
`RequestId`; that acknowledgement does not invent another record ID and still
requires readback against the original acknowledged ID. Unknown unclaimed adds
stop for review without IP-based adoption. A crash after an applied update or
remark resumes by readback, not by repeating the mutation. The HTTP adapter
reuses existing fixed-host signing, redirect restrictions and sanitized error
classification; it has no automatic mutation retries. HTTP errors and Aliyun
RPC throttling/internal errors are typed without retaining provider bodies.

Transient preflight/final inventory failures wait with a persisted bounded read
budget. Per-action readback allows at most 12 attempts and honors the saved
next-check time; restarting a worker does not reset it. The rehearsal facade can
advance only the fixture's labeled virtual clock. Direct kernel tests also
verify that a tick before the due time does no I/O.

Stopping records its request without waiting for the worker lease. It prevents
future dispatches, but cannot undo an already in-flight request. The kernel may
settle that one operation with read-only reconciliation. Unknown outcomes or an
add interrupted before its ownership remark remain `needs_review`, not a false
clean stop; no automatic deletion or rollback follows. The UI shows verified
action counts and a pending-stop state. Old payload-only histories are readable
but are never silently migrated, resumed or resent by the new kernel. Their
continue and stop controls are disabled. Downgrade retains runtime and fixture
audit, including blocked/stopped runs.

The provider fixture persists independently from the execution journal and does
not deduplicate accidental repeated creates. Tests inspect its actual request
history and records rather than accepting a success flag as evidence. Covered
cases include all six supported Aliyun lines with A/AAAA, bootstrap, member
replacement/detach/restore, permission failures, partial success, lost receipts,
add/remark interruptions, lease takeover, stale receipts, preflight/in-flight
stop races, throttling, read-budget exhaustion, revision/permission changes and
tampered journals. All source data and credentials used in these tests are fake.

Local full regression passed 3,730 backend tests / 25,479 assertions before the
final fixture-only duplicate-create guard, followed by 124 focused executor
tests / 1,798 assertions. All 13 added or changed PHP files passed syntax checks.
The full admin suite passed 2,773 tests in 287 files; admin lint and
the clean-source sync build passed at JS 4,665,749 / CSS 570,217 bytes within the unchanged approved
gates. Playwright checked stopping and read-only history at 1440px and 390px in
light/dark themes, including non-collapsed mobile selectors, with no page/modal
overflow, script errors or external requests. Draft-save, success and blocked
rehearsal views were also rechecked. Only in-memory SQLite was migrated: no
production database, real cloud/DNS account, node, queue/Horizon configuration,
execution gate or tanzhen resource/scheduler was touched. This source-only
synchronization includes the new source, tests, documentation and locally built
admin assets from keli-admin commit `b9981744b8ba0787807b1bf3eb25e499061f3da1`.
It does not publish a release, deploy, apply real migrations or enable any gate.
The synchronization rechecked 124 focused backend tests / 1,798 assertions and
74 focused admin tests. The synced asset hashes, cache-busting references and
clean source commit in `build-manifest.json` were verified locally.

Still required before any future live activation: real candidate/source
identity and fresh independent forwarding acceptance for every published family,
isolated MySQL/Redis concurrent worker/crash acceptance of the native binding,
account-specific DNS line/edition/TTL permissions,
real provider receipt reconciliation and external DNS/TTL propagation checks.
Unit tests and the synthetic fixture are not that authorization or evidence.

Wire references: [Aliyun add](https://help.aliyun.com/en/dns/api-alidns-2015-01-09-adddomainrecord),
[Aliyun ownership remark](https://help.aliyun.com/en/dns/api-alidns-2015-01-09-updatedomainrecordremark),
[Aliyun exact subdomain inventory](https://help.aliyun.com/en/dns/api-alidns-2015-01-09-describesubdomainrecords).

### Native Candidate and Address Proof Binding (Closed)

The native bridge now selects the record-set admission path when a service has
DNS sets, while preserving the legacy single-A path for services without sets.
It still checks the unconditional execution gate before every entry. There is
no new public activation route, gate relaxation or default-enable setting.
Service-scoped shadow and native admission validate the registered set's
credentials/revisions and membership instead of requiring dummy legacy
Cloudflare configuration. Independent legacy policy/single-A checks remain
unchanged; a caller-supplied unregistered snapshot cannot replace those checks.

`2026_11_10_010000_create_machine_native_address_proofs.php` is additive and
retains its audit on downgrade. The admission pins all managed source members
and their accepted task, held cloud resource, installation, provider creation
identity, release and selected A/AAAA values. Unknown or ambiguous addresses
fail closed. It requires exact existing ownership for every source slot before
preparing a replacement; this is not an importer or bootstrap/adoption path.

Each published family requires its own schema-2 signed worker challenge. The
full contract is in `machine-acceptance-verifier.md`. Addresses must belong to
the same candidate task, held resource, fresh identified Agent report and
fresh provider identity. A request's observed source IP or another Agent's
IPv6 is insufficient. Every requested node needs authenticated TCP and UDP
echoes plus cleanup. Proof IDs, request/receipt/context hashes and application-
key seals bind the original attempt to the candidate, process, release, expected
configuration, topology and verifier. The generic IPv4 acceptance is retained
but cannot substitute for either native family proof. Nothing changes the
IPv4-only domestic probe rule or the node binary.

Each DNS child has its own durable original intent and sealed publication plan,
inventory, source pins and proof links. Changed identity, stale proof, revoked
permission or synthetic/unbound receipt cannot authorize a write. The existing
kernel rechecks this binding at preparation, execution, just before dispatch
and final completion. No cloud/verifier/DNS I/O runs in SQL transactions.
Prepared child links are reused after interruption, not replaced with new
requests. A lost acknowledgement or superseded worker lease can only reconcile
the original recorded operation, never resend the mutation.

Cloud creation can retain its existing concurrency, but DNS handoff is
serialized across members of one service. A later member rebases only onto a
sealed, completed handoff from that same execution, not an arbitrary new
active instance. Partial or uncertain earlier handoffs block later members.
The runner respects persisted DNS due times and can promptly advance due native
DNS steps without changing queue routing, worker counts or resource limits.
Proof validity is not extended: a slow/throttled or large handoff that exceeds
its freshness budget requires review rather than automatic re-verification.

All required DNS children must pass fresh complete readback before one SQL
transaction activates the candidate, retains the old source, records a sealed
completion and marks the member/attempt complete. Completed-task reporting
checks that sealed original lineage rather than a bare success flag. Existing
unrelated scalar DNS records are not migrated or deleted. Service-provider
readback is not global DNS/TTL propagation evidence.
Activation also independently reads all required provider inventories again
outside SQL and checks their captured timestamps and journal digests inside the
commit. A completed flag with unpublished records or an intervening DNS change
cannot substitute for that readback. Before a persisted DNS due time the native
bridge does not poll the cloud or verifier just to wait.

Parent stop revokes child lease generations locally. Undispatched children stop;
any dispatched/partial or unknown outcome is retained for review. Late replies
cannot complete or clear a successor lease. Source recovery after a partial
handoff is review, not a clean retention or an automatic reverse cutover.

Only in-memory SQLite, fake cloud/DNS HTTP and fake signed verifier receipts
are used for this round. Worker unit tests mock client measurement. Production,
real MySQL/Redis, cloud accounts, DNS, node processes, queue/Horizon settings and
tanzhen are untouched. Actual isolated multi-worker native acceptance, live
per-family forwarding, provider/DNS permission checks and external propagation
remain required. Real creation, automatic rotation and DNS cutover remain off.

Local validation for this increment: the final backend Unit suite passed 3,788
tests / 27,876 assertions. The preceding focused native/registry/shadow/admission
regression passed 290 tests / 5,453 assertions, with subsequent targeted checks
for due-time waiting, final provider readback and replacement-policy binding.
All 12 added/changed PHP files passed syntax checks and `git diff --check`
passed. The worker passed 11 unit tests; five optional real-protocol loopback
tests were skipped without explicitly configured binaries. No real forwarding
or public IPv6 acceptance is claimed. Only backend, verifier source/tests and
documentation changed; admin and node worktrees remain unchanged. This round
was implemented without publishing, deployment or production migrations.

The subsequent source-only synchronization includes this native admission,
candidate identity and address-family proof binding together with its migration,
worker contract, tests and documentation. The commit uses `[skip ci]` to avoid
automatic CI-triggered image publishing; it does not dispatch an acceptance or
release workflow. Existing admin assets remain unchanged and pass their manifest
verification. Real automatic creation, credential rotation and DNS cutover stay
disabled. No production database, cloud account, DNS, node, queue/Horizon setting
or tanzhen resource/scheduler is accessed or changed by this synchronization.
The synchronization rechecked 293 focused backend tests / 5,640 assertions,
12 PHP syntax checks, the unchanged admin asset manifest and 11 worker unit
tests. The five optional real-protocol tests remain skipped without configured
binaries; no real cloud, DNS or public IPv6 acceptance is implied.

### Disposable Native Dual-Stack Concurrency and Daemon Acceptance (2026-11-10)

The native admission and address-family binding above were subsequently tested
with real isolated MySQL 8.4/InnoDB, Redis 7, separate Linux PHP workers and the
locked Horizon daemon. This extends the earlier in-memory-only evidence for the
specific paths below; provider creation, DNS HTTP and signed verifier measurement
are still synthetic. No production connection or real forwarding is involved.

Only the temporary `acceptance/native-dualstack-20261110` branch was uploaded.
The manual concurrency run used `2bf1daf862444aadfbbeecf9df4236a22a4c7894`;
the complete corrected daemon run used
`e931d54f054a368b0c435b432722b0b5cc55948b`. Main remained at
`bc6653b2e94c739032d3977a126bede844f1acd1`. No main merge, image publishing,
deployment, application/configuration/workflow change or production migration
is part of this acceptance. Real automatic creation, automatic credential
rotation and DNS cutover remain closed by the unchanged hard execution gate.
The existing tanzhen deployment and scheduler are untouched.

| Final run | MySQL isolation | Tests | Assertions | Failures/errors/skips |
| --- | --- | ---: | ---: | --- |
| [Concurrent workers](https://github.com/keli-123456/keliboard/actions/runs/42759265517) | REPEATABLE READ | 22 | 523 | 0/0/0 |
| Same concurrent run | READ COMMITTED | 22 | 523 | 0/0/0 |
| [Full Horizon recheck](https://github.com/keli-123456/keliboard/actions/runs/42760797159) | REPEATABLE READ | 6 | 208 | 0/0/0 |
| Same daemon recheck | READ COMMITTED | 6 | 208 | 0/0/0 |

The 44 concurrent cases include ten native record-set cases in each isolation:
two competing workers completing two dual-stack members with four distinct
family proofs; duplicate deliveries without another proof or mutation; SIGKILL
during an issued IPv6 challenge; candidate revision changes while a receipt is
in flight; a validly signed wrong-family receipt; stop during verification;
stop during prepared DNS inventory; stop after the IPv4 write; SIGKILL after a
DNS mutation but before acknowledgement; and an old acknowledgement returning
after a successor's readback. Original intents and child IDs are retained.
Partial writes require review without an automatic rollback or AAAA dispatch;
ambiguous or stale evidence cannot authorize candidate publication.

Six of the 22 concurrent cases per isolation explicitly force lease expiry for
fast fencing/crash tests. Their summaries record `forced_expiries`; these are
not real-duration lease evidence. The separate full Horizon run does not
accelerate clocks or locks. Observed seconds below are relative to the original
claim/issue and include scheduler polling, not latency or throughput benchmarks.

| Daemon measurement | REPEATABLE READ | READ COMMITTED |
| --- | ---: | ---: |
| IPv6 reply returned after the actual 45-second challenge deadline | 46 | 46 |
| Native IPv6 worker's actual job timeout | 75 | 75 |
| Native runtime lease recovery | 120 | 120 |
| Native stale-candidate review after the natural 300-second member lock | 311 | 312 |
| Legacy original-intent provider readback after the same natural member lock | 317 | 317 |
| Stop settled after worker death and natural runtime lease expiry | 121 | 121 |

In the expired-receipt case the IPv6 proof fails with no DNS write. In the
worker-timeout case the original IPv6 proof stays issued and spent for review;
neither a new challenge nor another cloud creation or DNS write is dispatched.
The daemon restart case retains the original task, with one synthetic creation
and no orphan processes. Stop recovery continues even with the execution gate
closed, but does not release an uncertain resource's budget.

The isolated failover worker peak is one, with the existing configured 128 MiB
worker memory threshold and `nice=10`; no operating-system memory limit is
inferred from that setting. Separate synthetic business jobs remained responsive
(107-1,063 ms observed across the final daemon cases), including the same
business worker surviving failover-worker timeout. Neighbor queue/master
sentinels remained unchanged. This is isolation evidence, not a production
capacity guarantee. There were no unexpected worker/Horizon errors. Deliberate
job timeouts/failures and stop-related queue exhaustion are asserted fault
outcomes, not hidden errors.

Every concurrent case reported cleanup complete. All twelve final daemon cases
additionally verified zero owned live processes, unchanged neighbor state,
empty disposable Redis/MySQL and removed private console files. Cleanup is
scoped to the owned fixture; no global queue purge or production process action
is used. Service containers are disposable CI services on random loopback ports,
with a database-scoped non-root SQL account, never a formal panel database.

The [first daemon run](https://github.com/keli-123456/keliboard/actions/runs/42759271061)
is retained as a failed run: REPEATABLE READ passed all six cases, while READ
COMMITTED stopped at the native timeout case because the test read a terminal
execution status before its separate durable `finished` runtime event existed.
The missing timestamp became zero in the assertion. The test now awaits both
the terminal state and that event. No runtime timer, evidence guard or application
code was relaxed. The two complete daemon matrices were then rerun, not merely
the previously failing assertion. Cleanup passed even in the failed case.

Final local regression on the corrected test source passed 3,788 Unit tests /
27,878 assertions and all eleven independent-worker unit tests. The fixture
guard subset passed 33 tests / 79 assertions. These are not real protocol-client
results. The four changed PHP test files passed syntax checks and
`git diff --check` passed. Only tests and this documentation differ from main.

Aggregate JUnit/JSONL evidence, including the failed first run, was downloaded to
the ignored workspace directory `.codex-audit/native-dualstack-20261110/` before
the seven-day CI artifact expiry. Final JSONL SHA256 digests are:

| Artifact | SHA256 |
| --- | --- |
| `42759265517-native-failover-0` | `de43f057064125b967b23368be72311a6a8153f5e88b02a5d9de6696a5bffe2b` |
| `42759265517-native-failover-1` | `475c8ecab96956c2c666f5205aa11920945eec587d99076059f63ac8a8871981` |
| `42760797159-native-horizon-0` | `4f223d86137e298254dda97e266d546916e622786c7f34a24e561fae63763316` |
| `42760797159-native-horizon-1` | `ea6551ed17b679d43c3109d22e028d7211f57b8ca713553e07fce0f31e4795ac` |

Remaining live gates are unchanged: account-specific provider receipts, actual
authenticated forwarding through every selected public IPv4/IPv6 endpoint,
DNS ownership/edition/line/TTL permissions, external propagation and an explicit
single-machine non-business acceptance authorization. The blocked verifier
fixture validates application challenge expiry and daemon timeout handling,
not actual HTTP-client timeout behavior, WAN availability, packet loss, mainland
reachability or a live DNS account. Passing these finite scenarios is not proof
that all failover behavior is complete or permission to enable live automation.
