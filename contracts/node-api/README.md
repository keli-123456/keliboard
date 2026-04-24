# Node API Contract

`node-api.json` is the stable contract between Xboardpro and v2node.

Rules:
- Release Xboardpro and v2node as one upgrade set for contract changes.
- Keep one-version compatibility in both directions during rolling upgrades.
- Add new fields as optional first.
- Do not rename or remove fields without a contract version bump.
- Do not change endpoint paths, required fields, or field meaning without a
  compatibility window and an explicit contract version bump.
- Keep `App\Contracts\NodeApiContract` in sync with this file.
- Keep v2node path constants in sync with this file.

The contract intentionally covers the node-facing APIs first because these endpoints
are consumed by an external binary and are harder to catch through frontend tests.
