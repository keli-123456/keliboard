# Node reporting instances

## Ownership

- A server-machine record represents one physical machine and has its own credentials.
- A logical node can have one primary machine and additional replica bindings in `v2_server_machine_binding`. Adding a replica does not transfer the primary owner.
- Node configuration and users are distributed to every assigned machine. Binding does not change the subscription endpoint or configure DNS/load balancing.
- Automatic, machine-local ECH and pinned certificates cannot be shared by this binding workflow. This includes HY2 independently issued `self`/`dns`/`http` certificates and TrustTunnel auto-pinned self-signed certificates. Use separate nodes or explicitly provision compatible shared material. HY2 file mode requires the same certificate/key on every replica; matching paths alone are not proof that file contents match.

## Reporting

`kelinode-rs` persists a random 128-bit installation identifier in `report-instance-id` inside its kernel config directory. It is loaded once at startup, sent as `X-Keli-Instance-Id` on panel HTTP requests, and included in authenticated realtime pings. It is not an authentication credential. Preserve it during upgrades; do not copy it into cloned machine images. An invalid existing file is not silently replaced.

The panel derives the reporting key from authenticated machine ownership plus the installation ID. Node-token agents are tracked separately. IP changes never create a new identity. Legacy reports remain accepted, but missing installation identity is visible and cannot prove a physical machine count.

Each source has an independent online snapshot and version watermark. Empty or delayed reports from one source cannot remove another source's devices. Existing device-limit modes remain in effect: strict counting unions replicas of the same logical node, while other logical nodes retain their existing separation. User-online display continues to deduplicate IPs.

The registry retains metadata for 24 hours, limits active sources per node to 32, and expires resource/online values independently. No new sampler, collector, listening port, or user payload is introduced.

## Accounting and receipts

- Original user/node accounting remains authoritative. The new per-instance daily totals contain billed bytes confirmed through durable traffic receipts, not a second charge.
- Original accounting, source totals and receipt completion commit in one transaction. Retrying a receipt cannot increment them twice.
- A legacy admitted receipt can be retried after the same authenticated machine gains an installation ID. Its original source and prepared payload are not rewritten.
- Raw legacy traffic reports have no durable receipt guarantee; missing diagnostic totals are unknown, not zero.
- Config/user receipts are scoped by authenticated source. One replica's success is not presented as another machine's success or as confirmation for all replicas.

## Administration and rollout

The server list shows observed reporters separately from bound nodes. A machine's runtime view groups the same reporter across its bound nodes and shows confirmed billed traffic per node. Reported addresses are what the panel sees, not SSH destinations. Each identified reporter retains up to eight address changes per node in the observation cache; a legacy source has no independent history. Changes before tracking began cannot be reconstructed.

Each server stays on one list row. Up to two reporting sources appear in aligned lines with their own addresses, resource readings, and billing. The instance count opens the full list, and the IP drawer shows additional addresses and history. IPv4 and IPv6 are address families, not priority roles. Observed-only addresses stay distinguishable in the drawer. The list uses its existing refresh interval; no per-row polling is introduced.

The list keeps live network rates separate from today's attributed billing. Billing uses the displayed installation's durable node receipts and does not imply that unreported nodes used zero bytes. Shared credentials still hide ambiguous machine-wide metrics; fresh CPU, memory and rates can be shown for the exact authenticated installation. Rates are marked as the last reading after 15 seconds (fast telemetry) or 90 seconds (full report), then hidden at 60 or 300 seconds respectively. Heartbeats without rates do not extend either deadline, and readings never fall back to another installation.

Domestic probes use public IPv4 targets only. The panel skips existing IPv6 targets and rejects them on save. The agent uses its existing leased ping worker and two-process cap, stopping the round on success. Per-address results require a fresh report explicitly bound to the IPv4 source and matching installation ID. Old agents and observed-only or unmatched IPv4 addresses stay unverified. IPv6 remains visible in the address inventory and history without a probe indicator. Two complete all-failure rounds on the same source are required for a per-address suspected block. This is outbound evidence, not an inbound client test, and is not used to retarget SSH.

Multiple live installation IDs, or overlapping legacy and identified reports of one node under the same machine credential from different observed IPs, block Agent terminals, machine-wide upgrades and rollouts. The latter is a safety signal, not proof of a physical machine count. Shared machine-level metrics are hidden while the conflict is active. Fallback SSH uses an explicitly configured, fingerprint-verified target; reports never populate or change that target.

For a shared record, **Upgrade instance** can queue a manual upgrade for a fresh kelinode-rs 0.1.385+ reporter and a newer release from the panel warehouse. Only one upgrade runs at a time per machine record. Dispatch and receipts match the complete installation ID and the panel-observed source address; tasks never follow an address change. The selected release hashes are pinned at enqueue, and success requires the target version and running binary hash. An installation ID and source address are routing checks, not independent credentials: only share credentials among trusted agents and do not clone installation IDs. For independent authorization, give each machine its own record and credentials and bind them to the logical node where compatible.

Deploy panel migrations and compatible admin assets first, then upgrade agents individually. Existing assignments and credentials are not migrated automatically. Review observed identities and independent delivery receipts before changing live bindings. This implementation does not deploy agents or modify production bindings by itself.
