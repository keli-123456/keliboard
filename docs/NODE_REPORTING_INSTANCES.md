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

The node list exposes runtime instances and replica binding management. Server management also exposes read-only instance inspection from each associated node. Upgrades and Agent terminals remain targeted at individual machine records. If multiple installation IDs use one machine credential, the panel flags the conflict, avoids last-writer machine telemetry, and blocks ambiguous Agent terminals/upgrades. Create separate machine records and reinstall each machine with its own credentials; do not split automatically by IP.

Deploy panel migrations and compatible admin assets first, then upgrade agents individually. Existing assignments and credentials are not migrated automatically. Review observed identities and independent delivery receipts before changing live bindings. This implementation does not deploy agents or modify production bindings by itself.
