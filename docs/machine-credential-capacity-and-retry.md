# Credential capacity and member retry controls

This change separates account occupancy, in-flight work, per-run attempts and a
rolling 24-hour circuit breaker. The former fixed account ceiling of two tasks
per day is removed. It does not authorize creation, rotation or DNS changes.

## Controls

| Scope | Setting | Meaning |
| --- | --- | --- |
| Cloud account | `provision_limits.max_instances` | Local account instance ceiling, 0 to 10000. Zero adds no local ceiling; provider limits still apply. |
| Cloud account | `provision_limits.max_parallel` | In-flight account creation allowance, 1 to 5. Linode and Vultr retain a single serialized lane because their provider quota is not assumed known. DigitalOcean can use the configured allowance. |
| Cloud account | `provision_limits.daily_limit` | Rolling 24-hour task fuse, 0 to 1000. Zero disables this fuse only. |
| Global account ceiling | `MACHINE_PROVISION_DAILY_LIMIT_PER_ACCOUNT` | Optional rolling 24-hour fuse. Default 0; invalid values fail closed instead of becoming zero. |
| Member policy | `daily_limit` | Rolling 24-hour machine/slot fuse, 0 to 1000. Existing explicit values remain unchanged. |
| Service | `max_parallel`, `attempt_limit` | At most 1 to 10 concurrent members and 1 to 100 durable attempts in a run. |
| Service member | `attempt_limit` | Optional per-run ceiling, 1 to 20. Null inherits the service ceiling; all members also share the service total. |

The account, member and service ceilings intersect. Raising one ceiling does
not override the others, cooldown, authorization or provider availability.
Existing member-policy defaults and saved settings are not rewritten.

## Accounting and safety

- Aliases of the same cloud account share occupancy and task history. Their
  strictest nonzero capacity/fuse and smallest concurrency allowance apply.
- Imported resources and task resource IDs are deduplicated. Queued tasks,
  unbound native reservations and unresolved creation outcomes retain slots.
- Initialization, reporting and unresolved cleanup continue to occupy the
  in-flight lane. A failed status without evidence of rejection does not free
  an ambiguous create.
- A rejected, undispatched or definitively rejected create can release its
  occupancy without deleting its task or resetting its attempt counters.
- Candidate cleanup releases a slot only after validating the existing sealed
  proof, exact identity and two independent missing-resource reads. A changed
  seal or incomplete cleanup blocks further creation.
- Cleanup does not refund member/service attempts or erase rolling task
  history. Retry still waits for the configured cooldown. A sibling's own
  attempt allowance is not consumed by another member's attempts.
- Native reservation, direct enqueue, dispatch and account-limit edits share
  the provider quota lock. Existing per-account, per-slot and intent locks
  remain in place.
- Account-limit edits are revision checked, preserve a blank token field and
  cannot override unresolved account work.
- Capacity reads occur only in creation preflight, not in list rendering,
  shadow checks or credential selection. DigitalOcean reads the account limit
  and inventory; Linode/Vultr inventory is read when a local instance cap is
  configured. Inventory totals, page cardinality and unique IDs must agree.
  Unknown, incomplete or full capacity stops dispatch.
- Local records without verified cleanup are retained conservatively; this
  change does not silently purge manually deleted or stale historical rows.

The existing identity checks, consecutive IPv4 failure thresholds, original
intent reconciliation, DNS ownership checks and audit records remain required.
No money-based execution threshold is introduced.

## Reference alignment

The local tanzhen reference is commit `f976f1b`, examined read-only. Its provider
pool (`utils/failover/provider_pool.go`) distinguishes live usage, reservations
and serialized lanes for unknown provider quotas. Its V2 member retry logic
lives separately in `utils/failoverv2`; it is not assumed to use every V1 pool
mechanism. This implementation follows that separation while retaining
keliboard's durable identity and cleanup proofs.

DigitalOcean's [account API](https://docs.digitalocean.com/reference/api/reference/account/)
defines `droplet_limit` as account capacity, not a daily creation allowance.
The required account and Droplet read permissions remain necessary; no live
provider request was made for this local change.

## Storage and local verification

Migration `2026_11_16_120000_add_machine_provision_limits.php` adds nullable
account limits and nullable member attempt ceilings. It does not rewrite old
policies, runs or automation switches. Before migration, capability flags hide
the new fields and old clients can omit them without clearing stored values.

`tests/Support/TestsProvisionLimits.php`, included by the existing native bridge
suite, covers same-day sources, configurable fuses, shared-account aliases,
cleanup proof tampering, cleanup/cooldown/retry, sibling budgets, provider
inventory completeness, ambiguous outcomes, strict configuration parsing,
account edits and quota-lock contention. Cloud and DNS transports are fakes;
PHP tests use in-memory SQLite/cache with socket/cURL execution disabled.

The admin preview and `audit/verify-machine-credential-capacity.cjs` verify
account/member save behavior at 1440px, 800px, 390px and 320px, including English
labels. All API responses are local mocks and nonlocal requests are blocked.

Full working-tree regression results: 916 backend tests / 11,323 assertions; 205 frontend
tests across 11 files; four browser viewport checks. The backend total includes
the existing provisioning, policy, credential, native retry and cleanup suites.
Before commit, the two mixed test files were exported from the index and their
18 targeted capacity/fuse/retry tests passed with 162 assertions. Unrelated
acceptance branch tests, driver changes and temporary CI edits are excluded.

The measured admin bundle is JS 4,680,932 bytes and CSS 571,265 bytes. The user
approved JS 4,684,000; CSS remains at 573,000. No dependency was added.

R34 was not executed and historical acceptance records were not rewritten by
this work. There is no deployment, real database access, cloud creation, DNS write,
queue configuration change, GitHub run or tanzhen modification. Real worker
competition and provider capacity behavior still require separately authorized
isolated acceptance; local mocks do not establish those results.
